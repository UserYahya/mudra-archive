<?php
// 404.php - Not Found page. Returns a real 404 status so search engines drop
// dead URLs instead of indexing a redirect to the homepage.
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';

if (http_response_code() === 200) {
    http_response_code(404);
}
send_security_headers();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <meta name="robots" content="noindex, follow"/>
    <meta name="theme-color" content="#f6f1e7"/>
    <?= theme_boot_script() ?>
    <title>Page not found | <?= sanitize(SITE_NAME) ?></title>
    <?php render_head_assets(); ?>
</head>
<body class="page-404">

    <?php render_site_header(false); ?>

    <main id="main" class="wrap not-found-page">
        <section class="not-found-shell vault vault-grain" aria-labelledby="nf-title">
            <span class="rosette" aria-hidden="true"></span>
            <div class="not-found">
                <div>
                    <img class="coin" src="/assets/logo.png" alt="" width="160" height="160" decoding="async"/>
                    <p class="code-404">Error 404</p>
                    <h1 id="nf-title">This page is not in the archive</h1>
                    <p>The coin or page you were looking for has moved or no longer exists.</p>
                    <div class="d-flex justify-content-center flex-wrap gap-2">
                        <a href="/" class="btn-x btn-gold">
                            <span class="material-symbols-outlined" aria-hidden="true">home</span>
                            <span>Browse the collection</span>
                        </a>
                        <a href="/#countriesMap" class="btn-x btn-ghost-light">
                            <span class="material-symbols-outlined" aria-hidden="true">public</span>
                            <span>Open the atlas</span>
                        </a>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <?php render_site_footer(false); ?>

    <script src="<?= sanitize(asset_url('/assets/js/site.js')) ?>" defer></script>
</body>
</html>
