<?php

namespace App\Controller;

use App\Entity\ArtistProfile;
use App\Entity\CityGeo;
use App\Entity\User;
use App\Repository\ArtistProfileRepository;
use App\Repository\CityGeoRepository;
use App\Repository\MusicRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Upcoming concerts of the library artists with at least 2 tracks rated 5
 * stars (app:cron:artist-concerts, from Songkick), soonest first, optionally of one country only; "new" ones were
 * announced since the last visit of the artist's page. Viewing this list
 * marks nothing as seen. ?view=map shows the same concerts on an OpenStreetMap
 * map, at their city (app:cron:concert-geocode).
 */
class ConcertsController extends AbstractController
{
    #[Route('/concerts', name: 'app_concerts', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] ?User $user, ArtistProfileRepository $profiles, MusicRepository $music, CityGeoRepository $cities, ClockInterface $clock): Response
    {
        $now = $clock->now();
        $today = $now->format('Y-m-d');
        $onlyNew = $request->query->getBoolean('new');
        $country = strtoupper((string) $request->query->get('country', ''));
        $country = preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null;
        $map = $request->query->get('view') === 'map';
        $coordinates = $map ? $cities->coordinates() : [];
        // Only the artists with enough 5-star tracks this viewer can see in the library.
        $visible = array_flip($music->artistsWithRating(5, ArtistProfile::CONCERT_MIN_FIVE_STARS, $user));

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
                $place = CityGeo::place($concert);
                $concerts[] = $concert + ['artist' => $profile->getName(), 'new' => $isNew, 'geo' => $place !== null ? $coordinates[$place] ?? null : null];
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
            'map' => $map,
        ]);
    }
}
