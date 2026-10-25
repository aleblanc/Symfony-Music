<?php

namespace App\Entity;

use App\Enum\AudioQuality;
use App\Repository\MusicRepository;
use App\Service\AudioFormat;
use App\Service\ReplayGain;
use App\Subsonic\IdCodec;
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
#[ORM\Index(name: 'music_album_key_idx', columns: ['album_key', 'disc_number', 'track_number'])]
#[ORM\Index(name: 'music_artist_key_idx', columns: ['artist_key'])]
#[ORM\Index(name: 'music_updated_at_idx', columns: ['updated_at'])]
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

    /** Album artist (ID3 TPE2 / Vorbis ALBUMARTIST), distinct from per-track artists. */
    #[ORM\Column(length: 512, nullable: true)]
    private ?string $albumArtist = null;

    #[ORM\Column(nullable: true)]
    private ?int $trackNumber = null;

    #[ORM\Column(nullable: true)]
    private ?int $discNumber = null;

    /**
     * Last metadata-enrichment attempt (MusicBrainz/AcoustID), matched or
     * not; `app:enrich` skips tracks already attempted unless --retry.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $enrichedAt = null;

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

    /** Star rating, 1 (lowest) to 5 (highest); null when not rated. One note per track. */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $rating = null;

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

    /** Spotify track id (base62, 22 chars), null until matched. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $spotifyId = null;

    /**
     * Unix time of the last Spotify lookup, matched or not, so
     * `app:spotify:match` doesn't re-query tracks it already failed on.
     */
    #[ORM\Column(nullable: true)]
    private ?int $spotifyCheckedAt = null;

    /**
     * Unix time of the last LRCLIB lookup, found or not, so
     * `app:lyrics:fetch` doesn't re-query tracks it already failed on.
     */
    #[ORM\Column(nullable: true)]
    private ?int $lyricsCheckedAt = null;

    /** ReplayGain track gain in dB (from the tags, read by app:scan). */
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $replayGainTrack = null;

    /** ReplayGain track peak, linear (1.0 = full scale). */
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $replayGainTrackPeak = null;

    /** ReplayGain album gain in dB. */
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $replayGainAlbum = null;

    /** ReplayGain album peak, linear. */
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $replayGainAlbumPeak = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isPrivate = false;

    /**
     * Subsonic album id (see IdCodec::albumKey), maintained by
     * MusicSubsonicFieldsListener on every insert/update.
     */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $albumKey = null;

    /** Subsonic artist id (see IdCodec::artistKey), maintained like albumKey. */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $artistKey = null;

    /** When the track entered the library; set once, never changed on update. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $addedAt = null;

    /** Last change to the row, for incremental client sync (search3 changedSince). */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

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

    public function getAlbumArtist(): ?string
    {
        return $this->albumArtist;
    }

    public function setAlbumArtist(?string $albumArtist): static
    {
        $this->albumArtist = $albumArtist;

        return $this;
    }

    public function getTrackNumber(): ?int
    {
        return $this->trackNumber;
    }

    public function setTrackNumber(?int $trackNumber): static
    {
        $this->trackNumber = $trackNumber;

        return $this;
    }

    public function getDiscNumber(): ?int
    {
        return $this->discNumber;
    }

    public function setDiscNumber(?int $discNumber): static
    {
        $this->discNumber = $discNumber;

        return $this;
    }

    public function getEnrichedAt(): ?\DateTimeImmutable
    {
        return $this->enrichedAt;
    }

    public function setEnrichedAt(?\DateTimeImmutable $enrichedAt): static
    {
        $this->enrichedAt = $enrichedAt;

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

    public function getRating(): ?int
    {
        return $this->rating;
    }

    /** 1 to 5 stars, null (or 0) to remove the rating. */
    public function setRating(?int $rating): static
    {
        $this->rating = $rating === null || $rating <= 0 ? null : min(5, $rating);

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

    public function getSpotifyId(): ?string
    {
        return $this->spotifyId;
    }

    public function setSpotifyId(?string $spotifyId): static
    {
        $this->spotifyId = $spotifyId;

        return $this;
    }

    public function getSpotifyCheckedAt(): ?int
    {
        return $this->spotifyCheckedAt;
    }

    public function setSpotifyCheckedAt(?int $spotifyCheckedAt): static
    {
        $this->spotifyCheckedAt = $spotifyCheckedAt;

        return $this;
    }

    public function getLyricsCheckedAt(): ?int
    {
        return $this->lyricsCheckedAt;
    }

    public function setLyricsCheckedAt(?int $lyricsCheckedAt): static
    {
        $this->lyricsCheckedAt = $lyricsCheckedAt;

        return $this;
    }

    public function getReplayGain(): ReplayGain
    {
        return new ReplayGain($this->replayGainTrack, $this->replayGainTrackPeak, $this->replayGainAlbum, $this->replayGainAlbumPeak);
    }

    public function setReplayGain(ReplayGain $replayGain): static
    {
        $this->replayGainTrack = $replayGain->trackGain;
        $this->replayGainTrackPeak = $replayGain->trackPeak;
        $this->replayGainAlbum = $replayGain->albumGain;
        $this->replayGainAlbumPeak = $replayGain->albumPeak;

        return $this;
    }

    public function getSpotifyUrl(): ?string
    {
        return $this->spotifyId !== null ? 'https://open.spotify.com/track/' . $this->spotifyId : null;
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

    public function getAlbumKey(): ?string
    {
        return $this->albumKey;
    }

    public function getArtistKey(): ?string
    {
        return $this->artistKey;
    }

    public function getAddedAt(): ?\DateTimeImmutable
    {
        return $this->addedAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Recomputes the Subsonic keys and timestamps. Called by
     * MusicSubsonicFieldsListener; $isNew sets addedAt (insert only).
     */
    public function refreshSubsonicFields(bool $isNew, \DateTimeImmutable $now): void
    {
        $this->artistKey = IdCodec::artistKey($this->albumArtist, $this->artist);
        $this->albumKey = IdCodec::albumKey($this->albumArtist, $this->artist, $this->album);
        $this->updatedAt = $now;
        if ($isNew && $this->addedAt === null) {
            $this->addedAt = $now;
        }
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
