<?php

namespace App\Service\Browser;

/**
 * Minimal headless Chrome driver over the DevTools protocol, no dependency:
 * Chrome is started with --remote-debugging-pipe, so CDP messages are
 * NUL-terminated JSON on fd 3 (to Chrome) and fd 4 (from Chrome).
 */
final class ChromePipe
{
    public const MAC_CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

    /** @var resource|null */
    private $process;
    /** @var array<int, resource> */
    private array $pipes = [];
    private string $buffer = '';
    private int $nextId = 0;
    private ?string $sessionId = null;
    private string $profileDir;
    /** @var list<array<string, mixed>> events received while waiting for responses */
    private array $events = [];
    /** @var \Closure(string): void */
    private \Closure $log;

    /**
     * @param ?\Closure(string): void $log receives one line per CDP message (verbose)
     */
    public function __construct(?\Closure $log = null)
    {
        $this->log = $log ?? static function (string $line): void {};
    }

    public function start(string $binary, bool $headless = true): void
    {
        $this->profileDir = sys_get_temp_dir() . '/chrome-pipe-' . getmypid() . '-' . bin2hex(random_bytes(3));
        @mkdir($this->profileDir, 0o777, true);
        $cmd = [$binary, '--remote-debugging-pipe', '--user-data-dir=' . $this->profileDir, '--no-first-run',
            '--no-default-browser-check', '--disable-gpu', '--window-size=1280,1024', 'about:blank'];
        if ($headless) {
            $cmd[] = '--headless=new';
        }
        if (\function_exists('posix_getuid') && posix_getuid() === 0) {
            $cmd[] = '--no-sandbox';
        }
        ($this->log)('spawn: ' . implode(' ', $cmd));

        $process = proc_open($cmd, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', $this->profileDir . '.stderr.log', 'w'],
            3 => ['pipe', 'r'],
            4 => ['pipe', 'w'],
        ], $this->pipes);
        if (!\is_resource($process)) {
            throw new \RuntimeException('Could not start Chrome: ' . $binary);
        }
        $this->process = $process;
        stream_set_blocking($this->pipes[4], false);
    }

    /** Opens a tab and attaches to it; every later page-level call goes to this tab. */
    public function openTab(): void
    {
        $targetId = $this->send('Target.createTarget', ['url' => 'about:blank'], browser: true)['targetId'];
        $this->sessionId = $this->send('Target.attachToTarget', ['targetId' => $targetId, 'flatten' => true], browser: true)['sessionId'];
        $this->send('Page.enable');
        $this->send('Runtime.enable');
    }

    public function allowDownloads(string $dir): void
    {
        $this->send('Browser.setDownloadBehavior', ['behavior' => 'allowAndName', 'downloadPath' => $dir, 'eventsEnabled' => true], browser: true);
    }

    public function navigate(string $url): void
    {
        $result = $this->send('Page.navigate', ['url' => $url]);
        if (isset($result['errorText'])) {
            throw new \RuntimeException('Navigation failed: ' . $result['errorText']);
        }
    }

    /** Runs a JS expression in the page (promises awaited) and returns its JSON value. */
    public function evaluate(string $expression): mixed
    {
        $result = $this->send('Runtime.evaluate', ['expression' => $expression, 'returnByValue' => true, 'awaitPromise' => true]);
        if (isset($result['exceptionDetails'])) {
            throw new \RuntimeException('JS error: ' . ($result['exceptionDetails']['exception']['description'] ?? $result['exceptionDetails']['text'] ?? 'unknown'));
        }

        return $result['result']['value'] ?? null;
    }

    /**
     * Reads messages for $seconds, returning the events received (plus any
     * buffered while waiting for earlier responses).
     *
     * @return list<array<string, mixed>>
     */
    public function pump(float $seconds): array
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            $this->readMessage(min(0.25, max(0.0, $deadline - microtime(true))));
        }
        $events = $this->events;
        $this->events = [];

        return $events;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function send(string $method, array $params = [], bool $browser = false, float $timeout = 30.0): array
    {
        $id = ++$this->nextId;
        $message = ['id' => $id, 'method' => $method, 'params' => (object) $params];
        if (!$browser && $this->sessionId !== null) {
            $message['sessionId'] = $this->sessionId;
        }
        $json = json_encode($message, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        ($this->log)('→ ' . $json);
        fwrite($this->pipes[3], $json . "\0");
        fflush($this->pipes[3]);

        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $msg = $this->readMessage(0.5);
            if ($msg === null || ($msg['id'] ?? null) !== $id) {
                continue;
            }
            if (isset($msg['error'])) {
                throw new \RuntimeException(\sprintf('%s: %s', $method, $msg['error']['message'] ?? json_encode($msg['error'])));
            }

            return $msg['result'] ?? [];
        }

        throw new \RuntimeException(\sprintf('%s: no answer from Chrome after %ds', $method, $timeout));
    }

    public function stop(): void
    {
        if (\is_resource($this->process)) {
            try {
                $this->send('Browser.close', browser: true, timeout: 3);
            } catch (\Throwable) {
            }
            foreach ($this->pipes as $pipe) {
                @fclose($pipe);
            }
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $this->process = null;
        if (isset($this->profileDir) && is_dir($this->profileDir)) {
            exec('sleep 0.5; rm -rf ' . escapeshellarg($this->profileDir) . ' 2>/dev/null');
        }
    }

    public function stderrLog(): string
    {
        return $this->profileDir . '.stderr.log';
    }

    /** @return array<string, mixed>|null one message, events are queued */
    private function readMessage(float $wait): ?array
    {
        while (($pos = strpos($this->buffer, "\0")) === false) {
            $read = [$this->pipes[4]];
            $write = $except = null;
            $sec = (int) $wait;
            if (@stream_select($read, $write, $except, $sec, (int) (($wait - $sec) * 1e6)) < 1) {
                return null;
            }
            $chunk = fread($this->pipes[4], 1 << 16);
            if ($chunk === '' || $chunk === false) {
                if (feof($this->pipes[4])) {
                    throw new \RuntimeException('Chrome closed the pipe (crashed?), see ' . $this->stderrLog());
                }

                return null;
            }
            $this->buffer .= $chunk;
        }
        $raw = substr($this->buffer, 0, $pos);
        $this->buffer = substr($this->buffer, $pos + 1);
        $msg = json_decode($raw, true);
        if (!\is_array($msg)) {
            return null;
        }
        ($this->log)('← ' . (\strlen($raw) > 400 ? substr($raw, 0, 400) . '…' : $raw));
        if (isset($msg['method'])) {
            $this->events[] = $msg;
        }

        return $msg;
    }
}
