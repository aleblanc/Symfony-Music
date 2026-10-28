<?php

declare(strict_types=1);

namespace App\Translation;

use Symfony\Component\Translation\TranslatableMessage;

/**
 * A translatable label that still reads as plain English when cast to a string.
 *
 * Twig prints it translated with `|trans`; non-Twig callers (console commands,
 * logs, sprintf('%s')) keep getting the English text they always had.
 */
final class LabelMessage extends TranslatableMessage implements \Stringable
{
    /**
     * @param string               $key        translation key (domain "messages")
     * @param string               $english    English text returned by __toString()
     * @param array<string, mixed> $parameters ICU parameters
     */
    public function __construct(string $key, private readonly string $english, array $parameters = [])
    {
        parent::__construct($key, $parameters);
    }

    public function __toString(): string
    {
        return $this->english;
    }
}
