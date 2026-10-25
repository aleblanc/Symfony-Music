<?php

/*
 * Standalone test for IdCodec: stable album/artist keys derived from tags,
 * and decoding of Subsonic ids (song, al-, ar-, pl-, pl-offline).
 *
 * Run:  php tests/subsonic-idcodec-test.php   (exit 0 = pass)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Subsonic\IdCodec;
use App\Subsonic\SubsonicException;

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

// Keys
$k = IdCodec::albumKey('Daft Punk', 'Daft Punk', 'Discovery');
check('album key is 16 hex chars', preg_match('/^[0-9a-f]{16}$/', $k) === 1, $k);
check('case and extra spaces give the same album key', $k === IdCodec::albumKey('  daft   PUNK ', 'x', 'DISCOVERY '));
check('empty album artist falls back to track artist', IdCodec::albumKey(null, 'Daft Punk', 'Discovery') === $k);
check('blank album artist falls back too', IdCodec::albumKey('  ', 'Daft Punk', 'Discovery') === $k);
check(
    'compilation: one key for different track artists',
    IdCodec::albumKey('Various Artists', 'A', 'Hits') === IdCodec::albumKey('Various Artists', 'B', 'Hits'),
);
check('accents are kept (é ≠ e)', IdCodec::artistKey(null, 'Stromaé') !== IdCodec::artistKey(null, 'Stromae'));
check('same album name, different artists → different keys', IdCodec::albumKey(null, 'A', 'Live') !== IdCodec::albumKey(null, 'B', 'Live'));
check('artist key ignores album', IdCodec::artistKey('Daft Punk', 'x') === IdCodec::artistKey(null, 'daft punk'));
check('null everything still gives a key', preg_match('/^[0-9a-f]{16}$/', IdCodec::albumKey(null, null, null)) === 1);

// Encoding helpers
check('album id prefix', IdCodec::album('abc') === 'al-abc');
check('artist id prefix', IdCodec::artist('abc') === 'ar-abc');
check('playlist id prefix', IdCodec::playlist(12) === 'pl-12');

// Decoding
check('decode song', IdCodec::decode('42') === ['type' => 'song', 'value' => 42]);
check('decode album', IdCodec::decode('al-0123456789abcdef') === ['type' => 'album', 'value' => '0123456789abcdef']);
check('decode artist', IdCodec::decode('ar-0123456789abcdef') === ['type' => 'artist', 'value' => '0123456789abcdef']);
check('decode playlist', IdCodec::decode('pl-7') === ['type' => 'playlist', 'value' => 7]);
check('decode offline list', IdCodec::decode('pl-offline') === ['type' => 'offline', 'value' => 'offline']);

foreach (['xx-1', '', 'al-', 'pl-abc', '-3', '4.5'] as $bad) {
    try {
        IdCodec::decode($bad);
        check("decode '{$bad}' throws", false, 'no exception');
    } catch (SubsonicException $e) {
        check("decode '{$bad}' throws code 70", $e->getCode() === 70);
    }
}

echo $failures === 0 ? "\nAll tests passed.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
