<?php

namespace App\Repository;

use App\Entity\Mix;
use App\Entity\MixSong;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MixSong>
 */
class MixSongRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MixSong::class);
    }

    /**
     * @return MixSong[] Ordered by manual sort order, matching the legacy
     *                    `manual_order` sort of the `mix_songs` view.
     */
    public function findForMix(Mix $mix): array
    {
        return $this->createQueryBuilder('ms')
            ->select('ms', 'song')
            ->join('ms.song', 'song')
            ->andWhere('ms.mix = :mix')
            ->setParameter('mix', $mix)
            ->orderBy('ms.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
