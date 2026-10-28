<?php

namespace App\Repository;

use App\Entity\ArtistProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ArtistProfile>
 */
class ArtistProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArtistProfile::class);
    }

    public function findOneByName(string $name): ?ArtistProfile
    {
        return $this->findOneBy(['name' => $name]);
    }

    /**
     * @return array<string, ArtistProfile> by artist name
     */
    public function allByName(): array
    {
        $profiles = [];
        foreach ($this->findAll() as $profile) {
            $profiles[$profile->getName()] = $profile;
        }

        return $profiles;
    }

    /** True when a profile keeps this Instagram post (shortcode: letters, digits, - and _, checked by the route; "_" matching any character is harmless here). */
    public function hasPost(string $shortcode): bool
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM artist_profile WHERE posts LIKE ?', ['%"id":"' . $shortcode . '"%']) > 0;
    }

    /**
     * @return list<ArtistProfile>
     */
    public function findWithYoutube(): array
    {
        /** @var list<ArtistProfile> */
        return $this->createQueryBuilder('a')
            ->andWhere('a.youtubeChannelId IS NOT NULL')
            ->orderBy('a.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
