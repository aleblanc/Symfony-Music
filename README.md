# PHP Music — Symfony 8.1 + Twig

Self-hosted music library: web player, artist pages, playlists, and a
pipeline that identifies, tags and files new music (MusicBrainz + AcoustID).
Rewrite of the original single-file **php-music** app (`legacy/php_music.php`,
read-only reference, never executed) on Symfony 8.1, Doctrine ORM (SQLite) and
Twig. No front-end build step: `public/app.css`, `player.js`, `nav.js`.

## 1. Install

**PHP 8.4+** with `pdo_sqlite`, `mbstring`, `intl`, `gd`, `curl` (all built in
with Homebrew PHP), **Composer 2**, and the external tools:

```bash
brew install atomicparsley flac chromaprint vorbis-tools ffmpeg        # macOS
sudo apt install atomicparsley flac libchromaprint-tools vorbis-tools ffmpeg  # Debian/Ubuntu
```

| Tool | Needed for |
|---|---|
| `AtomicParsley` | writing M4A tags |
| `metaflac` (flac) | writing FLAC tags |
| `fpcalc` (chromaprint) | audio fingerprints: identifies files with useless names |
| `vorbiscomment` (vorbis-tools) | writing Ogg tags |
| `ffmpeg` | `app:convert` (WMA → M4A) |

They are found automatically (PATH, Homebrew, `~/.local/bin`…).

Machine settings go in **`.env.local`** (never committed):

```dotenv
MUSIC_DIR="/Users/me/Music/Library"     # the library (indexed, browsed)
IMPORT_DIR="/Users/me/Music/Import"     # new music to identify, then moved into MUSIC_DIR
ACOUSTID_API_KEY="..."                  # free key: https://acoustid.org/new-application
MUSICBRAINZ_USER_AGENT="PHPMusic/1.0 ( you@example.com )"  # a contact is required, or MusicBrainz throttles you
```

`MUSIC_DIR` and `IMPORT_DIR` must be two separate folders (neither inside the other).

```bash
composer install
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:scan
php -S 127.0.0.1:8000 -t public public/index.php     # or nginx + php-fpm
```

## 2. Adding music — the commands, in order

Drop files (any layout, any names) into `IMPORT_DIR`, then run **one command
at a time**, checking the result before the next:

```bash
php bin/console app:convert --dry-run        # 1. only if there are WMA files: list them
php bin/console app:convert                  #    → M4A (AAC), tags kept, originals moved to IMPORT_DIR/_wma

php bin/console app:import --dry-run --limit=50   # 2. preview: what would be tagged and where it would go
php bin/console app:import                   #    identify, tag (+ cover, MusicBrainz ids) and move into MUSIC_DIR

#                                              3. web page /admin/import: choose among the candidates of the doubtful files

php bin/console app:scan                     # 4. index the library (new files in, removed ones out)
php bin/console app:spotify:match -v         # 5. Spotify link of each track (resumable: rerun it if it stops)
php bin/console app:artist:fetch             # 6. artist pages: photo, Wikipedia, links, YouTube channel
php bin/console app:artist:videos            # 7. latest YouTube videos (cron, e.g. hourly)
```

What happens to each file in `app:import`:

| Result | Where the file goes |
|---|---|
| identified (score ≥ 0.85) | `MUSIC_DIR/Artist/Album (Year)/07 Title.ext` (multi-disc: `2-07`, no album: `Artist/Singles/`) |
| doubtful | stays in `IMPORT_DIR`, up to 5 candidates wait in **/admin/import** |
| same audio already in the library | `IMPORT_DIR/_duplicates/` |
| not identified / error | stays in `IMPORT_DIR` (retried when the file changes, or `--retry`) |

Nothing is ever deleted: converted WMA go to `_wma/`, duplicates to `_duplicates/`.

## 3. Keeping the library clean

```bash
php bin/console app:enrich --dry-run --limit=50   # tracks already in MUSIC_DIR with poor tags ("Track 01", no cover...)
php bin/console app:enrich                        #   → doubtful proposals wait in /admin/suggestions
php bin/console app:artist:merge --dry-run        # "Blink 182" / "Blink182" / "blink‐182" → one artist name
php bin/console app:artist:merge                  #   rewrites the tags (files are not moved)
php bin/console app:scan --force                  # re-read every file (after editing tags elsewhere)
```

`app:scan` also drops from the database the tracks whose file is gone and the
tracks outside `MUSIC_DIR` (files kept on disk).

## 4. Command reference

| Command | Main options |
|---|---|
| `app:convert` | `--dry-run`, `--bitrate=auto\|192\|256\|320` (auto: WMA Lossless or ≥ 256 kb/s → 320, 160–255 → 256, below → 192), `--limit`, `--source` |
| `app:import` | `--dry-run`, `--limit`, `--threshold=0.85`, `--retry`, `--only=failed,skipped,pending,unmatched`, `--source` |
| `app:scan` | `--force` (re-read every file), `--verbose-scan` |
| `app:spotify:match` | `-v` (list matches), `--limit`, `--retry` (tracks not found before), `--write-tags` / `--tags-only` (URL into the MP3), `--browser` (last resort, needs Node.js + Playwright) |
| `app:enrich` | `--dry-run`, `--limit`, `--path=TEXT`, `--threshold`, `--retry`, `--all` |
| `app:artist:fetch` | `--artist=NAME`, `--retry` (not found before), `--refresh` (all), `--limit` |
| `app:artist:videos` | `--artist=NAME`, `-v` |
| `app:artist:merge` | `--dry-run`, `--artist=NAME --to=NEW_NAME` |

The long commands save as they go (per track, folder or artist): if one stops,
rerun it and it continues with what is left.

## 5. Web pages

- **Library / Artists / Years / Genres** — artist and year tiles show album cover mosaics.
- **Artist page** — photo, Wikipedia summary, links, latest YouTube videos
  (**New** since your last visit), albums, tracks, *Play all* / *Add all to queue*.
- **Song page** — details, quality, Spotify link, raw tags, per-track volume / EQ.
- **Player bar** — title → song page, artist and album → their pages; playback
  continues while browsing.
- Admin (buttons on the Library page): **/admin/import** (doubtful imports,
  keys 1–5 to choose, S skip, N next), **/admin/suggestions** (`app:enrich`
  proposals, revertible), **/admin/quality** (bitrate / format stats, worst first).

## 6. Good to know

- **SQLite database** `var/library/music.db` (WAL mode): write to it from **one
  machine only**. Two machines writing to the same file (e.g. a Docker
  container and the host through a mounted folder) corrupt it.
  If it is ever "malformed": stop the server, then
  `sqlite3 music.db ".recover" | sqlite3 recovered.db`, check
  `PRAGMA integrity_check`, swap the files, run `app:scan`.
- **MusicBrainz** allows 1 request/s per IP and throttles clients without a
  contact in `MUSICBRAINZ_USER_AGENT`. Do not run several commands that query
  it at the same time (`app:import`, `app:enrich`, `app:spotify:match`,
  `app:artist:fetch`).
- After updating `player.js` / `nav.js` / `app.css`, reload once with
  Cmd+Shift+R (service worker).

## Development

```bash
for t in tests/*-test.php; do php "$t" || echo "FAILED: $t"; done   # standalone tests, exit 0 = pass
node tests/rhythm-dsp-test.js
vendor/bin/phpstan analyse
```

```
src/Command/            app:* commands (Artist/ for app:artist:*)
src/Controller/         pages and admin pages
src/Entity/, Repository/
src/Service/Enrichment/ identification (MusicBrainz, AcoustID, Cover Art Archive)
src/Service/Import/     import folder → library (FileImporter, LibraryPath, WmaConverter)
src/Service/Artist/     artist pages (MusicBrainz → Wikidata → Wikipedia, YouTube RSS), merge
src/Service/Spotify/    Spotify matching
templates/              Twig
lib/getid3/             getID3 (tag reading / writing)
migrations/             Doctrine migrations
```

## To do

- Web page: paste a MusicBrainz link or search by hand when no import candidate fits.
- MusicBrainz ids stored in the database, read back by `app:scan` from the tags.
- Spotify from the release's MusicBrainz id (no name search).
- Latin names for artists credited in another script ("植松伸夫" → Nobuo Uematsu).
- `composer update "symfony/*"` for the advisories reported by `composer audit`.
- Still to port from the legacy app: social features, blogs / notes, admin
  (users, reports), audio extras (normalization, crossfade).
