# Metadata enrichment — design

Date: 2026-10-19 — status: approved in chat, implemented on `feature/metadata-enrichment`.

## Problem

Part of the library has poor tags: titles that are just the file name
(`Track 01`, `Piste 3`, `05`), `Unknown Artist` / `Unknown Album`, no track
number, no cover. The folder layout is arbitrary (download-year folders,
`Artist/`, `Artist/Album/`, `Artist - Album/`, catch-all dumps), so no fixed
"parent folder = album" rule works. Path segments are only *weak hints*.

## Sources

- **AcoustID** (audio fingerprint via `fpcalc` / Chromaprint) → MusicBrainz
  recording ids. Works even when the path says nothing.
- **MusicBrainz** (release search + release detail, 1 req/s, identified
  User-Agent) → canonical titles, track numbers, durations, year, album artist.
- **Cover Art Archive** → front cover (500 px thumbnail).

Config: `ACOUSTID_API_KEY` and `FPCALC_PATH` (default `fpcalc`) in `.env.local`.
Fingerprinting is skipped with a warning when either is missing.

## Units (`src/Service/Enrichment/`)

| Unit | Responsibility |
|---|---|
| `HintExtractor` | file path + current tags → `TrackHints` (filename pattern `NN - Title`, `Artist - Title`, placeholder detection, cleaned path segments, duration). |
| `Fingerprinter` | runs `fpcalc -json`, returns fingerprint + duration. |
| `AcoustIdClient` | fingerprint → scored recordings with their release ids. |
| `MusicBrainzClient` | release search, release detail (`ReleaseCandidate` with `ReleaseTrack`s). Rate-limited, responses cached (`cache.app`). |
| `CoverArtClient` | release id → front cover bytes. |
| `MatchScorer` | scores a release against a *folder group* of tracks and aligns files → release tracks (number first, then nearest duration, one-to-one). |
| `MetadataEnricher` | orchestrates per folder: fingerprint a sample, collect candidate releases (AcoustID + text search from hints), score, align; orphans fall back to per-track fingerprint. Produces `Proposal`s. |
| `SuggestionApplier` | applies a suggestion: writes tags (via `MetadataWriter`) + DB, keeps previous values for revert. |

## Scoring (0..1)

`0.40 duration alignment (±3 s) + 0.25 AcoustID consensus + 0.20 track-count fit + 0.15 name similarity`.
The best release (edition chosen by durations) wins if above the threshold.

## Persistence

- `music`: new columns `track_number`, `disc_number`, `album_artist`, `enriched_at`
  (tracks already processed are skipped unless `--all`/`--retry`).
- New `metadata_suggestion` table: music, proposed values (JSON), cover bytes,
  score, source, status (`pending|applied|rejected|reverted`), previous values
  (JSON) for revert, MusicBrainz ids, error, timestamps.

## Entry points

- `bin/console app:enrich [--all] [--retry] [--dry-run] [--threshold=0.85] [--limit=N]`
  → score ≥ threshold: applied automatically; below: `pending`.
- `/admin/suggestions` (ROLE_ADMIN): before/after + cover, Accept / Reject,
  Accept whole album, Revert applied.

## Tag writing

`MetadataWriter` now **merges** with existing tags before writing (getID3 with
`overwrite_tags=true` replaces the whole tag, which previously wiped year,
track number, cover and the POPM rating on every edit). Adds track number
(`N/total`), disc number, year, album artist.

## Errors

- No `fpcalc`/key → text search only.
- MusicBrainz 503 → retry with backoff; HTTP errors on one folder never abort the run.
- Tag write failure → DB untouched, suggestion stays `pending` with the error.
- After writing, `lastModified` is refreshed so `app:scan` does not undo the work.

## Out of scope

Renaming/moving files, lyrics lookup.

## Tests

Standalone scripts (project style, `php tests/<name>.php`, exit 0 = pass):
hint extraction on odd real-world paths, scorer/alignment, HTTP clients with
`MockHttpClient`, writer round-trip on a generated MP3 fixture.
