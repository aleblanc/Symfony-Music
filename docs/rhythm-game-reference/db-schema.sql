-- Rhythm Game tables, extracted verbatim from `legacy/php_music.php`'s
-- `init_db()` (canonical definitions, lines ~22854-22886). This is the
-- SOURCE OF TRUTH schema — several other `case` handlers in the legacy
-- dispatcher re-declare these same `CREATE TABLE IF NOT EXISTS` statements
-- defensively (lazy table creation on first use); those copies are
-- identical and were skipped here.
--
-- NOT executable as-is against the Symfony/Doctrine schema: this is
-- reference material for designing the equivalent Doctrine entities
-- (RhythmChart, RhythmScore, RhythmFavorite), not a migration to run.

CREATE TABLE IF NOT EXISTS rhythm_scores (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  song_id INTEGER NOT NULL,
  score INTEGER DEFAULT 0,
  max_combo INTEGER DEFAULT 0,
  perfect INTEGER DEFAULT 0,
  great INTEGER DEFAULT 0,
  good INTEGER DEFAULT 0,
  bad INTEGER DEFAULT 0,
  miss INTEGER DEFAULT 0,
  played_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (song_id) REFERENCES music(id) ON DELETE CASCADE
);

-- One row per (user, song): a rhythm-game-specific "favorite" list,
-- distinct from the general `favorites` table already ported (Feature
-- "Favoris (base)"). `sort_order` supports manual reordering, same pattern
-- as `listen_later`/`offline_songs` (already ported, Feature 5).
CREATE TABLE IF NOT EXISTS rhythm_favorites (
  user_id INTEGER NOT NULL,
  song_id INTEGER NOT NULL,
  sort_order INTEGER,
  PRIMARY KEY (user_id, song_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (song_id) REFERENCES music(id) ON DELETE CASCADE
);

-- One row per (song, difficulty): the generated (or admin-uploaded, see
-- `save_rhythm_chart`) note chart, cached so it's computed once per
-- song+difficulty pair rather than on every play. `notes_json` is a JSON
-- array of note objects — see chart-generation-functions.php for the exact
-- shape ({time, lane, isLong?, endTime?, isSwipe?, hitEndSwipe?}).
-- `level` is a computed difficulty rating (1-10ish) shown to players before
-- they pick a chart, see `calculatePhpLevel()`.
CREATE TABLE IF NOT EXISTS rhythm_charts (
  song_id INTEGER NOT NULL,
  difficulty TEXT NOT NULL,
  notes_json TEXT,
  level INTEGER DEFAULT 1,
  PRIMARY KEY (song_id, difficulty),
  FOREIGN KEY (song_id) REFERENCES music(id) ON DELETE CASCADE
);

-- Anti-cheat: referenced by `save_rhythm_score` (see
-- backend-dispatcher-cases.php). Not a separate table — it's a column on
-- `users` (`rhythm_strikes INTEGER DEFAULT 0`). 3 strikes = full account
-- ban (not just rhythm-game ban), see the `save_rhythm_score` excerpt.
-- ALTER TABLE users ADD COLUMN rhythm_strikes INTEGER DEFAULT 0;
