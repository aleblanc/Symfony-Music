<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Application user. Ported from the legacy `users` table of the monolithic
 * php-music index.php. The legacy schema mixed authentication data with
 * profile blobs; here we keep authentication first-class and expose profile
 * fields as plain columns.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'users_email_idx', columns: ['email'])]
#[ORM\UniqueConstraint(name: 'users_artist_idx', columns: ['artist'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $email = null;

    /**
     * Public artist / display name. Unique, mirrors the legacy `artist` column
     * used everywhere as the human-facing owner identity.
     */
    #[ORM\Column(length: 255, unique: true)]
    private ?string $artist = null;

    #[ORM\Column]
    private ?string $passwordHash = null;

    #[ORM\Column(length: 32, options: ['default' => 'user'])]
    private string $status = 'user';

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $banned = false;

    /**
     * Rhythm-game anti-cheat strike counter (see {@see \App\Service\RhythmAntiCheat}).
     * 1st/2nd strike = rhythm-game ban only; 3rd strike flips `banned` (full
     * account ban). Ported from the legacy `users.rhythm_strikes` column.
     */
    #[ORM\Column(name: 'rhythm_strikes', type: 'integer', options: ['default' => 0])]
    private int $rhythmStrikes = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $bio = null;

    /** Profile picture as a binary blob (image data). */
    #[ORM\Column(type: 'blob', nullable: true)]
    private mixed $profilePicture = null;

    /** MIME type of profile picture (e.g., 'image/webp', 'image/svg+xml'). */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $profilePictureType = null;

    /** Profile background as a binary blob (image data). */
    #[ORM\Column(type: 'blob', nullable: true)]
    private mixed $profileBackground = null;

    /** MIME type of profile background. */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $profileBackgroundType = null;

    /** Verification status: 'no' (default), 'pending', or 'yes'. */
    #[ORM\Column(length: 20, options: ['default' => 'no'])]
    private string $verified = 'no';

    /** Backup key for account restoration (only set when account is soft-deleted). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $backupKey = null;

    /** Flag set by forgot_password endpoint; checked by admin to send reset link. */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $resetRequested = false;

    /** JSON blob of arbitrary user preferences (mirrors legacy `settings`). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $settings = null;

    /** Date (Y-m-d) of the last song upload, used to reset the daily quota. */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $lastUploadDate = null;

    /** Number of songs uploaded on `lastUploadDate` (daily quota counter). */
    #[ORM\Column(options: ['default' => 0])]
    private int $dailyUploadCount = 0;

    /** @var Collection<int, Music> */
    #[ORM\OneToMany(targetEntity: Music::class, mappedBy: 'owner')]
    private Collection $songs;

    public function __construct()
    {
        $this->songs = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getArtist(): ?string
    {
        return $this->artist;
    }

    public function setArtist(string $artist): static
    {
        $this->artist = $artist;

        return $this;
    }

    /**
     * The unique identifier Symfony Security uses for this user.
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * Roles are derived from the legacy `status` column (there is no `roles`
     * column in the shared schema).
     *
     * @return string[]
     */
    public function getRoles(): array
    {
        $roles = ['ROLE_USER'];

        if (\in_array($this->status, ['admin', 'super_admin'], true)) {
            $roles[] = 'ROLE_ADMIN';
        }
        if ($this->status === 'super_admin') {
            $roles[] = 'ROLE_SUPER_ADMIN';
        }

        return $roles;
    }

    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    public function getPasswordHash(): ?string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(string $passwordHash): static
    {
        $this->passwordHash = $passwordHash;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isSuperAdmin(): bool
    {
        return $this->status === 'super_admin';
    }

    public function isBanned(): bool
    {
        return $this->banned;
    }

    public function setBanned(bool $banned): static
    {
        $this->banned = $banned;

        return $this;
    }

    public function getBio(): ?string
    {
        return $this->bio;
    }

    public function setBio(?string $bio): static
    {
        $this->bio = $bio;

        return $this;
    }

    public function getSettings(): ?string
    {
        return $this->settings;
    }

    public function setSettings(?string $settings): static
    {
        $this->settings = $settings;

        return $this;
    }

    /**
     * @return Collection<int, Music>
     */
    public function getSongs(): Collection
    {
        return $this->songs;
    }

    public function getLastUploadDate(): ?string
    {
        return $this->lastUploadDate;
    }

    public function setLastUploadDate(?string $lastUploadDate): static
    {
        $this->lastUploadDate = $lastUploadDate;

        return $this;
    }

    public function getDailyUploadCount(): int
    {
        return $this->dailyUploadCount;
    }

    public function setDailyUploadCount(int $dailyUploadCount): static
    {
        $this->dailyUploadCount = $dailyUploadCount;

        return $this;
    }

    public function getProfilePicture(): mixed
    {
        return $this->profilePicture;
    }

    public function setProfilePicture(?string $profilePicture): static
    {
        $this->profilePicture = $profilePicture;

        return $this;
    }

    public function getProfilePictureType(): ?string
    {
        return $this->profilePictureType;
    }

    public function setProfilePictureType(?string $profilePictureType): static
    {
        $this->profilePictureType = $profilePictureType;

        return $this;
    }

    public function getProfileBackground(): mixed
    {
        return $this->profileBackground;
    }

    public function setProfileBackground(?string $profileBackground): static
    {
        $this->profileBackground = $profileBackground;

        return $this;
    }

    public function getProfileBackgroundType(): ?string
    {
        return $this->profileBackgroundType;
    }

    public function setProfileBackgroundType(?string $profileBackgroundType): static
    {
        $this->profileBackgroundType = $profileBackgroundType;

        return $this;
    }

    public function getVerified(): string
    {
        return $this->verified;
    }

    public function setVerified(string $verified): static
    {
        $this->verified = $verified;

        return $this;
    }

    public function getBackupKey(): ?string
    {
        return $this->backupKey;
    }

    public function setBackupKey(?string $backupKey): static
    {
        $this->backupKey = $backupKey;

        return $this;
    }

    public function isResetRequested(): bool
    {
        return $this->resetRequested;
    }

    public function setResetRequested(bool $resetRequested): static
    {
        $this->resetRequested = $resetRequested;

        return $this;
    }

    public function getRhythmStrikes(): int
    {
        return $this->rhythmStrikes;
    }

    public function setRhythmStrikes(int $rhythmStrikes): static
    {
        $this->rhythmStrikes = $rhythmStrikes;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // No transient sensitive data stored on the entity.
    }
}
