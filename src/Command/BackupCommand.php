<?php

namespace App\Command;

use App\Service\BinaryLocator;
use Doctrine\DBAL\Connection;
use SimpleCronScheduler\Attribute\AsCronTask;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/**
 * Backs up the SQLite database with `sqlite3 … ".backup"` (a consistent copy
 * even while the app is running) into var/backup/, checks the copy with
 * `PRAGMA quick_check`, then prunes old backups on a GFS retention: keep the
 * newest plus the oldest one of each age band (0–12h, 12–24h, 24–48h, 48h–7d,
 * 7–14d), so a kept backup ages through its band into the next one.
 */
#[AsCommand(name: 'app:cron:backup', description: 'Back up the SQLite database (sqlite3 .backup) and prune old backups')]
#[AsCronTask('0 */12 * * *', timeout: 1800, description: 'Every 12 hours: database backup + pruning')]
class BackupCommand extends Command
{
    /** Upper bounds of the retention age bands in seconds: 12h, 24h, 48h, 7d, 14d. */
    private const RETENTION = [43200, 86400, 172800, 604800, 1209600];

    public function __construct(
        private readonly Connection $connection,
        private readonly string $projectDir,
        private readonly string $sqlite3Path = '',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('prune-only', null, InputOption::VALUE_NONE, 'Skip the backup, only apply the retention pruning')
            ->addOption('no-check', null, InputOption::VALUE_NONE, 'Skip the integrity check of the copy');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dir = $this->projectDir . '/var/backup';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $io->error(\sprintf('Cannot create the backup dir %s', $dir));

            return Command::FAILURE;
        }

        if (!$input->getOption('prune-only')) {
            $sqlite3 = (new BinaryLocator())->find('sqlite3', $this->sqlite3Path);
            if ($sqlite3 === null) {
                $io->error('sqlite3 not found: install it (brew install sqlite) or set SQLITE3_PATH.');

                return Command::FAILURE;
            }
            $dbPath = $this->databasePath();
            if ($dbPath === null || !is_file($dbPath)) {
                $io->error('SQLite database file not found (not a SQLite connection?)');

                return Command::FAILURE;
            }

            $dest = \sprintf('%s/backup-%s.sqlite', $dir, (new \DateTimeImmutable())->format('Ymd-His'));
            $started = microtime(true);
            $process = new Process([$sqlite3, $dbPath, ".backup '" . str_replace("'", "''", $dest) . "'"]);
            $process->setTimeout(1500);
            try {
                $process->mustRun();
            } catch (\Throwable $e) {
                $this->discard($dest);
                $io->error(\sprintf('Backup failed: %s', $e->getMessage()));

                return Command::FAILURE;
            }
            if (!is_file($dest) || filesize($dest) === 0) {
                $this->discard($dest);
                $io->error('The backup produced no file.');

                return Command::FAILURE;
            }

            // The copy inherits WAL mode, which cannot be opened read-only and needs
            // -wal/-shm files beside it: make it a standalone rollback-journal file
            // (SqlitePragmaMiddleware turns WAL back on once it is restored).
            // A corrupt copy is worse than none: it would push a good one out of the retention.
            $sql = 'PRAGMA journal_mode = DELETE;' . ($input->getOption('no-check') ? '' : ' PRAGMA quick_check;');
            $check = new Process([$sqlite3, $dest, $sql]);
            $check->setTimeout(1500);
            $check->run();
            $lines = preg_split('/\R/', trim($check->getOutput())) ?: [];
            if ($input->getOption('no-check')) {
                if (!$check->isSuccessful()) {
                    $this->discard($dest);
                    $io->error(\sprintf('Cannot finalize the copy, deleted: %s', trim($check->getErrorOutput())));

                    return Command::FAILURE;
                }
            } else {
                $result = trim((string) end($lines));
                if (!$check->isSuccessful() || $result !== 'ok') {
                    $this->discard($dest);
                    $io->error(\sprintf('The copy failed the integrity check, deleted: %s', $result !== '' ? $result : trim($check->getErrorOutput())));

                    return Command::FAILURE;
                }
            }

            // Back in rollback-journal mode with no connection left, a -shm (or empty
            // -wal) SQLite did not remove is stale.
            foreach (['-wal', '-shm'] as $suffix) {
                @unlink($dest . $suffix);
            }

            $io->text(\sprintf('Backup written: %s (%s, %.1fs)', basename($dest), $this->size((int) filesize($dest)), microtime(true) - $started));
        }

        $this->prune($dir, $io);

        return Command::SUCCESS;
    }

    /**
     * GFS pruning: keep the newest backup and the oldest one of each age band.
     * Keeping the oldest (not "the newest at least N old") is what lets a backup
     * survive until it reaches the next band; otherwise a younger one would take
     * its slot and nothing would ever get older than a band. Past the last band,
     * the newest expired backup stays while the 7–14d band is empty, so there is
     * always one at least 7 days old. Delete everything else.
     */
    private function prune(string $dir, SymfonyStyle $io): void
    {
        $files = glob($dir . '/backup-*.sqlite') ?: [];
        if ($files === []) {
            $io->text('No backup to prune.');

            return;
        }
        // Newest first, by mtime (independent of the filename).
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        $now = time();
        $age = static fn (string $f): int => $now - (int) filemtime($f);
        $keep = [$files[0] => true];
        $low = 0;
        $lastBandFilled = false;
        foreach (self::RETENTION as $high) {
            $oldest = null;
            foreach ($files as $f) {
                if ($age($f) > $low && $age($f) <= $high) {
                    $oldest = $f; // newest first: the last match is the oldest
                }
            }
            if ($oldest !== null) {
                $keep[$oldest] = true;
            }
            $lastBandFilled = $oldest !== null;
            $low = $high;
        }
        if (!$lastBandFilled) {
            foreach ($files as $f) {
                if ($age($f) > $low) {
                    $keep[$f] = true; // the newest expired one
                    break;
                }
            }
        }

        $deleted = 0;
        foreach ($files as $f) {
            if (!isset($keep[$f]) && @unlink($f)) {
                @unlink($f . '-wal');
                @unlink($f . '-shm');
                ++$deleted;
                $io->text('Pruned: ' . basename($f));
            }
        }
        $io->success(\sprintf('%d backup(s) kept, %d pruned.', \count($keep), $deleted));
    }

    /** Deletes a failed copy and the -wal / -shm files SQLite may have left beside it. */
    private function discard(string $dest): void
    {
        foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
            @unlink($dest . $suffix);
        }
    }

    /** Absolute path of the SQLite database file, or null if not a SQLite connection. */
    private function databasePath(): ?string
    {
        $path = $this->connection->getParams()['path'] ?? null;

        return \is_string($path) ? $path : null;
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1048576 ? \sprintf('%.1f MB', $bytes / 1048576) : \sprintf('%d KB', intdiv($bytes, 1024));
    }
}
