<?php

namespace App\Entity;

use App\Enum\AudioQuality;
use App\Repository\MusicRepository;
use App\Service\AudioFormat;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single indexed track. Ported from the legacy `music` table. Cover art is
 * kept inline as a BLOB (as in the original) so the library stays a single
 * self-contained SQLite file.
 */
#[ORM\Entity(repositoryClass: MusicRepository::class)]
#[ORM\Table(name: 'music')]
#[ORM\Index(name: 'music_artist_idx', columns: ['artist'])]
#[ORM\Index(name: 'music_album_idx', columns: ['album'])]
class Music
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Absolute (or library-relative) path of the audio file on disk. Unique so
     * a rescan updates existing rows instead of duplicating them.
     */
    #[ORM\Column(length: 1024, unique: true)]
    private ?string $file = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'songs')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'SET NULL', nullable: true)]
    private ?User $owner = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $artist = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $album = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $genre = null;

    #[ORM\Column(nullable: true)]
    private ?int $year = null;

    /** Duration in seconds. */
    #[ORM\Column(nullable: true)]
    private ?int $duration = null;

    /** Detected tempo (bpm); null until analysed client-side. Rhythm Phase 2. */
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $bpm = null;

    /**
     * JSON beat grid {bpm, bass:[sec...], drums:[sec...]} from the client-side
     * Web Audio analysis; null until analysed. This is the ONLY cached expensive
     * artifact for the rhythm game — note charts are regenerated on the fly from
     * it and never persisted (keeps the DB small). Rhythm Phase 2.
     */
    #[ORM\Column(name: 'beat_grid_json', type: 'text', nullable: true)]
    private ?string $beatGridJson = null;

    /**
     * Cover art bytes, extracted from tags at scan time. The legacy schema has
     * no mime column (art is normalised to WebP), so the type is sniffed from
     * the bytes when served. Doctrine hydrates a `blob` column as a stream
     * resource on read, while writes assign a raw byte string.
     *
     * @var resource|string|null
     */
    #[ORM\Column(type: 'blob', nullable: true)]
    private $image = null;

    #[ORM\Column(nullable: true)]
    private ?int $lastModified = null;

    /** Bits per second (getID3 `audio.bitrate`), not kbps. */
    #[ORM\Column(nullable: true)]
    private ?int $bitrate = null;

    /**
     * getID3 `audio.dataformat` (mp3, mp4, flac, vorbis, opus, wav...). Null
     * means the technical audio fields were never read: the scan refills them.
     */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $audioFormat = null;

    /** Codec/encoder name as reported by getID3 (e.g. "LAME", "ISO/IEC 14496-3 AAC"). */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $audioCodec = null;

    /** cbr, vbr or abr. */
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $bitrateMode = null;

    /** Hz. */
    #[ORM\Column(nullable: true)]
    private ?int $sampleRate = null;

    #[ORM\Column(nullable: true)]
    private ?int $bitsPerSample = null;

    #[ORM\Column(nullable: true)]
    private ?int $channels = null;

    #[ORM\Column(nullable: true)]
    private ?bool $lossless = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lyrics = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isPrivate = false;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFile(): ?string
    {
        return $this->file;
    }

    public function setFile(string $file): static
    {
        $this->file = $file;

        return $this;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getArtist(): ?string
    {
        return $this->artist;
    }

    public function setArtist(?string $artist): static
    {
        $this->artist = $artist;

        return $this;
    }

    public function getAlbum(): ?string
    {
        return $this->album;
    }

    public function setAlbum(?string $album): static
    {
        $this->album = $album;

        return $this;
    }

    public function getGenre(): ?string
    {
        return $this->genre;
    }

    public function setGenre(?string $genre): static
    {
        $this->genre = $genre;

        return $this;
    }

    public function getYear(): ?int
    {
        return $this->year;
    }

    public function setYear(?int $year): static
    {
        $this->year = $year;

        return $this;
    }

    public function getDuration(): ?int
    {
        return $this->duration;
    }

    public function setDuration(?int $duration): static
    {
        $this->duration = $duration;

        return $this;
    }

    public function getBpm(): ?float
    {
        return $this->bpm;
    }

    public function setBpm(?float $bpm): static
    {
        $this->bpm = $bpm;

        return $this;
    }

    public function getBeatGridJson(): ?string
    {
        return $this->beatGridJson;
    }

    public function setBeatGridJson(?string $beatGridJson): static
    {
        $this->beatGridJson = $beatGridJson;

        return $this;
    }

    /**
     * @return resource|string|null
     */
    public function getImage()
    {
        return $this->image;
    }

    /**
     * @param resource|string|null $image
     */
    public function setImage($image): static
    {
        $this->image = $image;

        return $this;
    }

    public function hasImage(): bool
    {
        return $this->image !== null;
    }

    public function getLastModified(): ?int
    {
        return $this->lastModified;
    }

    public function setLastModified(?int $lastModified): static
    {
        $this->lastModified = $lastModified;

        return $this;
    }

    public function getBitrate(): ?int
    {
        return $this->bitrate;
    }

    public function setBitrate(?int $bitrate): static
    {
        $this->bitrate = $bitrate;

        return $this;
    }

    public function getAudioFormat(): ?string
    {
        return $this->audioFormat;
    }

    public function getAudioCodec(): ?string
    {
        return $this->audioCodec;
    }

    public function getBitrateMode(): ?string
    {
        return $this->bitrateMode;
    }

    public function getSampleRate(): ?int
    {
        return $this->sampleRate;
    }

    public function getBitsPerSample(): ?int
    {
        return $this->bitsPerSample;
    }

    public function getChannels(): ?int
    {
        return $this->channels;
    }

    public function isLossless(): ?bool
    {
        return $this->lossless;
    }

    /** Copies every technical audio property (bitrate included) in one go. */
    public function applyAudioFormat(AudioFormat $format): static
    {
        $this->bitrate = $format->bitrate;
        $this->audioFormat = $format->dataformat;
        $this->audioCodec = $format->codec;
        $this->bitrateMode = $format->bitrateMode;
        $this->sampleRate = $format->sampleRate;
        $this->bitsPerSample = $format->bitsPerSample;
        $this->channels = $format->channels;
        $this->lossless = $format->lossless;

        return $this;
    }

    /** Short codec name for display (MP3, AAC, ALAC, FLAC, Vorbis...). */
    public function getFormatLabel(): ?string
    {
        return match ($this->audioFormat) {
            null => null,
            'mp4', 'm4a' => $this->lossless === true ? 'ALAC' : 'AAC',
            'vorbis', 'ogg' => 'Ogg Vorbis',
            'opus' => 'Opus',
            default => strtoupper($this->audioFormat),
        };
    }

    public function getQuality(): ?AudioQuality
    {
        return AudioQuality::classify($this->audioFormat, $this->lossless, $this->bitrate);
    }

    public function getLyrics(): ?string
    {
        return $this->lyrics;
    }

    public function setLyrics(?string $lyrics): static
    {
        $this->lyrics = $lyrics;

        return $this;
    }

    public function isPrivate(): bool
    {
        return $this->isPrivate;
    }

    public function setIsPrivate(bool $isPrivate): static
    {
        $this->isPrivate = $isPrivate;

        return $this;
    }

    /**
     * Human-friendly display title, falling back to the filename like the
     * legacy UI did when tags were missing.
     */
    public function getDisplayTitle(): string
    {
        if ($this->title !== null && trim($this->title) !== '') {
            return $this->title;
        }

        return $this->file ? pathinfo($this->file, \PATHINFO_FILENAME) : 'Unknown';
    }
}
