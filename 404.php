<?php
// 404.php - Not Found page. Returns a real 404 status so search engines drop
// dead URLs instead of indexing a redirect to the homepage.
require_once __DIR__ . '/includes/functions.php';

if (http_response_code() === 200) {
    http_response_code(404);
}
send_security_headers();

$cssVersion = file_exists(__DIR__ . '/assets/css/style.css') ? filemtime(__DIR__ . '/assets/css/style.css') : time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <meta name="robots" content="noindex, follow"/>
    <title>Page not found | <?= sanitize(SITE_NAME) ?></title>
    <link rel="icon" type="image/x-icon" href="/assets/favicon.ico"/>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400..900;1,400..900&family=Source+Sans+3:ital,wght@0,300..900;1,300..900&display=swap" rel="stylesheet"/>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200"/>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
    <link href="/assets/css/style.css?v=<?= $cssVersion ?>" rel="stylesheet"/>
</head>
<body class="d-flex flex-column min-vh-100">
    <main class="flex-grow-1 d-flex align-items-center justify-content-center text-center px-3 py-5">
        <div>
            <span class="material-symbols-outlined text-muted" style="font-size:4rem;" aria-hidden="true">search_off</span>
            <h1 class="font-heading display-6 mt-2 mb-2">This page is not in the archive</h1>
            <p class="text-secondary mb-4">The coin or page you were looking for has moved or no longer exists.</p>
            <a href="/" class="btn btn-primary-archival">Browse the collection</a>
        </div>
    </main>
    <footer class="py-4 border-top text-center text-muted small">
        <p class="mb-0">&copy; <?= date('Y') ?> <?= sanitize(SITE_NAME) ?></p>
    </footer>
</body>
</html>
