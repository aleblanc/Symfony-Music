<?php

namespace App\Entity;

use App\Repository\UserSongSettingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-user, per-track audio preferences (volume multiplier + 5-band equalizer).
 * Maps onto the legacy `user_song_settings` table, which has a composite
 * primary key (user_id, song_id) and no surrogate id — modeled the same way
 * as {@see Favorite}.
 */
#[ORM\Entity(repositoryClass: UserSongSettingRepository::class)]
#[ORM\Table(name: 'user_song_settings')]
class UserSongSetting
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Music::class)]
    #[ORM\JoinColumn(name: 'song_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Music $song = null;

    #[ORM\Column(type: 'float', options: ['default' => 1.0])]
    private float $volumeMultiplier = 1.0;

    /** JSON-encoded array of 5 equalizer band gains (dB), e.g. "[0,0,0,0,0]". Mirrors legacy `eq_bands`. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $eqBands = null;

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getSong(): ?Music
    {
        return $this->song;
    }

    public function setSong(?Music $song): static
    {
        $this->song = $song;

        return $this;
    }

    public function getVolumeMultiplier(): float
    {
        return $this->volumeMultiplier;
    }

    public function setVolumeMultiplier(float $volumeMultiplier): static
    {
        $this->volumeMultiplier = $volumeMultiplier;

        return $this;
    }

    public function getEqBands(): ?string
    {
        return $this->eqBands;
    }

    public function setEqBands(?string $eqBands): static
    {
        $this->eqBands = $eqBands;

        return $this;
    }

    /**
     * @return float[]|null Decoded EQ band gains, or null if none stored.
     */
    public function getEqBandsArray(): ?array
    {
        if ($this->eqBands === null) {
            return null;
        }

        $decoded = json_decode($this->eqBands, true);

        return is_array($decoded) ? array_map('floatval', $decoded) : null;
    }
}
