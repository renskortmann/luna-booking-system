# Macrolab website

A small web application for reserving time on the lab's instruments, built to
run on TU Delft LAMP hosting.

Signing in lands on the **hub** at `/`, which is the front door to two
unrelated systems: **booking** at `/booking` and **time registration** at
`/time`. They share the sign-in and nothing else - time is logged against a
project, never against a machine.

Lab members pick a machine there and book, change or cancel their own time
slots on its shared calendar - and only their own. One administrator controls
who may sign in at all, manages the list of machines, and can create, change or
delete any booking.

- **Stage 1 (now):** members sign in with their netID and a password they set
  themselves through a single-use link from the administrator.
- **Stage 2 (when TU Delft ICT registers the service provider):** members sign
  in with TU Delft SSO. Accounts are keyed on netID in both stages, so the
  switch is a setting, not a migration. See [the cutover](#stage-2-switching-to-tu-delft-sso).

The administrator's own sign-in never goes through SSO, so the lab keeps access
even when SSO is unavailable.

### Two separate sign-in pages

This trips people up, so it's worth stating plainly: there is no single sign-in
page.

| | URL | Who |
|---|---|---|
| Lab members | `/login` | netID + password (stage 1) or TU Delft SSO (stage 2) |
| Administrator | `/admin/login` | the one account created at `/install`, password + one-time code |

The administrator account is **not** a netID and is never on the allowlist -
entering its username at `/login` fails with the same generic
"Incorrect netID or password." that any unrecognised netID gets. Use
`/admin/login` instead.

---

## Requirements

| | |
|---|---|
| PHP | 8.2 or newer |
| PHP extensions | `pdo_mysql`, `mbstring`, `openssl`, `json`, `dom`/`xml`; `sodium` strongly preferred |
| Database | MySQL 5.7+ / MariaDB 10.4+ |
| Web server | Apache or nginx. `.htaccess` with `mod_rewrite` gives tidy URLs; without it there is a fallback that needs no rewrite rules |
| Shell on the server | **Not needed.** Installation, migrations and day-to-day operation all happen in the browser |
| Transport | HTTPS. The application redirects plain HTTP to the configured base URL |

Everything else is vendored: the calendar library is served from this host, so
the page needs no CDN and the content security policy can stay at `'self'`.

`sodium` is preferred for two reasons: it gives Argon2id password hashing, and
it encrypts the administrator's TOTP secret. Without it the application falls
back to bcrypt and AES-256-GCM, which is sound - the install page and the
administration dashboard both tell you which one you got.

## Local development

```bash
composer install
cp app/config.example.php app/config.php
php app/cli/generate-key.php        # paste the output into app.key
# fill in db credentials and set app.base_url to http://localhost:8000
# and app.require_https to false

mysql -e 'CREATE DATABASE macrolab CHARACTER SET utf8mb4'
php app/cli/migrate.php
php app/cli/create-admin.php

php -S localhost:8000 -t public_html
```

`php -S` has no `.htaccess`, so it serves `index.php` for unknown paths by
itself - which is what we want. It does *not* apply the deny rules, so never
use it for anything but development.

`create-admin.php` prints a QR code's worth of secret and ten recovery codes
to the terminal, **once**. Scan the secret into an authenticator app (or add
it by hand - most apps offer "enter setup key" as an alternative to scanning)
before you close that terminal; there is no second copy anywhere. Then sign in
at `http://localhost:8000/admin/login`, not `/login` - see
[the two sign-in pages](#two-separate-sign-in-pages) above.

It also reads fine from a pipe, if you want to script the setup instead of
typing at the prompts (username, password, password again):

```bash
printf 'admin\nyour-password\nyour-password\n' | php app/cli/create-admin.php
```

### If `mysql -e '...'` refuses your connection

The one-liner above assumes passwordless `root` access, which on a stock
MariaDB install only works as the `root` *OS* user (it authenticates via
`unix_socket`, matching your Linux username to the MySQL username - so your
own login, and a plain `root`/empty-password guess over TCP, both get
"Access denied" even though the server is running fine). Two ways past it:

- Run the database setup itself as root: `sudo mysql -e 'CREATE DATABASE ...'`.
- Or create a dedicated account for the app instead of fighting `root`:

  ```bash
  sudo mysql -e "
  CREATE DATABASE IF NOT EXISTS macrolab CHARACTER SET utf8mb4;
  CREATE USER IF NOT EXISTS 'macrolab_dev'@'localhost' IDENTIFIED BY 'pick-a-password';
  GRANT ALL PRIVILEGES ON macrolab.* TO 'macrolab_dev'@'localhost';
  FLUSH PRIVILEGES;
  "
  ```

  Then put `macrolab_dev` / that password in `app/config.php`'s `db` block, and
  make sure `db.socket` is `null` there - a value left over from a different
  local MySQL instance (e.g. a scratch one from a previous test run) makes the
  app try to connect through a socket that no longer exists instead of over
  TCP, which fails the same way and is easy to mistake for a credentials
  problem.

### Tests

```bash
vendor/bin/phpunit                    # unit tests, no database needed

export MACROLAB_TEST_DB_NAME=macrolab_test    # a scratch database - it gets dropped
export MACROLAB_TEST_DB_USER=root
export MACROLAB_TEST_DB_PASS=
mysql -e 'CREATE DATABASE macrolab_test CHARACTER SET utf8mb4'
vendor/bin/phpunit                    # now the database tests run too

bash tests/concurrency.sh             # two processes race for one slot, 20x
```

The database tests rebuild the schema before every test, so point them at a
database you do not mind losing.

---

## Deploying to TU Delft LAMP hosting

### What that hosting gives you, and what follows from it

| Offered | What it means here |
|---|---|
| Plesk panel, reachable **only from a campus network** | Deploy from campus or over eduVPN |
| FTP, unlimited users | The upload channel. Use **FTPS** in your client - plain FTP sends the password in the clear |
| **No SSH, no SFTP** | There is no command line. The schema and the administrator account are created in the browser, at `/install` |
| 1000 MB webspace, 10 databases | Ample: this application plus its dependencies is a few MB, and it uses one database |
| SSL available | Required. Get the certificate issued before you fix the base URL |
| Git available | An alternative to FTP for updates - see below |
| **Mail not available** | Which is why this system sends none. Nothing here depends on it |
| You are responsible for backups | Plesk can schedule them; nobody else will |

**Composer never runs on the server.** You build the `vendor/` directory on
your own machine and upload the result. Nothing in the application needs a
shell on the server.

### 1. Prepare the upload on your own machine

```bash
composer install --no-dev --optimize-autoloader
```

Then make a copy of `app/config.example.php` as `app/config.php` and fill in:

- `app.base_url` - the final HTTPS address, no trailing slash
- `app.key` - run `php app/cli/generate-key.php` locally and paste the output
- `app.install_token` - run `php app/cli/generate-key.php` again and paste that
  too; this is what protects `/install` during the minutes between upload and
  installation
- the `db` block - from Plesk, **Databases** → add a database and a database
  user with rights on that database only

### 2. Choose the PHP version in Plesk

**Websites & Domains** → your domain → **PHP Settings**. Choose **8.2 or
newer**. If nothing that recent is offered, ask ICT before going further; the
application will refuse to install on an older version, and the install page
will tell you which version it found.

### 3. Upload

The layout to aim for puts the application **beside** the document root rather
than inside it, so the configuration and the SAML key are not web-reachable at
all - no `.htaccess` rules involved:

```
<your FTP home>/
    httpdocs/            <- the document root
        index.php            (from public_html/index.php)
        .htaccess            (from public_html/.htaccess)
        assets/              (from public_html/assets/)
    app/                 <- beside httpdocs, so unreachable over the web
    vendor/
```

So: the **contents** of `public_html/` go into `httpdocs/`, and `app/` and
`vendor/` go one level up, next to `httpdocs/`. `index.php` detects this
arrangement by itself - there is nothing to configure and no need to change the
document root.

Then, in your FTP client, set the permissions on `app/config.php` to **600**
(owner read/write only).

**If your FTP account cannot write outside `httpdocs`**, put `app/` and
`vendor/` inside it instead. `index.php` handles that too, but the protection
of `app/` then rests entirely on `.htaccess`, so you must verify it:

```bash
curl -i https://<host>/app/config.php
```

That must return 403 or 404 and never any content. If it returns the file,
**stop**: replace the real database password with a placeholder, and ask ICT
either to raise `AllowOverride` or to let you write beside `httpdocs`.

### 4. Install, in the browser

Open:

```
https://<host>/install?token=<your install_token>
```

The page checks the server (PHP version, extensions, database connection,
whether `app/` is web-reachable), then loads the schema and creates the
administrator account in one step. It shows you the authenticator QR code and
your recovery codes **once, in that response, and nowhere else** - not by
email, not on a later page, not recoverable from the database. Scan the QR
code with your phone's authenticator app before you navigate away or close the
tab, and save the recovery codes somewhere durable.

**If you miss it anyway** - closed the tab, the page didn't load, whatever -
you are not locked out, but you cannot get the same QR code back:
1. Sign in at `/admin/login` with the username and password you just chose.
   The one-time-code step accepts a recovery code (shown further down on this
   same page) in place of a six-digit code, exactly once each.
2. Once in, go to **Administration → System** and re-enrol the authenticator.
   That issues a fresh secret and shows its QR code - again, once - which
   replaces the one you missed.

It then stops existing: with an administrator account on file, `/install`
returns 404. Afterwards, remove `install_token` from `app/config.php` and
re-upload it.

### 5. Check that pretty URLs work

Visit `https://<host>/login`. If you get the sign-in page, routing works and
you are done.

If you get a 404 from the web server, the hosting is serving the site through
nginx without honouring `.htaccess`. Two ways out:

- **Plesk** → **Apache & nginx Settings** → *Additional nginx directives*:
  ```nginx
  location / {
      try_files $uri $uri/ /index.php$is_args$args;
  }
  location ^~ /app/    { deny all; }
  location ^~ /vendor/ { deny all; }
  ```
- Or avoid rewriting altogether: set `app.base_url` to
  `https://<host>/index.php` and re-upload the config. Every link the
  application generates then goes through `/index.php/...`, which needs no
  rewrite rules at all. Slightly uglier URLs, nothing else changes.

### 6. Housekeeping

**Plesk** → **Scheduled Tasks** → add a task, type *Run a PHP script*, script
path `app/cli/prune.php`, daily. That trims the audit log to the retention
window and clears spent invite links. Nothing breaks if you skip it; the
database just grows slowly.

Also set up **Plesk** → **Backup Manager**, since backups are your
responsibility. The database is the part that matters - the files can be
re-uploaded from this repository at any time.

### Updating later: FTP or Plesk Git

**FTP** is the simple path: rebuild `vendor/` locally if the dependencies
changed, upload the files that changed, and if the update brings a database
migration, apply it from **Administration** → **System** → *Apply migrations*.
That page is behind your own sign-in, so it needs no install token and stays
available for the life of the installation.

**Plesk Git** avoids hand-uploading. Because Composer cannot run on the server,
the branch you deploy must contain `vendor/`, which the main branch does not:

```bash
git checkout -b deploy
composer install --no-dev --optimize-autoloader
git add -f vendor composer.lock
git commit -m "Deploy build"
git push origin deploy
```

Then, in Plesk, add the repository, choose the `deploy` branch, and set the
deployment path to your FTP home so that `httpdocs/`, `app/` and `vendor/` land
where they belong - which means the deploy branch should also have the contents
of `public_html/` moved to `httpdocs/`. Note that Plesk's *additional deployment
actions* run shell commands and are therefore not available here.

## Running it

### Giving someone access

**Who may sign in** → enter their netID → you get a single-use link. Send it
however you like: Teams, email, in person. They open it, choose a password, and
sign in. You never see their password.

The link works once and expires after seven days. Issuing a new one
invalidates the previous one.

### Someone forgot their password

Same page, **Reset password**. Their old password keeps working until they set
a new one, so a link that never arrives cannot lock them out.

### Taking access away

**Suspend** blocks sign-in immediately - including in the middle of a session,
because the allowlist is checked on every request - and keeps the person's
booking history. **Remove** is only offered when they have no bookings at all.

### Booking rules

**Booking rules** sets slot length, opening hours and days, minimum and maximum
booking length, how far ahead people may book, how many upcoming bookings each
may hold, and how much notice is needed to change one. They apply to lab
members; they do not apply to you. Overlapping bookings are refused for
everyone, including you.

### Audit log

Every booking change, allowlist change, settings change and sign-in - including
refused ones - is recorded with who, when and from which address. Entries are
kept for the number of days set in the rules (365 by default) and pruned by
`app/cli/prune.php`.

### If you lose your authenticator

Use a recovery code instead of the six-digit code; each works once. The
dashboard shows how many are left, and you can issue a fresh set from there.

Out of codes *and* out of authenticator, the account can only be recovered
through the database. In Plesk, **Databases** → **phpMyAdmin**, then:

```sql
DELETE FROM admin_account;   -- admin_recovery_codes cascades with it
```

Put an `install_token` back into `app/config.php`, re-upload it, and open
`/install` again to create the account afresh. Bookings, users and the audit
log are untouched.

## Stage 2: switching to TU Delft SSO

1. Send ICT the request in [docs/ICT-REQUEST.md](docs/ICT-REQUEST.md). Do this
   early - registration takes time, and nothing else in the build waits on it.
2. Generate the service provider key pair **on your own machine** and upload
   the two files to `app/secrets/` by FTP, setting `sp.key` to permissions 600:
   ```bash
   openssl req -x509 -newkey rsa:3072 -nodes -days 3650 \
       -keyout app/secrets/sp.key -out app/secrets/sp.crt -subj "/CN=<host>"
   ```
   Keep a copy of `sp.key` somewhere safe and out of version control: ICT
   registers the matching certificate, so losing the key means re-registering.
3. Paste the IdP signing certificate from
   `https://login.tudelft.nl/sso/saml2/idp/metadata.php` into
   `saml.profiles.tudelft.x509cert` in `app/config.php`. Take it from that URL
   yourself; do not accept a copy from anywhere else.
4. Set **Sign-in mode** to *Either password or TU Delft SSO* and sign in with a
   real netID. Check `/admin/saml-debug`: it lists the attribute names the
   assertion actually carried. If the netID did not arrive under `uid`, add the
   name you see to `saml.attr_map.netid` - configuration, not code.
5. Confirm that an SSO sign-in lands on the **existing** account, with its
   bookings intact, and that a netID which is not on the allowlist is still
   refused.
6. Set **Sign-in mode** to *TU Delft SSO only*. Password sign-in for lab
   members is then refused and logged. Your own sign-in is unaffected.
7. Once you are satisfied, clear the unused password hashes. With a shell:
   ```bash
   php app/cli/purge-local-passwords.php --force
   ```
   Without one, run this in Plesk's phpMyAdmin - it does the same thing, and
   only after the sign-in mode is already *TU Delft SSO only*:
   ```sql
   UPDATE users SET password_hash = NULL, password_changed_at = NULL;
   DELETE FROM user_invites;
   ```

The base URL is baked into the entityID and the ACS URL that ICT registers, so
**fix the hostname before step 1** - changing it later means asking ICT to
re-register.

---

## Notes on the design

- **Times.** Every timestamp is stored in UTC and rendered in
  `app.display_timezone`. Opening hours are compared in local wall-clock time,
  which is what "between 08:00 and 18:00" means, so the March and October DST
  transitions cannot produce an ambiguous booking.
- **Overlaps.** Each booking write takes a row lock on the machine first, so
  two requests cannot both find a slot free and then both fill it. Intervals
  are half-open: a booking ending at 10:00 and one starting at 10:00 do not
  clash.
- **Authorisation.** Every write re-loads the booking and asks
  `BookingPolicy::canModify()`. A request naming somebody else's booking id is
  refused with 403, whatever the interface offered.
- **The allowlist gate** lives in `Auth::signIn()`, not in an authentication
  provider, so it cannot be bypassed by a bug in one provider and does not have
  to be reimplemented when SSO is added.
- **Personal data** is limited to netID, display name and email address, plus
  each person's own bookings and the audit log. No email is sent and the
  application makes no outbound connections of any kind.

## Machines

The administrator manages the bookable machines at `/admin/machines`. Each
booking belongs to one machine, and the calendar shows one machine at a time:
its name is the heading, and a dropdown switches between them. The choice is
remembered for the next visit, and `/booking?machine=<slug>` links straight to
one.

A machine with bookings on record cannot be deleted, only retired - the same
reasoning as suspending a user rather than deleting them, so the record of who
used what stays intact. Retiring one hides it from the picker and stops new
bookings; the bookings it already has are untouched. The last machine still in
use cannot be retired.

Booking rules are shared by every machine. The per-person quota counts per
machine, so filling up one instrument does not lock anybody out of the others.

## Time registration

Employees log the hours they worked at `/time`: a day, a project, a duration
and an optional note. Hours can be typed as `3.5`, `3,5`, `3:30` or `3h30`, and
are stored as whole minutes, so nothing is lost to rounding.

**This system is not connected to the booking system.** A time entry names a
project and never a machine. The two halves share the sign-in and nothing else,
which is deliberate: hours are booked to work, not to equipment.

Employees own their entries and can change or remove their own at any time -
and only their own. A request naming somebody else's entry is refused with 403
and recorded, the same discipline the bookings use.

The administrator maintains the project list at `/admin/projects` and reads
what everyone has logged at `/admin/time`, filtered by person, project and date
range, with a CSV export of exactly those rows. That view is **read-only**:
there is no approval step, and nobody edits somebody else's timesheet.

A project with time on record cannot be deleted, only retired - the same
reasoning as retiring a machine. For the same reason, an account with time
registered cannot be removed from the allowlist, only suspended.

The limits on entry length, on the daily total, and on how far ahead or back
time may be logged are set alongside the booking rules at `/admin/settings`.

The CSV is UTF-8 with a byte-order mark, so Excel reads accented names
correctly. Any cell beginning with `=`, `+`, `-` or `@` is prefixed with an
apostrophe, because spreadsheets execute those on open and the note field is
typed by a user.

Time entries are **never pruned**. `app/cli/prune.php` trims logs and spent
tokens; hours are a business record.

---

## Layout

```
public_html/index.php        the only reachable PHP file; everything is routed
public_html/.htaccess        routing, deny rules, security headers
public_html/assets/          stylesheet, script, vendored FullCalendar
app/config.php               local configuration (gitignored)
app/routes.php               the whole route table
app/src/                     the application - see the class list below
app/views/                   plain PHP templates
app/migrations/              schema
app/cli/                     migrate, create-admin, generate-key, prune
docs/ICT-REQUEST.md          the SSO registration request to send ICT
tests/                       unit tests, database tests, concurrency probe
```

| Class | What it is for |
|---|---|
| `Auth`, `Actor` | who is signed in; the allowlist gate |
| `Auth\LocalProvider` | stage 1 password sign-in |
| `Auth\ProviderInterface` | the seam TU Delft SSO slots into |
| `Invite` | single-use links for setting a password |
| `AdminAuth`, `Crypto` | the administrator's password and one-time codes |
| `BookingRules`, `RuleSet` | the rules, as pure functions |
| `BookingService` | writes, with the lock and the overlap check |
| `BookingPolicy` | who may change which booking |
| `Navigation` | the one list of destinations, shared by the hub and the top bar |
| `TimeRules`, `TimeRuleSet` | the time rules and the hour/date parsing, as pure functions |
| `TimeEntryService` | time writes, with the daily cap and the audit entry |
| `TimeEntryPolicy` | who may change which time entry - the admin may not |
| `Projects` | the project list time is logged against |
| `TimeFilter` | one filter behind the admin table, its totals and the export |
| `Csv` | the export, quoted and safe to open in a spreadsheet |
| `Audit`, `RateLimit` | the record, and login throttling |
