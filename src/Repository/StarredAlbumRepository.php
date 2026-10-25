<?php

namespace App\Repository;

use App\Entity\StarredAlbum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StarredAlbum>
 */
class StarredAlbumRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StarredAlbum::class);
    }
}
