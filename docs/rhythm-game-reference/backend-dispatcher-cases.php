<?php
// Rhythm Game backend API handlers, extracted VERBATIM from the legacy
// dispatcher switch in `legacy/php_music.php` (lines 30237-31235).
// This is a raw reference excerpt, NOT valid standalone PHP (it's a
// slice of a `switch ($action) { ... }` block — $db, $user_id, etc.
// come from the surrounding legacy request-dispatch context).
//
// Contains: save_rhythm_score (anti-cheat scoring + 3-strike ban),
// get_rhythm_leaderboard, get_song_rhythm_leaderboard, get_rhythm_chart
// (lazy chart generation, see chart-generation-functions.php),
// get_rhythm_levels, get_rhythm_artists (players directory + stats),
// get_all_rhythm_levels, get_rhythm_artists_stats, save_rhythm_chart
// (admin/manual chart override), get_user_rhythm_scores,
// reset_rhythm_charts (wipe + regen), rescan_charts (bulk regen),
// toggle_rhythm_favorite, get_rhythm_favorite_songs, get_rhythm_favorites.

    case 'save_rhythm_score':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      
      // SERVER-SIDE ANTI-CHEAT VALIDATION (V6.2: Multi-Layered, Pattern Analysis, Auto-Ban)
      $hit_deltas = $data['hit_deltas'] ?? [];
      $total_hits = count($hit_deltas);
      $max_combo = intval($data['max_combo'] ?? 0);
      $claimed_score = intval($data['score'] ?? 0);
      $is_cheater = false;
      $ban_reason = '';

      if ($total_hits > 20) {
        // Layer 1: Inhuman Precision bounds (Too many hits occurring with sub-5ms delta)
        $suspicious_hits = 0;
        $sum = 0;
        foreach ($hit_deltas as $delta) {
          $d = (float)$delta;
          if ($d < 5.0) $suspicious_hits++;
          $sum += $d;
        }
        
        if (($suspicious_hits / $total_hits) > 0.8) {
          $is_cheater = true;
          $ban_reason = 'Inhuman Precision (80%+ hits under 5ms)';
        }

        // Layer 2: Pattern Analysis (Macro / Zero-Variance Detection via Standard Deviation)
        if (!$is_cheater) {
          $mean = $sum / $total_hits;
          $variance_sum = 0;
          foreach ($hit_deltas as $delta) {
            $variance_sum += pow((float)$delta - $mean, 2);
          }
          $std_dev = sqrt($variance_sum / $total_hits);
          
          // Human timing organically fluctuates. Standard deviation < 1.5ms across 20+ hits strongly indicates a machine macro.
          if ($std_dev < 1.5) {
            $is_cheater = true;
            $ban_reason = 'Macro Pattern Detected (StdDev < 1.5ms)';
          }
        }
      }

      // Layer 3: Bounds Validation (Score / Combo Spoofing)
      $theoretical_max = $total_hits * 1000;
      if ($claimed_score > $theoretical_max || $max_combo > $total_hits) {
        $is_cheater = true;
        $ban_reason = 'Data Spoofing (Score or Combo out of mathematical bounds)';
      }

      if ($is_cheater) {
        // Fetch current strikes (using absolute value to accurately track historical progression)
        $stmt_strikes = $db->prepare("SELECT rhythm_strikes FROM users WHERE id = ?");
        $stmt_strikes->execute([$user_id]);
        $current_strikes = (int)$stmt_strikes->fetchColumn();
        $new_strikes = abs($current_strikes) + 1;
        
        try {
          $db->exec("CREATE TABLE IF NOT EXISTS admin_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, admin_email TEXT, action TEXT, target_user_id INTEGER, target_email TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);");
        } catch(Exception $e) {}

        if ($new_strikes >= 3) {
          // 3rd strike: Full Account Permanent Ban
          $db->prepare("UPDATE users SET rhythm_strikes = ?, banned = 1 WHERE id = ?")->execute([$new_strikes, $user_id]);
          $db->prepare("INSERT INTO admin_logs (admin_email, action, target_user_id, target_email) VALUES (?, ?, ?, ?)")
             ->execute(['SYSTEM (Auto-Ban)', '3rd Strike Cheat: ' . $ban_reason, $user_id, 'SYSTEM']);
          session_destroy();
          http_response_code(403);
          send_json(['status' => 'error', 'message' => '3rd Strike Cheat detected: ' . $ban_reason . '. Your account has been permanently banned.']);
        } else {
          // 1st/2nd strike: Rhythm Game Ban Only
          $db->prepare("UPDATE users SET rhythm_strikes = ? WHERE id = ?")->execute([$new_strikes, $user_id]);
          $db->prepare("INSERT INTO admin_logs (admin_email, action, target_user_id, target_email) VALUES (?, ?, ?, ?)")
             ->execute(['SYSTEM (Game-Ban)', 'Strike ' . $new_strikes . ' Cheat: ' . $ban_reason, $user_id, 'SYSTEM']);
          http_response_code(403);
          send_json(['status' => 'error', 'message' => 'Cheat detected (Strike ' . $new_strikes . '/3): ' . $ban_reason . '. You are banned from the Rhythm Game.']);
        }
      }
      
      // Force-create table on demand to bypass any session installation cache
      try {
        $db->exec("CREATE TABLE IF NOT EXISTS rhythm_scores (
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
        );");
      } catch(Exception $e) {}
      try { $db->exec("ALTER TABLE rhythm_scores ADD COLUMN difficulty TEXT DEFAULT 'medium';"); } catch(Exception $e) {}

      $db->prepare("INSERT INTO rhythm_scores (user_id, song_id, score, max_combo, perfect, great, good, bad, miss, difficulty) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
         ->execute([$user_id, intval($data['song_id']), intval($data['score']), intval($data['max_combo']), intval($data['perfect']), intval($data['great']), intval($data['good']), intval($data['bad']), intval($data['miss']), $data['difficulty'] ?? 'medium']);
      send_json(['status' => 'success']);
      break;

    case 'get_rhythm_leaderboard':
      $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
      $offset = ($page - 1) * PAGE_SIZE;
      $limit_clause = " LIMIT " . PAGE_SIZE . " OFFSET " . $offset;
      // Force-create table on demand to bypass any session installation cache
      try {
        $db->exec("CREATE TABLE IF NOT EXISTS rhythm_scores (
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
        );");
      } catch(Exception $e) {}

      $stmt = $db->query("
        SELECT rs.user_id, u.artist as player_name, 
        SUM(rs.score) as total_score, 
        COUNT(rs.id) as total_plays,
        SUM(rs.perfect) as total_perfect,
        SUM(rs.great) as total_great,
        SUM(rs.good) as total_good,
        SUM(rs.bad) as total_bad,
        SUM(rs.miss) as total_miss
        FROM rhythm_scores rs
        JOIN users u ON rs.user_id = u.id
        GROUP BY rs.user_id
        ORDER BY total_score DESC
        $limit_clause
      ");
      send_json($stmt->fetchAll());
      break;

    case 'get_song_rhythm_leaderboard':
      // Force-create table on demand to bypass any session installation cache
      try {
        $db->exec("CREATE TABLE IF NOT EXISTS rhythm_scores (
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
        );");
      } catch(Exception $e) {}

      $song_id = intval($_GET['song_id'] ?? 0);
      $stmt = $db->prepare("
        SELECT rs.score, rs.max_combo, rs.perfect, rs.great, rs.good, rs.bad, rs.miss, u.artist as player_name, u.id as user_id
        FROM rhythm_scores rs
        JOIN users u ON rs.user_id = u.id
        WHERE rs.song_id = ?
        ORDER BY rs.score DESC, rs.max_combo DESC, rs.perfect DESC
        LIMIT 25
      ");
      $stmt->execute([$song_id]);
      send_json($stmt->fetchAll());
      break;

    case 'get_rhythm_chart':
      // Force-create table on demand to bypass any session installation cache
      try {
        $db->exec("CREATE TABLE IF NOT EXISTS rhythm_charts (
          song_id INTEGER NOT NULL,
          difficulty TEXT NOT NULL,
          notes_json TEXT,
          level INTEGER DEFAULT 1,
          PRIMARY KEY (song_id, difficulty),
          FOREIGN KEY (song_id) REFERENCES music(id) ON DELETE CASCADE
        );");
      } catch(Exception $e) {}

      $song_id = (int)$_GET['song_id'];
      $difficulty = $_GET['difficulty'];
      
      $stmt = $db->prepare("SELECT notes_json, level FROM rhythm_charts WHERE song_id = ? AND difficulty = ?");
      $stmt->execute([$song_id, $difficulty]);
      $chart = $stmt->fetch();
      
      $notes_arr = $chart ? json_decode($chart['notes_json'], true) : null;
      
      if ($chart && is_array($notes_arr) && count($notes_arr) > 0) {
        send_json([
          'status' => 'success', 
          'found' => true, 
          'notes' => $notes_arr, 
          'level' => $chart['level']
        ]);
      } else {
        // Automatically generate and cache in 1ms on the server to prevent client crashes! (Defaults to 1.0x spacing)
        $stmt_song = $db->prepare("SELECT duration FROM music WHERE id = ?");
        $stmt_song->execute([$song_id]);
        $duration = (int)$stmt_song->fetchColumn() ?: 180;
        
        $notes = generatePhpChart($song_id, $duration, $difficulty, 1.0);
        $level = calculatePhpLevel($notes, $duration, $difficulty, 1.0);
        
        $db->prepare("INSERT OR REPLACE INTO rhythm_charts (song_id, difficulty, notes_json, level) VALUES (?, ?, ?, ?)")
           ->execute([$song_id, $difficulty, json_encode($notes), $level]);
           
        send_json([
          'status' => 'success', 
          'found' => true, 
          'notes' => $notes, 
          'level' => $level
        ]);
      }
      break;

    case 'get_rhythm_levels':
      try {
        $db->exec("CREATE TABLE IF NOT EXISTS rhythm_charts (
          song_id INTEGER NOT NULL,
          difficulty TEXT NOT NULL,
          notes_json TEXT,
          level INTEGER DEFAULT 1,
          PRIMARY KEY (song_id, difficulty),
          FOREIGN KEY (song_id) REFERENCES music(id) ON DELETE CASCADE
        );");
      } catch(Exception $e) {}

      $song_id = (int)$_GET['song_id'];
      $stmt = $db->prepare("SELECT difficulty, level FROM rhythm_charts WHERE song_id = ?");
      $stmt->execute([$song_id]);
      $levels = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
      send_json(['status' => 'success', 'levels' => $levels]);
      break;

    case 'get_rhythm_artists':
      $sort_key = $_GET['sort'] ?? 'name_asc';
      $search = $_GET['q'] ?? '';
      $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
      $offset = ($page - 1) * PAGE_SIZE;
      $limit_clause = " LIMIT " . PAGE_SIZE . " OFFSET " . $offset;
      $is_super_admin = 0;
      $is_admin = 0;
      if (isset($_SESSION['user_id'])) {
        $stmt_admin = $db->prepare("SELECT status, is_admin FROM users WHERE id = ?");
        $stmt_admin->execute([$_SESSION['user_id']]);
        $admin_row = $stmt_admin->fetch();
        if ($admin_row) {
          if ($admin_row['status'] === 'super_admin') $is_super_admin = 1;
          if ($admin_row['is_admin'] == 1 || $admin_row['status'] === 'admin' || $is_super_admin) $is_admin = 1;
        }
      }

      $where = "WHERE u.email NOT LIKE 'deleted_%' AND u.banned = 0";
      $params = [$_SESSION['user_id'] ?? 0];

      if ($search !== '') {
        $where .= " AND match_text(u.artist, ?) = 1";
        $params[] = $search;
      }
      if (!empty($_GET['following_of'])) {
        $where .= " AND u.id IN (SELECT following_id FROM follows WHERE follower_id = ?)";
        $params[] = (int)$_GET['following_of'];
      }

      // Fetch all registered players and song creators, computing stats natively via SQL using indexed joins
      $sql = "
        SELECT 
          u.id as user_id,
          u.artist as name,
          (SELECT id FROM music WHERE user_id = u.id OR id IN (SELECT song_id FROM song_collaborators WHERE user_id = u.id) ORDER BY id DESC LIMIT 1) as id,
          (SELECT COUNT(*) FROM music WHERE (user_id = u.id OR id IN (SELECT song_id FROM song_collaborators WHERE user_id = u.id)) AND (is_private = 0 OR user_id = ? OR {$is_super_admin} = 1)) as count,
          COALESCE(SUM(rs.score), 0) as score,
          COALESCE(COUNT(rs.id), 0) as plays,
          COALESCE(SUM(rs.perfect), 0) as perfect,
          COALESCE(SUM(rs.great), 0) as great,
          COALESCE(SUM(rs.good), 0) as good,
          COALESCE(SUM(rs.bad), 0) as bad,
          COALESCE(SUM(rs.miss), 0) as miss,
          (SELECT COUNT(*) FROM follows WHERE following_id = u.id) as followers
        FROM users u
        LEFT JOIN rhythm_scores rs ON u.id = rs.user_id
        $where
        GROUP BY u.id, u.artist
      ";

      // Parse sorting natively in SQLite
      if ($sort_key === 'name_desc') {
        $sql .= " ORDER BY u.artist COLLATE NOCASE DESC";
      } elseif ($sort_key === 'count_desc') {
        $sql .= " ORDER BY count DESC, u.artist COLLATE NOCASE ASC";
      } elseif ($sort_key === 'followers_desc') {
        $sql .= " ORDER BY followers DESC, u.artist COLLATE NOCASE ASC";
      } elseif ($sort_key === 'score_desc') {
        $sql .= " ORDER BY score DESC, u.artist COLLATE NOCASE ASC";
      } elseif ($sort_key === 'rank_desc') {
        $sql .= " ORDER BY (
          (COALESCE(SUM(rs.perfect), 0) * 100.0 + COALESCE(SUM(rs.great), 0) * 75.0 + COALESCE(SUM(rs.good), 0) * 40.0 + COALESCE(SUM(rs.bad), 0) * 10.0) / 
          NULLIF(COALESCE(SUM(rs.perfect), 0) + COALESCE(SUM(rs.great), 0) + COALESCE(SUM(rs.good), 0) + COALESCE(SUM(rs.bad), 0) + COALESCE(SUM(rs.miss), 0), 0)
        ) DESC NULLS LAST, score DESC";
      } elseif ($sort_key === 'random') {
        $sql .= " ORDER BY RANDOM()";
      } else {
        $sql .= " ORDER BY u.artist COLLATE NOCASE ASC";
      }

      $sql .= $limit_clause;

      $stmt = $db->prepare($sql);
      $stmt->execute($params);
      send_json($stmt->fetchAll());
      break;

    case 'get_all_rhythm_levels':
      try {
        $db->exec("CREATE TABLE IF NOT EXISTS rhythm_charts (
          song_id INTEGER NOT NULL,
          difficulty TEXT NOT NULL,
          notes_json TEXT,
          level INTEGER DEFAULT 1,
          PRIMARY KEY (song_id, difficulty),
          FOREIGN KEY (song_id) REFERENCES music(id) ON DELETE CASCADE
        );");
      } catch(Exception $e) {}

      $stmt = $db->query("SELECT song_id, difficulty, level FROM rhythm_charts");
      $rows = $stmt->fetchAll();
      $map = [];
      foreach ($rows as $row) {
        $map[$row['song_id']][$row['difficulty']] = (int)$row['level'];
      }
      send_json(['status' => 'success', 'levels' => $map]);
      break;

    case 'get_rhythm_artists_stats':
      // Optimized bulk aggregator: returns followers, scores, and judgment metrics for all players
      $stmt = $db->query("
        SELECT u.id, u.artist as name,
        (SELECT COUNT(*) FROM follows WHERE following_id = u.id) as followers,
        (SELECT SUM(score) FROM rhythm_scores WHERE user_id = u.id) as total_score,
        (SELECT SUM(perfect) FROM rhythm_scores WHERE user_id = u.id) as total_perfect,
        (SELECT SUM(great) FROM rhythm_scores WHERE user_id = u.id) as total_great,
        (SELECT SUM(good) FROM rhythm_scores WHERE user_id = u.id) as total_good,
        (SELECT SUM(bad) FROM rhythm_scores WHERE user_id = u.id) as total_bad,
        (SELECT SUM(miss) FROM rhythm_scores WHERE user_id = u.id) as total_miss
        FROM users u WHERE u.banned = 0 AND u.email NOT LIKE 'deleted_%'
      ");
      send_json($stmt->fetchAll());
      break;

    case 'save_rhythm_chart':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $song_id = (int)$data['song_id'];
      $difficulty = $data['difficulty'];
      $notes_json = $data['notes_json'];
      $level = (int)$data['level'];
      
      $db->prepare("INSERT OR REPLACE INTO rhythm_charts (song_id, difficulty, notes_json, level) VALUES (?, ?, ?, ?)")
         ->execute([$song_id, $difficulty, $notes_json, $level]);
      send_json(['status' => 'success']);
      break;

    case 'get_user_rhythm_scores':
      $target_user_id = (int)$_GET['target_user_id'];
      $is_all = isset($_GET['all']) && $_GET['all'] == '1';
      
      try { $db->exec("ALTER TABLE rhythm_scores ADD COLUMN difficulty TEXT DEFAULT 'medium';"); } catch(Exception $e) {}
      try {
        $db->exec("CREATE TABLE IF NOT EXISTS rhythm_charts (
          song_id INTEGER NOT NULL,
          difficulty TEXT NOT NULL,
          notes_json TEXT,
          level INTEGER DEFAULT 1,
          PRIMARY KEY (song_id, difficulty),
          FOREIGN KEY (song_id) REFERENCES music(id) ON DELETE CASCADE
        );");
      } catch(Exception $e) {}

      $sql = "
        SELECT rs.*, m.title, m.artist, m.last_modified, rc.level
        FROM rhythm_scores rs
        JOIN music m ON rs.song_id = m.id
        LEFT JOIN rhythm_charts rc ON rs.song_id = rc.song_id AND rs.difficulty = rc.difficulty
        WHERE rs.user_id = ?
        ORDER BY rs.played_at DESC
      ";
      if (!$is_all) {
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $limit = 25;
        $offset = ($page - 1) * $limit;
        $sql .= " LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
      }
      
      $stmt = $db->prepare($sql);
      $stmt->bindValue(1, $target_user_id, PDO::PARAM_INT);
      $stmt->execute();
      send_json($stmt->fetchAll());
      break;

    case 'vacuum_database':
      $is_super_admin_check = false;
      if (isset($_SESSION['user_id'])) {
        $db_check = get_db();
        $stmt_chk = $db_check->prepare("SELECT status FROM users WHERE id = ?");
        $stmt_chk->execute([$_SESSION['user_id']]);
        if ($stmt_chk->fetchColumn() === 'super_admin') {
          $is_super_admin_check = true;
        }
      }
      if (!$is_super_admin_check) {
        die("Security violation: Super Admin required to optimize database.");
      }
      
      $attempt = isset($_GET['attempt']) ? (int)$_GET['attempt'] : 1;
      
      session_write_close();
      header('Content-Type: text/html; charset=utf-8');
      ob_implicit_flush();
      ?>
      <!DOCTYPE html>
      <html>
        <head>
          <meta charset="utf-8">
          <title>Database Optimization</title>
          <style>
            body { color: #e0e0e0; background-color: #030303; font-family: Consolas, 'Courier New', monospace; padding: 15px; margin: 0; }
            pre { white-space: pre-wrap; word-wrap: break-word; font-family: inherit; margin-top: 115px; }
            #header-wrap { position: fixed; top: 0; left: 0; right: 0; background: #121212; border-bottom: 1px solid #333; z-index: 10; }
            #prog-wrap { padding: 16px 15px 16px 15px; display: flex; align-items: center; }
            #prog-track { flex-grow: 1; background: #000; height: 12px; border-radius: 6px; overflow: hidden; margin-right: 15px; }
            #prog-bar { width: 0%; height: 100%; background: #00bcd4; transition: width 0.1s; }
            #prog-txt { font-weight: bold; min-width: 45px; text-align: right; font-size: 14px;}
            #warning-txt { padding: 0 15px 12px 15px; color: #ffbd2e; font-size: 12px; font-weight: bold; text-align: center; text-transform: uppercase; letter-spacing: 0.5px; }
            .success { color: #27c93f; }
            .warn { color: #ffbd2e; }
            .error { color: #ff3b30; }
          </style>
        </head>
        <body>
          <div id='header-wrap'>
            <div id='prog-wrap'><div id='prog-track'><div id='prog-bar'></div></div><div id='prog-txt'>0%</div></div>
            <div id='warning-txt'>⚠️ Optimizing database layout. Do not close this window!</div>
          </div>
          <pre id="log-output">PHP Music Library - SQLite VACUUM
===================================================

</pre>
          <script>
            const logArea = document.getElementById('log-output');
            const log = (msg, className = '') => {
              const span = document.createElement('span');
              span.className = className;
              span.textContent = msg + '\n';
              logArea.appendChild(span);
              window.scrollTo(0, document.body.scrollHeight);
            };
            const setProgress = (pct) => {
              document.getElementById('prog-bar').style.width = pct + '%';
              document.getElementById('prog-txt').innerText = pct + '%';
            };
          </script>
      <?php
      flush();
      
      $progress = min(90, $attempt * 5);
      echo "<script>setProgress({$progress}); log('Attempt {$attempt}: Requesting exclusive database lock...');</script>";
      flush();
      
      // CRITICAL FIX: Release all lingering PDOStatement read-locks from earlier script execution!
      $stmt_check = null; $stmt_fw = null; $stmt_sess = null; $stmt_key = null;
      $stmt_rl = null; $stmt_admin = null; $stmt_chk = null;
      
      $db = get_db();
      try {
        $before_size = filesize(DB_FILE);
        if ($attempt === 1) {
          echo "<script>log('Original database size: " . number_format($before_size / 1048576, 2) . " MB');</script>";
          flush();
        }
        
        // 1. Check if the server actually has enough physical disk space (needs ~1.5x the DB size)
        $free_space = @disk_free_space(__DIR__);
        if ($free_space !== false && $free_space < ($before_size * 1.5)) {
          throw new Exception("Insufficient Disk Space: You have " . number_format($free_space / 1048576, 2) . " MB free, but need at least " . number_format(($before_size * 1.5) / 1048576, 2) . " MB to safely vacuum.");
        }

        // 2. Prevent RAM exhaustion crashes. Default get_db() sets temp_store=MEMORY, which crashes on 1.5GB DBs!
        $db->exec("PRAGMA temp_store = FILE;");
        
        // 3. Force SQLite to use a local temp directory to bypass tiny /tmp RAM-disk limits on shared hosts
        $temp_dir = __DIR__ . '/.tmp_db';
        if (!is_dir($temp_dir)) @mkdir($temp_dir, 0755, true);
        putenv("TMPDIR=" . $temp_dir);
        putenv("SQLITE_TMPDIR=" . $temp_dir);
        @$db->exec("PRAGMA temp_store_directory = " . $db->quote($temp_dir) . ";");
        
        $db->exec("VACUUM");
        
        // 4. Clean up and restore memory pragma
        @$db->exec("PRAGMA temp_store_directory = '';");
        $db->exec("PRAGMA temp_store = MEMORY;");
        if (is_dir($temp_dir)) {
          array_map('unlink', glob("$temp_dir/*.*"));
          @rmdir($temp_dir);
        }
        
        clearstatcache(true, DB_FILE);
        $after_size = filesize(DB_FILE);
        $saved = $before_size - $after_size;
        $saved_mb = number_format($saved / 1048576, 2);
        $after_mb = number_format($after_size / 1048576, 2);
        
        echo "<script>setProgress(100); log('Database optimized successfully!', 'success');</script>";
        echo "<script>log('New database size: {$after_mb} MB');</script>";
        if ($saved > 0) {
          echo "<script>log('Space reclaimed: {$saved_mb} MB', 'success');</script>";
        } else {
          echo "<script>log('No significant fragmentation found. Database is optimal.');</script>";
        }

        // Bridge to automatically trigger the next scan step if called by the Rhythm Resetter
        $next_action = $_GET['next_action'] ?? '';
        $density = $_GET['density'] ?? '1.0';
        if ($next_action === 'rescan_charts') {
          echo "<script>log('\\nRedirecting to Division 1/6 (EASY) scan start...', 'info');</script>";
          echo "<script>setTimeout(() => { window.location.href = '?action=rescan_charts&step=1&run=1&density={$density}'; }, 2000);</script>";
        }

      } catch (Exception $e) {
        $msg = strtolower($e->getMessage());
        if (strpos($msg, 'locked') !== false || strpos($msg, 'busy') !== false || strpos($msg, 'in progress') !== false) {
          if ($attempt < 30) {
            $next = $attempt + 1;
            $pass_next = isset($_GET['next_action']) ? "&next_action=" . urlencode($_GET['next_action']) : "";
            $pass_dens = isset($_GET['density']) ? "&density=" . urlencode($_GET['density']) : "";
            echo "<script>log('Database is currently busy/locked by other readers. Retrying in 2 seconds...', 'warn');</script>";
            echo "<script>setTimeout(() => { window.location.href = '?action=vacuum_database&attempt={$next}{$pass_next}{$pass_dens}'; }, 2000);</script>";
          } else {
            echo "<script>setProgress(100); log('Failed to acquire lock after 30 attempts. Please try again later when traffic is lower.', 'error');</script>";
          }
        } else {
          echo "<script>setProgress(100); log('VACUUM failed: " . addslashes($e->getMessage()) . "', 'error');</script>";
        }
      }
      echo "</body></html>";
      exit;

    case 'reset_rhythm_charts':
      // Robust, direct database validation to verify Super Admin credentials
      $is_super_admin = false;
      if (isset($_SESSION['user_id'])) {
        $db_check = get_db();
        $stmt_chk = $db_check->prepare("SELECT status FROM users WHERE id = ?");
        $stmt_chk->execute([$_SESSION['user_id']]);
        if ($stmt_chk->fetchColumn() === 'super_admin') {
          $is_super_admin = true;
        }
      }
      if (!$is_super_admin) {
        die("Security violation: Super Admin session required.");
      }

      session_write_close();
      
      $start_time = microtime(true);
      header('Content-Type: text/html; charset=utf-8');
      ob_implicit_flush();
      ?>
      <!DOCTYPE html>
      <html>
        <head>
          <meta charset="utf-8">
          <title>Rhythm Game Charts Resetting</title>
          <style>
            body { color: #e0e0e0; background-color: #030303; font-family: Consolas, 'Courier New', monospace; padding: 15px; margin: 0; }
            pre { white-space: pre-wrap; word-wrap: break-word; font-family: inherit; margin-top: 115px; }
            #header-wrap { position: fixed; top: 0; left: 0; right: 0; background: #121212; border-bottom: 1px solid #333; z-index: 10; }
            #prog-wrap { padding: 16px 15px 16px 15px; display: flex; align-items: center; }
            #prog-track { flex-grow: 1; background: #000; height: 12px; border-radius: 6px; overflow: hidden; margin-right: 15px; }
            #prog-bar { width: 0%; height: 100%; background: #ff3b30; transition: width 0.1s; }
            #prog-txt { font-weight: bold; min-width: 45px; text-align: right; font-size: 14px;}
            #warning-txt { padding: 0 15px 12px 15px; color: #ff3b30; font-size: 12px; font-weight: bold; text-align: center; text-transform: uppercase; letter-spacing: 0.5px; }
            .success { color: #27c93f; }
            .info { color: #00bcd4; }
            .warn { color: #ffbd2e; }
          </style>
        </head>
        <body>
          <div id='header-wrap'>
            <div id='prog-wrap'><div id='prog-track'><div id='prog-bar'></div></div><div id='prog-txt'>0%</div></div>
            <div id='warning-txt'>⚠️ Wiping note charts to prepare for regeneration!</div>
          </div>
          <pre id="log-output">PHP Music Library - Note Charts Reset & Wipe Scanner
===================================================

</pre>
          <script>
            const logArea = document.getElementById('log-output');
            const log = (msg, className = '') => {
              const span = document.createElement('span');
              span.className = className;
              span.textContent = msg + '\n';
              logArea.appendChild(span);
              window.scrollTo(0, document.body.scrollHeight);
            };
            const setProgress = (pct) => {
              document.getElementById('prog-bar').style.width = pct + '%';
              document.getElementById('prog-txt').innerText = pct + '%';
            };
          </script>
      <?php
      flush();

      $db = get_db();
      $density_multiplier = isset($_GET['density']) ? max(0.1, min(10.0, (float)$_GET['density'])) : 1.0;

      echo "<script>setProgress(50); log('Dropping rhythm_charts table to instantly wipe all charts...', 'info');</script>";
      flush();

      // CRITICAL FIX: Release all lingering PDOStatement read-locks from earlier script execution before DDL!
      $stmt_check = null; $stmt_fw = null; $stmt_sess = null; $stmt_key = null;
      $stmt_rl = null; $stmt_admin = null; $stmt_chk = null;

      try {
        $db->exec("DROP TABLE IF EXISTS rhythm_charts");
        $db->exec("CREATE TABLE IF NOT EXISTS rhythm_charts (
          song_id INTEGER NOT NULL,
          difficulty TEXT NOT NULL,
          notes_json TEXT,
          level INTEGER DEFAULT 1,
          PRIMARY KEY (song_id, difficulty),
          FOREIGN KEY (song_id) REFERENCES music(id) ON DELETE CASCADE
        );");
        
        echo "<script>setProgress(100); log('All note charts successfully wiped and table recreated!', 'success');</script>";
        echo "<script>log('Redirecting to Chart Generator...', 'info');</script>";
        echo "<script>setTimeout(() => { window.location.href = '?action=rescan_charts&step=1&run=1&density=" . $density_multiplier . "'; }, 1000);</script>";
      } catch (Exception $e) {
        echo "<script>log('Error during wipe: " . addslashes($e->getMessage()) . "', 'warn');</script>";
      }

      echo "</body></html>";
      exit;

    case 'rescan_charts':
      // Robust, direct database validation to verify admin credentials
      $is_active_admin = false;
      $is_super_admin = false;
      if (isset($_SESSION['user_id'])) {
        $db_check = get_db();
        $stmt_chk = $db_check->prepare("SELECT status, is_admin FROM users WHERE id = ?");
        $stmt_chk->execute([$_SESSION['user_id']]);
        $u_chk = $stmt_chk->fetch();
        if ($u_chk && ($u_chk['is_admin'] == 1 || $u_chk['status'] === 'admin' || $u_chk['status'] === 'super_admin')) {
          $is_active_admin = true;
          if ($u_chk['status'] === 'super_admin') {
            $is_super_admin = true;
          }
        }
      }
      if (!$is_active_admin) {
        die("Security violation: Admin session required.");
      }
      
      // Concurrency Win 1: Release write-lock on session file immediately so other browser tabs don't freeze
      session_write_close();
      
      $start_time = microtime(true);
      header('Content-Type: text/html; charset=utf-8');
      ob_implicit_flush();

      $step = isset($_GET['step']) ? max(1, min(6, (int)$_GET['step'])) : 1;
      $density_multiplier = isset($_GET['density']) ? max(0.1, min(10.0, (float)$_GET['density'])) : 1.0;
      $diff_map = [
        1 => 'easy',
        2 => 'medium',
        3 => 'hard',
        4 => 'expert',
        5 => 'master',
        6 => 'demon'
      ];
      $current_diff = $diff_map[$step];
      ?>
      <!DOCTYPE html>
      <html>
        <head>
          <meta charset="utf-8">
          <title>Rhythm Game Charts Scanner</title>
          <style>
            body { color: #e0e0e0; background-color: #030303; font-family: Consolas, 'Courier New', monospace; padding: 15px; margin: 0; }
            pre { white-space: pre-wrap; word-wrap: break-word; font-family: inherit; margin-top: 115px; }
            #header-wrap { position: fixed; top: 0; left: 0; right: 0; background: #121212; border-bottom: 1px solid #333; z-index: 10; }
            #prog-wrap { padding: 16px 15px 16px 15px; display: flex; align-items: center; }
            #prog-track { flex-grow: 1; background: #000; height: 12px; border-radius: 6px; overflow: hidden; margin-right: 15px; }
            #prog-bar { width: 0%; height: 100%; background: #ff3b30; transition: width 0.1s; }
            #prog-txt { font-weight: bold; min-width: 45px; text-align: right; font-size: 14px;}
            #warning-txt { padding: 0 15px 12px 15px; color: #ff3b30; font-size: 12px; font-weight: bold; text-align: center; text-transform: uppercase; letter-spacing: 0.5px; }
            .success { color: #27c93f; }
            .info { color: #00bcd4; }
            .warn { color: #ffbd2e; }
          </style>
        </head>
        <body>
          <div id='header-wrap'>
            <div id='prog-wrap' style="position: relative; margin-bottom: 8px;">
              <div id='prog-track' style="margin-right: <?php echo $is_super_admin ? '180px' : '15px'; ?>;">
                <div id='prog-bar'></div>
              </div>
              <div id='prog-txt' style="position: absolute; right: <?php echo $is_super_admin ? '180px' : '0'; ?>; top: 15px; margin-right: 15px;">0%</div>
              <?php if ($is_super_admin): ?>
                <button id="super-admin-reset-btn" style="position: absolute; right: 15px; top: 10px; background-color: #ff3b30; color: #fff; border: none; border-radius: 20px; padding: 4px 16px; font-family: inherit; font-size: 11px; font-weight: bold; cursor: pointer; height: 26px;">Reset & Re-scan All</button>
              <?php endif; ?>
            </div>
            <div id='warning-txt' style="margin-bottom: 8px;">⚠️ Division <?php echo $step; ?>/6 (<?php echo strtoupper($current_diff); ?>): Scanning and generating permanently on the server!</div>
          </div>
          <pre id="log-output">PHP Music Library - Server-Side Note Charts Generation Scanner
=============================================================

</pre>
          <script>
            const logArea = document.getElementById('log-output');
            const log = (msg, className = '') => {
              const span = document.createElement('span');
              span.className = className;
              span.textContent = msg + '\n';
              logArea.appendChild(span);
              window.scrollTo(0, document.body.scrollHeight);
            };
            const setProgress = (pct) => {
              document.getElementById('prog-bar').style.width = pct + '%';
              document.getElementById('prog-txt').innerText = pct + '%';
            };

            // Super Admin Wiping Routine (Direct redirect to bypass AJAX timeouts and locking)
            const resetBtn = document.getElementById('super-admin-reset-btn');
            if (resetBtn) {
              resetBtn.addEventListener('click', () => {
                if (confirm('Are you sure you want to completely WIPE all existing note charts and levels from the database and regenerate them from scratch? This action is irreversible!')) {
                  localStorage.setItem('rhythm_scan_step', '1');
                  const currentUrl = window.location.href;
                  const urlParams = new URLSearchParams(currentUrl.split('?')[1]);
                  const activeDensity = urlParams.get('density') || '1.0';
                  window.location.href = '?action=reset_rhythm_charts&density=' + activeDensity;
                }
              });
            }
          </script>
      <?php
      flush();

      $db = get_db();
      
      // Auto-repair broken schemas from previous versions (Single PK bug fix)
      if ($step === 1) {
        try {
          $columns = $db->query("PRAGMA table_info(rhythm_charts)")->fetchAll(PDO::FETCH_ASSOC);
          $pk_count = 0;
          foreach ($columns as $col) {
            if ($col['pk'] > 0) $pk_count++;
          }
          if ($pk_count < 2 && count($columns) > 0) {
            $db->exec("DROP TABLE IF EXISTS rhythm_charts");
            $db->exec("CREATE TABLE rhythm_charts (
              song_id INTEGER NOT NULL,
              difficulty TEXT NOT NULL,
              notes_json TEXT,
              level INTEGER DEFAULT 1,
              PRIMARY KEY (song_id, difficulty),
              FOREIGN KEY (song_id) REFERENCES music(id) ON DELETE CASCADE
            );");
          }
        } catch(Exception $e) {}
      }
      
      // Get global totals for progress tracking across page reloads
      $total_songs = (int)$db->query("SELECT COUNT(id) FROM music")->fetchColumn();
      $total_possible_charts = $total_songs * 6;
      $completed_charts = (int)$db->query("SELECT COUNT(*) FROM rhythm_charts WHERE notes_json IS NOT NULL AND notes_json != '[]' AND notes_json != ''")->fetchColumn();

      // Query ONLY the first 25 songs missing the chart for THIS specific difficulty step
      // This ensures only 1 fast SQLite write (~0.5ms) is committed per song, fully eliminating database locks!
      $stmt = $db->prepare("
        SELECT id, title, artist, duration FROM music 
        WHERE id NOT IN (
          SELECT song_id FROM rhythm_charts WHERE difficulty = ? AND notes_json IS NOT NULL AND notes_json != '[]' AND notes_json != ''
        )
        LIMIT 25
      ");
      $stmt->execute([$current_diff]);
      $songs_to_scan = $stmt->fetchAll();
      $to_scan_count = count($songs_to_scan);

      echo "<script>log('Current Division [{$step}/6: " . strtoupper($current_diff) . "]. Progress: " . $completed_charts . "/" . $total_possible_charts . " compiled. Remaining in this division: " . $to_scan_count . " songs.', 'info');</script>";
      flush();

      if ($to_scan_count === 0) {
        if ($step < 6) {
          $next_step = $step + 1;
          echo "<script>log('\\nDivision " . $step . " complete! Progressing to Division " . $next_step . "/6...', 'success');</script>";
          echo "<script>setTimeout(() => { window.location.href = '?action=rescan_charts&step=" . $next_step . "&run=1&density=" . $density_multiplier . "'; }, 1000);</script>";
        } else {
          echo "<script>setProgress(100); log('All songs already have compiled note charts! Scan complete.', 'success'); localStorage.setItem('rhythm_scan_step', '1'); if (window.parent && window.parent.finishScan) window.parent.finishScan('chart-scan-modal');</script>";
        }
        echo "</body></html>";
        exit;
      }

      try {
        foreach ($songs_to_scan as $song) {
          $completed_charts++;
          $pct = round(($completed_charts / $total_possible_charts) * 100);
          
          $escapedTitle = addslashes($song['title']);
          $escapedArtist = addslashes($song['artist']);
          
          echo "<script>log('[Division {$step}/6] Generating: {$escapedTitle} - {$escapedArtist}...');</script>";
          flush();

          $duration = (int)($song['duration'] ?: 180);

          // Concurrency Win 2: Wrap each individual song's single difficulty chart in its own transaction
          // This is insanely fast (<0.5ms) and makes locking mathematically impossible
          $db->beginTransaction();

          $notes = generatePhpChart($song['id'], $duration, $current_diff, $density_multiplier);
          $level = calculatePhpLevel($notes, $duration, $current_diff, $density_multiplier);

          $db->prepare("INSERT OR REPLACE INTO rhythm_charts (song_id, difficulty, notes_json, level) VALUES (?, ?, ?, ?)")
             ->execute([$song['id'], $current_diff, json_encode($notes), $level]);

          $db->commit();

          echo "<script>setProgress({$pct}); if (window.parent && window.parent.updateScanPill) window.parent.updateScanPill({$completed_charts}, {$total_possible_charts}, 'Compiling Charts');</script>";
          flush();
          
          // Concurrency Win 3: Micro-sleep (10ms) between songs
          usleep(10000);
        }
      } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo "<script>log('Fatal Error during transaction: " . addslashes($e->getMessage()) . "', 'warn');</script>";
        flush();
      }

      // Auto-resume current division with 1 second delay
      echo "<script>log('\\nBatch of 25 songs in Division {$step} completed. Pausing 1s to allow database cooldown...', 'info');</script>";
      echo "<script>setTimeout(() => { window.location.href = '?action=rescan_charts&step=" . $step . "'; }, 1000);</script>";
      echo "</body></html>";
      exit;

    case 'toggle_rhythm_favorite':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $song_id = intval($data['song_id']);
      
      // Force-create table on demand to bypass any session installation cache
      try {
        $db->exec("CREATE TABLE IF NOT EXISTS rhythm_favorites (
          user_id INTEGER NOT NULL,
          song_id INTEGER NOT NULL,
          sort_order INTEGER,
          PRIMARY KEY (user_id, song_id),
          FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
          FOREIGN KEY (song_id) REFERENCES music(id) ON DELETE CASCADE
        );");
        $db->exec("ALTER TABLE rhythm_favorites ADD COLUMN sort_order INTEGER;");
      } catch(Exception $e) {}

      $stmt = $db->prepare("SELECT 1 FROM rhythm_favorites WHERE user_id = ? AND song_id = ?");
      $stmt->execute([$user_id, $song_id]);
      if ($stmt->fetch()) {
        $db->prepare("DELETE FROM rhythm_favorites WHERE user_id = ? AND song_id = ?")->execute([$user_id, $song_id]);
        send_json(['status' => 'removed', 'is_favorite' => 0]);
      } else {
        $stmt_order = $db->prepare("SELECT MAX(sort_order) FROM rhythm_favorites WHERE user_id = ?");
        $stmt_order->execute([$user_id]);
        $max_order = (int)$stmt_order->fetchColumn() ?: 0;
        $db->prepare("INSERT INTO rhythm_favorites (user_id, song_id, sort_order) VALUES (?, ?, ?)")->execute([$user_id, $song_id, $max_order + 1]);
        send_json(['status' => 'added', 'is_favorite' => 1]);
      }
      break;

    case 'get_rhythm_favorite_songs':
      if (!$user_id) { send_json([]); }
      
      // Force-create table and safely alter column on demand to bypass database migration gaps
      try {
        $db->exec("CREATE TABLE IF NOT EXISTS rhythm_favorites (
          user_id INTEGER NOT NULL,
          song_id INTEGER NOT NULL,
          sort_order INTEGER,
          PRIMARY KEY (user_id, song_id),
          FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
          FOREIGN KEY (song_id) REFERENCES music(id) ON DELETE CASCADE
        );");
      } catch(Exception $e) {}
      try {
        $db->exec("ALTER TABLE rhythm_favorites ADD COLUMN sort_order INTEGER;");
      } catch(Exception $e) {}

      $sort_key = $_GET['sort'] ?? 'manual_order';
      $search = $_GET['q'] ?? '';
      $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
      $offset = ($page - 1) * PAGE_SIZE;
      $limit_clause = " LIMIT " . PAGE_SIZE . " OFFSET " . $offset;

      $sort_map = [
        'manual_order' => 'ORDER BY rf.sort_order DESC',
        'random' => 'ORDER BY RANDOM()',
        'trending' => 'ORDER BY play_count DESC',
        'id_desc' => 'ORDER BY m.id DESC',
        'title_asc' => 'ORDER BY m.title COLLATE NOCASE ASC',
        'artist_asc' => 'ORDER BY m.artist COLLATE NOCASE ASC',
      ];
      $order_by = $sort_map[$sort_key] ?? $sort_map['manual_order'];

      $target_id = !empty($_GET['target_user_id']) ? (int)$_GET['target_user_id'] : $user_id;
      $where = "WHERE rf.user_id = ?";
      $params = [$target_id];
      if ($search !== '') {
        $where .= " AND (match_text(m.title, ?) = 1 OR match_text(m.artist, ?) = 1)";
        $q_param = $search;
        $params[] = $q_param;
        $params[] = $q_param;
      }

      $stmt = $db->prepare("
        SELECT m.id, m.title, m.artist, m.album, m.genre, m.duration, m.user_id, m.is_private, m.is_collaborative, m.last_modified,
        1 AS rg_favorite,
        (SELECT SUM(play_count) FROM play_counts WHERE song_id = m.id) as play_count
        FROM music m
        JOIN rhythm_favorites rf ON m.id = rf.song_id
        $where $order_by $limit_clause
      ");
      $stmt->execute($params);
      $songs_result = $stmt->fetchAll();

      // Optimize local play: Embed level metadata directly
      if (!empty($songs_result)) {
        $song_ids = array_column($songs_result, 'id');
        $placeholders = implode(',', array_fill(0, count($song_ids), '?'));
        $stmt_lvl = $db->prepare("SELECT song_id, difficulty, level FROM rhythm_charts WHERE song_id IN ($placeholders)");
        $stmt_lvl->execute($song_ids);
        $lvl_rows = $stmt_lvl->fetchAll();
        $lvl_map = [];
        foreach ($lvl_rows as $row) {
          $lvl_map[$row['song_id']][$row['difficulty']] = (int)$row['level'];
        }
        foreach ($songs_result as &$song) {
          $song['levels'] = $lvl_map[$song['id']] ?? null;
        }
        unset($song);
      }

      send_json($songs_result);
      break;

    case 'get_rhythm_favorites':
      if (!$user_id) { send_json([]); }

      // Force-create table on demand to bypass any session installation cache
      try {
        $db->exec("CREATE TABLE IF NOT EXISTS rhythm_favorites (
          user_id INTEGER NOT NULL,
          song_id INTEGER NOT NULL,
          sort_order INTEGER,
          PRIMARY KEY (user_id, song_id),
          FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
          FOREIGN KEY (song_id) REFERENCES music(id) ON DELETE CASCADE
        );");
        $db->exec("ALTER TABLE rhythm_favorites ADD COLUMN sort_order INTEGER;");
      } catch(Exception $e) {}

      try {
        $stmt = $db->prepare("SELECT song_id FROM rhythm_favorites WHERE user_id = ? ORDER BY sort_order DESC");
        $stmt->execute([$user_id]);
        send_json($stmt->fetchAll(PDO::FETCH_COLUMN));
      } catch (Exception $e) {
        // Robust fallback to prevent 500 crashes if older database files haven't fully committed column updates
        $stmt = $db->prepare("SELECT song_id FROM rhythm_favorites WHERE user_id = ?");
        $stmt->execute([$user_id]);
        send_json($stmt->fetchAll(PDO::FETCH_COLUMN));
      }
      break;

