<?php

namespace App\Entity;

use App\Repository\AlbumFavoriteRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * An album of an artist's discography a user marked to listen to, often
 * one not in the library yet. Kept by its MusicBrainz release group id, with
 * a copy of what to show (artist, title, date) so the list stands on its own.
 */
#[ORM\Entity(repositoryClass: AlbumFavoriteRepository::class)]
#[ORM\Table(name: 'album_favorite')]
#[ORM\UniqueConstraint(name: 'album_favorite_user_rg', columns: ['user_id', 'release_group_id'])]
class AlbumFavorite
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 36)]
    private string $releaseGroupId;

    /** Artist name as in the library (its artist page). */
    #[ORM\Column(length: 512)]
    private string $artist;

    #[ORM\Column(length: 512)]
    private string $title;

    #[ORM\Column(length: 16)]
    private string $type;

    /** First release date (YYYY, YYYY-MM or YYYY-MM-DD). */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $releaseDate;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $releaseGroupId, string $artist, string $title, string $type, ?string $releaseDate, \DateTimeImmutable $createdAt)
    {
        $this->user = $user;
        $this->releaseGroupId = $releaseGroupId;
        $this->artist = mb_substr($artist, 0, 512);
        $this->title = mb_substr($title, 0, 512);
        $this->type = mb_substr($type, 0, 16);
        $this->releaseDate = $releaseDate !== null ? mb_substr($releaseDate, 0, 10) : null;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getReleaseGroupId(): string
    {
        return $this->releaseGroupId;
    }

    public function getArtist(): string
    {
        return $this->artist;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getReleaseDate(): ?string
    {
        return $this->releaseDate;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
