<?php

namespace App\Command\Artist;

use App\Entity\ArtistProfile;
use App\Repository\ArtistProfileRepository;
use App\Repository\MusicRepository;
use App\Service\Artist\ArtistProfileUpdater;
use App\Service\Enrichment\EnrichmentException;
use Doctrine\ORM\EntityManagerInterface;
use SimpleCronScheduler\Attribute\AsCronTask;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Artist pages: finds each artist of the library on MusicBrainz, then its
 * photo, Wikipedia summary, YouTube channel and other links (Wikidata,
 * Wikipedia). Artists already done are skipped. Then run
 * `app:cron:artist-videos` for their latest YouTube videos.
 */
#[AsCommand(name: 'app:cron:artist-info', description: 'Fetch photo, Wikipedia summary, YouTube channel and links of the library artists')]
#[AsCronTask('0 3 * * *', timeout: 3000, description: 'Nightly: info and discography of the new artists')]
class ArtistFetchCommand extends Command
{
    public function __construct(
        private readonly MusicRepository $music,
        private readonly ArtistProfileRepository $profiles,
        private readonly ArtistProfileUpdater $updater,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('artist', null, InputOption::VALUE_REQUIRED, 'Only this artist (exact name, as in the tags); always refetched')
            ->addOption('retry', null, InputOption::VALUE_NONE, 'Also retry the artists not found last time')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Refetch every artist, even those already found')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'At most this many artists');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Artist pages');

        $only = $input->getOption('artist');
        $retry = (bool) $input->getOption('retry');
        $refresh = (bool) $input->getOption('refresh');
        $limit = $input->getOption('limit') !== null ? max(1, (int) $input->getOption('limit')) : null;

        $artists = $this->music->artistAlbums();
        if (\is_string($only)) {
            if (!isset($artists[$only])) {
                $io->error(\sprintf('No artist "%s" in the library.', $only));

                return Command::FAILURE;
            }
            $artists = [$only => $artists[$only]];
        }
        $known = $this->profiles->allByName();

        $stats = ['found' => 0, 'not found' => 0, 'failed' => 0, 'skipped' => 0];
        $done = 0;
        foreach ($artists as $name => $albums) {
            $profile = $known[$name] ?? null;
            $wanted = \is_string($only) || $refresh || $profile === null || ($retry && !$profile->isFound());
            // Found before the discography (or its popularity) existed: only that part is fetched.
            $discographyOnly = !$wanted && $profile !== null && $profile->isFound() && $profile->needsDiscography();
            if ((!$wanted && !$discographyOnly) || ($limit !== null && $done >= $limit)) {
                ++$stats['skipped'];
                continue;
            }
            ++$done;

            if ($discographyOnly) {
                $ok = $this->updater->refreshDiscography($profile);
                $this->em->flush();
                $io->writeln($ok
                    ? \sprintf('  <info>✓</info> %s <fg=gray>[discography: %d albums/EPs]</>', $name, \count($profile->getDiscography() ?? []))
                    : \sprintf('  <error>!</error> %s — discography: MusicBrainz unreachable', $name));
                ++$stats[$ok ? 'found' : 'failed'];
                continue;
            }

            try {
                $profile = $this->updater->refreshInfo((string) $name, $albums);
                $this->em->flush();
            } catch (EnrichmentException $e) {
                $io->writeln(\sprintf('  <error>!</error> %s — %s', $name, $e->getMessage()));
                ++$stats['failed'];
                continue;
            }

            if ($profile->getStatus() === ArtistProfile::NOT_FOUND) {
                $io->writeln(\sprintf('  <fg=gray>· %s — not found on MusicBrainz</>', $name));
                ++$stats['not found'];
                continue;
            }
            $has = array_filter([
                $profile->getImageUrl() !== null ? 'photo' : null,
                $profile->getWikipediaUrl() !== null ? 'Wikipedia' : null,
                $profile->getYoutubeChannelId() !== null ? 'YouTube' : null,
                \count($profile->getLinks()) > 1 ? (\count($profile->getLinks()) - 1) . ' link(s)' : null,
                $profile->getDiscography() !== null ? \count($profile->getDiscography()) . ' albums/EPs' : null,
            ]);
            $io->writeln(\sprintf('  <info>✓</info> %s <fg=gray>[%s]</>', $name, $has !== [] ? implode(', ', $has) : 'nothing more'));
            ++$stats['found'];
        }

        $io->newLine();
        $io->success(\sprintf('%d found, %d not found, %d failed, %d skipped (already done).', $stats['found'], $stats['not found'], $stats['failed'], $stats['skipped']));
        if ($stats['found'] > 0) {
            $io->text('Next: php bin/console app:cron:artist-videos');
        }
        if ($stats['skipped'] > 0) {
            $io->text('Artists already done are skipped: --retry for those not found, --refresh for all.');
        }

        return Command::SUCCESS;
    }
}
