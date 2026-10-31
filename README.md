# PHP Music — Symfony 8.1 + Twig

Self-hosted music library: web player, artist pages, playlists, a
**Subsonic-compatible API** for mobile apps, and a pipeline that identifies,
tags and files new music (MusicBrainz + AcoustID).
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
| `ffmpeg` | `app:import:convert-wma` (WMA → M4A) |

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
php bin/console app:cron:scan
php -S 127.0.0.1:8000 -t public public/index.php     # or nginx + php-fpm
```

## 2. Adding music — the commands, in order

Drop files (any layout, any names) into `IMPORT_DIR`, then run **one command
at a time**, checking the result before the next:

```bash
php bin/console app:import:convert-wma --dry-run   # 1. only if there are WMA files: list them
php bin/console app:import:convert-wma             #    → M4A (AAC), tags kept, originals moved to IMPORT_DIR/_wma

php bin/console app:import:files --dry-run --limit=50   # 2. preview: what would be tagged and where it would go
php bin/console app:import:files                   #    identify, tag (+ cover, MusicBrainz ids) and move into MUSIC_DIR

#                                                    3. web page /admin/import: choose among the candidates of the doubtful files

php bin/console app:cron:scan                      # 4. index the library (new files in, removed ones out)
php bin/console app:cron:spotify-match -v          # 5. Spotify link of each track (resumable: rerun it if it stops)
php bin/console app:cron:artist-info               # 6. artist pages: photo, Wikipedia, links, YouTube channel, discography
php bin/console app:cron:artist-videos             # 7. latest YouTube videos
php bin/console app:cron:lyrics -v                 # 8. lyrics from LRCLIB, synced (LRC) when available (resumable)
```

Steps 4–8 (`app:cron:*`) also run on their own once the scheduler is set up
(see [Scheduled tasks](#scheduled-tasks)): running them by hand only gets the
result sooner.

Command names tell how they are meant to be used:

| Prefix | Use |
|---|---|
| `app:import:*` | each time files are dropped into `IMPORT_DIR`, by hand |
| `app:cron:*` | scheduled; only handle what is new or not done yet, safe to run anytime |
| `app:tool:*` | now and then, by hand (they rewrite tags) |

What happens to each file in `app:import:files`:

| Result | Where the file goes |
|---|---|
| identified (score ≥ 0.85) | `MUSIC_DIR/Artist/Album (Year)/07 Title.ext` (multi-disc: `2-07`, no album: `Artist/Singles/`) |
| doubtful | stays in `IMPORT_DIR`, up to 5 candidates wait in **/admin/import** |
| same audio already in the library | `IMPORT_DIR/_duplicates/` |
| not identified (no candidate at all) | `MUSIC_DIR/_unclassified/` + its path in the import folder, untouched (indexed by `app:cron:scan`; `--no-unclassified` leaves it in `IMPORT_DIR`) |
| error | stays in `IMPORT_DIR` (retried when the file changes, or `--retry`) |

Nothing is ever deleted: converted WMA go to `_wma/`, FLAC converted with
`--flac-to-aac` to `_flac/`, duplicates to `_duplicates/`. Without that option
FLAC files are filed as they are (lossless; their tags need `metaflac`:
`brew install flac`).

Files AcoustID does not know are searched by their names, typos forgiven:
exact title first, then every word fuzzy ("Papa Roach - Last Ressort"), then
the title alone kept only for a similar artist ("Sum41" → Sum 41).

## 3. Keeping the library clean

```bash
php bin/console app:tool:enrich --dry-run --limit=50   # tracks already in MUSIC_DIR with poor tags ("Track 01", no cover...)
php bin/console app:tool:enrich                        #   → doubtful proposals wait in /admin/suggestions
php bin/console app:tool:artist-merge --dry-run        # "Blink 182" / "Blink182" / "blink‐182" → one artist name
php bin/console app:tool:artist-merge                  #   rewrites the tags (files are not moved)
php bin/console app:cron:scan --force                  # re-read every file (after editing tags elsewhere)
```

`app:cron:scan` also drops from the database the tracks whose file is gone and the
tracks outside `MUSIC_DIR` (files kept on disk).

## 4. Command reference

| Command | Main options |
|---|---|
| `app:import:convert-wma` | `--dry-run`, `--bitrate=auto\|192\|256\|320` (auto: WMA Lossless or ≥ 256 kb/s → 320, 160–255 → 256, below → 192), `--limit`, `--source` |
| `app:import:files` | `--dry-run`, `--limit`, `--threshold=0.85`, `--retry`, `--only=failed,skipped,pending,unmatched`, `--source`, `--no-unclassified`, `--flac-to-aac` (FLAC → M4A AAC 256 kb/s before filing, originals to `IMPORT_DIR/_flac`) |
| `app:cron:scan` | `--force` (re-read every file), `--verbose-scan` |
| `app:cron:spotify-match` | `-v` (list matches), `--limit`, `--retry` (tracks not found before), `--write-tags` / `--tags-only` (URL into the MP3), `--browser` (last resort: Spotify's search page in headless Google Chrome), `--browser-only` (only that step, e.g. with `--retry`) |
| `app:tool:enrich` | `--dry-run`, `--limit`, `--path=TEXT`, `--threshold`, `--retry`, `--all` |
| `app:cron:artist-info` | `--artist=NAME`, `--retry` (not found before), `--refresh` (all), `--limit`; artists found before the discography existed only get it fetched (one MusicBrainz request per album for the track lists: about 1 s each the first time, cached 30 days) |
| `app:cron:artist-videos` | `--artist=NAME`, `-v` |
| `app:cron:spectrum` | `--parallel=4` (ffmpeg processes at once), `--limit`, `--id`, `--all` (analyse every track again), `-v` (list genuine tracks too); needs ffmpeg |
| `app:cron:lyrics` | `-v` (list results), `--dry-run`, `--limit`, `--id`, `--retry` (tracks not found before), `--force` (also tracks with lyrics; synced ones are never replaced by plain ones), `--write-tags` (lyrics into the file too), `--delay=200` (ms between tracks) |
| `app:tool:artist-merge` | `--dry-run`, `--artist=NAME --to=NEW_NAME` |

The long commands save as they go (per track, folder or artist): if one stops,
rerun it and it continues with what is left.

### Scheduled tasks

The `app:cron:*` commands carry their schedule (`#[AsCronTask]`,
[simple-cron-scheduler](https://github.com/aleblanc/simple-cron-scheduler)).
One crontab line runs them, each in its own process, never twice at once:

```cron
* * * * * php /path/to/app/bin/console scheduler:run >> /path/to/app/var/log/scheduler.log 2>&1
```

| Task | When |
|---|---|
| `app:cron:scan` | hourly, at :05 |
| `app:cron:spotify-match` | every 2 hours (even hours), at :20 (not 3–5 h, while the nightly MusicBrainz jobs run) |
| `app:cron:lyrics` | every 2 hours (even hours), at :35 |
| `app:cron:spectrum` | every 2 hours (even hours), at :40 |
| `app:cron:artist-videos` | every 2 hours (even hours), at :50 |
| `app:cron:artist-info` | 03:00 |
| `app:cron:artist-concerts` | 04:00 |
| `app:cron:concert-geocode` | 05:15, the cities of the new concerts (OpenStreetMap Nominatim, one request per second) |
| `app:cron:artist-posts` | 06:00, the 100 artists checked longest ago (needs Chrome on that machine) |

Times are in `Europe/Luxembourg` (`config/packages/simple_cron_scheduler.yaml`).
`php bin/console scheduler:run --list` shows the schedule, `--dry-run` what is
due now. Run the scheduler on the machine that owns the database (see
[Good to know](#7-good-to-know)), and avoid `app:import:files` / `app:tool:enrich`
between 03:00 and 05:00 (MusicBrainz allows one request per second).

## 5. Web pages

- **Library / Artists / Years / Genres** — artist and year tiles show album cover mosaics.
- **Discover folder / Unclassified folder** (left menu) — the tracks under
  `MUSIC_DIR/_discover` (albums to discover, e.g. from your Favorite discography) and
  `MUSIC_DIR/_unclassified` (files `app:import:files` could not identify), grouped by
  sub-folder, with the same play / queue / playlist buttons, plus *Play all* /
  *Queue* per album. Both folders are scanned like the rest of the library.
- The **?** track list (MusicBrainz original edition, listeners of each track,
  ★ on the most listened) also shows on the album page, on the artist page's
  album cards and on each album of the Discover folder.
- **Album, artist, genre and year pages** — *Play all*, *Add all to queue* and
  *Add all to playlist* (an existing one, or a new private one named after the
  page; tracks already in it are skipped). Albums play in track order.
- **Artist page** — photo, Wikipedia summary, links, latest YouTube videos
  (**New** since your last visit), albums, tracks.
  **Discography**: every studio album / EP (MusicBrainz), newest first, even
  those not in the library (dimmed, ✓ on the ones you have), with their
  popularity (listeners on ListenBrainz) and **★ Essential** on the most listened;
  the **?** on a cover shows its track list (original edition, listeners of each
  track with ★ on the album's standout titles, ✓ on the tracks you have); the **♥** marks an album to listen to.
- **Favorite discography** (left menu) — the albums hearted in artist
  discographies, latest first, with their track list and a ✓ when already in
  the library.
- **Concerts** (left menu) — upcoming concerts of the library artists
  (Songkick, `app:cron:artist-concerts`), by month or on an OpenStreetMap
  **map** (*Map* tab: one pin per concert at its city, located by
  `app:cron:concert-geocode`, no API key), filtered by country or new dates.
- **Discover** — trending tracks, random tracks / albums / artists you never
  played, mixes, and **Albums to discover from your artists**: the most
  listened albums (ListenBrainz) missing from the library, for the artists
  you have at least 11 tracks of, one per artist per round (**See all →**:
  up to 120 on /discover/albums).
- **Track lists** (everywhere) — a coloured quality tag before the artist
  (very low → lossless, with format and bitrate) and a **★★★★★ rating**: one
  click rates the track 1 to 5 (click the current star again to remove it);
  one note per track, also on the song page.
- **Song page** — details, quality, Spotify link, raw tags, per-track volume / EQ
  (applied live while the song plays).
- **Player bar** — title → song page, artist and album → their pages; playback
  continues while browsing. Lyrics button (or **L**): synced lyrics scroll with
  the song, click a line to jump there. Queue button (or **Q**): the play queue —
  current track highlighted, played ones dimmed; click a track to jump to it,
  × to remove it, *Clear upcoming* to drop what follows. **RG** button: ReplayGain normalization
  track → album → off (needs ReplayGain tags, read by `app:cron:scan`).
- Admin (buttons on the Library page): **/admin/import** (doubtful imports,
  keys 1–5 to choose, S skip, U keep as is in `_unclassified`, N next),
  **/admin/suggestions** (`app:tool:enrich` proposals, revertible), **/admin/quality**
  (bitrate / format stats, worst first, the tracks worth replacing with their
  Spotify link: MP3 ≤ 192 kb/s, AAC / Vorbis / Opus ≤ 112 kb/s, which sound
  better than MP3 at the same bitrate).
- **Inflated bitrates** — `app:cron:spectrum` measures where each track's
  treble stops (three 8 s slices decoded by ffmpeg). A sharp cutoff too low for
  the declared bitrate gives away a re-encoded weaker source (or a narrow-band
  one): a "320" MP3 cut at 17 kHz has the bandwidth of a 128 one, a FLAC cut at
  16 kHz that of an MP3 112. Such a track is judged as the MP3 whose bandwidth
  it has (`MP3 ≈128`, quality tier included), the song page shows the cutoff,
  /admin/quality lists them with a histogram of cutoffs (one range per MP3
  bitrate's encoder lowpass), and /admin/duplicates suggests keeping the copy
  whose treble goes highest. The declared bitrate and the file are never changed.

## 6. Mobile apps (Subsonic API)

The library is served over the **Subsonic / OpenSubsonic** protocol at
`/rest/*`, so any Subsonic client can browse it, stream it and download
tracks for offline listening. Tested with **[Substreamer](https://substreamer.app)**
(Android / iOS, free). Other Subsonic clients should work the same way:
**[Tempus](https://github.com/eddyizm/tempus)** (Android, free, open source,
the maintained successor of Tempo; take the `degoogled` APK from its GitHub
releases for a build without Google services), Symfonium, DSub2000, SubTune,
the official Subsonic app.

What the apps get: artists, albums, genres, years, tracks (in disc/track
order), search, covers (one per album), synced lyrics, ★ ratings, favorites
(the same as the website's), play counts and history (listens made offline
are reported later with their real time), playlists, and the website's
**Offline** list as a playlist named *Hors ligne*, which apps can set to
download automatically. Files are served as they are (MP3, M4A, FLAC), with
seeking.

Set up once, after updating the code:

```bash
php bin/console doctrine:migrations:migrate   # adds the Subsonic columns and tables (back up music.db first)
```

Then, to use it from a phone on the same Wi-Fi:

```bash
php -S 0.0.0.0:8000 -t public public/index.php   # 0.0.0.0, not 127.0.0.1, so the phone can reach it
ipconfig getifaddr en0                            # the Mac's address, e.g. 192.168.1.42
```

In the app, add a **Subsonic** server: `http://192.168.1.42:8000`, any
username and password.

> **Not ready for the internet yet.** The API currently accepts any
> username and password and runs as the single library user. Keep it on the
> local network. An Apache `.htpasswd` in front of `/rest` would block most
> apps (Substreamer included: only a few, like Symfonium, can send that
> extra login), so internet access needs the API to check the Subsonic
> credentials itself first.

Not supported: transcoding (`maxBitRate`/`format` are ignored, the original
file is sent), podcasts, internet radio, shares, jukebox.

## 7. Good to know

- **SQLite database** `var/library/music.db` (WAL mode): write to it from **one
  machine only**. Two machines writing to the same file (e.g. a Docker
  container and the host through a mounted folder) corrupt it.
  If it is ever "malformed": stop the server, then
  `sqlite3 music.db ".recover" | sqlite3 recovered.db`, check
  `PRAGMA integrity_check`, swap the files, run `app:cron:scan`.
- **MusicBrainz** allows 1 request/s per IP and throttles clients without a
  contact in `MUSICBRAINZ_USER_AGENT`. Do not run several commands that query
  it at the same time (`app:import:files`, `app:tool:enrich`, `app:cron:spotify-match`,
  `app:cron:artist-info`).
- After updating `player.js` / `nav.js` / `app.css`, reload once with
  Cmd+Shift+R (service worker).
- ReplayGain values are read by `app:cron:scan` only for new or changed files:
  run `app:cron:scan --force` once so the tracks already indexed get theirs.
- LRCLIB sometimes answers 503: `app:cron:lyrics` skips that track and
  retries it on the next run (it only stops after 5 errors in a row).

## Development

```bash
for t in tests/*-test.php; do php "$t" || echo "FAILED: $t"; done   # standalone tests, exit 0 = pass
node tests/rhythm-dsp-test.js
vendor/bin/phpstan analyse
bash docs/subsonic-smoke.sh http://localhost:8000   # calls every Subsonic method, OK/FAIL per call
```

```
src/Command/            app:* commands (Artist/ for app:artist:*)
src/Controller/         pages and admin pages
src/Controller/Api/     Subsonic API: /rest/{method} dispatcher + Subsonic/ handlers
src/Subsonic/           Subsonic ids, request parsing, JSON/XML responses, library queries
src/Entity/, Repository/
src/Service/Enrichment/ identification (MusicBrainz, AcoustID, Cover Art Archive)
src/Service/Import/     import folder → library (FileImporter, LibraryPath, WmaConverter)
src/Service/Artist/     artist pages (MusicBrainz → Wikidata → Wikipedia, discography + ListenBrainz popularity, YouTube RSS), merge
src/Service/Spotify/    Spotify matching
src/Service/Lyrics/     LRCLIB lyrics (app:cron:lyrics)
templates/              Twig
lib/getid3/             getID3 (tag reading / writing)
migrations/             Doctrine migrations
```

## To do

- Web page: paste a MusicBrainz link or search by hand when no import candidate fits.
- MusicBrainz ids stored in the database, read back by `app:cron:scan` from the tags.
- Spotify from the release's MusicBrainz id (no name search).
- Latin names for artists credited in another script ("植松伸夫" → Nobuo Uematsu).
- `composer update "symfony/*"` for the advisories reported by `composer audit`.
- Still to port from the legacy app: social features, blogs / notes, admin
  (users, reports), audio extras (crossfade).
- `app:loudness`: measure the tracks without ReplayGain tags (ffmpeg `ebur128`)
  so the RG normalization covers the whole library.

Ideas taken from [Monochrome](https://github.com/monochrome-music/monochrome):

- Scrobbling to ListenBrainz / Last.fm once half the track (max 4 min) is played.
- Global keyboard shortcuts (←/→ seek, Shift+←/→ prev/next, ↑/↓ volume, M, S, R, Q),
  shuffle / repeat, sleep timer, endless radio when the queue ends.
- Listening signals for the recommendations: completion rate and skips
  (< 5 s or < 30 % played) per track and artist, disliked tracks left out.
- Crossfade (two `<audio>` elements) + preloading the next track, leading /
  trailing silence trimmed.
- Artist links from MusicBrainz `url-rels` (`/ws/2/artist/{mbid}?inc=url-rels`).
- Playlist import from Spotify / Exportify CSV, XSPF, JSPF, M3U: matched by
  ISRC, then by title / artist / album similarity.
- Smaller ones: accent color taken from the cover, M3U / CUE export, visualizer,
  Ctrl+K command palette.
