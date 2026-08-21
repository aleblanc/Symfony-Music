<?php

namespace App\Entity;

use App\Repository\MusicRepository;
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

    /**
     * Cover art bytes, extracted from tags at scan time. The legacy schema has
     * no mime column (art is normalised to WebP), so the type is sniffed from
     * the bytes when served.
     */
    #[ORM\Column(type: 'blob', nullable: true)]
    private $image = null;

    #[ORM\Column(nullable: true)]
    private ?int $lastModified = null;

    #[ORM\Column(nullable: true)]
    private ?int $bitrate = null;

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

    /**
     * @return resource|string|null
     */
    public function getImage()
    {
        return $this->image;
    }

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
