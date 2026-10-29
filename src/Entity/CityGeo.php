<?php

namespace App\Entity;

use App\Repository\CityGeoRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Where a concert city is (app:cron:concert-geocode, from OpenStreetMap
 * Nominatim), so the concerts map needs no request: one row per city and
 * country as Songkick names them. No coordinates when Nominatim found
 * nothing, so the city is not asked again on every run.
 */
#[ORM\Entity(repositoryClass: CityGeoRepository::class)]
#[ORM\Table(name: 'city_geo')]
#[ORM\UniqueConstraint(name: 'city_geo_place_idx', columns: ['place'])]
class CityGeo
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** CityGeo::place(): lowercased "city|country". */
    #[ORM\Column(length: 320)]
    private string $place;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $lat;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $lng;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $checkedAt;

    public function __construct(string $place, ?float $lat, ?float $lng, \DateTimeImmutable $checkedAt)
    {
        $this->place = mb_substr($place, 0, 320);
        $this->lat = $lat;
        $this->lng = $lng;
        $this->checkedAt = $checkedAt;
    }

    /**
     * The cache key of a concert's city: its country code when Songkick's
     * country name is known, the name otherwise; null without a city.
     *
     * @param array{city: ?string, country: ?string, countryCode: ?string} $concert
     */
    public static function place(array $concert): ?string
    {
        $city = trim((string) $concert['city']);

        return $city === '' ? null : mb_strtolower($city . '|' . ($concert['countryCode'] ?? $concert['country'] ?? ''));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlace(): string
    {
        return $this->place;
    }

    public function getLat(): ?float
    {
        return $this->lat;
    }

    public function getLng(): ?float
    {
        return $this->lng;
    }

    public function getCheckedAt(): \DateTimeImmutable
    {
        return $this->checkedAt;
    }

    public function update(?float $lat, ?float $lng, \DateTimeImmutable $checkedAt): void
    {
        $this->lat = $lat;
        $this->lng = $lng;
        $this->checkedAt = $checkedAt;
    }
}
