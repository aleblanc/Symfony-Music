<?php
// Chart generation, extracted VERBATIM from `legacy/php_music.php`
// (lines 22145-22373). THIS IS THE HEART OF THE ANSWER TO "is it based
// on special MP3s or does it work on anything?" — it is 100%
// deterministic pseudo-random generation (xorshift RNG seeded from
// song_id+difficulty+duration), NOT real audio/beat analysis. The
// ONLY real audio-derived input is `duration` (seconds), read from
// the `music` table (already populated by getID3 at scan time in this
// Symfony port — see App\\Service\\MusicScanner). Works on ANY track,
// no special preparation needed, but notes are NEVER truly synced to
// the real beat/rhythm of the audio — "drops"/chorus sections are
// just a coin-flip every ~20s (`if ($t % 20 < 1) $is_drop = ...`), not
// detected. See README.md's "Chart generation: two paths forward"
// section for what upgrading to real onset/beat detection would take.

function generatePhpChart($song_id, $duration, $difficulty, $density_multiplier = 1.0) {
  // 1. Highly sophisticated deterministic seed generator based on song ID and duration
  $seed_string = $song_id . '_' . $difficulty . '_' . $duration;
  $seed = crc32($seed_string) + hexdec(substr(md5($seed_string), 0, 8));
  
  // 2. Xorshift RNG for superior distribution compared to standard LCG
  $rng = function() use (&$seed) {
    $seed ^= $seed << 13;
    $seed ^= $seed >> 17;
    $seed ^= $seed << 5;
    // Keep within 32-bit unsigned bounds and return float 0.0 to 1.0
    return abs($seed % 1000000) / 1000000;
  };

  $lanesCount = ($difficulty === 'easy' || $difficulty === 'medium') ? 4 : ($difficulty === 'demon' ? 10 : 6);
  
  // 3. Dynamic base spacing calculation
  $base_spacing = 0.500;
  if ($difficulty === 'easy') $base_spacing = 0.850;
  elseif ($difficulty === 'medium') $base_spacing = 0.500;
  elseif ($difficulty === 'hard') $base_spacing = 0.320;
  elseif ($difficulty === 'expert') $base_spacing = 0.200;
  elseif ($difficulty === 'master') $base_spacing = 0.140;
  elseif ($difficulty === 'demon') $base_spacing = 0.025; // 3x extreme density for 10-lane demon

  $spacing = $base_spacing / max(0.1, min(10.0, $density_multiplier));

  $notes = [];
  $totalTime = $duration ?: 180;
  
  // 4. Advanced mechanic probabilities per difficulty tier
  $chordChance = 0.0;
  $tripleChordChance = 0.0;
  
  if ($difficulty === 'hard') { $chordChance = 0.18; }
  elseif ($difficulty === 'expert') { $chordChance = 0.35; $tripleChordChance = 0.05; }
  elseif ($difficulty === 'master') { $chordChance = 0.55; $tripleChordChance = 0.15; }
  elseif ($difficulty === 'demon') { $chordChance = 0.75; $tripleChordChance = 0.30; }
  
  $swipeChance = ($difficulty === 'easy') ? 0.0 : (($difficulty === 'medium') ? 0.08 : 0.25);
  $longChance = ($difficulty === 'demon') ? 0.10 : 0.20; // Less holds in demon, more tap stamina drain

  $lane_busy_until = array_fill(0, $lanesCount, 0.0);
  $restrict_holds = in_array($difficulty, ['easy', 'medium', 'hard']);
  $any_hold_until = 0.0;

  $is_drop = false;
  $current_step = $spacing;
  
  // 5. Chart Generation Loop with Musical Phrasing Simulation
  for ($t = 1.0; $t < $totalTime - 2.0; $t += $current_step) {
    
    // Simulate musical phrasing/drops (increases density by 20% during 'chorus' sections)
    if ($t % 20 < 1) {
      $is_drop = ($rng() > 0.5);
    }
    $current_step = $is_drop ? ($spacing * 0.8) : $spacing;

    // Add micro-stutters and syncopation to high difficulties
    if ($difficulty === 'demon' || $difficulty === 'master') {
      if ($rng() > 0.85) $current_step *= 0.5; // Double time burst
      if ($rng() > 0.96) $current_step *= 1.5; // Rest/syncopation break
    }

    if ($restrict_holds && $t < $any_hold_until) {
      continue;
    }

    $noteCount = 1;
    if ($rng() < $chordChance) $noteCount = 2;
    if ($rng() < $tripleChordChance && $lanesCount === 6) $noteCount = 3;

    $allowLong = !($restrict_holds && $noteCount > 1);
    $usedLanes = [];
    
    for ($i = 0; $i < $noteCount; $i++) {
      $lane = floor($rng() * $lanesCount) % $lanesCount;
      $attempts = 0;
      
      // Semi-random lane searching to create stairs/trills instead of vertical stacks
      while ((in_array($lane, $usedLanes) || $t < $lane_busy_until[$lane]) && $attempts < $lanesCount * 2) {
        $lane = ($lane + ($rng() > 0.5 ? 1 : -1) + $lanesCount) % $lanesCount;
        $attempts++;
      }
      if ($attempts >= $lanesCount * 2) continue;
      
      $usedLanes[] = $lane;
      
      $isLong = $allowLong && ($rng() < $longChance);
      $isSwipe = !$isLong && ($rng() < $swipeChance);
      $hitEndSwipe = $isLong && ($rng() < $swipeChance);
      
      $dur = 0;
      if ($isLong) {
        $durBase = 0.2 + ($rng() * 0.6);
        $dur = round($durBase / $spacing) * $spacing;
        if ($dur < 0.2) $dur = 0.2;
      }
      
      $lane_busy_until[$lane] = $t + $dur + 0.05; // 50ms anti-overlap buffer
      if ($isLong) {
        $any_hold_until = max($any_hold_until, $t + $dur);
      }
      
      $note = [
        'time' => round($t + 2.0, 3),
        'lane' => (int)$lane
      ];
      if ($isLong) {
        $note['isLong'] = true;
        $note['endTime'] = round($t + 2.0 + $dur, 3);
        if ($hitEndSwipe) {
          $note['hitEndSwipe'] = true;
        }
      } elseif ($isSwipe) {
        $note['isSwipe'] = true;
      }
      $notes[] = $note;
    }
  }
  return $notes;
}

function calculatePhpLevel($notes, $duration, $difficulty = 'medium', $density_multiplier = 1.0) {
  if (!$duration || $duration <= 0 || empty($notes)) return 1;

  $total_notes = count($notes);
  $nps = ($total_notes / $duration) / max(0.1, min(10.0, $density_multiplier));
  
  // 1. Calculate Peak NPS (Rolling 5-second intense window analysis)
  $peak_nps = 0;
  $window_size = 5.0;
  for ($i = 0; $i < $duration; $i += 1.0) {
    $window_notes = 0;
    foreach ($notes as $n) {
      if ($n['time'] >= $i && $n['time'] < $i + $window_size) {
        $window_notes++;
      }
      if ($n['time'] >= $i + $window_size) break; // Optimization exit
    }
    $current_window_nps = $window_notes / $window_size;
    if ($current_window_nps > $peak_nps) {
      $peak_nps = $current_window_nps;
    }
  }

  // 2. Compute Pattern Complexity (Lane variance & Chords & Rhythm irregularity)
  $chords = 0;
  $swipes = 0;
  $holds = 0;
  $cross_lane_distance = 0;
  $last_lane = 1;
  $last_time = 0;
  $time_variance_sum = 0;
  $interval_count = 0;

  foreach ($notes as $n) {
    if (!empty($n['isLong'])) $holds++;
    if (!empty($n['isSwipe'])) $swipes++;
    
    // Detect simultaneous chords (time diff < 0.05s)
    if (($n['time'] - $last_time) < 0.05) {
      $chords++;
    } else {
      if ($interval_count > 0 && $nps > 0) {
        $time_variance_sum += pow(($n['time'] - $last_time) - (1/$nps), 2);
      }
      $interval_count++;
    }
    
    $cross_lane_distance += abs($n['lane'] - $last_lane);
    $last_lane = $n['lane'];
    $last_time = $n['time'];
  }

  $chord_ratio = $total_notes > 0 ? ($chords / $total_notes) : 0;
  $swipe_ratio = $total_notes > 0 ? ($swipes / $total_notes) : 0;
  $hold_ratio = $total_notes > 0 ? ($holds / $total_notes) : 0;
  $avg_jump = $total_notes > 0 ? ($cross_lane_distance / $total_notes) : 0;
  $rhythm_irregularity = $interval_count > 0 ? sqrt($time_variance_sum / $interval_count) : 0;

  // 3. Base Algorithmic Polynomial Score Generation
  $base_complexity = ($nps * 1.4) + ($peak_nps * 0.9) + ($avg_jump * 3.0) + ($rhythm_irregularity * 12);
  $mechanic_weight = ($chord_ratio * 18) + ($swipe_ratio * 22) + ($hold_ratio * 5);
  
  $raw_score = $base_complexity + $mechanic_weight;

  // 4. Bracket Definition & Tier Multipliers
  $level = 1;

  switch ($difficulty) {
    case 'easy':
      $level = ($raw_score * 0.15) + 1;
      $level = max(1, min(5, $level));
      break;
    case 'medium':
      $level = ($raw_score * 0.35) + 4;
      $level = max(6, min(12, $level));
      break;
    case 'hard':
      $level = ($raw_score * 0.65) + 10;
      $level = max(13, min(24, $level));
      break;
    case 'expert':
      $level = ($raw_score * 1.1) + 22;
      $level = max(25, min(45, $level));
      break;
    case 'master':
      $level = ($raw_score * 1.8) + 40;
      $level = max(46, min(75, $level));
      break;
    case 'demon':
      // 20x multiplier compared to baseline metrics to represent a massive, yet logical leap
      $level = ($raw_score * 20.0) + 80;
      
      // Adds deterministic chaos based on absolute note count to ensure every song has vastly unique numbers
      $level += ($total_notes % 50) + ($duration % 10); 
      $level = max(76, min(999, $level));
      break;
    default:
      $level = 1;
  }

  // 5. Apply micro-variance offset based on CRC validation so songs with identical stats still have unique difficulty footprints
  $variance = (crc32($total_notes . $duration) % 5) - 2;
  $final_level = round($level) + $variance;

  return max(1, (int)$final_level);
}
