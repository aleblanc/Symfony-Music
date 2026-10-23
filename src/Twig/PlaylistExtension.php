<?php

namespace App\Twig;

use App\Entity\Playlist;
use App\Entity\User;
use App\Repository\PlaylistRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Attribute\AsTwigFunction;

/**
 * The playlists the current user can add tracks to (own + collaborations),
 * for the "Add all to playlist" menu of the album / artist / genre / year pages.
 */
class PlaylistExtension
{
    public function __construct(
        private readonly PlaylistRepository $playlists,
        private readonly Security $security,
    ) {}

    /** @return list<Playlist> own first (newest first), then collaborations (by name) */
    #[AsTwigFunction('editable_playlists')]
    public function editablePlaylists(): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return [];
        }

        return [...$this->playlists->findForUser($user), ...$this->playlists->findCollabForUser($user)];
    }
}
