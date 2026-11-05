<?php
// index.php - Mudra Archive Personal Digital Numismatic Museum & Public Collection
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';

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

// Landscape card for link previews, composed from the featured coins. Falls
// back to the logo if GD is unavailable or no coin has a photograph yet.
$socialPath = collection_social_image($heroCoins) ?: placeholder_image_path();
$socialUrl  = absolute_url($socialPath, $baseUrl);
$socialDims = image_dimensions($socialPath);

// rel=prev/next are machine-facing, so they carry no #collection fragment.
$prevUrl = $page > 1 ? $baseUrl . home_url($currentFilters + ($page - 1 > 1 ? ['page' => $page - 1] : [])) : null;
$nextUrl = $page < $totalPages ? $baseUrl . home_url($currentFilters + ['page' => $page + 1]) : null;

// Atlas: per-country coin counts, ISO codes and sample coins for the world map.
// The share of the world is counted by distinct modern ISO codes, so a
// historical issuer such as the Soviet Union does not inflate it.
$atlasItems = get_atlas_payload($pdo, $countryCounts);
$atlasIsos  = array_unique(array_filter(array_column($countryCounts, 'iso')));
$atlasPct   = min(100, (int)round(count($atlasIsos) / 195 * 100));
$atlasMax   = $countryCounts ? max(1, max(array_column($countryCounts, 'count'))) : 1;

// Hero showcase: the first coin takes the centre of the orbit, the rest circle it.
$orbitSlots = ['main', 's1', 's2', 's3'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <meta name="robots" content="<?= sanitize($robotsDirective) ?>"/>
    <meta name="theme-color" content="#f6f1e7"/>
    <?= theme_boot_script() ?>

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
    <meta property="og:image" content="<?= sanitize($socialUrl) ?>"/>
    <meta property="og:image:secure_url" content="<?= sanitize($socialUrl) ?>"/>
    <?php if ($socialDims): ?>
        <meta property="og:image:width" content="<?= (int)$socialDims['width'] ?>"/>
        <meta property="og:image:height" content="<?= (int)$socialDims['height'] ?>"/>
        <meta property="og:image:type" content="<?= sanitize($socialDims['mime']) ?>"/>
    <?php endif; ?>
    <meta property="og:image:alt" content="Coins from the <?= sanitize(SITE_NAME) ?> collection"/>

    <!-- Twitter Card Meta Tags (Absolute URLs) -->
    <meta name="twitter:card" content="summary_large_image"/>
    <meta name="twitter:url" content="<?= sanitize($canonicalUrl) ?>"/>
    <meta name="twitter:title" content="<?= sanitize($pageTitle) ?>"/>
    <meta name="twitter:description" content="<?= sanitize(truncate_text($pageDescription, 200)) ?>"/>
    <meta name="twitter:image" content="<?= sanitize($socialUrl) ?>"/>
    <meta name="twitter:image:alt" content="Coins from the <?= sanitize(SITE_NAME) ?> collection"/>

    <?php render_head_assets(); ?>

    <?php if (!empty($heroCoins)): ?>
        <!-- Preload the centre hero coin: it is the largest element painted above
             the fold, and LCP is a ranking signal. -->
        <link rel="preload" as="image" href="<?= sanitize(get_coin_thumbnail_path($heroCoins[0]['obverse_image'])) ?>" fetchpriority="high"/>
    <?php endif; ?>
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
<body class="page-home">

    <?php render_site_header(true); ?>

    <main id="main">

        <!-- SECTION 1: Hero - the vault display case -->
        <div class="wrap hero-shell">
            <section class="hero vault vault-grain" aria-labelledby="hero-title">
                <span class="rosette" aria-hidden="true"></span>
                <div class="hero-grid">
                    <div class="hero-copy">
                        <p class="hero-eyebrow">
                            <span>Digital Numismatic Museum</span>
                            <span class="dot" aria-hidden="true"></span>
                            <span lang="bn">মুদ্রা আর্কাইভ</span>
                        </p>
                        <h1 id="hero-title" class="hero-title"><?= sanitize(SITE_NAME) ?></h1>
                        <p class="hero-lead">An evolving personal collection of coins from around the world, catalogued in detail and photographed on both faces.</p>

                        <div class="hero-actions">
                            <a href="#collection" class="btn-x btn-gold">
                                <span>Explore the collection</span>
                                <span class="material-symbols-outlined" aria-hidden="true">arrow_downward</span>
                            </a>
                            <a href="#countriesMap" class="btn-x btn-ghost-light">
                                <span class="material-symbols-outlined" aria-hidden="true">public</span>
                                <span>Open the atlas</span>
                            </a>
                        </div>

                        <!-- Pieces held and catalogue entries are different numbers whenever
                             a coin is held in multiples, so both are named. -->
                        <dl id="statistics" class="hero-stats">
                            <div class="stat" title="<?= number_format($totalCoinsCount) ?> pieces held across <?= number_format($totalEntries) ?> catalogue entries">
                                <dt>Coins<?php if ($totalEntries !== $totalCoinsCount): ?><span class="stat-note"> · <?= number_format($totalEntries) ?> entries</span><?php endif; ?></dt>
                                <dd data-count="<?= (int)$totalCoinsCount ?>"><?= number_format($totalCoinsCount) ?></dd>
                            </div>
                            <div class="stat">
                                <dt>Countries</dt>
                                <dd data-count="<?= (int)$totalCountries ?>"><?= number_format($totalCountries) ?></dd>
                            </div>
                            <div class="stat">
                                <dt>Currencies</dt>
                                <dd data-count="<?= (int)$totalCurrencies ?>"><?= number_format($totalCurrencies) ?></dd>
                            </div>
                            <div class="stat">
                                <dt>Era span</dt>
                                <dd class="is-text"><?= sanitize($yearRange) ?></dd>
                            </div>
                        </dl>
                    </div>

                    <div class="hero-stage">
                        <div class="orbit">
                            <span class="orbit-ring orbit-ring--3" aria-hidden="true"></span>
                            <span class="orbit-ring orbit-ring--1" aria-hidden="true"></span>
                            <span class="orbit-ring orbit-ring--2" aria-hidden="true"></span>
                            <?php if (!empty($heroCoins)): ?>
                                <?php foreach ($heroCoins as $hIndex => $hc): ?>
                                    <?php
                                        if (!isset($orbitSlots[$hIndex])) { break; }
                                        $hThumb = get_coin_thumbnail_path($hc['obverse_image']);
                                        $hTitle = coin_display_title($hc);
                                        $hSize  = $hIndex === 0 ? 240 : 120;
                                    ?>
                                    <a href="<?= sanitize(coin_path($hc)) ?>" class="orbit-coin orbit-coin--<?= $orbitSlots[$hIndex] ?>" title="<?= sanitize($hTitle) ?>">
                                        <img class="coin-img" src="<?= sanitize($hThumb) ?>" alt="<?= sanitize($hTitle) ?>"
                                             width="<?= $hSize ?>" height="<?= $hSize ?>" loading="eager" decoding="async"
                                             <?= $hIndex === 0 ? 'fetchpriority="high"' : '' ?>/>
                                    </a>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="orbit-coin orbit-coin--main orbit-coin--logo">
                                    <img class="coin-img" src="<?= sanitize(placeholder_image_path()) ?>" alt="" width="240" height="240"/>
                                </span>
                            <?php endif; ?>
                            <?php if ($featuredCount > 0): ?>
                                <a class="orbit-caption" href="<?= home_url(['featured' => '1'], 'collection') ?>">
                                    <span class="material-symbols-outlined icon-fill" aria-hidden="true">star</span>
                                    <span>Featured showcase</span>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <!-- SECTION 2: The atlas - an Equal Earth world map drawn from local SVG,
             no tile server. The ranked list beside it is the accessible, crawlable
             version of the same information. -->
        <section id="countriesMap" class="section wrap" aria-labelledby="atlas-title">
            <div class="section-head" data-reveal>
                <div>
                    <p class="eyebrow">The atlas</p>
                    <h2 id="atlas-title" class="section-title">Where the coins come from</h2>
                    <p class="section-sub">Every country in gold is represented in the archive, and the deeper the gold, the more coins. Select a country to see what is held, or one that is still missing to offer an exchange.</p>
                </div>
            </div>

            <div class="atlas panel" id="atlas" data-reveal>
                <div class="atlas-main">
                    <div class="atlas-toolbar">
                        <div class="chip-row" role="group" aria-label="Zoom to a region">
                            <button type="button" class="chip" data-region="world" aria-pressed="true">World</button>
                            <button type="button" class="chip" data-region="europe" aria-pressed="false">Europe</button>
                            <button type="button" class="chip" data-region="asia" aria-pressed="false">Asia</button>
                            <button type="button" class="chip" data-region="africa" aria-pressed="false">Africa</button>
                            <button type="button" class="chip" data-region="americas" aria-pressed="false">Americas</button>
                            <button type="button" class="chip" data-region="oceania" aria-pressed="false">Oceania</button>
                        </div>
                        <div class="atlas-tools">
                            <button type="button" class="icon-btn" data-zoom="in" aria-label="Zoom in"><span class="material-symbols-outlined" aria-hidden="true">add</span></button>
                            <button type="button" class="icon-btn" data-zoom="out" aria-label="Zoom out"><span class="material-symbols-outlined" aria-hidden="true">remove</span></button>
                            <button type="button" class="icon-btn" data-fullscreen aria-label="Show the map full screen"><span class="material-symbols-outlined" aria-hidden="true" data-fs-icon>fullscreen</span></button>
                        </div>
                    </div>

                    <div class="atlas-map" id="atlasMap" tabindex="0"
                         data-src="<?= sanitize(asset_url('/assets/img/atlas.svg')) ?>"
                         aria-label="World map of the collection. Plus and minus zoom, arrow keys pan, zero resets.">
                        <div class="atlas-loading" aria-hidden="true">Loading map…</div>
                        <div class="atlas-legend" aria-hidden="true">
                            <span class="label-long">Coins</span>
                            <span>1</span>
                            <span class="scale"><span></span><span></span><span></span><span></span></span>
                            <span>10+</span>
                        </div>
                        <div class="atlas-tip" aria-hidden="true"></div>
                        <div class="atlas-hint" aria-hidden="true"></div>
                        <div class="atlas-card" role="region" aria-live="polite" aria-label="Country details" hidden></div>
                    </div>
                </div>

                <aside class="atlas-aside" aria-labelledby="atlas-list-title">
                    <div class="atlas-progress">
                        <div class="big"><?= number_format($totalCountries) ?><small><?= sanitize($countryLabel) ?></small></div>
                        <div class="label">About <?= $atlasPct ?>% of the world's 195 countries</div>
                        <div class="meter" style="--w: <?= max(2, $atlasPct) ?>%"><span></span></div>
                    </div>
                    <h3 id="atlas-list-title" class="atlas-list-title">Ranked by coins held</h3>
                    <ol class="atlas-list">
                        <?php foreach ($countryCounts as $ci => $cc): ?>
                            <li>
                                <a class="atlas-row<?= $countryFilter === $cc['country'] ? ' is-current' : '' ?>"
                                   href="<?= sanitize(home_url(['country' => $cc['country']], 'collection')) ?>"
                                   data-country="<?= sanitize($cc['country']) ?>"
                                   <?= $countryFilter === $cc['country'] ? 'aria-current="true"' : '' ?>>
                                    <span class="rank"><?= $ci + 1 ?></span>
                                    <span class="name"><?= sanitize($cc['country']) ?></span>
                                    <span class="num"><?= (int)$cc['count'] ?> <span class="visually-hidden">coin<?= (int)$cc['count'] === 1 ? '' : 's' ?></span></span>
                                    <span class="bar" aria-hidden="true"><span style="--w: <?= max(4, (int)round($cc['count'] / $atlasMax * 100)) ?>%"></span></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </aside>
            </div>
        </section>

        <!-- SECTION 3: Public collection gallery -->
        <section id="collection" class="section wrap" aria-labelledby="collection-title">
            <div class="section-head" data-reveal>
                <div>
                    <p class="eyebrow">The collection</p>
                    <h2 id="collection-title" class="section-title">Browse the archive</h2>
                    <!-- "entries", not "coins": this counts catalogue records, while the
                         Coins statistic counts pieces held. -->
                    <p class="section-sub gallery-meta">
                        Showing <?= count($coins) ?> of <?= number_format($totalMatching) ?> entr<?= $totalMatching === 1 ? 'y' : 'ies' ?><?= $totalPages > 1 ? ' (page ' . $page . ' of ' . $totalPages . ')' : '' ?>.
                        Hover over or tap a coin to turn it over.
                    </p>
                </div>
                <div class="chip-row" aria-label="Collection view">
                    <a href="<?= home_url([], 'collection') ?>" class="chip<?= $featuredFilter !== 1 ? ' is-active' : '' ?>"<?= $featuredFilter !== 1 ? ' aria-current="page"' : '' ?>>All coins</a>
                    <a href="<?= home_url(['featured' => '1'], 'collection') ?>" class="chip<?= $featuredFilter === 1 ? ' is-active' : '' ?>"<?= $featuredFilter === 1 ? ' aria-current="page"' : '' ?>>
                        <span class="material-symbols-outlined icon-fill" aria-hidden="true">star</span>
                        <span>Featured</span>
                        <span class="count"><?= (int)$featuredCount ?></span>
                    </a>
                </div>
            </div>

            <!-- Search & filter toolbar -->
            <form method="GET" action="/" id="galleryForm" class="toolbar panel" role="search">
                <?php
                    // Carry the filters this bar does not expose. Without these the
                    // search silently dropped an active currency or year filter
                    // while the tags below still claimed it was applied.
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
                <div class="field field-search">
                    <span class="material-symbols-outlined" aria-hidden="true">search</span>
                    <input type="search" name="search" class="input-x" aria-label="Search the collection"
                           placeholder="Search country, currency, era…" value="<?= sanitize($search) ?>"/>
                </div>

                <select name="country" aria-label="Filter by country" class="select-x desktop-only" data-autosubmit>
                    <option value="">All countries</option>
                    <?php foreach ($countries as $c): ?>
                        <option value="<?= sanitize($c) ?>" <?= $countryFilter === $c ? 'selected' : '' ?>><?= sanitize($c) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="grade" aria-label="Filter by grade" class="select-x desktop-only" data-autosubmit>
                    <option value="">All grades</option>
                    <?php foreach ($grades as $g): ?>
                        <option value="<?= sanitize($g) ?>" <?= $gradeFilter === $g ? 'selected' : '' ?>><?= sanitize($g) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="sort" aria-label="Sort the collection" class="select-x" data-autosubmit>
                    <option value="date_desc" <?= $sortBy === 'date_desc' ? 'selected' : '' ?>>Latest added</option>
                    <option value="date_asc" <?= $sortBy === 'date_asc' ? 'selected' : '' ?>>First added</option>
                    <option value="country_asc" <?= $sortBy === 'country_asc' ? 'selected' : '' ?>>Country (A–Z)</option>
                    <option value="year_asc" <?= $sortBy === 'year_asc' ? 'selected' : '' ?>>Oldest year</option>
                    <option value="year_desc" <?= $sortBy === 'year_desc' ? 'selected' : '' ?>>Newest year</option>
                </select>

                <button type="button" class="btn-x btn-ghost toolbar-mobile" data-filters-toggle aria-controls="filterPanel" aria-expanded="false">
                    <span class="material-symbols-outlined" aria-hidden="true">tune</span>
                    <span>Filters<?= $activeFilterCount > 0 ? ' (' . $activeFilterCount . ')' : '' ?></span>
                </button>
            </form>

            <!-- Full filter panel (phones). Every filter, including currency and year range. -->
            <div id="filterPanel" class="filter-panel panel" hidden>
                <form method="GET" action="/">
                    <div class="grid">
                        <div class="full">
                            <label for="f-search">Search</label>
                            <input id="f-search" type="search" name="search" class="input-x" placeholder="Era, country, notes…" value="<?= sanitize($search) ?>"/>
                        </div>
                        <div class="full">
                            <label for="f-featured">Showcase</label>
                            <select id="f-featured" name="featured" class="select-x">
                                <option value="">All coins</option>
                                <option value="1" <?= $featuredFilter === 1 ? 'selected' : '' ?>>Featured coins only</option>
                            </select>
                        </div>
                        <div class="full">
                            <label for="f-country">Country</label>
                            <select id="f-country" name="country" class="select-x">
                                <option value="">All countries</option>
                                <?php foreach ($countries as $c): ?>
                                    <option value="<?= sanitize($c) ?>" <?= $countryFilter === $c ? 'selected' : '' ?>><?= sanitize($c) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="full">
                            <label for="f-currency">Currency</label>
                            <select id="f-currency" name="currency" class="select-x">
                                <option value="">All currencies</option>
                                <?php foreach ($currencies as $cur): ?>
                                    <option value="<?= sanitize($cur) ?>" <?= $currencyFilter === $cur ? 'selected' : '' ?>><?= sanitize($cur) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="full">
                            <label for="f-grade">Condition / grade</label>
                            <select id="f-grade" name="grade" class="select-x">
                                <option value="">All grades</option>
                                <?php foreach ($grades as $g): ?>
                                    <option value="<?= sanitize($g) ?>" <?= $gradeFilter === $g ? 'selected' : '' ?>><?= sanitize($g) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="f-ymin">From year</label>
                            <input id="f-ymin" type="number" name="year_min" class="input-x" placeholder="e.g. 1800" value="<?= $yearMin ?? '' ?>"/>
                        </div>
                        <div>
                            <label for="f-ymax">To year</label>
                            <input id="f-ymax" type="number" name="year_max" class="input-x" placeholder="e.g. 1950" value="<?= $yearMax ?? '' ?>"/>
                        </div>
                        <div class="full">
                            <label for="f-sort">Sort order</label>
                            <select id="f-sort" name="sort" class="select-x">
                                <option value="date_desc" <?= $sortBy === 'date_desc' ? 'selected' : '' ?>>Latest added</option>
                                <option value="date_asc" <?= $sortBy === 'date_asc' ? 'selected' : '' ?>>First added</option>
                                <option value="country_asc" <?= $sortBy === 'country_asc' ? 'selected' : '' ?>>Country (A–Z)</option>
                                <option value="year_asc" <?= $sortBy === 'year_asc' ? 'selected' : '' ?>>Oldest year</option>
                                <option value="year_desc" <?= $sortBy === 'year_desc' ? 'selected' : '' ?>>Newest year</option>
                            </select>
                        </div>
                    </div>
                    <div class="actions">
                        <a href="<?= home_url([], 'collection') ?>" class="btn-x btn-ghost">Reset</a>
                        <button type="submit" class="btn-x btn-solid">Apply filters</button>
                    </div>
                </form>
            </div>

            <!-- Active filter tags -->
            <?php if ($activeFilterCount > 0 || $search !== ''): ?>
                <div class="active-filters">
                    <span class="label">Filtered by</span>
                    <?php if ($search !== ''): ?>
                        <span class="tag">“<?= sanitize($search) ?>”</span>
                    <?php endif; ?>
                    <?php if ($featuredFilter === 1): ?>
                        <span class="tag"><span class="material-symbols-outlined icon-fill" style="font-size:15px" aria-hidden="true">star</span>Featured</span>
                    <?php endif; ?>
                    <?php if ($countryFilter !== ''): ?>
                        <span class="tag">Country: <?= sanitize($countryFilter) ?></span>
                    <?php endif; ?>
                    <?php if ($currencyFilter !== ''): ?>
                        <span class="tag">Currency: <?= sanitize($currencyFilter) ?></span>
                    <?php endif; ?>
                    <?php if ($gradeFilter !== ''): ?>
                        <span class="tag">Grade: <?= sanitize($gradeFilter) ?></span>
                    <?php endif; ?>
                    <?php if ($yearMin !== null || $yearMax !== null): ?>
                        <span class="tag">Year: <?= $yearMin ?? 'any' ?> – <?= $yearMax ?? 'any' ?></span>
                    <?php endif; ?>
                    <a href="<?= home_url([], 'collection') ?>" class="clear-link">Clear all</a>
                </div>
            <?php endif; ?>

            <!-- Coin grid: each coin turns over to its reverse on hover, focus or tap -->
            <?php if (count($coins) > 0): ?>
                <div class="coin-grid">
                    <?php foreach ($coins as $coin): ?>
                        <?php
                            $obvThumb = get_coin_thumbnail_path($coin['obverse_image'] ?? '');
                            $obvIsPlaceholder = is_placeholder_image($obvThumb);
                            $hasRev = !empty($coin['reverse_image']) && file_exists(__DIR__ . '/assets/uploads/' . safe_upload_name($coin['reverse_image']));
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
                        <article class="coin-card">
                            <div class="coin-3d-scene" data-flip>
                                <div class="coin-3d-flipper<?= $hasRev ? ' has-reverse' : '' ?>">
                                    <div class="coin-face coin-face-obv<?= $obvIsPlaceholder ? ' is-placeholder' : '' ?>">
                                        <img src="<?= sanitize($obvThumb) ?>" alt="<?= $coinAltText ?>" loading="lazy" decoding="async" width="220" height="220"/>
                                    </div>
                                    <?php if ($hasRev): ?>
                                        <div class="coin-face coin-face-rev">
                                            <img src="<?= sanitize($revThumb) ?>" alt="<?= $coinAltText ?>, reverse" loading="lazy" decoding="async" width="220" height="220"/>
                                            <span class="badge-rev-indicator" aria-hidden="true">REV</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php if ((int)$coin['is_featured'] === 1): ?>
                                    <span class="badge-featured-mini" title="Featured coin">
                                        <span class="material-symbols-outlined icon-fill" aria-hidden="true">star</span>
                                        <span class="visually-hidden">Featured</span>
                                    </span>
                                <?php endif; ?>
                                <?php if ($hasRev): ?>
                                    <span class="flip-hint-badge" aria-hidden="true">
                                        <span class="material-symbols-outlined">sync</span>Flip
                                    </span>
                                <?php endif; ?>
                            </div>
                            <a href="<?= sanitize(coin_path($coin)) ?>" class="coin-card-link" title="<?= sanitize(coin_display_title($coin)) ?>">
                                <!-- h3: these sit under the "Browse the archive" section heading. -->
                                <h3 class="coin-card-title"><?= sanitize($coin['denomination']) ?></h3>
                                <div class="coin-card-sub"><?= sanitize($coin['country']) ?></div>
                                <div class="coin-card-meta">
                                    <?php if (trim((string)$coin['year']) !== ''): ?>
                                        <span class="coin-card-year"><?= sanitize($coin['year']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($coin['material'])): ?>
                                        <span class="coin-card-material <?= $matClass ?>" title="<?= sanitize($coin['material']) ?>"><?= sanitize($coin['material']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </a>
                        </article>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination (<?= COINS_PER_PAGE ?> entries per page) -->
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
                        <ul class="pagination mb-0 flex-wrap">
                            <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                                <a class="page-link" rel="prev" href="<?= $page > 1 ? sanitize(pagination_url($currentFilters, $page - 1)) : '#' ?>">Previous</a>
                            </li>

                            <?php if ($windowFrom > 1): ?>
                                <li class="page-item"><a class="page-link" href="<?= sanitize(pagination_url($currentFilters, 1)) ?>">1</a></li>
                                <?php if ($windowFrom > 2): ?>
                                    <li class="page-item disabled"><span class="page-link">…</span></li>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php for ($p = $windowFrom; $p <= $windowTo; $p++): ?>
                                <li class="page-item <?= ($p === $page) ? 'active' : '' ?>">
                                    <a class="page-link" <?= $p === $page ? 'aria-current="page"' : '' ?>
                                       href="<?= sanitize(pagination_url($currentFilters, $p)) ?>"><?= $p ?></a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($windowTo < $totalPages): ?>
                                <?php if ($windowTo < $totalPages - 1): ?>
                                    <li class="page-item disabled"><span class="page-link">…</span></li>
                                <?php endif; ?>
                                <li class="page-item"><a class="page-link" href="<?= sanitize(pagination_url($currentFilters, $totalPages)) ?>"><?= $totalPages ?></a></li>
                            <?php endif; ?>

                            <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                                <a class="page-link" rel="next" href="<?= $page < $totalPages ? sanitize(pagination_url($currentFilters, $page + 1)) : '#' ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>

            <?php else: ?>
                <div class="empty-state panel">
                    <span class="material-symbols-outlined" aria-hidden="true">search_off</span>
                    <h3 class="h4">No coins found</h3>
                    <p class="text-muted">No entries matched your search or filters.</p>
                    <a href="<?= home_url([], 'collection') ?>" class="btn-x btn-solid">Reset all filters</a>
                </div>
            <?php endif; ?>
        </section>

        <!-- SECTION 4: About the collection -->
        <section id="about" class="section wrap" aria-labelledby="about-title">
            <div class="about panel" data-reveal>
                <span class="rosette" aria-hidden="true"></span>
                <figure class="about-visual m-0">
                    <img src="/assets/logo.png" class="about-coin" width="320" height="320" loading="lazy" decoding="async"
                         alt="The <?= sanitize(SITE_NAME) ?> emblem: a silver coin with a portrait, inscribed মুদ্রা আর্কাইভ (Mudra Archive) and এক টাকা (one taka)"/>
                    <figcaption>The archive's emblem, inscribed <span lang="bn">মুদ্রা আর্কাইভ</span> and <span lang="bn">এক টাকা</span>, “one taka”.</figcaption>
                </figure>
                <div class="about-copy">
                    <p class="eyebrow">The curator</p>
                    <h2 id="about-title" class="section-title mb-3">About the collection</h2>
                    <div id="bioText" class="bio-collapsed">
                        <p class="lead-x"><?= sanitize(SITE_NAME) ?> is the personal coin collection of <?= sanitize(SITE_AUTHOR) ?>, catalogued and published as an open reference. It currently holds <strong><?= number_format($totalEntries) ?> catalogued coins</strong><?= $totalEntries !== $totalCoinsCount ? ' (' . number_format($totalCoinsCount) . ' pieces in total, since some are held in multiples)' : '' ?> from <strong><?= number_format($totalCountries) ?> <?= sanitize($countryLabel) ?></strong>, covering <?= sanitize($yearRange) ?>, across <?= number_format($totalCurrencies) ?> distinct currencies.</p>
                        <p>Most of these coins were gathered over time through Wikimedia events and through exchanges with collectors in other countries. Every entry records its denomination, year of issue, mint mark, metal, weight, diameter and condition grade, and is photographed on both the obverse and reverse faces so the details can be examined directly.</p>
                        <p class="mb-0">If you would like to exchange your country's coin for Bangladeshi Taka, write to <a href="mailto:<?= sanitize(SITE_CONTACT) ?>" class="fw-bold text-decoration-none"><?= sanitize(SITE_CONTACT) ?></a>. This archive exists to preserve global numismatic history, celebrate international exchange and record unusual coinage from around the world. Nothing here is for sale.</p>
                    </div>
                    <button type="button" class="text-toggle mt-2" data-bio-toggle aria-controls="bioText" aria-expanded="false">
                        <span>Read more</span>
                        <span class="material-symbols-outlined" aria-hidden="true">expand_more</span>
                    </button>

                    <ul class="about-facts">
                        <li>
                            <span class="material-symbols-outlined" aria-hidden="true">photo_camera</span>
                            <strong>Both faces</strong>
                            <span class="small">Every coin is photographed obverse and reverse.</span>
                        </li>
                        <li>
                            <span class="material-symbols-outlined" aria-hidden="true">swap_horiz</span>
                            <strong>Exchanges welcome</strong>
                            <span class="small">Trade your country's coin for Bangladeshi Taka.</span>
                        </li>
                        <li>
                            <span class="material-symbols-outlined" aria-hidden="true">money_off</span>
                            <strong>Never for sale</strong>
                            <span class="small">A reference collection, not a shop.</span>
                        </li>
                    </ul>

                    <div class="about-actions">
                        <a href="mailto:<?= sanitize(SITE_CONTACT) ?>?subject=<?= rawurlencode('Coin exchange') ?>" class="btn-x btn-solid">
                            <span class="material-symbols-outlined" aria-hidden="true">mail</span>
                            <span>Propose an exchange</span>
                        </a>
                        <a href="#collection" class="btn-x btn-ghost">
                            <span class="material-symbols-outlined" aria-hidden="true">grid_view</span>
                            <span>Browse the coins</span>
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <!-- SECTION 5: Questions this archive answers.
             The matching FAQPage structured data in <head> is only valid because
             the same text is visible here. <details> keeps every answer in the
             DOM, readable without JavaScript. -->
        <?php if (!$isFiltered && $page === 1): ?>
        <section id="faq" class="section wrap" aria-labelledby="faq-heading">
            <div class="section-head" data-reveal>
                <div>
                    <p class="eyebrow">Good to know</p>
                    <h2 id="faq-heading" class="section-title">Frequently asked questions</h2>
                </div>
            </div>
            <div class="faq panel" data-reveal>
                <?php foreach ($faqs as $fi => $faq): ?>
                    <details<?= $fi === 0 ? ' open' : '' ?>>
                        <summary>
                            <h3><?= sanitize($faq[0]) ?></h3>
                            <span class="plus" aria-hidden="true"></span>
                        </summary>
                        <p class="answer"><?= sanitize($faq[1]) ?></p>
                    </details>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

    </main>

    <?php render_site_footer(true); ?>

    <!-- Map data: read by atlas.js. JSON in a non-executing script block, with
         every HTML-significant character escaped. -->
    <script type="application/json" id="atlas-data"><?= json_encode(
        ['items' => $atlasItems, 'contact' => SITE_CONTACT],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?></script>
    <script src="<?= sanitize(asset_url('/assets/js/site.js')) ?>" defer></script>
    <script src="<?= sanitize(asset_url('/assets/js/atlas.js')) ?>" defer></script>
</body>
</html>
