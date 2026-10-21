<?php

namespace App\Service\Lyrics;

/** LRCLIB unreachable or failing: the run must stop, not mark tracks as "not found". */
class LyricsException extends \RuntimeException
{
}
