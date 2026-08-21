<?php

namespace App\Repository;

use App\Entity\Playlist;
use App\Entity\PlaylistSong;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlaylistSong>
 */
class PlaylistSongRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlaylistSong::class);
    }

    public function nextSortOrder(Playlist $playlist): int
    {
        $max = $this->createQueryBuilder('ps')
            ->select('MAX(ps.sortOrder)')
            ->andWhere('ps.playlist = :playlist')
            ->setParameter('playlist', $playlist)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $max + 1;
    }

    /**
     * @return list<PlaylistSong> Ordered by manual sort order.
     */
    public function findForPlaylist(Playlist $playlist): array
    {
        return $this->createQueryBuilder('ps')
            ->andWhere('ps.playlist = :playlist')
            ->setParameter('playlist', $playlist)
            ->orderBy('ps.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Swaps a track's manual position with its immediate neighbour (a simple
     * classic-form alternative to the legacy `update_playlist_order`, which
     * took a full drag-and-drop-reordered id list; see PLAN_MIGRATION §4bis
     * on preferring classic forms over JSON endpoints).
     */
    public function move(Playlist $playlist, int $songId, int $delta): void
    {
        $items = $this->findForPlaylist($playlist);

        $index = null;
        foreach ($items as $i => $item) {
            if ($item->getSong()?->getId() === $songId) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return;
        }

        $swapIndex = $index + $delta;
        if ($swapIndex < 0 || $swapIndex >= \count($items)) {
            return;
        }

        $a = $items[$index]->getSortOrder();
        $b = $items[$swapIndex]->getSortOrder();
        $items[$index]->setSortOrder($b);
        $items[$swapIndex]->setSortOrder($a);

        $this->getEntityManager()->flush();
    }
}
