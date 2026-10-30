<?php

namespace App\Command;

use App\Repository\MusicRepository;
use App\Service\SpectrumAnalyzer;
use SimpleCronScheduler\Attribute\AsCronTask;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/**
 * Finds the inflated bitrates: where each track's treble stops
 * (SpectrumAnalyzer, three 8 s slices decoded by ffmpeg) and, when that
 * cutoff is a sharp wall lower than any genuine encode at the declared
 * bitrate, its real bitrate (a "320" MP3 cut at 17 kHz is a 128 one). Only
 * the tracks never analysed; a track whose audio changes is analysed again
 * after the next scan. Saved as it goes: a stopped run goes on next time.
 */
#[AsCommand(name: 'app:cron:spectrum', description: 'Find the inflated bitrates (treble cutoff of each track, ffmpeg)')]
#[AsCronTask('40 */2 * * *', timeout: 3000, description: 'Every 2 hours, after the scan: treble cutoff of the new tracks')]
class SpectrumCommand extends Command
{
    public function __construct(
        private readonly MusicRepository $music,
        private readonly SpectrumAnalyzer $analyzer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Only this track id (always analysed again)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'At most N tracks')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Analyse every track again')
            ->addOption('parallel', null, InputOption::VALUE_REQUIRED, 'ffmpeg processes at once', '4');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Treble cutoff (inflated bitrates)');

        $rows = $this->music->spectrumCandidates(
            all: (bool) $input->getOption('all'),
            limit: $input->getOption('limit') !== null ? max(1, (int) $input->getOption('limit')) : null,
            id: $input->getOption('id') !== null ? (int) $input->getOption('id') : null,
        );
        if ($rows === []) {
            $io->success('Every track is already analysed.');

            return Command::SUCCESS;
        }
        $parallel = max(1, (int) $input->getOption('parallel'));
        $io->text(\sprintf('%d track(s), %d at once.', \count($rows), $parallel));

        $stats = ['genuine' => 0, 'inflated' => 0, 'failed' => 0, 'missing' => 0];
        /** @var list<array{row: array{id: int, file: string, duration: ?int, format: string, lossless: ?bool, bitrate: ?int}, process: Process}> $running */
        $running = [];
        $queue = $rows;
        $started = microtime(true);
        $io->progressStart(\count($rows));
        while ($queue !== [] || $running !== []) {
            while ($queue !== [] && \count($running) < $parallel) {
                $row = array_shift($queue);
                if (!is_file($row['file'])) {
                    // Not saved: a disk not mounted right now must not mark the whole library.
                    ++$stats['missing'];
                    $this->line($io, \sprintf('<fg=red>!</> #%d %s — file missing, skipped (tried again next run)', $row['id'], OutputFormatter::escape($row['file'])));
                    $io->progressAdvance();
                    continue;
                }
                try {
                    $running[] = ['row' => $row, 'process' => $this->analyzer->start($row['file'], $row['duration'])];
                } catch (\RuntimeException $e) {
                    $io->progressFinish();
                    $io->error($e->getMessage());

                    return Command::FAILURE;
                }
            }
            foreach ($running as $i => ['row' => $row, 'process' => $process]) {
                if ($process->isRunning()) {
                    continue;
                }
                unset($running[$i]);
                $this->save($row, $this->analyzer->result($process), $stats, $io, $output);
                $io->progressAdvance();
            }
            $running = array_values($running);
            usleep(5_000);
        }
        $io->progressFinish();

        $io->success(\sprintf('%d track(s) in %.0f s: %d genuine, %d inflated (real bitrate lower than declared), %d not decodable, %d missing.',
            \count($rows), microtime(true) - $started, $stats['genuine'], $stats['inflated'], $stats['failed'], $stats['missing']));

        return Command::SUCCESS;
    }

    /**
     * @param array{id: int, file: string, duration: ?int, format: string, lossless: ?bool, bitrate: ?int} $row
     * @param ?array{cutoffHz: int, sharp: bool}                                                           $result
     * @param array{genuine: int, inflated: int, failed: int, missing: int}                               $stats
     */
    private function save(array $row, ?array $result, array &$stats, SymfonyStyle $io, OutputInterface $output): void
    {
        $label = \sprintf('#%d %s', $row['id'], OutputFormatter::escape(basename($row['file'])));
        if ($result === null) {
            $this->music->saveSpectrum($row['id'], 0, null);
            ++$stats['failed'];
            $this->line($io, '<fg=red>!</> ' . $label . ' — could not be decoded');

            return;
        }
        $effective = SpectrumAnalyzer::effectiveKbps($row['format'], $row['lossless'], $row['bitrate'], $result['cutoffHz'], $result['sharp']);
        $this->music->saveSpectrum($row['id'], $result['cutoffHz'], $effective);
        $declared = $row['lossless'] === true || \in_array($row['format'], ['flac', 'alac', 'wav', 'aiff'], true) ? 'lossless' : round(($row['bitrate'] ?? 0) / 1000) . ' kb/s';
        if ($effective !== null) {
            ++$stats['inflated'];
            $this->line($io, \sprintf('<comment>▼</comment> %s — %s declared, cut at %.1f kHz: really ≈ %d kb/s', $label, $declared, $result['cutoffHz'] / 1000, $effective));
        } else {
            ++$stats['genuine'];
            if ($output->isVerbose()) {
                $this->line($io, \sprintf('<info>✓</info> %s — %s, %s at %.1f kHz', $label, $declared, $result['sharp'] ? 'cut' : 'treble up', $result['cutoffHz'] / 1000));
            }
        }
    }

    private function line(SymfonyStyle $io, string $message): void
    {
        $io->writeln('');
        $io->writeln('  ' . $message);
    }
}
