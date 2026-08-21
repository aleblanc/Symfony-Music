<?php

namespace App\Repository;

use App\Entity\Playlist;
use App\Entity\PlaylistCollaborator;
use App\Entity\PlaylistInvite;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Playlist>
 */
class PlaylistRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Playlist::class);
    }

    public function save(Playlist $playlist, bool $flush = true): void
    {
        $this->getEntityManager()->persist($playlist);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Playlist $playlist, bool $flush = true): void
    {
        $em = $this->getEntityManager();

        // This app never sets `PRAGMA foreign_keys = ON` on its SQLite
        // connections (see SqlitePragmaMiddleware), so the `ON DELETE
        // CASCADE` declared on playlist_collaborators.playlist_id and
        // playlist_invites.playlist_id is never actually enforced by
        // SQLite. Doctrine's own cascade only covers `Playlist::$items`
        // (playlist_songs, declared as an owned OneToMany with
        // cascade:['remove']) — PlaylistCollaborator/PlaylistInvite are not
        // modelled as collections on Playlist, so without this explicit
        // cleanup, deleting a playlist silently orphans its collaborator
        // rows and invite tokens. Verified against the running app (not
        // just raw SQL): see PLAN_MIGRATION §4bis.
        $em->createQueryBuilder()
            ->delete(PlaylistCollaborator::class, 'pc')
            ->andWhere('pc.playlist = :playlist')
            ->setParameter('playlist', $playlist)
            ->getQuery()
            ->execute();
        $em->createQueryBuilder()
            ->delete(PlaylistInvite::class, 'pi')
            ->andWhere('pi.playlist = :playlist')
            ->setParameter('playlist', $playlist)
            ->getQuery()
            ->execute();

        $em->remove($playlist);
        if ($flush) {
            $em->flush();
        }
    }

    /**
     * @return Playlist[]
     */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByPublicId(string $publicId): ?Playlist
    {
        return $this->findOneBy(['publicId' => $publicId]);
    }

    /**
     * Playlists the user collaborates on (but does not own). Ported from the
     * legacy `get_collab_playlists` action.
     *
     * @return Playlist[]
     */
    public function findCollabForUser(User $user): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('p')
            ->from(Playlist::class, 'p')
            ->join(PlaylistCollaborator::class, 'pc', 'WITH', 'pc.playlist = p')
            ->andWhere('pc.user = :user')
            ->setParameter('user', $user)
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
