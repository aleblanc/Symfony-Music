<?php

namespace App\Controller;

use App\Entity\Music;
use App\Entity\User;
use App\Repository\MusicRepository;
use App\Repository\UserRepository;
use App\Service\AudioFormat;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Song upload form. Ported from the legacy `upload_song` action: getID3
 * tag extraction, sharded on-disk storage under `MUSIC_DIR/uploads/`, and the
 * 10-songs-per-day upload quota.
 *
 * Simplifications vs the legacy action (documented in the migration plan):
 * ownership uses the real `music.user_id` foreign key instead of the legacy
 * "(id:N)" suffix hack baked into the `artist` string, and collaborator
 * invites (`song_collaborators`/`song_invites`) are left for a later feature.
 */
#[Route('/upload')]
#[IsGranted('ROLE_USER')]
class UploadController extends AbstractController
{
    private const DAILY_UPLOAD_LIMIT = 10;
    private const ALLOWED_EXTENSIONS = ['mp3', 'flac', 'm4a', 'ogg', 'wav'];

    /**
     * Real (content-sniffed) MIME types accepted per extension. Browsers and
     * fileinfo disagree a lot on m4a/ogg/wav containers, so each extension
     * accepts a small family of plausible sniffed types rather than one.
     */
    private const ALLOWED_MIME_TYPES = [
        'mp3' => ['audio/mpeg', 'audio/mp3', 'audio/x-mp3'],
        'flac' => ['audio/flac', 'audio/x-flac'],
        'm4a' => ['audio/mp4', 'audio/x-m4a', 'audio/m4a', 'video/mp4'],
        'ogg' => ['audio/ogg', 'application/ogg'],
        'wav' => ['audio/wav', 'audio/x-wav', 'audio/vnd.wave', 'audio/wave'],
    ];

    public function __construct(
        private readonly string $musicDir,
        private readonly int $maxUploadBytes,
    ) {}

    #[Route('', name: 'app_upload', methods: ['GET'])]
    public function form(#[CurrentUser] User $user): Response
    {
        return $this->render('upload/form.html.twig', [
            'quota' => $this->remainingQuota($user),
            'limit' => self::DAILY_UPLOAD_LIMIT,
            'maxUploadBytes' => $this->maxUploadBytes,
        ]);
    }

    #[Route('', name: 'app_upload_submit', methods: ['POST'])]
    public function submit(
        Request $request,
        #[CurrentUser]
        User $user,
        MusicRepository $musicRepository,
        UserRepository $userRepository,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('upload_song', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ($user->getVerified() !== 'yes') {
            $this->addFlash('error', 'Your account is not verified for uploads.');

            return $this->redirectToRoute('app_upload');
        }

        $remaining = $this->remainingQuota($user);
        if ($remaining <= 0) {
            $this->addFlash('error', \sprintf('Daily upload limit of %d songs reached.', self::DAILY_UPLOAD_LIMIT));

            return $this->redirectToRoute('app_upload');
        }

        /** @var UploadedFile|null $file */
        $file = $request->files->get('song');
        if ($file === null) {
            $this->addFlash('error', 'No file uploaded.');

            return $this->redirectToRoute('app_upload');
        }

        if (!$file->isValid()) {
            $this->addFlash('error', 'Upload error: ' . $file->getErrorMessage());

            return $this->redirectToRoute('app_upload');
        }

        if ($file->getSize() !== false && $file->getSize() > $this->maxUploadBytes) {
            $this->addFlash('error', \sprintf('File too large (max %d MB).', (int) ($this->maxUploadBytes / 1024 / 1024)));

            return $this->redirectToRoute('app_upload');
        }

        $ext = strtolower($file->getClientOriginalExtension());
        if (!\in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            $this->addFlash('error', 'Invalid file format. Only MP3, FLAC, M4A, OGG, and WAV are allowed.');

            return $this->redirectToRoute('app_upload');
        }

        // Validate the *real* content type (fileinfo magic-byte sniffing), not
        // just the extension the browser reported. `symfony/mime` is not a
        // dependency of this project, so UploadedFile::getMimeType() would
        // throw; sniff directly with ext-fileinfo instead (same approach the
        // legacy code used via finfo_open()).
        $sniffedMime = $this->sniffMimeType($file->getPathname());
        if ($sniffedMime === null || !\in_array($sniffedMime, self::ALLOWED_MIME_TYPES[$ext], true)) {
            $this->addFlash('error', 'Security error: file content does not match its extension.');

            return $this->redirectToRoute('app_upload');
        }

        if (!class_exists(\getID3::class)) {
            $this->addFlash('error', 'getID3 library is missing.');

            return $this->redirectToRoute('app_upload');
        }

        $getID3 = new \getID3();
        $getID3->setOption(['encoding' => 'UTF-8']);
        $info = $getID3->analyze($file->getPathname());

        // A second, content-based validation: getID3 must actually recognise
        // the file as audio, independent of extension/MIME sniffing above.
        if (!empty($info['error']) || empty($info['audio'])) {
            $this->addFlash('error', 'Security error: file could not be parsed as audio.');

            return $this->redirectToRoute('app_upload');
        }

        \getid3_lib::CopyTagsToComments($info);
        $comments = $info['comments'] ?? [];

        $uploaderName = (string) ($user->getArtist() ?? 'Unknown Artist');
        $artist = $this->tag($comments, 'artist', $uploaderName);
        if ($artist === '' || $artist === 'Unknown Artist') {
            $artist = $uploaderName;
        }

        $title = $this->tag($comments, 'title', pathinfo($file->getClientOriginalName(), \PATHINFO_FILENAME));
        $album = $this->tag($comments, 'album', 'Unknown Album');
        $genreOverride = trim((string) $request->request->get('genre', ''));
        $genre = $this->tag($comments, 'genre', $genreOverride !== '' ? $genreOverride : 'Uploaded');
        $year = (int) ($comments['year'][0] ?? 0);
        $duration = (int) ($info['playtime_seconds'] ?? 0);
        $audioFormat = AudioFormat::fromGetId3($info, $ext);
        $lyrics = $this->tag($comments, 'unsynchronised_lyric', '');
        $rawImage = $comments['picture'][0]['data'] ?? null;
        $isPrivate = (bool) $request->request->get('is_private', false);

        // Sharded on-disk storage: MUSIC_DIR/uploads/<md5-shard>/<artist>/file.
        $artistPath = $this->sanitizeForPath($artist);
        $shard = substr(md5($artistPath), 0, 2);
        $uploadDir = $this->musicDir . '/uploads/' . $shard . '/' . $artistPath;
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0o755, true) && !is_dir($uploadDir)) {
            $this->addFlash('error', 'Could not create upload directory.');

            return $this->redirectToRoute('app_upload');
        }

        $filename = uniqid('m_') . '.' . $ext;

        try {
            $movedFile = $file->move($uploadDir, $filename);
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Failed to move uploaded file.');

            return $this->redirectToRoute('app_upload');
        }

        $filePath = str_replace('\\', '/', $movedFile->getPathname());

        $song = new Music();
        $song->setFile($filePath);
        $song->setOwner($user);
        $song->setTitle($title);
        $song->setArtist($artist);
        $song->setAlbum($album);
        $song->setGenre($genre);
        $song->setYear($year ?: null);
        $song->setDuration($duration);
        $song->applyAudioFormat($audioFormat);
        $song->setLastModified(filemtime($filePath) ?: time());
        $song->setIsPrivate($isPrivate);
        if ($lyrics !== '') {
            $song->setLyrics($lyrics);
        }
        if ($rawImage !== null) {
            $song->setImage($rawImage);
        }

        $musicRepository->save($song, false);

        // Update the daily quota counter.
        $today = date('Y-m-d');
        $newCount = ($user->getLastUploadDate() === $today) ? $user->getDailyUploadCount() + 1 : 1;
        $user->setDailyUploadCount($newCount);
        $user->setLastUploadDate($today);
        $userRepository->save($user, false);

        $em->flush();

        $this->addFlash('success', \sprintf('"%s" uploaded successfully.', $title));

        return $this->redirectToRoute('app_song', ['id' => $song->getId()]);
    }

    /**
     * Songs the user may still upload today (0 if quota exhausted).
     */
    private function remainingQuota(User $user): int
    {
        $today = date('Y-m-d');
        $usedToday = $user->getLastUploadDate() === $today ? $user->getDailyUploadCount() : 0;

        return max(0, self::DAILY_UPLOAD_LIMIT - $usedToday);
    }

    /**
     * @param array<string, mixed> $comments
     */
    private function tag(array $comments, string $key, string $default): string
    {
        $value = $comments[$key][0] ?? null;
        if (!\is_string($value)) {
            return $default;
        }
        $value = trim($value);

        return $value !== '' ? $value : $default;
    }

    private function sanitizeForPath(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '', $value) ?? '';

        return $value !== '' ? $value : 'unknown';
    }

    /**
     * Sniffs the real MIME type from the file's magic bytes via ext-fileinfo.
     */
    private function sniffMimeType(string $path): ?string
    {
        $finfo = finfo_open(\FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }
        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        return $mime !== false ? $mime : null;
    }
}
