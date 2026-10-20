#!/usr/bin/env node
/*
 * Searches open.spotify.com in a headless Chromium, for app:spotify:match
 * (--browser): the last resort for songs the public sources didn't find.
 *
 * Spotify's search page is rendered by its own JavaScript, so a plain HTTP
 * fetch sees nothing; a real browser runs that code like any visitor. The
 * browser is not disguised (default HeadlessChrome user agent) and searches
 * are spaced out. Automated use is against Spotify's terms and the page
 * markup can change at any time: results are best effort.
 *
 * Input  (stdin):  JSON array of search strings
 * Output (stdout): one JSON line per query, in order:
 *                  {"q": "...", "tracks": [{"id", "name", "artists": [], "duration"}], "error"?: "..."}
 *
 * Needs Playwright + Chromium:  npm install -g playwright && npx playwright install chromium
 * (or PLAYWRIGHT_PATH=/path/to/node_modules/playwright).
 */
import { createRequire } from 'module';

const require = createRequire(import.meta.url);
let playwright;
try {
    playwright = require(process.env.PLAYWRIGHT_PATH || 'playwright');
} catch {
    process.stderr.write('Playwright not found: npm install -g playwright && npx playwright install chromium (or set PLAYWRIGHT_PATH)\n');
    process.exit(2);
}

const delayMs = Number(process.env.SEARCH_DELAY_MS || 1500);
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const toSeconds = (text) => (text || '').split(':').reduce((total, part) => total * 60 + Number(part), 0) || null;

let input = '';
for await (const chunk of process.stdin) input += chunk;
const queries = JSON.parse(input || '[]');

const browser = await playwright.chromium.launch();
const page = await browser.newPage({ locale: 'en-US' });

try {
    for (const [i, q] of queries.entries()) {
        if (i > 0) await sleep(delayMs);
        const result = { q, tracks: [] };
        try {
            await page.goto('https://open.spotify.com/search/' + encodeURIComponent(q) + '/tracks', { waitUntil: 'domcontentloaded', timeout: 30000 });
            await page.waitForSelector('[data-testid="tracklist-row"]', { timeout: 12000 });
            result.tracks = await page.$$eval('[data-testid="tracklist-row"]', (rows) => rows.slice(0, 10).map((row) => {
                const link = row.querySelector('a[href*="/track/"]');
                return {
                    id: (link?.getAttribute('href') || '').match(/track\/([A-Za-z0-9]{22})/)?.[1] || null,
                    name: link?.textContent || '',
                    artists: [...row.querySelectorAll('a[href*="/artist/"]')].map((a) => a.textContent),
                    duration: [...row.querySelectorAll('div')].map((d) => d.textContent).find((t) => /^\d+(:\d\d){1,2}$/.test(t || '')) || null,
                };
            }));
            result.tracks = result.tracks.filter((t) => t.id).map((t) => ({ ...t, duration: toSeconds(t.duration) }));
        } catch (e) {
            // No row within the timeout: no result, or the page changed / blocked us.
            result.error = String(e.message || e).split('\n')[0];
        }
        process.stdout.write(JSON.stringify(result) + '\n');
    }
} finally {
    await browser.close();
}
