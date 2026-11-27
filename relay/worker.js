/**
 * SnapPrints relay.
 *
 * Two jobs, both of which need a public HTTPS origin that is not the shared
 * host:
 *
 *  1. Callback inbox. The host's bot protection answers non-browser POSTs
 *     (Epson's job notifications included) with a 200 challenge page, so the
 *     callback never reaches PHP. Epson posts here instead and the print
 *     worker pulls the events on its own schedule (outbound works fine).
 *
 *  2. Backup vault. The nightly database dump and the photos of orders that
 *     have not printed yet are stored in R2, off the host.
 *
 * Auth is one bearer token, also accepted as ?token= so Epson's callback URL
 * can carry it. Nothing is public: without the token every route answers 404.
 */

const JSON_HEADERS = {
  'content-type': 'application/json; charset=utf-8',
  'cache-control': 'no-store',
};

let schemaReady = null;

function json(body, status = 200) {
  return new Response(JSON.stringify(body), { status, headers: JSON_HEADERS });
}

function equal(a, b) {
  if (typeof a !== 'string' || typeof b !== 'string' || a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i += 1) diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return diff === 0;
}

function authorized(request, env) {
  const token = String(env.RELAY_TOKEN || '').trim();
  if (!token) return false;

  const url = new URL(request.url);
  const candidates = [
    url.searchParams.get('token'),
    String(request.headers.get('authorization') || '').replace(/^Bearer\s+/i, ''),
    request.headers.get('x-relay-token'),
  ];

  return candidates.some((value) => value && equal(String(value).trim(), token));
}

function ensureSchema(env) {
  if (!schemaReady) {
    schemaReady = env.DB.prepare(
      `CREATE TABLE IF NOT EXISTS hook_events (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         received_at TEXT NOT NULL,
         ip TEXT,
         content_type TEXT,
         body TEXT NOT NULL
       )`
    ).run().catch((error) => {
      schemaReady = null;
      throw error;
    });
  }

  return schemaReady;
}

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    const path = url.pathname.replace(/\/+$/, '') || '/';

    if (path === '/health') {
      return json({ ok: true, configured: Boolean(env.RELAY_TOKEN), time: new Date().toISOString() });
    }

    if (!authorized(request, env)) {
      // Same answer as a missing route: do not confirm what lives here.
      return json({ ok: false }, 404);
    }

    if (path === '/hook') {
      if (request.method !== 'POST' && request.method !== 'GET') {
        return json({ ok: false, error: 'method not allowed' }, 405);
      }

      const body = request.method === 'POST'
        ? (await request.text()).slice(0, 32768)
        : '';
      const ip = request.headers.get('cf-connecting-ip') || request.headers.get('x-forwarded-for') || '';

      await ensureSchema(env);
      const inserted = await env.DB.prepare(
        'INSERT INTO hook_events (received_at, ip, content_type, body) VALUES (?, ?, ?, ?)'
      ).bind(new Date().toISOString(), ip.slice(0, 60), (request.headers.get('content-type') || '').slice(0, 80), body).run();

      return json({ ok: true, id: inserted.meta?.last_row_id ?? null });
    }

    if (path === '/hooks') {
      if (request.method !== 'GET') return json({ ok: false }, 405);

      await ensureSchema(env);
      const after = Number.parseInt(url.searchParams.get('after') || '0', 10) || 0;
      const limit = Math.min(100, Math.max(1, Number.parseInt(url.searchParams.get('limit') || '25', 10) || 25));

      const rows = await env.DB.prepare(
        'SELECT id, received_at, ip, content_type, body FROM hook_events WHERE id > ? ORDER BY id ASC LIMIT ?'
      ).bind(after, limit).all();

      return json({ ok: true, events: rows.results || [] });
    }

    if (path === '/hooks/ack') {
      if (request.method !== 'POST') return json({ ok: false }, 405);

      let payload = {};
      try {
        payload = await request.json();
      } catch {
        return json({ ok: false, error: 'invalid json' }, 400);
      }

      const ids = Array.isArray(payload.ids)
        ? payload.ids.map((id) => Number.parseInt(id, 10)).filter((id) => Number.isInteger(id) && id > 0).slice(0, 200)
        : [];

      if (ids.length > 0) {
        const placeholders = ids.map(() => '?').join(',');
        await ensureSchema(env);
        await env.DB.prepare(`DELETE FROM hook_events WHERE id IN (${placeholders})`).bind(...ids).run();
      }

      return json({ ok: true, deleted: ids.length });
    }

    if (path.startsWith('/vault/')) {
      const key = decodeURIComponent(path.slice('/vault/'.length));

      if (!key || key.includes('..') || key.length > 300) {
        return json({ ok: false, error: 'bad key' }, 400);
      }

      if (request.method === 'PUT') {
        const bytes = await request.arrayBuffer();

        if (bytes.byteLength > 25 * 1024 * 1024) {
          return json({ ok: false, error: 'object too large' }, 413);
        }

        await env.FILES.put(key, bytes, {
          httpMetadata: { contentType: request.headers.get('content-type') || 'application/octet-stream' },
        });

        return json({ ok: true, key, size: bytes.byteLength });
      }

      if (request.method === 'DELETE') {
        await env.FILES.delete(key);

        return json({ ok: true, key, deleted: true });
      }

      if (request.method === 'GET') {
        const object = await env.FILES.get(key);

        if (!object) return json({ ok: false, error: 'not found' }, 404);

        return new Response(object.body, {
          headers: {
            'content-type': object.httpMetadata?.contentType || 'application/octet-stream',
            'cache-control': 'no-store',
          },
        });
      }

      return json({ ok: false }, 405);
    }

    return json({ ok: false }, 404);
  },
};
