<?php

namespace App\Controller;

use App\Entity\Music;
use App\Entity\Playlist;
use App\Entity\PlaylistCollaborator;
use App\Entity\PlaylistInvite;
use App\Entity\PlaylistSong;
use App\Entity\User;
use App\Repository\MixRepository;
use App\Repository\MusicRepository;
use App\Repository\PlaylistCollaboratorRepository;
use App\Repository\PlaylistInviteRepository;
use App\Repository\PlaylistRepository;
use App\Repository\PlaylistSongRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
class PlaylistController extends AbstractController
{
    public function __construct(
        private readonly PlaylistRepository $playlists,
        private readonly PlaylistSongRepository $playlistSongs,
        private readonly PlaylistCollaboratorRepository $collaborators,
        private readonly PlaylistInviteRepository $invites,
        private readonly MusicRepository $music,
        private readonly MixRepository $mixes,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/playlists', name: 'app_playlists')]
    public function index(#[CurrentUser] User $user): Response
    {
        return $this->render('playlist/index.html.twig', [
            'playlists' => $this->playlists->findForUser($user),
        ]);
    }

    #[Route('/playlists/collab', name: 'app_playlists_collab')]
    public function collabIndex(#[CurrentUser] User $user): Response
    {
        return $this->render('playlist/collab.html.twig', [
            'playlists' => $this->playlists->findCollabForUser($user),
        ]);
    }

    #[Route('/playlists/new', name: 'app_playlist_new', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $user): Response
    {
        $this->assertCsrf($request, 'playlist_new');
        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') {
            $this->addFlash('error', new TranslatableMessage('flash.playlist.name_required'));

            return $this->redirectToRoute('app_playlists');
        }

        $playlist = new Playlist();
        $playlist->setOwner($user);
        $playlist->setName($name);
        $playlist->setPublicId(bin2hex(random_bytes(8)));
        $playlist->setDescription(trim((string) $request->request->get('description', '')) ?: null);
        $playlist->setIsPrivate($request->request->getBoolean('is_private'));
        $this->playlists->save($playlist);

        return $this->redirectToRoute('app_playlist_show', ['id' => $playlist->getId()]);
    }

    #[Route('/playlists/copy', name: 'app_playlist_copy', methods: ['POST'])]
    public function copy(Request $request, #[CurrentUser] User $user): Response
    {
        $this->assertCsrf($request, 'playlist_copy');
        $publicId = trim((string) $request->request->get('public_id', ''));
        $source = $publicId !== '' ? $this->playlists->findOneByPublicId($publicId) : null;

        // Deviation from the legacy `copy_playlist` (which had no visibility
        // check at all): here we require the source to be non-private, since
        // there is no public playlist browsing page in this app's scope to
        // discover a public_id otherwise — this gives `isPrivate` an actual
        // purpose for copying. See PLAN_MIGRATION Feature 9.
        if ($source === null || $source->isPrivate()) {
            $this->addFlash('error', new TranslatableMessage('flash.playlist.not_found'));

            return $this->redirectToRoute('app_playlists');
        }
        if ($source->getOwner() === $user) {
            $this->addFlash('error', new TranslatableMessage('flash.playlist.cannot_copy_own'));

            return $this->redirectToRoute('app_playlist_show', ['id' => $source->getId()]);
        }

        $copy = new Playlist();
        $copy->setOwner($user);
        $copy->setName('Copy of ' . $source->getName());
        $copy->setPublicId(bin2hex(random_bytes(8)));

        foreach ($this->playlistSongs->findForPlaylist($source) as $item) {
            $newItem = new PlaylistSong();
            $newItem->setSong($item->getSong());
            $newItem->setSortOrder($item->getSortOrder());
            $copy->addItem($newItem);
        }

        $this->playlists->save($copy);
        $this->addFlash('success', new TranslatableMessage('flash.playlist.copied'));

        return $this->redirectToRoute('app_playlist_show', ['id' => $copy->getId()]);
    }

    #[Route('/playlists/import', name: 'app_playlist_import', methods: ['POST'])]
    public function import(Request $request, #[CurrentUser] User $user): Response
    {
        $this->assertCsrf($request, 'playlist_import');

        $file = $request->files->get('import_file');
        if ($file === null) {
            $this->addFlash('error', new TranslatableMessage('flash.playlist.import_no_file'));

            return $this->redirectToRoute('app_playlists');
        }

        $content = (string) file_get_contents($file->getPathname());
        $data = json_decode($content, true);
        if (!\is_array($data) || !isset($data['name'], $data['songs']) || !\is_array($data['songs'])) {
            $this->addFlash('error', new TranslatableMessage('flash.playlist.import_invalid_json'));

            return $this->redirectToRoute('app_playlists');
        }

        $playlist = new Playlist();
        $playlist->setOwner($user);
        $playlist->setName((string) $data['name']);
        $playlist->setPublicId(bin2hex(random_bytes(8)));

        $order = 0;
        $songCount = 0;
        $addedSongIds = [];
        foreach ($data['songs'] as $entry) {
            $song = $this->music->matchImportedSong(\is_array($entry) ? $entry : ['filename' => (string) $entry]);
            if ($song === null || \in_array($song->getId(), $addedSongIds, true)) {
                continue;
            }

            $addedSongIds[] = $song->getId();
            $item = new PlaylistSong();
            $item->setSong($song);
            $item->setSortOrder($order++);
            $playlist->addItem($item);
            ++$songCount;
        }

        $this->playlists->save($playlist);
        $this->addFlash('success', new TranslatableMessage('flash.playlist.imported', ['name' => $playlist->getName(), 'count' => $songCount]));

        return $this->redirectToRoute('app_playlist_show', ['id' => $playlist->getId()]);
    }

    #[Route('/playlists/shared/{publicId}', name: 'app_playlist_shared', requirements: ['publicId' => '[0-9a-f]+'])]
    public function shared(string $publicId, #[CurrentUser] User $user): Response
    {
        $playlist = $this->playlists->findOneByPublicId($publicId);
        if ($playlist === null) {
            throw $this->createNotFoundException('Playlist not found.');
        }
        if ($playlist->getOwner() === $user) {
            return $this->redirectToRoute('app_playlist_show', ['id' => $playlist->getId()]);
        }
        if ($playlist->isPrivate() && !$user->isSuperAdmin() && !$this->collaborators->isCollaborator($playlist, $user)) {
            throw $this->createNotFoundException('Playlist not found.');
        }

        return $this->render('playlist/shared.html.twig', [
            'playlist' => $playlist,
        ]);
    }

    #[Route('/playlists/join/{token}', name: 'app_playlist_join_info', methods: ['GET'])]
    public function joinInfo(string $token): Response
    {
        $invite = $this->invites->findValidByToken($token);
        if ($invite === null) {
            throw $this->createNotFoundException('Invalid or expired invite link.');
        }

        return $this->render('playlist/join.html.twig', [
            'invite' => $invite,
            'playlist' => $invite->getPlaylist(),
        ]);
    }

    #[Route('/playlists/join/{token}', name: 'app_playlist_join', methods: ['POST'])]
    public function join(string $token, Request $request, #[CurrentUser] User $user): Response
    {
        $this->assertCsrf($request, 'playlist_join_' . $token);

        $invite = $this->invites->findValidByToken($token);
        if ($invite === null) {
            $this->addFlash('error', new TranslatableMessage('flash.playlist.invite_invalid'));

            return $this->redirectToRoute('app_playlists');
        }

        $playlist = $invite->getPlaylist();
        if ($playlist === null) {
            throw $this->createNotFoundException();
        }
        if (!$playlist->isCollaborative()) {
            $this->addFlash('error', new TranslatableMessage('flash.playlist.not_collaborative'));

            return $this->redirectToRoute('app_playlists');
        }
        if ($playlist->getOwner() === $user) {
            $this->addFlash('error', new TranslatableMessage('flash.playlist.already_owner'));

            return $this->redirectToRoute('app_playlist_show', ['id' => $playlist->getId()]);
        }

        if (!$this->collaborators->isCollaborator($playlist, $user)) {
            $collaborator = new PlaylistCollaborator();
            $collaborator->setPlaylist($playlist);
            $collaborator->setUser($user);
            $this->em->persist($collaborator);
            $this->em->flush();
        }

        $this->addFlash('success', new TranslatableMessage('flash.playlist.joined'));

        return $this->redirectToRoute('app_playlist_show', ['id' => $playlist->getId()]);
    }

    #[Route('/playlists/{id}', name: 'app_playlist_show', requirements: ['id' => '\d+'])]
    public function show(Playlist $playlist, #[CurrentUser] User $user): Response
    {
        $this->assertCanView($playlist, $user);

        $isOwner = $this->isOwner($playlist, $user);

        return $this->render('playlist/show.html.twig', [
            'playlist' => $playlist,
            'isOwner' => $isOwner,
            'canEdit' => $isOwner || $this->collaborators->isCollaborator($playlist, $user),
            'isCollaborator' => !$isOwner && $this->collaborators->isCollaborator($playlist, $user),
            'mixes' => $this->mixes->findBy([], ['id' => 'DESC'], 20),
        ]);
    }

    #[Route('/playlists/{id}/edit', name: 'app_playlist_edit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function edit(Playlist $playlist, Request $request, #[CurrentUser] User $user): Response
    {
        $this->assertOwner($playlist, $user);
        $this->assertCsrf($request, 'playlist_edit_' . $playlist->getId());

        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') {
            $this->addFlash('error', new TranslatableMessage('flash.playlist.name_empty'));

            return $this->redirectToRoute('app_playlist_show', ['id' => $playlist->getId()]);
        }

        $isPrivate = $request->request->getBoolean('is_private');
        $playlist->setName($name);
        $playlist->setDescription(trim((string) $request->request->get('description', '')) ?: null);
        $playlist->setIsPrivate($isPrivate);
        if ($isPrivate) {
            // Mirrors the legacy `is_collaborative = CASE WHEN ? = 1 THEN 0 ELSE is_collaborative END`
            // plus its follow-up cleanup: switching to private also wipes collaborators.
            $playlist->setIsCollaborative(false);
            $this->collaborators->removeAllForPlaylist($playlist);
        }
        $this->em->flush();

        $this->addFlash('success', new TranslatableMessage('flash.playlist.updated'));

        return $this->redirectToRoute('app_playlist_show', ['id' => $playlist->getId()]);
    }

    #[Route('/playlists/{id}/toggle-collaborative', name: 'app_playlist_toggle_collaborative', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggleCollaborative(Playlist $playlist, Request $request, #[CurrentUser] User $user): Response
    {
        $this->assertOwner($playlist, $user);
        $this->assertCsrf($request, 'playlist_collaborative_' . $playlist->getId());

        $newValue = !$playlist->isCollaborative();
        $playlist->setIsCollaborative($newValue);
        if (!$newValue) {
            $this->collaborators->removeAllForPlaylist($playlist);
        }
        $this->em->flush();

        $this->addFlash('success', new TranslatableMessage($newValue ? 'flash.playlist.now_collaborative' : 'flash.playlist.now_private'));

        return $this->redirectToRoute('app_playlist_show', ['id' => $playlist->getId()]);
    }

    #[Route('/playlists/{id}/delete', name: 'app_playlist_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Playlist $playlist, Request $request, #[CurrentUser] User $user): Response
    {
        $this->assertOwner($playlist, $user);
        $this->assertCsrf($request, 'playlist_delete_' . $playlist->getId());
        $this->playlists->remove($playlist);

        return $this->redirectToRoute('app_playlists');
    }

    #[Route('/playlists/{id}/add/{songId}', name: 'app_playlist_add', methods: ['POST'], requirements: ['id' => '\d+', 'songId' => '\d+'])]
    public function addSong(Playlist $playlist, int $songId, Request $request, #[CurrentUser] User $user): Response
    {
        $this->assertCanEdit($playlist, $user);
        $this->assertCsrf($request, 'playlist_item_' . $songId);

        $song = $this->music->find($songId);
        if ($song === null) {
            if ($this->wantsJson($request)) {
                return new JsonResponse(['error' => $this->translator->trans('error.track_not_found')], Response::HTTP_NOT_FOUND);
            }
            throw $this->createNotFoundException('Track not found.');
        }

        $added = $this->doAddSong($playlist, $song, $user);

        if ($this->wantsJson($request)) {
            return new JsonResponse(['added' => $added]);
        }

        $this->addFlash('success', new TranslatableMessage($added ? 'flash.playlist.track_added' : 'flash.playlist.track_already_in'));

        return $this->redirectToRoute('app_playlist_show', ['id' => $playlist->getId()]);
    }

    /**
     * Same action as {@see self::addSong()}, but for the "add a track by
     * typing its id" form on the playlist page: the target song id is not
     * known when the page is rendered, so it cannot be baked into the route
     * path (which would need a matching per-id CSRF token pre-rendered in
     * Twig — see PLAN_MIGRATION §4bis on never mixing JS and Twig for this
     * exact reason). The CSRF token here is scoped to the playlist, not the
     * song.
     */
    #[Route('/playlists/{id}/add-track', name: 'app_playlist_add_by_id', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addSongById(Playlist $playlist, Request $request, #[CurrentUser] User $user): Response
    {
        $this->assertCanEdit($playlist, $user);
        $this->assertCsrf($request, 'playlist_add_track_' . $playlist->getId());

        $songId = (int) $request->request->get('songId', 0);
        $song = $songId > 0 ? $this->music->find($songId) : null;
        if ($song === null) {
            $this->addFlash('error', new TranslatableMessage('flash.playlist.track_not_found'));

            return $this->redirectToRoute('app_playlist_show', ['id' => $playlist->getId()]);
        }

        $added = $this->doAddSong($playlist, $song, $user);
        $this->addFlash('success', new TranslatableMessage($added ? 'flash.playlist.track_added' : 'flash.playlist.track_already_in'));

        return $this->redirectToRoute('app_playlist_show', ['id' => $playlist->getId()]);
    }

    /**
     * "Add all to playlist" of the album, artist, genre and year pages: every
     * track of that page (visible to the user) into one of their playlists,
     * or into a new private one named after the page ("new"). Tracks already
     * in the playlist are skipped.
     */
    #[Route('/playlists/add-all', name: 'app_playlist_add_all', methods: ['POST'])]
    public function addAll(Request $request, #[CurrentUser] User $user, #[Autowire('%app.music_dir%')] string $musicDir): Response
    {
        $this->assertCsrf($request, 'playlist_add_all');
        $kind = (string) $request->request->get('kind', '');
        $value = trim((string) $request->request->get('value', ''));
        $songs = match ($kind) {
            'album' => $this->music->findByAlbum($value, $user),
            'artist' => $this->music->findByArtist($value, $user),
            'genre' => $this->music->findByGenre($value, $user),
            'year' => ctype_digit($value) ? $this->music->findByYear((int) $value, $user) : [],
            'folder' => \in_array($value, ['unclassified', 'discover'], true) ? $this->music->findInFolder(rtrim($musicDir, '/') . '/_' . $value, $user) : [],
            'rating' => preg_match('/^[0-5]$/', $value) === 1 ? $this->music->findByRating((int) $value, $user) : [],
            default => [],
        };
        $back = $this->backTo($request);
        if ($songs === []) {
            $this->addFlash('error', new TranslatableMessage('flash.playlist.no_track_to_add'));

            return $back;
        }

        $target = (string) $request->request->get('playlist', '');
        if ($target === 'new') {
            $playlist = new Playlist();
            $playlist->setOwner($user);
            $playlist->setName(mb_substr(trim((string) $request->request->get('name', '')) ?: $value, 0, 255));
            $playlist->setPublicId(bin2hex(random_bytes(8)));
            $playlist->setIsPrivate(true);
            $this->playlists->save($playlist);
        } else {
            $playlist = ctype_digit($target) ? $this->playlists->find((int) $target) : null;
            if ($playlist === null) {
                $this->addFlash('error', new TranslatableMessage('flash.playlist.not_found'));

                return $back;
            }
            $this->assertCanEdit($playlist, $user);
        }

        $present = [];
        foreach ($playlist->getItems() as $item) {
            $present[$item->getSong()?->getId()] = true;
        }
        $order = $this->playlistSongs->nextSortOrder($playlist);
        $added = 0;
        foreach ($songs as $song) {
            if (isset($present[$song->getId()])) {
                continue;
            }
            $present[$song->getId()] = true;
            $item = new PlaylistSong();
            $item->setSong($song);
            $item->setAddedBy($user);
            $item->setSortOrder($order++);
            $playlist->addItem($item);
            $this->em->persist($item);
            ++$added;
        }
        $this->em->flush();

        $skipped = \count($songs) - $added;
        $this->addFlash('success', new TranslatableMessage('flash.playlist.bulk_added', ['added' => $added, 'playlist' => $playlist->getName(), 'skipped' => $skipped]));

        return $back;
    }

    /** Back to the page the form was sent from (same site only), else the playlists. */
    private function backTo(Request $request): Response
    {
        $referer = (string) $request->headers->get('referer', '');
        if ($referer !== '' && parse_url($referer, \PHP_URL_HOST) === $request->getHost()) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('app_playlists');
    }

    /**
     * @return bool Whether the track was newly added (false if it was already there).
     */
    private function doAddSong(Playlist $playlist, Music $song, User $user): bool
    {
        foreach ($playlist->getItems() as $item) {
            if ($item->getSong() === $song) {
                return false;
            }
        }

        $item = new PlaylistSong();
        $item->setSong($song);
        $item->setAddedBy($user);
        $item->setSortOrder($this->playlistSongs->nextSortOrder($playlist));
        $playlist->addItem($item);
        $this->em->persist($item);
        $this->em->flush();

        return true;
    }

    #[Route('/playlists/{id}/remove/{songId}', name: 'app_playlist_remove', methods: ['POST'], requirements: ['id' => '\d+', 'songId' => '\d+'])]
    public function removeSong(Playlist $playlist, int $songId, Request $request, #[CurrentUser] User $user): Response
    {
        $this->assertCanEdit($playlist, $user);
        $this->assertCsrf($request, 'playlist_item_' . $songId);

        foreach ($playlist->getItems() as $item) {
            $song = $item->getSong();
            if ($song !== null && $song->getId() === $songId) {
                $playlist->removeItem($item);
                $this->em->remove($item);
                $this->em->flush();
                break;
            }
        }

        if ($this->wantsJson($request)) {
            return new JsonResponse(['removed' => true]);
        }

        return $this->redirectToRoute('app_playlist_show', ['id' => $playlist->getId()]);
    }

    #[Route('/playlists/{id}/move/{songId}', name: 'app_playlist_move', methods: ['POST'], requirements: ['id' => '\d+', 'songId' => '\d+'])]
    public function move(Playlist $playlist, int $songId, Request $request, #[CurrentUser] User $user): Response
    {
        // Reordering stays owner-only, matching the legacy `update_playlist_order`
        // permission check (owner or super-admin, not open to collaborators).
        $this->assertOwner($playlist, $user);
        $this->assertCsrf($request, 'playlist_order_' . $playlist->getId());

        $direction = (string) $request->request->get('direction', '');
        $this->playlistSongs->move($playlist, $songId, $direction === 'up' ? -1 : 1);

        return $this->redirectToRoute('app_playlist_show', ['id' => $playlist->getId()]);
    }

    #[Route('/playlists/{id}/mixes/add', name: 'app_playlist_add_mix', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addMix(Playlist $playlist, Request $request, #[CurrentUser] User $user): Response
    {
        $this->assertCanEdit($playlist, $user);
        $this->assertCsrf($request, 'playlist_add_mix_' . $playlist->getId());

        $mixPublicId = trim((string) $request->request->get('mix_id', ''));
        $mix = $mixPublicId !== '' ? $this->mixes->findOneByPublicId($mixPublicId) : null;
        if ($mix === null) {
            $this->addFlash('error', new TranslatableMessage('flash.playlist.mix_not_found'));

            return $this->redirectToRoute('app_playlist_show', ['id' => $playlist->getId()]);
        }

        $existingSongIds = [];
        foreach ($playlist->getItems() as $item) {
            $existingSong = $item->getSong();
            if ($existingSong !== null) {
                $existingSongIds[] = $existingSong->getId();
            }
        }

        $order = $this->playlistSongs->nextSortOrder($playlist);
        $added = 0;
        foreach ($mix->getSongs() as $mixSong) {
            $song = $mixSong->getSong();
            if ($song === null || \in_array($song->getId(), $existingSongIds, true)) {
                continue;
            }

            $item = new PlaylistSong();
            $item->setSong($song);
            $item->setAddedBy($user);
            $item->setSortOrder($order++);
            $playlist->addItem($item);
            $this->em->persist($item);
            $existingSongIds[] = $song->getId();
            ++$added;
        }
        $this->em->flush();

        $this->addFlash('success', new TranslatableMessage('flash.playlist.mix_added', ['count' => $added]));

        return $this->redirectToRoute('app_playlist_show', ['id' => $playlist->getId()]);
    }

    #[Route('/playlists/{id}/export', name: 'app_playlist_export', requirements: ['id' => '\d+'])]
    public function export(Playlist $playlist, #[CurrentUser] User $user): Response
    {
        $this->assertOwner($playlist, $user);

        $songs = [];
        foreach ($this->playlistSongs->findForPlaylist($playlist) as $item) {
            $song = $item->getSong();
            if ($song === null || !$this->music->isVisibleTo($song, $user)) {
                continue;
            }
            $songs[] = [
                'title' => $song->getTitle(),
                'artist' => $song->getArtist(),
                'album' => $song->getAlbum(),
                'filename' => basename(str_replace('\\', '/', (string) $song->getFile())),
            ];
        }

        $response = new JsonResponse(['name' => $playlist->getName(), 'songs' => $songs]);
        $response->setEncodingOptions(\JSON_PRETTY_PRINT);
        $filename = preg_replace('/[^A-Za-z0-9 _-]/', '_', $playlist->getName() ?? '') ?? 'playlist';
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '.json"');

        return $response;
    }

    #[Route('/playlists/{id}/collaborators', name: 'app_playlist_collaborators', requirements: ['id' => '\d+'])]
    public function collaborators(Playlist $playlist, #[CurrentUser] User $user): Response
    {
        $this->assertOwner($playlist, $user);

        return $this->render('playlist/collaborators.html.twig', [
            'playlist' => $playlist,
            'collaborators' => $this->collaborators->findForPlaylist($playlist),
        ]);
    }

    #[Route('/playlists/{id}/collaborators/add', name: 'app_playlist_collaborator_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addCollaborator(Playlist $playlist, Request $request, #[CurrentUser] User $user): Response
    {
        $this->assertOwner($playlist, $user);
        $this->assertCsrf($request, 'playlist_collaborators_' . $playlist->getId());

        $target = trim((string) $request->request->get('target', ''));
        if ($target === '') {
            $this->addFlash('error', new TranslatableMessage('flash.playlist.collab_identifier_required'));

            return $this->redirectToRoute('app_playlist_collaborators', ['id' => $playlist->getId()]);
        }

        $targetUser = $this->users->findOneByEmailOrArtistName($target);
        if ($targetUser === null) {
            $this->addFlash('error', new TranslatableMessage('flash.playlist.user_not_found'));
        } elseif ($targetUser === $user) {
            $this->addFlash('error', new TranslatableMessage('flash.playlist.already_own'));
        } else {
            if (!$this->collaborators->isCollaborator($playlist, $targetUser)) {
                $collaborator = new PlaylistCollaborator();
                $collaborator->setPlaylist($playlist);
                $collaborator->setUser($targetUser);
                $this->em->persist($collaborator);
                $this->em->flush();
            }
            $this->addFlash('success', new TranslatableMessage('flash.playlist.collaborator_added'));
        }

        return $this->redirectToRoute('app_playlist_collaborators', ['id' => $playlist->getId()]);
    }

    #[Route('/playlists/{id}/collaborators/{userId}/remove', name: 'app_playlist_collaborator_remove', methods: ['POST'], requirements: ['id' => '\d+', 'userId' => '\d+'])]
    public function removeCollaborator(Playlist $playlist, int $userId, Request $request, #[CurrentUser] User $user): Response
    {
        $this->assertOwner($playlist, $user);
        $this->assertCsrf($request, 'playlist_collaborators_' . $playlist->getId());

        $this->collaborators->removeByPlaylistAndUserId($playlist, $userId);
        $this->addFlash('success', new TranslatableMessage('flash.playlist.collaborator_removed'));

        return $this->redirectToRoute('app_playlist_collaborators', ['id' => $playlist->getId()]);
    }

    #[Route('/playlists/{id}/collaborators/invite', name: 'app_playlist_invite_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function createInvite(Playlist $playlist, Request $request, #[CurrentUser] User $user): Response
    {
        $this->assertOwner($playlist, $user);
        $this->assertCsrf($request, 'playlist_collaborators_' . $playlist->getId());

        $this->invites->purgeExpired();

        $expireMinutes = $request->request->get('expire');
        $expiresAt = null;
        if ($expireMinutes !== null && $expireMinutes !== '' && is_numeric($expireMinutes)) {
            $expiresAt = new \DateTimeImmutable()->modify('+' . (int) $expireMinutes . ' minutes');
        }

        $invite = new PlaylistInvite();
        $invite->setToken(bin2hex(random_bytes(16)));
        $invite->setPlaylist($playlist);
        $invite->setExpiresAt($expiresAt);
        $this->em->persist($invite);
        $this->em->flush();

        $url = $this->generateUrl('app_playlist_join_info', ['token' => $invite->getToken()], UrlGeneratorInterface::ABSOLUTE_URL);
        $this->addFlash('success', new TranslatableMessage('flash.playlist.invite_created', ['url' => $url]));

        return $this->redirectToRoute('app_playlist_collaborators', ['id' => $playlist->getId()]);
    }

    #[Route('/playlists/{id}/leave', name: 'app_playlist_leave', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function leave(Playlist $playlist, Request $request, #[CurrentUser] User $user): Response
    {
        $this->assertCsrf($request, 'playlist_leave_' . $playlist->getId());
        $this->collaborators->removeByPlaylistAndUserId($playlist, (int) $user->getId());

        $this->addFlash('success', new TranslatableMessage('flash.playlist.left'));

        return $this->redirectToRoute('app_playlists_collab');
    }

    private function isOwner(Playlist $playlist, User $user): bool
    {
        return $playlist->getOwner() === $user || $user->isSuperAdmin();
    }

    private function assertOwner(Playlist $playlist, User $user): void
    {
        if (!$this->isOwner($playlist, $user)) {
            throw $this->createAccessDeniedException('Only the owner can perform this action.');
        }
    }

    private function assertCanEdit(Playlist $playlist, User $user): void
    {
        if ($this->isOwner($playlist, $user) || $this->collaborators->isCollaborator($playlist, $user)) {
            return;
        }

        throw $this->createAccessDeniedException('Not your playlist.');
    }

    private function assertCanView(Playlist $playlist, User $user): void
    {
        $this->assertCanEdit($playlist, $user);
    }

    private function assertCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function wantsJson(Request $request): bool
    {
        return $request->headers->get('X-Requested-With') === 'fetch'
            || str_contains((string) $request->headers->get('Accept'), 'application/json');
    }
}
