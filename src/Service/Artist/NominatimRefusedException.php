<?php

namespace App\Service\Artist;

/** Nominatim refuses the request (403, 429): stop asking for this run. */
final class NominatimRefusedException extends \RuntimeException
{
}
