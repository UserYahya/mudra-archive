# Mudra Archive

A self-hosted catalogue for a personal coin collection. It publishes each coin as its own search-optimised page — both faces photographed, every specification recorded — and gives the collector a private admin area to manage the collection.

Built with plain PHP and SQLite so it runs on ordinary cPanel shared hosting: no Node.js, no build step, no database server to provision.

Live instance: <https://mudra.yahya.bd>

---

## Contents

- [What it does](#what-it-does)
- [Requirements](#requirements)
- [Quick start (local)](#quick-start-local)
- [Deploying](#deploying)
- [Configuration](#configuration)
- [First run and admin access](#first-run-and-admin-access)
- [Security](#security)
- [SEO](#seo)
- [Project layout](#project-layout)
- [Notes for contributors](#notes-for-contributors)

---

## What it does

**Public side**

- Gallery of the collection with 3D flip cards showing obverse and reverse
- Search and filtering by country, currency, grade, year range and featured status
- An interactive world map (Leaflet + OpenStreetMap) pinning every country represented
- Live statistics: total coins, countries, currencies, era span
- A detail page per coin with a full specification table and related entries
- Dark mode, responsive down to phone width

**Admin side** (`/admin/`)

- Sign in, add, edit and delete coins
- Client-side image compression and cropping before upload
- Optional AI autofill: reads a coin photograph with Google Gemini and pre-fills the form. The API key is supplied by the user and stored only in their own browser's `localStorage` — it is never sent to the server or stored in this repository.
- Change your own password

---

## Requirements

| | |
|---|---|
| PHP | 7.4+ or 8.x, with `pdo_sqlite` and `gd` |
| Web server | Apache with `mod_rewrite`. `mod_headers`, `mod_deflate` and `mod_expires` are used when available but not required |
| Database | SQLite 3 — the file is created automatically on first request |

No Composer dependencies. Bootstrap, Leaflet and Google Fonts load from public CDNs.

---

## Quick start (local)

```bash
git clone https://github.com/USER/REPO.git mudra
cd mudra
php -S localhost:8000 router.php
```

Then open <http://localhost:8000>.

`router.php` reproduces the `.htaccess` rewrites (clean coin URLs, `/sitemap.xml`, blocked directories), which the PHP built-in server cannot read on its own. Apache ignores it entirely. On Windows, `start_local_server.bat` does the same thing.

The database and a first admin account are created on the first request. See [First run and admin access](#first-run-and-admin-access).

---

## Deploying

See **[DEPLOYMENT.md](DEPLOYMENT.md)** for the full cPanel walkthrough, including how to update an existing live site without touching its data.

The short version:

1. Upload everything **except** `_private/`, `router.php` and `start_local_server.bat`, keeping hidden `.htaccess` files.
2. Make `database/` and `assets/uploads/` writable by the web server.
3. Confirm `pdo_sqlite` and `gd` are enabled.
4. Open the site once — the schema is created automatically.

---

## Configuration

Everything site-specific lives in [`includes/config.php`](includes/config.php). If you are running your own instance, this is the only file you need to edit:

```php
define('SITE_NAME',        'Mudra Archive');
define('SITE_TAGLINE',     'World Coin Collection & Numismatic Catalogue');
define('SITE_AUTHOR',      'Muhammad Yahya');
define('SITE_CONTACT',     'hello@yahya.bd');
define('CANONICAL_ORIGIN', 'https://mudra.yahya.bd');
```

`trusted_hosts()` in the same file is the allow-list used when building absolute URLs. Add your own domain there; any host not on the list falls back to `CANONICAL_ORIGIN`, which is what prevents a forged `Host` header from being reflected into a canonical tag or a redirect.

Other tunables in that file:

| Constant | Default | Purpose |
|---|---|---|
| `PRETTY_URLS` | `true` | Clean coin URLs. Set `false` if your host has no `mod_rewrite` |
| `COINS_PER_PAGE` | `60` | Gallery page size |
| `LOGIN_MAX_ATTEMPTS` | `5` | Failed sign-ins before lockout |
| `LOGIN_LOCKOUT_SECS` | `900` | Lockout duration |
| `ADMIN_IDLE_TIMEOUT` | `7200` | Auto sign-out after inactivity |

---

## First run and admin access

On the first request against an empty database, the app creates the schema and one admin account named `Yahya` with a **randomly generated password**, written once to:

```
database/INITIAL_ADMIN_PASSWORD.txt
```

That directory is denied over HTTP, so the file is only readable over FTP/SSH or in cPanel File Manager.

1. Read the password from that file.
2. Sign in at `/admin/login.php`.
3. Change it under the account menu → **Change Password**.
4. The credential file is deleted automatically when you do.

**No password is stored anywhere in this repository.** If you are adapting this project, change the default username in `includes/db.php` as well.

---

## Security

| Area | Measure |
|---|---|
| SQL | PDO prepared statements throughout |
| Passwords | `password_hash()` / `password_verify()`, transparent rehashing on sign-in, 12-character minimum |
| Sessions | HttpOnly, SameSite=Lax, `Secure` whenever the request is HTTPS (including behind a Cloudflare or cPanel proxy), strict mode, ID regenerated on login, idle timeout, bound to the browser's user agent |
| CSRF | A token is required on every state-changing POST: login, logout, add, edit, delete, password change |
| Brute force | Failed sign-ins are recorded per IP and trigger a timed lockout. The error message never reveals whether a username exists |
| Uploads | Extension, MIME and `getimagesize()` validation; re-encoded through GD; randomised filenames; `assets/uploads/.htaccess` disables script execution in that directory |
| Deletion | POST-only with a CSRF token |
| Headers | CSP, HSTS, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy` — sent from PHP as well as `.htaccess`, so they apply even without `mod_headers` |
| Privacy | Acquisition price, source and date are visible only to the signed-in curator, never to visitors or crawlers |
| Data at rest | `database/`, `includes/` and `_private/` are denied over HTTP; the admin area is `noindex` |
| Error handling | Database errors are logged, never printed — the driver message leaks absolute filesystem paths |

**Reporting a problem:** please open a private security advisory on this repository rather than a public issue.

---

## SEO

- **Clean URLs** — `/coin/12-morgan-dollar-united-states-1921`. The legacy `coin.php?id=12` form still works and issues a 301.
- **One canonical homepage** — `/index.php` redirects to `/`; filtered and searched views are `noindex, follow` so they do not compete with it; paginated pages self-canonicalise and chain with `rel=prev`/`rel=next`.
- **Structured data** — `WebSite` (with `SearchAction`), `Person`, `CollectionPage` + `ItemList` and `FAQPage` on the homepage; `ItemPage`, `CreativeWork` (every specification as a `PropertyValue`) and `BreadcrumbList` on coin pages. All emitted through `json_encode`, so apostrophes and non-ASCII text stay valid.
- **Real 404s** for missing coins, rather than a redirect to the homepage.
- **Answer-engine friendly** — each coin page opens with a self-contained factual sentence and presents its specifications as a real `<table>`; the homepage answers common questions in visible text backed by matching `FAQPage` markup.
- **Sitemap** at `/sitemap.xml`, with the Google image extension so both faces of every coin are eligible for Google Images.
- **Performance** — Leaflet loads only when the map nears the viewport, the LCP image is preloaded with `fetchpriority="high"`, scripts are deferred, and `.htaccess` enables compression and long-lived asset caching.
- `robots.txt` names the major search and AI answer crawlers explicitly. Note that a named group *replaces* the `*` group for that crawler rather than adding to it, so every named group repeats the same `Disallow` rules.

---

## Project layout

```
.
├── index.php               Gallery, map, statistics, about, FAQ
├── coin.php                Coin detail page
├── 404.php                 Not Found page (real 404 status)
├── sitemap.php             XML sitemap, served at /sitemap.xml
├── router.php              Local dev only — emulates the .htaccess rewrites
├── .htaccess               Rewrites, caching, compression, security headers
├── robots.txt
├── site.webmanifest
├── includes/
│   ├── config.php          Site identity, canonical host, tunables
│   ├── security.php        Sessions, CSRF, headers, login throttling
│   ├── db.php              Connection, schema, indexes, first-run seeding
│   ├── functions.php       URL, SEO text, image and query helpers
│   ├── countries.php       ISO codes and map coordinates
│   └── .htaccess           Deny direct HTTP access
├── admin/
│   ├── login.php  logout.php  dashboard.php  account.php
│   └── add-coin.php  edit-coin.php  delete-coin.php
├── assets/
│   ├── css/style.css
│   ├── js/compressor.js         Client-side compression before upload
│   ├── js/gemini-autofill.js    Optional AI autofill (key stays in the browser)
│   └── uploads/.htaccess        No script execution in the upload directory
└── database/.htaccess           Deny direct HTTP access
```

Not in this repository, by design: the SQLite database, uploaded coin photographs, and any credential file. See [`.gitignore`](.gitignore).

---

## Notes for contributors

- **Never commit `database/*.db`.** It contains the collection *and* the admin password hash. `.gitignore` covers it; please do not override it.
- Public-facing counts must go through `public_coins_filter()` so the gallery, statistics and map cannot disagree.
- Acquisition price, source and date are private. Anything rendering them must be behind `is_admin_logged_in()`.
- Site-wide strings belong in `includes/config.php`, not inline.
- Every state-changing form needs `csrf_field()`, and its handler needs `csrf_require()`.
- The codebase targets PHP 7.4, so no `str_contains`, `match` expressions, nullsafe operators or named arguments.

## Licence

No licence has been chosen yet. Until one is added, all rights are reserved by the author.
