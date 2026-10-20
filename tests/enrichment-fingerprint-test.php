<?php

/*
 * Standalone test for the fingerprint rules of Enrichment\MetadataEnricher
 * (taken from beets): every file of a folder is fingerprinted, an album is
 * only auto-applied when most fingerprints agree with it, a file whose
 * fingerprint names another song leaves the album and is identified alone,
 * and two different albums tied at the top are never auto-applied.
 *
 * Run:  php tests/enrichment-fingerprint-test.php   (exit 0 = pass)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Service\Enrichment\AcoustIdClient;
use App\Service\Enrichment\CoverArtClient;
use App\Service\Enrichment\EnrichmentTrack;
use App\Service\Enrichment\Fingerprint;
use App\Service\Enrichment\Fingerprinter;
use App\Service\Enrichment\HintExtractor;
use App\Service\Enrichment\MatchScorer;
use App\Service\Enrichment\MetadataEnricher;
use App\Service\Enrichment\MusicBrainzClient;
use App\Service\Enrichment\RecordingMatch;
use App\Service\Enrichment\ReleaseCandidate;
use App\Service\Enrichment\ReleaseSummary;
use App\Service\Enrichment\ReleaseTrack;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;

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

/** @param list<array{0:string,1:int,2:string}> $tracks title, seconds, recording id */
function rel(string $id, string $title, string $artist, string $group, array $tracks): ReleaseCandidate
{
    $list = [];
    foreach ($tracks as $i => [$t, $d, $rec]) {
        $list[] = new ReleaseTrack(1, $i + 1, $t, $artist, $d, $rec);
    }

    return new ReleaseCandidate($id, $title, $artist, 1997, null, $group, $list, false, 'Official');
}

final class FpFingerprinter extends Fingerprinter
{
    public int $calls = 0;

    public function __construct()
    {
        parent::__construct('fpcalc');
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function fingerprint(string $file): Fingerprint
    {
        ++$this->calls;

        return new Fingerprint('fp:' . basename($file), 200);
    }
}

final class FpAcoustId extends AcoustIdClient
{
    /** @param array<string, list<RecordingMatch>> $byFingerprint */
    public function __construct(public array $byFingerprint = [])
    {
        parent::__construct(new MockHttpClient(), new MockClock(), 'KEY');
    }

    public function lookup(Fingerprint $fingerprint): array
    {
        return $this->byFingerprint[$fingerprint->fingerprint] ?? [];
    }
}

final class FpMusicBrainz extends MusicBrainzClient
{
    /**
     * @param list<ReleaseSummary> $search
     * @param array<string, ReleaseCandidate> $releases
     * @param array<string, RecordingMatch> $recordings
     */
    public function __construct(public array $search = [], public array $releases = [], public array $recordings = [])
    {
        parent::__construct(new MockHttpClient(), new ArrayAdapter(), new MockClock(), 'UA');
    }

    public function searchReleases(?string $artist, string $album, int $limit = 5): array
    {
        return $this->search;
    }

    public function searchRecordings(?string $artist, string $title, int $limit = 5, bool $excludeLive = false): array
    {
        return [];
    }

    public function getRecording(string $id): ?RecordingMatch
    {
        return $this->recordings[$id] ?? null;
    }

    public function getRelease(string $id): ?ReleaseCandidate
    {
        return $this->releases[$id] ?? null;
    }
}

final class NoCovers extends CoverArtClient
{
    public function __construct()
    {
        parent::__construct(new MockHttpClient(), 'UA');
    }

    public function front(string $releaseId, ?string $releaseGroupId = null): ?string
    {
        return null;
    }
}

$durations = [284, 383, 267, 264];
$okc = rel('rel-okc', 'OK Computer', 'Radiohead', 'rg-okc', [
    ['Airbag', 284, 'rec-1'], ['Paranoid Android', 383, 'rec-2'], ['Subterranean Homesick Alien', 267, 'rec-3'], ['Exit Music (For a Film)', 264, 'rec-4'],
]);
$folder = static function (string $dir) use ($durations): array {
    $tracks = [];
    foreach ($durations as $i => $d) {
        $n = $i + 1;
        $tracks[] = new EnrichmentTrack($n, "/music/{$dir}/Track {$n}.mp3", "Track {$n}", 'Unknown Artist', 'Unknown Album', '', $d, null, true, true);
    }

    return $tracks;
};
$acoustFor = static function (string $prefix, float $score = 0.95): array {
    $map = [];
    foreach ([1, 2, 3, 4] as $n) {
        $map["fp:Track {$n}.mp3"] = [new RecordingMatch("{$prefix}-{$n}", "Song {$n}", 'Someone', null, $score, [])];
    }

    return $map;
};
$byId = static function (array $proposals): array {
    $out = [];
    foreach ($proposals as $p) {
        $out[$p->musicId] = $p;
    }

    return $out;
};
$log = [];
$logger = static function (string $l) use (&$log): void { $log[] = $l; };
$okcSummary = new ReleaseSummary('rel-okc', 'OK Computer', 'Radiohead', '1997', 'Official', 'Album', [], 4);

// ---- 1. Every file is fingerprinted, agreeing fingerprints confirm the album ------
$fp = new FpFingerprinter();
$mb = new FpMusicBrainz([$okcSummary], ['rel-okc' => $okc]);
$en = new MetadataEnricher(new HintExtractor(), new MatchScorer(), $mb, new FpAcoustId($acoustFor('rec')), $fp, new NoCovers(), '/music');
$p = $byId($en->enrichFolder($folder('Radiohead/OK Computer'), $logger));
check('1: all 4 files fingerprinted (not a sample of 3)', $fp->calls === 4, (string) $fp->calls);
check('1: album confirmed and confident', count($p) === 4 && ($p[1]->releaseId ?? '') === 'rel-okc' && $p[1]->score >= 0.85, json_encode(array_map(static fn($x) => [$x->releaseId, $x->score], $p)));
check('1: each file aligned by its own recording', ($p[3]->recordingId ?? '') === 'rec-3');

// ---- 2. Fingerprints point elsewhere: the album named by the folder is not auto-applied
$fp = new FpFingerprinter();
$en = new MetadataEnricher(new HintExtractor(), new MatchScorer(), new FpMusicBrainz([$okcSummary], ['rel-okc' => $okc]), new FpAcoustId($acoustFor('other')), $fp, new NoCovers(), '/music');
$log = [];
$p = $byId($en->enrichFolder($folder('Radiohead/OK Computer'), $logger));
$autoOkc = array_filter($p, static fn($x) => $x->releaseId === 'rel-okc' && $x->score >= 0.85);
check('2: album contradicted by most fingerprints is never auto-applied', $autoOkc === [], json_encode(array_map(static fn($x) => [$x->releaseId, $x->score, $x->source], $p)));
check('2: files identified by their own fingerprint instead', ($p[2]->source ?? '') === 'acoustid' && ($p[2]->recordingId ?? '') === 'other-2', json_encode($p[2] ?? null));

// ---- 3. Fingerprints name another release: it wins over the folder name ------------
$other = rel('rel-other', 'Other Album', 'Someone', 'rg-other', [
    ['Song 1', 284, 'other-1'], ['Song 2', 383, 'other-2'], ['Song 3', 267, 'other-3'], ['Song 4', 264, 'other-4'],
]);
$acoust = [];
foreach ([1, 2, 3, 4] as $n) {
    $acoust["fp:Track {$n}.mp3"] = [new RecordingMatch("other-{$n}", "Song {$n}", 'Someone', null, 0.95, [new ReleaseSummary('rel-other', 'Other Album', '', null, null, null, [], 4)])];
}
$en = new MetadataEnricher(new HintExtractor(), new MatchScorer(), new FpMusicBrainz([$okcSummary], ['rel-okc' => $okc, 'rel-other' => $other]), new FpAcoustId($acoust), new FpFingerprinter(), new NoCovers(), '/music');
$p = $byId($en->enrichFolder($folder('Radiohead/OK Computer'), $logger));
check('3: the release all fingerprints agree on wins', count($p) === 4 && ($p[1]->releaseId ?? '') === 'rel-other' && ($p[4]->fields['title'] ?? '') === 'Song 4', json_encode(array_map(static fn($x) => [$x->releaseId, $x->fields['title'] ?? null], $p)));

// ---- 4. One file's fingerprint contradicts its slot: it leaves the album -----------
$acoust = $acoustFor('rec');
$acoust['fp:Track 2.mp3'] = [new RecordingMatch('stranger', 'A Stranger', 'Somebody Else', 383, 0.97, [])];
$en = new MetadataEnricher(new HintExtractor(), new MatchScorer(), new FpMusicBrainz([$okcSummary], ['rel-okc' => $okc]), new FpAcoustId($acoust), new FpFingerprinter(), new NoCovers(), '/music');
$p = $byId($en->enrichFolder($folder('Radiohead/OK Computer'), $logger));
check('4: the contradicted file is identified by its own fingerprint', ($p[2]->recordingId ?? '') === 'stranger', json_encode($p[2] ?? null));
check('4: the others stay on the album', ($p[1]->releaseId ?? '') === 'rel-okc' && ($p[3]->releaseId ?? '') === 'rel-okc');

// ---- 5. Two different albums tie at the top: no auto-apply; editions don't count ----
$twin = rel('rel-twin', 'OK Computer', 'Radiohead Tribute Band', 'rg-twin', [
    ['Airbag', 284, 't-1'], ['Paranoid Android', 383, 't-2'], ['Subterranean Homesick Alien', 267, 't-3'], ['Exit Music (For a Film)', 264, 't-4'],
]);
$twinSummary = new ReleaseSummary('rel-twin', 'OK Computer', 'Radiohead Tribute Band', '1997', 'Official', 'Album', [], 4);
$en = new MetadataEnricher(new HintExtractor(), new MatchScorer(), new FpMusicBrainz([$okcSummary, $twinSummary], ['rel-okc' => $okc, 'rel-twin' => $twin]), new FpAcoustId(), new FpFingerprinter(), new NoCovers(), '/music');
$log = [];
$p = $byId($en->enrichFolder($folder('OK Computer'), $logger));
check('5: two different albums tied → kept for review', $p !== [] && max(array_map(static fn($x) => $x->score, $p)) < 0.85, json_encode(array_map(static fn($x) => [$x->releaseId, $x->score], $p)));
$edition = rel('rel-okc-jp', 'OK Computer', 'Radiohead', 'rg-okc', [
    ['Airbag', 284, 'rec-1'], ['Paranoid Android', 383, 'rec-2'], ['Subterranean Homesick Alien', 267, 'rec-3'], ['Exit Music (For a Film)', 264, 'rec-4'],
]);
$editionSummary = new ReleaseSummary('rel-okc-jp', 'OK Computer', 'Radiohead', '1997', 'Official', 'Album', [], 4);
$en = new MetadataEnricher(new HintExtractor(), new MatchScorer(), new FpMusicBrainz([$okcSummary, $editionSummary], ['rel-okc' => $okc, 'rel-okc-jp' => $edition]), new FpAcoustId(), new FpFingerprinter(), new NoCovers(), '/music');
$p = $byId($en->enrichFolder($folder('Radiohead/OK Computer'), $logger));
check('5: two editions of the same album tied → still confident', $p !== [] && min(array_map(static fn($x) => $x->score, $p)) >= 0.85, json_encode(array_map(static fn($x) => [$x->releaseId, $x->score], $p)));

// ---- 6. Real errors seen on the library ------------------------------------------
$single = static fn(int $id, string $path, int $d): EnrichmentTrack => new EnrichmentTrack($id, "/music/{$path}", pathinfo($path, PATHINFO_FILENAME), 'Unknown Artist', 'Unknown Album', '', $d, null, true, true);

// "Arctic Monkeys - Do I Wanna Know" fingerprinted as Foals' "Snake Oil": names contradict → never auto-applied.
$en = new MetadataEnricher(new HintExtractor(), new MatchScorer(), new FpMusicBrainz(), new FpAcoustId([
    'fp:Arctic Monkeys - Do I Wanna Know.mp3' => [new RecordingMatch('snake-oil', 'Snake Oil', 'Foals', 272, 0.9, [])],
]), new FpFingerprinter(), new NoCovers(), '/music');
$p = $en->enrichFolder([$single(60, 'Arctic Monkeys/Arctic Monkeys/Arctic Monkeys - Do I Wanna Know.mp3', 272)], $logger)[0] ?? null;
check('6a: fingerprint contradicting title AND artist is never auto-applied', $p === null || $p->score < 0.85, json_encode($p));

// AcoustID lists a remix first for "Complicated": the plain recording is preferred.
$en = new MetadataEnricher(new HintExtractor(), new MatchScorer(), new FpMusicBrainz(), new FpAcoustId([
    'fp:Avril Lavigne - Complicated.mp3' => [
        new RecordingMatch('matrix', 'Complicated (The Matrix mix)', 'Avril Lavigne', 245, 0.95, []),
        new RecordingMatch('orig', 'Complicated', 'Avril Lavigne', 244, 0.94, []),
    ],
]), new FpFingerprinter(), new NoCovers(), '/music');
$p = $en->enrichFolder([$single(61, 'Avril Lavigne/Let Go/Avril Lavigne - Complicated.mp3', 244)], $logger)[0] ?? null;
check('6b: plain recording preferred over a remix listed first', $p?->recordingId === 'orig', (string) $p?->recordingId);

// The demo take shares the fingerprint (disambiguation only known from MusicBrainz).
$en = new MetadataEnricher(new HintExtractor(), new MatchScorer(), new FpMusicBrainz([], [], [
    'aliens-demo' => new RecordingMatch('aliens-demo', 'Aliens Exist', 'blink-182', 191, 1.0, [], 'demo'),
    'aliens' => new RecordingMatch('aliens', 'Aliens Exist', 'blink-182', 191, 1.0, []),
]), new FpAcoustId([
    'fp:Aliens exist.mp3' => [
        new RecordingMatch('aliens-demo', 'Aliens Exist', 'blink-182', 191, 0.96, []),
        new RecordingMatch('aliens', 'Aliens Exist', 'blink-182', 191, 0.95, []),
    ],
]), new FpFingerprinter(), new NoCovers(), '/music');
$p = $en->enrichFolder([$single(62, 'Blink 182/Enma of the state/Aliens exist.mp3', 191)], $logger)[0] ?? null;
check('6c: demo take (disambiguation) not chosen', $p?->recordingId === 'aliens', (string) $p?->recordingId);

// Fingerprint whose length is far off ("Carousel" 0.00): no fingerprint proposal.
$en = new MetadataEnricher(new HintExtractor(), new MatchScorer(), new FpMusicBrainz(), new FpAcoustId([
    'fp:01 - Blink 182 - Carousel.mp3' => [new RecordingMatch('carousel', 'Carousel', 'blink-182', 170, 0.9, [])],
]), new FpFingerprinter(), new NoCovers(), '/music');
$p = $en->enrichFolder([$single(63, 'Blink 182/Buddha/01 - Blink 182 - Carousel.mp3', 300)], $logger)[0] ?? null;
check('6d: a fingerprint far off in length gives no proposal', $p === null || $p->score >= 0.5, json_encode($p));

// "Blink 182 - … - 13 - I Miss You" fingerprinted as Avril Lavigne's "I'm With You": artist named by the file disagrees.
$en = new MetadataEnricher(new HintExtractor(), new MatchScorer(), new FpMusicBrainz(), new FpAcoustId([
    'fp:Blink 182 - Greatest Hits Deluxe Edition - 13 - I Miss You.mp3' => [new RecordingMatch('im-with-you', "I'm With You", 'Avril Lavigne', 223, 0.98, [])],
]), new FpFingerprinter(), new NoCovers(), '/music');
$p = $en->enrichFolder([$single(64, 'Blink 182/Greatest Hits Deluxe Edition/Blink 182 - Greatest Hits Deluxe Edition - 13 - I Miss You.mp3', 223)], $logger)[0] ?? null;
check('6e: artist named by the file contradicted → not auto-applied', $p === null || $p->score < 0.85, json_encode($p));

// "shut up.mp3" fingerprinted as another song of the same band: title alone disagrees → review.
$en = new MetadataEnricher(new HintExtractor(), new MatchScorer(), new FpMusicBrainz(), new FpAcoustId([
    'fp:shut up.mp3' => [new RecordingMatch('gmogr', 'Give Me One Good Reason', 'blink-182', 191, 0.98, [])],
]), new FpFingerprinter(), new NoCovers(), '/music');
$p = $en->enrichFolder([$single(65, 'Blink 182/Non spécifié/shut up.mp3', 191)], $logger)[0] ?? null;
check('6f: title contradicted (same artist) → not auto-applied', $p === null || $p->score < 0.85, json_encode($p));

// Live album: the live take is the only fingerprint match → kept, confident.
$en = new MetadataEnricher(new HintExtractor(), new MatchScorer(), new FpMusicBrainz([], [], [
    'age-live' => new RecordingMatch('age-live', "What's My Age Again?", 'blink-182', 160, 1.0, [], 'live, 1999'),
]), new FpAcoustId([
    "fp:06 - Blink 182 - What's My Age Again.mp3" => [new RecordingMatch('age-live', "What's My Age Again?", 'blink-182', 160, 0.98, [])],
]), new FpFingerprinter(), new NoCovers(), '/music');
$p = $en->enrichFolder([$single(66, "Blink 182/The Mark, Tom & Travis Show/06 - Blink 182 - What's My Age Again.mp3", 160)], $logger)[0] ?? null;
check('6g: a live take that is the only fingerprint match stays confident', $p?->recordingId === 'age-live' && $p->score >= 0.85, json_encode($p));

// ---- 7. Second run on the library -------------------------------------------------
// "Avril Lavigne - Complicated" fingerprinted as a cover by another artist: same title, wrong artist.
$en = new MetadataEnricher(new HintExtractor(), new MatchScorer(), new FpMusicBrainz(), new FpAcoustId([
    'fp:Avril Lavigne - Complicated.mp3' => [new RecordingMatch('cover', 'Complicated (Avril Lavigne)', 'Jim Dusty ft. Kate Lawrence', 244, 1.0, [])],
]), new FpFingerprinter(), new NoCovers(), '/music');
$p = $en->enrichFolder([$single(70, 'Avril Lavigne/Under My Skin Let Go Disc 1/Avril Lavigne - Complicated.mp3', 244)], $logger)[0] ?? null;
check('7a: cover by another artist (same title) is not auto-applied', $p === null || $p->score < 0.85, json_encode($p));

// "What You Want - Angèle.mp3": title first in the file name, duo credit — must stay confident.
$en = new MetadataEnricher(new HintExtractor(), new MatchScorer(), new FpMusicBrainz(), new FpAcoustId([
    'fp:What You Want - Angèle.mp3' => [new RecordingMatch('wyw', 'What You Want', 'Angèle & Justice', 200, 0.97, [])],
]), new FpFingerprinter(), new NoCovers(), '/music');
$p = $en->enrichFolder([$single(71, 'Angèle, Justice/What You Want/What You Want - Angèle.mp3', 200)], $logger)[0] ?? null;
check('7b: "Title - Artist" file name and duo credit still confident', $p !== null && $p->score >= 0.85, json_encode($p));

// The artist's own album beats an older various-artists demo CD carrying the same recording.
$demo = new ReleaseSummary('rel-focal', 'Focal-JMlab Demonstration CD 4', 'Various Artists', '1997-01-01', 'Official', 'Album', [], 14);
$own = new ReleaseSummary('rel-fantaisie', 'Fantaisie militaire', 'Alain Bashung', '1998-02-01', 'Official', 'Album', [], 12);
$track = static fn(string $rid, string $t, int $pos): ReleaseCandidate => new ReleaseCandidate($rid, $t, $rid === 'rel-focal' ? 'Various Artists' : 'Alain Bashung', 1998, null, "rg-{$rid}", [new ReleaseTrack(1, $pos, 'La nuit je mens', 'Alain Bashung', 251, 'nuit')], false, 'Official');
$en = new MetadataEnricher(new HintExtractor(), new MatchScorer(), new FpMusicBrainz([], ['rel-focal' => $track('rel-focal', 'Focal-JMlab Demonstration CD 4', 7), 'rel-fantaisie' => $track('rel-fantaisie', 'Fantaisie militaire', 4)], [
    'nuit' => new RecordingMatch('nuit', 'La nuit je mens', 'Alain Bashung', 251, 1.0, [$demo, $own]),
]), new FpAcoustId(['fp:- Alain Bashung - La Nuit, Je Mens.mp3' => [new RecordingMatch('nuit', 'La nuit je mens', 'Alain Bashung', 251, 1.0, [])]]), new FpFingerprinter(), new NoCovers(), '/music');
$p = $en->enrichFolder([$single(72, 'Alain Bashung/25 Ans De Radio Nova (Boxset)/- Alain Bashung - La Nuit, Je Mens.mp3', 251)], $logger)[0] ?? null;
check("7c: the artist's own album beats a various-artists demo CD", $p?->releaseId === 'rel-fantaisie', (string) $p?->releaseId);

// Two recordings share the fingerprint: the one released on the artist's album wins over a promo-only one.
$promo = new ReleaseSummary('rel-promo', 'Promo Only: Modern Rock Radio', 'Various Artists', '2013-09-01', 'Promotion', 'Album', ['Compilation'], 20);
$am = new ReleaseSummary('rel-am', 'AM', 'Arctic Monkeys', '2013-09-09', 'Official', 'Album', [], 12);
$en = new MetadataEnricher(new HintExtractor(), new MatchScorer(), new FpMusicBrainz([], [
    'rel-am' => new ReleaseCandidate('rel-am', 'AM', 'Arctic Monkeys', 2013, null, 'rg-am', [new ReleaseTrack(1, 1, 'Do I Wanna Know?', 'Arctic Monkeys', 272, 'dwk-album')], false, 'Official'),
    'rel-promo' => new ReleaseCandidate('rel-promo', 'Promo Only: Modern Rock Radio', 'Various Artists', 2013, null, 'rg-promo', [new ReleaseTrack(1, 19, 'Do I Wanna Know', 'Arctic Monkeys', 272, 'dwk-promo')], true, 'Promotion', ['Compilation']),
], [
    'dwk-promo' => new RecordingMatch('dwk-promo', 'Do I Wanna Know', 'Arctic Monkeys', 272, 1.0, [$promo]),
    'dwk-album' => new RecordingMatch('dwk-album', 'Do I Wanna Know?', 'Arctic Monkeys', 272, 1.0, [$am]),
]), new FpAcoustId(['fp:Arctic Monkeys - Do I Wanna Know.mp3' => [
    new RecordingMatch('dwk-promo', 'Do I Wanna Know', 'Arctic Monkeys', 272, 0.97, []),
    new RecordingMatch('dwk-album', 'Do I Wanna Know?', 'Arctic Monkeys', 272, 0.96, []),
]]), new FpFingerprinter(), new NoCovers(), '/music');
$p = $en->enrichFolder([$single(73, 'Arctic Monkeys/Arctic Monkeys/Arctic Monkeys - Do I Wanna Know.mp3', 272)], $logger)[0] ?? null;
check('7d: recording on the artist album beats a promo-only one at equal fingerprint score', $p?->releaseId === 'rel-am', (string) $p?->releaseId);

echo $failures === 0 ? "\nAll fingerprint tests passed.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
