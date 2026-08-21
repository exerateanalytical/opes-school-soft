/*
 * Static server for the Expo web export, with SPA fallback.
 *
 * `expo export --platform web` emits ONE index.html plus a JS bundle:
 * expo-router does its routing client-side. A plain file server therefore
 * 404s every route except `/`, and the screenshot of a 404 looks exactly like
 * a screen that failed to build - which is the wrong lesson to learn from a
 * parity sheet. Anything without a file extension falls back to index.html so
 * the router can take over.
 */

import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { extname, join, normalize } from 'node:path';

const root = process.argv[2];
const port = Number(process.argv[3] ?? 8390);

if (!root) {
  console.error('usage: node serve-dist.mjs <dist-dir> [port]');
  process.exit(1);
}

const types = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.svg': 'image/svg+xml',
  '.ttf': 'font/ttf',
  '.otf': 'font/otf',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
  '.map': 'application/json; charset=utf-8',
};

createServer(async (req, res) => {
  const url = new URL(req.url ?? '/', 'http://localhost');
  // normalize() collapses any ../ before it can escape the export directory.
  const rel = normalize(decodeURIComponent(url.pathname)).replace(/^([/\\])+/, '');
  const ext = extname(rel);

  const target = ext === '' ? 'index.html' : rel;

  try {
    const body = await readFile(join(root, target));
    res.writeHead(200, {
      'content-type': types[extname(target)] ?? 'application/octet-stream',
      'cache-control': 'no-store',
    });
    res.end(body);
  } catch {
    try {
      const body = await readFile(join(root, 'index.html'));
      res.writeHead(200, { 'content-type': types['.html'], 'cache-control': 'no-store' });
      res.end(body);
    } catch {
      res.writeHead(404, { 'content-type': 'text/plain' });
      res.end('not found');
    }
  }
}).listen(port, () => console.log(`dist on http://127.0.0.1:${port}`));
