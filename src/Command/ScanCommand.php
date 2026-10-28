<?php

namespace App\Command;

use App\Service\MusicScanner;
use SimpleCronScheduler\Attribute\AsCronTask;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Indexes the music library. Replaces the legacy `?action=full_scan`
 * browser-driven scan with a proper CLI command that can also be cron-run.
 */
#[AsCommand(name: 'app:cron:scan', description: 'Scan the music library and sync the database')]
#[AsCronTask('5 * * * *', timeout: 3000, description: 'Hourly: index new, changed and removed files')]
class ScanCommand extends Command
{
    public function __construct(private readonly MusicScanner $scanner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('verbose-scan', null, InputOption::VALUE_NONE, 'Print every file as it is processed');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Re-read tags for every file, even if unchanged (mirrors legacy force_rescan)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Music library scan');
        $io->text('Library: ' . $this->scanner->getMusicDir());

        $verbose = (bool) $input->getOption('verbose-scan');
        $progress = static function (string $line) use ($io, $verbose): void {
            if ($verbose || !str_starts_with(ltrim($line), '+') && !str_starts_with(ltrim($line), '~')) {
                $io->writeln($line);
            }
        };

        try {
            $stats = $this->scanner->scan($progress, (bool) $input->getOption('force'));
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf(
            'Scan complete — %d added, %d updated, %d removed, %d unchanged.',
            $stats['added'],
            $stats['updated'],
            $stats['removed'],
            $stats['skipped'],
        ));

        return Command::SUCCESS;
    }
}
