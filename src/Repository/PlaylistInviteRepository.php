<?php

namespace App\Repository;

use App\Entity\PlaylistInvite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlaylistInvite>
 */
class PlaylistInviteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlaylistInvite::class);
    }

    /**
     * Returns the invite only if it exists and has not expired, mirroring
     * the legacy `WHERE token = ? AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)`.
     */
    public function findValidByToken(string $token): ?PlaylistInvite
    {
        $invite = $this->find($token);
        if ($invite === null || $invite->isExpired()) {
            return null;
        }

        return $invite;
    }

    /**
     * Opportunistic cleanup of expired tokens, ported from the legacy
     * `DELETE FROM playlist_invites WHERE expires_at IS NOT NULL AND expires_at <= CURRENT_TIMESTAMP`
     * run before minting a new invite.
     */
    public function purgeExpired(): void
    {
        $this->createQueryBuilder('pi')
            ->delete()
            ->andWhere('pi.expiresAt IS NOT NULL')
            ->andWhere('pi.expiresAt <= :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->execute();
    }
}
