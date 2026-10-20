<?php

/*
 * Standalone test for Enrichment\MetadataEnricher orchestration with fake
 * remote clients: folder-level album match from path hints, orphan file
 * identified by fingerprint, field policy (genre/cover only when missing),
 * remote failures never escaping.
 *
 * Run:  php tests/enrichment-enricher-test.php   (exit 0 = pass)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Service\Enrichment\AcoustIdClient;
use App\Service\Enrichment\CoverArtClient;
use App\Service\Enrichment\EnrichmentException;
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

function okc(string $id = 'rel-okc'): ReleaseCandidate
{
    $tracks = [];
    foreach ([['Airbag', 284], ['Paranoid Android', 383], ['Subterranean Homesick Alien', 267], ['Fitter Happier', 117]] as $i => [$t, $d]) {
        $tracks[] = new ReleaseTrack(1, $i + 1, $t, 'Radiohead', $d, 'rec-' . ($i + 1));
    }

    return new ReleaseCandidate($id, 'OK Computer', 'Radiohead', 1997, 'Alternative Rock', 'rg-okc', $tracks);
}

final class FakeMusicBrainz extends MusicBrainzClient
{
    public bool $fail = false;
    /** @var list<string> */
    public array $calls = [];

    public function __construct()
    {
        parent::__construct(new MockHttpClient(), new ArrayAdapter(), new MockClock(), 'UA');
    }

    public function searchReleases(?string $artist, string $album, int $limit = 5): array
    {
        $this->calls[] = "searchReleases({$artist}, {$album})";
        if ($this->fail) {
            throw new EnrichmentException('MusicBrainz returned HTTP 500');
        }

        return mb_strtolower($album) === 'ok computer'
            ? [new ReleaseSummary('rel-okc', 'OK Computer', 'Radiohead', '1997', 'Official', 'Album', [], 4)]
            : [];
    }

    public function searchRecordings(?string $artist, string $title, int $limit = 5, bool $excludeLive = false): array
    {
        $this->calls[] = "searchRecordings({$artist}, {$title})";

        // Real case: the first release MusicBrainz lists for "Faint" is a live bootleg.
        if (mb_strtolower($title) === 'somewhere i belong') {
            // Live bonus recording on an official anniversary edition, listed first.
            return [
                new RecordingMatch('rec-sib-live', 'Somewhere I Belong', 'Linkin Park', 215, 1.0, [
                    new ReleaseSummary('rel-meteora-20', 'Meteora (20th anniversary edition)', 'Linkin Park', '2023-04-07', 'Official', 'Album', [], 36),
                ], 'live, 2004-08-03: Projekt Revolution, Denver'),
                new RecordingMatch('rec-sib', 'Somewhere I Belong', 'Linkin Park', 214, 1.0, [
                    new ReleaseSummary('rel-meteora-20', 'Meteora (20th anniversary edition)', 'Linkin Park', '2023-04-07', 'Official', 'Album', [], 36),
                    new ReleaseSummary('rel-meteora', 'Meteora', 'Linkin Park', '2003-03-25', 'Official', 'Album', [], 12),
                ]),
            ];
        }
        if (mb_strtolower($title) === 'crawling') {
            // An early promo with the same status/type lists before the album.
            return [new RecordingMatch('rec-crawling', 'Crawling', 'Linkin Park', 209, 1.0, [
                new ReleaseSummary('rel-6tracks', '6 Tracks', 'Linkin Park', '2000-01-01', 'Official', 'Album', [], 6),
                new ReleaseSummary('rel-meteora', 'Meteora', 'Linkin Park', '2003-03-25', 'Official', 'Album', [], 12),
            ])];
        }
        if ($artist === 'Linkin Park' && mb_strtolower($title) === 'numb encore') {
            return [new RecordingMatch('rec-numb-encore', 'Numb/Encore', 'Jay-Z & Linkin Park', 205, 1.0, [
                new ReleaseSummary('rel-collision', 'Collision Course', 'Jay-Z & Linkin Park', '2004-11-29', 'Official', 'EP', [], 6),
            ])];
        }

        // The live performance is its own recording, only released on bootlegs.
        return mb_strtolower($title) === 'faint' ? [
            new RecordingMatch('rec-faint-live', 'Faint', 'Linkin Park', 162, 1.0, [
                new ReleaseSummary('rel-live', '2002-02-02: Colorado Springs, CO, USA', 'Linkin Park', '2002-02-02', 'Bootleg', 'Album', ['Live'], 20),
            ]),
            new RecordingMatch('rec-faint', 'Faint', 'Linkin Park', 162, 1.0, [
                new ReleaseSummary('rel-meteora', 'Meteora', 'Linkin Park', '2003-03-25', 'Official', 'Album', [], 12),
            ]),
        ] : [];
    }

    public function getRecording(string $id): ?RecordingMatch
    {
        $this->calls[] = "getRecording({$id})";

        return $id === 'rec-4' ? new RecordingMatch('rec-4', 'Fitter Happier', 'Radiohead', 117, 1.0, [
            new ReleaseSummary('rel-best-of', 'The Best Of', 'Radiohead', '2008', 'Official', 'Album', ['Compilation'], 30),
            new ReleaseSummary('rel-okc', 'OK Computer', 'Radiohead', '1997-05-21', 'Official', 'Album', [], 12),
            new ReleaseSummary('rel-okc-jp', 'OK Computer', 'Radiohead', '1997-06-01', 'Official', 'Album', [], 13),
        ]) : null;
    }

    public function getRelease(string $id): ?ReleaseCandidate
    {
        $this->calls[] = "getRelease({$id})";

        $single = static fn(string $title, int $pos, string $recording, int $d, string $artist = 'Linkin Park'): ReleaseCandidate
            => new ReleaseCandidate($id, $title, $artist, 2003, null, "rg-{$id}", [new ReleaseTrack(1, $pos, ucfirst(str_replace('rec-', '', $recording)), $artist, $d, $recording)], false, 'Official');
        if ($id === 'rel-meteora-20') {
            return new ReleaseCandidate($id, 'Meteora (20th anniversary edition)', 'Linkin Park', 2023, null, 'rg-m20', [
                new ReleaseTrack(1, 3, 'Somewhere I Belong', 'Linkin Park', 214, 'rec-sib'),
                new ReleaseTrack(2, 25, 'Somewhere I Belong (live in Denver)', 'Linkin Park', 215, 'rec-sib-live'),
            ], false, 'Official');
        }
        if ($id === 'rel-meteora') {
            return new ReleaseCandidate($id, 'Meteora', 'Linkin Park', 2003, null, 'rg-meteora', [
                new ReleaseTrack(1, 3, 'Somewhere I Belong', 'Linkin Park', 214, 'rec-sib'),
                new ReleaseTrack(1, 7, 'Faint', 'Linkin Park', 162, 'rec-faint'),
                new ReleaseTrack(1, 10, 'Crawling', 'Linkin Park', 209, 'rec-crawling'),
            ], false, 'Official');
        }
        if ($id === 'rel-6tracks') {
            return $single('6 Tracks', 3, 'rec-crawling', 209);
        }
        if ($id === 'rel-collision') {
            return $single('Collision Course', 4, 'rec-numb-encore', 205, 'Jay-Z & Linkin Park');
        }
        if ($id === 'rel-live') {
            $live = $id === 'rel-live';

            return new ReleaseCandidate($id, $live ? 'Colorado Springs' : 'Meteora', 'Linkin Park', $live ? 2002 : 2003, null, "rg-{$id}",
                [new ReleaseTrack(1, $live ? 13 : 7, 'Faint', 'Linkin Park', 162, $live ? 'rec-faint-live' : 'rec-faint')], false, $live ? 'Bootleg' : 'Official', $live ? ['Live'] : []);
        }

        return str_starts_with($id, 'rel-okc') ? okc($id) : null;
    }
}

final class FakeAcoustId extends AcoustIdClient
{
    public function __construct()
    {
        parent::__construct(new MockHttpClient(), new MockClock(), 'KEY');
    }

    public function lookup(Fingerprint $fingerprint): array
    {
        return $fingerprint->fingerprint === 'fp-fitter'
            ? [new RecordingMatch('rec-4', 'Fitter Happier', 'Radiohead', 117, 0.96, [new ReleaseSummary('rel-okc', 'OK Computer', '', null, null, null, [], 12)])]
            : [];
    }
}

final class FakeFingerprinter extends Fingerprinter
{
    public function __construct(private readonly bool $on)
    {
        parent::__construct('fpcalc');
    }

    public function isAvailable(): bool
    {
        return $this->on;
    }

    public function fingerprint(string $file): Fingerprint
    {
        return new Fingerprint(str_contains($file, 'dump') ? 'fp-fitter' : 'fp-unknown', 117);
    }
}

final class FakeCovers extends CoverArtClient
{
    public int $calls = 0;

    public function __construct()
    {
        parent::__construct(new MockHttpClient(), 'UA');
    }

    public function front(string $releaseId, ?string $releaseGroupId = null): ?string
    {
        ++$this->calls;

        return "\xFF\xD8\xFF-cover-{$releaseId}";
    }
}

$mb = new FakeMusicBrainz();
$covers = new FakeCovers();
$root = '/music';
$log = [];
$logger = static function (string $line) use (&$log): void { $log[] = $line; };

// ---- A. Folder of "Track N" files, path names the album, no fingerprinting ----
$enricher = new MetadataEnricher(new HintExtractor(), new MatchScorer(), $mb, new FakeAcoustId(), new FakeFingerprinter(false), $covers, $root);
$folder = [
    new EnrichmentTrack(1, "{$root}/2019/Radiohead/OK Computer/Track 1.mp3", 'Track 1', 'Unknown Artist', 'Unknown Album', 'Unknown Genre', 285, null, false, true),
    new EnrichmentTrack(2, "{$root}/2019/Radiohead/OK Computer/Track 2.mp3", 'Track 2', 'Unknown Artist', 'Unknown Album', 'Unknown Genre', 382, null, false, true),
    new EnrichmentTrack(3, "{$root}/2019/Radiohead/OK Computer/Track 3.mp3", 'Track 3', 'Unknown Artist', 'Unknown Album', 'Rock', 268, null, true, true),
    // Already well tagged sibling: helps the match, gets no proposal.
    new EnrichmentTrack(4, "{$root}/2019/Radiohead/OK Computer/04 Fitter Happier.mp3", 'Fitter Happier', 'Radiohead', 'OK Computer', 'Rock', 117, 4, true, false),
];
$proposals = $enricher->enrichFolder($folder, $logger);
$byId = [];
foreach ($proposals as $p) {
    $byId[$p->musicId] = $p;
}
check('A: one proposal per target track', array_keys($byId) == [1, 2, 3], json_encode(array_keys($byId)));
check('A: album match source', ($byId[1]->source ?? '') === 'album-match');
check('A: confident', ($byId[1]->score ?? 0) >= 0.85, (string) ($byId[1]->score ?? 0));
check('A: Track 2 → Paranoid Android', ($byId[2]->fields['title'] ?? null) === 'Paranoid Android');
check('A: track number + total', ($byId[2]->fields['trackNumber'] ?? null) === 2 && ($byId[2]->fields['trackTotal'] ?? null) === 4);
check('A: album / artist / album artist / year', ($byId[1]->fields['album'] ?? null) === 'OK Computer' && $byId[1]->fields['artist'] === 'Radiohead' && $byId[1]->fields['albumArtist'] === 'Radiohead' && $byId[1]->fields['year'] === 1997);
check('A: genre proposed when unknown', ($byId[1]->fields['genre'] ?? null) === 'Alternative Rock');
check('A: existing genre kept', !array_key_exists('genre', $byId[3]->fields ?? []), json_encode($byId[3]->fields ?? []));
check('A: cover only for tracks without one', ($byId[1]->cover ?? null) !== null && isset($byId[3]) && $byId[3]->cover === null);
check('A: cover downloaded once per release', $covers->calls === 1, (string) $covers->calls);
check('A: MusicBrainz ids recorded', ($byId[1]->releaseId ?? '') === 'rel-okc' && ($byId[1]->recordingId ?? '') === 'rec-1');

// ---- B. Orphan "Track 1" in a dump folder, identified by fingerprint ---------
$enricher = new MetadataEnricher(new HintExtractor(), new MatchScorer(), $mb, new FakeAcoustId(), new FakeFingerprinter(true), new FakeCovers(), $root);
$mb->calls = [];
$proposals = $enricher->enrichFolder([
    new EnrichmentTrack(9, "{$root}/dump/Track 1.mp3", 'Track 1', 'Unknown Artist', 'Unknown Album', '', 117, null, false, true),
], $logger);
$p = $proposals[0] ?? null;
check('B: orphan identified', $p !== null && $p->fields['title'] === 'Fitter Happier', json_encode($p?->fields));
check('B: source acoustid', $p?->source === 'acoustid');
check('B: studio album preferred over compilation, oldest edition', $p?->releaseId === 'rel-okc', (string) $p?->releaseId);
check('B: track number from the release, not from "Track 1"', ($p?->fields['trackNumber'] ?? null) === 4);
check('B: confident', ($p?->score ?? 0) >= 0.85, (string) $p?->score);

// ---- D. Live bootlegs are never chosen (unless the folder says "live") -------
$enricher = new MetadataEnricher(new HintExtractor(), new MatchScorer(), $mb, new FakeAcoustId(), new FakeFingerprinter(false), new FakeCovers(), $root);
$p = $enricher->enrichFolder([new EnrichmentTrack(20, "{$root}/Linkin Park/Album inconnu/07 Faint.mp3", '07 Faint', 'Unknown Artist', 'Unknown Album', '', 162, null, true, true)], $logger)[0] ?? null;
check('D: studio album instead of the live bootleg', $p?->releaseId === 'rel-meteora' && ($p->fields['album'] ?? null) === 'Meteora', (string) $p?->releaseId);
$p = $enricher->enrichFolder([new EnrichmentTrack(21, "{$root}/Linkin Park/Live in Colorado/07 Faint.mp3", '07 Faint', 'Unknown Artist', 'Unknown Album', '', 162, null, true, true)], $logger)[0] ?? null;
check('D: live release allowed when the folder says live', $p !== null && $p->releaseId !== null, (string) $p?->releaseId);

// ---- E. Live bonus recording, folder coherence, mashup file names ----------
$enricher = new MetadataEnricher(new HintExtractor(), new MatchScorer(), $mb, new FakeAcoustId(), new FakeFingerprinter(false), new FakeCovers(), $root);
$res = [];
foreach ($enricher->enrichFolder([
    new EnrichmentTrack(30, "{$root}/Linkin Park/Album inconnu/03 Somewhere I Belong.mp3", '03 Somewhere I Belong', 'Unknown Artist', 'Unknown Album', '', 214, null, true, true),
    new EnrichmentTrack(31, "{$root}/Linkin Park/Album inconnu/05 Crawling.mp3", '05 Crawling', 'Unknown Artist', 'Unknown Album', '', 209, null, true, true),
    new EnrichmentTrack(32, "{$root}/Linkin Park/Album inconnu/04 Numb - Encore.mp3", '04 Numb - Encore', 'Unknown Artist', 'Unknown Album', '', 205, null, true, true),
], $logger) as $p) {
    $res[$p->musicId] = $p;
}
check('E: studio recording preferred over a live bonus with a longer title', ($res[30]->recordingId ?? null) === 'rec-sib', (string) ($res[30]->recordingId ?? '-'));
check('E: original album preferred over the anniversary edition', ($res[30]->fields['album'] ?? null) === 'Meteora', (string) ($res[30]->fields['album'] ?? '-'));
check('E: folder coherence: Crawling joins the album its siblings matched', ($res[31]->fields['album'] ?? null) === 'Meteora', (string) ($res[31]->fields['album'] ?? '-'));
check('E: "Numb - Encore" retried as a title by the folder artist', ($res[32]->recordingId ?? null) === 'rec-numb-encore' && ($res[32]->fields['album'] ?? null) === 'Collision Course', json_encode($res[32]->fields ?? null));

$extended = new RecordingMatch('r', 'All Night (extended club version)', 'Parov Stelar', 300, 1.0, []);
check('F: extended club version is a variant of "all night"', $extended->isVariantOf('all night'));
check('F: not a variant when the file asks for it', !$extended->isVariantOf('All Night (Extended Club Version)'));
check('F: plain title is not a variant', !(new RecordingMatch('r', 'All Night', 'Parov Stelar', 300, 1.0, []))->isVariantOf('all night'));

// ---- C. Remote failure is logged, never thrown --------------------------------
$mb->fail = true;
$log = [];
$enricher = new MetadataEnricher(new HintExtractor(), new MatchScorer(), $mb, new FakeAcoustId(), new FakeFingerprinter(false), new FakeCovers(), $root);
try {
    $proposals = $enricher->enrichFolder($folder, $logger);
    check('C: failure → no proposals', $proposals === []);
    check('C: failure logged', (bool) array_filter($log, static fn(string $l): bool => str_contains($l, 'HTTP 500')), json_encode($log));
} catch (Throwable $e) {
    check('C: failure must not throw', false, $e->getMessage());
}

echo $failures === 0 ? "\nAll enricher tests passed.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
