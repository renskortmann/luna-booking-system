# CLAUDE.md

Context for Claude Code sessions on this repository. README.md is the full
manual (architecture, deployment runbook, operations); read it before changing
behaviour. This file records what README.md does not: the live deployment state
and how deployment has been done in practice.

## Project in one paragraph

PHP 8.2+ / MySQL app for the TU Delft Macrolab: instrument booking at
`/booking` and time registration at `/time`, behind one sign-in, with a hub at
`/`. No framework; PSR-4 `Macrolab\` -> `app/src/`, routes in `app/routes.php`,
plain PHP views in `app/views/`, migrations in `app/migrations/`. Only
`public_html/index.php` is web-reachable. Composer deps are vendored at deploy
time because the server has no shell.

- Tests: `composer install && vendor/bin/phpunit` (integration suite is skipped
  unless `MACROLAB_TEST_DB_NAME` is set, see `tests/test-config.php`).
- Local run: see README.md "Local development" (`php -S localhost:8000 -t public_html`).
- `app/config.php` is gitignored and machine-specific. Never commit it or any
  generated key/token.

## Hosting

| | |
|---|---|
| Domain | `macrolab.citg.tudelft.nl` |
| Server | TU Delft shared LAMP hosting, `lamp8.tudelft.nl`, IP `131.180.77.135` |
| Panel | Plesk, reachable only from campus network or eduVPN |
| Plesk login | `rkortmann` (subscription owner: Rens Kortmann) |
| PHP | 8.2.34 selected in Plesk |
| Document root | `httpdocs/` (Plesk default; leave as is) |
| Access | Plesk web UI (File Manager, Databases, SSL, Scheduled Tasks) and FTP(S). No SSH/SFTP, no Composer on the server, no outbound mail |

Target layout on the server (index.php detects it automatically):

```
/ (subscription root)
    httpdocs/   <- contents of public_html/ (index.php, .htaccess, assets/)
    app/        <- beside httpdocs, not web-reachable
    vendor/     <- built locally with --no-dev
```

## Deployment status (as of 2026-10-01)

**Blocked on DNS.** `macrolab.citg.tudelft.nl` returned NXDOMAIN from
`ns1.tudelft.nl`, so Plesk's Let's Encrypt issuance failed
(`urn:ietf:params:acme:error:dns`). The tudelft.nl zone is managed by ICT, not
Plesk. ICT was asked to create the record (A -> 131.180.77.135 or CNAME ->
lamp8.tudelft.nl) and replied that registration "can take a few working days".

Done:
- Deployment bundle built once on the original (WSL) machine as
  `~/macrolab-deploy.zip`. It contains a production `app/config.php` with
  `base_url = https://macrolab.citg.tudelft.nl`, a generated `app.key` and
  `install_token`, and DB user/password placeholders `FILL_IN_DB_USER` /
  `FILL_IN_DB_PASSWORD`. That zip and token exist only on that machine.

Not done yet (the user had not executed any server step when this was written):
1. Plesk -> Databases: create DB (e.g. `macrolab`) + a user scoped to it.
2. Plesk -> PHP Settings: confirm extensions `pdo_mysql`, `mbstring`,
   `openssl`, `dom`, and preferably `sodium`.
3. Plesk -> Files: upload zip to the subscription root (one level above
   `httpdocs`), Extract Files, delete Plesk's default `httpdocs/index.html`,
   delete the zip.
4. Edit `app/config.php` in File Manager: fill DB name/user/pass; set
   permissions 600.
5. **After DNS resolves:** Plesk -> SSL/TLS Certificates -> issue Let's Encrypt.
6. **After HTTPS works:** open
   `https://macrolab.citg.tudelft.nl/install?token=<install_token, URL-encoded>`.
   QR code and recovery codes are shown once only. Then blank `install_token`
   in `app/config.php`.
7. Verify `/login` works and `/app/config.php` is NOT served (README step 5;
   nginx fallback directives are there if routing 404s).
8. Scheduled Task: daily PHP script `app/cli/prune.php`. Set up Backup Manager.

Steps 1-4 can be done before DNS is ready. Steps 5-6 must not: the app forces
HTTPS and `/install` sends the token and admin password, so do not install via
a preview URL or hosts-file override.

If the original zip is unavailable on this machine, either read the token from
the already-uploaded `app/config.php` via Plesk File Manager, or rebuild the
bundle (below) with a fresh key and token. A new `app.key` is harmless before
`/install` has run; after that it would invalidate the admin's TOTP enrolment.

## Building the deployment bundle

Build from a clean export so local files (dev config, dev `vendor/`) never leak
into the upload:

```bash
B=$(mktemp -d)
git archive HEAD | tar -x -C "$B/" && cd "$B"
composer install --no-dev --optimize-autoloader --no-interaction
cp app/config.example.php app/config.php
php app/cli/generate-key.php   # -> app.key
php app/cli/generate-key.php   # -> app.install_token
# edit app/config.php: base_url, key, install_token, db block
mkdir upload && mv public_html upload/httpdocs && mv app vendor upload/
```

Then zip the *contents* of `upload/` (so the archive root holds `httpdocs/`,
`app/`, `vendor/`) and make sure the dotfiles `httpdocs/.htaccess` and
`app/.htaccess` are included. `zip` may not be installed; PHP's `ZipArchive`
works. When scripting the config edits, pass values to `php -r` as arguments
(`$argv`), not via unexported shell variables. That mistake once left the key
and token empty.

The install token is base64 and may contain `/`, `+` or `=`: URL-encode it in
the `/install?token=` link.

## Updates after go-live

Rebuild `vendor/` only if `composer.lock` changed, upload changed files via
File Manager or FTPS, and apply new migrations from Administration -> System ->
Apply migrations. Do not overwrite the server's `app/config.php` with a fresh
bundle. Plesk Git deploy is possible but needs a `deploy` branch containing
`vendor/` and `httpdocs/` (see README.md "Updating later").
