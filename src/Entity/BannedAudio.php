<?php

namespace App\Entity;

use App\Repository\BannedAudioRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A recording marked as "not this song" for the track it was filed as: its
 * raw Chromaprint fingerprint (first 120 s), so the same audio is recognised
 * and refused when it comes back for that artist and title, whatever its
 * codec or bitrate. See BanList.
 */
#[ORM\Entity(repositoryClass: BannedAudioRepository::class)]
#[ORM\Table(name: 'banned_audio')]
class BannedAudio
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The track it was wrongly filed as. */
    #[ORM\Column(length: 512)]
    private string $artist;

    #[ORM\Column(length: 512)]
    private string $title;

    /** Raw fingerprint: 32-bit words, little-endian, base64. */
    #[ORM\Column(type: 'text')]
    private string $fingerprint;

    #[ORM\Column]
    private int $duration;

    /** Library path it had when banned. */
    #[ORM\Column(length: 1024)]
    private string $file;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $bannedAt;

    /** @param list<int> $fingerprint */
    public function __construct(string $artist, string $title, array $fingerprint, int $duration, string $file, \DateTimeImmutable $bannedAt)
    {
        $this->artist = mb_substr($artist, 0, 512);
        $this->title = mb_substr($title, 0, 512);
        $this->fingerprint = base64_encode(pack('V*', ...$fingerprint));
        $this->duration = $duration;
        $this->file = mb_substr($file, 0, 1024);
        $this->bannedAt = $bannedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getArtist(): string
    {
        return $this->artist;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    /** @return list<int> */
    public function getFingerprint(): array
    {
        $bytes = base64_decode($this->fingerprint, true);

        return $bytes === false || $bytes === '' ? [] : array_values(unpack('V*', $bytes) ?: []);
    }

    public function getDuration(): int
    {
        return $this->duration;
    }

    public function getFile(): string
    {
        return $this->file;
    }

    public function getBannedAt(): \DateTimeImmutable
    {
        return $this->bannedAt;
    }
}
