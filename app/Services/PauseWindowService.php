<?php

namespace App\Services;

use App\Models\PauseWindow;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

class PauseWindowService
{
    private const CACHE_KEY = 'pause-window-config';
    private const CACHE_TTL = 60; // seconds

    public function current(bool $useCache = true): PauseWindowSettings
    {
        if ($useCache) {
            return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => $this->resolveSettings());
        }

        return $this->resolveSettings();
    }

    public function refreshCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->current();
    }

    public function isPaused(?CarbonInterface $now = null): bool
    {
        $settings = $this->current();
        if (!$settings->enabled) {
            return false;
        }

        $now = $now ? CarbonImmutable::instance($now) : CarbonImmutable::now($settings->timezone);

        [$start, $end] = $this->resolveWindowBounds($settings, $now);

        return $now->greaterThanOrEqualTo($start) && $now->lessThan($end);
    }

    public function secondsUntilResume(?CarbonInterface $now = null): int
    {
        if (!$this->isPaused($now)) {
            return 0;
        }

        $settings = $this->current();
        $now      = $now ? CarbonImmutable::instance($now) : CarbonImmutable::now($settings->timezone);

        [, $end] = $this->resolveWindowBounds($settings, $now);

        return max($now->diffInSeconds($end, false), 0);
    }

    public function nextPauseAt(?CarbonInterface $now = null): ?CarbonImmutable
    {
        $settings = $this->current();
        if (!$settings->enabled) {
            return null;
        }

        $now = $now ? CarbonImmutable::instance($now) : CarbonImmutable::now($settings->timezone);

        [$start] = $this->upcomingWindowBounds($settings, $now);

        return $start;
    }

    public function nextResumeAt(?CarbonInterface $now = null): ?CarbonImmutable
    {
        $settings = $this->current();
        if (!$settings->enabled) {
            return null;
        }

        $now = $now ? CarbonImmutable::instance($now) : CarbonImmutable::now($settings->timezone);

        if ($this->isPaused($now)) {
            [, $end] = $this->resolveWindowBounds($settings, $now);

            return $end;
        }

        [, $end] = $this->upcomingWindowBounds($settings, $now);

        return $end;
    }

    private function resolveSettings(): PauseWindowSettings
    {
        $record = PauseWindow::query()->first();

        if (!$record) {
            return $this->defaultSettings();
        }

        return new PauseWindowSettings(
            enabled: (bool) $record->enabled,
            startTime: $this->minutesToTimeString($record->starts_at),
            endTime: $this->minutesToTimeString($record->ends_at),
            timezone: $record->timezone ?: config('app.timezone', 'Asia/Kolkata'),
        );
    }

    private function defaultSettings(): PauseWindowSettings
    {
        $config = config('pause-window.default');

        return new PauseWindowSettings(
            enabled: (bool) ($config['enabled'] ?? false),
            startTime: $config['start'] ?? '01:00',
            endTime: $config['end'] ?? '08:00',
            timezone: $config['timezone'] ?? config('app.timezone', 'Asia/Kolkata'),
        );
    }

    private function minutesToTimeString(int $minutes): string
    {
        $minutes = max(0, min($minutes, 1439));

        $hours = intdiv($minutes, 60);
        $mins  = $minutes % 60;

        return sprintf('%02d:%02d', $hours, $mins);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolveWindowBounds(PauseWindowSettings $settings, CarbonImmutable $now): array
    {
        $start = $now->setTimeFromTimeString($settings->startTime);
        $end   = $now->setTimeFromTimeString($settings->endTime);

        if (!$settings->spansMidnight()) {
            return [$start, $end];
        }

        if ($now->lessThan($end)) {
            $start = $start->subDay();
        } else {
            $end = $end->addDay();
        }

        return [$start, $end];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function upcomingWindowBounds(PauseWindowSettings $settings, CarbonImmutable $now): array
    {
        $start = $now->setTimeFromTimeString($settings->startTime);
        $end   = $now->setTimeFromTimeString($settings->endTime);

        if (!$settings->spansMidnight()) {
            if ($now->lessThanOrEqualTo($start)) {
                return [$start, $end];
            }

            return [$start->addDay(), $end->addDay()];
        }

        $currentWindowEnd = $end->addDay();

        if ($now->lessThan($start)) {
            return [$start, $currentWindowEnd];
        }

        if ($now->lessThan($currentWindowEnd)) {
            return [$start->addDay(), $currentWindowEnd->addDay()];
        }

        return [$start->addDay(), $currentWindowEnd->addDay()];
    }
}

class PauseWindowSettings
{
    public function __construct(
        public readonly bool $enabled,
        public readonly string $startTime,
        public readonly string $endTime,
        public readonly string $timezone,
    ) {}

    public function getStartMinutes(): int
    {
        return $this->timeStringToMinutes($this->startTime);
    }

    public function getEndMinutes(): int
    {
        return $this->timeStringToMinutes($this->endTime);
    }

    public function getDurationMinutes(): int
    {
        $start = $this->getStartMinutes();
        $end   = $this->getEndMinutes();

        if ($this->spansMidnight()) {
            return (1440 - $start) + $end;
        }

        return $end - $start;
    }

    private function timeStringToMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return ($hours * 60) + $minutes;
    }

    public function spansMidnight(): bool
    {
        return $this->getStartMinutes() >= $this->getEndMinutes();
    }

}
