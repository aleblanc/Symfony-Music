<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\UserSongSetting;
use App\Repository\MusicRepository;
use App\Repository\UserSongSettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Per-user, per-track audio preferences (volume + 5-band equalizer). Ported
 * from the legacy `save_song_settings` / `reset_song_settings` actions
 * (`get_song_data` already exposes the current values via {@see SongController}).
 */
#[IsGranted('ROLE_USER')]
class SongSettingsController extends AbstractController
{
    public function __construct(
        private readonly UserSongSettingRepository $settings,
        private readonly MusicRepository $music,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/song/{id}/settings', name: 'app_song_settings_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $song = $this->music->find($id);
        if ($song === null) {
            return new JsonResponse(['error' => 'Track not found'], Response::HTTP_NOT_FOUND);
        }

        $setting = $this->settings->findOneForUser($user, $song);

        return new JsonResponse([
            'song_id' => $song->getId(),
            'volume_multiplier' => $setting?->getVolumeMultiplier() ?? 1.0,
            'eq_bands' => $setting?->getEqBandsArray(),
        ]);
    }

    #[Route('/song/{id}/settings', name: 'app_song_settings_save', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function save(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $song = $this->music->find($id);
        if ($song === null) {
            return new JsonResponse(['error' => 'Track not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isCsrfTokenValid('song_settings_' . $song->getId(), $this->extractToken($request))) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        $volume = (float) ($data['volume'] ?? 1.0);
        $eq = $data['eq'] ?? null;
        $eqJson = is_array($eq) ? json_encode(array_map('floatval', $eq)) : null;

        $setting = $this->settings->findOneForUser($user, $song);
        if ($setting === null) {
            $setting = new UserSongSetting();
            $setting->setUser($user);
            $setting->setSong($song);
            $this->em->persist($setting);
        }
        $setting->setVolumeMultiplier($volume);
        $setting->setEqBands($eqJson);

        $this->em->flush();

        return new JsonResponse(['status' => 'success']);
    }

    #[Route('/song/{id}/settings/reset', name: 'app_song_settings_reset', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reset(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $song = $this->music->find($id);
        if ($song === null) {
            return new JsonResponse(['error' => 'Track not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isCsrfTokenValid('song_settings_' . $song->getId(), $this->extractToken($request))) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $setting = $this->settings->findOneForUser($user, $song);
        if ($setting !== null) {
            $this->em->remove($setting);
            $this->em->flush();
        }

        return new JsonResponse(['status' => 'success']);
    }

    /**
     * JSON API requests carry the CSRF token in the X-CSRF-TOKEN header
     * (there is no form body to place a hidden `_token` field into).
     */
    private function extractToken(Request $request): string
    {
        return (string) $request->headers->get('X-CSRF-TOKEN', '');
    }
}
