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

### External tools (optional, per feature)

| Tool | macOS | Debian/Ubuntu | Needed for |
|---|---|---|---|
| `fpcalc` (Chromaprint) | `brew install chromaprint` | `apt install libchromaprint-tools` | audio fingerprinting in `app:enrich` (identifies files with no usable name) |
| `metaflac` | `brew install flac` | `apt install flac` | writing tags into **FLAC** files (getID3 shells out to it) |
| `vorbiscomment` | `brew install vorbis-tools` | `apt install vorbis-tools` | writing tags into **Ogg Vorbis** files |

MP3 tags are written in pure PHP. getID3 cannot write MP4/M4A tags: for those
files only the database is updated.

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

- [ ] **`app:import`**: identify the files of `IMPORT_DIR`, write tags + cover
  (embedded and `cover.jpg`), move them into
  `MUSIC_DIR/Artist/Album/NN Title` (singles and compilations apart,
  file names sanitised). Unidentified files stay in the import folder;
  `var/import/state.json` remembers failed attempts (retried when the file
  changes or with `--retry`); duplicates (same MusicBrainz ids already in the
  library) go to `IMPORT_DIR/_duplicates`.
- [ ] **Web page for the files left in the import folder**: list each
  unidentified file with its candidates (title, artist, album, year, cover,
  score, listen button), pick one with a single ergonomic click (or type a
  MusicBrainz link / search by hand), one file after the other; the choice
  writes the tags and renames/moves the file like `app:import`.
- [ ] **MusicBrainz ids in the database** (`music.musicbrainz_*`), read back by
  `app:scan` from the tags (Picard's UFID / TXXX, already written by
  `app:enrich`): durable "already identified" marker that survives a database
  rebuild, and duplicate detection.
- [ ] **Spotify "step 0"**: release MusicBrainz id → its `open.spotify.com/album`
  URL relation → album tracks, matched on title + duration (no artist-name
  search).
- [ ] `composer update "symfony/*"` for the 3 advisories reported by
  `composer audit` (expression-language, http-foundation, yaml).
