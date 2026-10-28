<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A business error whose message is meant for the person using the web UI.
 *
 * getMessage() stays English (logs, console commands); the web layer shows
 * the translated text through the translation key + ICU parameters.
 * Extends \RuntimeException so existing `catch (\RuntimeException)` blocks keep working.
 */
class UserFacingException extends \RuntimeException implements TranslatableInterface
{
    /**
     * @param string               $message    English text, for logs and the CLI
     * @param string               $key        translation key (domain "messages")
     * @param array<string, mixed> $parameters ICU parameters
     */
    public function __construct(
        string $message,
        private readonly string $key,
        private readonly array $parameters = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getKey(): string
    {
        return $this->key;
    }

    /** @return array<string, mixed> */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->toMessage()->trans($translator, $locale);
    }

    /** A serializable message (safe to store in a flash bag, unlike the exception itself). */
    public function toMessage(): TranslatableMessage
    {
        return new TranslatableMessage($this->key, $this->parameters);
    }

    /**
     * The message to show for any caught error: the translated text of a
     * UserFacingException, otherwise the raw (technical, English) message
     * wrapped in "Error: {error}".
     */
    public static function messageFor(\Throwable $e): TranslatableMessage
    {
        return $e instanceof self ? $e->toMessage() : self::wrap($e, 'flash.common.error');
    }

    /**
     * Wraps any caught error in a translated message (e.g. "Not banned: {error}"):
     * `{error}` receives the translated text of a UserFacingException, or the raw
     * (technical, English) message of any other exception.
     *
     * @param array<string, mixed> $parameters extra ICU parameters of the wrapper
     */
    public static function wrap(\Throwable $e, string $wrapperKey, array $parameters = []): TranslatableMessage
    {
        return new TranslatableMessage($wrapperKey, ['error' => $e instanceof self ? $e->toMessage() : $e->getMessage()] + $parameters);
    }
}
