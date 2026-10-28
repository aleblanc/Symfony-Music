<?php

namespace App\Service\Artist;

/** Songkick refuses the request (403, 406, 429): stop asking for this run. */
final class SongkickRefusedException extends \RuntimeException
{
}
