<?php

/*
 * Standalone test for SpotifyMatcher's offline logic (no network): title
 * cleaning, "Artist - Title" recovery when the artist tag is unknown, and
 * candidate scoring (accept the right track, reject covers/other songs).
 *
 * Run:  php tests/spotify-matcher-test.php   (exit 0 = pass)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Service\Spotify\SpotifyMatcher;
use App\Service\Spotify\SpotifyTrack;

$matcher = new SpotifyMatcher();
$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if ($ok) {
        echo "  ok  {$label}\n";
    } else {
        ++$failures;
        echo "  FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function track(string $name, array $artists, ?int $duration): SpotifyTrack
{
    return new SpotifyTrack('x', $name, $artists, null, $duration);
}

echo "cleanTitle\n";
foreach ([
    ['Fight Till You Die - 2005 Remaster', 'Fight Till You Die'],
    ['Here To Stay (Remastered 2011)', 'Here To Stay'],
    ['What You Want (feat. Justice)', 'What You Want'],
    ['Chaos Lives In Everything (feat. Skrillex)', 'Chaos Lives In Everything'],
    ['Blind [Official Video]', 'Blind'],
    ['01 - Twist', 'Twist'],
    ['99 Problems', '99 Problems'],
    ['A.D.I.D.A.S.', 'A.D.I.D.A.S.'],
] as [$in, $expected]) {
    $out = $matcher->cleanTitle($in);
    check("\"{$in}\" → \"{$expected}\"", $out === $expected, "got \"{$out}\"");
}

echo "searchTerms\n";
[$t, $a] = $matcher->searchTerms('Pennywise - Fight Till You Die - 2005 Remaster', 'Unknown Artist');
check('artist recovered from title when tag is unknown', $t === 'Fight Till You Die' && $a === ['Pennywise'], json_encode([$t, $a]));
[$t, $a] = $matcher->searchTerms('What You Want', 'Angèle, Justice');
check('multiple artists split', $a === ['Angèle', 'Justice'], json_encode($a));
[$t, $a] = $matcher->searchTerms('Pennywise- California Dreaming', 'Pennywise');
check('artist prefix stripped from title', $t === 'California Dreaming', $t);
[$t, $a] = $matcher->searchTerms('Get Low', 'Lil Jon & The East Side Boyz');
check('"&" splits artists', $a === ['Lil Jon', 'The East Side Boyz'], json_encode($a));

echo "score\n";
$s = $matcher->score('Here To Stay', ['Korn'], 271, track('Here To Stay', ['Korn'], 271));
check('exact match scores high', $s !== null && $s > 0.95, var_export($s, true));
$s = $matcher->score('What You Want', ['Angèle', 'Justice'], 188, track('What You Want', ['Angele', 'Justice'], 189));
check('accents ignored, any shared artist counts', $s !== null && $s > 0.9, var_export($s, true));
$s = $matcher->score('Adam\'s song', ['Blink 182'], 250, track('Adam\'s Song', ['blink-182'], 249));
check('"Blink 182" ≈ "blink-182"', $s !== null && $s >= SpotifyMatcher::MIN_SCORE, var_export($s, true));
$s = $matcher->score('Fight Till You Die', ['Pennywise'], 143, track('Fight Till You Die - 2005 Remaster', ['Pennywise'], 143));
check('remaster suffix on the Spotify side tolerated', $s !== null && $s > 0.95, var_export($s, true));
$s = $matcher->score('Here To Stay', ['Korn'], 271, track('Here To Stay', ['Some Cover Band'], 265));
check('same title, other artist → rejected', $s === null, var_export($s, true));
$s = $matcher->score('Blind', ['Korn'], 258, track('Twisted Transistor', ['Korn'], 251));
check('same artist, other title → rejected', $s === null, var_export($s, true));
$s = $matcher->score('Pennywise- California Dreaming', ['Pennywise'], 190, track('Pennywise', ['Pennywise'], 190));
check('short title inside a long one → rejected', $s === null, var_export($s, true));
$s = $matcher->score('Sum Luv', [], 200, track('Sum Luv', ['Shaka Ponk'], 201));
check('unknown artist: exact title + same length accepted', $s !== null && $s >= SpotifyMatcher::MIN_SCORE, var_export($s, true));
$s = $matcher->score('Sum Luv', [], 200, track('Sum Luv', ['Shaka Ponk'], 320));
check('unknown artist: different length rejected', $s === null, var_export($s, true));
$live = $matcher->score('Freak On A Leash', ['Korn'], 255, track('Freak On A Leash', ['Korn'], 410));
$studio = $matcher->score('Freak On A Leash', ['Korn'], 255, track('Freak On A Leash', ['Korn'], 255));
check('matching duration ranks above a long live take', $live !== null && $studio !== null && $studio > $live, var_export([$studio, $live], true));

echo $failures === 0 ? "\nAll good.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
