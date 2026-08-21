<?php

namespace App\Entity;

use App\Repository\PlaylistRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user playlist. Ported from the legacy `playlists` table. `publicId` is the
 * shareable slug used in deep-links; kept unique and unguessable.
 */
#[ORM\Entity(repositoryClass: PlaylistRepository::class)]
#[ORM\Table(name: 'playlists')]
class Playlist
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 64, unique: true)]
    private ?string $publicId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isPrivate = false;

    /** Whether other users may join as collaborators via an invite link. */
    #[ORM\Column(name: 'is_collaborative', type: 'boolean', options: ['default' => false])]
    private bool $isCollaborative = false;

    /** Legacy view counter, bumped from {@see \App\Controller\PlaybackController} when a track is played from this playlist. */
    #[ORM\Column(name: 'play_count', options: ['default' => 0])]
    private int $playCount = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    /** @var Collection<int, PlaylistSong> */
    #[ORM\OneToMany(targetEntity: PlaylistSong::class, mappedBy: 'playlist', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    private Collection $items;

    public function __construct()
    {
        $this->items = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getPublicId(): ?string
    {
        return $this->publicId;
    }

    public function setPublicId(string $publicId): static
    {
        $this->publicId = $publicId;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

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

    public function isCollaborative(): bool
    {
        return $this->isCollaborative;
    }

    public function setIsCollaborative(bool $isCollaborative): static
    {
        $this->isCollaborative = $isCollaborative;

        return $this;
    }

    public function getPlayCount(): int
    {
        return $this->playCount;
    }

    public function incrementPlayCount(): static
    {
        ++$this->playCount;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, PlaylistSong>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(PlaylistSong $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setPlaylist($this);
        }

        return $this;
    }

    public function removeItem(PlaylistSong $item): static
    {
        $this->items->removeElement($item);

        return $this;
    }
}
