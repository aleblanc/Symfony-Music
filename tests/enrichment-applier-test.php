<?php

/*
 * Standalone test for Enrichment\SuggestionApplier on a generated MP3:
 * apply writes the file + entity and keeps the previous values; revert puts
 * everything back (cover included); a failed tag write leaves the entity
 * untouched.
 *
 * Run:  php tests/enrichment-applier-test.php   (exit 0 = pass)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Entity\MetadataSuggestion;
use App\Entity\Music;
use App\Enum\SuggestionStatus;
use App\Service\CoverImageProcessor;
use App\Service\Enrichment\SuggestionApplier;
use App\Service\MetadataWriter;

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

/** @return array<string, mixed> */
function readTags(string $file): array
{
    clearstatcache(true, $file);
    $getID3 = new getID3();
    $getID3->setOption(['encoding' => 'UTF-8']);
    $info = $getID3->analyze($file);
    getid3_lib::CopyTagsToComments($info);

    return $info;
}

$file = sys_get_temp_dir() . '/applier-test-' . bin2hex(random_bytes(4)) . '.mp3';
file_put_contents($file, str_repeat("\xFF\xFB\x90\x64" . str_repeat("\x00", 413), 200));
$jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');

$writer = new MetadataWriter(__DIR__ . '/../lib/getid3');
$writer->writeTags($file, ['title' => 'Track 7', 'artist' => 'Unknown Artist', 'genre' => 'Rock']);

$music = (new Music())->setFile($file)->setTitle('Track 7')->setArtist('Unknown Artist')->setAlbum('Unknown Album')->setGenre('Rock');
$applier = new SuggestionApplier($writer, new CoverImageProcessor());

$s = new MetadataSuggestion($music, 'album-match', 0.93, [
    'title' => 'Fitter Happier', 'artist' => 'Radiohead', 'album' => 'OK Computer', 'albumArtist' => 'Radiohead',
    'trackNumber' => 7, 'trackTotal' => 12, 'discNumber' => 1, 'discTotal' => 1, 'year' => 1997,
]);
$s->setCover($jpeg);

try {
    check('apply succeeds', $applier->apply($s));
    check('status applied', $s->getStatus() === SuggestionStatus::Applied);
    check('entity updated', $music->getTitle() === 'Fitter Happier' && $music->getAlbum() === 'OK Computer'
        && $music->getAlbumArtist() === 'Radiohead' && $music->getTrackNumber() === 7 && $music->getDiscNumber() === 1 && $music->getYear() === 1997);
    check('entity genre untouched (not proposed)', $music->getGenre() === 'Rock');
    check('entity cover set', $music->hasImage());
    check('enrichedAt set', $music->getEnrichedAt() !== null);
    check('lastModified refreshed', $music->getLastModified() === filemtime($file));
    $info = readTags($file);
    check('file title', ($info['comments']['title'][0] ?? null) === 'Fitter Happier');
    check('file track', ($info['tags']['id3v2']['track_number'][0] ?? null) === '7' && ($info['tags']['id3v2']['totaltracks'][0] ?? null) === '12');
    check('file cover', ($info['comments']['picture'][0]['data'] ?? null) !== null);
    check('previous values kept', ($s->getPrevious()['title'] ?? null) === 'Track 7' && array_key_exists('trackNumber', $s->getPrevious() ?? []));

    check('revert succeeds', $applier->revert($s));
    check('status reverted', $s->getStatus() === SuggestionStatus::Reverted);
    check('entity restored', $music->getTitle() === 'Track 7' && $music->getAlbum() === 'Unknown Album' && $music->getTrackNumber() === null && $music->getAlbumArtist() === null);
    check('entity cover removed', !$music->hasImage());
    $info = readTags($file);
    check('file title restored', ($info['comments']['title'][0] ?? null) === 'Track 7');
    check('file track removed', !isset($info['tags']['id3v2']['track_number']));
    check('file cover removed', !isset($info['comments']['picture']));
    check('file genre kept throughout', ($info['comments']['genre'][0] ?? null) === 'Rock');

    // Unwritable file: entity must stay untouched.
    $ghost = (new Music())->setFile($file . '.missing.mp3')->setTitle('Track 1');
    $g = new MetadataSuggestion($ghost, 'search', 0.9, ['title' => 'Something']);
    check('apply on missing file fails', !$applier->apply($g));
    check('entity untouched on failure', $ghost->getTitle() === 'Track 1');
    check('suggestion stays pending with error', $g->getStatus() === SuggestionStatus::Pending && $g->getError() !== null);
} finally {
    @unlink($file);
}

echo $failures === 0 ? "\nAll applier tests passed.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
