<?php

namespace App\Controller\Api\Subsonic;

use App\Entity\User;
use App\Repository\MusicRepository;
use App\Subsonic\SongMapper;
use App\Subsonic\SubsonicHandler;
use App\Subsonic\SubsonicRequest;
use Doctrine\DBAL\Connection;

/**
 * Connection and server-capability calls: ping, license, OpenSubsonic
 * extensions, music folders, user, scan status.
 */
final class SystemHandler implements SubsonicHandler
{
    public const MUSIC_FOLDER_ID = 1;

    public function __construct(
        private readonly MusicRepository $music,
        private readonly Connection $db,
    ) {}

    public static function methods(): array
    {
        return ['ping', 'getLicense', 'getOpenSubsonicExtensions', 'getMusicFolders', 'getUser', 'getScanStatus'];
    }

    public function handle(string $method, SubsonicRequest $request, User $user): array
    {
        return match ($method) {
            'ping' => [],
            'getLicense' => ['license' => ['valid' => true, 'email' => $user->getEmail()]],
            'getOpenSubsonicExtensions' => ['openSubsonicExtensions' => [
                ['name' => 'formPost', 'versions' => [1]],
                ['name' => 'songLyrics', 'versions' => [1]],
            ]],
            'getMusicFolders' => ['musicFolders' => ['musicFolder' => [['id' => self::MUSIC_FOLDER_ID, 'name' => 'Bibliothèque']]]],
            'getUser' => ['user' => [
                'username' => $request->get('username') ?? $user->getUserIdentifier(),
                'email' => $user->getEmail(),
                'scrobblingEnabled' => true,
                'adminRole' => $user->isSuperAdmin(),
                'settingsRole' => true,
                'downloadRole' => true,
                'uploadRole' => false,
                'playlistRole' => true,
                'coverArtRole' => true,
                'commentRole' => false,
                'podcastRole' => false,
                'streamRole' => true,
                'jukeboxRole' => false,
                'shareRole' => false,
                'videoConversionRole' => false,
                'folder' => [self::MUSIC_FOLDER_ID],
            ]],
            'getScanStatus' => ['scanStatus' => $this->scanStatus($user)],
            default => throw new \LogicException("Unhandled Subsonic method {$method}"),
        };
    }

    /**
     * Clients (Substreamer) compare count + lastScan between syncs to decide
     * whether anything changed; lastScan = the latest track change.
     *
     * @return array<string, mixed>
     */
    public function scanStatus(User $user): array
    {
        $last = $this->db->fetchOne('SELECT MAX(updated_at) FROM music');

        return [
            'scanning' => false,
            'count' => $this->music->countAll($user),
            'folderCount' => 1,
            'lastScan' => SongMapper::iso($last !== false ? $last : null),
        ];
    }
}
