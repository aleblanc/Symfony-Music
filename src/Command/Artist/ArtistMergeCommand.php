<?php

namespace App\Command\Artist;

use App\Repository\ArtistProfileRepository;
use App\Repository\MusicRepository;
use App\Service\Artist\ArtistInfoFetcher;
use App\Service\Artist\ArtistMerger;
use App\Service\MetadataWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Merges the spellings of one artist ("Blink 182", "Blink182", "blink‐182")
 * into a single name: rewrites the artist (and album artist) tags of the
 * files, then the database. Files are not moved or renamed. Only files
 * inside MUSIC_DIR are touched; tracks still indexed elsewhere (e.g. the
 * import folder) are listed and left alone.
 */
#[AsCommand(name: 'app:artist:merge', description: 'Merge the spellings of an artist (Blink 182 / Blink182 / blink-182) into one name, tags included')]
class ArtistMergeCommand extends Command
{
    public function __construct(
        private readonly MusicRepository $music,
        private readonly ArtistProfileRepository $profiles,
        private readonly ArtistMerger $merger,
        private readonly MetadataWriter $writer,
        private readonly EntityManagerInterface $em,
        private readonly string $musicDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the groups and what would be rewritten; change nothing')
            ->addOption('artist', null, InputOption::VALUE_REQUIRED, 'Only the group of this artist (any of its spellings)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Name to keep for that group, instead of the most used spelling (with --artist; e.g. --to=blink-182)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Merge artist spellings');

        $dryRun = (bool) $input->getOption('dry-run');
        $only = $input->getOption('artist');
        $to = $input->getOption('to');
        if (\is_string($to) && !\is_string($only)) {
            $io->error('--to needs --artist (the group to rename).');

            return Command::FAILURE;
        }

        $counts = $this->music->artistTrackCounts();
        $groups = $this->merger->groups($counts);
        if (\is_string($only)) {
            $key = ArtistInfoFetcher::normalize($only);
            $groups = array_values(array_filter($groups, static fn(array $g) => $g['key'] === $key));
            if ($groups === [] && \is_string($to) && isset($counts[$only])) {
                // A single spelling renamed with --to.
                $groups = [['key' => $key, 'canonical' => $only, 'variants' => [$only => $counts[$only]]]];
            }
            if ($groups === []) {
                $io->error(\sprintf('No other spelling of "%s" in the library.', $only));

                return Command::FAILURE;
            }
        }
        if (\is_string($to)) {
            $to = trim($to);
            if ($to === '' || ArtistInfoFetcher::normalize($to) !== $groups[0]['key']) {
                $io->error(\sprintf('"%s" is not a spelling of the same artist.', $to));

                return Command::FAILURE;
            }
            $groups[0]['canonical'] = $to;
        }
        if ($groups === []) {
            $io->success('No artist with several spellings.');

            return Command::SUCCESS;
        }

        $library = rtrim((string) realpath($this->musicDir) ?: $this->musicDir, '/') . '/';
        $stats = ['tracks' => 0, 'failed' => 0, 'outside' => 0];
        foreach ($groups as $group) {
            $canonical = $group['canonical'];
            $others = array_diff_key($group['variants'], [$canonical => true]);
            $io->writeln(\sprintf(
                '<info>%s</info> (%d) ← %s',
                $canonical,
                $group['variants'][$canonical] ?? 0,
                implode(', ', array_map(static fn($n, $c) => \sprintf('%s (%d)', $n, $c), array_keys($others), $others)),
            ));

            $names = array_map('strval', array_keys($group['variants']));
            foreach ($this->music->findByArtistOrAlbumArtist($names) as $song) {
                $fields = [];
                if ($song->getArtist() !== null && $song->getArtist() !== $canonical && \in_array($song->getArtist(), $names, true)) {
                    $fields['artist'] = $canonical;
                }
                if ($song->getAlbumArtist() !== null && $song->getAlbumArtist() !== $canonical && \in_array($song->getAlbumArtist(), $names, true)) {
                    $fields['albumArtist'] = $canonical;
                }
                if ($fields === []) {
                    continue;
                }

                $file = (string) $song->getFile();
                $shown = str_starts_with($file, $library) ? substr($file, \strlen($library)) : $file;
                if (!str_starts_with((string) (realpath($file) ?: $file), $library)) {
                    $io->writeln(\sprintf('    <comment>-</comment> %s <fg=gray>— outside MUSIC_DIR, left alone (app:import it, or app:scan to drop it if gone)</>', $shown));
                    ++$stats['outside'];
                    continue;
                }
                if ($dryRun) {
                    $io->writeln(\sprintf('    <fg=gray>%s</> %s', implode(' + ', array_keys($fields)), $shown));
                    ++$stats['tracks'];
                    continue;
                }

                $ext = $this->writer->detectFormat($file) ?? strtolower(pathinfo($file, \PATHINFO_EXTENSION));
                if (!is_file($file) || !$this->writer->canWrite($ext) || !$this->writer->writeTags($file, $fields)) {
                    $io->writeln(\sprintf('    <error>!</error> %s — %s', $shown, is_file($file) ? ($this->writer->lastError() ?? 'tags not writable for .' . $ext) : 'file missing'));
                    ++$stats['failed'];
                    continue;
                }
                if (isset($fields['artist'])) {
                    $song->setArtist($canonical);
                }
                if (isset($fields['albumArtist'])) {
                    $song->setAlbumArtist($canonical);
                }
                clearstatcache(true, $file);
                if (($mtime = filemtime($file)) !== false) {
                    $song->setLastModified($mtime);
                }
                ++$stats['tracks'];
            }

            if (!$dryRun) {
                $this->mergeProfiles($canonical, $names);
                $this->em->flush();
            }
        }

        $io->newLine();
        $io->success(\sprintf(
            '%s — %d group(s), %d track(s) %s, %d failed, %d outside MUSIC_DIR.',
            $dryRun ? 'Dry run complete' : 'Merge complete',
            \count($groups),
            $stats['tracks'],
            $dryRun ? 'to rewrite' : 'rewritten',
            $stats['failed'],
            $stats['outside'],
        ));
        if ($dryRun) {
            $io->text('Another name for a group: --artist="Blink 182" --to="blink-182". Files are not moved or renamed.');
        }

        return Command::SUCCESS;
    }

    /**
     * One artist page profile for the merged name: the canonical one if any,
     * else the first variant's, renamed; the others are dropped.
     *
     * @param list<string> $names
     */
    private function mergeProfiles(string $canonical, array $names): void
    {
        $keep = $this->profiles->findOneByName($canonical);
        foreach ($names as $name) {
            if ($name === $canonical || ($profile = $this->profiles->findOneByName($name)) === null) {
                continue;
            }
            if ($keep === null && $profile->isFound()) {
                $profile->rename($canonical);
                $keep = $profile;
            } else {
                $this->em->remove($profile);
            }
        }
    }
}
