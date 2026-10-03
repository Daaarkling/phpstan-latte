<?php

declare(strict_types=1);

namespace Efabrica\PHPStanLatte\Analyser;

use function count;
use function error_log;
use function microtime;
use function register_shutdown_function;
use function sprintf;

final class LatteContextProfiler
{
    /** @var array<string, float> */
    private array $durations = [];

    /** @var array<string, int> */
    private array $counts = [];

    /** @var list<string> */
    private array $cacheMisses = [];

    private bool $started = false;

    private bool $shutdownRegistered = false;

    private bool $reported = false;

    public function enter(): void
    {
        if (!$this->started) {
            $this->start();
        }
    }

    public function leave(): void
    {
        $this->started = false;
    }

    public function increment(string $name): void
    {
        $this->counts[$name] = ($this->counts[$name] ?? 0) + 1;
    }

    public function recordDuration(string $name, float $startedAt): void
    {
        $this->durations[$name] = ($this->durations[$name] ?? 0.0) + microtime(true) - $startedAt;
        $this->increment($name);
    }

    public function recordCacheMiss(string $file, string $cacheFile, string $reason): void
    {
        $this->increment('cacheMiss:' . $reason);
        if (count($this->cacheMisses) < 20) {
            $this->cacheMisses[] = sprintf('%s: %s (%s)', $reason, $file, $cacheFile);
        }
    }

    public function stop(): void
    {
        $this->leave();
        if ($this->reported) {
            return;
        }

        foreach ($this->counts as $name => $count) {
            $duration = $this->durations[$name] ?? 0.0;
            error_log(sprintf('[phpstan-latte profiler] %s count=%d time=%.3Fs', $name, $count, $duration));
        }
        foreach ($this->cacheMisses as $cacheMiss) {
            error_log(sprintf('[phpstan-latte profiler] cache miss %s', $cacheMiss));
        }

        $this->reported = true;
    }

    private function start(): void
    {
        $this->started = true;
        if (!$this->shutdownRegistered) {
            register_shutdown_function([$this, 'stop']);
            $this->shutdownRegistered = true;
        }
    }
}
