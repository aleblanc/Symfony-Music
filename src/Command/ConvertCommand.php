<?php

namespace App\Command;

use App\Service\Import\WmaConverter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Step 0 of adding music, when the import folder holds WMA files: converts
 * them to M4A (AAC) with ffmpeg, tags and cover kept, originals moved to
 * IMPORT_DIR/_wma. Then run `app:import:files` yourself.
 */
#[AsCommand(name: 'app:import:convert-wma', description: 'Convert the WMA files of the import folder to M4A (AAC), tags kept; originals moved to IMPORT_DIR/_wma')]
class ConvertCommand extends Command
{
    private const BITRATES = [192, 256, 320];

    public function __construct(
        private readonly WmaConverter $converter,
        private readonly string $importDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Folder to convert (default: IMPORT_DIR)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be converted, at which bitrate; change nothing')
            ->addOption('bitrate', null, InputOption::VALUE_REQUIRED, 'AAC bitrate: auto (WMA ≥256 kb/s or Lossless → 320, 160-255 → 256, below → 192), or 192, 256, 320', 'auto')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Convert at most this many files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Convert WMA');

        $source = rtrim((string) ($input->getOption('source') ?? $this->importDir), '/');
        $dryRun = (bool) $input->getOption('dry-run');
        $limit = $input->getOption('limit') !== null ? max(1, (int) $input->getOption('limit')) : null;
        $bitrate = strtolower((string) $input->getOption('bitrate'));
        $forced = null;
        if ($bitrate !== 'auto') {
            $forced = (int) rtrim($bitrate, 'k');
            if (!\in_array($forced, self::BITRATES, true)) {
                $io->error('--bitrate accepts: auto, 192, 256, 320.');

                return Command::FAILURE;
            }
        }

        if (!is_dir($source)) {
            $io->error(\sprintf('Import folder "%s" does not exist (set IMPORT_DIR in .env.local).', $source));

            return Command::FAILURE;
        }
        if (!$dryRun && $this->converter->binaryPath() === null) {
            $io->error('ffmpeg not found: brew install ffmpeg (or set FFMPEG_PATH in .env.local).');

            return Command::FAILURE;
        }

        $files = $this->converter->findWma($source);
        if ($limit !== null) {
            $files = \array_slice($files, 0, $limit);
        }
        $io->definitionList(['From' => $source], ['Originals to' => $source . '/' . WmaConverter::ORIGINALS_DIR . ($dryRun ? '  (dry run: nothing converted or moved)' : '')]);
        if ($files === []) {
            $io->success('No WMA file to convert.');

            return Command::SUCCESS;
        }

        $stats = ['converted' => 0, 'failed' => 0];
        foreach ($files as $file) {
            $name = substr($file, \strlen($source) + 1);
            try {
                $plan = $this->converter->plan($file, $forced);
                $how = \sprintf('AAC %d kb/s, from %s', $plan['kbps'], $plan['lossless'] ? 'WMA Lossless' : ($plan['sourceKbps'] !== null ? $plan['sourceKbps'] . ' kb/s' : 'unknown bitrate'));
                if (!$dryRun) {
                    $this->converter->convert($file, $source, $forced);
                }
                $io->writeln(\sprintf('  <info>✓</info> %s → %s <fg=gray>[%s]</>', $name, basename($plan['target']), $how));
                ++$stats['converted'];
            } catch (\RuntimeException $e) {
                $io->writeln(\sprintf('  <error>!</error> %s — %s', $name, $e->getMessage()));
                ++$stats['failed'];
            }
        }

        $io->newLine();
        $io->success(\sprintf('%s — %d converted, %d failed.', $dryRun ? 'Dry run complete' : 'Conversion complete', $stats['converted'], $stats['failed']));
        if (!$dryRun && $stats['converted'] > 0) {
            $io->text('Next: php bin/console app:import:files');
        }

        return $stats['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
