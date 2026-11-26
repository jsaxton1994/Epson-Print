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
index.php            redirects to the public site when the root is the docroot
```

## Orders and photos

An order is a print header (customer, size, finish, colour, quality, notes) with **1–100 photos** attached. Each upload request carries at most **10 photos**, and the browser chains those requests automatically for a large selection. Each photo gets its own row in `order_photos`, its own status and its own Epson job id, so one bad file does not mark the whole order as printed.

While an order is still `pending`, the customer can add more photos from the order page. The endpoint checks ownership, CSRF, the order status, the photo count and the order's storage budget, inserts each batch one photo at a time, and rolls that batch back if any file fails. The browser remembers how many photos were already added, so a failed chunk can be retried without duplicating earlier ones.

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
5. The queue worker creates **one Epson job per photo**, sending up to `PRINT_BATCH_MAX` photos per order with a `PRINT_BATCH_SECONDS` wall-clock budget. A 100-photo order is therefore spread across several cron runs instead of one long request.
6. Epson job status is refreshed in batches too (`STATUS_BATCH_MAX` / `STATUS_BATCH_SECONDS`), and the customer sees the timeline and per-photo status update.

Epson's upload endpoint accepts a single file per job (`File=1.jpg`), so a multi-photo order is sent as several jobs, one after another. Because each photo is its own job, Epson's printable-file limit no longer caps the order size.

## Automatic print queue (cron)

Accepted orders are printed without another click. Add this command to a cPanel cron job running **every minute**:

```bash
/usr/local/bin/php /home/snapprin/public_html/cron/print-queue.php \
  >> /home/snapprin/print-queue.log 2>&1
```

Each run:

- sends pending photos from up to five `accepted` orders, oldest first, within the existing print batch/time limits;
- checks the status of Epson jobs already `printing` and marks each order `completed` automatically as soon as its last photo finishes, with no admin click;
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

Alerts go to **Admin → Settings → Queue alert email**, falling back to the support email and then the first admin account. It sends one alert per incident, repeats at most hourly while the problem continues, and sends a recovery email when the queue is healthy again.

The dashboard also shows the worker state, last run, watchdog state and oldest accepted order. If both cron jobs are managed by the same cPanel account, a full cron-service outage can stop both; use an external uptime monitor for that case.

Print sizes are mapped to Epson paper IDs at request time: `ps_4x6` when the printer advertises it, otherwise `ps_kg` (102 × 153 mm) and finally `ps_l`. 5 × 7 uses `ps_2l`/`ps_5x8` and A4 uses `ps_a4`, always checked against the printer's advertised capabilities and falling back to a supported borderless or quality mode.

## Database

The live site uses the MySQL database created in cPanel. The non-secret connection defaults (host `localhost`, port, database and user) are in `includes/config.php`. When the `users` table is missing, the installer creates the tables, default settings and the two demo accounts.

MySQL is the only database driver. If the password file is missing or the connection fails, `includes/db.php` stops the request with a **503** page and logs the technical error; it never falls back to another data store. The MySQL schema is embedded in `includes/installer.php`, so there are no schema files to import or maintain.

The password is **not** in `includes/config.php`. Copy `includes/config.local.example.php` to `includes/config.local.php` on the server and fill in `pass`; the local file is gitignored and overrides the defaults. This keeps `config.php` safe to publish. Deployments merge files with `cp`, so an existing `config.local.php` is preserved.

## Connecting the Epson ET-2926

Printing runs through the Epson Connect API v2 (device authorisation, OAuth2).

1. Register the printer with Epson Connect, then sign in at <https://developer.epsonconnect.com/>.
2. Create an app and copy the **Client ID**, **Client Secret** and **API Key**.
3. Add your Redirect URI to that app — the site shows the exact address on the settings page, typically `https://your-site.example/admin/epson-callback.php`.
4. In **Admin → Settings → Epson Connect API v2**, paste the credentials and save.
5. Press **Connect Epson ET-2926**, sign in with your Epson account and pick the printer.
6. Open any photo order and press **Accept & queue**; the cron worker uploads each photo as its own job. The page shows the job status and the manual **Send now** fallback.

Until the printer is linked, orders can still be accepted, managed and marked printed by hand; accepted orders wait for the printer.

## Security notes

- Passwords use `password_hash()`, sessions use `HttpOnly` + `SameSite` cookies, all forms are CSRF protected.
- Every query uses prepared statements; output is escaped with `e()`.
- Photos are stored in the database and served only through authenticated endpoints (`customer/photo.php`, `admin/photo.php`); `includes/` and `cron/` are blocked with `.htaccess`, and the cron worker refuses HTTP requests itself.
- Customers can only ever see their own photos and orders. Uploads are limited to common image names/MIME types, decoded by GD or Imagick, normalised to JPEG/PNG, and capped by a per-file size limit in `includes/config.php`.
- Admin: deleting a customer or an order also deletes all of its stored photos. On the order page an admin can remove a single photo; the order status is recalculated from what is left and the removal is written to the order timeline. Removing the last photo deletes the empty order.

## Customising

- Site name, support email, default print size/quality and the queue pause switch: **Admin → Settings**.
- Photos per order and per upload batch: `MAX_PHOTOS_PER_ORDER` (default 100) and `MAX_PHOTOS_PER_BATCH` (default 10) in `includes/config.php`. The browser chains batches automatically.
- Per-photo upload limit: `MAX_UPLOAD_MB` in `includes/config.php` (Epson allows 10 MB for photo mode). The effective limit is the smaller of this and PHP's `upload_max_filesize` / `post_max_size`.
- Batch and status work: `PRINT_BATCH_MAX`, `PRINT_BATCH_SECONDS`, `STATUS_BATCH_MAX` and `STATUS_BATCH_SECONDS` in `includes/config.php`.
- Storage: photos live in the MySQL database as BLOBs, so the database grows with every upload. Each order is capped at `MAX_ORDER_STORAGE_MB` (default 5000 MB / 4.9 GB; `0` disables the cap). Photos are inserted one at a time; thumbnails are capped at a 480 px long edge and skipped for images above 30 MP or when GD is unavailable. Admin → Settings shows the current database size.
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
