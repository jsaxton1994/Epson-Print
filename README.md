# PrintFree

A completely free **photo** printing service for an **Epson ET-2926**, built in plain PHP with a database, and powered by the **Epson Connect API v2**.

Customers upload up to **10 JPG or PNG photos per order**, choose a print size (4 × 6, 5 × 7 or A4), borderless or white border, colour and quality. Every photo prints once — there is no copies setting. Administrators see the queue, send the photos straight to the printer (or mark them printed manually), and manage customers. No payments, allowances or credits anywhere.

Demo accounts:

| Role     | Email             | Password |
| -------- | ----------------- | -------- |
| Customer | `customer@me.com` | `123`    |
| Admin    | `admin@me.com`    | `123`    |

---

## Requirements

- PHP 8.1 or newer with `pdo_sqlite` (default) **or** `pdo_mysql`, plus `curl`, `fileinfo`, `mbstring` and `gd`
- Apache with `mod_rewrite` (not required) or Nginx / PHP's built-in server
- Write access to `storage/`

## Quick start

1. Upload the folder to your host, or run it locally:

   ```bash
   php -S 127.0.0.1:8080 -t public
   ```

   The web root is `public/`. Point Apache, Nginx or your host (including Wasmer) at that folder. If the project root is the document root instead, the bundled `.htaccess` sends visitors to `public/index.php` and links automatically include the `/public/` prefix.

2. Open the site. The first request creates the database, tables and demo data (sample photos included) automatically.
3. Sign in with one of the demo accounts above.

The database file is `storage/database.sqlite`. Delete it to start over with fresh demo data.

## Folder structure

```
public/              web root (landing, sign in, register, assets)
public/css/          base.css, site.css, dashboard.css
public/js/           main.js, forms.js, dashboard.js
public/img/          favicon and landing-page sample photo
public/admin/        admin area (dashboard, orders, customers, settings)
public/customer/     customer area (dashboard, new order, orders, profile)
includes/            config, database, session, auth, Epson client, layout
                      (outside the web root)
database/            SQLite + MySQL schema files and demo photos
storage/             SQLite database, including the stored photos
index.php            redirects to the public site when the root is the docroot
```

## Orders and photos

An order is a print header (customer, size, finish, colour, quality, notes) with **1–10 photos** attached. Each photo gets its own row in `order_photos`, its own status and its own Epson job id, so one bad file does not mark the whole order as printed.

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
- any photo sent or printed → order `printing`
- otherwise → order `pending`

## The photo flow

1. A customer uploads 1–10 JPGs or PNGs (validated by MIME type, extension and real image dimensions via `getimagesize()`), each up to 10 MB.
2. They pick a print size — **4 × 6** is the default — plus borderless or white border, colour and quality. All photos in an order share those settings.
3. Each photo is stored as a BLOB in `order_photos.image_data` — nothing is written to disk — one file at a time, so a 10-photo order never holds more than a single photo in memory. A small JPEG preview is generated with GD and stored in `thumb_data`; list pages and galleries load that instead of the full-size photo.
4. An administrator opens the order and presses **Print**. The site creates **one Epson job per photo**, uploads it in `photo` print mode and starts it, recording the job id against that photo.
5. Epson job status is polled from the order page, and the customer sees the timeline and per-photo status update.

The 10-photo cap matches Epson's printable-file limit for a print submission. Epson's upload endpoint accepts a single file per job (`File=1.jpg`), so a multi-photo order is sent as several jobs, one after another.

Print sizes are mapped to Epson paper IDs at request time: `ps_4x6` when the printer advertises it, otherwise `ps_kg` (102 × 153 mm) and finally `ps_l`. 5 × 7 uses `ps_2l`/`ps_5x8` and A4 uses `ps_a4`, always checked against the printer's advertised capabilities and falling back to a supported borderless or quality mode.

## Database

SQLite needs no setup, which is handy for a free host: the site keeps everything in `storage/database.sqlite`.

For MySQL, set these environment variables (Railway, Render, Heroku, Docker, or your host's control panel):

| Variable | Example |
| --- | --- |
| `DB_HOST` | `db.example.com` |
| `DB_PORT` | `3306` |
| `DB_NAME` | `printfree` |
| `DB_USERNAME` | `printfree` |
| `DB_PASSWORD` | your database password |

When `DB_HOST` is set the site connects to MySQL and installs its tables and demo data on the first load. **If MySQL cannot be reached, it falls back to SQLite automatically**, so the site still runs locally or on a host without a database. Set `DB_DRIVER=sqlite` to skip MySQL entirely. Admin → Settings → Storage shows the driver actually in use.

Credentials stay in the environment, never in the repository. Both schemas live in `database/` if you prefer to import them by hand.

## Connecting the Epson ET-2926

Printing runs through the Epson Connect API v2 (device authorisation, OAuth2).

1. Register the printer with Epson Connect, then sign in at <https://developer.epsonconnect.com/>.
2. Create an app and copy the **Client ID**, **Client Secret** and **API Key**.
3. Add your Redirect URI to that app — the site shows the exact address on the settings page, typically `https://your-site.example/admin/epson-callback.php`.
4. In **Admin → Settings → Epson Connect API v2**, paste the credentials and save.
5. Press **Connect Epson ET-2926**, sign in with your Epson account and pick the printer.
6. Open any photo order and press **Print**. Each photo is uploaded and started as its own job, then the page shows the job status.

Until the printer is linked, orders can still be managed and marked printed by hand.

## Security notes

- Passwords use `password_hash()`, sessions use `HttpOnly` + `SameSite` cookies, all forms are CSRF protected.
- Every query uses prepared statements; output is escaped with `e()`.
- Photos are stored in the database and served only through authenticated endpoints (`customer/photo.php`, `admin/photo.php`); `includes/`, `database/` and `storage/` are blocked with `.htaccess`.
- Customers can only ever see their own photos and orders. Uploads are validated by extension, MIME type (`image/jpeg` / `image/png`), `getimagesize()` and a per-file size limit in `includes/config.php`.
- Admin: deleting a customer or an order also deletes all of its stored photos.

## Customising

- Site name, support email, default print size/quality and the queue pause switch: **Admin → Settings**.
- Photos per order: `MAX_PHOTOS_PER_ORDER` in `includes/config.php` (default 10, Epson's printable-file limit).
- Per-photo upload limit: `MAX_UPLOAD_MB` in `includes/config.php` (Epson allows 10 MB for photo mode).
- Storage: photos live in the database as BLOBs, so the SQLite file (or MySQL database) grows with every upload — budget up to 10 MB per photo, 10 photos per order, plus a small thumbnail each. Photos are inserted one at a time; thumbnails are capped at a 480 px long edge and skipped for images above 30 MP or when GD is unavailable.
- Styling: `public/css/base.css` holds the tokens (colours, radii, shadows); `site.css` is the public website and `dashboard.css` the signed-in areas.
