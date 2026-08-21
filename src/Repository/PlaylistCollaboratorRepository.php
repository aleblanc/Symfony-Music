<?php

namespace App\Repository;

use App\Entity\Playlist;
use App\Entity\PlaylistCollaborator;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlaylistCollaborator>
 */
class PlaylistCollaboratorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlaylistCollaborator::class);
    }

    public function isCollaborator(Playlist $playlist, User $user): bool
    {
        return $this->findOneBy(['playlist' => $playlist, 'user' => $user]) !== null;
    }

    /**
     * @return PlaylistCollaborator[]
     */
    public function findForPlaylist(Playlist $playlist): array
    {
        return $this->createQueryBuilder('pc')
            ->andWhere('pc.playlist = :playlist')
            ->setParameter('playlist', $playlist)
            ->getQuery()
            ->getResult();
    }

    public function removeByPlaylistAndUserId(Playlist $playlist, int $userId): void
    {
        $this->createQueryBuilder('pc')
            ->delete()
            ->andWhere('pc.playlist = :playlist')
            ->andWhere('pc.user = :userId')
            ->setParameter('playlist', $playlist)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();
    }

    /**
     * Wipes every collaborator of a playlist, mirroring the legacy cleanup
     * done when a playlist is switched back to private / non-collaborative.
     */
    public function removeAllForPlaylist(Playlist $playlist): void
    {
        $this->createQueryBuilder('pc')
            ->delete()
            ->andWhere('pc.playlist = :playlist')
            ->setParameter('playlist', $playlist)
            ->getQuery()
            ->execute();
    }
}
