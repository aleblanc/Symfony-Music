<?php

namespace App\Command;

use App\Entity\Music;
use App\Repository\MusicRepository;
use App\Service\Spotify\PublicCatalog;
use App\Service\Spotify\SpotifyException;
use App\Service\Spotify\SpotifyMatcher;
use App\Service\Spotify\SpotifyResolver;
use App\Service\Spotify\SpotifyTagWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Links library tracks to their exact open.spotify.com page, without any
 * Spotify account (MusicBrainz + Wikidata + public embed pages, see
 * {@see SpotifyResolver}). Stores the Spotify id in the database and, with
 * --write-tags, the URL in the MP3's WOAF frame.
 *
 * Coverage is partial: unmatched songs keep the "Find on Spotify" search
 * button. Re-runnable: by default only tracks never looked up are processed,
 * so a cron after each `app:scan` only handles new files.
 */
#[AsCommand(name: 'app:spotify:match', description: 'Find the open.spotify.com link of each track (DB, optionally file tags)')]
class SpotifyMatchCommand extends Command
{
    private const BATCH_SIZE = 20;

    public function __construct(
        private readonly MusicRepository $musicRepository,
        private readonly SpotifyResolver $resolver,
        private readonly PublicCatalog $catalog,
        private readonly SpotifyTagWriter $tagWriter,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Only this track id (always re-queried)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Process at most N tracks')
            ->addOption('retry', null, InputOption::VALUE_NONE, 'Also retry tracks previously looked up without a match')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Re-match every track, even already linked ones')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show matches without saving anything')
            ->addOption('write-tags', null, InputOption::VALUE_NONE, 'Also write the URL into the file (MP3 ID3v2 WOAF frame)')
            ->addOption('tags-only', null, InputOption::VALUE_NONE, 'No Spotify lookup: write tags for tracks already linked')
            ->addOption('min-score', null, InputOption::VALUE_REQUIRED, 'Minimum match confidence 0..1', (string) SpotifyMatcher::MIN_SCORE)
            ->addOption('delay', null, InputOption::VALUE_REQUIRED, 'Pause between open.spotify.com page requests, in ms', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $tagsOnly = (bool) $input->getOption('tags-only');
        $writeTags = $tagsOnly || (bool) $input->getOption('write-tags');
        $minScore = (float) $input->getOption('min-score');
        $this->catalog->setEmbedDelay((int) $input->getOption('delay'));
        $limit = $input->getOption('limit') !== null ? max(1, (int) $input->getOption('limit')) : null;
        if ($dryRun && !$output->isVerbose()) {
            // A dry run is for reviewing matches: always list them.
            $output->setVerbosity(OutputInterface::VERBOSITY_VERBOSE);
        }

        $ids = $input->getOption('id') !== null
            ? [(int) $input->getOption('id')]
            : $this->musicRepository->spotifyCandidateIds(
                retryUnmatched: (bool) $input->getOption('retry'),
                all: (bool) $input->getOption('force'),
                matchedOnly: $tagsOnly,
                limit: $limit,
            );

        $io->title($tagsOnly ? 'Spotify links → file tags' : 'Spotify matching');
        $io->text(\sprintf('%d track(s) to process%s.', \count($ids), $dryRun ? ' (dry run, nothing saved)' : ''));
        if ($ids === []) {
            return Command::SUCCESS;
        }

        $stats = ['matched' => 0, 'unmatched' => 0, 'tagged' => 0, 'tag_skipped' => 0, 'errors' => 0];
        $trace = static function (string $message) use ($io): void {
            if ($io->isVeryVerbose()) {
                $io->writeln('');
                $io->writeln('  <fg=gray>… ' . $message . '</>');
            }
        };

        $io->progressStart(\count($ids));
        foreach ($ids as $n => $id) {
            $song = $this->musicRepository->find($id);
            if ($song === null) {
                $io->progressAdvance();
                continue;
            }
            $label = \sprintf('#%d %s – %s', $id, $song->getArtist() ?? '?', $song->getDisplayTitle());

            if (!$tagsOnly) {
                try {
                    $match = $this->resolver->resolve($song, $minScore, $trace);
                } catch (SpotifyException $e) {
                    $io->progressFinish();
                    $io->error($e->getMessage());
                    $this->em->flush();

                    return Command::FAILURE;
                }

                if ($match !== null) {
                    ++$stats['matched'];
                    $song->setSpotifyId($match->track->id);
                    $this->line($io, \sprintf(
                        '<info>✓</info> %s → %s – %s <comment>(%d%%, %s)</comment> %s',
                        $label,
                        implode(', ', $match->track->artists),
                        $match->track->name,
                        (int) round($match->score * 100),
                        $match->source,
                        $match->track->url(),
                    ));
                } else {
                    ++$stats['unmatched'];
                    $this->line($io, '<fg=yellow>✗</> ' . $label . ' : not found in the public sources');
                }
                if (!$dryRun) {
                    $song->setSpotifyCheckedAt(time());
                }
            }

            if ($writeTags && !$dryRun && $song->getSpotifyUrl() !== null) {
                $this->writeTags($io, $song, $label, $stats);
            }

            $io->progressAdvance();
            if (($n + 1) % self::BATCH_SIZE === 0) {
                $this->flush($dryRun);
            }
        }
        $this->flush($dryRun);
        $io->progressFinish();

        $rows = [];
        if (!$tagsOnly) {
            $rows[] = ['Matched', $stats['matched']];
            $rows[] = ['No confident match', $stats['unmatched']];
        }
        if ($writeTags) {
            $rows[] = ['Tags written', $stats['tagged']];
            $rows[] = ['Tags skipped (non-MP3 / unsafe)', $stats['tag_skipped']];
        }
        $io->table(['Result', 'Tracks'], $rows);
        if ($stats['unmatched'] > 0 && !$dryRun) {
            $io->note('Unmatched tracks keep the "Find on Spotify" search button and are skipped next time (--retry to try again). Paste the exact link in the song\'s "Edit metadata" page to fix one by hand.');
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, int> $stats
     */
    private function writeTags(SymfonyStyle $io, Music $song, string $label, array &$stats): void
    {
        try {
            if ($this->tagWriter->write($song, (string) $song->getSpotifyUrl())) {
                ++$stats['tagged'];
                clearstatcache(true, (string) $song->getFile());
                // The file changed on purpose: keep app:scan from re-reading it.
                $song->setLastModified(filemtime((string) $song->getFile()) ?: $song->getLastModified());
            }
        } catch (\RuntimeException $e) {
            ++$stats['tag_skipped'];
            $this->line($io, '  <fg=gray>tags not written for ' . $label . ': ' . $e->getMessage() . '</>');
        }
    }

    private function line(SymfonyStyle $io, string $message): void
    {
        if ($io->isVerbose()) {
            $io->writeln('');
            $io->writeln($message);
        }
    }

    private function flush(bool $dryRun): void
    {
        if (!$dryRun) {
            $this->em->flush();
        }
        $this->em->clear();
    }
}
