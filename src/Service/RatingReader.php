<?php

namespace App\Service;

/**
 * Reads the ID3v2 "Popularimeter" (POPM) frame from an MP3 file — the frame
 * media players such as Windows Media Player use to store the star rating a
 * user assigned to a track. The rating is embedded in the file itself, so it
 * survives moving the file between machines (unlike the player's own database).
 *
 * WMP encodes the five-star scale into the POPM rating byte (0-255) as:
 *   1 star = 1, 2 stars = 64, 3 stars = 128, 4 stars = 196, 5 stars = 255.
 * We convert any byte back to a 0-5 star count using the standard thresholds.
 */
class RatingReader
{
    /**
     * @return array{stars: int, byte: int, email: string}|null
     *                                                            null when the file has no POPM frame (never rated)
     */
    public function read(string $filePath): ?array
    {
        if (!class_exists(\getID3::class) || !is_file($filePath)) {
            return null;
        }

        $getID3 = new \getID3();
        $getID3->setOption(['encoding' => 'UTF-8']);
        $info = $getID3->analyze($filePath);

        $frame = $info['id3v2']['POPM'][0] ?? null;
        if ($frame === null || !isset($frame['rating'])) {
            return null;
        }

        $byte = (int) $frame['rating'];

        return [
            'stars' => $this->byteToStars($byte),
            'byte' => $byte,
            'email' => (string) ($frame['email'] ?? ''),
        ];
    }

    /**
     * Maps the POPM rating byte (0-255) back to a 0-5 star count, following the
     * de-facto Windows Media Player thresholds.
     */
    private function byteToStars(int $byte): int
    {
        return match (true) {
            $byte >= 224 => 5,
            $byte >= 160 => 4,
            $byte >= 96 => 3,
            $byte >= 32 => 2,
            $byte >= 1 => 1,
            default => 0,
        };
    }
}
