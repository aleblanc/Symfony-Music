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

- PHP 8.4+ with `pdo_sqlite`, `mbstring`, `intl`, `dom`, `xml`
- Composer

## Setup

```bash
composer install

# Point the library at your music (or leave empty to use var/library).
# Edit .env  ->  MUSIC_DIR="/absolute/path/to/music"

# Create the schema (Doctrine migrations)
php bin/console doctrine:migrations:migrate --no-interaction

# Index the library
php bin/console app:scan            # --verbose-scan for per-file output

# Run
php -S 127.0.0.1:8000 -t public public/index.php
# or: symfony serve
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
