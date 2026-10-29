<?php

namespace App\Command\Artist;

use App\Entity\ArtistProfile;
use App\Repository\ArtistProfileRepository;
use App\Service\Artist\SongkickClient;
use App\Service\Artist\SongkickRefusedException;
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
 * Reads the upcoming concerts of every artist found on MusicBrainz from its
 * public Songkick calendar (no API key): the Songkick id comes from the
 * MusicBrainz links (looked up once), then one page per artist, slowly. The
 * artists checked longest ago go first, so a run stopped by Songkick goes on
 * where it stopped next time. Dates announced since the last visit of the
 * artist page are flagged "new" in the web pages.
 */
#[AsCommand(name: 'app:cron:artist-concerts', description: 'Fetch the upcoming concerts of the artists (public Songkick calendars, no API key)')]
#[AsCronTask('0 4,18 * * *', timeout: 3600, description: 'Nightly: upcoming concerts')]
class ArtistConcertsCommand extends Command
{
    public function __construct(
        private readonly ArtistProfileRepository $profiles,
        private readonly SongkickClient $songkick,
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
            ->addOption('pause', null, InputOption::VALUE_REQUIRED, 'Seconds between two Songkick pages', '4')
            ->addOption('relookup', null, InputOption::VALUE_NONE, 'Look the Songkick id up again on MusicBrainz for the artists that had none');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Upcoming concerts');

        $relookup = (bool) $input->getOption('relookup');
        $profiles = array_values(array_filter($this->profiles->findAll(), static fn (ArtistProfile $p): bool => $p->isFound() && $p->getMbid() !== null
            && ($p->getSongkickId() !== '' || $relookup)));
        $only = $input->getOption('artist');
        if (\is_string($only)) {
            $profiles = array_values(array_filter($profiles, static fn (ArtistProfile $p): bool => $p->getName() === $only));
        }
        // Never checked first, then the oldest checks.
        usort($profiles, static fn (ArtistProfile $a, ArtistProfile $b): int => ($a->getConcertsCheckedAt()?->getTimestamp() ?? 0) <=> ($b->getConcertsCheckedAt()?->getTimestamp() ?? 0));
        if ($input->getOption('limit') !== null) {
            $profiles = \array_slice($profiles, 0, max(1, (int) $input->getOption('limit')));
        }
        if ($profiles === []) {
            $io->warning('No artist to check: run php bin/console app:cron:artist-info first.');

            return Command::SUCCESS;
        }
        $pause = max(0, (int) $input->getOption('pause'));
        $io->text(\sprintf('%d artist(s), one Songkick page every %d s.', \count($profiles), $pause));

        $stats = ['checked' => 0, 'withConcerts' => 0, 'concerts' => 0, 'new' => 0, 'noSongkick' => 0, 'failed' => 0];
        $refused = false;
        $first = true;
        foreach ($profiles as $profile) {
            $label = $profile->getName();
            if ($profile->getSongkickId() === null || ($relookup && $profile->getSongkickId() === '')) {
                usleep(1_100_000); // MusicBrainz: 1 request per second
                $id = $this->songkick->songkickId((string) $profile->getMbid());
                if ($id === null) {
                    $io->writeln(\sprintf('  <error>!</error> %s — MusicBrainz unreachable', OutputFormatter::escape($label)));
                    ++$stats['failed'];
                    continue;
                }
                $profile->setSongkickId($id);
                $this->em->flush();
            }
            if ($profile->getSongkickId() === '') {
                ++$stats['noSongkick'];
                if ($output->isVerbose()) {
                    $io->writeln(\sprintf('  <fg=gray>· %s — no Songkick link on MusicBrainz</>', OutputFormatter::escape($label)));
                }
                continue;
            }

            if (!$first && $pause > 0) {
                sleep($pause);
            }
            $first = false;
            try {
                $concerts = $this->songkick->upcomingConcerts((string) $profile->getSongkickId());
            } catch (SongkickRefusedException $e) {
                $io->writeln(\sprintf('  <comment>!</comment> %s — %s: stopped here, the next run starts again from this artist', OutputFormatter::escape($label), OutputFormatter::escape($e->getMessage())));
                $refused = true;
                break;
            } catch (\RuntimeException $e) {
                $io->writeln(\sprintf('  <error>!</error> %s — %s', OutputFormatter::escape($label), OutputFormatter::escape($e->getMessage())));
                ++$stats['failed'];
                continue;
            }
            $now = $this->clock->now();
            $profile->setConcerts($concerts, $now);
            $this->em->flush();
            ++$stats['checked'];
            $stats['concerts'] += \count($concerts);
            $new = \count($profile->newConcerts($now));
            $stats['new'] += $new;
            if ($concerts !== []) {
                ++$stats['withConcerts'];
                $next = $concerts[0];
                $io->writeln(\sprintf('  <info>♪</info> %s — %d concert(s)%s, next: %s %s, %s', OutputFormatter::escape($label), \count($concerts), $new > 0 ? \sprintf(' (<info>%d new</info>)', $new) : '',
                    $next['date'], OutputFormatter::escape($next['title']), OutputFormatter::escape((string) ($next['city'] ?? ''))));
            } elseif ($output->isVerbose()) {
                $io->writeln(\sprintf('  <fg=gray>· %s — no upcoming concert</>', OutputFormatter::escape($label)));
            }
        }

        $summary = \sprintf('%d artist(s) checked: %d on tour (%d concerts, %d new), %d without a Songkick link, %d failed.', $stats['checked'], $stats['withConcerts'], $stats['concerts'], $stats['new'], $stats['noSongkick'], $stats['failed']);
        $refused ? $io->warning($summary . ' Stopped: Songkick refused the requests, try again later.') : $io->success($summary);

        return Command::SUCCESS;
    }
}
