<?php

namespace App\Service;

/**
 * Produces a human-readable dump of every tag getID3 can read from
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

    /**
     * Every readable getID3 section as key/value rows, plus a final
     * "rating-related" section gathering POPM/rating-looking fields.
     *
     * @return array<string, list<array{0: string, 1: string}>>|null null when the file can't be read
     */
    public function sections(string $filePath): ?array
    {
        if (!class_exists(\getID3::class) || !is_file($filePath)) {
            return null;
        }

        $getID3 = new \getID3();
        $getID3->setOption(['encoding' => 'UTF-8']);
        $info = $getID3->analyze($filePath);
        \getid3_lib::CopyTagsToComments($info);

        $sections = ['file' => [['size', ($info['filesize'] ?? '?') . ' bytes']]];

        foreach (self::SECTIONS as $section) {
            if (!isset($info[$section])) {
                continue;
            }
            $lines = [];
            $this->walk($info[$section], '', $lines);
            $sections[$section] = array_map(
                static function (string $line): array {
                    $parts = explode(' = ', $line, 2);

                    return [$parts[0], $parts[1] ?? ''];
                },
                $lines,
            );
        }

        $rating = [];
        array_walk_recursive($info, static function ($v, $k) use (&$rating): void {
            // Anchored so "sample_rate" / "tag_offset_start" don't match.
            if (\is_string($k) && preg_match('/rating|popm|popular|^stars?$|score/i', $k) && !\is_array($v)) {
                $rating[] = [$k, \is_scalar($v) ? (string) $v : \gettype($v)];
            }
        });
        $sections['rating-related'] = $rating;

        return $sections;
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
