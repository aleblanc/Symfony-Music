<?php

namespace App\Command;

use App\Entity\Favorite;
use App\Entity\Playlist;
use App\Entity\PlaylistSong;
use App\Repository\MusicRepository;
use App\Service\BinaryLocator;
use App\Service\Import\LibraryPath;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/**
 * Prepares a folder to copy onto a phone, an SD card or a USB key: the
 * tracks of a playlist, the 5-star ones and/or the hearted ones, filed
 * Artist/Album (Year)/NN Title.ext (names safe on exFAT / FAT32), one .m3u
 * per selection at the root. Lossless files can be converted on the fly to
 * M4A (AAC 256), or everything to MP3 320 for players that only take MP3;
 * tags and cover are kept. Run it again: only what is missing or changed is
 * written. Nothing is ever removed from the folder.
 */
#[AsCommand(name: 'app:tool:device-sync', description: 'Prepare a folder (to copy onto a phone, SD card, USB key) with a playlist, the 5-star and/or the hearted tracks, optionally converted to M4A or MP3')]
class DeviceSyncCommand extends Command
{
    private const LOSSLESS = ['flac', 'alac', 'wav', 'aiff'];
    private const AAC_KBPS = 256;
    private const MP3_KBPS = 320;

    public function __construct(
        private readonly MusicRepository $music,
        private readonly EntityManagerInterface $em,
        private readonly LibraryPath $libraryPath,
        private readonly string $ffmpegPath = '',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Folder to fill (created if needed), e.g. ~/Music/ToPhone (required)')
            ->addOption('playlist', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A playlist, by id or name (repeatable)')
            ->addOption('rating', null, InputOption::VALUE_REQUIRED, 'Tracks with at least this many stars (5: the 5-star ones)')
            ->addOption('favorites', null, InputOption::VALUE_NONE, 'The hearted tracks (of any user)')
            ->addOption('convert-m4a', null, InputOption::VALUE_NONE, sprintf('Convert the lossless files (FLAC, ALAC, WAV, AIFF) to M4A AAC %d kb/s', self::AAC_KBPS))
            ->addOption('convert-mp3', null, InputOption::VALUE_NONE, sprintf('Convert everything that is not MP3 (FLAC, M4A...) to MP3 %d kb/s, for players that only take MP3', self::MP3_KBPS))
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be written and how much space it takes; write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Device sync');

        $to = (string) $input->getOption('to');
        if ($to === '') {
            $io->error('--to is required: the folder to fill, e.g. --to=~/Music/ToPhone');

            return Command::FAILURE;
        }
        $to = rtrim(str_starts_with($to, '~/') ? (getenv('HOME') ?: '~') . substr($to, 1) : $to, '/');
        $dryRun = (bool) $input->getOption('dry-run');
        $mode = $input->getOption('convert-mp3') ? 'mp3' : ($input->getOption('convert-m4a') ? 'm4a' : null);

        try {
            $selections = $this->selections($input);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        if ($selections === []) {
            $io->error('Nothing selected: use --playlist=<id or name>, --rating=5 and/or --favorites.');

            return Command::FAILURE;
        }
        $ffmpeg = $mode !== null ? (new BinaryLocator())->find('ffmpeg', $this->ffmpegPath) : null;
        if ($mode !== null && $ffmpeg === null && !$dryRun) {
            $io->error('ffmpeg not found: brew install ffmpeg (or set FFMPEG_PATH in .env.local).');

            return Command::FAILURE;
        }

        // Every track once, whatever the number of selections it is in.
        /** @var array<int, array<string, mixed>> $tracks */
        $tracks = [];
        foreach ($selections as $selection) {
            foreach ($selection['tracks'] as $track) {
                $tracks[(int) $track['id']] ??= $track;
            }
        }
        $discTotals = $this->discTotals();
        $targets = [];
        $used = [];
        foreach ($tracks as $id => $track) {
            $format = $this->outputFormat($track, $mode);
            $relative = $this->libraryPath->relativePath([
                'title' => $track['title'] ?? pathinfo((string) $track['file'], \PATHINFO_FILENAME),
                'artist' => $track['artist'],
                'albumArtist' => $track['albumArtist'],
                'album' => $track['album'],
                'year' => $track['year'],
                'trackNumber' => $track['trackNumber'],
                'discNumber' => $track['discNumber'],
                'discTotal' => $discTotals[$track['albumKey'] ?? ''] ?? 1,
            ], $format['ext']);
            // Two tracks filed at the same place (a duplicate): the second one gets " (2)".
            for ($i = 2, $base = $relative; isset($used[mb_strtolower($relative)]); ++$i) {
                $relative = preg_replace('/(\.[^.\/]+)$/', " ($i)$1", $base, 1);
            }
            $used[mb_strtolower($relative)] = true;
            $targets[$id] = ['relative' => $relative] + $format;
        }

        $io->definitionList(
            ['Selection' => implode(', ', array_map(static fn (array $s): string => \sprintf('%s (%d)', $s['name'], \count($s['tracks'])), $selections)) . \sprintf(' → %d tracks', \count($tracks))],
            ['Into' => $to . ($dryRun ? '  (dry run: nothing written)' : '')],
            ['Formats' => match ($mode) {
                'mp3' => \sprintf('everything as MP3 (non-MP3 converted to %d kb/s)', self::MP3_KBPS),
                'm4a' => \sprintf('lossless converted to M4A AAC %d kb/s, the rest as is', self::AAC_KBPS),
                default => 'as in the library',
            }],
        );

        if (!$dryRun && !is_dir($to) && !mkdir($to, 0o777, true) && !is_dir($to)) {
            $io->error('Cannot create ' . $to);

            return Command::FAILURE;
        }

        $stats = ['copied' => 0, 'converted' => 0, 'unchanged' => 0, 'missing' => 0, 'failed' => 0];
        $bytes = 0;
        $progress = $io->createProgressBar(\count($tracks));
        $progress->setFormat($output->isVerbose() ? 'debug' : 'normal');
        foreach ($tracks as $id => $track) {
            $source = (string) $track['file'];
            $target = $targets[$id];
            $file = $to . '/' . $target['relative'];
            if (!is_file($source)) {
                ++$stats['missing'];
                $progress->clear();
                $io->writeln(\sprintf('  <comment>?</comment> file missing in the library: %s', OutputFormatter::escape($source)));
                $progress->display();
                $progress->advance();
                continue;
            }
            // Already there and not older than the library file: nothing to do.
            if (is_file($file) && filesize($file) > 0 && filemtime($file) >= filemtime($source)) {
                ++$stats['unchanged'];
                $progress->advance();
                continue;
            }
            $bytes += $target['convert'] ? (int) ((int) $track['duration'] * $target['kbps'] * 125) : (int) filesize($source);
            if ($output->isVerbose()) {
                $progress->clear();
                $io->writeln(\sprintf('  %s %s', $target['convert'] ? '<info>♪</info>' : '<info>→</info>', OutputFormatter::escape($target['relative'])));
                $progress->display();
            }
            if ($dryRun) {
                ++$stats[$target['convert'] ? 'converted' : 'copied'];
                $progress->advance();
                continue;
            }

            try {
                if (!is_dir(\dirname($file)) && !mkdir(\dirname($file), 0o777, true) && !is_dir(\dirname($file))) {
                    throw new \RuntimeException('cannot create ' . \dirname($file));
                }
                // Written next to the target, then renamed: an interrupted run leaves no half file.
                $tmp = \dirname($file) . '/.sync-' . bin2hex(random_bytes(4)) . '.' . $target['ext'];
                try {
                    if ($target['convert']) {
                        $this->convert((string) $ffmpeg, $source, $tmp, $target['ext'], $target['kbps']);
                    } elseif (!copy($source, $tmp)) {
                        throw new \RuntimeException('copy failed');
                    }
                    if (!rename($tmp, $file)) {
                        throw new \RuntimeException('cannot write ' . $file);
                    }
                } finally {
                    if (is_file($tmp)) {
                        @unlink($tmp);
                    }
                }
                ++$stats[$target['convert'] ? 'converted' : 'copied'];
            } catch (\RuntimeException $e) {
                ++$stats['failed'];
                $progress->clear();
                $io->writeln(\sprintf('  <error>!</error> %s: %s', OutputFormatter::escape($target['relative']), OutputFormatter::escape($e->getMessage())));
                $progress->display();
            }
            $progress->advance();
        }
        $progress->finish();
        $io->newLine(2);

        // One playlist file per selection, paths relative to the folder root.
        foreach ($selections as $selection) {
            $m3u = $to . '/' . basename($this->libraryPath->relativePath(['title' => $selection['name']], 'm3u'));
            $lines = ['#EXTM3U'];
            foreach ($selection['tracks'] as $track) {
                $lines[] = \sprintf('#EXTINF:%d,%s - %s', (int) $track['duration'], $track['artist'] ?? '', $track['title'] ?? '');
                $lines[] = $targets[(int) $track['id']]['relative'];
            }
            if ($dryRun) {
                $io->writeln(\sprintf('  · playlist %s (%d tracks)', OutputFormatter::escape(basename($m3u)), \count($selection['tracks'])));
            } elseif (file_put_contents($m3u, implode("\n", $lines) . "\n") === false) {
                $io->warning('Cannot write ' . $m3u);
            }
        }

        $free = is_dir($to) ? @disk_free_space($to) : false;
        $summary = \sprintf('%d copied, %d converted, %d already there, %d missing in the library, %d failed · %s %s', $stats['copied'], $stats['converted'], $stats['unchanged'], $stats['missing'], $stats['failed'], $dryRun ? 'would write about' : 'wrote about', self::size($bytes))
            . ($free !== false ? ' · ' . self::size((int) $free) . ' free' : '');
        $dryRun || $stats['failed'] === 0 ? $io->success($summary) : $io->warning($summary);
        if ($dryRun && $free !== false && $bytes > $free) {
            $io->warning('Not enough free space in ' . $to);
        }
        if (!$dryRun) {
            $io->text('Next: copy the content of ' . $to . ' into the Music folder of the device (SD card).');
        }

        return $stats['failed'] === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return list<array{name: string, tracks: list<array<string, mixed>>}>
     */
    private function selections(InputInterface $input): array
    {
        $selections = [];
        foreach ((array) $input->getOption('playlist') as $wanted) {
            $wanted = trim((string) $wanted);
            $playlists = ctype_digit($wanted)
                ? array_filter([$this->em->getRepository(Playlist::class)->find((int) $wanted)])
                : $this->em->getRepository(Playlist::class)->createQueryBuilder('p')->andWhere('LOWER(p.name) = :name')->setParameter('name', mb_strtolower($wanted))->getQuery()->getResult();
            if (\count($playlists) !== 1) {
                throw new \InvalidArgumentException(\sprintf($playlists === [] ? 'No playlist "%s".' : 'Several playlists are called "%s": use its id.', $wanted));
            }
            /** @var Playlist $playlist */
            $playlist = reset($playlists);
            $tracks = $this->columns($this->music->createQueryBuilder('m'))
                ->join(PlaylistSong::class, 'ps', 'WITH', 'ps.song = m')
                ->andWhere('ps.playlist = :playlist')->setParameter('playlist', $playlist)
                ->orderBy('ps.sortOrder', 'ASC')
                ->getQuery()->getArrayResult();
            $selections[] = ['name' => (string) $playlist->getName(), 'tracks' => $tracks];
        }
        if ($input->getOption('rating') !== null) {
            $stars = max(1, min(5, (int) $input->getOption('rating')));
            $selections[] = ['name' => $stars === 5 ? '5 stars' : $stars . '+ stars', 'tracks' => $this->sorted($this->music->createQueryBuilder('m')->andWhere('m.rating >= :stars')->setParameter('stars', $stars))];
        }
        if ($input->getOption('favorites')) {
            $selections[] = ['name' => 'Favorites', 'tracks' => $this->sorted($this->music->createQueryBuilder('m')->andWhere('EXISTS (SELECT 1 FROM ' . Favorite::class . ' f WHERE f.song = m)'))];
        }

        return $selections;
    }

    /** @return list<array<string, mixed>> by artist, album, disc and track */
    private function sorted(QueryBuilder $qb): array
    {
        return $this->columns($qb)->orderBy('m.artist', 'ASC')->addOrderBy('m.album', 'ASC')->addOrderBy('m.discNumber', 'ASC')->addOrderBy('m.trackNumber', 'ASC')->getQuery()->getArrayResult();
    }

    /** Only the columns needed: whole entities would load every cover into memory. */
    private function columns(QueryBuilder $qb): QueryBuilder
    {
        return $qb->select('m.id', 'm.file', 'm.title', 'm.artist', 'm.albumArtist', 'm.album', 'm.year', 'm.trackNumber', 'm.discNumber', 'm.duration', 'm.albumKey', 'm.audioFormat', 'm.lossless');
    }

    /** @return array<string, int> highest disc number per album: "2-05 Title" only on multi-disc albums */
    private function discTotals(): array
    {
        $totals = [];
        foreach ($this->music->createQueryBuilder('m')->select('m.albumKey', 'MAX(m.discNumber) AS discs')->andWhere('m.albumKey IS NOT NULL')->groupBy('m.albumKey')->getQuery()->getArrayResult() as $row) {
            $totals[(string) $row['albumKey']] = max(1, (int) $row['discs']);
        }

        return $totals;
    }

    /** @return array{ext: string, convert: bool, kbps: int} */
    /** @param array<string, mixed> $track */
    private function outputFormat(array $track, ?string $mode): array
    {
        $ext = strtolower(pathinfo((string) $track['file'], \PATHINFO_EXTENSION));
        $lossless = $track['lossless'] === true || \in_array($track['audioFormat'], self::LOSSLESS, true) || \in_array($ext, ['flac', 'wav', 'aif', 'aiff'], true);

        return match (true) {
            $mode === 'mp3' && $ext !== 'mp3' => ['ext' => 'mp3', 'convert' => true, 'kbps' => self::MP3_KBPS],
            $mode === 'm4a' && $lossless => ['ext' => 'm4a', 'convert' => true, 'kbps' => self::AAC_KBPS],
            default => ['ext' => $ext, 'convert' => false, 'kbps' => 0],
        };
    }

    /** ffmpeg, tags and cover kept (without the cover when the container refuses it). */
    private function convert(string $ffmpeg, string $source, string $target, string $ext, int $kbps): void
    {
        if ($ext === 'mp3') {
            $codec = ['-c:a', 'libmp3lame', '-b:a', $kbps . 'k', '-id3v2_version', '3', '-f', 'mp3'];
        } else {
            static $aac = null;
            if ($aac === null) {
                $encoders = new Process([$ffmpeg, '-hide_banner', '-encoders']);
                $encoders->run();
                $aac = str_contains($encoders->getOutput(), ' aac_at ') ? 'aac_at' : 'aac';
            }
            $codec = ['-c:a', $aac, '-b:a', $kbps . 'k', '-movflags', '+faststart', '-f', 'ipod'];
        }
        $common = [$ffmpeg, '-hide_banner', '-loglevel', 'error', '-nostdin', '-y', '-i', $source, '-map_metadata', '0'];
        $error = '';
        foreach ([
            [...$common, '-map', '0:a:0', '-map', '0:v:0?', ...$codec, '-c:v', 'copy', '-disposition:v:0', 'attached_pic', $target],
            [...$common, '-map', '0:a:0', ...$codec, $target],
        ] as $command) {
            $process = new Process($command, null, null, null, 600);
            $process->run();
            if ($process->isSuccessful() && is_file($target) && filesize($target) > 0) {
                return;
            }
            $error = trim($process->getErrorOutput());
            @unlink($target);
        }
        throw new \RuntimeException('ffmpeg failed: ' . ($error !== '' ? $error : 'no output'));
    }

    private static function size(int $bytes): string
    {
        return $bytes >= 1073741824 ? \sprintf('%.1f GB', $bytes / 1073741824) : \sprintf('%d MB', (int) round($bytes / 1048576));
    }
}
