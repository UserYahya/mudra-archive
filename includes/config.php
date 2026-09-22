<?php
// includes/config.php - Mudra Archive central configuration.
// Every hard-coded site string lives here so the rest of the codebase stays consistent.

// --- Identity -------------------------------------------------------------
define('SITE_NAME',        'Mudra Archive');
define('SITE_TAGLINE',     'World Coin Collection & Numismatic Catalogue');
define('SITE_AUTHOR',      'Muhammad Yahya');
define('SITE_CONTACT',     'hello@yahya.bd');
define('SITE_LOCALE',      'en_US');

// --- Canonical host -------------------------------------------------------
// The one host search engines should index. Every other host that reaches the
// app still renders, but canonical/OG/sitemap URLs always point here.
define('CANONICAL_ORIGIN', 'https://mudra.yahya.bd');

// Hosts the app trusts when building absolute URLs from $_SERVER['HTTP_HOST'].
// Anything not listed falls back to CANONICAL_ORIGIN, which blocks host-header
// injection into canonical tags, Open Graph tags and redirects.
function trusted_hosts() {
    return [
        'mudra.yahya.bd',
        'www.mudra.yahya.bd',
        'localhost',
        'localhost:8000',
        '127.0.0.1',
        '127.0.0.1:8000',
    ];
}

// --- Behaviour ------------------------------------------------------------
// Clean coin URLs (/coin/12-morgan-dollar-united-states-1921) need mod_rewrite.
// Set to false if the host has no mod_rewrite; links fall back to coin.php?id=N.
define('PRETTY_URLS', true);

define('COINS_PER_PAGE', 60);

// Admin login throttling.
define('LOGIN_MAX_ATTEMPTS',  5);
define('LOGIN_LOCKOUT_SECS',  900);   // 15 minutes after MAX_ATTEMPTS failures
define('ADMIN_IDLE_TIMEOUT',  7200);  // sign out after 2 hours of inactivity

// Show PHP errors on screen only when developing locally.
define('IS_LOCAL_DEV', in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', 'localhost:8000', '127.0.0.1', '127.0.0.1:8000'], true));
