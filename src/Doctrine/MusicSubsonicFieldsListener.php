<?php

namespace App\Doctrine;

use App\Entity\Music;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Keeps Music::albumKey/artistKey/addedAt/updatedAt in sync on every ORM
 * write (scan, tag edit, rating, …) without touching those code paths.
 *
 * Done in onFlush rather than preUpdate: in preUpdate Doctrine ignores direct
 * property changes, so the new values would silently not be saved. Here we
 * change the entity, then recompute its changeset so they are.
 * addedAt is only ever set on insert.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class MusicSubsonicFieldsListener
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $meta = $em->getClassMetadata(Music::class);
        $now = new \DateTimeImmutable();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Music) {
                $entity->refreshSubsonicFields(true, $now);
                $uow->recomputeSingleEntityChangeSet($meta, $entity);
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof Music) {
                $entity->refreshSubsonicFields(false, $now);
                $uow->recomputeSingleEntityChangeSet($meta, $entity);
            }
        }
    }
}
