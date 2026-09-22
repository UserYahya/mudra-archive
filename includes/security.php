<?php
// includes/security.php - Session hardening, CSRF protection, response headers
// and admin login throttling for Mudra Archive.

require_once __DIR__ . '/config.php';

/**
 * True when the request reached us over HTTPS, including when TLS was
 * terminated upstream by Cloudflare or a cPanel proxy.
 */
function request_is_https() {
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }
    if (isset($_SERVER['HTTP_CF_VISITOR']) && strpos($_SERVER['HTTP_CF_VISITOR'], 'https') !== false) {
        return true;
    }
    return (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

/**
 * Start the session with hardened cookie settings.
 * Called once, from functions.php, before any output.
 */
function start_secure_session() {
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        // Previously this only checked $_SERVER['HTTPS'], so behind Cloudflare the
        // session cookie was never flagged Secure on the live site.
        'secure'   => request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_name('mudra_session');
    session_start();
}

/**
 * Baseline response headers, sent from PHP so they apply even on hosts where
 * mod_headers is unavailable and the .htaccess block is skipped.
 */
function send_security_headers($isAdminArea = false) {
    if (headers_sent()) {
        return;
    }

    // Do not advertise the exact PHP version to anyone scanning for known bugs.
    header_remove('X-Powered-By');

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=(), interest-cohort=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('X-Permitted-Cross-Domain-Policies: none');

    if (request_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }

    // Content Security Policy sized to what the pages actually load: Bootstrap and
    // Leaflet from their CDNs, Google Fonts, OpenStreetMap tiles, and — on the
    // admin screens only — the Gemini vision endpoint used by the autofill helper.
    $script  = "'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com";
    $style   = "'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com https://fonts.googleapis.com";
    $font    = "'self' https://fonts.gstatic.com data:";
    $img     = "'self' data: blob: https://*.tile.openstreetmap.org https://unpkg.com";
    $connect = $isAdminArea
        ? "'self' https://generativelanguage.googleapis.com"
        : "'self'";

    header(
        "Content-Security-Policy: default-src 'self'; " .
        "script-src $script; style-src $style; font-src $font; img-src $img; " .
        "connect-src $connect; frame-ancestors 'self'; base-uri 'self'; " .
        "form-action 'self'; object-src 'none'"
    );

    if ($isAdminArea) {
        // Keep the curator portal out of every search index and cache.
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Pragma: no-cache');
    }
}

// --- CSRF -----------------------------------------------------------------

/**
 * Per-session CSRF token, created on first use.
 */
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Hidden input to drop inside every state-changing form.
 */
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '"/>';
}

/**
 * Validate the token submitted with a POST request.
 */
function csrf_verify($token = null) {
    $token = $token ?? ($_POST['csrf_token'] ?? '');
    return !empty($_SESSION['csrf_token'])
        && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Guard for POST handlers: stop the request unless the CSRF token checks out.
 */
function csrf_require() {
    if (!csrf_verify()) {
        http_response_code(419);
        header('Content-Type: text/html; charset=utf-8');
        exit('<!doctype html><meta charset="utf-8"><title>Session expired</title>'
            . '<p style="font:16px system-ui;padding:2rem">Your session expired or the request could not be verified. '
            . '<a href="' . htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8') . '">Reload the page</a> and try again.</p>');
    }
}

// --- Login throttling -----------------------------------------------------

/**
 * Client IP, preferring Cloudflare's header when present.
 */
function client_ip() {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return substr($_SERVER['HTTP_CF_CONNECTING_IP'], 0, 45);
    }
    return substr($_SERVER['REMOTE_ADDR'] ?? 'unknown', 0, 45);
}

/**
 * Seconds remaining on a lockout for this IP, or 0 when sign-in is allowed.
 */
function login_lockout_remaining(PDO $pdo) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS failures, MAX(attempted_at) AS last_try
         FROM login_attempts
         WHERE ip = :ip AND attempted_at > :since"
    );
    $stmt->execute([
        ':ip'    => client_ip(),
        ':since' => time() - LOGIN_LOCKOUT_SECS,
    ]);
    $row = $stmt->fetch();

    if (!$row || (int)$row['failures'] < LOGIN_MAX_ATTEMPTS) {
        return 0;
    }
    return max(0, LOGIN_LOCKOUT_SECS - (time() - (int)$row['last_try']));
}

function login_record_failure(PDO $pdo) {
    $stmt = $pdo->prepare("INSERT INTO login_attempts (ip, attempted_at) VALUES (:ip, :at)");
    $stmt->execute([':ip' => client_ip(), ':at' => time()]);

    // Opportunistic cleanup so the table cannot grow without bound.
    $pdo->prepare("DELETE FROM login_attempts WHERE attempted_at < :cutoff")
        ->execute([':cutoff' => time() - (LOGIN_LOCKOUT_SECS * 8)]);
}

function login_clear_failures(PDO $pdo) {
    $pdo->prepare("DELETE FROM login_attempts WHERE ip = :ip")->execute([':ip' => client_ip()]);
}
