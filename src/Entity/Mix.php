<?php

namespace App\Entity;

use App\Repository\MixRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * An algorithmically generated "mix" (a themed shuffled playlist seeded from
 * an artist/genre/year). Ported from the legacy `mixes` table. Mixes are
 * global (not owned by a user) and short-lived: the legacy app deletes any
 * mix older than 3 days on every request and regenerates a fresh batch when
 * none remain (see {@see \App\Repository\MixRepository::expireOlderThan()}).
 *
 * Note: `imageId` intentionally has no foreign-key relation, mirroring the
 * legacy schema (`image_id INTEGER` with no `REFERENCES` clause) — it is
 * resolved to a track's cover art at render time on a best-effort basis.
 */
#[ORM\Entity(repositoryClass: MixRepository::class)]
#[ORM\Table(name: 'mixes')]
class Mix
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private ?string $publicId = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255)]
    private ?string $creator = null;

    #[ORM\Column(name: 'image_id', nullable: true)]
    private ?int $imageId = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    /** @var Collection<int, MixSong> */
    #[ORM\OneToMany(targetEntity: MixSong::class, mappedBy: 'mix', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    private Collection $songs;

    public function __construct()
    {
        $this->songs = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getCreator(): ?string
    {
        return $this->creator;
    }

    public function setCreator(string $creator): static
    {
        $this->creator = $creator;

        return $this;
    }

    public function getImageId(): ?int
    {
        return $this->imageId;
    }

    public function setImageId(?int $imageId): static
    {
        $this->imageId = $imageId;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, MixSong>
     */
    public function getSongs(): Collection
    {
        return $this->songs;
    }

    public function addSong(MixSong $item): static
    {
        if (!$this->songs->contains($item)) {
            $this->songs->add($item);
            $item->setMix($this);
        }

        return $this;
    }
}
