<?php

namespace App\Subsonic;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\Response;

/**
 * A group of Subsonic API methods. Every implementation is picked up by
 * SubsonicController through the tag; methods() says which calls it answers.
 */
#[AutoconfigureTag(self::TAG)]
interface SubsonicHandler
{
    public const TAG = 'app.subsonic_handler';

    /** @return list<string> Subsonic method names, e.g. ['ping', 'getLicense'] */
    public static function methods(): array;

    /**
     * @return array<string, mixed>|Response the payload (without envelope), or
     *                                       a raw response for media endpoints
     *
     * @throws SubsonicException
     */
    public function handle(string $method, SubsonicRequest $request, User $user): array|Response;
}
