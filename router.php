<?php
// router.php - Front controller for the PHP built-in server ONLY.
//   php -S localhost:8000 router.php
// It reproduces the .htaccess rewrites so clean coin URLs work locally.
// Apache/cPanel never uses this file.

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Block the local-only quarantine folder and the private directories.
if (preg_match('#^/(_private|database|includes)(/|$)#', $path)) {
    http_response_code(403);
    exit('Forbidden');
}

// /sitemap.xml -> sitemap.php
if ($path === '/sitemap.xml') {
    require __DIR__ . '/sitemap.php';
    return true;
}

// /coin/12-morgan-dollar-united-states-1921 -> coin.php?id=12
if (preg_match('#^/coin/([0-9]+)(-[^/]*)?/?$#', $path, $m)) {
    $_GET['id'] = (int)$m[1];
    require __DIR__ . '/coin.php';
    return true;
}

// Serve existing static files as-is.
$file = __DIR__ . urldecode($path);
if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
return true;
