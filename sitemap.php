<?php
// sitemap.php - Dynamic XML sitemap, served at /sitemap.xml via .htaccess.
// Includes the Google image extension so every coin photograph is eligible for
// Google Images, which is a meaningful traffic source for a visual archive.
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/xml; charset=utf-8');
header('X-Robots-Tag: noindex');

$baseUrl = CANONICAL_ORIGIN;

$stmt = $pdo->query(
    "SELECT id, country, currency_name, denomination, year, obverse_image, reverse_image, updated_at, created_at
     FROM coins
     WHERE " . public_coins_filter() . "
     ORDER BY id DESC"
);
$coins = $stmt->fetchAll();

// Freshest coin timestamp drives the homepage lastmod.
$homeLastMod = date('Y-m-d');
foreach ($coins as $c) {
    $stamp = $c['updated_at'] ?: $c['created_at'];
    if ($stamp) {
        $homeLastMod = substr($stamp, 0, 10);
        break;
    }
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
    <url>
        <loc><?= htmlspecialchars($baseUrl . '/', ENT_XML1) ?></loc>
        <lastmod><?= $homeLastMod ?></lastmod>
        <changefreq>daily</changefreq>
        <priority>1.0</priority>
    </url>
<?php foreach ($coins as $c): ?>
<?php
    $stamp   = $c['updated_at'] ?: $c['created_at'];
    $lastMod = $stamp ? substr($stamp, 0, 10) : date('Y-m-d');
    $title   = coin_display_title($c);

    $images = [];
    foreach (['obverse_image' => 'Obverse of ', 'reverse_image' => 'Reverse of '] as $field => $prefix) {
        $path = get_coin_image_path($c[$field]);
        if ($path !== 'assets/logo.png') {
            $images[] = ['url' => $baseUrl . '/' . $path, 'caption' => $prefix . $title];
        }
    }
?>
    <url>
        <loc><?= htmlspecialchars(coin_url($c, $baseUrl), ENT_XML1) ?></loc>
        <lastmod><?= $lastMod ?></lastmod>
        <changefreq>monthly</changefreq>
        <priority>0.8</priority>
<?php foreach ($images as $img): ?>
        <image:image>
            <image:loc><?= htmlspecialchars($img['url'], ENT_XML1) ?></image:loc>
            <image:title><?= htmlspecialchars($title, ENT_XML1) ?></image:title>
            <image:caption><?= htmlspecialchars($img['caption'], ENT_XML1) ?></image:caption>
        </image:image>
<?php endforeach; ?>
    </url>
<?php endforeach; ?>
</urlset>
