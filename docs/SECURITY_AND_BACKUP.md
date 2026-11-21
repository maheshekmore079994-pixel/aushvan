# Security, Backup and Recovery

Status: **accepted 2026-11-11** (owner said to continue; no changes requested). Implemented mainly in Phases 1 and 8.

## Authentication and sessions

- Username + password; passwords hashed with Laravel's default (bcrypt/argon2). Minimum length 8.
- Sanctum cookie sessions (HttpOnly, SameSite=Lax, Secure on HTTPS). No tokens in browser storage.
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

### What is backed up
The whole database plus the uploads folder (prescription photos, invoice scans, product images) and a small
manifest (app version, schema version, row counts of key tables, checksums).

### How
| Deployment | Mechanism | Where it goes |
|---|---|---|
| Hosted (MySQL) | PHP-based dump through the application (works on shared hosting where `mysqldump` may be unavailable); `mysqldump` used when present | Server private storage + **download to the shop PC**; optional copy to the owner's cloud drive later |
| Windows local (SQLite) | Consistent snapshot of the database file (`VACUUM INTO`) + uploads, zipped | A second folder/drive or USB stick + encrypted upload to cheap off-site storage when online |

- **Manual:** Settings → Backup → "Back up now" → download.
- **Scheduled:** daily where the host allows cron; additionally offered at Day Closing ("Back up today's data").
- **Retention:** last 7 daily, 4 weekly, 12 monthly (configurable).
- **Reminder:** the dashboard warns the Owner when the last verified backup is older than 2 days.

### Verification — a backup is not "successful" until
1. the file exists, is non-empty, and its checksum is recorded;
2. the archive opens and contains the manifest and database dump;
3. the dump is restored into a temporary database (SQLite: opened and `integrity_check` run) and the row
   counts of key tables match the manifest.

Only then is the backup marked **Verified** and reported as successful. Any failure is shown in red with the reason.

### Restore
- Settings → Backup → Restore (Owner only, password re-entry, typed confirmation).
- The system first takes a safety backup of the current data, then restores into a clean database, runs
  migrations if the backup is from an older version, runs the integrity check, and reports the result.
- Restore replaces everything; it is never a merge. This is stated on the screen.

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
