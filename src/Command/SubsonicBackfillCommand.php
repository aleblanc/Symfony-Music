<?php

namespace App\Command;

use App\Subsonic\IdCodec;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-off fill of the Subsonic columns on tracks that predate them. Plain
 * DBAL on purpose: it reads only the tag columns it needs (never the image
 * BLOBs) and writes only album_key, artist_key, added_at and updated_at.
 * Re-runnable: rows already filled are skipped (unless --keys).
 */
#[AsCommand(name: 'app:subsonic:backfill', description: 'Fill Subsonic album/artist keys and added/updated dates on existing tracks')]
class SubsonicBackfillCommand extends Command
{
    private const BATCH = 200;

    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count what would change, write nothing');
        $this->addOption('keys', null, InputOption::VALUE_NONE, 'Recompute album/artist keys on every track (e.g. after changing IdCodec)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $allKeys = (bool) $input->getOption('keys');
        $io->title('Subsonic backfill' . ($dryRun ? ' (dry run)' : ''));

        $where = $allKeys ? '1 = 1' : 'album_key IS NULL OR artist_key IS NULL OR added_at IS NULL OR updated_at IS NULL';
        $total = (int) $this->db->fetchOne("SELECT COUNT(*) FROM music WHERE {$where}");
        if ($total === 0) {
            $io->success('Nothing to do: every track is already filled.');

            return Command::SUCCESS;
        }

        $io->progressStart($total);
        $now = new \DateTimeImmutable()->format('Y-m-d H:i:s');
        $updated = 0;
        $lastId = 0;

        do {
            $rows = $this->db->fetchAllAssociative(
                "SELECT id, artist, album_artist, album, last_modified, added_at, updated_at
                 FROM music WHERE id > ? AND ({$where}) ORDER BY id LIMIT " . self::BATCH,
                [$lastId],
            );

            if (!$dryRun) {
                $this->db->beginTransaction();
            }
            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                $addedAt = $row['added_at'] ?? ($row['last_modified'] !== null
                    ? date('Y-m-d H:i:s', (int) $row['last_modified'])
                    : $now);

                if (!$dryRun) {
                    $this->db->executeStatement(
                        'UPDATE music SET album_key = ?, artist_key = ?, added_at = ?, updated_at = ? WHERE id = ?',
                        [
                            IdCodec::albumKey($row['album_artist'], $row['artist'], $row['album']),
                            IdCodec::artistKey($row['album_artist'], $row['artist']),
                            $addedAt,
                            $row['updated_at'] ?? $addedAt,
                            $lastId,
                        ],
                    );
                }
                ++$updated;
                $io->progressAdvance();
            }
            if (!$dryRun) {
                $this->db->commit();
            }
        } while (\count($rows) === self::BATCH);

        $io->progressFinish();
        $io->success(\sprintf('%d track(s) %s.', $updated, $dryRun ? 'would be updated' : 'updated'));

        return Command::SUCCESS;
    }
}
