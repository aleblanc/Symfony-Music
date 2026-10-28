<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\ArtistProfileRepository;
use App\Repository\MusicRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Upcoming concerts of every library artist (app:artist:concerts, from
 * Songkick), soonest first, optionally of one country only; "new" ones were
 * announced since the last visit of the artist's page. Viewing this list
 * marks nothing as seen.
 */
class ConcertsController extends AbstractController
{
    #[Route('/concerts', name: 'app_concerts', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] ?User $user, ArtistProfileRepository $profiles, MusicRepository $music, ClockInterface $clock): Response
    {
        $now = $clock->now();
        $today = $now->format('Y-m-d');
        $onlyNew = $request->query->getBoolean('new');
        $country = strtoupper((string) $request->query->get('country', ''));
        $country = preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null;
        // Only the artists this viewer can see in the library.
        $visible = array_flip(array_map(static fn (array $a): string => (string) $a['artist'], $music->listArtists($user)));

        $concerts = [];
        $countries = [];
        $newCount = 0;
        foreach ($profiles->findAll() as $profile) {
            if (!isset($visible[$profile->getName()]) || $profile->getConcerts() === []) {
                continue;
            }
            $new = array_flip(array_column($profile->newConcerts($now), 'id'));
            foreach ($profile->getConcerts() as $concert) {
                if ($concert['date'] < $today) {
                    continue;
                }
                if ($concert['countryCode'] !== null) {
                    $countries[$concert['countryCode']] = ($countries[$concert['countryCode']] ?? 0) + 1;
                }
                $isNew = isset($new[$concert['id']]);
                $newCount += (int) $isNew;
                if (($onlyNew && !$isNew) || ($country !== null && $concert['countryCode'] !== $country)) {
                    continue;
                }
                $concerts[] = $concert + ['artist' => $profile->getName(), 'new' => $isNew];
            }
        }
        usort($concerts, static fn (array $a, array $b): int => strcmp($a['date'] . ($a['time'] ?? ''), $b['date'] . ($b['time'] ?? '')) ?: strcasecmp($a['artist'], $b['artist']));
        arsort($countries);

        return $this->render('concerts/index.html.twig', [
            'concerts' => $concerts,
            'countries' => $countries,
            'country' => $country,
            'newCount' => $newCount,
            'onlyNew' => $onlyNew,
        ]);
    }
}
