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
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private readonly TranslatorInterface $translator,
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
        $query = trim($request->query->getString('q'));
        $sort = $request->query->getString('sort', 'id_desc');
        $artist = trim($request->query->getString('artist'));
        $songs = $this->music->rhythmList(
            $query !== '' ? $query : null,
            $sort,
            self::PAGE_SIZE,
            ($page - 1) * self::PAGE_SIZE,
            $artist !== '' ? $artist : null,
        );

        $ids = array_map(static fn($s): int => $s->getId() ?? throw new \LogicException('Persisted song must have an id.'), $songs);
        $levels = $this->charts->levelsForSongs($ids);

        $out = [];
        foreach ($songs as $s) {
            $songId = $s->getId() ?? throw new \LogicException('Persisted song must have an id.');
            $out[] = [
                'id' => $songId,
                'title' => $s->getTitle(),
                'artist' => $s->getArtist(),
                'album' => $s->getAlbum(),
                'duration' => $s->getDuration(),
                'stream' => $urls->generate('app_stream', ['id' => $songId]),
                'cover' => $urls->generate('app_cover', ['id' => $songId]),
                'levels' => $levels[$songId] ?? null,
                // Usable = v3 grid carrying strength-ranked band onsets; older
                // formats report false so the client re-analyses (self-healing).
                'beatAware' => str_contains((string) $s->getBeatGridJson(), '"v":4'),
            ];
        }

        return new JsonResponse($out);
    }

    /**
     * Artist card of the hub's artist-detail view (port of the legacy
     * `get_view_data&type=artist`): tells whether the artist name maps to a
     * registered account (which unlocks the favorites shelf) and returns the
     * artist's tracks. Without this the engine treated EVERY artist as
     * unregistered ("Only registered users can have favorites").
     */
    #[Route('/rhythm/artist-info', name: 'app_rhythm_artist_info', methods: ['GET'])]
    public function artistInfo(Request $request, UrlGeneratorInterface $urls): JsonResponse
    {
        $name = trim($request->query->getString('name'));
        if ($name === '') {
            return new JsonResponse(['status' => 'error', 'message' => $this->translator->trans('error.rhythm.missing_artist')], Response::HTTP_BAD_REQUEST);
        }

        $owner = $this->users->findOneBy(['artist' => $name]);
        $songs = $this->music->rhythmList(null, 'id_desc', self::PAGE_SIZE, 0, $name);
        $ids = array_map(static fn($s): int => $s->getId() ?? throw new \LogicException('Persisted song must have an id.'), $songs);
        $levels = $this->charts->levelsForSongs($ids);

        $out = [];
        foreach ($songs as $s) {
            $songId = $s->getId() ?? throw new \LogicException('Persisted song must have an id.');
            $out[] = [
                'id' => $songId,
                'title' => $s->getTitle(),
                'artist' => $s->getArtist(),
                'album' => $s->getAlbum(),
                'duration' => $s->getDuration(),
                'stream' => $urls->generate('app_stream', ['id' => $songId]),
                'cover' => $urls->generate('app_cover', ['id' => $songId]),
                'levels' => $levels[$songId] ?? null,
                'beatAware' => str_contains((string) $s->getBeatGridJson(), '"v":4'),
            ];
        }

        return new JsonResponse([
            'status' => 'success',
            'details' => [
                'is_user' => $owner !== null,
                'user_id' => $owner?->getId(),
            ],
            'songs' => $out,
        ]);
    }

    /**
     * Artist directory tab. Much simplified port of `get_rhythm_artists`:
     * per-player score stats and follower counts are out of scope (social
     * features were cut), so those fields are zeroed for the engine's cards.
     */
    #[Route('/rhythm/artists', name: 'app_rhythm_artists_list', methods: ['GET'])]
    public function artists(Request $request): JsonResponse
    {
        // The engine's "following_of" mode belongs to the removed social
        // feature: report an empty list instead of a wrong one.
        if (trim($request->query->getString('following_of')) !== '') {
            return new JsonResponse([]);
        }

        $page = max(1, (int) $request->query->get('page', '1'));
        $query = trim($request->query->getString('q'));
        $sort = $request->query->getString('sort', 'name_asc');
        $rows = $this->music->rhythmArtists($query !== '' ? $query : null, $sort, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE);

        // Map artist names to registered accounts in one query.
        $names = array_column($rows, 'name');
        $userIds = [];
        if ($names !== []) {
            foreach ($this->users->findBy(['artist' => $names]) as $u) {
                $userIds[(string) $u->getArtist()] = $u->getId();
            }
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'user_id' => $userIds[$r['name']] ?? null,
                'name' => $r['name'],
                'id' => $r['sample'],
                'count' => $r['count'],
                'score' => 0,
                'plays' => 0,
                'perfect' => 0,
                'great' => 0,
                'good' => 0,
                'bad' => 0,
                'miss' => 0,
                'followers' => 0,
            ];
        }

        return new JsonResponse($out);
    }

    #[Route('/rhythm/chart/{songId}/{difficulty}', name: 'app_rhythm_chart', methods: ['GET'], requirements: ['songId' => '\d+'])]
    public function chart(int $songId, string $difficulty): JsonResponse
    {
        if (!\in_array($difficulty, self::VALID_DIFFICULTIES, true)) {
            return new JsonResponse(['status' => 'error', 'message' => $this->translator->trans('error.rhythm.invalid_difficulty')], Response::HTTP_BAD_REQUEST);
        }

        $song = $this->music->find($songId);
        if ($song === null) {
            return new JsonResponse(['status' => 'error', 'message' => $this->translator->trans('error.rhythm.song_not_found')], Response::HTTP_NOT_FOUND);
        }

        $duration = $song->getDuration() ?: 180;
        $grid = $song->getBeatGridJson();

        // Phase 2: beat-synced, regenerated on the fly from the cached grid,
        // never persisted (keeps the DB tiny — only the grid + levels are stored).
        // Usable = v3 bands with enough onsets, or a legacy beats grid.
        if ($grid !== null) {
            $decoded = json_decode($grid, true);
            if (\is_array($decoded) && self::gridUsable($decoded)) {
                $notes = $this->generator->generateFromBeats($songId, $duration, $difficulty, $decoded, 1.0);
                $level = $this->charts->findOneForSong($song, $difficulty)?->getLevel()
                    ?? $this->generator->level($notes, $duration, $difficulty, 1.0);

                return new JsonResponse(['status' => 'success', 'found' => true, 'beatAware' => true, 'notes' => $notes, 'level' => $level]);
            }
        }

        // Phase 1: pseudo-random, lazily generated + cached.
        $chart = $this->charts->findOneForSong($song, $difficulty);
        $notes = $chart?->getNotesJson() !== null ? json_decode((string) $chart->getNotesJson(), true) : null;
        if ($chart !== null && \is_array($notes) && $notes !== []) {
            return new JsonResponse(['status' => 'success', 'found' => true, 'beatAware' => false, 'notes' => $notes, 'level' => $chart->getLevel()]);
        }

        $notes = $this->generator->generate($songId, $duration, $difficulty, 1.0);
        $level = $this->generator->level($notes, $duration, $difficulty, 1.0);
        $chart ??= new RhythmChart()->setSong($song)->setDifficulty($difficulty);
        $chart->setNotesJson(json_encode($notes) ?: null);
        $chart->setLevel($level);
        $this->em->persist($chart);
        $this->em->flush();

        return new JsonResponse(['status' => 'success', 'found' => true, 'beatAware' => false, 'notes' => $notes, 'level' => $level]);
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
            return new JsonResponse(['status' => 'error', 'message' => $this->translator->trans('error.csrf_invalid')], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['status' => 'error', 'message' => $this->translator->trans('error.rhythm.invalid_body')], Response::HTTP_BAD_REQUEST);
        }

        $rawHitDeltas = \is_array($data['hit_deltas'] ?? null) ? $data['hit_deltas'] : [];
        // Normalise the raw JSON payload into a clean list of floats (inspect()
        // treats each delta as a float anyway).
        $hitDeltas = array_values(array_map(static fn($delta): float => (float) $delta, $rawHitDeltas));
        $claimedScore = (int) ($data['score'] ?? 0);
        $maxCombo = (int) ($data['max_combo'] ?? 0);

        $banReason = $this->antiCheat->inspect($hitDeltas, $claimedScore, $maxCombo);
        if ($banReason !== null) {
            $reason = new TranslatableMessage('ui.rhythm.cheat_reason.' . $banReason);
            $newStrikes = abs($user->getRhythmStrikes()) + 1;
            $user->setRhythmStrikes($newStrikes);
            if ($newStrikes >= 3) {
                // 3rd strike: full account ban (legacy also destroyed the session).
                $user->setBanned(true);
                $this->em->flush();

                return new JsonResponse(
                    ['status' => 'error', 'message' => $this->translator->trans('error.rhythm.cheat_banned', ['reason' => $reason->trans($this->translator)])],
                    Response::HTTP_FORBIDDEN
                );
            }
            $this->em->flush();

            return new JsonResponse(
                ['status' => 'error', 'message' => $this->translator->trans('error.rhythm.cheat_strike', ['strike' => $newStrikes, 'reason' => $reason->trans($this->translator)])],
                Response::HTTP_FORBIDDEN
            );
        }

        $song = $this->music->find((int) ($data['song_id'] ?? 0));
        if ($song === null) {
            return new JsonResponse(['status' => 'error', 'message' => $this->translator->trans('error.rhythm.song_not_found')], Response::HTTP_NOT_FOUND);
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
            return new JsonResponse(['status' => 'error', 'message' => $this->translator->trans('error.csrf_invalid')], Response::HTTP_FORBIDDEN);
        }

        $song = $this->music->find($songId);
        if ($song === null) {
            return new JsonResponse(['status' => 'error', 'message' => $this->translator->trans('error.rhythm.song_not_found')], Response::HTTP_NOT_FOUND);
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
    public function favoriteSongs(Request $request, #[CurrentUser] ?User $user, UrlGeneratorInterface $urls): JsonResponse
    {
        // The artist-detail view lists ANOTHER player's rhythm favorites via
        // target_user_id; without it, the caller's own list is returned.
        $targetId = (int) $request->query->get('target_user_id', '0');
        if ($targetId > 0) {
            $user = $this->users->find($targetId);
        }
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
            $songId = $s->getId() ?? throw new \LogicException('Persisted song must have an id.');
            $out[] = [
                'id' => $songId,
                'title' => $s->getTitle(),
                'artist' => $s->getArtist(),
                'album' => $s->getAlbum(),
                'duration' => $s->getDuration(),
                'stream' => $urls->generate('app_stream', ['id' => $songId]),
                'cover' => $urls->generate('app_cover', ['id' => $songId]),
                'rg_favorite' => 1,
                'levels' => $levels[$songId] ?? null,
                // Usable = v3 grid carrying strength-ranked band onsets; older
                // formats report false so the client re-analyses (self-healing).
                'beatAware' => str_contains((string) $s->getBeatGridJson(), '"v":4'),
            ];
        }

        return new JsonResponse($out);
    }

    /**
     * Phase 2 v3: persist the client-side audio analysis for a song — bass/
     * mid/high onsets WITH strengths (the note anchors) plus the optional
     * tracked beat grid (de-jitter only). This is the only cached expensive
     * artifact — note charts are regenerated on the fly from it (see chart()).
     * Also precomputes the 6 tiny difficulty levels.
     */
    #[Route('/rhythm/beatgrid/{songId}', name: 'app_rhythm_beatgrid', methods: ['POST'], requirements: ['songId' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function saveBeatGrid(int $songId, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->headers->get('X-CSRF-TOKEN'))) {
            return new JsonResponse(['status' => 'error', 'message' => $this->translator->trans('error.csrf_invalid')], Response::HTTP_FORBIDDEN);
        }

        $song = $this->music->find($songId);
        if ($song === null) {
            return new JsonResponse(['status' => 'error', 'message' => $this->translator->trans('error.rhythm.song_not_found')], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['status' => 'error', 'message' => $this->translator->trans('error.rhythm.invalid_body')], Response::HTTP_BAD_REQUEST);
        }

        // Defensive normalisation, capped per band.
        $clampTimes = static function (mixed $arr): array {
            if (!\is_array($arr)) {
                return [];
            }
            $out = [];
            foreach ($arr as $t) {
                if (is_numeric($t) && (float) $t >= 0.0 && (float) $t <= 7200.0) {
                    $out[] = round((float) $t, 3);
                }
                if (\count($out) >= 20000) {
                    break;
                }
            }

            return $out;
        };
        // [t, strength] pairs (v3 band onsets).
        $clampPairs = static function (mixed $arr): array {
            if (!\is_array($arr)) {
                return [];
            }
            $out = [];
            foreach ($arr as $pair) {
                if (\is_array($pair) && isset($pair[0]) && is_numeric($pair[0])
                    && (float) $pair[0] >= 0.0 && (float) $pair[0] <= 7200.0) {
                    $s = isset($pair[1]) && is_numeric($pair[1]) ? max(0.0, min(1.0, (float) $pair[1])) : 0.6;
                    $out[] = [round((float) $pair[0], 3), round($s, 2)];
                }
                if (\count($out) >= 20000) {
                    break;
                }
            }

            return $out;
        };

        $bandsIn = \is_array($data['bands'] ?? null) ? $data['bands'] : [];
        $grid = [
            // v4 = attack-sharpness-weighted low band (client analyser); the
            // beatAware flags above check this exact version so older grids
            // self-heal by re-analysis.
            'v' => 4,
            'bpm' => isset($data['bpm']) && is_numeric($data['bpm']) ? (float) $data['bpm'] : null,
            'beats' => $clampTimes($data['beats'] ?? []),
            'bands' => [
                'low' => $clampPairs($bandsIn['low'] ?? []),
                'mid' => $clampPairs($bandsIn['mid'] ?? []),
                'high' => $clampPairs($bandsIn['high'] ?? []),
            ],
        ];
        // The low/mid onsets ARE the note anchors; without enough of them the
        // grid is useless (beats alone no longer suffice in the v3 model).
        if (\count($grid['bands']['low']) + \count($grid['bands']['mid']) < 8) {
            return new JsonResponse(['status' => 'error', 'message' => $this->translator->trans('error.rhythm.too_few_onsets')], Response::HTTP_BAD_REQUEST);
        }

        $song->setBeatGridJson(json_encode($grid) ?: null);
        $song->setBpm($grid['bpm']);
        $this->em->flush();

        // Cache only the 6 levels (a few ints); notes stay on-the-fly.
        $duration = $song->getDuration() ?: 180;
        foreach (self::VALID_DIFFICULTIES as $diff) {
            $notes = $this->generator->generateFromBeats($songId, $duration, $diff, $grid, 1.0);
            $level = $this->generator->level($notes, $duration, $diff, 1.0);
            $chart = $this->charts->findOneForSong($song, $diff) ?? new RhythmChart()->setSong($song)->setDifficulty($diff);
            $chart->setNotesJson(null);
            $chart->setLevel($level);
            $chart->setGeneratorVersion(3);
            $this->em->persist($chart);
        }
        $this->em->flush();

        return new JsonResponse(['status' => 'success']);
    }

    /**
     * A stored grid can drive Phase 2 generation when it carries enough v3
     * band onsets (the note anchors) or, for not-yet-reanalysed legacy grids,
     * a tracked beats pulse.
     *
     * @param array<string, mixed> $decoded
     */
    private static function gridUsable(array $decoded): bool
    {
        $bands = \is_array($decoded['bands'] ?? null) ? $decoded['bands'] : null;
        if ($bands !== null) {
            $low = \is_array($bands['low'] ?? null) ? \count($bands['low']) : 0;
            $mid = \is_array($bands['mid'] ?? null) ? \count($bands['mid']) : 0;

            return ($low + $mid) >= 8;
        }

        return \is_array($decoded['beats'] ?? null) && \count($decoded['beats']) >= 8;
    }
}
