<?php
// coin.php - Mudra Archive single coin detail page with SEO & JSON-LD structured data
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';

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

// Provenance is public and is unique information no other site holds, so it is
// exposed to search and answer engines. The acquisition price is deliberately
// never added here - structured data is read by crawlers even when a human
// would not see it on the page.
if (!empty($coin['acquisition_date'])) {
    $additionalProperties[] = [
        '@type' => 'PropertyValue',
        'name'  => 'Acquired',
        'value' => trim($coin['acquisition_date']),
    ];
}
if (!empty($coin['source_or_seller'])) {
    $additionalProperties[] = [
        '@type' => 'PropertyValue',
        'name'  => 'Source',
        'value' => trim($coin['source_or_seller']),
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

$matLower = strtolower(trim((string)$coin['material']));
$matClass = 'badge-material-default';
if ($matLower === 'silver' || $matLower === 'platinum') {
    $matClass = 'badge-material-silver';
} elseif ($matLower === 'gold') {
    $matClass = 'badge-material-gold';
} elseif (in_array($matLower, ['copper', 'bronze', 'brass'], true)) {
    $matClass = 'badge-material-copper';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1"/>
    <meta name="theme-color" content="#f6f1e7"/>
    <?= theme_boot_script() ?>

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

    <?php render_head_assets(); ?>

    <!-- Preload the largest image on the page so it paints sooner (Core Web Vitals) -->
    <link rel="preload" as="image" href="<?= sanitize($obvImg) ?>" fetchpriority="high"/>

    <!-- Structured Data (JSON-LD) -->
    <?= json_ld($webPage) ?>
    <?= json_ld($creativeWork) ?>
    <?= json_ld($breadcrumbs) ?>
</head>
<body class="page-coin">

    <?php render_site_header(false); ?>

    <main id="main" class="wrap coin-page">

        <nav aria-label="Breadcrumb">
            <ol class="breadcrumb-x">
                <li><a href="<?= home_url([], 'collection') ?>">Gallery</a></li>
                <li><a href="<?= sanitize(home_url(['country' => $coin['country']], 'collection')) ?>"><?= sanitize($coin['country']) ?></a></li>
                <li aria-current="page"><?= sanitize($coinTitle) ?></li>
            </ol>
        </nav>

        <div class="coin-layout">

            <!-- The display case: both faces of the coin on a turning flipper,
                 with a magnifying loupe under the cursor. Both images are in the
                 markup, so both are indexed whichever face is showing. -->
            <div class="display-case vault vault-grain">
                <span class="rosette" aria-hidden="true"></span>
                <div class="viewer" data-viewer>
                    <div class="viewer-flipper">
                        <figure class="viewer-face viewer-face--obv<?= $hasObv ? '' : ' is-placeholder' ?>">
                            <img src="<?= sanitize($obvImg) ?>" alt="<?= sanitize($imageAltObv) ?>" decoding="async" fetchpriority="high" width="440" height="440"/>
                        </figure>
                        <?php if ($hasRev): ?>
                            <figure class="viewer-face viewer-face--rev">
                                <img src="<?= sanitize($revImg) ?>" alt="<?= sanitize($imageAltRev) ?>" loading="lazy" decoding="async" width="440" height="440"/>
                            </figure>
                        <?php endif; ?>
                    </div>
                    <span class="loupe-lens" aria-hidden="true"></span>
                </div>

                <?php if ($hasRev): ?>
                    <div class="viewer-controls">
                        <div class="segmented" role="group" aria-label="Coin face">
                            <button type="button" data-face="obverse" aria-pressed="true">Obverse</button>
                            <button type="button" data-face="reverse" aria-pressed="false">Reverse</button>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($hasObv): ?>
                    <p class="viewer-hint">
                        <span class="hint-hover">Hover to magnify<?= $hasRev ? ' · click the coin or press F to turn it over' : '' ?></span>
                        <?php if ($hasRev): ?><span class="hint-touch">Tap the coin to turn it over</span><?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>

            <!-- Information hierarchy -->
            <div class="coin-info">
                <div class="coin-chips">
                    <?php if ((int)$coin['is_featured'] === 1): ?>
                        <span class="badge badge-featured">
                            <span class="material-symbols-outlined icon-fill" aria-hidden="true">star</span> Featured
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($coin['material'])): ?>
                        <span class="badge <?= $matClass ?>">
                            <span class="material-symbols-outlined" aria-hidden="true">diamond</span>
                            <?= sanitize($coin['material']) ?>
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($coin['condition_grade'])): ?>
                        <span class="badge badge-grade">
                            <span class="material-symbols-outlined" aria-hidden="true">verified</span>
                            Grade: <?= sanitize($coin['condition_grade']) ?>
                        </span>
                    <?php endif; ?>
                </div>

                <!-- Single H1 per page -->
                <h1 class="coin-title"><?= sanitize($coin['denomination']) ?></h1>
                <p class="coin-origin">
                    <?= sanitize($coin['country']) ?><?= trim((string)$coin['year']) !== '' ? ', ' . sanitize($coin['year']) : '' ?>
                </p>

                <!-- Plain-language summary. Search snippets and AI answer engines
                     quote self-contained sentences like this one. -->
                <p class="coin-summary"><?= sanitize($summary) ?></p>

                <!-- Specifications: a real table, so the facts are machine-readable -->
                <section aria-labelledby="specs-heading" class="spec-section">
                    <h2 id="specs-heading">
                        <span class="material-symbols-outlined" aria-hidden="true">straighten</span>
                        <span>Coin specifications</span>
                    </h2>
                    <div class="spec-wrap">
                        <table class="spec-table">
                            <caption class="visually-hidden">Technical specifications for <?= sanitize($coinTitle) ?></caption>
                            <tbody>
                                <?php foreach ($specs as [$label, $value]): ?>
                                    <?php if ($value === '') { continue; } ?>
                                    <tr>
                                        <th scope="row"><?= sanitize($label) ?></th>
                                        <td><?= sanitize($value) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <?php if (!empty($coin['notes'])): ?>
                    <section class="notes-section panel" aria-labelledby="notes-heading">
                        <h2 id="notes-heading">
                            <span class="material-symbols-outlined" aria-hidden="true">history_edu</span>
                            <span>Historical notes</span>
                        </h2>
                        <p><?= sanitize($coin['notes']) ?></p>
                    </section>
                <?php endif; ?>

                <!-- Provenance. Acquisition date and source are public: they are
                     genuinely useful to other collectors and are unique content no
                     other site has. The purchase price is not, and is rendered only
                     for the signed-in curator, so it never reaches a visitor or a
                     crawler. -->
                <?php
                    $hasPublicProvenance = !empty($coin['acquisition_date']) || !empty($coin['source_or_seller']);
                ?>
                <?php if ($hasPublicProvenance): ?>
                    <div class="provenance">
                        <?php if (!empty($coin['acquisition_date'])): ?>
                            <span class="item">
                                <span class="material-symbols-outlined" aria-hidden="true">calendar_month</span>
                                <span>Acquired: <?= sanitize($coin['acquisition_date']) ?></span>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($coin['source_or_seller'])): ?>
                            <span class="item">
                                <span class="material-symbols-outlined" aria-hidden="true">storefront</span>
                                <span>Source: <?= sanitize($coin['source_or_seller']) ?></span>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($isCurator): ?>
                    <div class="provenance curator mt-2">
                        <span class="item">
                            <span class="material-symbols-outlined" aria-hidden="true">visibility_off</span>
                            <strong>Curator only</strong>
                        </span>
                        <span class="item">
                            <span class="material-symbols-outlined" aria-hidden="true">lock</span>
                            <span>Cost: <?= sanitize(format_currency($coin['acquisition_price'])) ?></span>
                        </span>
                        <?php if (empty($coin['acquisition_date'])): ?>
                            <span class="item">
                                <span class="material-symbols-outlined" aria-hidden="true">calendar_month</span>
                                <span>Acquired: not recorded</span>
                            </span>
                        <?php endif; ?>
                        <?php if (empty($coin['source_or_seller'])): ?>
                            <span class="item">
                                <span class="material-symbols-outlined" aria-hidden="true">storefront</span>
                                <span>Source: not recorded</span>
                            </span>
                        <?php endif; ?>
                        <a href="/admin/edit-coin.php?id=<?= (int)$coin['id'] ?>" class="btn-x btn-ghost btn-x--sm">
                            <span class="material-symbols-outlined" aria-hidden="true">edit</span>
                            <span>Edit entry</span>
                        </a>
                    </div>
                <?php endif; ?>

                <div class="d-flex flex-wrap gap-2 mt-4">
                    <a href="<?= sanitize(home_url(['country' => $coin['country']], 'collection')) ?>" class="btn-x btn-ghost">
                        <span class="material-symbols-outlined" aria-hidden="true">public</span>
                        <span>More from <?= sanitize($coin['country']) ?></span>
                    </a>
                    <a href="<?= home_url([], 'collection') ?>" class="btn-x btn-ghost">
                        <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
                        <span>Back to the collection</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- Related entries: internal links let crawlers reach every coin and
             tie related pieces together as a topical cluster. -->
        <?php if ($relatedCoins): ?>
            <section class="section" aria-labelledby="related-heading">
                <div class="section-head">
                    <div>
                        <p class="eyebrow">Keep exploring</p>
                        <h2 id="related-heading" class="section-title">More coins in this archive</h2>
                    </div>
                </div>
                <div class="related-grid">
                    <?php foreach ($relatedCoins as $rc): ?>
                        <?php
                            $rcThumb = get_coin_thumbnail_path($rc['obverse_image']);
                            $rcYear  = trim((string)$rc['year']);
                        ?>
                        <a href="<?= sanitize(coin_path($rc)) ?>" class="mini-card">
                            <img src="<?= sanitize($rcThumb) ?>" alt="<?= sanitize(coin_display_title($rc)) ?>"
                                 class="<?= is_placeholder_image($rcThumb) ? 'is-placeholder' : '' ?>"
                                 loading="lazy" decoding="async" width="110" height="110"/>
                            <strong><?= sanitize($rc['denomination']) ?></strong>
                            <span><?= sanitize($rc['country']) ?><?= $rcYear !== '' ? ', ' . sanitize($rcYear) : '' ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

    </main>

    <?php render_site_footer(false); ?>

    <script src="<?= sanitize(asset_url('/assets/js/site.js')) ?>" defer></script>
</body>
</html>
