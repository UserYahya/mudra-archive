<?php
// includes/functions.php - Mudra Archive Utility & Helper Functions

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/countries.php';

start_secure_session();

/**
 * Sanitize string input for safe HTML output
 */
function sanitize($data) {
    return htmlspecialchars(trim((string)$data), ENT_QUOTES, 'UTF-8');
}

/**
 * Escape a value for use inside an HTML attribute that is not already quoted
 * by sanitize() - kept as a distinct name so intent is readable at call sites.
 */
function attr($data) {
    return sanitize($data);
}

/**
 * Site base absolute URL for canonical tags, Open Graph, Twitter Cards and the
 * sitemap. Unrecognised Host headers fall back to the canonical origin, so a
 * forged Host cannot be reflected into a canonical tag or a redirect.
 */
function get_base_url() {
    $host = strtolower($_SERVER['HTTP_HOST'] ?? '');

    if (!in_array($host, trusted_hosts(), true)) {
        return CANONICAL_ORIGIN;
    }

    // The live site always advertises one origin, never the www. alias.
    if ($host === 'mudra.yahya.bd' || $host === 'www.mudra.yahya.bd') {
        return CANONICAL_ORIGIN;
    }

    return (request_is_https() ? 'https' : 'http') . '://' . $host;
}

// --- Authentication -------------------------------------------------------

/**
 * Check if current user is authenticated as admin, enforcing an idle timeout.
 */
function is_admin_logged_in() {
    if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        return false;
    }

    $last = (int)($_SESSION['admin_last_active'] ?? 0);
    if ($last > 0 && (time() - $last) > ADMIN_IDLE_TIMEOUT) {
        admin_session_destroy();
        return false;
    }

    // Binding the session to the browser's UA string makes a stolen cookie
    // marginally harder to replay.
    $ua = $_SESSION['admin_ua'] ?? '';
    if ($ua !== '' && !hash_equals($ua, hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? ''))) {
        admin_session_destroy();
        return false;
    }

    $_SESSION['admin_last_active'] = time();
    return true;
}

/**
 * Establish an authenticated admin session.
 */
function admin_session_start($username) {
    session_regenerate_id(true); // Prevent session fixation
    $_SESSION['admin_logged_in']  = true;
    $_SESSION['admin_user']       = $username;
    $_SESSION['admin_last_active'] = time();
    $_SESSION['admin_ua']         = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
    unset($_SESSION['csrf_token']); // issue a fresh token for the new session
}

/**
 * Tear down the session and its cookie.
 */
function admin_session_destroy() {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}

/**
 * Enforce admin authentication guard for protected routes.
 */
function require_admin() {
    if (!is_admin_logged_in()) {
        header('Location: login.php?expired=1');
        exit();
    }
}

// --- SEO helpers ----------------------------------------------------------

/**
 * URL-safe slug from arbitrary text (transliterating where the intl/iconv
 * extensions allow it).
 */
function slugify($text) {
    $text = trim((string)$text);
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);
        if ($converted !== false) {
            $text = $converted;
        }
    }
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

/**
 * Root-relative URL for the gallery, optionally with query parameters and a
 * fragment. Everything links to "/" rather than "/index.php" so there is a
 * single indexable homepage.
 */
function home_url(array $query = [], $fragment = '') {
    $url = '/';
    $query = array_filter($query, function ($v) {
        return $v !== null && $v !== '' && $v !== [];
    });
    if ($query) {
        $url .= '?' . http_build_query($query);
    }
    if ($fragment !== '') {
        $url .= '#' . ltrim($fragment, '#');
    }
    return $url;
}

/**
 * Human-readable title for one coin: "Morgan Dollar, United States, 1921".
 * Used for <title>, headings, link text and structured data so all three agree.
 */
function coin_display_title(array $coin) {
    $parts = array_filter([
        trim((string)$coin['denomination']),
        trim((string)$coin['country']),
        trim((string)$coin['year']),
    ], function ($p) {
        return $p !== '' && strtolower($p) !== 'year';
    });
    return implode(', ', $parts);
}

/**
 * Canonical path for a coin. Clean, keyword-bearing URLs rank and read better
 * than coin.php?id=12; the legacy query form keeps working and 301s to this.
 */
function coin_path(array $coin) {
    if (!PRETTY_URLS) {
        return 'coin.php?id=' . (int)$coin['id'];
    }
    $slug = slugify(coin_display_title($coin));
    return '/coin/' . (int)$coin['id'] . ($slug !== '' ? '-' . $slug : '');
}

/**
 * Absolute canonical URL for a coin.
 */
function coin_url(array $coin, $baseUrl = null) {
    $baseUrl = $baseUrl ?? get_base_url();
    $path = coin_path($coin);
    return $path[0] === '/' ? $baseUrl . $path : $baseUrl . '/' . $path;
}

/**
 * One factual sentence describing a coin. Search snippets and AI answer
 * engines both lift text like this, so it states the facts plainly and in full
 * rather than relying on the surrounding page for context.
 */
function coin_summary(array $coin) {
    $bits = [];
    $bits[] = trim((string)$coin['denomination']);

    $origin = trim((string)$coin['country']);
    $year   = trim((string)$coin['year']);
    if ($origin !== '' && $year !== '' && strtolower($year) !== 'year') {
        $bits[] = 'is a coin issued by ' . $origin . ' in ' . $year;
    } elseif ($origin !== '') {
        $bits[] = 'is a coin issued by ' . $origin;
    } else {
        $bits[] = 'is a coin in the ' . SITE_NAME;
    }

    $sentence = implode(' ', $bits) . '.';

    $facts = [];
    if (!empty($coin['material']))        { $facts[] = 'struck in ' . strtolower(trim($coin['material'])); }
    if (!empty($coin['weight_grams']))    { $facts[] = 'weighing ' . rtrim(rtrim(number_format((float)$coin['weight_grams'], 2), '0'), '.') . ' g'; }
    if (!empty($coin['diameter_mm']))     { $facts[] = 'measuring ' . rtrim(rtrim(number_format((float)$coin['diameter_mm'], 2), '0'), '.') . ' mm across'; }
    if (!empty($coin['condition_grade'])) { $facts[] = 'graded ' . trim($coin['condition_grade']); }

    if ($facts) {
        $sentence .= ' It is ' . implode(', ', $facts) . '.';
    }

    if (!empty($coin['ruler_or_series'])) {
        $sentence .= ' The piece belongs to the ' . trim($coin['ruler_or_series']) . ' series.';
    }

    return $sentence;
}

/**
 * Meta description for a coin page, trimmed to a length search engines display
 * without truncating mid-word.
 */
function coin_meta_description(array $coin) {
    $text = coin_summary($coin);
    if (!empty($coin['notes'])) {
        $text .= ' ' . trim(preg_replace('/\s+/', ' ', $coin['notes']));
    }
    return truncate_text($text, 158);
}

/**
 * Trim text to a length without cutting a word in half. Falls back to the
 * byte-wise string functions where the mbstring extension is unavailable, which
 * is common on shared hosting.
 */
function truncate_text($text, $limit) {
    $hasMb = function_exists('mb_strlen');
    $text  = trim(preg_replace('/\s+/', ' ', (string)$text));

    $length = $hasMb ? mb_strlen($text, 'UTF-8') : strlen($text);
    if ($length <= $limit) {
        return $text;
    }

    $cut       = $hasMb ? mb_substr($text, 0, $limit - 1, 'UTF-8') : substr($text, 0, $limit - 1);
    $lastSpace = $hasMb ? mb_strrpos($cut, ' ', 0, 'UTF-8') : strrpos($cut, ' ');

    if ($lastSpace !== false && $lastSpace > $limit * 0.6) {
        $cut = $hasMb ? mb_substr($cut, 0, $lastSpace, 'UTF-8') : substr($cut, 0, $lastSpace);
    }

    return rtrim($cut, " ,.;:-") . '…';
}

/**
 * Render a JSON-LD block. json_encode handles quoting and Unicode correctly;
 * the previous addslashes() approach produced invalid JSON for any value
 * containing an apostrophe or a non-ASCII character.
 */
function json_ld(array $data) {
    $json = json_encode(
        $data,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE
    );
    // </script> cannot appear inside the block, even via a coin note.
    $json = str_replace('<', '<', $json);
    return '<script type="application/ld+json">' . "\n" . $json . "\n" . '</script>';
}

// --- Image handling -------------------------------------------------------

/**
 * Helper to generate a small thumbnail from an image file
 */
function generate_thumbnail_file($sourcePath, $thumbPath, $thumbMaxDim = 350, $quality = 75) {
    if (!file_exists($sourcePath) || !extension_loaded('gd')) return false;

    $imgData = @file_get_contents($sourcePath);
    if ($imgData === false) return false;

    $srcImg = @imagecreatefromstring($imgData);
    if (!$srcImg) return false;

    $origW = imagesx($srcImg);
    $origH = imagesy($srcImg);

    if ($origW > $thumbMaxDim || $origH > $thumbMaxDim) {
        if ($origW > $origH) {
            $thumbW = $thumbMaxDim;
            $thumbH = (int)round(($origH / $origW) * $thumbMaxDim);
        } else {
            $thumbH = $thumbMaxDim;
            $thumbW = (int)round(($origW / $origH) * $thumbMaxDim);
        }
    } else {
        $thumbW = $origW;
        $thumbH = $origH;
    }

    $thumbImg = imagecreatetruecolor($thumbW, $thumbH);
    $white = imagecolorallocate($thumbImg, 255, 255, 255);
    imagefill($thumbImg, 0, 0, $white);
    imagealphablending($thumbImg, true);

    imagecopyresampled($thumbImg, $srcImg, 0, 0, 0, 0, $thumbW, $thumbH, $origW, $origH);
    imagejpeg($thumbImg, $thumbPath, $quality);

    imagedestroy($thumbImg);
    imagedestroy($srcImg);
    return true;
}

/**
 * Process, resize, and compress uploaded coin images safely.
 * Generates both full-size image (max 1200px) and gallery thumbnail (max 350px, ~20KB).
 */
function upload_coin_image($fileKey, $targetDir = __DIR__ . '/../assets/uploads/') {
    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    if (!file_exists($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    $fileTmpPath = $_FILES[$fileKey]['tmp_name'];
    $fileName    = $_FILES[$fileKey]['name'];
    $fileSize    = $_FILES[$fileKey]['size'];

    // Reject anything that did not arrive as a genuine HTTP upload.
    if (!is_uploaded_file($fileTmpPath)) {
        return null;
    }

    // 25 MB limit
    if ($fileSize > 25 * 1024 * 1024) {
        return null;
    }

    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($ext, $allowedExts, true)) {
        return null;
    }

    // Verify actual image MIME type if finfo available
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $fileTmpPath);
        finfo_close($finfo);

        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($mimeType, $allowedMimes, true)) {
            return null;
        }
    }

    // getimagesize() is a second, independent check that the bytes really are a
    // decodable raster image and not a polyglot file with an image header.
    $dimensions = @getimagesize($fileTmpPath);
    if ($dimensions === false || empty($dimensions[0]) || empty($dimensions[1])) {
        return null;
    }

    // Save all optimized coin images as .jpg for 100% consistent white background rendering
    $newFileName = 'coin_' . time() . '_' . bin2hex(random_bytes(4)) . '.jpg';
    $targetPath  = rtrim($targetDir, '/\\') . '/' . $newFileName;
    $thumbPath   = rtrim($targetDir, '/\\') . '/thumb_' . $newFileName;

    // GD Processing
    if (extension_loaded('gd') && function_exists('imagecreatefromstring')) {
        $imgData = @file_get_contents($fileTmpPath);
        if ($imgData !== false) {
            $srcImg = @imagecreatefromstring($imgData);
            if ($srcImg !== false) {
                $origWidth  = imagesx($srcImg);
                $origHeight = imagesy($srcImg);
                $maxDim = 1200; // Full size for detail page

                // Step 1: Flatten transparent PNG layers onto solid white at full resolution
                $flatSrc = imagecreatetruecolor($origWidth, $origHeight);
                $white = imagecolorallocate($flatSrc, 255, 255, 255);
                imagefill($flatSrc, 0, 0, $white);
                imagealphablending($flatSrc, true);
                imagecopy($flatSrc, $srcImg, 0, 0, 0, 0, $origWidth, $origHeight);

                // Step 2: Calculate scale dimensions for full size
                if ($origWidth > $maxDim || $origHeight > $maxDim) {
                    if ($origWidth > $origHeight) {
                        $newWidth = $maxDim;
                        $newHeight = (int)round(($origHeight / $origWidth) * $maxDim);
                    } else {
                        $newHeight = $maxDim;
                        $newWidth = (int)round(($origWidth / $origHeight) * $maxDim);
                    }
                } else {
                    $newWidth = $origWidth;
                    $newHeight = $origHeight;
                }

                // Step 3: Resample the full-size image
                $dstImg = imagecreatetruecolor($newWidth, $newHeight);
                imagefill($dstImg, 0, 0, $white);
                imagecopyresampled($dstImg, $flatSrc, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
                imagejpeg($dstImg, $targetPath, 85);
                imagedestroy($dstImg);

                // Step 4: Generate small compressed gallery thumbnail (max 350px, ~20KB)
                $thumbMax = 350;
                if ($origWidth > $thumbMax || $origHeight > $thumbMax) {
                    if ($origWidth > $origHeight) {
                        $tW = $thumbMax;
                        $tH = (int)round(($origHeight / $origWidth) * $thumbMax);
                    } else {
                        $tH = $thumbMax;
                        $tW = (int)round(($origWidth / $origHeight) * $thumbMax);
                    }
                } else {
                    $tW = $origWidth;
                    $tH = $origHeight;
                }

                $thumbImg = imagecreatetruecolor($tW, $tH);
                imagefill($thumbImg, 0, 0, $white);
                imagecopyresampled($thumbImg, $flatSrc, 0, 0, 0, 0, $tW, $tH, $origWidth, $origHeight);
                imagejpeg($thumbImg, $thumbPath, 75);
                imagedestroy($thumbImg);

                imagedestroy($flatSrc);
                imagedestroy($srcImg);
                return $newFileName;
            }
        }
    }

    // Fallback standard move
    if (move_uploaded_file($fileTmpPath, $targetPath)) {
        generate_thumbnail_file($targetPath, $thumbPath, 350, 75);
        return $newFileName;
    }

    return null;
}

/**
 * Reject stored filenames that try to escape the uploads directory. Values come
 * from the database, but a path traversal there should not become a file read.
 */
function safe_upload_name($imageFileName) {
    $name = basename((string)$imageFileName);
    return ($name === '' || $name === '.' || $name === '..') ? '' : $name;
}

/**
 * Shown when a coin has no photograph on file.
 */
function placeholder_image_path() {
    return '/assets/logo.png';
}

function is_placeholder_image($path) {
    return $path === placeholder_image_path();
}

/**
 * Absolute URL for a root-relative asset path, for Open Graph tags, structured
 * data and the sitemap.
 */
function absolute_url($path, $baseUrl = null) {
    $baseUrl = $baseUrl ?? get_base_url();
    return rtrim($baseUrl, '/') . '/' . ltrim((string)$path, '/');
}

/**
 * Returns small thumbnail URL for gallery list view (max 350px, ~20KB)
 * Falls back to full image if thumbnail not generated yet.
 *
 * Paths are root-relative ("/assets/uploads/x.jpg"), never relative, because
 * pages are served from more than one URL depth: the gallery sits at "/" but a
 * coin page sits at "/coin/12-slug". A relative src resolves against the
 * current directory, so on a coin page it became "/coin/assets/uploads/x.jpg"
 * and every photograph broke.
 */
function get_coin_thumbnail_path($imageFileName, $baseDir = '/assets/uploads/') {
    $imageFileName = safe_upload_name($imageFileName);
    if ($imageFileName === '') return placeholder_image_path();

    $uploadDir = __DIR__ . '/../assets/uploads/';
    $fullPath  = $uploadDir . $imageFileName;
    $thumbFile = 'thumb_' . $imageFileName;
    $thumbPath = $uploadDir . $thumbFile;

    if (file_exists($thumbPath)) {
        return $baseDir . $thumbFile;
    }

    // Auto-generate thumbnail on the fly if missing and full file exists
    if (file_exists($fullPath)) {
        generate_thumbnail_file($fullPath, $thumbPath, 350, 75);
        if (file_exists($thumbPath)) {
            return $baseDir . $thumbFile;
        }
        return $baseDir . $imageFileName;
    }

    return placeholder_image_path();
}

/**
 * Full-size image URL for a coin face, or the logo placeholder.
 * Root-relative, for the same reason as get_coin_thumbnail_path().
 */
function get_coin_image_path($imageFileName, $baseDir = '/assets/uploads/') {
    $imageFileName = safe_upload_name($imageFileName);
    if ($imageFileName === '') return placeholder_image_path();

    return file_exists(__DIR__ . '/../assets/uploads/' . $imageFileName)
        ? $baseDir . $imageFileName
        : placeholder_image_path();
}

// --- Social preview cards -------------------------------------------------

/**
 * Compose a 1200x630 preview card from up to four coin photographs laid out as
 * circles on the archival cream background, and return its root-relative path.
 *
 * Link previews need a landscape image. A bare coin photo is square, around
 * 300 KB, and carries no dimension metadata, which is why Facebook, LinkedIn
 * and WhatsApp were showing a title and description with no picture:
 *   - 1200x630 is the aspect ratio Open Graph and summary_large_image expect;
 *     a square image is cropped top and bottom, which beheads a round coin.
 *   - WhatsApp drops preview images over roughly 300 KB, and several of the
 *     full-size photographs are larger than that.
 *   - og:image:width / og:image:height let a scraper render the card on the
 *     first fetch instead of queueing the image and showing nothing.
 *
 * Cards are cached on disk and keyed by their source files, so a card is
 * rebuilt only when the coin's photographs change.
 *
 * @return string|null Root-relative path, or null when GD cannot produce one.
 */
function generate_social_card(array $sourceFiles, $cacheKey) {
    if (!extension_loaded('gd') || !function_exists('imagecreatefromstring')) {
        return null;
    }

    $sourceFiles = array_values(array_filter($sourceFiles, 'is_file'));
    if (!$sourceFiles) {
        return null;
    }

    $uploadDir = __DIR__ . '/../assets/uploads/';
    $fileName  = 'social_' . preg_replace('/[^A-Za-z0-9_]/', '', $cacheKey) . '.jpg';
    $outPath   = $uploadDir . $fileName;
    $webPath   = '/assets/uploads/' . $fileName;

    if (is_file($outPath)) {
        return $webPath;
    }
    if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
        return null;
    }

    $W = 1200; $H = 630; $PAD = 60; $GAP = 40;

    $canvas = imagecreatetruecolor($W, $H);
    // Warm archival cream, matching --surface-bright in the stylesheet.
    $bg = imagecolorallocate($canvas, 0xF8, 0xF6, 0xF0);
    imagefilledrectangle($canvas, 0, 0, $W, $H, $bg);
    $ring = imagecolorallocate($canvas, 0xD8, 0xD3, 0xC6);

    $sources = [];
    foreach (array_slice($sourceFiles, 0, 4) as $file) {
        $data = @file_get_contents($file);
        if ($data === false) continue;
        $img = @imagecreatefromstring($data);
        if ($img !== false) $sources[] = $img;
    }
    if (!$sources) {
        imagedestroy($canvas);
        return null;
    }

    $n = count($sources);
    $d = (int)min($H - 2 * $PAD, ($W - 2 * $PAD - ($n - 1) * $GAP) / $n);
    $r = $d / 2;
    $totalW = $n * $d + ($n - 1) * $GAP;
    $startX = (int)(($W - $totalW) / 2);
    $topY   = (int)(($H - $d) / 2);

    foreach ($sources as $i => $src) {
        // Crop to a centred square first so the coin is never distorted.
        $sw = imagesx($src); $sh = imagesy($src);
        $side = min($sw, $sh);
        $sx = (int)(($sw - $side) / 2);
        $sy = (int)(($sh - $side) / 2);

        $square = imagecreatetruecolor($d, $d);
        imagefilledrectangle($square, 0, 0, $d, $d, imagecolorallocate($square, 255, 255, 255));
        imagecopyresampled($square, $src, 0, 0, $sx, $sy, $d, $d, $side, $side);

        // Copy the square onto the canvas one row at a time, clipped to the
        // circle. GD has no alpha masking, and this is exact and quick.
        $cx = $startX + $i * ($d + $GAP);
        for ($y = 0; $y < $d; $y++) {
            $dy   = $y - $r + 0.5;
            $half = sqrt(max(0.0, $r * $r - $dy * $dy));
            $x0   = (int)round($r - $half);
            $w    = (int)round(2 * $half);
            if ($w > 0) {
                imagecopy($canvas, $square, $cx + $x0, $topY + $y, $x0, $y, $w, 1);
            }
        }

        imagesetthickness($canvas, 3);
        imageellipse($canvas, (int)($cx + $r), (int)($topY + $r), $d, $d, $ring);

        imagedestroy($square);
        imagedestroy($src);
    }

    // Quality 80 keeps a two-coin card comfortably under 150 KB.
    $ok = imagejpeg($canvas, $outPath, 80);
    imagedestroy($canvas);

    return $ok ? $webPath : null;
}

/**
 * Social preview card for one coin: obverse and reverse side by side.
 */
function coin_social_image(array $coin) {
    $uploadDir = __DIR__ . '/../assets/uploads/';
    $files = [];
    foreach (['obverse_image', 'reverse_image'] as $field) {
        $name = safe_upload_name($coin[$field] ?? '');
        if ($name !== '' && is_file($uploadDir . $name)) {
            $files[] = $uploadDir . $name;
        }
    }
    if (!$files) {
        return null;
    }

    $key = (int)$coin['id'] . '_' . substr(md5(implode('|', $files) . '|' . ($coin['updated_at'] ?? '')), 0, 10);
    return generate_social_card($files, $key);
}

/**
 * Social preview card for the gallery: the featured coins on the homepage.
 */
function collection_social_image(array $heroCoins) {
    $uploadDir = __DIR__ . '/../assets/uploads/';
    $files = [];
    foreach ($heroCoins as $c) {
        $name = safe_upload_name($c['obverse_image'] ?? '');
        if ($name !== '' && is_file($uploadDir . $name)) {
            $files[] = $uploadDir . $name;
        }
    }
    if (!$files) {
        return null;
    }

    $key = 'home_' . substr(md5(implode('|', $files)), 0, 10);
    return generate_social_card($files, $key);
}

/**
 * Pixel dimensions of a root-relative image path, for og:image:width/height.
 */
function image_dimensions($webPath) {
    $file = __DIR__ . '/../' . ltrim((string)$webPath, '/');
    if (!is_file($file)) {
        return null;
    }
    $info = @getimagesize($file);
    return $info ? ['width' => $info[0], 'height' => $info[1], 'mime' => $info['mime']] : null;
}

/**
 * Format price as currency string
 */
function format_currency($amount) {
    if ($amount === null || $amount === '') return 'N/A';
    return '$' . number_format((float)$amount, 2);
}

// --- Collection queries ---------------------------------------------------

/**
 * SQL fragment excluding placeholder rows from every public-facing figure.
 * Centralised so the gallery, the statistics and the map can never disagree
 * about what counts as a coin - previously three queries filtered out the
 * "Test coun" row and four others did not.
 */
function public_coins_filter($alias = '') {
    $p = $alias !== '' ? $alias . '.' : '';
    return "({$p}country IS NOT NULL AND TRIM({$p}country) != '' AND LOWER(TRIM({$p}country)) NOT IN ('test coun', 'test country', 'test'))";
}

/**
 * Fetch distinct countries for filter dropdowns
 */
function get_unique_countries($pdo) {
    $stmt = $pdo->query("SELECT DISTINCT country FROM coins WHERE " . public_coins_filter() . " ORDER BY country ASC");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Fetch distinct currencies for filter dropdowns
 */
function get_unique_currencies($pdo) {
    $stmt = $pdo->query("SELECT DISTINCT currency_name FROM coins WHERE currency_name IS NOT NULL AND currency_name != '' AND " . public_coins_filter() . " ORDER BY currency_name ASC");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Fetch distinct condition grades for filter dropdowns
 */
function get_unique_grades($pdo) {
    $stmt = $pdo->query("SELECT DISTINCT condition_grade FROM coins WHERE condition_grade IS NOT NULL AND condition_grade != '' AND " . public_coins_filter() . " ORDER BY condition_grade ASC");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Calculate dynamic earliest-latest year range from collection database
 */
function get_collection_year_range($pdo) {
    $stmt = $pdo->query("SELECT year FROM coins WHERE year IS NOT NULL AND year != '' AND year != 'year' AND " . public_coins_filter());
    $years = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($years)) return 'N/A';

    $parsed = [];
    foreach ($years as $yStr) {
        $clean = trim($yStr);
        $isBC = (bool)preg_match('/BC|BCE/i', $clean);
        if (preg_match('/(\d+)/', $clean, $matches)) {
            $num = (int)$matches[1];
            $parsed[] = [
                'val'     => $isBC ? -$num : $num,
                'display' => $clean,
            ];
        }
    }

    if (empty($parsed)) return 'N/A';

    usort($parsed, function ($a, $b) {
        return $a['val'] <=> $b['val'];
    });

    $earliest = $parsed[0]['display'];
    $latest   = $parsed[count($parsed) - 1]['display'];

    return $earliest === $latest ? $earliest : $earliest . ' – ' . $latest;
}

/**
 * Fetch country coin breakdown for interactive world map & fallback list
 */
function get_country_coin_counts($pdo) {
    $stmt = $pdo->query(
        "SELECT country, SUM(quantity) as count FROM coins
         WHERE " . public_coins_filter() . "
         GROUP BY country ORDER BY count DESC, country ASC"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $mapData = [];
    foreach ($rows as $r) {
        $mapData[] = [
            'country' => $r['country'],
            'count'   => (int)$r['count'],
            'iso'     => get_country_iso_code($r['country']),
        ];
    }
    return $mapData;
}

/**
 * Other coins worth linking to from a detail page: same country first, then the
 * rest of the collection. Internal links like these are how crawlers reach
 * deeper pages and how related entries get associated with each other.
 */
function get_related_coins($pdo, array $coin, $limit = 6) {
    $stmt = $pdo->prepare(
        "SELECT * FROM coins
         WHERE id != :id AND " . public_coins_filter() . "
         ORDER BY (country = :country) DESC, (currency_name = :currency) DESC, is_featured DESC, id DESC
         LIMIT :limit"
    );
    $stmt->bindValue(':id', (int)$coin['id'], PDO::PARAM_INT);
    $stmt->bindValue(':country', $coin['country']);
    $stmt->bindValue(':currency', $coin['currency_name']);
    $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}
