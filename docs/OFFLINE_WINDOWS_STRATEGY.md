# Online, Offline and Windows Strategy

Status: **accepted 2026-11-11 with the default answer to Q1** (hosted first; see `DECISION_LOG.md` D-002). Q1 is still open and can change the pilot shape. Research date: 11 Nov 2026.
Claims are marked **[V]** verified against the tool's own documentation/releases on that date, or **[E]**
estimate / not verified.

## The three deployment shapes

| | A. Hosted web app | B. Windows desktop (local) | C. Hybrid (local + cloud) |
|---|---|---|---|
| Where Laravel + DB run | Rented server | The shop PC | Shop PC, plus a small server |
| Works without internet | **No** | **Yes, fully** | Yes |
| Access from owner's phone / home | Yes | No (LAN only) | Yes (read copy / backup) |
| Monthly cost | Shared hosting ≈ ₹150–650 [E] | ₹0 | Shared hosting |
| Main risk | Internet outage stops billing | PC failure/theft loses data unless backups leave the PC | Sync complexity |
| Backup | Server-side, easy | Must be automated to USB + off-site | Server copy is the off-site backup |
| Second counter PC | Just open the browser | Browser on LAN pointing to first PC | Same as B |
| Extra development | none (baseline) | packaging, installer, updater | B + sync layer |

Plain-language summary: a hosted app is the cheapest to build and maintain but a rural shop cannot bill when
the internet is down. A local install bills regardless of internet but puts the only copy of the data on a
PC in the shop, so automatic backups become critical. Hybrid gives both but true two-way sync of stock is
the most expensive thing in this whole project and is not justified for one shop.

## Recommendation

1. **Build one codebase that runs in both shapes A and B.** Laravel + compiled React, MySQL *or* SQLite,
   no dependency on internet services at runtime (fonts, icons, scripts all bundled).
2. **Version 1 is developed and tested as the hosted web app** (as the project brief asks), with honest
   connection-loss behaviour: cart kept on the PC, clear "No connection" banner, safe retry. It is not
   called offline.
3. **Windows desktop (shape B) follows in Phase 9** using the same code and a SQLite database.
4. **"Hybrid" is delivered as one-way backup, not sync:** the local install uploads an encrypted database
   backup to cheap storage/hosting when internet is available. Real two-way sync is deferred until a
   concrete need exists (second branch, or phone access to live data), and the data model keeps that door
   open (see "Keeping sync possible").

> **Challenge to the brief — please decide (open question Q1):** if the shop's internet is unreliable for
> hours at a time, the hosted version will frustrate the operator from day one. Because the codebase is the
> same, the pilot in the shop can be a *local install* even before the polished Windows installer exists
> (Phase 9 moved earlier). This costs little extra and removes the biggest operational risk.

## Windows packaging options compared

| Option | What it is | Status (Nov 2026) | Dev effort | Installer [E] | Idle RAM [E] | Database | Updates | Risk |
|---|---|---|---|---|---|---|---|---|
| **NativePHP Desktop** | Electron shell + bundled static PHP, made for Laravel | v2.5.0 released 10 Nov 2026; Electron-based; MIT [V] | Low | 120–200 MB | 300–500 MB | SQLite only [V] | Built in [V] | Medium (small team) |
| **Local server install** | Inno Setup installer lays down portable PHP/FrankenPHP + app + SQLite; opens in an Edge app window | Plain parts, always works; FrankenPHP has native Windows builds since v1.12 [V] | Medium | 40–80 MB | 60–150 MB + Edge | SQLite (or MariaDB) | Own script | Low |
| Tauri (WebView2) + PHP sidecar | Light Rust shell starting PHP | Possible; community packages are tiny [V] | Medium-high | 40–80 MB | 150–250 MB | either | Tauri updater | Medium-high |
| Hand-built Electron + PHP | DIY version of NativePHP | — | Medium | 150–200 MB | 300–500 MB | either | electron-updater | Medium |
| PHP Desktop | Chromium + PHP bundle | Old Chromium (130), author seeking sponsors [V] | Low-medium | 80–120 MB | 150–250 MB | SQLite | none | High |
| FrankenPHP single-binary embed | App compiled into one exe | Embedding on Windows is undocumented [V] | Medium | — | — | — | — | Do not plan on it |
| Offline PWA (IndexedDB) | Browser-only offline against hosted server | — | Very high | 0 | browser | IndexedDB | automatic | High |

Why not an offline PWA: billing offline would need pricing, GST, lot/FEFO selection, stock deduction, ledger
posting and bill numbering re-written in JavaScript, then re-validated and reconciled by the server. Every
business rule would exist twice and could disagree. It is the costliest way to get the least reliable result.

**Shortlist: NativePHP Desktop vs. local-server install.** Both run the real Laravel application on the
shop PC, so there is no duplicated logic.

- NativePHP gives installer, auto-update, migrations-on-update and printing API ready-made, at the cost of
  Electron's memory use and a dependency on a small open-source team.
- The local-server install is lighter on an old PC and lets a second PC use the system over LAN with just a
  browser, but we write and maintain the start-up supervision and updater ourselves.

**Decision method (Phase 9):** a one-day proof of concept of both on the actual shop PC (or a 4 GB / HDD
machine), measuring cold-start time and idle memory; pick on measured numbers. No published figures exist;
the sizes above are estimates. Leaning: local-server install if the PC has 4 GB RAM or a second counter is
likely; NativePHP otherwise.

Code signing: an unsigned installer shows a Windows SmartScreen warning ("More info → Run anyway"). For one
shop installed by us that is acceptable. A signing certificate costs roughly ₹15–40k per year [E] and is only
worth it if the product is distributed to many shops. Microsoft's low-cost Azure signing does not currently
cover Indian publishers [V].

## Hardware in every shape

- **Barcode scanner:** USB "keyboard wedge" — types the code and presses Enter. No driver, no vendor software.
  Optional: the screens also read barcodes with a phone or laptop camera (D-050). Browsers allow a live camera
  only on a secure address — `https://…` or `localhost`. A phone that opens the shop PC as
  `http://192.168.x.x` gets the slower "take a photo of the barcode" mode until the application is served over
  https (hosting, or a certificate on the shop PC).
- **Printing, version 1:** HTML + print CSS through the normal print dialog; works with any thermal (58/80 mm)
  or A4 printer that has a Windows driver. Chrome/Edge kiosk-printing mode can skip the dialog.
- **Printing, desktop build:** because PHP runs on the PC, receipts can be sent silently, and raw ESC/POS
  (cash-drawer kick, paper cut) becomes possible without extra paid software. To be proven in Phase 9 on
  the actual printer.

## The ten offline rules — how each is met

| # | Rule | Design answer |
|---|---|---|
| 1 | Never claim offline just because it installs | Hosted build shows "No connection — billing paused". Only the local build is described as working offline, after Scenario 13 tests pass on it. |
| 2 | A sale must not disappear when internet drops | Hosted: cart persisted locally after every change; Pay is not confirmed until the server answers; retry is safe. Local: no network involved. |
| 3 | No duplicate bills during sync/retry | Client-generated ULID per sale used as an idempotency key with a unique index. |
| 4 | Idempotent handling of duplicate requests | Every write endpoint requires an `Idempotency-Key`; repeats return the stored result. |
| 5 | Unique transaction IDs | ULID on every transactional row, independent of the bill number. |
| 6 | Concurrent stock changes | One database of record in v1. Atomic conditional updates (`qty = qty − n WHERE qty ≥ n`) inside a transaction; row locks on MySQL; single-writer on SQLite. |
| 7 | Local backups | Local build: automatic daily backup file to a second folder/USB, plus on day closing. See `SECURITY_AND_BACKUP.md`. |
| 8 | Never silently overwrite newer records | Financial/stock rows are append-only. Editable master rows carry a version number; a stale save is rejected with "changed by someone else — reload". |
| 9 | Show sync status | Top-bar status dot: Online / No connection (hosted); Backup OK / Backup pending since … (local). |
| 10 | Recovery after computer failure | Install on new PC → restore latest verified backup → self-check → continue. Written step by step in `SECURITY_AND_BACKUP.md` and rehearsed in Phase 8/9. |

## Keeping sync possible (cheap choices made now)

- ULID on every transactional table; server can merge by ULID later.
- Idempotency key on every write.
- Immutable movement / payment / ledger rows; corrections are new rows. Syncing append-only rows is far
  simpler than syncing balances.
- Bill number series has a configurable prefix per counter/device (`A-`, `B-`), so two offline tills can
  never issue the same number.
- All timestamps stored in UTC; `store_id` on every business table; soft deletes on master data only.
- REST API + React SPA, so another client (phone app, sync agent) can use the same API.

If sync is ever built, the pattern is: local database is the source of truth → an append-only outbox of
operations is pushed to a Laravel server → server applies by ULID. Stock would be merged as movements, with
temporary negative stock allowed and a reconciliation report — because two tills can sell the same last
unit while disconnected. This is recorded here so nobody under-estimates it.

## SQLite / MySQL portability rules

The rules that make one codebase run on both are in `TECHNICAL_ARCHITECTURE.md` ("Database portability").

## Hosting for shape A

- Requirements: PHP 8.3+ with common extensions, MySQL/MariaDB, SSH with Composer, cron (for the scheduler),
  ability to point the web root at Laravel's `public/` (or symlink). Laravel 13 needs PHP 8.3+ [V].
- Typical Indian shared hosting meets this on mid-tier plans; SSH is sometimes only on higher tiers [E] —
  confirm before buying. No long-running queue workers on shared hosting: jobs run from cron
  (`schedule:run` + `queue:work --stop-when-empty`). The application is designed to need almost no queueing.
- Entry VPS (≈ ₹600/month [E]) is an alternative but needs someone to maintain the server; not recommended
  for this shop unless shared hosting proves insufficient.

## Desktop app and mobile app (owner request, 2026-11-11)

The owner wants a desktop application and a mobile application once the product is ready. Plan (decision D-036,
to be confirmed by the owner):

| Target | How | When |
|---|---|---|
| Phone / tablet, now | The same web application: responsive screens, bottom bar, installable from the browser ("Add to Home screen") through the web app manifest. Needs a connection to the server. | Built 2026-11-11 |
| Windows desktop | Shape B: `build/windows/make-desktop.ps1` packages a trimmed PHP, the application and the `AUSHVAN.exe` launcher (D-057). First package built 2026-11-22; owner test on a clean PC pending (KNOWN_ISSUES D1–D6). Works without internet; data in SQLite under %LOCALAPPDATA%\AUSHVAN. | Phase 9, started |
| Mobile app in the Play Store | A thin wrapper (Trusted Web Activity or Capacitor) around the same hosted application. Camera scanning is already part of the web screens (D-050), so the wrapper gets it without extra work. Only useful once a hosted server exists for the phone to reach. | After the pilot |
| Owner's phone with a local-only shop PC | Needs either the shop PC reachable on the shop Wi-Fi, or sync to a hosted copy (the two-way sync deferred beyond version 1). | Decide with Q1 |

What is **not** claimed: there is no offline mode on phones, no native mobile app, and no sync between a shop PC
and a phone today.

## Plans for every budget (owner question, 2026-11-19 — D-053)

The owner will take hosting for the shops that can pay. For the rest, the same code base offers two no-fee ways in:

| Tier | What the shop needs | Running cost | What it gets | Limits |
|---|---|---|---|---|
| A. Hosted | Any browser; internet | Monthly hosting fee shared across shops (one server can serve many small shops, each with its own database) | Everything, from any device, backups done centrally, phones with live camera scanning (https) | Billing pauses when the internet is down |
| B. Shop PC | One Windows PC (even an old one) | Nothing per month | The Windows build (Phase 9): installs in minutes, data in SQLite on the PC, works with no internet, prints to any Windows printer; phones on the shop Wi-Fi can open it | Owner cannot see the shop from outside; phones get photo-mode scanning until https on the PC; the shop is responsible for the backup USB |
| C. Phone only | A smartphone | The smallest hosted tier, or free while the shop is tiny | Billing with camera scanning, udhar, stock, reports — no PC, no scanner, no printer (bills shared as PDF/WhatsApp later) | Needs mobile data; printing only to a Bluetooth printer later |

A shop can start at C, move to B when it buys a PC, and to A when it wants the owner to see the shop from home.
Moving B → A needs the one-way upload of the SQLite data (planned, not built); A → B needs the reverse. Two-way
sync stays out of version 1.

## Open items

- Measure NativePHP vs. local-server on the real PC (Phase 9).
- Confirm NativePHP auto-update works for unsigned Windows builds.
- Prove silent thermal printing on the shop's actual printer.
- Confirm hosting plan features/prices at purchase time.
