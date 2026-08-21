<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/profile')]
class ProfileController extends AbstractController
{
    #[Route('', name: 'app_profile_show', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function show(#[CurrentUser] User $user): Response
    {
        return $this->render('profile/show.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/edit', name: 'app_profile_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function edit(
        #[CurrentUser] User $user,
        Request $request,
        EntityManagerInterface $em,
        UserRepository $userRepo
    ): Response {
        if ($request->isMethod('POST')) {
            $token = $request->request->get('_token');
            if (!$token) {
                // Fallback: get CSRF token from request attributes
                $token = $request->attributes->get('_csrf_token');
            }
            $this->validateCsrfToken('profile_edit', $token);

            $newName = trim($request->request->get('name', ''));
            $newBio = trim($request->request->get('bio', ''));

            if (empty($newName)) {
                $this->addFlash('error', 'Name cannot be empty.');
                return $this->redirectToRoute('app_profile_edit');
            }

            // Check if artist name is already taken
            $existingUser = $userRepo->findOneBy(['artist' => $newName]);
            if ($existingUser && $existingUser->getId() !== $user->getId()) {
                $this->addFlash('error', 'Display name already taken.');
                return $this->redirectToRoute('app_profile_edit');
            }

            $user->setArtist($newName);
            $user->setBio($newBio);
            $em->flush();

            $this->addFlash('success', 'Profile updated successfully.');
            return $this->redirectToRoute('app_profile_show');
        }

        return $this->render('profile/edit.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/change-password', name: 'app_profile_change_password', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function changePassword(
        #[CurrentUser] User $user,
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher
    ): Response {
        if ($request->isMethod('POST')) {
            $this->validateCsrfToken('change_password', $request->request->get('_token'));

            $newPassword = $request->request->get('new_password', '');

            if (strlen($newPassword) < 6) {
                $this->addFlash('error', 'Password must be at least 6 characters.');
                return $this->redirectToRoute('app_profile_change_password');
            }

            $hashedPassword = $hasher->hashPassword($user, $newPassword);
            $user->setPasswordHash($hashedPassword);
            $em->flush();

            $this->addFlash('success', 'Password changed successfully.');
            return $this->redirectToRoute('app_profile_show');
        }

        return $this->render('profile/change_password.html.twig');
    }

    #[Route('/picture', name: 'app_profile_upload_picture', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function uploadPicture(
        #[CurrentUser] User $user,
        Request $request,
        EntityManagerInterface $em
    ): JsonResponse {
        $this->validateCsrfToken('upload_picture', $request->request->get('_token'));

        $file = $request->files->get('profile_picture');
        if (!$file) {
            return $this->json(['status' => 'error', 'message' => 'No file uploaded.'], Response::HTTP_BAD_REQUEST);
        }

        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        if (!in_array($file->getMimeType(), $allowedTypes)) {
            return $this->json(['status' => 'error', 'message' => 'Invalid file type. Only JPG, PNG, GIF allowed.'], Response::HTTP_BAD_REQUEST);
        }

        $imageData = file_get_contents($file->getPathname());
        $webpData = $this->processImageToWebp($imageData, 200, 80);

        if (!$webpData) {
            return $this->json(['status' => 'error', 'message' => 'Failed to process image.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $user->setProfilePicture($webpData);
        $user->setProfilePictureType('image/webp');
        $em->flush();

        return $this->json(['status' => 'success', 'message' => 'Profile picture updated.']);
    }

    #[Route('/background', name: 'app_profile_upload_background', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function uploadBackground(
        #[CurrentUser] User $user,
        Request $request,
        EntityManagerInterface $em
    ): JsonResponse {
        $this->validateCsrfToken('upload_background', $request->request->get('_token'));

        $file = $request->files->get('profile_background');
        if (!$file) {
            return $this->json(['status' => 'error', 'message' => 'No file uploaded.'], Response::HTTP_BAD_REQUEST);
        }

        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file->getMimeType(), $allowedTypes)) {
            return $this->json(['status' => 'error', 'message' => 'Invalid file type.'], Response::HTTP_BAD_REQUEST);
        }

        $imageData = file_get_contents($file->getPathname());
        $webpData = $this->processImageToWebp($imageData, 1200, 400, true);

        if (!$webpData) {
            return $this->json(['status' => 'error', 'message' => 'Failed to process image.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $user->setProfileBackground($webpData);
        $user->setProfileBackgroundType('image/webp');
        $em->flush();

        return $this->json(['status' => 'success', 'message' => 'Background updated.']);
    }

    #[Route('/picture/{id}', name: 'app_profile_get_picture', methods: ['GET'])]
    public function getPicture(int $id, UserRepository $userRepo): Response
    {
        $user = $userRepo->find($id);
        if (!$user) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        if ($user->getProfilePicture()) {
            return new Response(
                $user->getProfilePicture(),
                Response::HTTP_OK,
                [
                    'Content-Type' => $user->getProfilePictureType() ?? 'image/webp',
                    'Cache-Control' => 'public, max-age=31536000, immutable',
                ]
            );
        }

        // Fallback: generate SVG avatar with artist initial
        $artistName = $user->getArtist() ?? 'Unknown';
        $initial = mb_strtoupper(mb_substr($artistName, 0, 1, 'UTF-8'));
        $bgColor = $this->generateColorFromString($artistName);

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200">'
            . '<rect width="200" height="200" fill="%s"/>'
            . '<text x="50%%" y="50%%" dominant-baseline="central" text-anchor="middle" '
            . 'font-family="Arial, sans-serif" font-size="100" font-weight="bold" fill="#ffffff">%s</text>'
            . '</svg>',
            htmlspecialchars($bgColor),
            htmlspecialchars($initial)
        );

        return new Response(
            $svg,
            Response::HTTP_OK,
            [
                'Content-Type' => 'image/svg+xml',
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ]
        );
    }

    #[Route('/background/{id}', name: 'app_profile_get_background', methods: ['GET'])]
    public function getBackground(int $id, UserRepository $userRepo): Response
    {
        $user = $userRepo->find($id);
        if (!$user) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        if ($user->getProfileBackground()) {
            return new Response(
                $user->getProfileBackground(),
                Response::HTTP_OK,
                [
                    'Content-Type' => $user->getProfileBackgroundType() ?? 'image/webp',
                    'Cache-Control' => 'public, max-age=31536000, immutable',
                ]
            );
        }

        // Fallback: generate gradient SVG background
        $artistName = $user->getArtist() ?? 'Unknown';
        $colors = $this->generateGradientColors($artistName);

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 400">'
            . '<defs><linearGradient id="grad" x1="0%%" y1="0%%" x2="100%%" y2="100%%">'
            . '<stop offset="0%%" stop-color="%s" />'
            . '<stop offset="50%%" stop-color="%s" />'
            . '<stop offset="100%%" stop-color="%s" />'
            . '</linearGradient></defs>'
            . '<rect width="1200" height="400" fill="url(#grad)"/>'
            . '</svg>',
            htmlspecialchars($colors[0]),
            htmlspecialchars($colors[1]),
            htmlspecialchars($colors[2])
        );

        return new Response(
            $svg,
            Response::HTTP_OK,
            [
                'Content-Type' => 'image/svg+xml',
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ]
        );
    }

    #[Route('/delete', name: 'app_profile_delete', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function delete(
        #[CurrentUser] User $user,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $this->validateCsrfToken('delete_account', $request->request->get('_token'));

        $mode = $request->request->get('mode', 'all');

        if ($mode === 'keep_data') {
            // Soft delete: generate backup key
            $randomBytes = bin2hex(random_bytes(16));
            $hash = password_hash($randomBytes, PASSWORD_DEFAULT);
            $backupKey = $user->getId() . '-' . $randomBytes;

            $user->setEmail('deleted_' . $user->getId() . '_' . time() . '@mail.com');
            $user->setArtist('Deleted User');
            $user->setBio(null);
            $user->setPasswordHash(null);
            $user->setBackupKey($hash);
            $user->setProfilePicture(null);
            $user->setProfileBackground(null);

            $em->flush();
            $this->container->get('security.token_storage')->setToken(null);

            return $this->json([
                'status' => 'success',
                'backup_key' => $backupKey,
                'message' => 'Account soft-deleted. Save your backup key to restore it later.',
            ]);
        } else {
            // Hard delete: delete all data
            // TODO: implement deletion of related records (songs, playlists, etc.)
            // For now, just anonymize the user record

            $user->setEmail('deleted_' . $user->getId() . '_' . time() . '@mail.com');
            $user->setArtist('Deleted User');
            $user->setBio(null);
            $user->setPasswordHash(null);
            $user->setBackupKey(null);
            $user->setProfilePicture(null);
            $user->setProfileBackground(null);

            $em->flush();
            $this->container->get('security.token_storage')->setToken(null);

            return $this->json(['status' => 'success', 'message' => 'Account deleted.']);
        }
    }

    #[Route('/restore', name: 'app_profile_restore', methods: ['POST'])]
    public function restore(
        Request $request,
        EntityManagerInterface $em,
        UserRepository $userRepo,
        UserPasswordHasherInterface $hasher
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $backupKey = $data['backup_key'] ?? '';

        $keyParts = explode('-', $backupKey);
        if (count($keyParts) !== 2) {
            return $this->json(['status' => 'error', 'message' => 'Invalid key format.'], Response::HTTP_BAD_REQUEST);
        }

        $userId = (int) $keyParts[0];
        $restoreStr = $keyParts[1];

        $user = $userRepo->find($userId);
        if (!$user || !$user->getBackupKey()) {
            return $this->json(['status' => 'error', 'message' => 'Invalid or expired backup key.'], Response::HTTP_BAD_REQUEST);
        }

        if (!password_verify($restoreStr, $user->getBackupKey())) {
            return $this->json(['status' => 'error', 'message' => 'Invalid backup key.'], Response::HTTP_BAD_REQUEST);
        }

        $newEmail = $data['new_email'] ?? '';
        $newArtist = trim($data['new_artist'] ?? '');
        $newPassword = $data['new_password'] ?? '';

        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL) || empty($newArtist) || strlen($newPassword) < 6) {
            return $this->json(['status' => 'error', 'message' => 'Invalid input data.'], Response::HTTP_BAD_REQUEST);
        }

        // Check uniqueness
        $existingEmail = $userRepo->findOneBy(['email' => $newEmail]);
        $existingArtist = $userRepo->findOneBy(['artist' => $newArtist]);

        if (($existingEmail && $existingEmail->getId() !== $userId) || ($existingArtist && $existingArtist->getId() !== $userId)) {
            return $this->json(['status' => 'error', 'message' => 'Email or Artist already taken.'], Response::HTTP_BAD_REQUEST);
        }

        // Restore account
        $initial = mb_strtoupper(mb_substr($newArtist, 0, 1, 'UTF-8'));
        $bgColor = $this->generateColorRandomly();
        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200">'
            . '<rect width="200" height="200" fill="%s"/>'
            . '<text x="50%%" y="50%%" dominant-baseline="central" text-anchor="middle" '
            . 'font-family="Arial, sans-serif" font-size="100" font-weight="bold" fill="#ffffff">%s</text>'
            . '</svg>',
            htmlspecialchars($bgColor),
            htmlspecialchars($initial)
        );

        $user->setEmail($newEmail);
        $user->setArtist($newArtist);
        $user->setPasswordHash($hasher->hashPassword($user, $newPassword));
        $user->setBackupKey(null);
        $user->setProfilePicture($svg);
        $user->setProfilePictureType('image/svg+xml');

        $em->flush();

        return $this->json(['status' => 'success', 'message' => 'Account restored successfully.']);
    }

    /**
     * Validate CSRF token or throw exception.
     */
    private function validateCsrfToken(string $id, ?string $token): void
    {
        if (!$this->isCsrfTokenValid($id, $token)) {
            throw $this->createAccessDeniedException('CSRF token is invalid.');
        }
    }

    /**
     * Process image to WebP format with resizing.
     */
    private function processImageToWebp(string $imageData, int $width, int $height, bool $isBackground = false): ?string
    {
        $sourceImage = @imagecreatefromstring($imageData);
        if (!$sourceImage) {
            return null;
        }

        $resizedImage = imagecreatetruecolor($width, $height);
        imagecopyresampled(
            $resizedImage,
            $sourceImage,
            0,
            0,
            0,
            0,
            $width,
            $height,
            imagesx($sourceImage),
            imagesy($sourceImage)
        );

        ob_start();
        imagewebp($resizedImage, null, 80);
        $webpData = ob_get_clean();

        imagedestroy($sourceImage);
        imagedestroy($resizedImage);

        return $webpData ? (string) $webpData : null;
    }

    /**
     * Generate a deterministic color from a string.
     */
    private function generateColorFromString(string $input): string
    {
        $colors = [
            '#f44336', '#e91e63', '#9c27b0', '#673ab7', '#3f51b5',
            '#2196f3', '#03a9f4', '#00bcd4', '#009688', '#4caf50',
            '#8bc34a', '#cddc39', '#ffeb3b', '#ffc107', '#ff9800',
            '#ff5722', '#795548',
        ];

        $hash = hexdec(substr(md5($input), 0, 6));
        return $colors[$hash % count($colors)];
    }

    /**
     * Generate gradient colors from a string.
     *
     * @return array<int, string>
     */
    private function generateGradientColors(string $input): array
    {
        $colors = [
            '#f44336', '#e91e63', '#9c27b0', '#673ab7', '#3f51b5',
            '#2196f3', '#03a9f4', '#00bcd4', '#009688', '#4caf50',
            '#8bc34a', '#cddc39', '#ffeb3b', '#ffc107', '#ff9800',
            '#ff5722', '#795548',
        ];

        $color1 = hexdec(substr(md5($input . 'bg1'), 0, 6)) % count($colors);
        $color2 = hexdec(substr(md5($input . 'bg2'), 0, 6)) % count($colors);
        $color3 = hexdec(substr(md5($input . 'bg3'), 0, 6)) % count($colors);

        return [
            $colors[$color1],
            $colors[$color2],
            $colors[$color3],
        ];
    }

    /**
     * Generate a random color for fallback SVG.
     */
    private function generateColorRandomly(): string
    {
        $colors = [
            '#f44336', '#e91e63', '#9c27b0', '#673ab7', '#3f51b5',
            '#2196f3', '#03a9f4', '#00bcd4', '#009688', '#4caf50',
            '#8bc34a', '#cddc39', '#ffeb3b', '#ffc107', '#ff9800',
            '#ff5722', '#795548',
        ];

        return $colors[array_rand($colors)];
    }
}
