<?php

namespace App\Service\Artist;

/** Instagram shows its login wall instead of the profile: stop asking for this run. */
final class LoginWallException extends \RuntimeException
{
}
