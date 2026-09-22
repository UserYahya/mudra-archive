<?php
// coin.php - Mudra Archive single coin detail page with SEO & JSON-LD structured data
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

send_security_headers();

$baseUrl = get_base_url();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$coin = null;
if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM coins WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $coin = $stmt->fetch();
}

// A missing coin is a 404, not a redirect to the homepage. Redirecting instead
// of answering 404 creates "soft 404s", which Google reports as errors and
// which keep dead URLs in the index.
if (!$coin) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit();
}

// Canonical URL enforcement: /coin.php?id=12 and any stale slug both 301 to the
// one canonical path, so link equity is never split across URL variants.
$canonicalPath = coin_path($coin);
$requestPath   = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
if (PRETTY_URLS && rtrim($requestPath, '/') !== rtrim($canonicalPath, '/')) {
    header('Location: ' . $canonicalPath, true, 301);
    exit();
}

$canonicalUrl = coin_url($coin, $baseUrl);
$isCurator    = is_admin_logged_in();

// Resolve obverse and reverse images
$obvImg = get_coin_image_path($coin['obverse_image']);
$revImg = get_coin_image_path($coin['reverse_image']);
$hasObv = !is_placeholder_image($obvImg);
$hasRev = !is_placeholder_image($revImg);

$coinTitle   = coin_display_title($coin);
$seoTitle    = $coinTitle . ' | ' . SITE_NAME;
$seoDesc     = coin_meta_description($coin);
$summary     = coin_summary($coin);
$fullObvUrl  = absolute_url($obvImg, $baseUrl);
$fullRevUrl  = absolute_url($revImg, $baseUrl);

// Landscape card for link previews, falling back to the obverse photograph if
// GD cannot build one. Dimensions are read from whichever image is used, so the
// og:image:width/height tags are always truthful.
$socialPath = coin_social_image($coin) ?: $obvImg;
$socialUrl  = absolute_url($socialPath, $baseUrl);
$socialDims = image_dimensions($socialPath);
$relatedCoins = get_related_coins($pdo, $coin, 6);

$imageAltObv = 'Obverse of ' . $coinTitle;
$imageAltRev = 'Reverse of ' . $coinTitle;

// Specification rows, built once and reused by the visible table and the
// structured data so the two can never drift apart.
$specs = [];
$specs[] = ['Country',        trim((string)$coin['country'])];
$specs[] = ['Currency',       trim((string)$coin['currency_name'])];
$specs[] = ['Denomination',   trim((string)$coin['denomination'])];
$specs[] = ['Year / Era',     trim((string)$coin['year'])];
if (!empty($coin['ruler_or_series'])) { $specs[] = ['Ruler / Series', trim($coin['ruler_or_series'])]; }
if (!empty($coin['mint_mark']))       { $specs[] = ['Mint Mark',      trim($coin['mint_mark'])]; }
if (!empty($coin['material']))        { $specs[] = ['Material',       trim($coin['material'])]; }
if (!empty($coin['weight_grams']))    { $specs[] = ['Weight',         rtrim(rtrim(number_format((float)$coin['weight_grams'], 2), '0'), '.') . ' g']; }
if (!empty($coin['diameter_mm']))     { $specs[] = ['Diameter',       rtrim(rtrim(number_format((float)$coin['diameter_mm'], 2), '0'), '.') . ' mm']; }
if (!empty($coin['condition_grade'])) { $specs[] = ['Condition Grade', trim($coin['condition_grade'])]; }
$specs[] = ['Pieces Held', (string)(int)$coin['quantity']];

// --- Structured data ------------------------------------------------------
// CreativeWork rather than Product: nothing here is for sale, and a Product
// without an offer is an invalid rich result. CollectionCoin-style detail is
// carried in additionalProperty, which answer engines read directly.
$images = [];
if ($hasObv) { $images[] = $fullObvUrl; }
if ($hasRev) { $images[] = $fullRevUrl; }
if (!$images) { $images[] = absolute_url(placeholder_image_path(), $baseUrl); }

$additionalProperties = [];
foreach ($specs as [$label, $value]) {
    if ($value === '') { continue; }
    $additionalProperties[] = [
        '@type' => 'PropertyValue',
        'name'  => $label,
        'value' => $value,
    ];
}

$creativeWork = [
    '@context'       => 'https://schema.org',
    '@type'          => 'CreativeWork',
    'additionalType' => 'https://www.wikidata.org/wiki/Q41207', // coin
    '@id'            => $canonicalUrl . '#coin',
    'name'           => $coinTitle,
    'headline'       => $coinTitle,
    'url'            => $canonicalUrl,
    'description'    => $summary,
    'image'          => $images,
    'inLanguage'     => 'en',
    'genre'          => 'Numismatics',
    'keywords'       => implode(', ', array_filter([
        $coin['denomination'], $coin['country'], $coin['currency_name'],
        $coin['material'], $coin['ruler_or_series'], 'coin', 'numismatics',
    ])),
    'material'        => $coin['material'] ?: null,
    'dateCreated'     => trim((string)$coin['year']) !== '' ? trim((string)$coin['year']) : null,
    'countryOfOrigin' => $coin['country'] ? ['@type' => 'Country', 'name' => $coin['country']] : null,
    'creator'         => $coin['country'] ? ['@type' => 'Organization', 'name' => $coin['country'] . ' Mint'] : null,
    'isPartOf'        => [
        '@type' => 'Collection',
        '@id'   => $baseUrl . '/#collection',
        'name'  => SITE_NAME,
        'url'   => $baseUrl . '/',
    ],
    'maintainer' => [
        '@type' => 'Person',
        'name'  => SITE_AUTHOR,
        'url'   => $baseUrl . '/#about',
    ],
    'additionalProperty' => $additionalProperties,
];
if (!empty($coin['notes'])) {
    $creativeWork['abstract'] = trim(preg_replace('/\s+/', ' ', $coin['notes']));
}
if (!empty($coin['updated_at'])) {
    $creativeWork['dateModified'] = substr($coin['updated_at'], 0, 10);
}
$creativeWork = array_filter($creativeWork, function ($v) { return $v !== null && $v !== '' && $v !== []; });

$breadcrumbs = [
    '@context'        => 'https://schema.org',
    '@type'           => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => SITE_NAME, 'item' => $baseUrl . '/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => $coin['country'], 'item' => $baseUrl . home_url(['country' => $coin['country']], 'collection')],
        ['@type' => 'ListItem', 'position' => 3, 'name' => $coinTitle, 'item' => $canonicalUrl],
    ],
];

$webPage = [
    '@context'         => 'https://schema.org',
    '@type'            => 'ItemPage',
    '@id'              => $canonicalUrl,
    'url'              => $canonicalUrl,
    'name'             => $seoTitle,
    'description'      => $seoDesc,
    'isPartOf'         => ['@type' => 'WebSite', '@id' => $baseUrl . '/#website'],
    'primaryImageOfPage' => ['@type' => 'ImageObject', 'contentUrl' => $images[0], 'caption' => $imageAltObv],
    'mainEntity'       => ['@id' => $canonicalUrl . '#coin'],
    'breadcrumb'       => ['@id' => $canonicalUrl . '#breadcrumb'],
];
$breadcrumbs['@id'] = $canonicalUrl . '#breadcrumb';

$cssVersion = file_exists(__DIR__ . '/assets/css/style.css') ? filemtime(__DIR__ . '/assets/css/style.css') : time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1"/>
    <meta name="theme-color" content="#1e293b"/>

    <!-- Primary SEO Meta Tags -->
    <title><?= sanitize($seoTitle) ?></title>
    <meta name="description" content="<?= sanitize($seoDesc) ?>"/>
    <meta name="author" content="<?= sanitize(SITE_AUTHOR) ?>"/>
    <link rel="canonical" href="<?= sanitize($canonicalUrl) ?>"/>

    <!-- Open Graph / Facebook -->
    <meta property="og:site_name" content="<?= sanitize(SITE_NAME) ?>"/>
    <meta property="og:type" content="article"/>
    <meta property="og:locale" content="<?= sanitize(SITE_LOCALE) ?>"/>
    <meta property="og:url" content="<?= sanitize($canonicalUrl) ?>"/>
    <meta property="og:title" content="<?= sanitize($coinTitle) ?>"/>
    <meta property="og:description" content="<?= sanitize($seoDesc) ?>"/>
    <meta property="og:image" content="<?= sanitize($socialUrl) ?>"/>
    <meta property="og:image:secure_url" content="<?= sanitize($socialUrl) ?>"/>
    <?php if ($socialDims): ?>
        <!-- Explicit dimensions let a scraper render the preview on its first
             fetch rather than queueing the image and showing no picture. -->
        <meta property="og:image:width" content="<?= (int)$socialDims['width'] ?>"/>
        <meta property="og:image:height" content="<?= (int)$socialDims['height'] ?>"/>
        <meta property="og:image:type" content="<?= sanitize($socialDims['mime']) ?>"/>
    <?php endif; ?>
    <meta property="og:image:alt" content="<?= sanitize($coinTitle) ?>"/>

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image"/>
    <meta name="twitter:url" content="<?= sanitize($canonicalUrl) ?>"/>
    <meta name="twitter:title" content="<?= sanitize($coinTitle) ?>"/>
    <meta name="twitter:description" content="<?= sanitize($seoDesc) ?>"/>
    <meta name="twitter:image" content="<?= sanitize($socialUrl) ?>"/>
    <meta name="twitter:image:alt" content="<?= sanitize($coinTitle) ?>"/>

    <!-- Early Theme Script (Prevents Flash of Light Mode) -->
    <script>
        (function() {
            var savedTheme = localStorage.getItem('mudra_theme');
            if (savedTheme) {
                document.documentElement.setAttribute('data-theme', savedTheme);
            }
        })();

        function toggleTheme() {
            var currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
            var newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', newTheme);
            localStorage.setItem('mudra_theme', newTheme);
            updateThemeIcon(newTheme);
        }

        function updateThemeIcon(theme) {
            var icon = document.getElementById('themeIcon');
            if (icon) {
                icon.innerText = theme === 'dark' ? 'light_mode' : 'dark_mode';
            }
        }
    </script>

    <!-- Preload the largest image on the page so it paints sooner (Core Web Vitals) -->
    <link rel="preload" as="image" href="<?= sanitize($obvImg) ?>" fetchpriority="high"/>

    <!-- Resource Preconnect & Font Links -->
    <link rel="preconnect" href="https://fonts.googleapis.com"/>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin=""/>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin=""/>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400..900;1,400..900&family=Source+Sans+3:ital,wght@0,300..900;1,300..900&display=swap" rel="stylesheet"/>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200"/>

    <!-- Favicons -->
    <link rel="icon" type="image/x-icon" href="/assets/favicon.ico"/>
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32x32.png"/>
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/apple-touch-icon.png"/>
    <link rel="manifest" href="/site.webmanifest"/>

    <!-- Bootstrap 5 CSS via CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
    <!-- Master Custom Stylesheet -->
    <link href="/assets/css/style.css?v=<?= $cssVersion ?>" rel="stylesheet"/>

    <!-- Structured Data (JSON-LD) -->
    <?= json_ld($webPage) ?>
    <?= json_ld($creativeWork) ?>
    <?= json_ld($breadcrumbs) ?>
</head>
<body>

    <!-- Header Bar with Theme Toggle -->
    <header class="app-header d-flex align-items-center px-3 px-md-4 sticky-top border-bottom shadow-sm">
        <div class="container-fluid max-w-container-max d-flex justify-content-between align-items-center p-0">
            <a href="<?= home_url([], 'collection') ?>" class="btn btn-sm btn-outline-archival d-inline-flex align-items-center gap-1">
                <span class="material-symbols-outlined fs-5" aria-hidden="true">arrow_back</span>
                <span>Back to Collection</span>
            </a>

            <a href="/" class="brand-title text-decoration-none"><?= sanitize(SITE_NAME) ?></a>

            <div class="d-flex align-items-center gap-2">
                <button id="themeToggle" class="btn btn-outline-archival p-2 d-flex align-items-center justify-content-center" onclick="toggleTheme()" aria-label="Toggle dark mode">
                    <span id="themeIcon" class="material-symbols-outlined" aria-hidden="true">dark_mode</span>
                </button>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="flex-grow-1 py-4 py-md-5 px-3 px-md-4 container-lg">

        <!-- Breadcrumb Navigation -->
        <nav aria-label="Breadcrumb" class="mb-3">
            <ol class="breadcrumb small">
                <li class="breadcrumb-item"><a href="<?= home_url([], 'collection') ?>" class="text-decoration-none text-secondary">Gallery</a></li>
                <li class="breadcrumb-item"><a href="<?= sanitize(home_url(['country' => $coin['country']], 'collection')) ?>" class="text-decoration-none text-secondary"><?= sanitize($coin['country']) ?></a></li>
                <li class="breadcrumb-item active" aria-current="page"><?= sanitize($coinTitle) ?></li>
            </ol>
        </nav>

        <div class="row g-4">

            <!-- Left Column: Obverse & Reverse High-Res Loupe Photos -->
            <div class="col-12 col-lg-5">
                <div class="d-flex flex-column gap-3">
                    <figure class="card border-0 bg-transparent mb-0">
                        <div class="loupe-container">
                            <img src="<?= sanitize($obvImg) ?>" alt="<?= sanitize($imageAltObv) ?>" class="loupe-image" decoding="async" fetchpriority="high" width="400" height="400"/>
                        </div>
                        <figcaption class="text-center mt-2 text-muted small font-heading">Obverse (Front)</figcaption>
                    </figure>

                    <?php if ($hasRev): ?>
                        <figure class="card border-0 bg-transparent mt-2 mb-0">
                            <div class="loupe-container">
                                <img src="<?= sanitize($revImg) ?>" alt="<?= sanitize($imageAltRev) ?>" class="loupe-image" loading="lazy" decoding="async" width="400" height="400"/>
                            </div>
                            <figcaption class="text-center mt-2 text-muted small font-heading">Reverse (Back)</figcaption>
                        </figure>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Column: Information Hierarchy -->
            <div class="col-12 col-lg-7 ps-lg-4">

                <!-- Title & Meta Badges -->
                <div class="border-bottom pb-3 mb-4">
                    <div class="d-flex flex-wrap gap-2 mb-2">
                        <?php if ((int)$coin['is_featured'] === 1): ?>
                            <span class="badge badge-featured px-3 py-2 rounded-pill d-inline-flex align-items-center gap-1">
                                <span class="material-symbols-outlined" style="font-size: 14px;" aria-hidden="true">star</span> Featured Collection
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($coin['material'])): ?>
                            <?php
                                $mat = strtolower(trim($coin['material']));
                                $matClass = 'badge-material-default';
                                if ($mat === 'silver' || $mat === 'platinum') {
                                    $matClass = 'badge-material-silver';
                                } elseif ($mat === 'gold') {
                                    $matClass = 'badge-material-gold';
                                } elseif (in_array($mat, ['copper', 'bronze', 'brass'], true)) {
                                    $matClass = 'badge-material-copper';
                                }
                            ?>
                            <span class="badge <?= $matClass ?> px-3 py-2 rounded-pill d-inline-flex align-items-center gap-1">
                                <span class="material-symbols-outlined me-1" style="font-size: 14px;" aria-hidden="true">diamond</span>
                                <?= sanitize($coin['material']) ?>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($coin['condition_grade'])): ?>
                            <span class="badge badge-grade px-3 py-2 rounded-pill d-inline-flex align-items-center gap-1">
                                <span class="material-symbols-outlined me-1" style="font-size: 14px;" aria-hidden="true">verified</span>
                                Grade: <?= sanitize($coin['condition_grade']) ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <!-- Single H1 per page -->
                    <h1 class="display-6 font-heading mb-1"><?= sanitize($coin['denomination']) ?></h1>
                    <p class="fs-5 text-primary mb-0 font-heading">
                        <?= sanitize($coin['country']) ?><?= trim((string)$coin['year']) !== '' ? ', ' . sanitize($coin['year']) : '' ?>
                    </p>
                </div>

                <!-- Plain-language summary. Search snippets and AI answer engines
                     quote self-contained sentences like this one. -->
                <p class="lead fs-6 text-secondary leading-relaxed mb-4"><?= sanitize($summary) ?></p>

                <!-- Specifications: a real table, so the facts are machine-readable -->
                <section aria-labelledby="specs-heading" class="mb-4">
                    <h2 id="specs-heading" class="font-heading fs-4 mb-3 d-flex align-items-center gap-2">
                        <span class="material-symbols-outlined text-primary" aria-hidden="true">straighten</span>
                        <span>Coin Specifications</span>
                    </h2>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0 coin-spec-table">
                            <caption class="visually-hidden">Technical specifications for <?= sanitize($coinTitle) ?></caption>
                            <tbody>
                                <?php foreach ($specs as [$label, $value]): ?>
                                    <?php if ($value === '') { continue; } ?>
                                    <tr>
                                        <th scope="row" class="form-label-archival fw-normal" style="width: 40%;"><?= sanitize($label) ?></th>
                                        <td class="fw-bold"><?= sanitize($value) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- Notes / Description Section -->
                <?php if (!empty($coin['notes'])): ?>
                    <section class="card border-0 rounded-3 p-4 mb-4" aria-labelledby="notes-heading">
                        <h2 id="notes-heading" class="font-heading fs-4 mb-3 d-flex align-items-center gap-2">
                            <span class="material-symbols-outlined text-primary" aria-hidden="true">history_edu</span>
                            <span>Historical Notes</span>
                        </h2>
                        <p class="card-text text-secondary leading-relaxed mb-0" style="white-space: pre-line;"><?= sanitize($coin['notes']) ?></p>
                    </section>
                <?php endif; ?>

                <!-- Acquisition record: private. Visible only to the signed-in curator,
                     never to visitors and never to a search engine crawler. -->
                <?php if ($isCurator): ?>
                    <div class="private-details d-flex flex-wrap justify-content-between align-items-center gap-3 text-muted">
                        <div class="d-flex align-items-center gap-1">
                            <span class="material-symbols-outlined fs-6" aria-hidden="true">visibility_off</span>
                            <span class="fw-bold">Curator only</span>
                        </div>
                        <div class="d-flex align-items-center gap-1">
                            <span class="material-symbols-outlined fs-6" aria-hidden="true">calendar_month</span>
                            <span>Acquired: <?= sanitize($coin['acquisition_date'] ?: 'Not recorded') ?></span>
                        </div>
                        <div class="d-flex align-items-center gap-1">
                            <span class="material-symbols-outlined fs-6" aria-hidden="true">storefront</span>
                            <span>Source: <?= sanitize($coin['source_or_seller'] ?: 'Not recorded') ?></span>
                        </div>
                        <div class="d-flex align-items-center gap-1">
                            <span class="material-symbols-outlined fs-6" aria-hidden="true">lock</span>
                            <span>Cost: <?= sanitize(format_currency($coin['acquisition_price'])) ?></span>
                        </div>
                        <a href="/admin/edit-coin.php?id=<?= (int)$coin['id'] ?>" class="btn btn-sm btn-outline-archival d-inline-flex align-items-center gap-1">
                            <span class="material-symbols-outlined fs-6" aria-hidden="true">edit</span>
                            <span>Edit entry</span>
                        </a>
                    </div>
                <?php endif; ?>

            </div>
        </div>

        <!-- Related entries: internal links let crawlers reach every coin and
             tie related pieces together as a topical cluster. -->
        <?php if ($relatedCoins): ?>
            <section class="mt-5 pt-4 border-top" aria-labelledby="related-heading">
                <h2 id="related-heading" class="font-heading fs-4 mb-3">More coins in this archive</h2>
                <div class="row row-cols-2 row-cols-md-3 row-cols-lg-6 g-3">
                    <?php foreach ($relatedCoins as $rc): ?>
                        <?php $rcThumb = get_coin_thumbnail_path($rc['obverse_image']); ?>
                        <div class="col">
                            <a href="<?= sanitize(coin_path($rc)) ?>" class="text-decoration-none d-block text-center related-coin-link">
                                <img src="<?= sanitize($rcThumb) ?>"
                                     alt="<?= sanitize(coin_display_title($rc)) ?>"
                                     class="img-fluid rounded-circle border bg-white mb-2"
                                     loading="lazy" decoding="async" width="120" height="120"
                                     style="aspect-ratio:1/1;object-fit:cover;"/>
                                <span class="d-block small fw-bold text-body"><?= sanitize($rc['denomination']) ?></span>
                                <span class="d-block small text-muted"><?= sanitize($rc['country']) ?><?= trim((string)$rc['year']) !== '' ? ', ' . sanitize($rc['year']) : '' ?></span>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

    </main>

    <!-- Footer -->
    <footer class="mt-auto py-4 border-top text-center text-muted small">
        <div class="container">
            <p class="mb-1">&copy; <?= date('Y') ?> <?= sanitize(SITE_NAME) ?> &bull; A personal digital archive of <?= sanitize(SITE_AUTHOR) ?>'s numismatic collection.</p>
            <p class="mb-0"><a href="/" class="text-muted text-decoration-underline">Browse the full collection</a></p>
        </div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            updateThemeIcon(document.documentElement.getAttribute('data-theme') || 'light');
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" defer></script>
</body>
</html>
