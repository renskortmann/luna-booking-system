# CLAUDE.md

Context for Claude Code sessions on this repository. README.md is the full
manual (architecture, deployment runbook, operations); read it before changing
behaviour. This file records what README.md does not: the live deployment state
and how deployment has been done in practice.

**This file is committed to a public GitHub repo.** Keep account names, IPs,
database names/users, filesystem paths, passwords, keys and tokens out of it.
Put machine-specific private details in `CLAUDE.local.md` (gitignored) or the
user's password manager.

## Project in one paragraph

PHP 8.2+ / MySQL app for the TU Delft Macrolab with two independent systems
behind one sign-in and a hub at `/`: instrument booking at `/booking`, and time
registration at `/time`, where lab technicians log hours per activity
(maintenance, teaching support, tidying the lab, ...) so lab management can see
how their time is spent. The activities are called "projects" in the code. No
framework; PSR-4 `Macrolab\` -> `app/src/`, with `Macrolab\Booking` and
`Macrolab\Time` in their own folders, which never use each other; routes in
`app/routes.php`, plain PHP views in `app/views/`, migrations in
`app/migrations/`. Only
`public_html/index.php` is web-reachable. TU Delft SSO ("stage 2") is NOT
built: routes, columns, setting and config are scaffolding, and
`Settings::authMode()` stays `local` until `Macrolab\Auth\SamlProvider` exists. Composer deps must be installed
without a shell on the server (see "Getting vendor/ onto the server").

- Tests: `composer install && vendor/bin/phpunit` (integration suite is skipped
  unless `MACROLAB_TEST_DB_NAME` is set, see `tests/test-config.php`).
- Local run: see README.md "Local development" (`php -S localhost:8000 -t public_html`).
- `app/config.php` is gitignored and machine-specific. Never commit it or any
  generated key/token.

## Hosting

| | |
|---|---|
| Domain | `macrolab.citg.tudelft.nl` |
| Server | TU Delft shared LAMP hosting (Plesk) |
| Panel | Plesk, reachable only from campus network or eduVPN |
| PHP | 8.2.34, run as "FPM application served by Apache" (so `.htaccess` works) |
| Document root | `public_html` (changed from Plesk's default `httpdocs` in Hosting Settings) |
| Access | Plesk web UI (File Manager, Databases, SSL, Scheduled Tasks, Git). FTP(S). Plesk has an "SSH access" link, but the README assumes no shell; unverified. No outbound mail |

## Deployment strategy: Plesk Git, no zip

Decision (2026-10-02): deploy from GitHub with Plesk's Git extension, because
many small changes are expected. The zip bundle approach was abandoned and the
earlier `~/macrolab-deploy.zip` must not be used (it has the old `httpdocs/`
layout and its install token was shown in a chat, so treat it as burnt).

Layout on the server, with the repo deployed to the subscription root `/`:

```
/ (subscription root)
    public_html/   <- document root: index.php, .htaccess, assets/ (from the repo)
    app/           <- from the repo; beside the document root, not web-reachable
    vendor/        <- NOT in git; see below
    app/config.php <- NOT in git; created once on the server
    tests/ docs/ composer.json ...  (harmless, outside the document root)
```

`public_html/index.php` detects `app/` one level above itself, so nothing
needs configuring. The old `httpdocs/` folder is unused and can be deleted
once everything works.

Plesk -> Git -> Create repository settings:
- **Remote repository**, URL = the GitHub repo. If it is private, Plesk shows an
  SSH public key after creation: add it as a read-only **deploy key** in GitHub
  and use the SSH URL.
- Repository name: anything unique (Plesk suggests `macrolab.git`).
- **Deployment mode: Manual.** Automatic needs GitHub to call a webhook on the
  Plesk server, which is campus-only and unreachable from GitHub. Click "Pull
  updates" in Plesk (on campus/eduVPN) to deploy.
- **Deployment directory: `/`** (not `/httpdocs`).
- Leave "post deployment actions" off: the README says the hosting does not
  allow shell commands.

The repo was renamed from `luna-booking-system` to `macrolab-website` (planned
2026-10-02, check `git remote -v`); GitHub redirects the old name but never
create a new repo with the old name. Enter the final URL in Plesk.

### Getting vendor/ onto the server: Plesk PHP Composer (works, 2026-10-02)

`vendor/` is gitignored and there is no shell, so Plesk's PHP Composer
extension builds it on the server (domain dashboard -> PHP Composer). Verified
on the first deployment:
- It found `composer.json` in the subscription root ("Folder: /").
- **Mode: Production** installs without dev dependencies (no phpunit).
- **Install** (not Update) used the versions from `composer.lock` and created
  `vendor/` beside `app/`, with `autoload.php` and only the production packages.

After a pull that changes `composer.lock`, run Install again. Never click
Update on the server: it ignores `composer.lock`. Change dependencies locally
with `composer update`, commit the lock file, then pull and Install.

Fallback if the extension ever stops working: a `deploy` branch containing
`vendor/` (`composer install --no-dev --optimize-autoloader`, `git add -f
vendor`, deploy that branch).

### app/config.php (created once on the server)

Not in git, and deployment does not delete untracked files (assumption: verify
after the first pull that `config.php` survived). Create it in Plesk File
Manager by copying `app/config.example.php` to `app/config.php`, then set:
- `app.base_url` = `https://macrolab.citg.tudelft.nl`
- `app.key` and `app.install_token`: run `php app/cli/generate-key.php` locally
  twice and paste the outputs. Never reuse values that appeared in chat.
- `db`: host `localhost`, port `3306`; database name, user and password are in
  the user's password manager (see "Database" below).
- permissions 600.

### PHP environment (verified from phpinfo, 2026-10-02)

phpinfo from Plesk (`docs/PHP 8.2.34 - phpinfo().pdf`, gitignored) was taken
first while PHP ran as "FPM served by nginx", then again after switching to
"FPM application served by Apache". The second shows `SERVER_SOFTWARE =
Apache` (nginx proxies in front), the same PHP version, ini files and
`open_basedir`, and `HTTPS = on`, so `.htaccess` should now be honoured.
That PDF still shows `DOCUMENT_ROOT = .../httpdocs`, so it predates the
document-root change. Its `REMOTE_ADDR`, `X-Real-IP` and `SERVER_ADDR` are all
the server itself, because Plesk's "PHP info" link fetches the page
server-side. So it says nothing about what real visitors' addresses look like
(see go-live step 7):

- PHP 8.2.34, FPM, memory_limit 256M, upload/post 16M, max_execution_time 60.
- All required extensions are loaded: `pdo_mysql`, `mbstring`, `openssl`
  (1.1.1k), `dom`/`xml`/`libxml`, `json`, and `sodium` (libsodium 1.0.18, so
  Argon2id and sodium-based encryption are available). `zip` is also loaded.
- `open_basedir = <subscription folder>/:/tmp/` and `HOME` is that same
  folder, so `{WEBSPACEROOT}` is the subscription folder and PHP can read `app/` and `vendor/` beside
  `public_html/`. If `/install` ever reports an `open_basedir` problem, the
  fallback is the restricted layout in README.md "Fallbacks".
- Default timezone UTC (the app sets its own).
- Sessions: `session.save_path = /var/lib/php/session`, `session.gc_maxlifetime
  = 1440`, `gc_probability = 0`. Plesk's PHP Settings page (checked
  2026-10-03) offers no field for `gc_maxlifetime` and no "additional
  directives" box, so the subscription cannot raise it. Decision (2026-10-03):
  accept it. The idle limits (`auth.*_session_idle_minutes`) are 24 for members
  and the admin, so the app's rule and the host's cleanup agree. The server's
  `app/config.php` was created with the old 480/30 values: change them to 24.

### Database (created 2026-10-02)

MariaDB 10.11 at `localhost:3306`, one database plus one user scoped to it,
linked to the site `macrolab.citg.tudelft.nl` in Plesk. Plesk added no name
prefix. Name, user (it contains a hyphen; quote it in config.php) and password
are in the user's password manager. The database is empty until `/install`
runs.

## Deployment status (as of 2026-10-02, end of day)

**Blocked on DNS.** `macrolab.citg.tudelft.nl` returned NXDOMAIN from
`ns1.tudelft.nl`, so Plesk's Let's Encrypt issuance failed
(`urn:ietf:params:acme:error:dns`). The tudelft.nl zone is managed by ICT, not
Plesk. ICT was asked to create the record (an A record to the shared hosting
server, or a CNAME to it) and replied that registration "can take a few working days".
Check with `curl -s "https://dns.google/resolve?name=macrolab.citg.tudelft.nl&type=A"`
(`Status` 0 with an `Answer` means it resolves; 3 is NXDOMAIN) or by opening the
site.

Done: database created; PHP set to Apache mode; phpinfo verified; document root
changed to `public_html` (confirmed 2026-10-03 on the PHP Settings page).

To do, in order:
1. ~~Rename the GitHub repo to `macrolab-website`~~ - done; local remote updated.
2. ~~Plesk -> Git~~ - done: repository `macrolab-website.git`, branch `main`,
   deployed to `/`; `app/`, `public_html/`, `composer.json` landed in the
   subscription root. Confirm the deployment mode is Manual.
3. ~~Get `vendor/` onto the server~~ - done with Plesk PHP Composer.
4. ~~Create `app/config.php`~~ - done 2026-10-02 (permissions 600).
5. **After DNS resolves:** Plesk -> SSL/TLS Certificates -> Let's Encrypt, and
   **untick the www option**: `www.macrolab.citg.tudelft.nl` is not in DNS and
   would fail the same way.
6. **After HTTPS works:** open
   `https://macrolab.citg.tudelft.nl/install?token=<install_token, URL-encoded>`
   (base64 tokens may contain `/`, `+`, `=`). QR code and recovery codes are
   shown once only. Then blank `install_token` in `app/config.php`.
7. Verify `/login` works and `https://macrolab.citg.tudelft.nl/app/config.php`
   is NOT served (README step 7 has nginx fallback directives). `/install`'s
   "Application directory" check should say "outside the document root",
   which confirms the `public_html` document root was saved. After the first
   real sign-in, check Administration -> Audit log: the address must be your
   own, not the server's. If every entry shows the server's address (nginx
   proxying), set `app.trusted_proxy_header` to `'X-Real-IP'` in
   `app/config.php`, or the per-IP login throttle treats all visitors as one.
8. Scheduled Task: daily PHP script `app/cli/prune.php`. Set up Backup Manager.
9. Delete the unused `httpdocs/` folder.

Steps 1-4 can be done before DNS is ready. Steps 5-6 must not: the app forces
HTTPS and `/install` sends the token and admin password, so do not install via
a preview URL or hosts-file override.

## Updates after go-live

Push to GitHub, then Plesk -> Git -> Pull now, then Deploy now. If
`composer.lock` changed, or classes under `app/src/` were added or moved,
run Install in PHP Composer (the latter only refreshes the optimised class
map; PSR-4 still finds unmapped classes). If a release adds a
migration, apply it from Administration -> System -> Apply migrations. The
server's `app/config.php` is never overwritten by a pull; do not delete it.
