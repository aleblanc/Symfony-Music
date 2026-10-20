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

// A file with a real title must not be aligned by number/duration onto a different song
// ("10 Forgotten" landed on "Faint" of a live album with a similar length).
$titled = [new FolderTrack(501, $x->extract('/m/Linkin Park/Album inconnu/10 Forgotten.mp3', '/m', '10 Forgotten', '', '', 230))];
$t = $scorer->score($titled, $okc, null);
check('title contradiction blocks number/duration alignment', !isset($t->assignments[501]), json_encode(array_map(static fn($r) => $r->title, $t->assignments)));
$ok = [new FolderTrack(502, $x->extract('/m/x/08 Electioneering.mp3', '/m', '08 Electioneering', '', '', 231))];
check('matching title still aligns', ($scorer->score($ok, $okc, null)->assignments[502] ?? null)?->title === 'Electioneering');

// Multi-disc release, rip numbered continuously (disc 1 has 4 tracks, "Piste audio 05" = disc 2 track 1).
$twoDiscs = new ReleaseCandidate('2cd', 'Gorillaz', 'Gorillaz', 2001, null, null, [
    new ReleaseTrack(1, 1, 'Re-Hash', 'Gorillaz', 217, 'r1'), new ReleaseTrack(1, 2, '5/4', 'Gorillaz', 159, 'r2'),
    new ReleaseTrack(1, 3, 'Tomorrow Comes Today', 'Gorillaz', 192, 'r3'), new ReleaseTrack(1, 4, 'New Genious', 'Gorillaz', 237, 'r4'),
    new ReleaseTrack(2, 1, 'Clint Eastwood', 'Gorillaz', 340, 'r5'), new ReleaseTrack(2, 2, 'Man Research', 'Gorillaz', 270, 'r6'),
]);
$rip = [];
foreach ([1 => 218, 5 => 341, 6 => 271] as $n => $d) {
    $rip[] = new FolderTrack(600 + $n, $x->extract(sprintf('/m/Gorillaz/Gorillaz/Piste audio %02d.mp3', $n), '/m', sprintf('Piste audio %02d', $n), 'Gorillaz', 'Gorillaz', $d));
}
$g = $scorer->score($rip, $twoDiscs, null);
check('continuous numbering across discs: #5 → disc 2 track 1', ($g->assignments[605] ?? null)?->title === 'Clint Eastwood' && ($g->assignments[606] ?? null)?->title === 'Man Research', json_encode(array_map(static fn($r) => $r->title, $g->assignments)));

// A known track number is not overridden by a duration coincidence.
$numbered = [new FolderTrack(701, $x->extract('/m/x/Piste audio 06.mp3', '/m', 'Piste audio 06', '', '', 230))];
$n = $scorer->score($numbered, $okc, null); // OK Computer #6 Karma Police = 264 s, #8 Electioneering = 230 s
check('known number blocks duration alignment onto another number', !isset($n->assignments[701]), json_encode(array_map(static fn($r) => $r->title, $n->assignments)));

// Optimal assignment (beets-style), not greedy: closest pair first would strand a file.
$near = new ReleaseCandidate('near', 'Near', 'X', null, null, null, [
    new ReleaseTrack(1, 1, 'Song X', 'X', 203, 'rx'), new ReleaseTrack(1, 2, 'Song Y', 'X', 208, 'ry'),
]);
$pair = [
    new FolderTrack(801, $x->extract('/m/x/Track.mp3', '/m', 'Track', '', '', 200)),
    new FolderTrack(802, $x->extract('/m/x/Untitled.mp3', '/m', 'Untitled', '', '', 205)),
];
$h = $scorer->score($pair, $near, null);
check('optimal assignment: 200→203 and 205→208 (greedy strands one)', ($h->assignments[801] ?? null)?->title === 'Song X' && ($h->assignments[802] ?? null)?->title === 'Song Y', json_encode(array_map(static fn($r) => $r->title, $h->assignments)));

check('similarity: accents/case/punctuation', $scorer->similarity('Beyoncé', 'beyonce') === 1.0);
check('similarity: containment', $scorer->similarity('OK Computer', 'Radiohead - OK Computer') >= 0.85);
check('similarity: unrelated', $scorer->similarity('OK Computer', 'Pablo Honey') < 0.5);

echo $failures === 0 ? "\nAll scorer tests passed.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
