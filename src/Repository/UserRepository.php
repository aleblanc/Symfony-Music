<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function save(User $user, bool $flush = true): void
    {
        $this->getEntityManager()->persist($user);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Used by Symfony Security to transparently rehash passwords when the
     * configured algorithm changes.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPasswordHash($newHashedPassword);
        $this->save($user);
    }

    /**
     * Case-insensitive lookup by display name, used to link a "Discover
     * Artists" shelf entry back to a registered user's account when the
     * track's `artist` tag matches one.
     */
    public function findOneByArtistName(string $name): ?User
    {
        return $this->createQueryBuilder('u')
            ->andWhere('LOWER(u.artist) = LOWER(:name)')
            ->setParameter('name', $name)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Exact lookup by email OR display name, used solely to resolve a single
     * playlist collaborator by the identifier the owner typed in. Ported
     * from the `target` branch of the legacy `manage_collaborators`/
     * `manage_song_collaborators` "add" action — not a general user search
     * (that live-search variant, `collab_action === 'search'`, is out of
     * scope, see PLAN_MIGRATION Feature 9).
     */
    public function findOneByEmailOrArtistName(string $target): ?User
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.email = :target OR LOWER(u.artist) = LOWER(:target)')
            ->setParameter('target', $target)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Random users to suggest, excluding the viewer and an optional target
     * user, banned accounts and soft-deleted accounts. Ported from the
     * legacy `get_recommended_artists` action. Random selection is done in
     * PHP (fetch ids, shuffle, slice) to stay within plain QueryBuilder
     * usage (no SQL `RANDOM()` function).
     *
     * @return User[]
     */
    public function randomSuggested(?User $viewer, ?int $excludeUserId, int $limit = 20): array
    {
        $qb = $this->createQueryBuilder('u')
            ->select('u.id')
            ->andWhere('u.banned = false')
            ->andWhere("u.email NOT LIKE 'deleted\\_%' ESCAPE '\\'");

        if ($viewer !== null) {
            $qb->andWhere('u.id != :viewerId')->setParameter('viewerId', $viewer->getId());
        }
        if ($excludeUserId !== null) {
            $qb->andWhere('u.id != :targetId')->setParameter('targetId', $excludeUserId);
        }

        $ids = array_map(
            static fn(array $r): int => (int) $r['id'],
            $qb->getQuery()->getScalarResult(),
        );
        shuffle($ids);
        $ids = \array_slice($ids, 0, $limit);

        if ($ids === []) {
            return [];
        }

        return $this->createQueryBuilder('u')
            ->andWhere('u.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }
}
