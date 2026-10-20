<?php

namespace App\Service\Import;

use App\Service\Enrichment\HintExtractor;

/**
 * Where an identified file is filed in MUSIC_DIR:
 *
 *   Album Artist/Album (Year)/NN Title.ext          album track
 *   Album Artist/Album (Year)/D-NN Title.ext        multi-disc album
 *   Compilations/Album (Year)/NN Artist - Title.ext various-artists release
 *   Artist/Singles/Title.ext                        no album known
 *
 * Every part is made safe for any file system: no "/" or reserved
 * characters, no leading/trailing dots or spaces, no "..", NFC, ≤ 120 bytes.
 */
class LibraryPath
{
    private const MAX_PART_BYTES = 120;
    private const COMPILATION_ARTISTS = ['various artists', 'various', 'artistes divers', 'divers'];

    /**
     * @param array<string, string|int|null> $fields proposal fields (title, artist, album, albumArtist, year, trackNumber, trackTotal, discNumber, discTotal)
     */
    public function relativePath(array $fields, string $extension): string
    {
        $ext = strtolower(ltrim($extension, '.'));
        $artist = $this->part($fields['artist'] ?? null, 'Unknown Artist');
        $albumArtist = $this->part($fields['albumArtist'] ?? null, $artist);
        $title = $this->part($fields['title'] ?? null, 'Untitled');
        $albumName = trim((string) ($fields['album'] ?? ''));

        if ($albumName === '') {
            return $this->component($artist) . '/Singles/' . $this->fit($this->component($title), '.' . $ext);
        }

        $year = (int) ($fields['year'] ?? 0);
        $album = $this->part($albumName . ($year > 0 ? ' (' . $year . ')' : ''), 'Unknown Album');
        $compilation = \in_array(mb_strtolower($albumArtist), self::COMPILATION_ARTISTS, true);

        $number = (int) ($fields['trackNumber'] ?? 0);
        $prefix = '';
        if ($number > 0) {
            $digits = max(2, \strlen((string) (int) ($fields['trackTotal'] ?? 0)));
            $prefix = str_pad((string) $number, $digits, '0', \STR_PAD_LEFT) . ' ';
            if ((int) ($fields['discTotal'] ?? 1) > 1) {
                $prefix = (int) ($fields['discNumber'] ?? 1) . '-' . $prefix;
            }
        }

        $name = $prefix . ($compilation ? $artist . ' - ' : '') . $title;

        return ($compilation ? 'Compilations' : $this->component($albumArtist)) . '/' . $this->component($album) . '/'
            . $this->fit($this->component($this->part($name, 'Untitled')), '.' . $ext);
    }

    /** A whole file/folder name never starts with a dot (hidden file, "."/".."). */
    private function component(string $value): string
    {
        $value = ltrim($value, '. ');

        return $value !== '' ? $value : 'Untitled';
    }

    /** One safe path component. */
    private function part(string|int|null $value, string $fallback): string
    {
        $value = (string) HintExtractor::nfc((string) $value);
        $value = str_replace(['/', '\\'], '-', $value);
        $value = str_replace(['"', '|'], ["'", '-'], $value);
        $value = str_replace(':', ' -', $value);
        $value = preg_replace('/[<>?*\x00-\x1F\x7F]/u', '', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        $value = rtrim(trim($value, " \t"), '. ');
        if ($value === '' || trim($value, '.') === '') {
            return $fallback;
        }

        return $this->fit($value, '');
    }

    /** Shortens to MAX_PART_BYTES (UTF-8 safe), keeping the suffix. */
    private function fit(string $value, string $suffix): string
    {
        $max = self::MAX_PART_BYTES - \strlen($suffix);
        if (\strlen($value) > $max) {
            $value = rtrim(mb_strcut($value, 0, $max, 'UTF-8'), ' .');
        }

        return $value . $suffix;
    }
}
