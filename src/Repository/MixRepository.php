<?php

namespace App\Repository;

use App\Entity\Mix;
use App\Entity\MixSong;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Mix>
 *
 * Ported from the legacy `get_mixes` action: mixes are algorithmically
 * generated "themed" shuffles (seeded from a random artist, genre or year),
 * are global (not per-user), and expire after 3 days. Generation
 * deliberately does **not** filter by track visibility (the legacy query
 * pulls candidate song ids from the whole `music` table regardless of
 * `is_private`) — visibility is only enforced when a mix is actually opened
 * ({@see \App\Controller\DiscoveryController::mixShow()}), exactly mirroring
 * the legacy split between the generation query (unfiltered) and the
 * `mix_songs` view resolver (filtered).
 */
class MixRepository extends ServiceEntityRepository
{
    private const SONGS_PER_MIX = 50;
    private const PAD_POOL_SIZE = 100;

    public function __construct(
        ManagerRegistry $registry,
        private readonly MusicRepository $music,
    ) {
        parent::__construct($registry, Mix::class);
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('mx')
            ->select('COUNT(mx.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Deletes mixes (and cascades to their mix_songs) older than the cutoff,
     * mirroring the legacy `DELETE FROM mixes WHERE created_at <= datetime('now', '-3 days')`
     * housekeeping that runs on every request.
     */
    public function expireOlderThan(\DateTimeImmutable $cutoff): void
    {
        $this->createQueryBuilder('mx')
            ->delete()
            ->andWhere('mx.createdAt <= :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }

    /**
     * Generates a fresh batch of mixes if none currently exist, matching the
     * legacy behaviour of auto-seeding 24 mixes the first time `get_mixes` is
     * called after the table is empty (e.g. right after the 3-day expiry).
     */
    public function ensureSeeded(int $target = 24): void
    {
        if ($this->countAll() === 0) {
            $this->generate($target);
        }
    }

    /**
     * Builds $count new mixes from random artist/genre/year seeds.
     */
    public function generate(int $count): void
    {
        $seeds = $this->music->distinctSeedValues();
        if ($seeds === []) {
            return;
        }

        shuffle($seeds);
        $seeds = \array_slice($seeds, 0, $count);

        $em = $this->getEntityManager();

        foreach ($seeds as $seed) {
            $songIds = $this->music->idsMatchingSeed($seed['type'], $seed['value']);
            shuffle($songIds);
            $songIds = \array_slice($songIds, 0, self::SONGS_PER_MIX);

            if (\count($songIds) < self::SONGS_PER_MIX) {
                $needed = self::SONGS_PER_MIX - \count($songIds);
                $padCandidates = $this->music->randomAnyIds(self::PAD_POOL_SIZE);
                foreach ($padCandidates as $candidateId) {
                    if ($needed <= 0) {
                        break;
                    }
                    if (!\in_array($candidateId, $songIds, true)) {
                        $songIds[] = $candidateId;
                        --$needed;
                    }
                }
            }

            if ($songIds === []) {
                continue;
            }

            $imageId = $this->music->oneImageIdMatchingSeed($seed['type'], $seed['value']) ?? $songIds[0];

            $mix = new Mix();
            $mix->setPublicId(bin2hex(random_bytes(8)));
            $mix->setName('Mix - ' . $seed['value']);
            $mix->setCreator('PHP-Music');
            $mix->setImageId($imageId);
            $em->persist($mix);

            $order = 0;
            foreach ($songIds as $songId) {
                $song = $this->music->find($songId);
                if ($song === null) {
                    continue;
                }
                $mixSong = new MixSong();
                $mixSong->setMix($mix);
                $mixSong->setSong($song);
                $mixSong->setSortOrder($order++);
                $em->persist($mixSong);
            }
        }

        $em->flush();
    }

    /**
     * Sort keys accepted by `?sort=` on the mixes listing, ported from the
     * legacy `get_mixes` `sort_map`.
     */
    public const SORT_KEYS = ['name_asc', 'name_desc', 'newest', 'oldest'];
    public const DEFAULT_SORT = 'newest';

    /**
     * @return Paginator<Mix>
     */
    public function paginate(?string $q, string $sort, int $page, int $perPage): Paginator
    {
        $qb = $this->createQueryBuilder('mx');

        match ($sort) {
            'name_asc' => $qb->orderBy('mx.name', 'ASC'),
            'name_desc' => $qb->orderBy('mx.name', 'DESC'),
            'oldest' => $qb->orderBy('mx.id', 'ASC'),
            default => $qb->orderBy('mx.id', 'DESC'),
        };

        if ($q !== null && trim($q) !== '') {
            $qb->andWhere('mx.name LIKE :q')->setParameter('q', '%' . trim($q) . '%');
        }

        $qb->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage);

        return new Paginator($qb->getQuery(), fetchJoinCollection: false);
    }

    public function findOneByPublicId(string $publicId): ?Mix
    {
        return $this->findOneBy(['publicId' => $publicId]);
    }

    /**
     * @return int Number of tracks in the mix (for the listing's "N tracks" badge).
     */
    public function songCount(Mix $mix): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(ms.song)')
            ->from(MixSong::class, 'ms')
            ->andWhere('ms.mix = :mix')
            ->setParameter('mix', $mix)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
