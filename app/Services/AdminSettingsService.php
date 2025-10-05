<?php

namespace App\Services;

use App\Models\AdminSetting;
use Illuminate\Cache\CacheManager;

class AdminSettingsService
{
    private const CACHE_PREFIX = 'admin_settings_cache.';
    private const LOG_CLEANUP_KEY   = 'log_cleanup';
    private const CRICBUZZ_KEY      = 'integrations.cricbuzz';
    private const FIRESTORE_KEY     = 'integrations.firestore';
    private const MIN_RETENTION_DAYS = 5;

    public function __construct(private readonly CacheManager $cache)
    {
    }

    /**
     * Fetch a stored setting as an array payload.
     */
    public function get(string $key, array $default = []): array
    {
        $record = AdminSetting::query()->where('key', $key)->first();

        return is_array($record?->value) ? $record->value : $default;
    }

    public function put(string $key, array $value): void
    {
        AdminSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);

        $this->cache->store()->forget(self::CACHE_PREFIX . $key);
    }

    public function logRetentionDays(): int
    {
        $settings = $this->get(self::LOG_CLEANUP_KEY, ['days' => self::MIN_RETENTION_DAYS]);

        $days = (int) ($settings['days'] ?? self::MIN_RETENTION_DAYS);

        return max(self::MIN_RETENTION_DAYS, min($days, 365));
    }

    public function logRetentionMinutes(): int
    {
        return $this->logRetentionDays() * 24 * 60;
    }

    public function updateLogRetentionDays(int $days): void
    {
        $days = max(self::MIN_RETENTION_DAYS, min($days, 365));

        $this->put(self::LOG_CLEANUP_KEY, ['days' => $days]);
    }

    public function cricbuzzSettings(): array
    {
        $stored = $this->get(self::CRICBUZZ_KEY, []);

        $defaults = [
            'host' => config('services.cricbuzz.host', 'cricbuzz-cricket2.p.rapidapi.com'),
            'key'  => config('services.cricbuzz.key'),
        ];

        return [
            'host' => $this->valueOrDefault($stored['host'] ?? null, $defaults['host']),
            'key'  => $this->valueOrDefault($stored['key'] ?? null, $defaults['key']),
        ];
    }

    public function firestoreSettings(): array
    {
        $stored = $this->get(self::FIRESTORE_KEY, []);

        $defaults = [
            'project_id' => config('services.firestore.project_id'),
            'sa_json'    => config('services.firestore.sa_json'),
        ];

        return [
            'project_id' => $this->valueOrDefault($stored['project_id'] ?? null, $defaults['project_id']),
            'sa_json'    => $this->valueOrDefault($stored['sa_json'] ?? null, $defaults['sa_json']),
        ];
    }

    public function updateCricbuzzSettings(array $settings): void
    {
        $payload = [
            'host' => $this->sanitize($settings['host'] ?? null),
            'key'  => $this->sanitize($settings['key'] ?? null),
        ];

        $this->put(self::CRICBUZZ_KEY, $this->filterEmpty($payload));
    }

    public function updateFirestoreSettings(array $settings): void
    {
        $payload = [
            'project_id' => $this->sanitize($settings['project_id'] ?? null),
            'sa_json'    => $this->sanitize($settings['sa_json'] ?? null),
        ];

        $this->put(self::FIRESTORE_KEY, $this->filterEmpty($payload));
    }

    private function sanitize(?string $value): ?string
    {
        $trimmed = $value !== null ? trim($value) : null;

        return $trimmed === '' ? null : $trimmed;
    }

    private function filterEmpty(array $values): array
    {
        return array_filter(
            $values,
            fn($value) => $value !== null && $value !== ''
        );
    }

    private function valueOrDefault($value, $default)
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return $value;
    }
}
