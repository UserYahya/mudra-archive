# Deployment Guide

How to install Mudra Archive on cPanel shared hosting, and how to update an existing live site without losing its data.

- [A. Updating an existing live site](#a-updating-an-existing-live-site)
- [B. Fresh installation](#b-fresh-installation)
- [C. Post-deployment checklist](#c-post-deployment-checklist)
- [D. Backups](#d-backups)
- [E. Troubleshooting](#e-troubleshooting)

---

## A. Updating an existing live site

**Your collection lives in `database/coins.db` on the server. Nothing in an update touches it.**

`includes/db.php` only ever issues `CREATE TABLE IF NOT EXISTS` and `CREATE INDEX IF NOT EXISTS`. There are no `ALTER TABLE`, `DROP` or migration statements anywhere in the codebase. The seeding routines are each guarded by a count:

- Sample coins are inserted **only** when the `coins` table has zero rows.
- The first admin account is created **only** when the `admin_users` table has zero rows.

An existing site has rows in both, so both are skipped. Your coins, your photographs and your current admin password are all left exactly as they are.

What an update **adds** to the database:

| Added | Why |
|---|---|
| `login_attempts` table | Records failed sign-ins so the login page can rate-limit |
| Indexes `idx_coins_country`, `idx_coins_currency`, `idx_coins_grade`, `idx_coins_featured`, `idx_login_ip` | Query performance |

Both are additive and reversible. No existing table, column or row is modified.

### Steps

1. **Back up first**, even though the update is non-destructive:
   - Download `database/coins.db`
   - Download the whole `assets/uploads/` folder
   - Store both **outside** the web root

2. **Upload the new files**, overwriting the old ones:

   ```
   404.php           index.php         robots.txt        sitemap.php
   coin.php          site.webmanifest  .htaccess
   admin/            includes/         assets/css/       assets/js/
   assets/logo.png   assets/favicon.ico   assets/favicon-32x32.png
   assets/apple-touch-icon.png
   assets/uploads/.htaccess    database/.htaccess
   ```

   > **Enable "Show Hidden Files" in cPanel File Manager** (Settings, top right), or the `.htaccess` files will be skipped silently. They carry the URL rewrites and much of the security hardening.

3. **Do not upload** — these are local-only or would overwrite live data:

   | Skip | Reason |
   |---|---|
   | `database/coins.db` | Would overwrite your live collection |
   | `assets/uploads/*.jpg` etc. | Would overwrite your coin photographs |
   | `router.php` | Local development server only; Apache uses `.htaccess` |
   | `start_local_server.bat` | Local development only |
   | `_private/` | Local quarantine folder — must never be web-accessible |
   | `README.md`, `DEPLOYMENT.md`, `.gitignore` | Documentation and tooling |

4. **Delete these from the server if they are still there.** They were publicly downloadable and are a live security exposure:

   | Delete from server | Why |
   |---|---|
   | `admin - Copy/` | A stale duplicate admin panel with no CSRF protection |
   | `mudra.zip` | A full source archive, downloadable by anyone |
   | `scratch/` | Debug scripts that printed database rows in the browser |
   | `database/coins_backup.db` | Keep backups off the web server entirely |
   | `assets/worldmap.svg` | Unused leftover |

5. **Check folder permissions.** `database/` and `assets/uploads/` must be writable by the web server user — `755`, or `775`/`777` if your host runs PHP as a different user. The `login_attempts` table is created on the first request after the update, which needs write access to `database/`.

6. **Change the admin password.** If you have never changed it from the original setup value, treat it as compromised: it was published in the old `README.md` and inside `mudra.zip`, both of which were downloadable from the live site. Sign in, open the account menu, choose **Change Password**.

7. **Load the site once** to trigger the schema additions, then confirm:
   - The homepage shows your correct coin and country counts
   - A coin page opens at its new clean URL
   - An old `coin.php?id=N` link redirects to that clean URL
   - `/sitemap.xml` loads
   - You can sign in, edit a coin and save

### Rolling back

Restore the previous PHP files from your backup. The database needs no rollback — the added table and indexes are inert if the old code runs against them, because the old code simply never references them.

---

## B. Fresh installation

### 1. Create the subdomain or domain

In cPanel, go to **Domains → Subdomains** (or **Addon Domains**), create the hostname, and note the document root it reports, for example `public_html/mudra`.

### 2. Upload the files

Upload everything from this repository **except** `_private/`, `router.php`, `start_local_server.bat`, `README.md`, `DEPLOYMENT.md` and `.gitignore`, preserving the folder structure and the hidden `.htaccess` files.

### 3. Set permissions

| Folder | Permission |
|---|---|
| `database/` | `755` (or `775`/`777` if your host requires it) |
| `assets/uploads/` | `755` (or `775`/`777`) |

### 4. Enable the PHP extensions

**Select PHP Version** → make sure `pdo_sqlite` and `gd` are ticked. PHP 7.4 or newer.

### 5. Point the app at your domain

Edit `includes/config.php`:

```php
define('CANONICAL_ORIGIN', 'https://your-domain.example');
```

and add your hostname to `trusted_hosts()` in the same file.

### 6. First load

Open the site. The database is created and seeded, and the first admin credentials are written to `database/INITIAL_ADMIN_PASSWORD.txt`.

Read that file over FTP/SSH or in File Manager — it is not reachable over HTTP — then sign in at `/admin/login.php` and change the password. The file is deleted automatically once you do.

---

## C. Post-deployment checklist

**Working**

- [ ] Homepage loads, statistics look right
- [ ] Coin pages open at `/coin/<id>-<slug>`
- [ ] Old `coin.php?id=N` links return a 301 to the clean URL
- [ ] A non-existent coin returns a real 404, not a redirect
- [ ] `/sitemap.xml` and `/robots.txt` load
- [ ] The world map renders (it loads as you scroll to it)
- [ ] Sign in, add, edit and delete all work

**Secure**

- [ ] `https://your-domain/database/coins.db` returns 403
- [ ] `https://your-domain/includes/db.php` returns 403
- [ ] `https://your-domain/_private/` returns 403 or 404
- [ ] Acquisition price and source are **not** visible when signed out
- [ ] The admin password has been changed
- [ ] `database/INITIAL_ADMIN_PASSWORD.txt` is gone

**Search engines**

- [ ] Submit `https://your-domain/sitemap.xml` to [Google Search Console](https://search.google.com/search-console) and [Bing Webmaster Tools](https://www.bing.com/webmasters)
- [ ] Test a coin page in the [Rich Results Test](https://search.google.com/test/rich-results)
- [ ] Run the homepage through [PageSpeed Insights](https://pagespeed.web.dev/)

Expect the 301s from the old URLs to take a few weeks to settle in the index. Rankings do not move overnight.

---

## D. Backups

The whole site is two things: **`database/coins.db`** and **`assets/uploads/`**. Everything else is code you can re-deploy from this repository.

Download both regularly and keep them off the web server. If you store a backup on the server anyway, keep it outside the document root — a `.db` file inside it is one guessed URL away from being downloaded, which is why `database/.htaccess` exists.

---

## E. Troubleshooting

**Coin links 404 after deploying**
`mod_rewrite` is probably unavailable. Set `PRETTY_URLS` to `false` in `includes/config.php`; links fall back to `coin.php?id=N` and everything keeps working.

**500 Internal Server Error immediately after upload**
Almost always an `.htaccess` directive your host disallows. Rename `.htaccess` to `.htaccess.off`, reload, then reintroduce it section by section. `php_flag` lines are the usual culprit on hosts running PHP-FPM.

**"The archive is temporarily unavailable"**
The database could not be opened — nearly always permissions on `database/`. Make it writable by the web server user. The underlying error is in your cPanel error log; it is deliberately not shown in the browser because it contains server paths.

**Uploaded images do not appear**
`assets/uploads/` is not writable, or `gd` is not enabled.

**Icons show as words like `dashboard` or `delete`**
The Material Symbols font did not load. Check that the page is not blocked from reaching `fonts.googleapis.com`.

**Signed out constantly**
`ADMIN_IDLE_TIMEOUT` in `includes/config.php` is two hours by default. The session is also bound to your browser's user-agent string, so switching browsers ends the session.

**Locked out of the admin login**
Five failed attempts locks your IP for fifteen minutes. Wait it out, or clear the `login_attempts` table in `database/coins.db` with any SQLite tool:

```sql
DELETE FROM login_attempts;
```
