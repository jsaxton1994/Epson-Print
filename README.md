# PrintFree

A completely free **photo** printing service for an **Epson ET-2926**, built in plain PHP with a database, and powered by the **Epson Connect API v2**.

Customers build orders of up to **100 photos** — JPG, PNG, HEIC, WebP, GIF, BMP, TIFF, AVIF and similar. They can select the whole album at once; the browser uploads it **10 photos at a time** so no request is huge. Then they choose a print size (4 × 6, 5 × 7 or A4), borderless or white border, colour and quality. Every photo prints once — there is no copies setting. Administrators see the queue, send photos to the printer in batches, and manage customers. No payments, allowances or credits anywhere.

Demo accounts:

| Role     | Email             | Password |
| -------- | ----------------- | -------- |
| Customer | `customer@me.com` | `123`    |
| Admin    | `admin@me.com`    | `123`    |

---

## Requirements

- PHP 8.1 or newer with `pdo_mysql`, plus `curl`, `fileinfo`, `mbstring` and `gd`
- A MySQL database (the live site uses cPanel MySQL)
- Apache with `mod_rewrite` (not required) or Nginx / PHP's built-in server

## Quick start

1. Copy `includes/config.local.example.php` to `includes/config.local.php` and enter the MySQL password. The local file is gitignored.
2. Upload the folder to your host, or run it locally:

   ```bash
   php -S 127.0.0.1:8080 -t public
   ```

   The web root is `public/`. Point Apache, Nginx or your host at that folder. If the project root is the document root instead, the bundled `.htaccess` sends visitors to `public/index.php` and links automatically include the `/public/` prefix.

3. Open the site. The first request creates the tables, settings and demo accounts automatically if the MySQL database is empty.
4. Sign in with one of the demo accounts above.

MySQL is the only supported database. If the password file is missing or MySQL is unreachable, the site returns a clear **503** page; it never switches to another database.

## Folder structure

```
public/              web root (landing, sign in, register, assets)
public/css/          base.css, site.css, dashboard.css
public/js/           main.js, forms.js, dashboard.js
public/js/vendor/    bundled HEIC (heic2any) and TIFF (UTIF.js) browser decoders
public/img/          favicon and landing-page sample photo
public/admin/        admin area (dashboard, orders, customers, settings)
public/customer/     customer area (dashboard, new order, orders, profile)
includes/            config, database, session, auth, Epson client, layout
                      (outside the web root)
cron/                CLI-only print queue worker and watchdog
tools/               SNMP ink bridge for a machine on the shop network (CLI only)
index.php            redirects to the public site when the root is the docroot
```

## Orders and photos

An order is a print header (customer, size, finish, colour, quality, notes) with **1–100 photos** attached. Each upload request carries at most **10 photos**, and the browser chains those requests automatically for a large selection. Each photo gets its own row in `order_photos`, its own status and its own Epson job id, so one bad file does not mark the whole order as printed.

While an order is still `pending`, the customer can add more photos from the order page. The endpoint checks ownership, CSRF, the order status, the photo count and the order's storage budget, inserts each batch one photo at a time, and rolls that batch back if any file fails. The browser remembers how many photos were already added, so a failed chunk can be retried without duplicating earlier ones.

While a selection uploads, the page shows a progress bar and a live row per photo (**Waiting**, **Preparing…** for HEIC/TIFF, **Uploading…**, **Added**, or **Not added**) so the whole selection is visible from the first request instead of appearing to hang until the last batch finishes. Uploads go through `XMLHttpRequest` to report byte progress, and HEIC/TIFF conversions yield to the browser between photos so the page keeps painting.

Each batch is prepared and sent as it is reached rather than converting the whole selection first, and on the order page every saved batch is inserted into the gallery, the **Photo details** table, the photo count and the storage figure straight away (the JSON response carries the new rows). So the order visibly fills up while later photos are still uploading.

A selection of up to 100 photos is accepted in one pick. The dropzone shows small thumbnails for the first 12 selected photos (decoded as 200 px bitmaps, falling back to object URLs), plus a `+N` tile for the rest, and the whole flow logs its steps (picker opened/closed, file counts, HEIC conversions, each upload batch, errors) to the browser console under `[upload]` — no on-page debug box.

```
orders        reference, user_id, paper_size, borderless, color_mode,
              print_quality, notes, admin_note, status, error_message, …
order_photos  order_id, file_name, mime_type, image_data (BLOB),
              thumb_data (BLOB, generated JPEG), file_size,
              image_width, image_height, position, status, epson_job_id, …
```

The order status is derived from its photos:

- every photo `completed` → order `completed`
- any photo `failed` → order `failed`
- accepted with photos still waiting → order `accepted`
- any photo sent or printed → order `printing`
- otherwise → order `pending`

## The photo flow

1. A customer selects up to 100 photos. The browser accepts any common image type and converts HEIC/TIFF with the bundled decoders, validates size, then uploads the selection in chunks of at most 10 (smaller when the host's `post_max_size` requires it). Each photo is decoded again on the server, up to 10 MB.
2. The first chunk creates the order with the chosen print settings; the remaining chunks are sent to `customer/add-photos.php` under the same order. A progress line shows how many photos have been uploaded, and a failed chunk can be retried from where it stopped.
3. Each photo is normalised before storage: JPEG and PNG keep their bytes, while WebP, GIF, BMP, AVIF and TGA are converted to JPEG with GD. HEIC and TIFF are converted in the browser by the bundled decoders; direct uploads of those formats also use Imagick when the server has it. Transparency is flattened onto white. Stored BLOBs in `order_photos.image_data` are therefore always printable, and nothing is written to disk — one file at a time, so a request never holds more than a single photo in memory. A small JPEG preview is generated with GD and stored in `thumb_data`; list pages and galleries load that instead of the full-size photo.
4. An administrator opens the order and presses **Accept & queue**. The order becomes `accepted`; the automatic queue worker sends its photos in batches. The **Send next … now** button remains available as a manual fallback.
5. The queue worker creates **one Epson job per photo** and keeps exactly one sheet on the printer: it sends a photo, waits for Epson to report that job `completed`, then sends the next photo of the same order, working through it until the `PRINT_SEQUENTIAL_SECONDS` budget is spent. A 100-photo order is therefore spread across several cron runs instead of one long request. Only the oldest order with photos left to print is served, and no other order's photos are submitted until every photo of that order is completed (or has failed and needs attention).
6. Epson job status is refreshed in batches too (`STATUS_BATCH_MAX` / `STATUS_BATCH_SECONDS`), and the customer sees the timeline and per-photo status update.

Epson's upload endpoint accepts a single file per job (`File=1.jpg`), so a multi-photo order is sent as several jobs, one after another. Because each photo is its own job, Epson's printable-file limit no longer caps the order size. Each photo records when it was sent to the printer, and the order page shows that elapsed time next to any photo still marked `printing`, so a slow job stands out from a stuck one. The printer capability is cached after the first call (`epson_capability_photo`), so each sheet no longer spends an extra Epson round-trip choosing paper size and quality.

Epson calls are hardened against the host's occasional stalled connections: a status check gives up after 25 s and tries again once, and a dropped connection during a status check is treated as transient. The worker keeps the run alive and retries instead of reporting the whole run as failed, because the sheet is still printing. Failure messages now include cURL's DNS, connect, TLS, first-byte and total timings so a stall can be told apart from a slow printer. When only these connection problems happen, the run records the status `warning` instead of `error`: the dashboard shows an amber *Epson is not answering; the queue is retrying* notice and the worker card reads **Retrying**, so a network blip no longer looks like a broken queue. A photo whose send fails when the connection never opened, or before the start command (job never created or uploaded), is put back to `pending` instead of `failed`, because nothing can have printed yet: the next run retries it without a reprint notice or a red queue alert. Failed photos that are left over can be returned to the queue from the order page with **Retry N failed photos**, which keeps the order's place in the queue instead of re-accepting it.

Photos are sent to Epson exactly as they were uploaded — no resizing, padding, blur or recompression. Epson's photo mode fills the sheet, so a **Borderless** 4 × 6 print of a photo that isn't 3:2 is trimmed slightly at the edges; the **White border** option fits the whole photo inside the paper's margins instead.

Before sending, the order page in admin shows **Print preview** for the next photo — the exact uploaded file Epson receives, with a **Preview** link on every photo row to open the full-size version (`admin/print-preview.php`).

Epson calls are slow from the host, so the client is built to ride that out: every request is pinned to HTTP/1.1 over IPv4, API calls and uploads retry once on a dropped connection before the photo is failed, and uploads get `EPSON_UPLOAD_TIMEOUT` (120 s). Failures carry the cURL code, e.g. `Operation timed out after 45001 milliseconds … (cURL 28)`, which tells a timeout from a refused connection.

## Automatic print queue (cron)

Accepted orders are printed without another click. Add this command to a cPanel cron job running **every minute**:

```bash
/usr/local/bin/php /home/snapprin/public_html/cron/print-queue.php \
  >> /home/snapprin/print-queue.log 2>&1
```

**Surviving Epson outages.** The hosting network intermittently cannot open a connection to `api.epsonconnect.com` / `auth.epsonconnect.com` (cURL 7 or 28 with 0 bytes). Four things keep the queue alive through that: a **20 s connect timeout** instead of 10 s, so TCP's own retransmits get a chance to finish instead of being cut off just before the 15 s retry; a **fail-fast circuit** that opens for 60 s after two consecutive transport failures, so the worker and dashboard stop stacking up timeouts; **adaptive status polling** (5 s normally, backing off to 30 s while Epson is down); and a **stale-tolerant status card** that keeps showing the last known printer state for up to 10 minutes rather than replacing it with an error. Every attempt is logged in a small ring buffer (`epson_request_log`, `epson_request_stats`), shown on **Printer Settings → Epson connection** with a success rate, the last eight attempts and a **Test** button that makes one request with the circuit bypassed. Photos are never lost: a send that fails before the print command returns to `pending`, and a status check that cannot reach Epson is retried without failing the run.

On top of that, outages are tracked as incidents. Every transport failure records `epson_outage_since`, a failure count and the last error; the first successful request afterwards records `epson_outage_recovered_at` and the length. The worker emails the queue alert address once an outage passes `EPSON_OUTAGE_ALERT_MINUTES` (10) with orders waiting, repeats every `EPSON_OUTAGE_REMINDER_MINUTES` (60) while it lasts, and sends one recovery note — all sent after the worker lock is released so a slow mailer cannot block the next run. The dashboard shows a dedicated "Epson Connect has been unreachable for N minutes" banner (waiting orders, failed attempts, whether the alert already went out), and Printer Settings → Epson connection shows the outage start, attempt count, last recovery and how long it lasted. The watchdog stays quiet about `queue_stalled` while an outage is active so nobody gets two emails for the same problem.

Catch-up is automatic and prompt: a send that cannot connect is retried inside the same worker run (up to `PRINT_TRANSPORT_RETRIES`, with 5 s and 10 s pauses, inside the run's time budget), so when Epson comes back mid-run the next photo goes out immediately instead of waiting for the next cron tick. A job already on the printer is still polled with the adaptive interval, and the circuit breaker closes on the first success.

**Printer maintenance.** Admin → **Maintenance** pauses the queue for head cleaning, paper changes or anything else that needs the printer free. While paused the worker still records the sheet already printing, but sends nothing new, `Send now`/`Print` are refused, and the watchdog holds its queue emails; orders keep their place. Epson Connect exposes no maintenance commands (its API has jobs, device info, capabilities, cancel and notification only), so cleaning itself runs from the printer panel or Epson Smart Panel — the page gives the ET-2926 steps (nozzle check, head cleaning, power cleaning), and **Admin → Service Stats → Printer right now** shows the live printer status while you work.

Each run:

- prints **one sheet at a time** and works the queue in **acceptance order**: `orders.accepted_at` (added automatically to existing databases) records when an order was accepted, and the front order is the one accepted longest ago. The worker sends its next photo only when nothing is already on the printer, waits for that job to report `completed`, then sends the following photo, so sheets come out in order and a second order is never touched until the first has no photos pending or printing. The next photo's Epson job is created and uploaded **while the current sheet is printing** (`preparePhoto()` + `startPhoto()`), so when Epson reports the sheet finished only the start command is sent and the printer does not spend 20–30 s waiting for an upload between sheets. It works through the front order until the `PRINT_SEQUENTIAL_SECONDS` (900 s) budget is spent, polling every `PRINT_POLL_SECONDS` (5 s for the first `PRINT_POLL_FAST_SECONDS` of a sheet, then `PRINT_POLL_SLOW_SECONDS` = 20 s through the long middle, then 5 s again from `PRINT_POLL_TAIL_START_SECONDS` until `PRINT_POLL_TAIL_END_SECONDS` as the sheet nears its usual finish, which keeps the wait between sheets to seconds without multiplying connections through a fragile route to Epson) and only sleeping the part of that interval the Epson call did not already use, then continues on the next cron tick — a long slice lets one tick print several sheets instead of leaving the printer idle between ticks. An order left with only failed photos no longer blocks the ones behind it — it appears under **Needs attention** instead. **Send now** / **Print** sends a single photo and is refused while any photo is still printing;
- checks the status of Epson jobs already `printing` and marks each order `completed` automatically as soon as its last photo finishes, with no admin click;
- releases a **stuck print**: a photo the printer has left in `printing` for more than `PRINT_JOB_STUCK_MINUTES` (45 minutes) becomes `failed` with a message saying it was stuck, so the order shows the retry banner and the same photo can be sent again with **Print** or **Retry & queue**. This also stops a job that never reports back from blocking the status checks of newer photos. The clock starts when the photo is handed to Epson (`order_photos.print_started_at`, added automatically to existing databases) and the order page shows how many minutes a printing photo has been running. An admin who does not want to wait can press **Release** on a printing photo to do the same thing by hand, then **Print** it again once the printer is ready;
- closes any order left `accepted` or `printing` with no photos at all (for example after every photo was removed) and records why on its timeline, so an empty order cannot sit in the queue for ever;
- claims each photo with a MySQL named lock and re-checks its status after claiming, so two cron runs or a cron run and **Send now** cannot send the same photo at once;
- leaves accepted orders untouched while the printer is unlinked, so they start automatically once it is connected.

The worker is CLI-only and inside the protected `cron/` folder, so it cannot be triggered from the browser. The log file records each batch and any failure. If an order fails, fix the printer problem and press **Retry & queue** on the order to return its failed photos to the queue.

### Heartbeat and stalled-queue alerts

The worker writes its heartbeat and last result to the database on every run. A separate watchdog checks them, so it can still alert when the worker itself has stopped. Add a second cPanel cron job running **every five minutes**:

```bash
/usr/local/bin/php /home/snapprin/public_html/cron/watchdog.php \
  >> /home/snapprin/queue-watchdog.log 2>&1
```

The watchdog emails when:

- the worker has not run for 10 minutes, or has never run while accepted orders are waiting;
- the last worker run ended with an error;
- an accepted order has been waiting for more than 20 minutes without progress;
- accepted orders are waiting while the printer is not linked.

Alerts go to **Admin → Site Settings → Queue alert email**, falling back to the support email and then the first admin account. It sends one alert per incident, repeats at most hourly while the problem continues, and sends a recovery email when the queue is healthy again.

An order becomes `printing` as soon as its first photo is handed to the printer (not only when a photo finishes), so the dashboard's **Printing now** count shows the active order while the rest of it is still queued; the count also catches any order that has a photo in `printing` even if its status is stale. The card names that order and its position (`PF-TYAWD7 · photo 17 of 50`), read from the photo the worker has on the printer, so it advances by itself as sheets finish. Both the count and that hint are the queue's own record and say so (**Queue record · …**), followed by Epson's own last reading and its age (**Epson: Printing · 2 min ago**), read from the status cache with no Epson call on a dashboard load; **Admin → Printer Settings** shows what Epson reports live (**Printer (Epson)**, "Epson Connect · checked …"), so the two can differ for a moment while a sheet finishes — the dashboard updates on the worker's next pass, Printer Settings the moment Epson answers. The live queue list is ordered like the worker: printing first, then the order accepted longest ago.

**Admin → Dashboard** opens with the Epson Connect card, its last cached reading and a **Refresh printer state** button (a page load never calls Epson), then the **Ink** and **Paper** cards. **Admin → Printer Settings** shows the live **Printer (Epson)** card: it asks Epson on every load and shows what the printer is doing right now — **Printing · photo 4 of 12 · 3 min on the printer**, **Sent, waiting for the printer**, **Out of paper**, **Paper jam**, **Out of ink**, **Idle**, or **Epson unreachable** with the error. If Epson does not answer, the last known reading stays on the card marked stale instead of going blank.

**Admin → Service Stats** shows the worker state, last run, watchdog state, oldest accepted order and the printer's last known state (**Printer right now**). When anything fails — a rejected photo, or a print the queue released as stuck — the dashboard opens with a red banner counting the orders that need reprinting, followed by a **Needs attention** list of those orders (reference, customer, failed photo count and the printer's message) with an **Open** button on each and a link to **Admin → Orders → Failed**. A run holds the worker lock for a whole send slice (`PRINT_SEQUENTIAL_SECONDS`, 15 minutes) plus the status batch, which is longer than the 10-minute stale threshold. So the worker publishes a slice deadline when it starts and clears it when it finishes: while that deadline is in the future Service Stats shows **Working** and neither the banner nor the watchdog treats the run as stopped.

A deadline only says how long a run may take, not that it is still doing anything, so the run also writes a liveness timestamp — at every loop iteration, while waiting between status checks, and from inside slow Epson uploads/status calls (throttled to one write every few seconds). If a run is inside its slice but that timestamp is older than `PRINT_QUEUE_LIVENESS_SECONDS` (60 s), the run is treated as hung: Service Stats shows **Stopped** and the watchdog alerts, without waiting for the deadline. **Admin → Service Stats → Sign of life** shows how long ago the last heartbeat was. A crashed run clears nothing, so its liveness goes stale within a minute.


### Paper, ink and jams

When a job reports `media_empty`, `marker_supply_empty` or `media_jam`, the queue stops sending photos instead of feeding the next one into the same problem. The operator is emailed once, reminded hourly, and the dashboard and **Admin → Maintenance** show the hold with a **Clear and continue** button (pressing **Resume** clears it too). The dashboard raises the paper stop as a red alert at the top — **The printer is out of photo paper** or **Paper jam — printing has stopped** — with a **Load paper & clear the hold** button, and the Paper card turns amber at the same time. The alert comes from the queue's hold or, before the next worker pass, from a recent Epson reading naming the same stop; a reading older than ten minutes is ignored so paper that has since been loaded cannot raise a false alarm. The photo already on the printer keeps being tracked; nothing is marked failed because of it. Clearing the hold does not clear the printer — load the paper or change the ink first.

### Customer estimates

The customer order page shows a finish estimate and the orders list shows the time left on each queued order. It is arithmetic on real measurements: seconds per sheet come from `order_photos.print_started_at` → `printed_at` over the last week (fallback 185 s), and the queue ahead is walked in the worker's own order, with the sheet currently printing counted only for the time it has left.

Print sizes are mapped to Epson paper IDs at request time: `ps_4x6` when the printer advertises it, otherwise `ps_kg` (102 × 153 mm) and finally `ps_l`. 5 × 7 uses `ps_2l`/`ps_5x8` and A4 uses `ps_a4`, always checked against the printer's advertised capabilities and falling back to a supported borderless or quality mode.

## Backups

The whole business — orders, customers, settings and every photo — lives in one MySQL database on one shared host, so a nightly copy is kept on the host itself. Add a cPanel cron job:

```bash
/usr/local/bin/php /home/snapprin/public_html/cron/backup.php \
  >> /home/snapprin/backup.log 2>&1
```

It writes a restorable SQL dump (schema and every table, with the `image_data`/`thumb_data` BLOB columns excluded, since the photos themselves are far too large to duplicate nightly) and gzips it into `backups/` beside `public_html` — outside the web root, so the files cannot be fetched over HTTP and a deploy that copies into `public_html` cannot overwrite them. The folder also gets an Apache `deny` file as a second line of defence, and only the newest `BACKUP_KEEP_FILES` (10) dumps are kept. **Admin → Backup Settings** shows the last run, file, size, folder and how many dumps are held, and can run one on demand. Restore with `gunzip` + `mysql <db> < db-….sql`; the dump's header says what it does and does not contain.

## Database

The live site uses the MySQL database created in cPanel. The non-secret connection defaults (host `localhost`, port, database and user) are in `includes/config.php`. When the `users` table is missing, the installer creates the tables, default settings and the two demo accounts.

MySQL is the only database driver. If the password file is missing or the connection fails, `includes/db.php` stops the request with a **503** page and logs the technical error; it never falls back to another data store. The MySQL schema is embedded in `includes/installer.php`, so there are no schema files to import or maintain.

The password is **not** in `includes/config.php`. Copy `includes/config.local.example.php` to `includes/config.local.php` on the server and fill in `pass`; the local file is gitignored and overrides the defaults. This keeps `config.php` safe to publish. Deployments merge files with `cp`, so an existing `config.local.php` is preserved.

## Connecting the Epson ET-2926

Printing runs through the Epson Connect API v2 (device authorisation, OAuth2).

1. Register the printer with Epson Connect, then sign in at <https://developer.epsonconnect.com/>.
2. Create an app and copy the **Client ID**, **Client Secret** and **API Key**.
3. Add your Redirect URI to that app — the site shows the exact address on the settings page, typically `https://your-site.example/admin/epson-callback.php`.
4. In **Admin → Printer Settings → Epson Connect API v2**, paste the credentials and save.
5. Press **Connect Epson ET-2926**, sign in with your Epson account and pick the printer.
6. Open any photo order and press **Accept & queue**; the cron worker uploads each photo as its own job, one sheet at a time. The page shows the job status and a manual **Send now** fallback that sends one photo per click.

Until the printer is linked, orders can still be accepted, managed and marked printed by hand; accepted orders wait for the printer.

### Ink levels over SNMP (shop-network bridge)

Epson Connect API v2 returns a device's model, serial and link state but no tank levels, so the Ink card cannot read them from Epson. The ET-2926 does publish its own Printer MIB over SNMPv2c, so a small script on an always-on machine **on the same network as the printer** reads `prtMarkerSuppliesLevel` and posts the four percentages to the site.

1. **Admin → Printer Settings → Ink bridge (SNMP) → Create token.** Copy the token when it appears: only its SHA-256 hash is stored, so it cannot be shown again. Replacing it stops an old bridge until it is updated.
2. Copy `tools/snmp.php` and `tools/ink-bridge.php` to the machine beside the printer. It needs PHP 8.1 CLI only — the SNMP codec is bundled, so there is no `php-snmp` extension and no net-snmp binary to install.
3. Test the read without posting anything:

   ```bash
   php ink-bridge.php --host 192.168.1.42 --dry-run
   ```

4. Post every five minutes (the site marks a reading older than `PRINTER_INK_STALE_MINUTES`, 20, as stale):

   ```bash
   php ink-bridge.php \
     --url https://snapprints.co.uk/printer-ink.php \
     --token "$INK_FEED_TOKEN" \
     --host 192.168.1.42 \
     --every 300
   ```

   Run it under systemd, `screen`/`tmux`, or a cron entry instead of `--every` if you prefer; without `--every` it posts once and exits.

Options (or their environment variables): `--host`/`INK_PRINTER_HOST`, `--community`/`INK_SNMP_COMMUNITY` (default `public`), `--port`, `--timeout`, `--retries`, `--model`, `--url`/`INK_FEED_URL`, `--token`/`INK_FEED_TOKEN`, `--every`, `--dry-run`, `--v1` (SNMPv1 instead of v2c). The bridge only ever sends GET/GETNEXT, so the printer is never changed.

The bridge walks `1.3.6.1.2.1.43.11.1.1.6.1` (supply descriptions) to find each tank's index, then reads `.8.1` (max capacity) and `.9.1` (level) for that index and converts levels to percentages, so a level of 27 with a capacity of 60 reads 45%. The Printer MIB sentinels for unknown (`-2`) and some-remaining (`-3`) have no number and show as —. If the printer publishes no useful descriptions, the first four supply indexes are taken as black, cyan, magenta and yellow.

The Ink card shows each tank's percentage, the lowest tank in the headline (amber at or below `PRINTER_INK_LOW_PERCENT`, 10), and when the reading was taken. A reading older than 20 minutes is labelled stale rather than passed off as live. The feed (`public/printer-ink.php`) accepts `POST` only, requires the token as `Authorization: Bearer …` (or `X-Ink-Token`), accepts only 0–100 numbers and stores the reading with its timestamp in the settings table. Nothing is exposed to customers.

`tools/snmp-sim.php` is a local SNMP agent for testing the bridge and the feed without a printer:

```bash
php tools/snmp-sim.php --port 1161 --levels 51,30,12,3 --max 60 &
php tools/ink-bridge.php --host 127.0.0.1 --port 1161 --dry-run
```

## Security notes

- Passwords use `password_hash()`, sessions use `HttpOnly` + `SameSite` cookies, all forms are CSRF protected.
- Every query uses prepared statements; output is escaped with `e()`.
- Photos are stored in the database and served only through authenticated endpoints (`customer/photo.php`, `admin/photo.php`); `includes/`, `cron/` and `tools/` are blocked with `.htaccess`, and the cron worker and both tools refuse HTTP requests themselves.
- The ink feed (`public/printer-ink.php`) is not a session endpoint: it requires the bridge token, compares its SHA-256 hash with `hash_equals()`, accepts `POST` and JSON numbers only, and is the only public endpoint that writes without a login.
- Customers can only ever see their own photos and orders. Uploads are limited to common image names/MIME types, decoded by GD or Imagick, normalised to JPEG/PNG, and capped by a per-file size limit in `includes/config.php`.
- Admin: deleting a customer or an order also deletes all of its stored photos. On the order page an admin can tick one or more photos and remove them together; the order status is recalculated from what is left and the removal is written to the order timeline. Removing the last photo deletes the empty order. An order that has no photos shows an empty state on both the admin and customer pages, cannot be accepted or sent to the printer, and the customer can still add photos to it while it is `pending`.

## Customising

- Site name, support email and queue alert address: **Admin → Site Settings**. Default print size/quality and the queue pause switch: **Admin → Service Settings**. Queue monitoring, printer state and service health: **Admin → Service Stats**. Storage limits and database size: **Admin → Site Settings**. The Epson Connect card with the ink and paper cards: **Admin → Dashboard** (Epson publishes no tank levels; the optional SNMP bridge fills the Ink card — see **Ink levels over SNMP**); the link, credentials and Epson connection health: **Admin → Printer Settings**. Nightly dumps: **Admin → Backup Settings**. New orders default to **High** quality: on the ET-2926, Standard can leave faint banding lines on photo paper, so High is the right default for this service. An administrator can still lower it per order from the order page while it is unprinted.
- An administrator can change an order's print quality from the order page while it is still unprinted (new, queued, printing or failed). The change is written to the order timeline; photos already sent to Epson keep the quality they were sent with, and everything sent afterwards uses the new one. Printed and cancelled orders keep the quality they were printed with.
- Photos per order and per upload batch: `MAX_PHOTOS_PER_ORDER` (default 100) and `MAX_PHOTOS_PER_BATCH` (default 10) in `includes/config.php`. The browser chains batches automatically.
- Per-photo upload limit: `MAX_UPLOAD_MB` in `includes/config.php` (Epson allows 10 MB for photo mode). The effective limit is the smaller of this and PHP's `upload_max_filesize` / `post_max_size`.
- Status work: `STATUS_BATCH_MAX` and `STATUS_BATCH_SECONDS` in `includes/config.php`. One-at-a-time printing waits up to `PRINT_SEQUENTIAL_SECONDS` per cron run and polls the printer every `PRINT_POLL_SECONDS`.
- Storage: photos live in the MySQL database as BLOBs, so the database grows with every upload. Each order is capped at `MAX_ORDER_STORAGE_MB` (default 5000 MB / 4.9 GB; `0` disables the cap). Photos are inserted one at a time; thumbnails are capped at a 480 px long edge and skipped for images above 30 MP or when GD is unavailable. Admin → Site Settings shows the current database size and the storage limits.
- Sessions: stored in the `sessions` table of the same database instead of PHP's per-instance files, so sign-in and CSRF checks stay valid when a host routes requests to several instances. The table is created automatically on existing installs and expired rows are pruned.
- PHP limits: `public/.user.ini` and the LiteSpeed `lsapi_module` block in `.htaccess` request 12 MB per file and a 128 MB POST. The app still caps each photo at `MAX_UPLOAD_MB` (10 MB), and the UI shows whichever limit the server actually applies.
- If PHP discards an oversized POST, the upload pages detect it and show a friendly "smaller batch" message instead of a CSRF error.
- Styling: `public/css/base.css` holds the tokens (colours, radii, shadows); `site.css` is the public website and `dashboard.css` the signed-in areas.
- Photo formats: GD handles JPG, PNG, WebP, GIF, BMP, AVIF, TGA and friends; the bundled `heic2any` and `UTIF.js` decoders convert HEIC/TIFF in the browser, so no PHP extension is required. Imagick is used as a server-side fallback when available. Both bundled libraries are MIT licensed — see `public/js/vendor/*.LICENSE*`.

## Deploying

Every push to `main` runs `.github/workflows/deploy.yml`, which asks cPanel to pull the repository and run `.cpanel.yml` (copying the site into `public_html`). The workflow then waits for the footer version to appear on <https://snapprints.co.uk>, so a failed or incomplete deployment is visible in the Actions tab.

Add these under **GitHub → Settings → Secrets and variables → Actions → New repository secret**:

| Secret | Value |
| --- | --- |
| `CPANEL_HOST` | cPanel server hostname, without `https://` or a port (use the hostname in your cPanel address bar, e.g. `server123.example.com`) |
| `CPANEL_USERNAME` | cPanel account username |
| `CPANEL_API_TOKEN` | token from **cPanel → Security → Manage API Tokens** |
| `CPANEL_REPO_ROOT` | repository path from **cPanel → Git Version Control**, e.g. `/home/snapprin/repositories/Epson-Print` |
| `CPANEL_PORT` | optional; defaults to `2083` |

Bump `APP_VERSION` in `includes/config.php` with each release; the workflow checks that exact version on the live site.
