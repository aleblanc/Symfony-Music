<?php

namespace App\Controller;

use App\Entity\RhythmChart;
use App\Entity\RhythmFavorite;
use App\Entity\RhythmScore;
use App\Entity\User;
use App\Repository\MusicRepository;
use App\Repository\RhythmChartRepository;
use App\Repository\RhythmFavoriteRepository;
use App\Repository\RhythmScoreRepository;
use App\Repository\UserRepository;
use App\Service\RhythmAntiCheat;
use App\Service\RhythmChartGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Rhythm game (Feature 20). Unlike the rest of the port (classic Twig CRUD),
 * this controller is deliberately a JSON API: the gameplay loop is a
 * real-time JS engine that must fetch charts/scores/leaderboards without page
 * reloads. Chart generation is the deterministic pseudo-random Phase 1 port;
 * real beat syncing is Phase 2. See docs/rhythm-game-reference/.
 */
class RhythmController extends AbstractController
{
    private const CSRF_TOKEN_ID = 'rhythm';
    private const PAGE_SIZE = 30;
    private const VALID_DIFFICULTIES = ['easy', 'medium', 'hard', 'expert', 'master', 'demon'];

    public function __construct(
        private readonly RhythmChartRepository $charts,
        private readonly RhythmScoreRepository $scores,
        private readonly RhythmFavoriteRepository $favorites,
        private readonly MusicRepository $music,
        private readonly UserRepository $users,
        private readonly RhythmChartGenerator $generator,
        private readonly RhythmAntiCheat $antiCheat,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/rhythm', name: 'app_rhythm')]
    public function hub(): Response
    {
        return $this->render('rhythm/hub.html.twig');
    }

    #[Route('/rhythm/songs', name: 'app_rhythm_songs', methods: ['GET'])]
    public function songs(Request $request, UrlGeneratorInterface $urls): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', '1'));
        $songs = $this->music->findBy([], ['id' => 'DESC'], self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE);

        $ids = array_map(static fn($s) => $s->getId(), $songs);
        $levels = $this->charts->levelsForSongs($ids);

        $out = [];
        foreach ($songs as $s) {
            $out[] = [
                'id' => $s->getId(),
                'title' => $s->getTitle(),
                'artist' => $s->getArtist(),
                'album' => $s->getAlbum(),
                'duration' => $s->getDuration(),
                'stream' => $urls->generate('app_stream', ['id' => $s->getId()]),
                'cover' => $urls->generate('app_cover', ['id' => $s->getId()]),
                'levels' => $levels[$s->getId()] ?? null,
            ];
        }

        return new JsonResponse($out);
    }

    #[Route('/rhythm/chart/{songId}/{difficulty}', name: 'app_rhythm_chart', methods: ['GET'], requirements: ['songId' => '\d+'])]
    public function chart(int $songId, string $difficulty): JsonResponse
    {
        if (!\in_array($difficulty, self::VALID_DIFFICULTIES, true)) {
            return new JsonResponse(['status' => 'error', 'message' => 'Invalid difficulty'], Response::HTTP_BAD_REQUEST);
        }

        $song = $this->music->find($songId);
        if ($song === null) {
            return new JsonResponse(['status' => 'error', 'message' => 'Song not found'], Response::HTTP_NOT_FOUND);
        }

        $chart = $this->charts->findOneForSong($song, $difficulty);
        $notes = $chart?->getNotesJson() !== null ? json_decode((string) $chart->getNotesJson(), true) : null;

        if ($chart !== null && \is_array($notes) && $notes !== []) {
            return new JsonResponse(['status' => 'success', 'found' => true, 'notes' => $notes, 'level' => $chart->getLevel()]);
        }

        // Lazy generate + cache (Phase 1: pseudo-random, 1.0x density).
        $duration = $song->getDuration() ?: 180;
        $notes = $this->generator->generate($songId, $duration, $difficulty, 1.0);
        $level = $this->generator->level($notes, $duration, $difficulty, 1.0);

        $chart ??= new RhythmChart()->setSong($song)->setDifficulty($difficulty);
        $chart->setNotesJson(json_encode($notes));
        $chart->setLevel($level);
        $this->em->persist($chart);
        $this->em->flush();

        return new JsonResponse(['status' => 'success', 'found' => true, 'notes' => $notes, 'level' => $level]);
    }

    #[Route('/rhythm/levels/{songId}', name: 'app_rhythm_levels', methods: ['GET'], requirements: ['songId' => '\d+'])]
    public function levels(int $songId): JsonResponse
    {
        return new JsonResponse(['status' => 'success', 'levels' => $this->charts->levelsForSong($songId)]);
    }

    #[Route('/rhythm/levels', name: 'app_rhythm_all_levels', methods: ['GET'])]
    public function allLevels(): JsonResponse
    {
        $rows = $this->em->createQuery(
            'SELECT IDENTITY(c.song) AS song_id, c.difficulty AS difficulty, c.level AS level FROM App\Entity\RhythmChart c'
        )->getScalarResult();

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['song_id']][(string) $r['difficulty']] = (int) $r['level'];
        }

        return new JsonResponse(['status' => 'success', 'levels' => $map]);
    }

    #[Route('/rhythm/leaderboard', name: 'app_rhythm_leaderboard', methods: ['GET'])]
    public function leaderboard(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', '1'));

        return new JsonResponse($this->scores->globalLeaderboard($page, self::PAGE_SIZE));
    }

    #[Route('/rhythm/leaderboard/song/{songId}', name: 'app_rhythm_song_leaderboard', methods: ['GET'], requirements: ['songId' => '\d+'])]
    public function songLeaderboard(int $songId): JsonResponse
    {
        return new JsonResponse($this->scores->songLeaderboard($songId));
    }

    #[Route('/rhythm/scores/{userId}', name: 'app_rhythm_user_scores', methods: ['GET'], requirements: ['userId' => '\d+'])]
    public function userScores(int $userId, Request $request): JsonResponse
    {
        $user = $this->users->find($userId);
        if ($user === null) {
            return new JsonResponse([]);
        }

        $all = $request->query->get('all') === '1';
        $page = $all ? null : max(1, (int) $request->query->get('page', '1'));
        $rows = $this->scores->forUser($user, $page);

        $out = [];
        foreach ($rows as $rs) {
            $out[] = [
                'song_id' => $rs->getSong()?->getId(),
                'title' => $rs->getSong()?->getTitle(),
                'artist' => $rs->getSong()?->getArtist(),
                'score' => $rs->getScore(),
                'max_combo' => $rs->getMaxCombo(),
                'perfect' => $rs->getPerfect(),
                'great' => $rs->getGreat(),
                'good' => $rs->getGood(),
                'bad' => $rs->getBad(),
                'miss' => $rs->getMiss(),
                'difficulty' => $rs->getDifficulty(),
                'played_at' => $rs->getPlayedAt(),
            ];
        }

        return new JsonResponse($out);
    }

    #[Route('/rhythm/score', name: 'app_rhythm_score', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function saveScore(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->headers->get('X-CSRF-TOKEN'))) {
            return new JsonResponse(['status' => 'error', 'message' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['status' => 'error', 'message' => 'Invalid body'], Response::HTTP_BAD_REQUEST);
        }

        $hitDeltas = \is_array($data['hit_deltas'] ?? null) ? $data['hit_deltas'] : [];
        $claimedScore = (int) ($data['score'] ?? 0);
        $maxCombo = (int) ($data['max_combo'] ?? 0);

        $banReason = $this->antiCheat->inspect($hitDeltas, $claimedScore, $maxCombo);
        if ($banReason !== null) {
            $newStrikes = abs($user->getRhythmStrikes()) + 1;
            $user->setRhythmStrikes($newStrikes);
            if ($newStrikes >= 3) {
                // 3rd strike: full account ban (legacy also destroyed the session).
                $user->setBanned(true);
                $this->em->flush();

                return new JsonResponse(
                    ['status' => 'error', 'message' => '3rd Strike Cheat detected: ' . $banReason . '. Your account has been permanently banned.'],
                    Response::HTTP_FORBIDDEN
                );
            }
            $this->em->flush();

            return new JsonResponse(
                ['status' => 'error', 'message' => 'Cheat detected (Strike ' . $newStrikes . '/3): ' . $banReason . '. You are banned from the Rhythm Game.'],
                Response::HTTP_FORBIDDEN
            );
        }

        $song = $this->music->find((int) ($data['song_id'] ?? 0));
        if ($song === null) {
            return new JsonResponse(['status' => 'error', 'message' => 'Song not found'], Response::HTTP_NOT_FOUND);
        }

        $run = new RhythmScore();
        $run->setUser($user)
            ->setSong($song)
            ->setScore($claimedScore)
            ->setMaxCombo($maxCombo)
            ->setPerfect((int) ($data['perfect'] ?? 0))
            ->setGreat((int) ($data['great'] ?? 0))
            ->setGood((int) ($data['good'] ?? 0))
            ->setBad((int) ($data['bad'] ?? 0))
            ->setMiss((int) ($data['miss'] ?? 0))
            ->setDifficulty((string) ($data['difficulty'] ?? 'medium'));
        $this->em->persist($run);
        $this->em->flush();

        return new JsonResponse(['status' => 'success']);
    }

    #[Route('/rhythm/favorite/{songId}', name: 'app_rhythm_favorite_toggle', methods: ['POST'], requirements: ['songId' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function toggleFavorite(int $songId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->headers->get('X-CSRF-TOKEN'))) {
            return new JsonResponse(['status' => 'error', 'message' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $song = $this->music->find($songId);
        if ($song === null) {
            return new JsonResponse(['status' => 'error', 'message' => 'Song not found'], Response::HTTP_NOT_FOUND);
        }

        $existing = $this->favorites->findOneForUser($user, $song);
        if ($existing !== null) {
            $this->em->remove($existing);
            $this->em->flush();

            return new JsonResponse(['status' => 'removed', 'is_favorite' => 0]);
        }

        $fav = new RhythmFavorite();
        $fav->setUser($user)->setSong($song)->setSortOrder($this->favorites->nextSortOrder($user));
        $this->em->persist($fav);
        $this->em->flush();

        return new JsonResponse(['status' => 'added', 'is_favorite' => 1]);
    }

    #[Route('/rhythm/favorites', name: 'app_rhythm_favorites', methods: ['GET'])]
    public function favoriteIds(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse([]);
        }

        return new JsonResponse($this->favorites->songIdsForUser($user));
    }

    #[Route('/rhythm/favorite-songs', name: 'app_rhythm_favorite_songs', methods: ['GET'])]
    public function favoriteSongs(#[CurrentUser] ?User $user, UrlGeneratorInterface $urls): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse([]);
        }

        $favs = $this->favorites->findForUser($user);
        $songs = array_map(static fn(RhythmFavorite $f) => $f->getSong(), $favs);
        $ids = array_filter(array_map(static fn($s) => $s?->getId(), $songs));
        $levels = $this->charts->levelsForSongs($ids);

        $out = [];
        foreach ($songs as $s) {
            if ($s === null) {
                continue;
            }
            $out[] = [
                'id' => $s->getId(),
                'title' => $s->getTitle(),
                'artist' => $s->getArtist(),
                'album' => $s->getAlbum(),
                'duration' => $s->getDuration(),
                'stream' => $urls->generate('app_stream', ['id' => $s->getId()]),
                'cover' => $urls->generate('app_cover', ['id' => $s->getId()]),
                'rg_favorite' => 1,
                'levels' => $levels[$s->getId()] ?? null,
            ];
        }

        return new JsonResponse($out);
    }
}
