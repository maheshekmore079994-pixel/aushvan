# Security, Backup and Recovery

Status: **accepted 2026-11-11** (owner said to continue; no changes requested). Implemented mainly in Phases 1 and 8.

## Authentication and sessions

- Username + password; passwords hashed with Laravel's default (bcrypt/argon2). Minimum length 8.
- Sanctum cookie sessions (HttpOnly, SameSite=Lax, Secure on HTTPS). No tokens in browser storage.
  The session is issued to the application's own hosts and to any origin in the private address ranges
  (192.168.x.x, 10.x.x.x, 172.16–31.x.x), so a phone on the shop network needs no setting when the PC's
  address changes (D-058); any other origin is refused at sign-in (`not_shop_network`).
- CSRF protection on all state-changing requests.
- Login rate limiting (per username + IP); temporary lockout after repeated failures.
- Optional 4–6 digit PIN (hashed) used only to *authorise an action* at the counter (pharmacist
  verification, discount override) while another user is logged in. A PIN is not a login.
- Idle timeout configurable (default long for the counter PC, short for remote logins). Owner can end other sessions.
- First-run setup creates the Owner account; there is no default password.

## Authorisation

- Roles and permissions stored as data (`spatie/laravel-permission`), checked in policies / middleware on
  the backend for every endpoint. Frontend hiding is cosmetic only.
- Sensitive permissions: cancel bill, restock returned medicine, stock adjustment, price override,
  discount above limit, ledger reversal, view cost/profit, view prescriptions, manage users, backup/restore,
  reopen day, change GST/licence settings.
- Each permission has a feature test proving a user without it receives 403.

## Input and data safety

- Every request validated by a Form Request class. Eloquent / query builder bindings only — no string-built SQL.
- Output escaped by React by default; no `dangerouslySetInnerHTML` with user data.
- HTTPS required for the hosted deployment; HSTS and standard security headers set.
- Rate limiting on the API in general and tighter on login and export endpoints.
- Mass-assignment protection: explicit `$fillable`, never `$request->all()` into models.

## File uploads

- Allowed: JPEG, PNG, WebP, PDF. Type checked by content, not just extension. Size limit (default 5 MB).
- Stored on a private disk outside the web root with random names; served only through an authorised
  controller. Images re-encoded on upload. No executable types, ever.

## Audit trail

`audit_logs` records who, when, what and why for: login/logout/failed login, bill cancellation, returns,
price and discount overrides, stock adjustments and write-offs, ledger reversals, product price changes,
user/role changes, settings changes (GST mode, licences, schedules, tax rates), day reopen, backup, restore,
data export, prescription record views. Visible to the Owner under Settings → Audit history. Append-only.

## Personal and prescription data

- Collect the minimum: a walk-in sale needs no personal data; a customer record needs only a name.
- Mobile numbers and addresses are used for billing and udhar only; no marketing messages by default.
- Prescription and patient details are captured only where the drug schedule's configuration requires
  them, are visible only to roles with the permission, and their viewing is audited. They are excluded
  from general exports.
- Customer data can be exported or anonymised on request; transaction records that the law requires the
  shop to keep (bills, drug registers) are retained with the legal minimum of identifying detail.
- Backups are encrypted when they leave the PC/server.
- Legal position (researched 11 Nov 2026): the Digital Personal Data Protection Act 2023 and Rules (notified
  Nov 2025) phase in, with the main duties — notice, consent, security safeguards, breach notification,
  access/correction/erasure — expected from about May 2027; no small-business exemption has been notified
  (secondary sources). The design above is intended to be ready for that. **CONFIRM** exact dates and
  duties with a lawyer before relying on this.

## Backup

**Status 2026-11-26:** built as described in D-059 — `app/Domain/Backup/` (archive, verifier, restorer, folder), `app/Actions/MakeBackup.php`,
Settings → Backup, home-screen reminder and automatic daily copy, `backup:run` / `backup:restore` commands. Not yet
built, marked *(planned)* below: restore screen, encryption, off-site upload, weekly/monthly retention tiers.

### What is backed up
The whole database plus the uploads folder (prescription photos, invoice scans, product images) and a small
manifest (app version, schema version, row counts of key tables, checksums).

### How
| Deployment | Mechanism | Where it goes |
|---|---|---|
| Hosted (MySQL) | PHP-based dump through the application (works on shared hosting where `mysqldump` may be unavailable); `mysqldump` used when present | Server private storage + **download to the shop PC**; optional copy to the owner's cloud drive later |
| Windows local (SQLite) | Consistent snapshot of the database file (`VACUUM INTO`) + uploads, zipped | `%LOCALAPPDATA%\AUSHVAN\backups` (last 7) **and** the backup folder (USB stick / second disk) set in Settings → Backup; encrypted off-site upload *(planned)* |

- **Manual:** Settings → Backup → "Back up now"; the last 7 local copies can be downloaded from the same screen.
- **Automatic (local install):** when a backup folder is set and no verified copy reached it in the last 20 hours, the home screen of a user with `backup.manage` takes one and says where it went. **Scheduled (hosted):** `backup:run --if-due` daily at 21:30 through cron. Offered at Day Closing *(planned with day closing)*.
- **Retention:** last 7 local copies (`BACKUP_KEEP_LOCAL`); nothing is ever deleted from the backup folder. Weekly/monthly tiers *(planned)*.
- **Reminder:** the home screen warns users with `backup.manage` when the last verified backup is older than 48 hours (`BACKUP_DUE_AFTER_HOURS`).
- **Permission:** every backup route needs `backup.manage` (Owner by default). Every attempt, success or failure, is a `backups` row and an audit entry; folder changes, downloads and restores are audited too.

### Verification — a backup is not "successful" until
1. the file exists, is non-empty, and its checksum is recorded;
2. the archive opens and contains the manifest and database dump;
3. the database copy has the checksum recorded in the manifest, opens (SQLite: `integrity_check`; MySQL dump: header
   and statement structure) and the row counts of the 11 key tables match the manifest. The copy in the backup folder
   is verified separately from the local one.

Only then is the backup marked **Verified** and reported as successful. Any failure is shown in red with the reason.

### Restore
- Built: `php artisan backup:restore <file>` (asks for confirmation; `--force` skips it). It verifies the archive, refuses one from another application version (`backup_schema_differs`) or database kind, takes a safety backup of the current data (`before_restore`), then replaces every table except `migrations` and `backups` inside one transaction and recounts the key tables against the manifest. HOW_TO_RUN §10 has the steps, including the desktop build.
- Restore replaces everything; it is never a merge. This is stated on the Backup screen.
- *(planned)* Settings → Backup → Restore (Owner only, password re-entry, typed confirmation); migrating a backup from an older version forward.

## Recovery guide in simple language (to be finalised and rehearsed in Phase 8)

**If the computer breaks or is stolen**
1. Do not panic — your data is in the backup.
2. Get any Windows computer. Install the application (hosted version: just open the web address and log in — nothing was lost).
3. Local version: open the app, choose "Restore from backup", pick the newest backup file from the USB stick or download it from the off-site storage.
4. Wait for the green "Restore complete" message. Check that yesterday's last bill number is there.
5. Enter again, from the paper bill copies, any sales made after that backup.

**If the power goes off while billing** — switch on, open New Sale. The bill you were making is still on
the screen. If it says "already saved as bill no. …", it was saved; just reprint.

**If the internet stops (hosted version)** — the bill on screen is kept. Write urgent sales in the paper
bill book and enter them when the connection returns.

**Every evening** — close the day and press "Back up". Keep the USB stick somewhere other than the shop once a week.

## Checks before go-live (Phase 8)

Permission tests for every endpoint · dependency vulnerability audit (`composer audit`, `npm audit`) ·
upload abuse tests · session/CSRF tests · backup → restore → integrity round trip on both MySQL and SQLite ·
debug mode off, error pages without stack traces · least-privilege database user.
