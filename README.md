# PHP Music — Symfony 8.1 + Twig (clean native rewrite)

A rewrite of the original **php-music** single-file app (`../index.php`,
~87k lines, ~200 `?action=` handlers) onto **Symfony 8.1**, **Doctrine ORM**
and **Twig**. Front-end assets are plain static files in `public/`
(`app.css`, `player.js`, `nav.js`) referenced with ordinary `<link>` /
`<script>` tags — no build step, no AssetMapper. `nav.js` does partial
page navigation (fetch + swap `<main>`) so audio never cuts between pages.

## Architecture

- **One front controller**: `public/index.php`. Every request is handled by a
  Symfony controller. Nothing else is executed.
- **The legacy monolith is NOT run.** `legacy/php_music.php` is kept only as a
  read-only reference to port features from. It is never included or executed by
  the app.
- **Doctrine owns the schema**, via migrations (`migrations/`). Entities define
  the tables; there is no dependency on the legacy `init_db()`.
- Features are ported **one at a time** into clean controllers + Twig + Doctrine.
  A feature is available once ported; the rest simply isn't wired up yet.
- Reusable *logic* from the monolith (chart generation, image processing, …) is
  extracted into proper `App\…` service classes as each feature is ported — not
  copied inline, and never via globals.

## Ported so far

Scan (`app:scan`, getID3) · auth (register / login / logout, remember-me) ·
library browse + search · artists / artist / album · audio streaming with HTTP
Range (206) · cover art · favorites · playlists (CRUD + add/remove) · play counts
+ history · embeddable single-track player (`/embed/{id}`) · web player + queue.

Entities: `User`, `Music`, `Favorite`, `Playlist`, `PlaylistSong`, `History`,
`PlayCount`.

## Requirements

### PHP

- **PHP 8.4+** (developed on 8.5)
- **Composer 2**

| Extension | Required | Used for |
|---|---|---|
| `pdo_sqlite` | yes | the library database (`var/library/music.db`) |
| `mbstring` | yes | text handling everywhere (titles, search, hints) |
| `ctype`, `iconv` | yes | Symfony / getID3 (tag encodings) |
| `dom`, `xml`, `simplexml`, `tokenizer` | yes | Symfony / Twig / Doctrine |
| `fileinfo` | yes | uploaded audio / cover type sniffing |
| `openssl` | yes | HTTPS calls (MusicBrainz, AcoustID, Cover Art Archive, Spotify embeds) |
| `intl` | recommended | accent-insensitive name matching (`Transliterator`); falls back to plain lowercase |
| `gd` (with WebP) | recommended | cover resizing, WebP for the database, JPEG for embedded tags; without it covers are stored as-is |
| `curl` | recommended | faster HTTP client transport (Symfony falls back to PHP streams) |

Check with `php -m`. On macOS (Homebrew PHP) all of these are built in.

### Composer packages (installed by `composer install`)

Runtime: `symfony/framework-bundle`, `console`, `dotenv`, `runtime`, `flex`,
`yaml`, `form`, `validator`, `security-bundle`, `twig-bundle`,
`expression-language`, `asset-mapper`, **`http-client`** (MusicBrainz /
AcoustID / Cover Art Archive / Spotify), **`process`** (runs `fpcalc`), all
`8.1.*`; `doctrine/orm` ^3.6, `doctrine/doctrine-bundle` ^3.3,
`doctrine/doctrine-migrations-bundle` ^4.0; `twig/twig` + `twig/extra-bundle` ^3.

Dev: `phpstan/phpstan-doctrine`, `phpstan/phpstan-symfony`,
`symfony/maker-bundle`, `symfony/web-profiler-bundle`.

### Bundled libraries (no install needed)

- `lib/getid3/` — **getID3** (tag reading for `app:scan`, tag writing for the
  editor, `app:enrich` and `app:spotify:match`), classmapped by Composer.
- `public/vendor/` — **Bootstrap** + **Bootstrap Icons** (rhythm game UI) and
  **Essentia.js** (WASM audio analysis for rhythm charts, runs in the browser).

### External tools

Everything at once:

```bash
# macOS
brew install atomicparsley flac chromaprint vorbis-tools ffmpeg
# Debian/Ubuntu
sudo apt install atomicparsley flac libchromaprint-tools vorbis-tools ffmpeg
```

All of them are located automatically (PATH, `/opt/homebrew/bin`,
`/usr/local/bin`, `~/.local/bin`…), also when PHP runs under a web server
whose PATH lacks Homebrew.

**Required**

| Tool | macOS | Debian/Ubuntu | Needed for |
|---|---|---|---|
| `AtomicParsley` | `brew install atomicparsley` | `apt install atomicparsley` | writing tags into **M4A** files (getID3 cannot): editor, `app:enrich`, import |
| `metaflac` | `brew install flac` | `apt install flac` | writing tags into **FLAC** files (getID3 shells out to it); without it FLAC files fail with "metaflac not found" |

**Optional, per feature**

| Tool | macOS | Debian/Ubuntu | Needed for |
|---|---|---|---|
| `fpcalc` (Chromaprint) | `brew install chromaprint` | `apt install libchromaprint-tools` | audio fingerprinting in `app:enrich` / `app:import` (strongly recommended: identifies files with no usable name) |
| `vorbiscomment` | `brew install vorbis-tools` | `apt install vorbis-tools` | writing tags into **Ogg Vorbis** files |
| `ffmpeg` | `brew install ffmpeg` | `apt install ffmpeg` | `app:convert`: WMA → M4A (AAC) before the import (`FFMPEG_PATH` to force a location) |

MP3 tags are written in pure PHP. M4A tags (cover and MusicBrainz ids
included, as Picard's `----:com.apple.iTunes` atoms) are written by
AtomicParsley; if it is missing, M4A files are not tagged (only the database
is updated, and the import files them untagged).

### External services

| Service | Account | Used by |
|---|---|---|
| [MusicBrainz](https://musicbrainz.org/doc/MusicBrainz_API) | none (1 request/s, identified User-Agent) | `app:enrich`, `app:spotify:match` |
| [Cover Art Archive](https://coverartarchive.org/) | none | `app:enrich` (album covers) |
| [AcoustID](https://acoustid.org/new-application) | free application API key | `app:enrich` fingerprint lookups |
| Wikidata + open.spotify.com embed pages | none | `app:spotify:match` |

## Configuration

Put machine-specific values in `.env.local` (not committed):

```dotenv
# Music library to index (empty = var/library)
MUSIC_DIR="/absolute/path/to/music"
# Import folder: new files to identify and file into MUSIC_DIR (empty = var/import)
IMPORT_DIR="/absolute/path/to/import"

# Audio fingerprints (app:enrich)
ACOUSTID_API_KEY="your-acoustid-application-key"
# Optional: fpcalc is found automatically (PATH, /opt/homebrew/bin,
# /usr/local/bin, /opt/local/bin, Linuxbrew, /usr/bin, /snap/bin, ~/.local/bin).
# Set it only for a non-standard location.
# FPCALC_PATH="/custom/path/fpcalc"
# MusicBrainz asks for contact info in the User-Agent
MUSICBRAINZ_USER_AGENT="PHPMusic/1.0 ( you@example.com )"
```


## Setup

```bash
composer install

# Create the schema (Doctrine migrations)
php bin/console doctrine:migrations:migrate --no-interaction

# Index the library
php bin/console app:scan            # --verbose-scan for per-file output, --force to re-read every file

# Run
php -S 127.0.0.1:8000 -t public public/index.php
# or: symfony serve
```

`app:scan` also removes from the database the tracks whose file is gone, and
the tracks outside `MUSIC_DIR` (indexed by an older scan of another folder,
such as the import folder): their files are kept, only the database rows go,
along with their favorites / play counts. Uploads live under
`MUSIC_DIR/uploads` and stay.

## Completing metadata (`app:enrich`)

Order of the steps, each run on its own (check the result before the next):

```bash
php bin/console app:enrich --dry-run       # 1. preview the identifications (writes nothing)
php bin/console app:enrich                 #    apply: tags + cover written to the files and the database
php bin/console app:scan                   # 2. re-index
php bin/console app:spotify:match          # 3. link the tracks to Spotify
```


Finds the real title, artist, album, album artist, track/disc number, year,
genre (when unknown) and front cover of tracks with poor tags (`Track 01`,
`Piste audio 05`, `Unknown Artist`, `Album inconnu`, no track number, no
cover), writes them into the files and the database.

```bash
php bin/console app:scan --force                    # once: index existing track numbers / album artists
php bin/console app:enrich --dry-run --limit=50     # preview, writes nothing
php bin/console app:enrich --path="Linkin Park"     # one artist folder
php bin/console app:enrich                          # every incomplete track
```

| Option | Effect |
|---|---|
| `--dry-run` | print the proposals, write nothing |
| `--threshold=0.85` | score needed to apply automatically; below it the proposal waits in **/admin/suggestions** |
| `--path=TEXT` | only tracks whose path contains TEXT |
| `--limit=N` | at most N tracks |
| `--retry` | retry incomplete tracks already attempted |
| `--all` | every track, even complete ones |

How it identifies tracks (the folder layout is not trusted):

1. Hints from the path, file name and current tags (`07-artist-title`,
   `Artist - Album - 07 - Title`, noise folders such as `2019/`, `Downloads/`,
   `Album inconnu/`, download-site stamps…).
2. **Album pass** per folder: AcoustID fingerprints of a few files (when
   `fpcalc` + `ACOUSTID_API_KEY` are available), MusicBrainz release and
   recording searches; each candidate release is scored on track durations,
   track count, names and fingerprint agreement, then files are aligned to
   tracks.
3. **File pass** for mixed folders and single files: fingerprint, else a
   title/artist recording search. Live takes, bootlegs, remixes and vinyl
   editions are avoided unless the file asks for them.

Applied changes keep the previous values: **/admin/suggestions** (admin only,
also linked from the Library page) lists pending, applied, rejected and
reverted proposals, with Accept / Reject, Accept album and Revert.

## Importing new music (import folder → library)

New files are dropped into an **import folder** (`IMPORT_DIR`, default
`var/import/`). The import identifies each file, tags it and files it into the
library (`MUSIC_DIR`). Files it is not sure about wait in the database until
someone picks the right candidate in a web page.

```
  IMPORT_DIR/  (anything: "Track 01.mp3", "Downloads/xyz.flac", whole albums...)
        │
        ▼
 ┌──────────────────────────────────────────────────────────────────┐
 │ 1. IDENTIFY            MetadataEnricher::identifyFolder()        │
 │    hints (path, name, tags)                                      │
 │    + fpcalc fingerprint ──► AcoustID ──► MusicBrainz recordings  │
 │    + MusicBrainz release / recording searches                    │
 │    ──► up to 5 scored candidates per file (best first)           │
 └──────────────────────────────────────────────────────────────────┘
        │
        ├── best score ≥ 0.85 (confident) ───────────────────────┐
        │                                                         │
        ├── doubtful ─────────► import_item  status = pending     │
        │                       (candidates JSON, size, mtime)    │
        │                              │                          │
        │                              ▼                          │
        │                   ┌──────────────────────────┐          │
        │                   │ 3. WEB PAGE (admin)      │          │
        │                   │ one file at a time:      │          │
        │                   │ candidates with title,   │          │
        │                   │ artist, album, year,     │          │
        │                   │ cover, score, ▶ listen   │          │
        │                   │ → one click to choose    │          │
        │                   └──────────────────────────┘          │
        │                              │ chosen candidate         │
        │                              ▼                          ▼
        │                 ┌──────────────────────────────────────────┐
        │                 │ 2. FILE           FileImporter::import() │
        │                 │ write tags + cover + MusicBrainz ids     │
        │                 │ move to MUSIC_DIR/<LibraryPath>          │
        │                 │ add cover.jpg, remove emptied folders    │
        │                 └──────────────────────────────────────────┘
        │                        │                       │
        │                        ▼                       ▼
        │              MUSIC_DIR/Artist/Album/…   IMPORT_DIR/_duplicates/
        │              status = imported          status = duplicate
        │
        └── nothing found ────► status = unmatched (file stays in IMPORT_DIR)
                                tagging/moving error ─► status = failed
```

### 1. Identification (metadata lookup)

Same engine as `app:enrich` (see above), in candidate mode
(`MetadataEnricher::identifyFolder()`): every file of a folder is
fingerprinted, the folder is matched against MusicBrainz releases (album
pass), then doubtful files are identified alone (file pass: AcoustID
recordings, then title/artist search). A confident file gets a single
candidate; a doubtful one gets up to 5 (runner-up albums, other fingerprint
recordings, name-search results), deduplicated and sorted by score. When the
fingerprint names another song than the file name suggests, name-search
candidates are capped below the confidence level: a human must decide.

### 2. Filing into the library

`FileImporter` writes the tags first (title, artist, album, album artist,
track/disc, year, genre, front cover, MusicBrainz recording / release /
release-group ids) — if that fails, nothing moves. Then the file goes to the
path given by `LibraryPath`:

| Case | Destination in `MUSIC_DIR` |
|---|---|
| album track | `Album Artist/Album (Year)/07 Title.ext` |
| multi-disc album | `Album Artist/Album (Year)/2-07 Title.ext` |
| various-artists release | `Artist/Album (Year)/07 Title.ext` (the track's own artist) |
| no album known | `Artist/Singles/Title.ext` |

Names are sanitised for any file system (no `/ \ : * ? " < > |`, no leading
dot, NFC, ≤ 120 bytes per part). An existing file is never overwritten
(`Title (2).ext`); the same audio stream already at the destination (tags
ignored) is a duplicate and goes to `IMPORT_DIR/_duplicates/`. `cover.jpg` is
added next to the album when missing, and the import folders left empty are
removed. Moving works across disks (copy + delete when `rename()` fails).
MP3, FLAC, Ogg and M4A are tagged; WAV is only filed.

### Pending files in the database (`import_item`)

Every file looked at is one `import_item` row: path, original name, size +
mtime, status (`pending`, `unmatched`, `failed`, `imported`, `duplicate`),
candidates (JSON, best first), destination and error. A re-run skips
`pending` / `unmatched` / `failed` files whose size and mtime have not changed
(no new remote lookups); a file that changed (tags fixed by hand, replaced) is
tried again.

### 3. Web page for the pending files (`/admin/import`)

Admin only, linked from the Library page ("Import queue"). One `pending` file
at a time: its current tags, an audio player, and its candidates (cover,
title, artist, album, year, track, score, source, destination path,
MusicBrainz link). One click on a candidate — or **1-5** on the keyboard —
writes the tags (+ cover) and files the track exactly like `app:import`, then
moves on to the next file. **S** sets the file aside (`skipped`: later runs
leave it alone until it changes or `--retry`), **N** goes to the next one
without deciding. Failed files are retried by the next `app:import`.

### Comparison with beets

The identification was compared with [beets](https://beets.io/) on files picked at
random from the library. Beets could not identify some of the MP3s that this
tool found, so this tool was kept,
and the good ideas beets had that it lacked were added to it:
every file of a folder is fingerprinted (not a sample), an album is
auto-applied only when most fingerprints agree with it, a file whose
fingerprint names another song leaves the album and is identified on its
own, two different albums within 0.05 of each other are never auto-applied
(beets' `rec_gap_thresh`), and files are aligned to album tracks with an
optimal assignment instead of a greedy one. Covered by
`tests/enrichment-fingerprint-test.php` and `tests/enrichment-scorer-test.php`.

### Usage

`IMPORT_DIR` and `MUSIC_DIR` must be two separate folders (neither inside the
other). One command per step, run one after the other:

```bash
php bin/console doctrine:migrations:migrate   # once: import_item table
php bin/console app:convert                   # only if IMPORT_DIR holds WMA files: → M4A (AAC), tags kept
php bin/console app:import --dry-run --limit=50 # preview: what would be tagged and where it would be filed
php bin/console app:import                    # tag + file the confident files, store the candidates of the others
php bin/console app:scan                      # index the new files of MUSIC_DIR
php bin/console app:spotify:match             # link them to Spotify
```

| `app:import` option | Effect |
|---|---|
| `--dry-run` | print the decisions, write and move nothing |
| `--threshold=0.85` | score needed to file automatically; below it the file waits (`pending`) |
| `--retry` | also retry files already tried and unchanged since |
| `--only=failed,skipped` | only files already tried with these statuses (`failed`, `skipped`, `pending`, `unmatched`); new files are left alone |
| `--limit=N` | at most N files |
| `--source=DIR` | import another folder than `IMPORT_DIR` |

`app:import` ignores WMA (browsers cannot play it, getID3 cannot tag it):
`app:convert` turns every WMA of `IMPORT_DIR` into an M4A (AAC) next to it,
keeping title, artist, album, album artist, track, year, genre and cover. The
AAC bitrate follows the source: WMA Lossless or ≥ 256 kb/s → 320 kb/s,
160–255 kb/s → 256 kb/s, below → 192 kb/s. Each M4A is checked (audio, same
duration) before the original is moved to `IMPORT_DIR/_wma/` (same relative
path, never deleted; delete it yourself once happy). An existing `.m4a` of the
same name is never overwritten.

| `app:convert` option | Effect |
|---|---|
| `--dry-run` | list the files and the chosen bitrate, convert nothing |
| `--bitrate=auto` | `192`, `256` or `320` to force one bitrate for every file |
| `--limit=N` | at most N files |
| `--source=DIR` | convert another folder than `IMPORT_DIR` |

When MusicBrainz rate-limits (the limit is per IP, shared with every client on
the machine), the album lookup of a folder is retried once after 30 s before
falling back to files one by one.

### Status

Done: identification with candidates, `FileImporter` + `LibraryPath`,
`import_item` entity + migration, the `app:import` command, the
`/admin/import` web page.

## Artist pages (photo, Wikipedia, YouTube)

Each artist page shows a photo, the Wikipedia summary (French first, then
English), links (official site, YouTube, Spotify, Deezer, Bandcamp,
Instagram, MusicBrainz…), the latest YouTube videos (**New** badge for those
published since your last visit of the page), the albums as tiles, then the
tracks. The Artists list flags the artists with new videos. Free sources, no
API key: MusicBrainz → Wikidata (photo from Wikimedia Commons, YouTube
channel) → Wikipedia, and the public RSS feed of the YouTube channel.
Homonyms are told apart by the albums of the library.

```bash
php bin/console doctrine:migrations:migrate   # once: artist_profile table
php bin/console app:artist:fetch              # photo, summary, links (new artists only; --retry, --refresh, --artist=NAME, --limit=N)
php bin/console app:artist:videos             # latest YouTube videos (run it regularly, e.g. cron every hour)
```

Cron example: `0 * * * * cd /path/to/project && php bin/console app:artist:videos -q`.

**Several spellings of one artist** ("Blink 182", "Blink182", "blink‐182";
"Offspring" / "The Offspring"; case differences) come from files tagged by
hand next to files tagged from MusicBrainz. `app:artist:merge` finds them
(case, accents, spaces, punctuation and a leading "The" ignored) and keeps the
most used spelling: it rewrites the artist / album artist tags, then the
database, and merges the artist page profiles. Files are not moved or
renamed, and only files inside `MUSIC_DIR` are touched.

```bash
php bin/console app:artist:merge --dry-run                            # list the groups and the files to rewrite
php bin/console app:artist:merge --artist="Blink 182" --to=blink-182  # one group, another name to keep
php bin/console app:artist:merge                                      # rewrite them all
```
Admins also get a "Refresh artist info" button on each artist page.

## Audio quality stats (`/admin/quality`)

Admin page (also linked from Library): tracks per quality tier (Low →
Lossless, thresholds per codec as in `AudioQuality`), per format, and per
bitrate range inside each format (FLAC/ALAC by resolution), always from the
worst to the best. Click a range to list its tracks; by default the page lists
the worst tracks (lowest bitrate first), to know what to replace. Figures come
from the scan: run `app:scan --force` once if tracks show as not analysed.

## Tests

Standalone scripts, no PHPUnit needed (exit code 0 = pass):

```bash
for t in tests/*-test.php; do php "$t" || echo "FAILED: $t"; done
node tests/rhythm-dsp-test.js
```

## Project layout

```
public/index.php     the single front controller
src/
  Command/           app:scan
  Controller/        Library, Stream, Embed, Favorite, Playlist, Playback,
                     Security, Registration
  Entity/            User, Music, Favorite, Playlist, PlaylistSong, History,
                     PlayCount
  Repository/        visibility-aware queries, pagination, top/recent
  Service/           MusicScanner (getID3 indexing)
    Enrichment/      metadata identification (MusicBrainz, AcoustID, covers)
    Import/          FileImporter, LibraryPath (import folder → library)
templates/           Twig (base layout + player, embed, partials/_tracks macro)
public/app.css       styles (plain static file, no build step)
public/player.js     audio player + queue + per-track "⋮" context menu
public/nav.js        partial navigation (keeps audio playing across pages)
migrations/          Doctrine schema migrations
lib/getid3/          getID3 library (used by MusicScanner)
legacy/php_music.php the original monolith — READ-ONLY reference, never executed
```

## Porting the next feature (the repeatable recipe)

1. Read the corresponding `case '…':` handler in `legacy/php_music.php`.
2. Add entities + a Doctrine migration for any new tables it needs.
3. Write a Symfony controller + Twig template (all markup becomes Twig; any
   reusable logic goes into an `App\…` service, never inline, never `$GLOBALS`).
4. Add the route; verify; done. The legacy handler stays as reference until the
   whole app is ported, after which `legacy/` can be deleted.

## Roadmap (still to port)

Uploads + ID3 editor · social (follows, comments, reactions, messaging, feed) ·
blogs / notes / tasks · discovery (mixes, stations) · admin (users, reports,
bans) · PWA / offline caching · audio engine extras (EQ, normalization,
crossfade) · long tail (rhythm game, image editor, in-browser IDE, DB & drive
managers).

Follow-ups: PHPUnit coverage for `MusicScanner` + controllers; consider
`james-heinrich/getid3` as a composer dependency instead of the vendored copy.

### Metadata & import — to do

- [x] **`app:import`**: identifies the files of `IMPORT_DIR`, writes tags +
  cover + MusicBrainz ids, files them into `MUSIC_DIR/Artist/Album (Year)/NN
  Title` (singles apart, compilations under each track's artist, file
  names sanitised). Unidentified files stay in the import folder;
  `import_item` remembers attempts (retried when the file changes or with
  `--retry`) and stores the candidates;
  duplicates (same audio already filed) go to `IMPORT_DIR/_duplicates`.
- [x] **Web page for the files left in the import folder** (`/admin/import`):
  candidates with cover / score / destination, audio player, one click (or
  1-5) to tag + file, S to skip, N for the next file.
- [ ] Web page: enter a MusicBrainz link or search by hand when no candidate fits.
- [ ] **MusicBrainz ids in the database** (`music.musicbrainz_*`), read back by
  `app:scan` from the tags (Picard's UFID / TXXX, already written by
  `app:enrich`): durable "already identified" marker that survives a database
  rebuild, and duplicate detection.
- [ ] **Spotify "step 0"**: release MusicBrainz id → its `open.spotify.com/album`
  URL relation → album tracks, matched on title + duration (no artist-name
  search).
- [ ] **Latin artist names**: MusicBrainz credits some artists in their own
  script ("植松伸夫" for Nobuo Uematsu); when the file names are in Latin
  script, prefer the artist's Latin alias / sort name for tags and folders.
- [ ] `composer update "symfony/*"` for the 3 advisories reported by
  `composer audit` (expression-language, http-foundation, yaml).
