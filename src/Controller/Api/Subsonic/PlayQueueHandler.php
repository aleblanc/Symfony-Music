<?php

namespace App\Controller\Api\Subsonic;

use App\Entity\PlayQueue;
use App\Entity\User;
use App\Subsonic\IdCodec;
use App\Subsonic\LibraryQuery;
use App\Subsonic\SongMapper;
use App\Subsonic\SubsonicException;
use App\Subsonic\SubsonicHandler;
use App\Subsonic\SubsonicRequest;
use Doctrine\ORM\EntityManagerInterface;

/**
 * savePlayQueue / getPlayQueue: one saved queue (single user), so playback
 * started on the phone can resume on the tablet.
 */
final class PlayQueueHandler implements SubsonicHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LibraryQuery $library,
        private readonly SongMapper $songs,
    ) {}

    public static function methods(): array
    {
        return ['savePlayQueue', 'getPlayQueue'];
    }

    public function handle(string $method, SubsonicRequest $request, User $user): array
    {
        $queue = $this->em->find(PlayQueue::class, PlayQueue::SINGLETON_ID);

        if ($method === 'savePlayQueue') {
            $ids = [];
            foreach ($request->getAll('id') as $id) {
                $decoded = IdCodec::decode($id);
                if ($decoded['type'] !== 'song') {
                    throw SubsonicException::notFound('Song');
                }
                $ids[] = (int) $decoded['value'];
            }
            $current = $request->get('current');
            if ($queue === null) {
                $queue = new PlayQueue();
                $this->em->persist($queue);
            }
            $queue->replace($ids, $current !== null && ctype_digit($current) ? (int) $current : null, $request->int('position', 0), $request->client());
            $this->em->flush();

            return [];
        }

        if ($queue === null || $queue->getSongIds() === []) {
            return [];
        }

        return ['playQueue' => [
            'current' => $queue->getCurrent() !== null ? (string) $queue->getCurrent() : null,
            'position' => $queue->getPosition(),
            'username' => $user->getUserIdentifier(),
            'changed' => SongMapper::iso($queue->getChangedAt()->format('Y-m-d H:i:s')),
            'changedBy' => $queue->getChangedBy(),
            'entry' => $this->songs->children($user, $this->library->songsByIds($user, $queue->getSongIds())),
        ]];
    }
}
