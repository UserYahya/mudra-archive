<?php
// index.php - Mudra Archive Personal Digital Numismatic Museum & Public Collection
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

send_security_headers();

$baseUrl = get_base_url();

// Parse query parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$countryFilter = isset($_GET['country']) ? trim($_GET['country']) : '';
$currencyFilter = isset($_GET['currency']) ? trim($_GET['currency']) : '';
$gradeFilter = isset($_GET['grade']) ? trim($_GET['grade']) : '';
$featuredFilter = isset($_GET['featured']) && $_GET['featured'] === '1' ? 1 : 0;
$yearMin = isset($_GET['year_min']) && $_GET['year_min'] !== '' ? (int)$_GET['year_min'] : null;
$yearMax = isset($_GET['year_max']) && $_GET['year_max'] !== '' ? (int)$_GET['year_max'] : null;
$allowedSorts = ['date_desc', 'date_asc', 'country_asc', 'year_asc', 'year_desc'];
$sortBy = isset($_GET['sort']) && in_array($_GET['sort'], $allowedSorts, true) ? $_GET['sort'] : 'date_desc';
$page = isset($_GET['page']) && (int)$_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$perPage = COINS_PER_PAGE;

// Every public figure is restricted to the same set of rows, so the statistics,
// the map and the gallery can never report different totals.
$publicOnly = public_coins_filter();

// Public Statistics Counters
$totalCountries  = (int)$pdo->query("SELECT COUNT(DISTINCT country) FROM coins WHERE $publicOnly")->fetchColumn();
$totalCurrencies = (int)$pdo->query("SELECT COUNT(DISTINCT currency_name) FROM coins WHERE currency_name IS NOT NULL AND currency_name != '' AND $publicOnly")->fetchColumn();
$totalCoinsCount = (int)$pdo->query("SELECT SUM(quantity) FROM coins WHERE $publicOnly")->fetchColumn();
$totalEntries    = (int)$pdo->query("SELECT COUNT(*) FROM coins WHERE $publicOnly")->fetchColumn();
$yearRange = get_collection_year_range($pdo);
$countryCounts = get_country_coin_counts($pdo);

// Fetch Real Coins for Visual Composition Hero Showcase (up to 4 valid obverse images)
$heroStmt = $pdo->query(
    "SELECT id, country, currency_name, denomination, year, obverse_image
     FROM coins
     WHERE obverse_image IS NOT NULL AND obverse_image != '' AND $publicOnly
     ORDER BY is_featured DESC, id DESC LIMIT 4"
);
$heroCoins = $heroStmt->fetchAll();

// Fetch Featured Coins count
$featuredCount = (int)$pdo->query("SELECT COUNT(*) FROM coins WHERE is_featured = 1 AND $publicOnly")->fetchColumn();

// Build SQL Query with PDO Prepared Statements
$whereClauses = [$publicOnly];
$params = [];

if ($search !== '') {
    $whereClauses[] = "(country LIKE :search OR currency_name LIKE :search OR denomination LIKE :search OR ruler_or_series LIKE :search OR notes LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if ($countryFilter !== '') {
    $whereClauses[] = "country = :country";
    $params[':country'] = $countryFilter;
}

if ($currencyFilter !== '') {
    $whereClauses[] = "currency_name = :currency";
    $params[':currency'] = $currencyFilter;
}

if ($gradeFilter !== '') {
    $whereClauses[] = "condition_grade = :grade";
    $params[':grade'] = $gradeFilter;
}

if ($featuredFilter === 1) {
    $whereClauses[] = "is_featured = 1";
}

if ($yearMin !== null) {
    $whereClauses[] = "CAST(year AS INTEGER) >= :year_min";
    $params[':year_min'] = $yearMin;
}

if ($yearMax !== null) {
    $whereClauses[] = "CAST(year AS INTEGER) <= :year_max";
    $params[':year_max'] = $yearMax;
}

// Count total matching records for pagination
$countSql = "SELECT COUNT(*) FROM coins";
if (!empty($whereClauses)) {
    $countSql .= " WHERE " . implode(" AND ", $whereClauses);
}
$stmtCount = $pdo->prepare($countSql);
$stmtCount->execute($params);
$totalMatching = (int)$stmtCount->fetchColumn();

$totalPages = max(1, (int)ceil($totalMatching / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

// Fetch Paginated Coins (100 per page)
$sql = "SELECT * FROM coins";
if (!empty($whereClauses)) {
    $sql .= " WHERE " . implode(" AND ", $whereClauses);
}

// Sorting logic
switch ($sortBy) {
    case 'country_asc':
        $sql .= " ORDER BY country ASC, year ASC";
        break;
    case 'year_asc':
        $sql .= " ORDER BY CAST(year AS INTEGER) ASC, country ASC";
        break;
    case 'year_desc':
        $sql .= " ORDER BY CAST(year AS INTEGER) DESC, country ASC";
        break;
    case 'date_asc':
        $sql .= " ORDER BY id ASC";
        break;
    case 'date_desc':
    default:
        $sql .= " ORDER BY id DESC";
        break;
}

$sql .= " LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$coins = $stmt->fetchAll();

// Dropdown options
$countries = get_unique_countries($pdo);
$currencies = get_unique_currencies($pdo);
$grades = get_unique_grades($pdo);
$activeFilterCount = ($countryFilter ? 1 : 0) + ($currencyFilter ? 1 : 0) + ($gradeFilter ? 1 : 0) + ($featuredFilter ? 1 : 0) + ($yearMin !== null ? 1 : 0) + ($yearMax !== null ? 1 : 0);

// The only query parameters that ever appear in a URL we generate. Echoing back
// whatever arrived in $_GET let crawlers discover unlimited URL permutations of
// the same page; this keeps the crawlable surface finite.
$currentFilters = array_filter([
    'search'   => $search,
    'country'  => $countryFilter,
    'currency' => $currencyFilter,
    'grade'    => $gradeFilter,
    'featured' => $featuredFilter === 1 ? '1' : '',
    'year_min' => $yearMin,
    'year_max' => $yearMax,
    'sort'     => $sortBy !== 'date_desc' ? $sortBy : '',
], function ($v) { return $v !== null && $v !== ''; });

function pagination_url(array $filters, $pageNo) {
    return home_url($pageNo > 1 ? $filters + ['page' => $pageNo] : $filters, 'collection');
}

// --- Page-level SEO -------------------------------------------------------
$isFiltered = ($activeFilterCount > 0 || $search !== '');

// Filtered and searched views are thin, near-duplicate slices of the gallery.
// They stay crawlable (follow) so the coin pages behind them are discovered,
// but they are kept out of the index so they cannot compete with the homepage.
$robotsDirective = $isFiltered
    ? 'noindex, follow'
    : 'index, follow, max-image-preview:large, max-snippet:-1';

// Paginated pages self-canonicalise; every filtered view points at the homepage.
$canonicalUrl = $baseUrl . '/';
if (!$isFiltered && $page > 1) {
    $canonicalUrl = $baseUrl . home_url(['page' => $page]);
}

$countryLabel = $totalCountries === 1 ? 'country' : 'countries';
$pageTitle = $isFiltered && $countryFilter !== ''
    ? sprintf('%s Coins | %s', $countryFilter, SITE_NAME)
    : sprintf('%s — %s', SITE_NAME, SITE_TAGLINE);

$pageDescription = sprintf(
    'Browse %s catalogued coins from %d %s in the %s, a personal numismatic collection curated by %s. Every entry records denomination, year, mint mark, metal, weight, diameter and grade, with photographs of both faces.',
    number_format($totalEntries),
    $totalCountries,
    $countryLabel,
    SITE_NAME,
    SITE_AUTHOR
);

// rel=prev/next are machine-facing, so they carry no #collection fragment.
$prevUrl = $page > 1 ? $baseUrl . home_url($currentFilters + ($page - 1 > 1 ? ['page' => $page - 1] : [])) : null;
$nextUrl = $page < $totalPages ? $baseUrl . home_url($currentFilters + ['page' => $page + 1]) : null;

// Coordinates mapping guaranteeing 100% of collection countries are plotted accurately
$leafletMarkers = [];
foreach ($countryCounts as $cc) {
    $cName = $cc['country'];
    $cnt = $cc['count'];
    $coords = get_country_coordinates($cName);
    if ($coords) {
        $leafletMarkers[] = [
            'country' => $cName,
            'count' => $cnt,
            'lat' => $coords[0],
            'lng' => $coords[1]
        ];
    }
}

$cssVersion = file_exists(__DIR__ . '/assets/css/style.css') ? filemtime(__DIR__ . '/assets/css/style.css') : time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <meta name="robots" content="<?= sanitize($robotsDirective) ?>"/>
    <meta name="theme-color" content="#1e293b"/>

    <!-- Primary SEO Meta Tags -->
    <title><?= sanitize($pageTitle) ?></title>
    <meta name="description" content="<?= sanitize(truncate_text($pageDescription, 300)) ?>"/>
    <meta name="author" content="<?= sanitize(SITE_AUTHOR) ?>"/>
    <link rel="canonical" href="<?= sanitize($canonicalUrl) ?>"/>
    <?php if ($prevUrl): ?><link rel="prev" href="<?= sanitize($prevUrl) ?>"/><?php endif; ?>
    <?php if ($nextUrl): ?><link rel="next" href="<?= sanitize($nextUrl) ?>"/><?php endif; ?>

    <!-- Open Graph / Facebook Meta Tags (Absolute URLs) -->
    <meta property="og:site_name" content="<?= sanitize(SITE_NAME) ?>"/>
    <meta property="og:type" content="website"/>
    <meta property="og:locale" content="<?= sanitize(SITE_LOCALE) ?>"/>
    <meta property="og:url" content="<?= sanitize($canonicalUrl) ?>"/>
    <meta property="og:title" content="<?= sanitize($pageTitle) ?>"/>
    <meta property="og:description" content="<?= sanitize(truncate_text($pageDescription, 200)) ?>"/>
    <meta property="og:image" content="<?= $baseUrl ?>/assets/logo.png"/>
    <meta property="og:image:alt" content="<?= sanitize(SITE_NAME) ?> logo"/>

    <!-- Twitter Card Meta Tags (Absolute URLs) -->
    <meta name="twitter:card" content="summary_large_image"/>
    <meta name="twitter:url" content="<?= sanitize($canonicalUrl) ?>"/>
    <meta name="twitter:title" content="<?= sanitize($pageTitle) ?>"/>
    <meta name="twitter:description" content="<?= sanitize(truncate_text($pageDescription, 200)) ?>"/>
    <meta name="twitter:image" content="<?= $baseUrl ?>/assets/logo.png"/>

    <!-- Early Theme & Safety Inline Resets -->
    <script>
        (function() {
            const savedTheme = localStorage.getItem('mudra_theme');
            if (savedTheme) {
                document.documentElement.setAttribute('data-theme', savedTheme);
            }
        })();

        // Global UI Functions registered immediately
        function toggleTheme() {
            const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', newTheme);
            localStorage.setItem('mudra_theme', newTheme);
            updateThemeIcon(newTheme);
        }

        function updateThemeIcon(theme) {
            const icon = document.getElementById('themeIcon');
            if (icon) {
                icon.innerText = theme === 'dark' ? 'light_mode' : 'dark_mode';
            }
        }

        function toggleMobileNav(e) {
            if (e) {
                e.preventDefault();
                e.stopPropagation();
            }
            const nav = document.getElementById('mobileNavMenu');
            if (nav) {
                nav.classList.toggle('show');
                nav.classList.toggle('active');
            }
        }

        function closeMobileNav() {
            const nav = document.getElementById('mobileNavMenu');
            if (nav) {
                nav.classList.remove('show');
                nav.classList.remove('active');
            }
        }

        function toggleCoinFlip(el, e) {
            if (!el) return;
            const flipper = el.querySelector('.coin-3d-flipper.has-reverse');
            if (flipper) {
                flipper.classList.toggle('flipped');
            }
        }

        function toggleBio() {
            const bioText = document.getElementById('bioText');
            const toggleBtn = document.getElementById('bioToggleBtn');
            const toggleIcon = document.getElementById('bioToggleIcon');

            if (bioText.classList.contains('bio-collapsed')) {
                bioText.classList.remove('bio-collapsed');
                toggleBtn.querySelector('span').innerText = 'Show Less';
                toggleIcon.innerText = 'expand_less';
            } else {
                bioText.classList.add('bio-collapsed');
                toggleBtn.querySelector('span').innerText = 'Show Full Details';
                toggleIcon.innerText = 'expand_more';
            }
        }

        function toggleMobileFilters(e) {
            if (e) e.preventDefault();
            const panel = document.getElementById('mobileFilterPanel');
            if (panel) {
                panel.classList.toggle('show');
                panel.classList.toggle('active');
                if (panel.classList.contains('active') || panel.classList.contains('show')) {
                    panel.scrollIntoView({ behavior: 'smooth' });
                }
            }
        }
    </script>

    <!-- Critical Overriding Layout Styles (Immune to Browser Caching) -->
    <style>
        html { scroll-behavior: smooth; }
        #collection, #countriesMap, #statistics, #about { scroll-margin-top: 80px; }
        #mobileNavMenu { display: none !important; }
        #mobileNavMenu.show, #mobileNavMenu.active { display: block !important; }
        #mobileFilterPanel { display: none !important; }
        #mobileFilterPanel.show, #mobileFilterPanel.active { display: block !important; }
        #leafletMap { height: 420px; width: 100%; border-radius: 12px; z-index: 1; transition: height 0.3s ease; }

        /* Fanned Overlapping Museum Coin Deck */
        .hero-coin-stack {
            position: relative !important;
            height: 140px !important;
            width: 100% !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            margin: 0.5rem 0 !important;
        }

        .hero-coin-link {
            position: absolute !important;
            width: 90px !important;
            height: 90px !important;
            border-radius: 50% !important;
            display: block !important;
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.3s ease !important;
            cursor: pointer !important;
            text-decoration: none !important;
            -webkit-tap-highlight-color: transparent;
        }

        .hero-coin-link img,
        .hero-coin-card {
            width: 100% !important;
            height: 100% !important;
            border-radius: 50% !important;
            background-color: #ffffff !important;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.28) !important;
            border: 3px solid #ffffff !important;
            object-fit: cover !important;
            display: block !important;
        }

        [data-theme="dark"] .hero-coin-link img,
        [data-theme="dark"] .hero-coin-card {
            border-color: #334155 !important;
            background-color: #1e293b !important;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.6) !important;
        }

        /* Overlapping fanned positions */
        .hero-coin-stack > .hero-coin-link:nth-child(1),
        .hero-coin-stack > *:nth-child(1) {
            transform: translateX(-55px) rotate(-10deg) !important;
            z-index: 1 !important;
        }

        .hero-coin-stack > .hero-coin-link:nth-child(2),
        .hero-coin-stack > *:nth-child(2) {
            transform: translateX(-18px) rotate(-3deg) !important;
            z-index: 2 !important;
        }

        .hero-coin-stack > .hero-coin-link:nth-child(3),
        .hero-coin-stack > *:nth-child(3) {
            transform: translateX(18px) rotate(4deg) !important;
            z-index: 3 !important;
        }

        .hero-coin-stack > .hero-coin-link:nth-child(4),
        .hero-coin-stack > *:nth-child(4) {
            transform: translateX(55px) rotate(11deg) !important;
            z-index: 4 !important;
        }

        @media (min-width: 768px) {
            .hero-coin-stack {
                height: 165px !important;
                margin: 0 !important;
            }
            .hero-coin-link {
                width: 120px !important;
                height: 120px !important;
            }
            .hero-coin-link img,
            .hero-coin-card {
                border-width: 4px !important;
            }
            .hero-coin-stack > .hero-coin-link:nth-child(1),
            .hero-coin-stack > *:nth-child(1) { transform: translateX(-80px) rotate(-10deg) !important; }
            .hero-coin-stack > .hero-coin-link:nth-child(2),
            .hero-coin-stack > *:nth-child(2) { transform: translateX(-26px) rotate(-3deg) !important; }
            .hero-coin-stack > .hero-coin-link:nth-child(3),
            .hero-coin-stack > *:nth-child(3) { transform: translateX(26px) rotate(4deg) !important; }
            .hero-coin-stack > .hero-coin-link:nth-child(4),
            .hero-coin-stack > *:nth-child(4) { transform: translateX(80px) rotate(11deg) !important; }

            .hero-coin-stack:hover > .hero-coin-link:nth-child(1),
            .hero-coin-stack:hover > *:nth-child(1) { transform: translateX(-120px) rotate(-14deg) scale(1.08) !important; z-index: 10 !important; }
            .hero-coin-stack:hover > .hero-coin-link:nth-child(2),
            .hero-coin-stack:hover > *:nth-child(2) { transform: translateX(-40px) rotate(-4deg) scale(1.08) !important; z-index: 11 !important; }
            .hero-coin-stack:hover > .hero-coin-link:nth-child(3),
            .hero-coin-stack:hover > *:nth-child(3) { transform: translateX(40px) rotate(5deg) scale(1.08) !important; z-index: 12 !important; }
            .hero-coin-stack:hover > .hero-coin-link:nth-child(4),
            .hero-coin-stack:hover > *:nth-child(4) { transform: translateX(120px) rotate(15deg) scale(1.08) !important; z-index: 13 !important; }
        }

        /* 3D Coin Scene in Gallery Grid */
        .coin-3d-scene {
            width: 100% !important;
            max-width: 175px !important;
            aspect-ratio: 1 / 1 !important;
            perspective: 1000px !important;
            margin: 0 auto 0.85rem auto !important;
            position: relative !important;
            cursor: pointer !important;
            -webkit-tap-highlight-color: transparent !important;
            touch-action: manipulation !important;
        }

        .coin-3d-flipper {
            position: relative !important;
            width: 100% !important;
            height: 100% !important;
            transform-style: preserve-3d !important;
            transition: transform 0.65s cubic-bezier(0.4, 0, 0.2, 1) !important;
            border-radius: 50% !important;
            will-change: transform !important;
        }

        .coin-card:hover .coin-3d-flipper.has-reverse,
        .coin-3d-flipper.has-reverse.flipped {
            transform: rotateY(180deg) !important;
        }

        .coin-face {
            position: absolute !important;
            inset: 0 !important;
            width: 100% !important;
            height: 100% !important;
            -webkit-backface-visibility: hidden !important;
            backface-visibility: hidden !important;
            border-radius: 50% !important;
            background-color: #ffffff !important;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.12) !important;
            border: 2px solid #ffffff !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            overflow: hidden !important;
            transform: translateZ(0) !important;
        }

        [data-theme="dark"] .coin-face {
            background-color: #1e293b !important;
            border-color: #334155 !important;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.5) !important;
        }

        .coin-face img {
            width: 100% !important;
            height: 100% !important;
            object-fit: cover !important;
            border-radius: 50% !important;
            display: block !important;
        }

        .coin-face-obv { transform: rotateY(0deg) !important; }
        .coin-face-rev { transform: rotateY(180deg) !important; }

        .coin-card-material {
            font-size: 0.72rem !important;
            font-weight: 600 !important;
            padding: 1px 7px !important;
            border-radius: 8px !important;
            text-transform: uppercase !important;
            letter-spacing: 0.03em !important;
            display: inline-block !important;
        }

        .badge-metal-silver, .coin-card-material.badge-metal-silver { background-color: #f1f5f9 !important; color: #1e293b !important; border: 1px solid #cbd5e1 !important; }
        [data-theme="dark"] .badge-metal-silver, [data-theme="dark"] .coin-card-material.badge-metal-silver { background-color: #334155 !important; color: #f8fafc !important; border-color: #475569 !important; }

        .badge-metal-gold, .coin-card-material.badge-metal-gold { background-color: #fef9c3 !important; color: #713f12 !important; border: 1px solid #facc15 !important; }
        [data-theme="dark"] .badge-metal-gold, [data-theme="dark"] .coin-card-material.badge-metal-gold { background-color: #713f12 !important; color: #fef08a !important; border-color: #a16207 !important; }

        .badge-metal-copper, .coin-card-material.badge-metal-copper { background-color: #ffedd5 !important; color: #7c2d12 !important; border: 1px solid #fdba74 !important; }
        [data-theme="dark"] .badge-metal-copper, [data-theme="dark"] .coin-card-material.badge-metal-copper { background-color: #431407 !important; color: #fdba74 !important; border-color: #7c2d12 !important; }

        .badge-metal-default, .coin-card-material.badge-metal-default { background-color: #f8fafc !important; color: #334155 !important; border: 1px solid #cbd5e1 !important; }
        [data-theme="dark"] .badge-metal-default, [data-theme="dark"] .coin-card-material.badge-metal-default { background-color: #1e293b !important; color: #f1f5f9 !important; border-color: #334155 !important; }

        /* Full Screen Map View */
        #countriesMap:fullscreen,
        #countriesMap:-webkit-full-screen,
        .map-fullscreen-active {
            width: 100vw !important;
            height: 100vh !important;
            max-width: 100vw !important;
            margin: 0 !important;
            padding: 1.25rem !important;
            border-radius: 0 !important;
            display: flex !important;
            flex-direction: column !important;
            overflow-y: auto !important;
            background-color: var(--surface-bright) !important;
            z-index: 99999 !important;
        }

        #countriesMap:fullscreen #leafletMap,
        #countriesMap:-webkit-full-screen #leafletMap,
        .map-fullscreen-active #leafletMap {
            flex-grow: 1 !important;
            height: calc(100vh - 180px) !important;
            min-height: 450px !important;
        }
    </style>

    <!-- Resource Preconnect & Font Links -->
    <link rel="preconnect" href="https://fonts.googleapis.com"/>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin=""/>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin=""/>
    <link rel="dns-prefetch" href="https://unpkg.com"/>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400..900;1,400..900&family=Source+Sans+3:ital,wght@0,300..900;1,300..900&display=swap" rel="stylesheet"/>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200"/>

    <?php if (!empty($heroCoins)): ?>
        <!-- Preload the first hero coin: it is the largest element painted above
             the fold, and LCP is a ranking signal. -->
        <link rel="preload" as="image" href="<?= sanitize(get_coin_thumbnail_path($heroCoins[0]['obverse_image'])) ?>" fetchpriority="high"/>
    <?php endif; ?>

    <!-- Favicons & PWA manifest -->
    <link rel="icon" type="image/x-icon" href="/assets/favicon.ico"/>
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32x32.png"/>
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/apple-touch-icon.png"/>
    <link rel="manifest" href="/site.webmanifest"/>

    <!-- Bootstrap 5 CSS via CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
    <!-- Master Custom Stylesheet (with dynamic cache buster) -->
    <link href="/assets/css/style.css?v=<?= $cssVersion ?>" rel="stylesheet"/>

    <!-- Leaflet's CSS and JS are fetched only when the map scrolls into view
         (see the loader at the end of <body>), so they cost nothing on first paint. -->
    <link rel="preload" as="style" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" onload="this.rel='stylesheet'"/>
    <noscript><link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/></noscript>

    <?php
    // --- Structured data ---------------------------------------------------
    // A connected graph (site -> curator -> collection -> the coins on this page)
    // rather than one isolated WebSite node. Search engines and AI answer engines
    // use it to understand what this site is an authority on, and the SearchAction
    // makes the collection searchable straight from a Google result.
    $websiteNode = [
        '@context'        => 'https://schema.org',
        '@type'           => 'WebSite',
        '@id'             => $baseUrl . '/#website',
        'name'            => SITE_NAME,
        'alternateName'   => SITE_NAME . ' — ' . SITE_TAGLINE,
        'url'             => $baseUrl . '/',
        'description'     => $pageDescription,
        'inLanguage'      => 'en',
        'publisher'       => ['@id' => $baseUrl . '/#curator'],
        'potentialAction' => [
            '@type'       => 'SearchAction',
            'target'      => [
                '@type'       => 'EntryPoint',
                'urlTemplate' => $baseUrl . '/?search={search_term_string}',
            ],
            'query-input' => 'required name=search_term_string',
        ],
    ];

    $curatorNode = [
        '@context'    => 'https://schema.org',
        '@type'       => 'Person',
        '@id'         => $baseUrl . '/#curator',
        'name'        => SITE_AUTHOR,
        'email'       => 'mailto:' . SITE_CONTACT,
        'url'         => $baseUrl . '/#about',
        'knowsAbout'  => ['Numismatics', 'Coin collecting', 'World currency', 'Monetary history'],
        'description' => SITE_AUTHOR . ' curates the ' . SITE_NAME . ', a personal collection of world coins gathered largely through Wikimedia events and international exchange.',
    ];

    // ItemList of the coins actually rendered on this page, in display order.
    $itemListElements = [];
    foreach ($coins as $i => $listCoin) {
        $itemListElements[] = [
            '@type'    => 'ListItem',
            'position' => $offset + $i + 1,
            'url'      => coin_url($listCoin, $baseUrl),
            'name'     => coin_display_title($listCoin),
        ];
    }

    $collectionNode = [
        '@context'      => 'https://schema.org',
        '@type'         => 'CollectionPage',
        '@id'           => $canonicalUrl,
        'url'           => $canonicalUrl,
        'name'          => $pageTitle,
        'description'   => $pageDescription,
        'isPartOf'      => ['@id' => $baseUrl . '/#website'],
        'about'         => ['@type' => 'Thing', 'name' => 'Numismatics'],
        'inLanguage'    => 'en',
        'creator'       => ['@id' => $baseUrl . '/#curator'],
        'mainEntity'    => [
            '@type'           => 'ItemList',
            'name'            => SITE_NAME . ' coin catalogue',
            'numberOfItems'   => $totalEntries,
            'itemListOrder'   => 'https://schema.org/ItemListOrderDescending',
            'itemListElement' => $itemListElements,
        ],
    ];

    // FAQ entries matching the questions this page genuinely answers. These are
    // the phrasings people type into a search box, and the format answer engines
    // quote from most readily.
    $faqs = [
        [
            'What is the Mudra Archive?',
            SITE_NAME . ' is a personal digital museum of world coins curated by ' . SITE_AUTHOR . '. It catalogues ' .
            number_format($totalEntries) . ' catalogued coins (' . number_format($totalCoinsCount) .
            ' pieces in total, since some are held in multiples) from ' . $totalCountries . ' ' . $countryLabel .
            ', each recorded with its denomination, year, mint mark, metal, weight, diameter and condition grade, alongside photographs of the obverse and reverse.',
        ],
        [
            'Which countries are represented in the collection?',
            'The archive currently holds coins from ' . $totalCountries . ' ' . $countryLabel . ': ' .
            implode(', ', array_slice(array_column($countryCounts, 'country'), 0, 25)) .
            (count($countryCounts) > 25 ? ', and others.' : '.'),
        ],
        [
            'How old are the coins in the Mudra Archive?',
            'The collection spans ' . $yearRange . ', ranging from ancient issues through to modern commemorative coinage.',
        ],
        [
            'Are the coins in the Mudra Archive for sale?',
            'No. ' . SITE_NAME . ' is a private collection published for reference and for the enjoyment of other collectors; nothing on the site is for sale. ' .
            SITE_AUTHOR . ' does exchange coins with collectors abroad — enquiries go to ' . SITE_CONTACT . '.',
        ],
        [
            'How were the coins in the collection acquired?',
            'Most were collected over time through Wikimedia events and exchanges with collectors in other countries, supplemented by individual acquisitions.',
        ],
    ];

    $faqNode = [
        '@context'   => 'https://schema.org',
        '@type'      => 'FAQPage',
        '@id'        => $baseUrl . '/#faq',
        'mainEntity' => array_map(function ($f) {
            return [
                '@type'          => 'Question',
                'name'           => $f[0],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]],
            ];
        }, $faqs),
    ];

    echo json_ld($websiteNode);
    echo json_ld($curatorNode);
    echo json_ld($collectionNode);
    if (!$isFiltered && $page === 1) {
        echo json_ld($faqNode);
    }
    ?>
</head>
<body>

    <!-- Header Navigation with Solid Mobile Menu & Dark Mode Toggle -->
    <header class="app-header px-3 px-md-4 sticky-top border-bottom shadow-sm position-relative">
        <div class="container-fluid max-w-container-max p-0">
            <div class="d-flex justify-content-between align-items-center py-2">
                <a href="/" class="d-flex align-items-center gap-2 text-decoration-none">
                    <img src="/assets/logo.png" alt="Mudra Archive Logo" class="nav-logo-img" width="38" height="38"/>
                    <span class="brand-title">Mudra Archive</span>
                </a>

                <!-- Desktop Links (Collection, Countries, Statistics, About) -->
                <nav class="d-none d-md-flex align-items-center gap-4">
                    <a href="#collection" class="text-secondary text-decoration-none font-label-archival fw-bold">Collection</a>
                    <a href="#countriesMap" class="text-secondary text-decoration-none font-label-archival fw-bold">Countries</a>
                    <a href="#statistics" class="text-secondary text-decoration-none font-label-archival fw-bold">Statistics</a>
                    <a href="#about" class="text-secondary text-decoration-none font-label-archival fw-bold">About</a>
                </nav>

                <div class="d-flex align-items-center gap-2">
                    <!-- Dark Mode Toggle Button -->
                    <button id="themeToggle" class="btn btn-outline-archival p-2 d-flex align-items-center justify-content-center" onclick="toggleTheme()" aria-label="Toggle Dark Mode">
                        <span id="themeIcon" class="material-symbols-outlined">dark_mode</span>
                    </button>

                    <!-- Mobile Hamburger Button -->
                    <button class="btn btn-outline-archival d-md-none p-2 d-flex align-items-center justify-content-center" type="button" onclick="toggleMobileNav(event)" aria-label="Toggle Navigation Menu">
                        <span class="material-symbols-outlined fs-4">menu</span>
                    </button>

                    <?php if (is_admin_logged_in()): ?>
                        <a href="admin/dashboard.php" class="btn btn-sm btn-outline-archival d-none d-md-inline-flex align-items-center gap-1">
                            <span class="material-symbols-outlined fs-6">dashboard</span>
                            <span>Dashboard</span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Fixed Dropdown Mobile Navigation Menu (100% Opaque Solid Background) -->
            <div id="mobileNavMenu" class="d-md-none">
                <div class="d-flex flex-column gap-2">
                    <a href="#collection" onclick="closeMobileNav()" class="btn btn-outline-archival text-start d-flex align-items-center gap-2 py-2">
                        <span class="material-symbols-outlined">grid_view</span>
                        <span>Collection</span>
                    </a>
                    <a href="#countriesMap" onclick="closeMobileNav()" class="btn btn-outline-archival text-start d-flex align-items-center gap-2 py-2">
                        <span class="material-symbols-outlined">public</span>
                        <span>Countries Map</span>
                    </a>
                    <a href="#statistics" onclick="closeMobileNav()" class="btn btn-outline-archival text-start d-flex align-items-center gap-2 py-2">
                        <span class="material-symbols-outlined">analytics</span>
                        <span>Statistics</span>
                    </a>
                    <a href="#about" onclick="closeMobileNav()" class="btn btn-outline-archival text-start d-flex align-items-center gap-2 py-2">
                        <span class="material-symbols-outlined">info</span>
                        <span>About the Archive</span>
                    </a>
                    <?php if (is_admin_logged_in()): ?>
                        <a href="admin/dashboard.php" class="btn btn-primary-archival text-start d-flex align-items-center gap-2 mt-1 py-2">
                            <span class="material-symbols-outlined">dashboard</span>
                            <span>Admin Dashboard</span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content Canvas -->
    <main class="flex-grow-1 py-4 px-3 px-md-4 container-lg">

        <!-- SECTION 1: Personal Museum Hero Section -->
        <section class="museum-hero mb-4 shadow-sm">
            <div class="row align-items-center g-3 g-md-4">
                <div class="col-12 col-md-7">
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-1 rounded-pill mb-2 font-label-archival">Digital Numismatic Museum</span>
                    <h1 class="display-5 font-heading text-primary fw-bold mb-2">Mudra Archive</h1>
                    <p class="fs-5 text-secondary leading-relaxed mb-3">
                        An evolving personal collection of coins from around the world.
                    </p>

                    <!-- Dynamic 4-Metric Museum Statistics Grid (Coins, Countries, Currencies, Era Span) -->
                    <div id="statistics" class="row row-cols-2 row-cols-md-4 g-2 g-md-3">
                        <div class="col">
                            <div class="stat-pill" title="<?= number_format($totalCoinsCount) ?> pieces held across <?= number_format($totalEntries) ?> catalogue entries">
                                <div class="stat-pill-label">Coins</div>
                                <div class="stat-pill-value text-primary"><?= number_format($totalCoinsCount) ?></div>
                                <?php if ($totalEntries !== $totalCoinsCount): ?>
                                    <!-- Pieces held and catalogue entries are different numbers whenever
                                         a coin is held in multiples. Both were previously labelled
                                         "coins", which read as a contradiction against the gallery. -->
                                    <div class="stat-pill-note"><?= number_format($totalEntries) ?> entries</div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col">
                            <div class="stat-pill">
                                <div class="stat-pill-label">Countries</div>
                                <div class="stat-pill-value text-secondary"><?= number_format($totalCountries) ?></div>
                            </div>
                        </div>
                        <div class="col">
                            <div class="stat-pill">
                                <div class="stat-pill-label">Currencies</div>
                                <div class="stat-pill-value"><?= number_format($totalCurrencies) ?></div>
                            </div>
                        </div>
                        <div class="col">
                            <div class="stat-pill">
                                <div class="stat-pill-label">Era Span</div>
                                <div class="stat-pill-value text-success" title="<?= sanitize($yearRange) ?>"><?= sanitize($yearRange) ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Visual Coin Composition Showcase (Fanned Overlapping Museum Coin Deck) -->
                <div class="col-12 col-md-5">
                    <div class="hero-coin-stack">
                        <?php foreach ($heroCoins as $hIndex => $hc): ?>
                            <?php
                                $hThumb = get_coin_thumbnail_path($hc['obverse_image']);
                                $hTitle = coin_display_title($hc);
                            ?>
                            <a href="<?= sanitize(coin_path($hc)) ?>" class="hero-coin-link" title="<?= sanitize($hTitle) ?>">
                                <img src="<?= sanitize($hThumb) ?>" alt="<?= sanitize($hTitle) ?>" class="hero-coin-card"
                                     width="120" height="120" loading="eager" decoding="async"
                                     <?= $hIndex === 0 ? 'fetchpriority="high"' : '' ?>/>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </section>

        <!-- SECTION 2: World Map View (OpenStreetMap + Leaflet.js Real Geographic Map with ALL Collection Countries) -->
        <section id="countriesMap" class="card border-0 shadow-sm rounded-4 p-3 p-md-4 mb-4">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
                <div>
                    <h2 class="font-heading text-primary fs-3 mb-0 d-flex align-items-center gap-2">
                        <span class="material-symbols-outlined text-primary fs-3">public</span>
                        <span>Interactive Numismatic World Map</span>
                    </h2>
                    <p class="text-muted small mb-0">Explore <?= count($leafletMarkers) ?> mapped coin origin regions across OpenStreetMap. Tap any pulsing gold pin to filter entries.</p>
                </div>

                <!-- Map Legend & Control Buttons -->
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <button class="btn btn-sm btn-outline-archival d-inline-flex align-items-center gap-1" onclick="resetMapView()">
                        <span class="material-symbols-outlined fs-6">center_focus_strong</span>
                        <span>Fit All Pins</span>
                    </button>
                    <!-- Full Screen Map Button -->
                    <button id="mapFullscreenBtn" class="btn btn-sm btn-outline-archival d-inline-flex align-items-center gap-1" onclick="toggleMapFullscreen()">
                        <span class="material-symbols-outlined fs-6" id="mapFullscreenIcon">fullscreen</span>
                        <span id="mapFullscreenText">Full Screen</span>
                    </button>
                </div>
            </div>

            <!-- OpenStreetMap Leaflet Container -->
            <div class="position-relative border p-1 mb-3 overflow-hidden shadow-sm rounded-3">
                <div id="leafletMap"></div>
            </div>

            <!-- Compact & Space-Saving Country List (Horizontal Scroll & Dropdown) -->
            <div class="border-top pt-3">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <h3 class="font-label-archival text-muted mb-0">Represented Countries (<?= count($countryCounts) ?>):</h3>
                    <a href="<?= home_url([], 'collection') ?>" class="small text-primary text-decoration-none font-label-archival">View All</a>
                </div>

                <!-- Mobile Compact Select Dropdown -->
                <div class="d-block d-md-none mb-2">
                    <select class="form-select form-select-archival" onchange="if(this.value) window.location.href=this.value;">
                        <option value="<?= home_url([], 'collection') ?>">Filter by Country...</option>
                        <?php foreach ($countryCounts as $cc): ?>
                            <option value="<?= sanitize(home_url(['country' => $cc['country']], 'collection')) ?>" <?= ($countryFilter === $cc['country']) ? 'selected' : '' ?>>
                                <?= sanitize($cc['country']) ?> (<?= $cc['count'] ?> coin<?= $cc['count'] === 1 ? '' : 's' ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Desktop & Tablet Horizontal Scroll Bar -->
                <div class="d-none d-md-flex align-items-center gap-2 overflow-x-auto country-scroll-bar pb-1">
                    <a href="<?= home_url([], 'collection') ?>" class="btn btn-sm <?= empty($countryFilter) ? 'btn-primary-archival' : 'btn-outline-archival' ?> text-nowrap">All Countries</a>
                    <?php foreach ($countryCounts as $cc): ?>
                        <a href="<?= sanitize(home_url(['country' => $cc['country']], 'collection')) ?>" class="btn btn-sm <?= ($countryFilter === $cc['country']) ? 'btn-primary-archival' : 'btn-outline-archival' ?> text-nowrap d-inline-flex align-items-center gap-1">
                            <span><?= sanitize($cc['country']) ?></span>
                            <span class="badge bg-secondary-subtle text-dark ms-1"><?= $cc['count'] ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- SECTION 3: Public Collection Gallery Grid -->
        <section id="collection">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <div class="d-flex align-items-center gap-2">
                    <a href="<?= home_url([], 'collection') ?>" class="btn btn-sm <?= ($featuredFilter !== 1) ? 'btn-primary-archival' : 'btn-outline-archival' ?>">All Collection</a>
                    <a href="<?= home_url(['featured' => '1'], 'collection') ?>" class="btn btn-sm <?= ($featuredFilter === 1) ? 'btn-primary-archival' : 'btn-outline-archival' ?> d-inline-flex align-items-center gap-1">
                        <span class="material-symbols-outlined fs-6">star</span>
                        <span>Featured Showcase (<?= $featuredCount ?>)</span>
                    </a>
                </div>
                <div class="small text-muted d-none d-sm-block">
                    <!-- "entries", not "coins": this counts catalogue records, while the
                         Coins statistic counts pieces held. Naming the unit is what stops
                         the two figures reading as a contradiction. -->
                    Showing <?= count($coins) ?> of <?= number_format($totalMatching) ?> entr<?= $totalMatching === 1 ? 'y' : 'ies' ?><?= $totalPages > 1 ? ' (Page ' . $page . ' of ' . $totalPages . ')' : '' ?>
                </div>
            </div>

            <!-- Search & Desktop Filter Bar -->
            <form method="GET" action="/" id="galleryForm" class="mb-4">
                <?php
                    // Carry the filters this bar does not expose. Without these the
                    // desktop search silently dropped an active currency or year
                    // filter while the badges above still claimed it was applied.
                    if ($featuredFilter === 1) {
                        echo '<input type="hidden" name="featured" value="1"/>';
                    }
                    if ($currencyFilter !== '') {
                        echo '<input type="hidden" name="currency" value="' . sanitize($currencyFilter) . '"/>';
                    }
                    if ($yearMin !== null) {
                        echo '<input type="hidden" name="year_min" value="' . (int)$yearMin . '"/>';
                    }
                    if ($yearMax !== null) {
                        echo '<input type="hidden" name="year_max" value="' . (int)$yearMax . '"/>';
                    }
                ?>

                <div class="row g-2 align-items-center">
                    <div class="col-12 col-md-5">
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0 border-outline-variant">
                                <span class="material-symbols-outlined text-muted">search</span>
                            </span>
                            <input type="text" name="search" aria-label="Search Collection" class="form-control form-control-archival border-start-0 ps-0" placeholder="Search country, currency, era..." value="<?= sanitize($search) ?>"/>
                        </div>
                    </div>

                    <div class="col-8 col-md-4 d-none d-md-flex gap-2">
                        <select name="country" aria-label="Filter by Country" class="form-select form-select-archival" onchange="this.form.submit()">
                            <option value="">All Countries</option>
                            <?php foreach ($countries as $c): ?>
                                <option value="<?= sanitize($c) ?>" <?= $countryFilter === $c ? 'selected' : '' ?>><?= sanitize($c) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <select name="grade" aria-label="Filter by Grade" class="form-select form-select-archival" onchange="this.form.submit()">
                            <option value="">All Grades</option>
                            <?php foreach ($grades as $g): ?>
                                <option value="<?= sanitize($g) ?>" <?= $gradeFilter === $g ? 'selected' : '' ?>><?= sanitize($g) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-6 col-md-3">
                        <select name="sort" aria-label="Sort Collection" class="form-select form-select-archival" onchange="this.form.submit()">
                            <option value="date_desc" <?= $sortBy === 'date_desc' ? 'selected' : '' ?>>Sort: Date Added (Newest)</option>
                            <option value="date_asc" <?= $sortBy === 'date_asc' ? 'selected' : '' ?>>Sort: Date Added (Oldest)</option>
                            <option value="country_asc" <?= $sortBy === 'country_asc' ? 'selected' : '' ?>>Sort: Country (A-Z)</option>
                            <option value="year_asc" <?= $sortBy === 'year_asc' ? 'selected' : '' ?>>Sort: Year (Ascending)</option>
                            <option value="year_desc" <?= $sortBy === 'year_desc' ? 'selected' : '' ?>>Sort: Year (Descending)</option>
                        </select>
                    </div>

                    <div class="col-6 col-md-auto d-md-none text-end">
                        <button class="btn btn-outline-archival w-100 d-flex align-items-center justify-content-center gap-1" type="button" onclick="toggleMobileFilters(event)">
                            <span class="material-symbols-outlined fs-5">tune</span>
                            <span>Filters (<?= $activeFilterCount ?>)</span>
                        </button>
                    </div>
                </div>
            </form>

            <!-- Direct Expandable Mobile Filter Panel (Hidden by default) -->
            <div id="mobileFilterPanel" class="card border-0 shadow-sm p-4 rounded-3 mb-4" style="background-color: var(--surface-bright); border: 1px solid var(--primary-color) !important;">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="font-heading text-primary fs-5 mb-0 d-flex align-items-center gap-1">
                        <span class="material-symbols-outlined">tune</span>
                        <span>Filter Collection</span>
                    </h2>
                    <button type="button" class="btn-close" onclick="toggleMobileFilters(event)" aria-label="Close Mobile Filters"></button>
                </div>
                
                <form method="GET" action="/">
                    <div class="mb-3">
                        <label class="form-label-archival">Search Keywords</label>
                        <input type="text" name="search" class="form-control form-control-archival" placeholder="Search era, country, notes..." value="<?= sanitize($search) ?>"/>
                    </div>

                    <div class="mb-3">
                        <label class="form-label-archival">Showcase Filter</label>
                        <select name="featured" class="form-select form-select-archival">
                            <option value="">All Coins</option>
                            <option value="1" <?= $featuredFilter === 1 ? 'selected' : '' ?>>⭐ Featured Showcase Only</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label-archival">Country / Region</label>
                        <select name="country" class="form-select form-select-archival">
                            <option value="">All Countries</option>
                            <?php foreach ($countries as $c): ?>
                                <option value="<?= sanitize($c) ?>" <?= $countryFilter === $c ? 'selected' : '' ?>><?= sanitize($c) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label-archival">Currency Name</label>
                        <select name="currency" class="form-select form-select-archival">
                            <option value="">All Currencies</option>
                            <?php foreach ($currencies as $cur): ?>
                                <option value="<?= sanitize($cur) ?>" <?= $currencyFilter === $cur ? 'selected' : '' ?>><?= sanitize($cur) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label-archival">Condition / Grade</label>
                        <select name="grade" class="form-select form-select-archival">
                            <option value="">All Grades</option>
                            <?php foreach ($grades as $g): ?>
                                <option value="<?= sanitize($g) ?>" <?= $gradeFilter === $g ? 'selected' : '' ?>><?= sanitize($g) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label-archival">Min Year</label>
                            <input type="number" name="year_min" class="form-control form-control-archival" placeholder="e.g. 1800" value="<?= $yearMin ?? '' ?>"/>
                        </div>
                        <div class="col-6">
                            <label class="form-label-archival">Max Year</label>
                            <input type="number" name="year_max" class="form-control form-control-archival" placeholder="e.g. 1950" value="<?= $yearMax ?? '' ?>"/>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label-archival">Sort Order</label>
                        <select name="sort" class="form-select form-select-archival">
                            <option value="date_desc" <?= $sortBy === 'date_desc' ? 'selected' : '' ?>>Date Added (Newest)</option>
                            <option value="date_asc" <?= $sortBy === 'date_asc' ? 'selected' : '' ?>>Date Added (Oldest)</option>
                            <option value="country_asc" <?= $sortBy === 'country_asc' ? 'selected' : '' ?>>Country (A-Z)</option>
                            <option value="year_asc" <?= $sortBy === 'year_asc' ? 'selected' : '' ?>>Year (Ascending)</option>
                            <option value="year_desc" <?= $sortBy === 'year_desc' ? 'selected' : '' ?>>Year (Descending)</option>
                        </select>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary-archival">Apply Mobile Filters</button>
                        <a href="<?= home_url([], 'collection') ?>" class="btn btn-outline-secondary">Reset Filters</a>
                    </div>
                </form>
            </div>

            <!-- Active Filter Badges -->
            <?php if ($activeFilterCount > 0 || $search !== ''): ?>
                <div class="d-flex flex-wrap align-items-center gap-2 mb-4">
                    <span class="text-muted small">Active Filters:</span>
                    <?php if ($search !== ''): ?>
                        <span class="badge bg-secondary-subtle text-dark border px-2 py-1">Query: "<?= sanitize($search) ?>"</span>
                    <?php endif; ?>
                    <?php if ($featuredFilter === 1): ?>
                        <span class="badge badge-featured px-2 py-1">Featured Showcase</span>
                    <?php endif; ?>
                    <?php if ($countryFilter !== ''): ?>
                        <span class="badge bg-secondary-subtle text-dark border px-2 py-1">Country: <?= sanitize($countryFilter) ?></span>
                    <?php endif; ?>
                    <?php if ($currencyFilter !== ''): ?>
                        <span class="badge bg-secondary-subtle text-dark border px-2 py-1">Currency: <?= sanitize($currencyFilter) ?></span>
                    <?php endif; ?>
                    <?php if ($gradeFilter !== ''): ?>
                        <span class="badge bg-secondary-subtle text-dark border px-2 py-1">Grade: <?= sanitize($gradeFilter) ?></span>
                    <?php endif; ?>
                    <?php if ($yearMin !== null || $yearMax !== null): ?>
                        <span class="badge bg-secondary-subtle text-dark border px-2 py-1">Year: <?= $yearMin ?? 'Any' ?> - <?= $yearMax ?? 'Any' ?></span>
                    <?php endif; ?>
                    <a href="<?= home_url([], 'collection') ?>" class="btn btn-link text-decoration-none p-0 ms-2 text-danger small">Clear All</a>
                </div>
            <?php endif; ?>

            <!-- Coin Grid Section with 3D Rotating Flipping Medallions & Lazy Loading -->
            <?php if (count($coins) > 0): ?>
                <div class="row row-cols-2 row-cols-md-3 row-cols-lg-4 g-3 g-md-4 mb-4">
                    <?php foreach ($coins as $coin): ?>
                        <?php
                            $obvThumb = get_coin_thumbnail_path($coin['obverse_image'] ?? '');
                            $hasRev = !empty($coin['reverse_image']) && file_exists(__DIR__ . '/assets/uploads/' . $coin['reverse_image']);
                            $revThumb = $hasRev ? get_coin_thumbnail_path($coin['reverse_image']) : $obvThumb;
                            
                            $matLower = strtolower(trim($coin['material'] ?? ''));
                            if ($matLower === 'gold') {
                                $matClass = 'badge-metal-gold';
                            } elseif ($matLower === 'silver' || $matLower === 'platinum') {
                                $matClass = 'badge-metal-silver';
                            } elseif ($matLower === 'copper' || $matLower === 'bronze' || $matLower === 'brass') {
                                $matClass = 'badge-metal-copper';
                            } else {
                                $matClass = 'badge-metal-default';
                            }
                            $coinAltText = sanitize($coin['denomination']) . ' coin from ' . sanitize($coin['country']) . ' (' . sanitize($coin['year']) . ')';
                        ?>
                        <div class="col">
                            <div class="coin-card">
                                <div class="coin-3d-scene" onclick="toggleCoinFlip(this, event)">
                                    <div class="coin-3d-flipper <?= $hasRev ? 'has-reverse' : '' ?>">
                                        <!-- Obverse (Front Face - Pure Unobstructed Artwork) -->
                                        <div class="coin-face coin-face-obv">
                                            <img src="<?= sanitize($obvThumb) ?>" alt="<?= $coinAltText ?>" loading="lazy" decoding="async" width="220" height="220"/>
                                            <?php if ((int)$coin['is_featured'] === 1): ?>
                                                <span class="badge-featured-mini" title="Featured Collection">
                                                    <span class="material-symbols-outlined">star</span>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <!-- Reverse (Back Face - 3D Flipped on Hover/Tap) -->
                                        <?php if ($hasRev): ?>
                                            <div class="coin-face coin-face-rev">
                                                <img src="<?= sanitize($revThumb) ?>" alt="<?= $coinAltText ?> Reverse" loading="lazy" decoding="async" width="220" height="220"/>
                                                <span class="badge-rev-indicator">REV</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($hasRev): ?>
                                        <span class="flip-hint-badge" title="Tap or hover to flip coin">
                                            <span class="material-symbols-outlined">sync</span>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <a href="<?= sanitize(coin_path($coin)) ?>" class="coin-card-link mt-auto w-100 text-decoration-none" title="<?= sanitize(coin_display_title($coin)) ?>">
                                    <!-- h3, not h2: these sit under the "Collection" section
                                         heading. 60 sibling h2s flattened the outline. -->
                                    <h3 class="coin-card-title h5 mb-1"><?= sanitize($coin['denomination']) ?></h3>
                                    <div class="coin-card-sub"><?= sanitize($coin['country']) ?></div>
                                    <div class="d-flex align-items-center justify-content-center gap-1 mt-1 flex-wrap">
                                        <span class="coin-card-year"><?= sanitize($coin['year']) ?></span>
                                        <?php if (!empty($coin['material'])): ?>
                                            <span class="text-muted small">&bull;</span>
                                            <span class="coin-card-material <?= $matClass ?>"><?= sanitize($coin['material']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination Bar (<?= COINS_PER_PAGE ?> coins per page) -->
                <?php if ($totalPages > 1): ?>
                    <?php
                        // A window around the current page rather than every page
                        // number: once the collection grows, printing all of them
                        // produces an unusable bar and a wall of links on every page.
                        $window     = 2;
                        $windowFrom = max(1, $page - $window);
                        $windowTo   = min($totalPages, $page + $window);
                    ?>
                    <nav aria-label="Coin gallery pagination" class="d-flex justify-content-center my-4">
                        <ul class="pagination pagination-md mb-0 flex-wrap">
                            <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                                <a class="page-link border-outline-variant text-dark" rel="prev"
                                   href="<?= $page > 1 ? sanitize(pagination_url($currentFilters, $page - 1)) : '#' ?>">Previous</a>
                            </li>

                            <?php if ($windowFrom > 1): ?>
                                <li class="page-item"><a class="page-link border-outline-variant text-dark" href="<?= sanitize(pagination_url($currentFilters, 1)) ?>">1</a></li>
                                <?php if ($windowFrom > 2): ?>
                                    <li class="page-item disabled"><span class="page-link border-outline-variant">…</span></li>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php for ($p = $windowFrom; $p <= $windowTo; $p++): ?>
                                <li class="page-item <?= ($p === $page) ? 'active' : '' ?>">
                                    <a class="page-link border-outline-variant <?= ($p === $page) ? 'bg-primary border-primary text-white' : 'text-dark' ?>"
                                       <?= $p === $page ? 'aria-current="page"' : '' ?>
                                       href="<?= sanitize(pagination_url($currentFilters, $p)) ?>"><?= $p ?></a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($windowTo < $totalPages): ?>
                                <?php if ($windowTo < $totalPages - 1): ?>
                                    <li class="page-item disabled"><span class="page-link border-outline-variant">…</span></li>
                                <?php endif; ?>
                                <li class="page-item"><a class="page-link border-outline-variant text-dark" href="<?= sanitize(pagination_url($currentFilters, $totalPages)) ?>"><?= $totalPages ?></a></li>
                            <?php endif; ?>

                            <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                                <a class="page-link border-outline-variant text-dark" rel="next"
                                   href="<?= $page < $totalPages ? sanitize(pagination_url($currentFilters, $page + 1)) : '#' ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>

            <?php else: ?>
                <div class="text-center py-5 bg-white border rounded p-4">
                    <span class="material-symbols-outlined text-muted display-4 mb-3">search_off</span>
                    <h2 class="font-heading h4">No Coins Found</h2>
                    <p class="text-muted">No numismatic records matched your selected criteria or search term.</p>
                    <a href="<?= home_url([], 'collection') ?>" class="btn btn-primary-archival mt-2">Reset All Filters</a>
                </div>
            <?php endif; ?>
        </section>

        <!-- SECTION 4: About the Collection (Personal Digital Archive Reframing) -->
        <section id="about" class="card border-0 shadow-sm rounded-4 p-4 mt-5 mb-4">
            <div class="d-flex align-items-start gap-3">
                <div class="rounded-circle p-3 d-flex align-items-center justify-content-center flex-shrink-0" style="background-color: var(--primary-fixed); color: var(--primary-color);">
                    <span class="material-symbols-outlined fs-2">museum</span>
                </div>
                <div class="flex-grow-1">
                    <h2 class="font-heading text-primary fs-3 mb-2">About the Collection</h2>
                    <div id="bioText" class="bio-collapsed text-secondary leading-relaxed">
                        <p><?= sanitize(SITE_NAME) ?> is the personal coin collection of <?= sanitize(SITE_AUTHOR) ?>, catalogued and published as an open reference. It currently holds <strong><?= number_format($totalEntries) ?> catalogued coins</strong><?= $totalEntries !== $totalCoinsCount ? ' (' . number_format($totalCoinsCount) . ' pieces in total, since some are held in multiples)' : '' ?> from <strong><?= number_format($totalCountries) ?> <?= sanitize($countryLabel) ?></strong>, covering <?= sanitize($yearRange) ?>, across <?= number_format($totalCurrencies) ?> distinct currencies.</p>
                        <p>Most of these coins were gathered over time through Wikimedia events and through exchanges with collectors in other countries. Every entry records its denomination, year of issue, mint mark, metal, weight, diameter and condition grade, and is photographed on both the obverse and reverse faces so the details can be examined directly.</p>
                        <p class="mb-0">If you would like to exchange your country's coin for Bangladeshi Taka, write to <a href="mailto:<?= sanitize(SITE_CONTACT) ?>" class="text-primary fw-bold text-decoration-none"><?= sanitize(SITE_CONTACT) ?></a>. This archive exists to preserve global numismatic history, celebrate international exchange and record unusual coinage from around the world. Nothing here is for sale.</p>
                    </div>
                    <button type="button" id="bioToggleBtn" onclick="toggleBio()" class="btn btn-link text-primary p-0 border-0 mt-2 font-label-archival text-decoration-none d-inline-flex align-items-center gap-1">
                        <span>Show Full Details</span>
                        <span class="material-symbols-outlined fs-6" id="bioToggleIcon" aria-hidden="true">expand_more</span>
                    </button>
                </div>
            </div>
        </section>

        <!-- SECTION 5: Questions this archive answers.
             These are the phrasings people search for, answered in full on the
             page. The matching FAQPage structured data in <head> is only valid
             because the same text is visible here. -->
        <?php if (!$isFiltered && $page === 1): ?>
        <section id="faq" class="card border-0 shadow-sm rounded-4 p-4 mb-4" aria-labelledby="faq-heading">
            <h2 id="faq-heading" class="font-heading text-primary fs-3 mb-3 d-flex align-items-center gap-2">
                <span class="material-symbols-outlined fs-2" aria-hidden="true">help</span>
                <span>Frequently Asked Questions</span>
            </h2>
            <div class="accordion accordion-flush" id="faqAccordion">
                <?php foreach ($faqs as $fi => $faq): ?>
                    <div class="accordion-item bg-transparent">
                        <h3 class="accordion-header" id="faqHeading<?= $fi ?>">
                            <button class="accordion-button <?= $fi === 0 ? '' : 'collapsed' ?> bg-transparent px-0 font-heading fs-6"
                                    type="button" data-bs-toggle="collapse" data-bs-target="#faqBody<?= $fi ?>"
                                    aria-expanded="<?= $fi === 0 ? 'true' : 'false' ?>" aria-controls="faqBody<?= $fi ?>">
                                <?= sanitize($faq[0]) ?>
                            </button>
                        </h3>
                        <div id="faqBody<?= $fi ?>" class="accordion-collapse collapse <?= $fi === 0 ? 'show' : '' ?>"
                             aria-labelledby="faqHeading<?= $fi ?>" data-bs-parent="#faqAccordion">
                            <div class="accordion-body px-0 text-secondary leading-relaxed"><?= sanitize($faq[1]) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

    </main>

    <!-- Footer (Spec Requirement: A personal digital archive of Muhammad Yahya's numismatic collection) -->
    <footer class="mt-auto py-4 bg-white border-top text-center text-muted small">
        <div class="container">
            <p class="mb-1">&copy; <?= date('Y') ?> <?= sanitize(SITE_NAME) ?> &bull; A personal digital archive of <?= sanitize(SITE_AUTHOR) ?>'s numismatic collection.</p>
            <p class="mb-0 text-secondary" style="font-size: 0.8rem;">
                <a href="<?= home_url([], 'about') ?>" class="text-muted text-decoration-underline">About</a> &bull;
                <a href="<?= home_url([], 'faq') ?>" class="text-muted text-decoration-underline">FAQ</a> &bull;
                <a href="mailto:<?= sanitize(SITE_CONTACT) ?>" class="text-muted text-decoration-underline">Contact</a> &bull;
                <a href="/sitemap.xml" class="text-muted text-decoration-underline">XML Sitemap</a>
            </p>
        </div>
    </footer>

    <!-- Interactive Scripts for OpenStreetMap, Full Screen & Leaflet Map Controls -->
    <script>
        const markerData = <?= json_encode($leafletMarkers, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        let leafletMapInstance = null;
        let leafletFeatureGroup = null;

        document.addEventListener('DOMContentLoaded', function() {
            // Update Theme Icon on load
            const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
            updateThemeIcon(currentTheme);

            // Close mobile menu on click outside
            document.addEventListener('click', function(e) {
                const nav = document.getElementById('mobileNavMenu');
                const btn = e.target.closest('button[onclick*="toggleMobileNav"]');
                if (nav && (nav.classList.contains('active') || nav.classList.contains('show')) && !nav.contains(e.target) && !btn) {
                    nav.classList.remove('active');
                    nav.classList.remove('show');
                }
            });

            // Leaflet (~150 KB of JS and CSS) is fetched only once the map is
            // about to enter the viewport. Loading it up front delayed first
            // paint for every visitor, including those who never scroll to it.
            const mapEl = document.getElementById('leafletMap');
            if (mapEl) {
                const loadMap = function() {
                    if (window.L) { initLeafletMap(); return; }

                    const css = document.createElement('link');
                    css.rel = 'stylesheet';
                    css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                    document.head.appendChild(css);

                    const js = document.createElement('script');
                    js.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                    js.onload = initLeafletMap;
                    document.body.appendChild(js);
                };

                if ('IntersectionObserver' in window) {
                    const io = new IntersectionObserver(function(entries, obs) {
                        if (entries.some(function(e) { return e.isIntersecting; })) {
                            obs.disconnect();
                            loadMap();
                        }
                    }, { rootMargin: '400px' });
                    io.observe(mapEl);
                } else {
                    loadMap();
                }
            }
        });

        function initLeafletMap() {
            if (leafletMapInstance || !window.L) return;

            // Initialize OpenStreetMap Leaflet Engine
            leafletMapInstance = L.map('leafletMap', {
                center: [25, 20],
                zoom: 2,
                scrollWheelZoom: false
            });

            // OpenStreetMap Standard Free Tiles (No API key required)
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank">OpenStreetMap</a> contributors'
            }).addTo(leafletMapInstance);

            leafletFeatureGroup = L.featureGroup();

            // Country names come from the database and are interpolated into the
            // popup markup below, so they are escaped first.
            const escapeHtml = function(value) {
                return String(value).replace(/[&<>"']/g, function(ch) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
                });
            };

            // High-Tech Custom Pulsing Gold Markers for ALL Collection Countries
            markerData.forEach(m => {
                const pulseHtml = `
                    <div class="pulse-marker-container">
                        <div class="pulse-marker-ring"></div>
                        <div class="pulse-marker-core">${Number(m.count)}</div>
                    </div>
                `;
                
                const customIcon = L.divIcon({
                    html: pulseHtml,
                    className: 'custom-leaflet-div-icon',
                    iconSize: [32, 32],
                    iconAnchor: [16, 16],
                    popupAnchor: [0, -16]
                });

                const marker = L.marker([m.lat, m.lng], { icon: customIcon });

                const popupHtml = `
                    <div style="text-align: center; padding: 6px 4px; min-width: 140px;">
                        <h6 style="margin: 0 0 2px 0; font-family: 'Playfair Display', Georgia, serif; font-weight: 700; font-size: 1rem;">${escapeHtml(m.country)}</h6>
                        <div style="font-size: 0.85rem; color: #64748b; margin-bottom: 8px;">${Number(m.count)} coin${m.count === 1 ? '' : 's'} in archive</div>
                        <a href="/?country=${encodeURIComponent(m.country)}#collection" class="btn btn-sm btn-primary-archival py-1 px-3 text-white text-decoration-none d-block" style="font-size: 0.75rem;">View Coins</a>
                    </div>
                `;
                marker.bindPopup(popupHtml);
                leafletFeatureGroup.addLayer(marker);
            });

            leafletFeatureGroup.addTo(leafletMapInstance);

            // Auto fit bounds if markers exist and trigger invalidateSize
            if (markerData.length > 0) {
                leafletMapInstance.fitBounds(leafletFeatureGroup.getBounds().pad(0.2));
            }

            setTimeout(function() {
                if (leafletMapInstance) {
                    leafletMapInstance.invalidateSize();
                }
            }, 300);

            window.addEventListener('resize', function() {
                if (leafletMapInstance) {
                    leafletMapInstance.invalidateSize();
                }
            });
        }

        function resetMapView() {
            if (leafletMapInstance && leafletFeatureGroup && markerData.length > 0) {
                leafletMapInstance.fitBounds(leafletFeatureGroup.getBounds().pad(0.2));
            }
        }

        // Full Screen Toggle Feature
        function toggleMapFullscreen() {
            const mapCard = document.getElementById('countriesMap');
            const icon = document.getElementById('mapFullscreenIcon');
            const text = document.getElementById('mapFullscreenText');

            if (!document.fullscreenElement && !document.webkitFullscreenElement) {
                if (mapCard.requestFullscreen) {
                    mapCard.requestFullscreen();
                } else if (mapCard.webkitRequestFullscreen) {
                    mapCard.webkitRequestFullscreen();
                } else {
                    // Fallback CSS fullscreen
                    mapCard.classList.toggle('map-fullscreen-active');
                    updateFullscreenUI(mapCard.classList.contains('map-fullscreen-active'));
                }
            } else {
                if (document.exitFullscreen) {
                    document.exitFullscreen();
                } else if (document.webkitExitFullscreen) {
                    document.webkitExitFullscreen();
                }
            }
        }

        function updateFullscreenUI(isFull) {
            const icon = document.getElementById('mapFullscreenIcon');
            const text = document.getElementById('mapFullscreenText');
            if (icon) icon.innerText = isFull ? 'fullscreen_exit' : 'fullscreen';
            if (text) text.innerText = isFull ? 'Exit Fullscreen' : 'Full Screen';
            
            setTimeout(function() {
                if (leafletMapInstance) {
                    leafletMapInstance.invalidateSize();
                    if (leafletFeatureGroup && markerData.length > 0) {
                        leafletMapInstance.fitBounds(leafletFeatureGroup.getBounds().pad(0.15));
                    }
                }
            }, 250);
        }

        document.addEventListener('fullscreenchange', function() {
            updateFullscreenUI(!!document.fullscreenElement);
        });
        document.addEventListener('webkitfullscreenchange', function() {
            updateFullscreenUI(!!document.webkitFullscreenElement);
        });
    </script>
    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" defer></script>
</body>
</html>
