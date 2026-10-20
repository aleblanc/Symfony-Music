<?php

/*
 * Unit test for ArtistMerger: which spellings are the same artist, and
 * which name is kept.
 *
 * Run:  php tests/artist-merge-test.php   (exit 0 = pass)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Service\Artist\ArtistMerger;

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if ($ok) {
        echo "  ok  {$label}\n";
    } else {
        ++$failures;
        echo "FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

$merger = new ArtistMerger();
$groups = $merger->groups([
    'Blink 182' => 2, 'Blink182' => 1, "blink\u{2010}182" => 29, 'Blink' => 1,
    'Offspring' => 9, 'The Offspring' => 9,
    'Sum41' => 1, 'Sum 41' => 1,
    'Muse' => 12,
    'Beyoncé' => 3, 'Beyonce' => 1,
]);
$byKey = array_column($groups, null, 'key');

check('groups: only artists with several spellings, biggest first', array_keys($byKey) === ['blink182', 'offspring', 'beyonce', 'sum41'], json_encode(array_keys($byKey)));
check('Blink 182 / Blink182 / blink‐182 together, "Blink" apart', array_keys($byKey['blink182']['variants']) === ['Blink 182', 'Blink182', "blink\u{2010}182"], json_encode(array_keys($byKey['blink182']['variants']), JSON_UNESCAPED_UNICODE));
check('most used spelling kept', $byKey['blink182']['canonical'] === "blink\u{2010}182");
check('tie: the one with "The"', $byKey['offspring']['canonical'] === 'The Offspring');
check('tie: the one with a space', $byKey['sum41']['canonical'] === 'Sum 41');
check('accents: the most used', $byKey['beyonce']['canonical'] === 'Beyoncé');
check('stable whatever the order', $merger->canonical(['Sum 41' => 1, 'Sum41' => 1]) === $merger->canonical(['Sum41' => 1, 'Sum 41' => 1]));

echo $failures === 0 ? "\nAll artist merge tests passed.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
