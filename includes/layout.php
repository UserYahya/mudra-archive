<?php
// includes/layout.php - Shared chrome for the public pages (gallery, coin pages,
// 404): asset URLs, the icon font, the no-flash theme script, header and footer.
// Requires functions.php to have been loaded first.

/**
 * Root-relative URL for a local asset with a cache-busting version, so a
 * changed stylesheet or script is fetched again despite the long-lived cache
 * headers in .htaccess.
 */
function asset_url($path) {
    $path = '/' . ltrim((string)$path, '/');
    $file = __DIR__ . '/..' . $path;
    return is_file($file) ? $path . '?v=' . filemtime($file) : $path;
}

/**
 * Material Symbols, subset to the icons the public pages actually use.
 *
 * The unsubset font is a single 4 MB download that every visitor paid for, and
 * until it arrived each icon showed as its ligature name ("dark_mode"). Google
 * Fonts serves only the listed glyphs when icon_names is given; the list must
 * be in alphabetical order, so it is sorted here rather than trusted.
 * Adding an icon to a public page? Add its name here too.
 */
function icon_font_url() {
    $icons = [
        'add', 'arrow_back', 'arrow_downward', 'arrow_forward', 'calendar_month', 'close',
        'dark_mode', 'dashboard', 'diamond', 'edit', 'expand_less', 'expand_more',
        'fullscreen', 'fullscreen_exit', 'grid_view', 'help', 'history_edu', 'home', 'info',
        'light_mode', 'lock', 'mail', 'menu', 'money_off', 'photo_camera', 'public', 'remove',
        'search', 'search_off', 'star', 'storefront', 'straighten', 'swap_horiz', 'sync',
        'tune', 'verified', 'visibility_off', 'zoom_in',
    ];
    $icons = array_values(array_unique($icons));
    sort($icons, SORT_STRING);
    return 'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0..1,0'
        . '&icon_names=' . implode(',', $icons) . '&display=block';
}

/**
 * Font, stylesheet and icon <link> tags shared by every public page.
 */
function render_head_assets() {
    ?>
    <link rel="preconnect" href="https://fonts.googleapis.com"/>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin=""/>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin=""/>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400..900;1,400..900&amp;family=Source+Sans+3:ital,wght@0,300..900;1,300..900&amp;display=swap" rel="stylesheet"/>
    <link rel="stylesheet" href="<?= sanitize(icon_font_url()) ?>"/>

    <link rel="icon" type="image/x-icon" href="/assets/favicon.ico"/>
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32x32.png"/>
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/apple-touch-icon.png"/>
    <link rel="manifest" href="/site.webmanifest"/>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
    <link href="<?= sanitize(asset_url('/assets/css/style.css')) ?>" rel="stylesheet"/>
    <?php
}

/**
 * Applies the saved theme, or the system preference, before first paint, so a
 * dark-mode visitor never sees a flash of the light page. Bootstrap's own
 * colour mode attribute is kept in step for its form controls.
 */
function theme_boot_script() {
    return "<script>(function(){var d=document.documentElement,t=null;"
        . "try{t=localStorage.getItem('mudra_theme')}catch(e){}"
        . "if(t!=='light'&&t!=='dark'){t=window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'}"
        . "d.setAttribute('data-theme',t);d.setAttribute('data-bs-theme',t);"
        . "var m=document.querySelector('meta[name=\"theme-color\"]');if(m){m.setAttribute('content',t==='dark'?'#0b0d12':'#f6f1e7')}"
        . "})();</script>";
}

/**
 * Site header. On the gallery the section links are bare fragments, so they
 * scroll within the current (possibly filtered) view instead of reloading "/".
 */
function render_site_header($isHome = false) {
    $p = $isHome ? '' : '/';
    $links = [
        ['collection',   'Collection', 'grid_view'],
        ['countriesMap', 'Atlas',      'public'],
        ['about',        'About',      'info'],
        ['faq',          'FAQ',        'help'],
    ];
    ?>
    <a class="skip-link" href="#main">Skip to content</a>
    <header class="site-header" id="siteHeader">
        <div class="wrap header-inner">
            <a href="/" class="brand">
                <img src="/assets/logo-128.png" alt="" class="brand-coin" width="42" height="42" decoding="async"/>
                <span class="brand-text">
                    <span class="brand-name"><?= sanitize(SITE_NAME) ?></span>
                    <span class="brand-sub" lang="bn">মুদ্রা আর্কাইভ</span>
                </span>
            </a>

            <nav class="site-nav" aria-label="Main">
                <?php foreach ($links as $l): ?>
                    <a href="<?= $p ?>#<?= $l[0] ?>" data-nav-section="<?= $l[0] ?>"><?= $l[1] ?></a>
                <?php endforeach; ?>
            </nav>

            <div class="header-actions">
                <?php if (is_admin_logged_in()): ?>
                    <a href="/admin/dashboard.php" class="btn-x btn-ghost btn-x--sm d-none d-md-inline-flex">
                        <span class="material-symbols-outlined" aria-hidden="true">dashboard</span>
                        <span>Dashboard</span>
                    </a>
                <?php endif; ?>
                <button type="button" class="icon-btn" data-theme-toggle aria-label="Switch colour theme">
                    <span class="material-symbols-outlined theme-icon-moon" aria-hidden="true">dark_mode</span>
                    <span class="material-symbols-outlined theme-icon-sun" aria-hidden="true">light_mode</span>
                </button>
                <button type="button" class="icon-btn only-mobile" data-nav-toggle aria-controls="mobileNav" aria-expanded="false" aria-label="Open menu">
                    <span class="material-symbols-outlined" aria-hidden="true">menu</span>
                </button>
            </div>
        </div>

        <nav class="mobile-nav" id="mobileNav" aria-label="Mobile" hidden>
            <ul class="wrap">
                <?php foreach ($links as $l): ?>
                    <li>
                        <a href="<?= $p ?>#<?= $l[0] ?>">
                            <span class="material-symbols-outlined" aria-hidden="true"><?= $l[2] ?></span>
                            <span><?= $l[1] ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
                <?php if (is_admin_logged_in()): ?>
                    <li>
                        <a href="/admin/dashboard.php">
                            <span class="material-symbols-outlined" aria-hidden="true">dashboard</span>
                            <span>Admin dashboard</span>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>
    </header>
    <?php
}

/**
 * Site footer, on the dark "vault" surface with the guilloche rosette.
 */
function render_site_footer($isHome = false) {
    $p = $isHome ? '' : '/';
    ?>
    <footer class="site-footer vault vault-grain">
        <span class="rosette" aria-hidden="true"></span>
        <div class="wrap">
            <div class="footer-grid">
                <div class="footer-brand">
                    <img src="/assets/logo-128.png" alt="" width="64" height="64" loading="lazy" decoding="async"/>
                    <div>
                        <div class="name"><?= sanitize(SITE_NAME) ?></div>
                        <div class="sub">A personal digital archive of <?= sanitize(SITE_AUTHOR) ?>'s numismatic collection.</div>
                    </div>
                </div>
                <div>
                    <p class="footer-heading">Explore</p>
                    <ul>
                        <li><a href="<?= $p ?>#collection">The collection</a></li>
                        <li><a href="<?= $p ?>#countriesMap">World atlas</a></li>
                        <li><a href="<?= sanitize(home_url(['featured' => '1'], 'collection')) ?>">Featured coins</a></li>
                    </ul>
                </div>
                <div>
                    <p class="footer-heading">The archive</p>
                    <ul>
                        <li><a href="<?= $p ?>#about">About</a></li>
                        <li><a href="<?= $p ?>#faq">FAQ</a></li>
                        <li><a href="mailto:<?= sanitize(SITE_CONTACT) ?>">Contact</a></li>
                        <li><a href="/sitemap.xml">XML sitemap</a></li>
                    </ul>
                </div>
            </div>
            <div class="footer-bottom">
                <span>&copy; <?= date('Y') ?> <?= sanitize(SITE_NAME) ?></span>
                <span>Published for reference &middot; nothing here is for sale</span>
            </div>
        </div>
    </footer>
    <?php
}
