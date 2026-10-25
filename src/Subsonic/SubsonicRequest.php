<?php

namespace App\Subsonic;

use Symfony\Component\HttpFoundation\Request;

/**
 * Subsonic request parameters. Parses the raw query string (and a form POST
 * body) itself, because Subsonic repeats keys without "[]" —
 * `songIdToAdd=1&songIdToAdd=2` — and PHP's own parsing keeps only the last.
 */
final class SubsonicRequest
{
    /** @var array<string, list<string>> */
    private array $params = [];

    private function __construct(private readonly Request $request) {}

    public static function fromRequest(Request $request): self
    {
        $self = new self($request);
        $sources = [(string) $request->server->get('QUERY_STRING', '')];
        if (str_starts_with((string) $request->headers->get('Content-Type', ''), 'application/x-www-form-urlencoded')) {
            $sources[] = $request->getContent();
        }

        foreach ($sources as $raw) {
            foreach (explode('&', $raw) as $pair) {
                if ($pair === '') {
                    continue;
                }
                [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
                $self->params[urldecode($key)][] = urldecode($value);
            }
        }

        return $self;
    }

    public function get(string $key): ?string
    {
        return $this->params[$key][0] ?? null;
    }

    /** @return list<string> */
    public function getAll(string $key): array
    {
        return $this->params[$key] ?? [];
    }

    /** @throws SubsonicException code 10 when missing or empty */
    public function require(string $key): string
    {
        $value = $this->get($key);
        if ($value === null || $value === '') {
            throw SubsonicException::missing($key);
        }

        return $value;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->get($key);

        return $value !== null && preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        return $value === null ? $default : \in_array(strtolower($value), ['true', '1', 'yes'], true);
    }

    /** @return 'json'|'xml' */
    public function format(): string
    {
        return $this->get('f') === 'json' ? 'json' : 'xml';
    }

    /** Client name (`c` parameter), e.g. "Symfonium". */
    public function client(): string
    {
        return $this->get('c') ?? '';
    }

    public function httpRequest(): Request
    {
        return $this->request;
    }
}
