<?php

namespace App\Command\Artist;

use App\Repository\ArtistProfileRepository;
use App\Service\Artist\ArtistProfileUpdater;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reads the YouTube RSS feed (no API key) of every artist whose channel
 * `app:artist:fetch` found, and stores the latest videos. Videos published
 * since the last visit of the artist page are flagged "new" in the web pages.
 * Meant to run regularly (cron).
 */
#[AsCommand(name: 'app:artist:videos', description: 'Fetch the latest YouTube videos of the artists (flagged "new" in the artist pages)')]
class ArtistVideosCommand extends Command
{
    public function __construct(
        private readonly ArtistProfileRepository $profiles,
        private readonly ArtistProfileUpdater $updater,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('artist', null, InputOption::VALUE_REQUIRED, 'Only this artist (exact name, as in the tags)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('YouTube videos');

        $profiles = $this->profiles->findWithYoutube();
        $only = $input->getOption('artist');
        if (\is_string($only)) {
            $profiles = array_values(array_filter($profiles, static fn($p) => $p->getName() === $only));
        }
        if ($profiles === []) {
            $io->warning('No artist with a YouTube channel: run php bin/console app:artist:fetch first.');

            return Command::SUCCESS;
        }

        $withNew = 0;
        $failed = 0;
        foreach ($profiles as $i => $profile) {
            if ($i > 0) {
                usleep(300_000); // gentle with YouTube
            }
            $new = $this->updater->refreshVideos($profile);
            if ($new === null) {
                $io->writeln(\sprintf('  <error>!</error> %s — feed unreachable', $profile->getName()));
                ++$failed;
            } elseif ($new > 0) {
                $io->writeln(\sprintf('  <info>★</info> %s — %d new video(s): %s', $profile->getName(), $new, $profile->getVideos()[0]['title'] ?? ''));
                ++$withNew;
            } elseif ($output->isVerbose()) {
                $io->writeln(\sprintf('  <fg=gray>· %s</>', $profile->getName()));
            }
        }
        $this->em->flush();

        $io->success(\sprintf('%d channel(s) checked: %d with new videos, %d unreachable.', \count($profiles), $withNew, $failed));

        return Command::SUCCESS;
    }
}
