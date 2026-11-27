# SnapPrints relay

A small Cloudflare Worker with two jobs:

1. **Callback inbox** — Epson's print-job notifications are posted to `/hook`.
   The shared host's bot protection answers those POSTs with a "verifying you
   are not a robot" page before PHP runs, so Epson's callbacks never reach the
   site. Epson posts here instead, and the print worker pulls the events
   (`relay_pull_notifications()` in `includes/functions.php`).
2. **Backup vault** — `cron/backup.php` puts the nightly database dump and the
   photos of orders that have not printed yet into R2 (`/vault/...`).

Everything requires the bearer token. Without `RELAY_TOKEN` set, every route
answers 404, including `/hook`.

## Routes

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/health` | Liveness, no auth |
| POST | `/hook?token=…` | Epson callback inbox (returns 200 quickly) |
| GET | `/hooks?after=0&limit=25` | Pending events for the app |
| POST | `/hooks/ack` `{"ids":[…]}` | Drop delivered events |
| PUT | `/vault/<key>` | Store a backup object (≤ 25 MB) |
| GET | `/vault/<key>` | Read a backup object |

## Setup

1. `npx wrangler dev --local` here, then:
   ```bash
   curl -sS localhost:8787/health
   curl -sS -X POST 'localhost:8787/hook?token=local-dev-token' -d '{"Param":{"JobId":"x","JobStatus":{"status":"completed"}}}'
   curl -sS -H 'Authorization: Bearer local-dev-token' 'localhost:8787/hooks'
   ```
2. Deploy with the Freebuff Sites deployer (`deploy.json`), then set the secret:
   Sites → the site → Secrets → `RELAY_TOKEN`.
3. Copy the same value into Admin → Settings → Relay on the site. The value is
   the token in the existing webhook URL, shown there.
4. Point Epson at the relay with Admin → Settings → Relay → *Point Epson at the
   relay*. That registers `https://<relay>/hook?token=…` as the callback URI.

## Restore

* Database: `GET /vault/backup/<newest>.sql.gz` with the token, `gunzip`, then
  `mysql <database> < db-….sql`. The dump has schema and rows; image columns are
  excluded by design.
* Photos: `photo/<id>-<hash>.bin` holds the original upload for that photo id.
