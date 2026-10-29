<?php

namespace App\Command\Artist;

use App\Entity\ArtistProfile;
use App\Repository\ArtistProfileRepository;
use App\Service\Artist\InstagramClient;
use App\Service\Artist\LoginWallException;
use App\Service\Browser\ChromePipe;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use SimpleCronScheduler\Attribute\AsCronTask;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reads the public Instagram profile (no account, no API key) of every
 * artist whose links (app:cron:artist-info) have one, in Chrome, slowly, and
 * keeps the latest posts (ArtistProfile::MAX_POSTS). The artists checked
 * longest ago go first, so a run cut short by Instagram's login wall goes on
 * where it stopped next time. Posts published since the last visit of the
 * artist page are flagged "new" in the web pages.
 */
#[AsCommand(name: 'app:cron:artist-posts', description: 'Fetch the latest Instagram posts of the artists (public profiles, in Chrome, no account)')]
#[AsCronTask('0 6,12,20 * * *', args: ['--limit=100'], timeout: 3600, description: 'Nightly: Instagram posts of the 100 artists checked longest ago (needs Chrome)')]
class ArtistPostsCommand extends Command
{
    public function __construct(
        private readonly ArtistProfileRepository $profiles,
        private readonly InstagramClient $instagram,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('artist', null, InputOption::VALUE_REQUIRED, 'Only this artist (exact name, as in the tags)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'At most this many artists')
            ->addOption('pause', null, InputOption::VALUE_REQUIRED, 'Seconds between two profiles (slow is what keeps the login wall away)', '25')
            ->addOption('chrome', null, InputOption::VALUE_REQUIRED, 'Chrome binary (default: CHROME_PATH env, else the macOS Google Chrome app)')
            ->addOption('show', null, InputOption::VALUE_NONE, 'Show the Chrome window instead of running headless');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Instagram posts');

        $chromeBinary = (string) ($input->getOption('chrome') ?? (getenv('CHROME_PATH') ?: ChromePipe::MAC_CHROME));
        if (!is_file($chromeBinary)) {
            $io->error(\sprintf('Chrome not found at "%s" (use --chrome or CHROME_PATH).', $chromeBinary));

            return Command::FAILURE;
        }

        $profiles = array_values(array_filter($this->profiles->findAll(), static fn (ArtistProfile $p): bool => $p->getInstagramHandle() !== null));
        $only = $input->getOption('artist');
        if (\is_string($only)) {
            $profiles = array_values(array_filter($profiles, static fn (ArtistProfile $p): bool => $p->getName() === $only));
        }
        // Never checked first, then the oldest checks.
        usort($profiles, static fn (ArtistProfile $a, ArtistProfile $b): int => ($a->getPostsCheckedAt()?->getTimestamp() ?? 0) <=> ($b->getPostsCheckedAt()?->getTimestamp() ?? 0));
        if ($input->getOption('limit') !== null) {
            $profiles = \array_slice($profiles, 0, max(1, (int) $input->getOption('limit')));
        }
        if ($profiles === []) {
            $io->warning('No artist with an Instagram link: run php bin/console app:cron:artist-info first.');

            return Command::SUCCESS;
        }
        $pause = max(0, (int) $input->getOption('pause'));
        $io->text(\sprintf('%d profile(s), one every %d s.', \count($profiles), $pause));

        $chrome = new ChromePipe();
        $chrome->start($chromeBinary, !$input->getOption('show'));
        $chrome->openTab();
        $stats = ['checked' => 0, 'withNew' => 0, 'failed' => 0];
        $wall = false;
        try {
            foreach ($profiles as $i => $profile) {
                if ($i > 0 && $pause > 0) {
                    $chrome->pump($pause);
                }
                $handle = (string) $profile->getInstagramHandle();
                $label = \sprintf('%s (@%s)', $profile->getName(), $handle);
                try {
                    $posts = $this->instagram->latestPosts($chrome, $handle);
                } catch (LoginWallException) {
                    $io->writeln(\sprintf('  <comment>!</comment> %s — Instagram shows its login wall: stopped here, the next run starts again from this artist', OutputFormatter::escape($label)));
                    $wall = true;
                    break;
                } catch (\RuntimeException $e) {
                    $io->writeln(\sprintf('  <error>!</error> %s — %s', OutputFormatter::escape($label), OutputFormatter::escape($e->getMessage())));
                    ++$stats['failed'];
                    continue;
                }
                $now = $this->clock->now();
                $profile->setPosts($posts, $now);
                $this->em->flush();
                ++$stats['checked'];
                $new = \count($profile->newPosts($now));
                if ($new > 0) {
                    ++$stats['withNew'];
                    $io->writeln(\sprintf('  <info>★</info> %s — %d new post(s), %d read', OutputFormatter::escape($label), $new, \count($posts)));
                } elseif ($output->isVerbose()) {
                    $io->writeln(\sprintf('  <fg=gray>· %s — %d read</>', OutputFormatter::escape($label), \count($posts)));
                }
            }
        } finally {
            $chrome->stop();
        }
        $kept = [];
        foreach ($this->profiles->findAll() as $profile) {
            array_push($kept, ...array_column($profile->getPosts(), 'id'));
        }
        if (($pruned = $this->instagram->pruneThumbnails($kept)) > 0 && $output->isVerbose()) {
            $io->writeln(\sprintf('  <fg=gray>%d thumbnail(s) of posts no longer kept deleted</>', $pruned));
        }

        $summary = \sprintf('%d profile(s) checked: %d with new posts, %d failed.', $stats['checked'], $stats['withNew'], $stats['failed']);
        $wall ? $io->warning($summary . ' Stopped by the login wall: try again in a few hours.') : $io->success($summary);

        return Command::SUCCESS;
    }
}
