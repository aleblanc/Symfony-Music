<?php

namespace App\Command\Artist;

use App\Entity\CityGeo;
use App\Repository\ArtistProfileRepository;
use App\Repository\CityGeoRepository;
use App\Service\Artist\NominatimClient;
use App\Service\Artist\NominatimRefusedException;
use Doctrine\ORM\EntityManagerInterface;
use SimpleCronScheduler\Attribute\AsCronTask;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Locates the cities of the upcoming concerts (app:cron:artist-concerts) for
 * the concerts map, with OpenStreetMap Nominatim (no API key): only the
 * cities never asked, one request per second as its usage policy wants.
 * A city Nominatim does not know is remembered too and only asked again
 * with --retry.
 */
#[AsCommand(name: 'app:cron:concert-geocode', description: 'Locate the cities of the upcoming concerts for the map (OpenStreetMap Nominatim, no API key)')]
#[AsCronTask('15 5 * * *', timeout: 3000, description: 'Nightly, after the concerts: locate their new cities')]
class ConcertGeocodeCommand extends Command
{
    public function __construct(
        private readonly ArtistProfileRepository $profiles,
        private readonly CityGeoRepository $cities,
        private readonly NominatimClient $nominatim,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'At most this many cities')
            ->addOption('retry', null, InputOption::VALUE_NONE, 'Ask again for the cities Nominatim did not find');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Concert cities');

        $known = $this->cities->byPlace();
        $retry = (bool) $input->getOption('retry');
        $today = $this->clock->now()->format('Y-m-d');
        /** @var array<string, array{city: string, country: ?string, countryCode: ?string}> $todo */
        $todo = [];
        foreach ($this->profiles->findAll() as $profile) {
            foreach ($profile->getConcerts() as $concert) {
                $place = CityGeo::place($concert);
                if ($place === null || $concert['date'] < $today || isset($todo[$place])
                    || (isset($known[$place]) && ($known[$place]->getLat() !== null || !$retry))) {
                    continue;
                }
                $todo[$place] = ['city' => trim((string) $concert['city']), 'country' => $concert['country'], 'countryCode' => $concert['countryCode']];
            }
        }
        if ($input->getOption('limit') !== null) {
            $todo = \array_slice($todo, 0, max(1, (int) $input->getOption('limit')), true);
        }
        if ($todo === []) {
            $io->success('Every concert city is already located.');

            return Command::SUCCESS;
        }
        $io->text(\sprintf('%d cit%s to locate, one Nominatim request per second.', \count($todo), \count($todo) > 1 ? 'ies' : 'y'));

        $stats = ['found' => 0, 'unknown' => 0, 'failed' => 0];
        $refused = false;
        foreach ($todo as $place => $where) {
            $label = $where['city'] . ', ' . ($where['countryCode'] ?? $where['country'] ?? '?');
            usleep(1_100_000);
            try {
                $found = $this->nominatim->locate($where['city'], $where['countryCode'], $where['country'], 1_100_000);
            } catch (NominatimRefusedException $e) {
                $io->writeln(\sprintf('  <comment>!</comment> %s — %s: stopped here, the next run goes on', OutputFormatter::escape($label), OutputFormatter::escape($e->getMessage())));
                $refused = true;
                break;
            } catch (\RuntimeException $e) {
                $io->writeln(\sprintf('  <error>!</error> %s — %s', OutputFormatter::escape($label), OutputFormatter::escape($e->getMessage())));
                ++$stats['failed'];
                continue;
            }
            [$lat, $lng] = $found ?? [null, null];
            $now = $this->clock->now();
            if (isset($known[$place])) {
                $known[$place]->update($lat, $lng, $now);
            } else {
                $this->em->persist(new CityGeo($place, $lat, $lng, $now));
            }
            $this->em->flush();
            if ($found !== null) {
                ++$stats['found'];
                if ($output->isVerbose()) {
                    $io->writeln(\sprintf('  <info>✓</info> %s — %.4f, %.4f', OutputFormatter::escape($label), $lat, $lng));
                }
            } else {
                ++$stats['unknown'];
                $io->writeln(\sprintf('  <fg=gray>· %s — not found</>', OutputFormatter::escape($label)));
            }
        }

        $summary = \sprintf('%d cit%s located, %d not found, %d failed.', $stats['found'], $stats['found'] > 1 ? 'ies' : 'y', $stats['unknown'], $stats['failed']);
        $refused ? $io->warning($summary . ' Stopped: Nominatim refused the requests, try again later.') : $io->success($summary);

        return Command::SUCCESS;
    }
}
