<?php

namespace App\Subsonic;

use Symfony\Component\HttpFoundation\Response;

/**
 * Wraps a handler payload in the `subsonic-response` envelope, as JSON or XML.
 *
 * Payload conventions (shared by both formats):
 * - null values are dropped;
 * - XML: scalars become attributes, maps become child elements, lists become
 *   repeated elements named after their key, and the key "value" becomes the
 *   element's text (lyrics);
 * - an empty \stdClass is an empty object ({} in JSON, <x/> in XML).
 */
final class SubsonicResponse
{
    public const API_VERSION = '1.16.1';
    private const XMLNS = 'http://subsonic.org/restapi';

    /** @param array<string, mixed> $payload */
    public static function ok(array $payload, string $format): Response
    {
        return self::render(['status' => 'ok'] + self::envelope() + $payload, $format);
    }

    public static function error(int $code, string $message, string $format): Response
    {
        return self::render(['status' => 'failed'] + self::envelope() + ['error' => ['code' => $code, 'message' => $message]], $format);
    }

    /** @return array<string, mixed> */
    private static function envelope(): array
    {
        return ['version' => self::API_VERSION, 'type' => 'symfony-music', 'serverVersion' => '1.0', 'openSubsonic' => true];
    }

    /** @param array<string, mixed> $data */
    private static function render(array $data, string $format): Response
    {
        if ($format === 'json') {
            $json = json_encode(['subsonic-response' => self::clean($data)], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR);

            return new Response($json, 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $root = $doc->createElementNS(self::XMLNS, 'subsonic-response');
        $doc->appendChild($root);
        self::fill($doc, $root, $data);

        return new Response((string) $doc->saveXML(), 200, ['Content-Type' => 'text/xml; charset=UTF-8']);
    }

    /** Recursively drops nulls (kept: empty \stdClass objects). */
    private static function clean(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            if ($v !== null) {
                $out[$k] = self::clean($v);
            }
        }

        return array_is_list($value) ? array_values($out) : $out;
    }

    /** @param array<string, mixed> $map */
    private static function fill(\DOMDocument $doc, \DOMElement $element, array $map): void
    {
        foreach ($map as $key => $value) {
            if ($value === null) {
                continue;
            }
            if ($key === 'value' && !\is_array($value)) {
                $element->appendChild($doc->createTextNode(self::scalar($value)));
            } elseif (\is_scalar($value)) {
                $element->setAttribute((string) $key, self::scalar($value));
            } else {
                self::append($doc, $element, (string) $key, $value);
            }
        }
    }

    private static function append(\DOMDocument $doc, \DOMElement $parent, string $name, mixed $value): void
    {
        if (\is_array($value) && array_is_list($value) && $value !== []) {
            foreach ($value as $item) {
                self::append($doc, $parent, $name, $item);
            }

            return;
        }

        $child = $doc->createElementNS(self::XMLNS, $name);
        $parent->appendChild($child);
        if (\is_scalar($value)) {
            $child->appendChild($doc->createTextNode(self::scalar($value)));
        } elseif (\is_array($value) || $value instanceof \stdClass) {
            self::fill($doc, $child, (array) $value);
        }
    }

    private static function scalar(mixed $value): string
    {
        return \is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }
}
