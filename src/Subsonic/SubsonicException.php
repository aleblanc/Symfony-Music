<?php

namespace App\Subsonic;

/**
 * A Subsonic API error, rendered as `status="failed"` with this code (still
 * HTTP 200, as the protocol requires).
 *
 * Codes: 0 generic, 10 required parameter missing, 40 wrong credentials,
 * 50 not authorized, 70 data not found.
 */
final class SubsonicException extends \RuntimeException
{
    public const GENERIC = 0;
    public const MISSING_PARAMETER = 10;
    public const NOT_AUTHORIZED = 50;
    public const NOT_FOUND = 70;

    public function __construct(int $code, string $message)
    {
        parent::__construct($message, $code);
    }

    public static function notFound(string $what): self
    {
        return new self(self::NOT_FOUND, $what . ' not found');
    }

    public static function missing(string $param): self
    {
        return new self(self::MISSING_PARAMETER, "Required parameter is missing: {$param}");
    }
}
