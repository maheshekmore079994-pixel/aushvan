# Hosting AUSHVAN on a cPanel account

For the hosted tier (D-053 A) and for demos. Tested path: a cPanel shared-hosting account with Apache, PHP 8.4
and MySQL/MariaDB, one subdomain for the application. Nothing on the account's main website is touched.

## What the application needs

| Need | Why |
|---|---|
| PHP 8.3 or newer with `pdo_mysql`, `mbstring`, `dom` / `xml`, `xmlreader`, `gd`, `zip`, `fileinfo`, `openssl`, `tokenizer`, `iconv` (the ones cPanel often leaves off are `dom`, `zip` and `gd`) | Laravel 13 and the PDF / Excel exports |
| One MySQL database and user | All shop data (`InnoDB` is forced by the application) |
| A subdomain with **https** (cPanel AutoSSL is free) | The phone camera and the sign-in cookie work only on https; the mobile app needs it |
| Document root pointed at the application's `public` folder | Only `public` is served; code, `.env` and backups stay out of reach |
| One cron line | Sets the installation up and updates it (`app:ready`), then the nightly verified backup (`backup:run --if-due`) |
| About 150 MB disk | Code 100 MB, plus data and backups |

Memory of 512 MB is enough to run; it is not enough to run Composer on the server, which is why the package
is built on the PC with everything inside. **No terminal or SSH is needed**: everything the server has to do
once (application key, tables, caches) is done by `php artisan app:ready`, which the cron line runs every minute
and which does nothing when nothing is pending. Hosts that do offer a terminal can run `sh post-upload.sh`
instead of waiting a minute.

## One-time setup (the account owner, in cPanel)

1. **Subdomain** → create `aushvan.<domain>`; set its document root to `aushvan/public` (type the path; the
   folder is created by the upload below). If cPanel creates the folder first, fine.
2. **SSL/TLS Status** → tick the new subdomain and press **Run AutoSSL** (or wait for the hourly run). Until it
   has issued, the row says "self-signed" and browsers warn; AutoSSL needs the subdomain's DNS to point at this
   server (it does when the domain's DNS is on the same cPanel; with Cloudflare, the record must be DNS-only /
   grey cloud while it issues, or use Cloudflare's own certificate with "Full" SSL). Check that
   `https://aushvan.<domain>` answers with a padlock before going on.
3. **MultiPHP Manager** → set the subdomain to PHP 8.4 (or 8.3). **Select PHP Version / Extensions** → tick
   `intl`, `bcmath`, `gd`, `zip`, `mbstring`, `fileinfo` if any is off.
4. **MySQL Databases** → create a database, a user with a long password, and add the user to the database with
   **All privileges**. Some accounts prefix the names (`<user>_aushvan`), some show them bare (`aushvan`): use
   them exactly as the page shows them. Keep the names and the password in a file on the PC, not in chat.
5. **Cron Jobs** → **Common Settings: Once Per Minute**, command (replace `<user>` with the cPanel user name shown
   at the top right of cPanel / in File Manager's `/home/<user>` path; the PHP path is shown in MultiPHP Manager,
   usually `/usr/local/bin/php`, or `/opt/cpanel/ea-php84/root/usr/bin/php` to pin 8.4):

   ```
   cd /home/<user>/aushvan && /usr/local/bin/php artisan app:ready >> storage/logs/app-ready.log 2>&1 && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
   ```

   The first command sets the installation up (key, tables, caches) the minute the files and `.env` are in place,
   and again after every update; it writes a line to `storage/logs/app-ready.log` only when it did something or
   failed. The second runs Laravel's scheduler (backup at 21:30 shop time and anything added later). Until the
   folder exists the line fails silently every minute, which is harmless.

## Upload and first start (the developer, once per version)

1. On the PC: `powershell -ExecutionPolicy Bypass -File build\hosting\make-hosting.ps1 -Version 0.9.0`
   → `build\hosting\out\aushvan-hosting-0.9.0.zip`.
2. cPanel **File Manager** → go to `/home/<user>` (not `public_html`) → Upload the zip → Extract. It makes
   the `aushvan` folder with `public` inside, which is the document root set in step 1 above.
3. In `aushvan`, copy `.env.hosting` to `.env` (File Manager → right-click → Copy, type the new name; turn on
   **Show Hidden Files (dotfiles)** in File Manager's Settings first) and edit it (right-click → Edit): `APP_URL`
   and `SANCTUM_STATEFUL_DOMAINS` with the subdomain, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` with step 4's
   values. Leave `APP_KEY=` empty.
4. Wait one minute: the cron line's `app:ready` creates the key, the tables and the caches. `storage/logs/app-ready.log`
   then has one line saying so (or the reason it could not, usually a wrong `DB_*` line). With a terminal,
   `cd ~/aushvan && sh post-upload.sh` does the same at once (`PHP=/opt/cpanel/ea-php84/root/usr/bin/php sh post-upload.sh`
   if the terminal's `php` is older than 8.3).
5. Open `https://aushvan.<domain>`: the shop setup screen appears (owner name, shop name, language). Phones on
   any network open the same address.

Folder permissions: cPanel's defaults (755 folders, 644 files) are right. `storage` and `bootstrap/cache` must be
writable by the account user, which they are by default.

## If something does not work

- **Sign-in says the device is not on the shop network.** The address bar must show **https** and the host must
  match `SANCTUM_STATEFUL_DOMAINS` exactly (no `www`, no port). The sign-in cookie is sent only over https
  (`SESSION_SECURE_COOKIE=true`), so until AutoSSL has issued the certificate, sign-in cannot work.
- **Cloudflare in front of the domain**: use "Full" SSL, not "Flexible"; with Flexible the server sees plain http and
  drops the secure cookie.
- **Backups**: `BACKUP_LOCAL_DIR=../aushvan-backups` is taken from the application folder, so web requests and the
  cron write the same `~/aushvan-backups`. After moving the application, old rows on the Backup screen whose files
  were under the old path show as "this PC only / not downloadable"; the files are still in `~/aushvan-backups`.
- **Still "500" or a blank page a few minutes after the upload**: `storage/logs/app-ready.log` and
  `storage/logs/laravel-*.log` have the reason (File Manager → View); most often a missing PHP extension (enable
  it in "Select PHP Version") or a wrong `DB_*` line. After correcting `.env`, delete `bootstrap/cache/config.php`
  so that the next `app:ready` minute caches the corrected values. An empty `app-ready.log` after several minutes
  means the cron line is not running: check the `<user>` and the PHP path in it.

## Updating to a new version

1. Build the new zip on the PC. 2. In File Manager, rename `aushvan` to `aushvan-old`, upload and extract the
new zip, copy `aushvan-old/.env` to `aushvan/.env` and `aushvan-old/storage/app/private` to the same place.
3. Wait one minute (`app:ready` runs the new migrations and refreshes the caches; with a terminal,
`cd ~/aushvan && sh post-upload.sh`). 4. Check the site, then delete `aushvan-old`.
The backups folder `~/aushvan-backups` is outside the application folder and survives updates.

## Backups on the server

Settings → Backup works the same as on the PC: "Back up now" makes a verified zip under `~/aushvan-backups`,
downloadable from the screen. The cron line above takes one every night. Download one to the shop PC every week
or set a second copy (Settings → Backup → folder) to a path the host backs up. Restore on the server:
`php artisan backup:restore ~/aushvan-backups/<file>.zip` from a terminal (takes a safety backup first). On a
host without a terminal, a restore is done from the PC (download the backup, restore into the desktop build) until
the restore screen exists (KNOWN_ISSUES).

## Not covered yet

- A domain of the shop's own (the demo runs on a friend's subdomain).
- E-mail (password reset by e-mail is not built; the owner resets passwords from Users).
- Sending the server's backups off-site automatically (KNOWN_ISSUES).
- Several shops on one installation: one installation = one shop.
