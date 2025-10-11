<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class FailedJob extends Model
{
    protected $table = 'failed_jobs';

    public $timestamps = false;

    protected $casts = [
        'failed_at' => 'datetime',
    ];

    /**
     * Decode the raw payload JSON into an array.
     *
     * @return array<string, mixed>
     */
    public function payloadAsArray(): array
    {
        $decoded = json_decode($this->payload ?? '', true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Resolve a human-friendly display name for the failed job.
     */
    public function displayName(): string
    {
        $payload = $this->payloadAsArray();
        $display = $payload['displayName']
            ?? Arr::get($payload, 'data.commandName')
            ?? Arr::get($payload, 'job');

        if (is_string($display) && $display !== '') {
            return $display;
        }

        $class = Arr::get($payload, 'data.command');
        if (is_string($class) && Str::contains($class, 'class')) {
            return Str::after($class, 'class');
        }

        return 'Unknown Job';
    }

    /**
     * Extract the underlying class name from the payload, if present.
     */
    public function jobClass(): ?string
    {
        $payload = $this->payloadAsArray();
        $commandName = Arr::get($payload, 'data.commandName');

        if (is_string($commandName) && $commandName !== '') {
            return $commandName;
        }

        $commandSerialized = Arr::get($payload, 'data.command');
        if (is_string($commandSerialized) && $commandSerialized !== '') {
            $parts = explode('"', $commandSerialized);

            foreach ($parts as $index => $segment) {
                if ($segment === 'class' && isset($parts[$index + 2])) {
                    return (string) $parts[$index + 2];
                }
            }
        }

        $jobName = Arr::get($payload, 'job');
        if (is_string($jobName) && $jobName !== '') {
            return $jobName;
        }

        return null;
    }

    /**
     * Return the first line of the exception for quick display.
     */
    public function exceptionHeadline(): string
    {
        return Str::of((string) $this->exception)->before("\n")->trim()->value() ?: 'No exception details.';
    }
}
