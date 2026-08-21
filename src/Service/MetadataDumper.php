<?php

namespace App\Service;

/**
 * Produces a flat, human-readable text dump of every tag getID3 can read from
 * a file (ID3v1, ID3v2 frames, APE, comments, "tags" arrays, …). Binary blobs
 * such as embedded cover art are omitted so the output stays copy-pasteable.
 *
 * Intended as a diagnostic view — e.g. to check whether a player-specific
 * rating (Windows Media Player's POPM frame or a WM/SharedUserRating field) is
 * actually embedded in the file rather than only in the player's own database.
 */
class MetadataDumper
{
    /** Top-level getID3 sections worth showing; the rest are internals. */
    private const SECTIONS = ['fileformat', 'audio', 'tags', 'comments', 'id3v1', 'id3v2', 'ape', 'asf', 'quicktime'];

    public function dump(string $filePath): string
    {
        if (!class_exists(\getID3::class) || !is_file($filePath)) {
            return 'File not readable or getID3 unavailable.';
        }

        $getID3 = new \getID3();
        $getID3->setOption(['encoding' => 'UTF-8']);
        $info = $getID3->analyze($filePath);
        \getid3_lib::CopyTagsToComments($info);

        $out = [];
        $out[] = '===== FILE =====';
        $out[] = 'size = ' . ($info['filesize'] ?? '?') . ' bytes';
        $out[] = '';

        foreach (self::SECTIONS as $section) {
            if (!isset($info[$section])) {
                continue;
            }
            $out[] = '===== ' . strtoupper($section) . ' =====';
            $this->walk($info[$section], '', $out);
            $out[] = '';
        }

        $out[] = '===== RATING-RELATED FIELDS (heuristic scan) =====';
        $matched = false;
        array_walk_recursive($info, static function ($v, $k) use (&$out, &$matched): void {
            if (\is_string($k) && preg_match('/rat|popm|popular|star|score/i', $k) && !\is_array($v)) {
                $out[] = '  ' . $k . ' = ' . (\is_scalar($v) ? (string) $v : \gettype($v));
                $matched = true;
            }
        });
        if (!$matched) {
            $out[] = '  (nothing matched rat/popm/popular/star/score)';
        }

        return implode("\n", $out);
    }

    /**
     * @param array<string, mixed>|scalar|null $value
     * @param list<string>                      $out
     */
    private function walk(mixed $value, string $prefix, array &$out): void
    {
        if (\is_array($value)) {
            foreach ($value as $k => $v) {
                $key = $prefix === '' ? (string) $k : $prefix . '.' . $k;
                if (\in_array((string) $k, ['data', 'picture', 'APIC'], true)) {
                    $out[] = $key . ' = [binary blob omitted]';
                    continue;
                }
                $this->walk($v, $key, $out);
            }

            return;
        }

        if (\is_bool($value)) {
            $out[] = $prefix . ' = ' . ($value ? 'true' : 'false');

            return;
        }

        $str = (string) $value;
        if ($this->isBinaryish($str)) {
            $out[] = $prefix . ' = [binary, ' . \strlen($str) . ' bytes]';

            return;
        }
        if (\strlen($str) > 300) {
            $str = substr($str, 0, 300) . '…(truncated)';
        }
        $out[] = $prefix . ' = ' . $str;
    }

    private function isBinaryish(string $s): bool
    {
        if ($s === '') {
            return false;
        }
        if (str_contains($s, "\x00")) {
            return true;
        }
        $printable = preg_match_all('/[\x20-\x7E\xA0-\xFF]/', $s);

        return ($printable / \strlen($s)) < 0.7;
    }
}
