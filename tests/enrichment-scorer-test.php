<?php

/*
 * Standalone test for Enrichment\MatchScorer: scores a MusicBrainz release
 * against a folder of local files and aligns files to release tracks
 * (recording id, then track number, then title, then nearest duration).
 *
 * Run:  php tests/enrichment-scorer-test.php   (exit 0 = pass)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Service\Enrichment\FolderTrack;
use App\Service\Enrichment\HintExtractor;
use App\Service\Enrichment\MatchScorer;
use App\Service\Enrichment\ReleaseCandidate;
use App\Service\Enrichment\ReleaseTrack;

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

$x = new HintExtractor();
$scorer = new MatchScorer();

/** @param list<array{0:string,1:int}> $tracks title, seconds */
function release(string $id, string $title, string $artist, array $tracks, bool $compilation = false): ReleaseCandidate
{
    $list = [];
    foreach ($tracks as $i => [$t, $d]) {
        $list[] = new ReleaseTrack(disc: 1, position: $i + 1, title: $t, artist: $artist, duration: $d, recordingId: "rec-{$id}-" . ($i + 1));
    }

    return new ReleaseCandidate(id: $id, title: $title, artist: $artist, year: 1997, genre: 'alternative rock', releaseGroupId: "rg-{$id}", tracks: $list, isCompilation: $compilation);
}

$okc = release('okc', 'OK Computer', 'Radiohead', [
    ['Airbag', 284], ['Paranoid Android', 383], ['Subterranean Homesick Alien', 267], ['Exit Music (For a Film)', 264],
    ['Let Down', 299], ['Karma Police', 264], ['Fitter Happier', 117], ['Electioneering', 230],
]);
// Same album, deluxe edition: extra tracks shift the count, durations of the first 8 identical.
$deluxe = release('deluxe', 'OK Computer (Collector\'s Edition)', 'Radiohead', [
    ['Airbag', 284], ['Paranoid Android', 383], ['Subterranean Homesick Alien', 267], ['Exit Music (For a Film)', 264],
    ['Let Down', 299], ['Karma Police', 264], ['Fitter Happier', 117], ['Electioneering', 230],
    ['Lull', 147], ['Meeting in the Aisle', 188], ['Polyethylene', 263], ['Pearly*', 214],
]);
$other = release('other', 'Pablo Honey', 'Radiohead', [
    ['You', 208], ['Creep', 238], ['How Do You?', 132], ['Stop Whispering', 325],
    ['Thinking About You', 161], ['Anyone Can Play Guitar', 217], ['Ripcord', 189], ['Vegetable', 192],
]);

// A download-year folder of "Track N" files whose durations match OK Computer (±2 s), out of order on disk.
$durations = [1 => 285, 2 => 382, 3 => 268, 4 => 263, 5 => 300, 6 => 265, 7 => 116, 8 => 231];
$files = [];
foreach ([3, 1, 2, 8, 5, 4, 7, 6] as $n) {
    $files[] = new FolderTrack(100 + $n, $x->extract("/m/2019/Radiohead/OK Computer/Track {$n}.mp3", '/m', "Track {$n}", 'Unknown Artist', 'Unknown Album', $durations[$n]));
}

$m = $scorer->score($files, $okc, null);
check('exact album scores high without fingerprint', $m->score >= 0.9, (string) $m->score);
check('every file aligned', \count($m->assignments) === 8, (string) \count($m->assignments));
check('Track 7 → Fitter Happier', ($m->assignments[107] ?? null)?->title === 'Fitter Happier');
check('Track 1 → Airbag', ($m->assignments[101] ?? null)?->title === 'Airbag');
// Karma Police (264) and Exit Music (264) share a duration: track number must break the tie.
check('equal durations resolved by track number', ($m->assignments[106] ?? null)?->title === 'Karma Police' && ($m->assignments[104] ?? null)?->title === 'Exit Music (For a Film)');

$d = $scorer->score($files, $deluxe, null);
check('standard edition beats deluxe edition', $m->score > $d->score, "{$m->score} vs {$d->score}");
$o = $scorer->score($files, $other, null);
check('wrong album scores low', $o->score < 0.6, (string) $o->score);

// No useful names at all (catch-all folder, placeholder titles, no tags) but fingerprint consensus.
$bare = [];
foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $n) {
    $bare[] = new FolderTrack(200 + $n, $x->extract("/m/2019/Track {$n}.mp3", '/m', "Track {$n}", '', '', $durations[$n]));
}
$f = $scorer->score($bare, $okc, 1.0);
check('fingerprint consensus + durations scores high without names', $f->score >= 0.85, (string) $f->score);
$nf = $scorer->score($bare, $okc, null);
check('same files without fingerprint stay below auto threshold', $nf->score < 0.85, (string) $nf->score);

// Recording ids from AcoustID win over track numbers lying on disk.
$liar = [new FolderTrack(301, $x->extract('/m/x/Track 1.mp3', '/m', 'Track 1', '', '', 117), ['rec-okc-7'])];
$l = $scorer->score($liar, $okc, 1.0);
check('recording id beats misleading track number', ($l->assignments[301] ?? null)?->title === 'Fitter Happier');

// More files than tracks: catch-all folder must not match a short release.
$many = [];
for ($n = 1; $n <= 20; ++$n) {
    $many[] = new FolderTrack(400 + $n, $x->extract("/m/dump/Track {$n}.mp3", '/m', "Track {$n}", '', '', 180 + $n * 7));
}
$c = $scorer->score($many, $okc, null);
check('catch-all folder vs 8-track album scores low', $c->score < 0.5, (string) $c->score);

check('similarity: accents/case/punctuation', $scorer->similarity('Beyoncé', 'beyonce') === 1.0);
check('similarity: containment', $scorer->similarity('OK Computer', 'Radiohead - OK Computer') >= 0.85);
check('similarity: unrelated', $scorer->similarity('OK Computer', 'Pablo Honey') < 0.5);

echo $failures === 0 ? "\nAll scorer tests passed.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
