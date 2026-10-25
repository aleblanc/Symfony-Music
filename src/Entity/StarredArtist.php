<?php

namespace App\Entity;

use App\Repository\StarredArtistRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A starred artist (Subsonic `star?artistId=`), keyed by Music::artistKey.
 * Single-user app, so no user column.
 */
#[ORM\Entity(repositoryClass: StarredArtistRepository::class)]
#[ORM\Table(name: 'starred_artist')]
class StarredArtist
{
    #[ORM\Id]
    #[ORM\Column(length: 16)]
    private string $artistKey;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $starredAt;

    public function __construct(string $artistKey, \DateTimeImmutable $starredAt = new \DateTimeImmutable())
    {
        $this->artistKey = $artistKey;
        $this->starredAt = $starredAt;
    }

    public function getArtistKey(): string
    {
        return $this->artistKey;
    }

    public function getStarredAt(): \DateTimeImmutable
    {
        return $this->starredAt;
    }
}
