<?php

namespace App\Command;

use App\Entity\Music;
use App\Repository\MusicRepository;
use App\Service\Lyrics\LrclibClient;
use App\Service\Lyrics\LyricsException;
use App\Service\MetadataWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fills the lyrics of library tracks from LRCLIB: synced LRC when the
 * durations agree (the player then scrolls them with the song), plain text
 * otherwise. Stored in the database and, with --write-tags, in the file's
 * lyrics tag (app:scan only overwrites the database when the tag has some).
 *
 * Re-runnable: by default only tracks without lyrics that were never looked
 * up are processed, so a cron after each `app:scan` only handles new files.
 */
#[AsCommand(name: 'app:lyrics:fetch', description: 'Fetch synced (LRC) or plain lyrics from LRCLIB (DB, optionally file tags)')]
class LyricsFetchCommand extends Command implements SignalableCommandInterface
{
    /** LRCLIB errors in a row before the run gives up (it is flaky: a track's error is usually gone on the next one). */
    private const MAX_CONSECUTIVE_ERRORS = 5;

    private bool $interrupted = false;

    public function __construct(
        private readonly MusicRepository $musicRepository,
        private readonly LrclibClient $lrclib,
        private readonly MetadataWriter $metadataWriter,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Only this track id (always re-queried)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Process at most N tracks')
            ->addOption('retry', null, InputOption::VALUE_NONE, 'Also retry tracks LRCLIB did not know before')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Every track, even those with lyrics (replaced when LRCLIB has some; synced lyrics are never replaced by plain ones)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be found without saving anything')
            ->addOption('write-tags', null, InputOption::VALUE_NONE, 'Also write the lyrics into the file (MP3, M4A, FLAC, Ogg)')
            ->addOption('delay', null, InputOption::VALUE_REQUIRED, 'Pause between tracks, in ms', '200');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $writeTags = (bool) $input->getOption('write-tags');
        $delayMs = max(0, (int) $input->getOption('delay'));
        $limit = $input->getOption('limit') !== null ? max(1, (int) $input->getOption('limit')) : null;
        if ($dryRun && !$output->isVerbose()) {
            $output->setVerbosity(OutputInterface::VERBOSITY_VERBOSE);
        }

        $ids = $input->getOption('id') !== null
            ? [(int) $input->getOption('id')]
            : $this->musicRepository->lyricsCandidateIds(
                retry: (bool) $input->getOption('retry'),
                all: (bool) $input->getOption('force'),
                limit: $limit,
            );

        $io->title('Lyrics from LRCLIB');
        $io->text(\sprintf('%d track(s) to process%s.', \count($ids), $dryRun ? ' (dry run, nothing saved)' : ''));
        if ($ids === []) {
            return Command::SUCCESS;
        }

        $stats = ['synced' => 0, 'plain' => 0, 'instrumental' => 0, 'kept' => 0, 'missing' => 0, 'errors' => 0, 'tagged' => 0, 'tag_skipped' => 0];
        $consecutiveErrors = 0;
        $io->progressStart(\count($ids));
        foreach ($ids as $n => $id) {
            if ($n > 0 && $delayMs > 0) {
                usleep($delayMs * 1000);
            }
            $song = $this->musicRepository->find($id);
            if ($song === null) {
                $io->progressAdvance();
                continue;
            }
            $label = \sprintf('#%d %s – %s', $id, $song->getArtist() ?? '?', $song->getDisplayTitle());

            try {
                $match = $this->lrclib->find((string) $song->getTitle(), (string) $song->getArtist(), $song->getAlbum(), $song->getDuration());
            } catch (LyricsException $e) {
                if (++$consecutiveErrors >= self::MAX_CONSECUTIVE_ERRORS) {
                    $io->progressFinish();
                    $io->error($e->getMessage());
                    $this->flush($dryRun);

                    return Command::FAILURE;
                }
                // Not marked as looked up: the next run tries it again.
                ++$stats['errors'];
                $this->line($io, '<fg=red>!</> ' . $label . ' : LRCLIB error, skipped (retried next run)');
                $io->progressAdvance();
                $this->em->clear();
                continue;
            }
            $consecutiveErrors = 0;

            if ($match === null) {
                ++$stats['missing'];
                $this->line($io, '<fg=yellow>✗</> ' . $label . ' : not on LRCLIB');
            } elseif ($match->text() === null) {
                ++$stats['instrumental'];
                $this->line($io, '<fg=gray>♪</> ' . $label . ' : instrumental');
            } elseif ($match->synced === null && self::isSynced($song->getLyrics())) {
                ++$stats['kept'];
                $this->line($io, '<fg=gray>=</> ' . $label . ' : only plain lyrics on LRCLIB, synced ones kept');
            } else {
                ++$stats[$match->synced !== null ? 'synced' : 'plain'];
                $this->line($io, \sprintf(
                    '<info>✓</info> %s → %s – %s <comment>(%s, %d%%)</comment> https://lrclib.net/api/get/%d',
                    $label,
                    $match->artist,
                    $match->title,
                    $match->synced !== null ? 'synced' : 'plain',
                    (int) round($match->score * 100),
                    $match->lrclibId,
                ));
                if (!$dryRun) {
                    $song->setLyrics($match->text());
                    if ($writeTags) {
                        $this->writeTags($io, $song, $label, $stats);
                    }
                }
            }
            if (!$dryRun) {
                $song->setLyricsCheckedAt(time());
            }

            $io->progressAdvance();
            $this->flush($dryRun);
            if ($this->interrupted) {
                $io->progressFinish();
                $io->warning(\sprintf('Interrupted after %d track(s), all saved: run the command again to continue with the others.', $n + 1));

                return Command::FAILURE;
            }
        }
        $io->progressFinish();

        $rows = [
            ['Synced lyrics (LRC)', $stats['synced']],
            ['Plain lyrics', $stats['plain']],
            ['Instrumental', $stats['instrumental']],
            ['Not on LRCLIB', $stats['missing']],
        ];
        if ($stats['errors'] > 0) {
            $rows[] = ['LRCLIB errors (retried next run)', $stats['errors']];
        }
        if ($stats['kept'] > 0) {
            $rows[] = ['Synced lyrics kept', $stats['kept']];
        }
        if ($writeTags) {
            $rows[] = ['Tags written', $stats['tagged']];
            $rows[] = ['Tags skipped', $stats['tag_skipped']];
        }
        $io->table(['Result', 'Tracks'], $rows);
        if ($stats['missing'] > 0 && !$dryRun) {
            $io->note('Tracks not found are skipped next time (--retry to try again). Lyrics can also be pasted by hand in the song\'s "Edit metadata" page, LRC [mm:ss.xx] lines included.');
        }

        return Command::SUCCESS;
    }

    /** Whether a text holds LRC timestamps ("[01:23.45] …"). */
    private static function isSynced(?string $lyrics): bool
    {
        return $lyrics !== null && preg_match('/^\s*\[\d{1,3}:\d{2}(?:[.:]\d{1,3})?\]/m', $lyrics) === 1;
    }

    /**
     * @param array<string, int> $stats
     */
    private function writeTags(SymfonyStyle $io, Music $song, string $label, array &$stats): void
    {
        $file = (string) $song->getFile();
        if (!$this->metadataWriter->canWrite(pathinfo($file, \PATHINFO_EXTENSION))
            || !$this->metadataWriter->writeTags($file, ['lyrics' => $song->getLyrics()])) {
            ++$stats['tag_skipped'];
            $this->line($io, '  <fg=gray>tags not written for ' . $label . ($this->metadataWriter->lastError() !== null ? ': ' . $this->metadataWriter->lastError() : '') . '</>');

            return;
        }
        ++$stats['tagged'];
        clearstatcache(true, $file);
        // The file changed on purpose: keep app:scan from re-reading it.
        $song->setLastModified(filemtime($file) ?: $song->getLastModified());
    }

    private function line(SymfonyStyle $io, string $message): void
    {
        if ($io->isVerbose()) {
            $io->writeln('');
            $io->writeln($message);
        }
    }

    /** @return list<int> */
    public function getSubscribedSignals(): array
    {
        return \defined('SIGINT') ? [\SIGINT, \SIGTERM] : [];
    }

    /**
     * Ctrl+C: finish the current track, save, then stop (see the loop).
     * A second Ctrl+C stops at once.
     */
    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        if ($this->interrupted) {
            return 130;
        }
        $this->interrupted = true;

        return false;
    }

    private function flush(bool $dryRun): void
    {
        if (!$dryRun) {
            $this->em->flush();
        }
        $this->em->clear();
    }
}
