<?php

namespace App\Entity;

use App\Repository\StarredAlbumRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A starred local album (Subsonic `star?albumId=`), keyed by Music::albumKey.
 * Single-user app, so no user column. Not to be confused with AlbumFavorite,
 * which hearts remote MusicBrainz release groups.
 */
#[ORM\Entity(repositoryClass: StarredAlbumRepository::class)]
#[ORM\Table(name: 'starred_album')]
class StarredAlbum
{
    #[ORM\Id]
    #[ORM\Column(length: 16)]
    private string $albumKey;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $starredAt;

    public function __construct(string $albumKey, \DateTimeImmutable $starredAt = new \DateTimeImmutable())
    {
        $this->albumKey = $albumKey;
        $this->starredAt = $starredAt;
    }

    public function getAlbumKey(): string
    {
        return $this->albumKey;
    }

    public function getStarredAt(): \DateTimeImmutable
    {
        return $this->starredAt;
    }
}
