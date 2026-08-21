/*
 * Photograph every mapped Expo screen at the reference designs' device size.
 *
 * Geometry is inherited from the portal harness next door, and for the same
 * reason: the reference PNGs are 852x1846, which is a 426x922 CSS viewport at
 * DPR 2. This shoots at 426x922 with deviceScaleFactor 1 and lets sheet.php
 * double it when compositing - `--force-device-scale-factor=2` does NOT give a
 * 426px CSS viewport, it lays out at ~392px and upscales the raster, so
 * content that fits in the live browser reads as clipped in the shot. That was
 * already learned once on this codebase; it is not re-learned here.
 *
 * Two things make a signed-in shot possible without typing into a form:
 *
 *   - the fixture API answers any credential, and
 *   - the token is seeded straight into localStorage before the app boots.
 *     expo-secure-store falls back to localStorage on web, so the app finds a
 *     session exactly where it would look for a real one.
 *
 * The app's API base URL is baked into the bundle as http://10.0.2.2:8000
 * (the Android emulator's route to the host). Rather than edit app.json - a
 * tracked file the app ships with - Chrome is told to resolve that address to
 * the fixture server. The bundle under test is therefore byte-identical to the
 * one that ships.
 */

import { launch } from 'puppeteer-core';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { join } from 'node:path';

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const REPO = 'C:\\laragon\\www\\opeschool-cloud';
const DIST_URL = process.env.PARITY_DIST_URL ?? 'http://127.0.0.1:8390';
const SCRATCH = process.env.PARITY_SCRATCH ?? join(process.env.TEMP ?? '.', 'parity');
const ONLY = process.argv.slice(2);

const map = JSON.parse(await readFile(new URL('./screens.map.json', import.meta.url), 'utf8'));
const shots = join(SCRATCH, 'shots');

await mkdir(shots, { recursive: true });

const browser = await launch({
  executablePath: CHROME,
  headless: 'new',
  defaultViewport: { width: 426, height: 922, deviceScaleFactor: 1 },
  args: [
    '--host-resolver-rules=MAP 10.0.2.2 127.0.0.1',
    '--hide-scrollbars',
    '--disable-gpu',
    '--force-color-profile=srgb',
    '--font-render-hinting=none',
  ],
});

const report = [];

for (const [slug, entry] of Object.entries(map)) {
  if (slug.startsWith('_')) continue;
  if (ONLY.length > 0 && !ONLY.includes(slug)) continue;

  if (!existsSync(join(REPO, 'mobile', `${slug}.png`))) {
    report.push({ slug, status: 'no-reference' });
    continue;
  }

  const page = await browser.newPage();
  const problems = [];

  page.on('console', (m) => {
    if (m.type() === 'error') problems.push(m.text().slice(0, 200));
  });
  page.on('pageerror', (e) => problems.push(String(e).slice(0, 200)));

  try {
    // Seed the session before any app code runs, on the origin the app uses.
    await page.goto(`${DIST_URL}/`, { waitUntil: 'domcontentloaded' });

    if (entry.auth !== false) {
      // The exact keys src/storage/secure.ts reads on web.
      await page.evaluate(() => {
        localStorage.setItem(
          'opes.guardian.token',
          JSON.stringify({ value: 'parity|fixture-token', expiresAt: '2026-09-10T21:00:00Z' }),
        );
        localStorage.setItem('opes.guardian.device_id', 'parity-device');
      });
    } else {
      await page.evaluate(() => localStorage.clear());
    }

    await page.goto(`${DIST_URL}${entry.route}`, { waitUntil: 'networkidle0', timeout: 45000 });

    // React Native Web mounts, then the screen's own fetch resolves. Give the
    // second paint a beat; a shot taken mid-transition is a false failure.
    await new Promise((r) => setTimeout(r, 1200));

    if (entry.scrollTo) {
      await page.evaluate((y) => {
        const scroller = document.querySelector('[data-testid="screen-scroll"]')
          ?? document.scrollingElement
          ?? document.body;
        scroller.scrollTop = y;
        window.scrollTo(0, y);
      }, entry.scrollTo);
      await new Promise((r) => setTimeout(r, 500));
    }

    await page.screenshot({ path: join(shots, `${slug}.png`) });
    report.push({ slug, route: entry.route, status: 'shot', problems: problems.slice(0, 3) });
  } catch (error) {
    report.push({ slug, route: entry.route, status: 'failed', error: String(error).slice(0, 200) });
  } finally {
    await page.close();
  }
}

await browser.close();
await writeFile(join(SCRATCH, 'shoot-report.json'), JSON.stringify(report, null, 2));

const shot = report.filter((r) => r.status === 'shot').length;
const failed = report.filter((r) => r.status !== 'shot');

console.log(`shot ${shot}/${report.length}`);
for (const f of failed) console.log(`  ${f.status.padEnd(13)} ${f.slug} ${f.error ?? ''}`);

const withProblems = report.filter((r) => (r.problems?.length ?? 0) > 0);
if (withProblems.length) {
  console.log(`\nconsole errors on ${withProblems.length} screens:`);
  for (const p of withProblems.slice(0, 10)) console.log(`  ${p.slug}: ${p.problems[0]}`);
}
