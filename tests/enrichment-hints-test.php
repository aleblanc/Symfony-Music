<?php

/*
 * Standalone test for Enrichment\HintExtractor: turns arbitrary real-world
 * paths + current tags into weak hints (the library layout is unreliable:
 * download-year folders, Artist/, Artist/Album/, "Artist - Album (2003)/",
 * catch-all dumps...).
 *
 * Run:  php tests/enrichment-hints-test.php   (exit 0 = pass)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Service\Enrichment\HintExtractor;

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
$root = '/music';

// ---- Placeholder titles ------------------------------------------------------
foreach (['Track 1', 'track01', 'Piste 3', '05', 'Titre 12', 'Untitled', 'Unknown', 'Audio Track 7', 'Track_04', 'Pista 2', ''] as $t) {
    check("placeholder: '{$t}'", $x->isPlaceholderTitle($t));
}
foreach (['Yesterday', '1979', 'Seven Nation Army', '99 Problems', 'One'] as $t) {
    check("real title: '{$t}'", !$x->isPlaceholderTitle($t));
}

// ---- Filename parsing --------------------------------------------------------
$h = $x->extract("{$root}/2019/Track 1.mp3", $root, title: 'Track 1', artist: 'Unknown Artist', album: 'Unknown Album', duration: 201);
check('Track 1: placeholder title', $h->titleIsPlaceholder);
check('Track 1: track number from filename', $h->trackNumber === 1, var_export($h->trackNumber, true));
check('Track 1: no filename title', $h->filenameTitle === null, var_export($h->filenameTitle, true));
check('Track 1: unknown artist dropped', $h->tagArtist === null);
check('Track 1: unknown album dropped', $h->tagAlbum === null);
check('Track 1: pure year folder dropped from segments', $h->pathSegments === [], json_encode($h->pathSegments));
check('Track 1: bare year folder (download year) is not a year hint', $h->yearHint === null, var_export($h->yearHint, true));
check('Track 1: duration kept', $h->duration === 201);

$h = $x->extract("{$root}/Daft Punk - Discovery (2001)/03 - Digital Love.mp3", $root, title: '03 - Digital Love', artist: 'Unknown Artist', album: 'Unknown Album', duration: 301);
check('NN - Title: track number', $h->trackNumber === 3);
check('NN - Title: title', $h->filenameTitle === 'Digital Love', var_export($h->filenameTitle, true));
check('NN - Title: title equal to filename is not a real tag', $h->tagTitle === null, var_export($h->tagTitle, true));
check('Artist - Album (year) segment cleaned', $h->pathSegments === ['Daft Punk - Discovery'], json_encode($h->pathSegments));
check('year from segment', $h->yearHint === 2001);

$h = $x->extract("{$root}/Downloads/misc/Nirvana - Smells Like Teen Spirit [Official Video].mp3", $root, title: 'Nirvana - Smells Like Teen Spirit [Official Video]', artist: 'Unknown Artist', album: 'Unknown Album', duration: 279);
check('Artist - Title: artist', $h->filenameArtist === 'Nirvana', var_export($h->filenameArtist, true));
check('Artist - Title: title without noise', $h->filenameTitle === 'Smells Like Teen Spirit', var_export($h->filenameTitle, true));
check('Artist - Title: no track number', $h->trackNumber === null);
check('noise folders dropped', $h->pathSegments === [], json_encode($h->pathSegments));

$h = $x->extract("{$root}/50 Cent - In Da Club.mp3", $root, title: '50 Cent - In Da Club', artist: 'Unknown Artist', album: 'Unknown Album', duration: 193);
check('"50 Cent" is not a track number', $h->trackNumber === null, var_export($h->trackNumber, true));
check('"50 Cent" artist', $h->filenameArtist === '50 Cent', var_export($h->filenameArtist, true));

$h = $x->extract("{$root}/Radiohead/OK Computer/CD1/07_Fitter_Happier.flac", $root, title: 'Fitter Happier', artist: 'Radiohead', album: 'OK Computer', duration: 117);
check('underscore filename: track', $h->trackNumber === 7);
check('underscore filename: title', $h->filenameTitle === 'Fitter Happier', var_export($h->filenameTitle, true));
check('disc folder gives disc number', $h->discNumber === 1);
check('nearest-first segments without disc folder', $h->pathSegments === ['OK Computer', 'Radiohead'], json_encode($h->pathSegments));
check('real tags kept', $h->tagTitle === 'Fitter Happier' && $h->tagArtist === 'Radiohead' && $h->tagAlbum === 'OK Computer');

$h = $x->extract("{$root}/The Beatles/1.05. Yesterday.mp3", $root, title: 'Yesterday', artist: 'The Beatles', album: 'Unknown Album', duration: 125);
check('D.NN. Title: disc', $h->discNumber === 1, var_export($h->discNumber, true));
check('D.NN. Title: track', $h->trackNumber === 5, var_export($h->trackNumber, true));
check('D.NN. Title: title', $h->filenameTitle === 'Yesterday', var_export($h->filenameTitle, true));

// ---- Real cases from the library (Windows Media Player rips, web downloads) ---
$lib = '/Users/aleblanc/Documents/Music';

$h = $x->extract("{$lib}/Coldplay/Mylo Xyloto/07-coldplay-every_teardrop_is_a_waterfall.mp3", $lib, title: '07-coldplay-every_teardrop_is_a_waterfall', artist: 'Coldplay/Coldplay [2.Bia2Voice.biz]', album: 'Mylo Xyloto', duration: 240);
check('NN-artist-title: track', $h->trackNumber === 7, var_export($h->trackNumber, true));
check('NN-artist-title: artist split on bare hyphen when it matches a folder', $h->filenameArtist === 'coldplay', var_export($h->filenameArtist, true));
check('NN-artist-title: title', $h->filenameTitle === 'every teardrop is a waterfall', var_export($h->filenameTitle, true));
check('polluted artist tag cleaned ("X/X [site]")', $h->tagArtist === 'Coldplay', var_export($h->tagArtist, true));

$h = $x->extract("{$lib}/Biga Ranx/Album inconnu/2.Ordinary Day.mp3", $lib, title: '2.Ordinary Day', artist: 'Biga Ranx', album: 'Unknown Album', duration: 182);
check('N.Title: track', $h->trackNumber === 2);
check('N.Title: title', $h->filenameTitle === 'Ordinary Day', var_export($h->filenameTitle, true));
check('"Album inconnu" folder is noise', $h->pathSegments === ['Biga Ranx'], json_encode($h->pathSegments));

$h = $x->extract("{$lib}/Gorillaz/Gorillaz/Piste audio 08.mp3", $lib, title: 'Piste audio 08', artist: 'Gorillaz', album: 'Gorillaz', duration: 283);
check('"Piste audio 08" is a placeholder', $h->titleIsPlaceholder);
check('"Piste audio 08" gives track 8', $h->trackNumber === 8, var_export($h->trackNumber, true));

$h = $x->extract("{$lib}/Linkin Park/Album inconnu/10 Forgotten.mp3", $lib, title: '10 Forgotten', artist: 'Unknown Artist', album: 'Unknown Album', duration: 194);
check('"10 Forgotten": low unpadded number + space is a track number', $h->trackNumber === 10, var_export($h->trackNumber, true));
check('"10 Forgotten": title', $h->filenameTitle === 'Forgotten', var_export($h->filenameTitle, true));
check('artist guess from outermost folder', $h->artistGuess() === 'Linkin Park', var_export($h->artistGuess(), true));

$h = $x->extract("{$lib}/Blink 182/Non spécifié/Piste audio 15.mp3", $lib, title: 'Piste audio 15', artist: 'Blink 182', album: 'Non spécifié', duration: 85);
check('"Non spécifié" album tag is unknown', $h->tagAlbum === null, var_export($h->tagAlbum, true));
check('"Non spécifié" folder is noise', $h->pathSegments === ['Blink 182'], json_encode($h->pathSegments));

$h = $x->extract("{$lib}/Interprète inconnu/Album inconnu/parov stelar - all night.mp3", $lib, title: 'parov stelar - all night', artist: 'Interprète inconnu', album: 'Album inconnu', duration: 200);
check('"Interprète inconnu" is unknown + noise', $h->tagArtist === null && $h->pathSegments === [], json_encode([$h->tagArtist, $h->pathSegments]));
check('artist from file name when folders are noise', $h->artistGuess() === 'parov stelar');

$h = $x->extract("{$lib}/mp3lio.com/mp3hup.com/Queen - Bohemian Rhapsody.mp3", $lib, title: 'Queen - Bohemian Rhapsody', artist: '', album: '', duration: 355);
check('download-site folders are noise', $h->pathSegments === [], json_encode($h->pathSegments));

$h = $x->extract("{$lib}/Mano Negra/Best Of Mano Negra/Mano Negra - Best Of - 07 - Noche De Accion.mp3", $lib, title: 'Mano Negra - Best Of - 07 - Noche De Accion', artist: '', album: '', duration: 200);
check('Artist - Album - NN - Title: artist', $h->filenameArtist === 'Mano Negra', var_export($h->filenameArtist, true));
check('Artist - Album - NN - Title: title', $h->filenameTitle === 'Noche De Accion', var_export($h->filenameTitle, true));
check('Artist - Album - NN - Title: track', $h->trackNumber === 7, var_export($h->trackNumber, true));

$h = $x->extract("{$lib}/Muse/Black Holes And Revelations/Muse - Black Holes And Revelations (2006) - 06 - Invincible.mp3", $lib, title: 'x', artist: '', album: '', duration: 300);
check('Artist - Album (year) - NN - Title', $h->filenameArtist === 'Muse' && $h->filenameTitle === 'Invincible' && $h->trackNumber === 6, json_encode([$h->filenameArtist, $h->filenameTitle, $h->trackNumber]));

$h = $x->extract("{$lib}/Swingin' Utters/Five Lessons Learned/Tony Hawk Pro Skater 2 - Swingin' Utters - FiveLessonsLearne.mp3", $lib, title: 'x', artist: '', album: '', duration: 100);
check('artist found in the middle part (matches folder)', $h->filenameArtist === "Swingin' Utters" && $h->filenameTitle === 'FiveLessonsLearne', json_encode([$h->filenameArtist, $h->filenameTitle]));

$h = $x->extract("{$lib}/System of a Down/Steal This Album!/13-system_of_a_down-ego_brain-rns.mp3", $lib, title: '13-system_of_a_down-ego_brain-rns', artist: '', album: '', duration: 100);
check('scene name: release-group suffix dropped', $h->filenameTitle === 'ego brain' && $h->trackNumber === 13, json_encode([$h->filenameTitle, $h->trackNumber]));

$h = $x->extract("{$lib}/Rammstein/MUTTER/track 05b.mp3", $lib, title: 'track 05b', artist: 'Rammstein', album: 'MUTTER', duration: 100);
check('"track 05b" is a placeholder', $h->titleIsPlaceholder && $h->trackNumber === 5, json_encode([$h->titleIsPlaceholder, $h->trackNumber]));

$h = $x->extract("{$lib}/2019/Radiohead/OK Computer/Track 4.mp3", $lib, title: 'Track 4', artist: '', album: '', duration: 0);
check('artist guess skips the album folder', $h->artistGuess() === 'Radiohead', var_export($h->artistGuess(), true));

// ---- Search pairs ------------------------------------------------------------
$h = $x->extract("{$root}/2020/Radiohead/OK Computer/Track 4.mp3", $root, title: 'Track 4', artist: 'Unknown Artist', album: 'Unknown Album', duration: 0);
$pairs = $h->albumSearchPairs();
check('Artist/Album folders → (artist, album) pair', in_array(['Radiohead', 'OK Computer'], $pairs, true), json_encode($pairs));
check('album-only fallback pair', in_array([null, 'OK Computer'], $pairs, true), json_encode($pairs));

$h = $x->extract("{$root}/Daft Punk - Discovery/Track 4.mp3", $root, title: 'Track 4', artist: 'Unknown Artist', album: 'Unknown Album', duration: 0);
check('"Artist - Album" segment → pair', in_array(['Daft Punk', 'Discovery'], $h->albumSearchPairs(), true), json_encode($h->albumSearchPairs()));

$h = $x->extract("{$root}/x/Track 4.mp3", $root, title: 'Track 4', artist: 'Muse', album: 'Absolution', duration: 0);
check('real tags pair comes first', ($h->albumSearchPairs()[0] ?? null) === ['Muse', 'Absolution'], json_encode($h->albumSearchPairs()));

echo $failures === 0 ? "\nAll hint tests passed.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
