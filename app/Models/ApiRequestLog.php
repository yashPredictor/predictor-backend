<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiRequestLog extends Model
{
    protected $fillable = [
        'job_key',
        'run_id',
        'tag',
        'method',
        'host',
        'path',
        'url',
        'status_code',
        'is_error',
        'duration_ms',
        'response_bytes',
        'response_body',
        'exception_class',
        'exception_message',
        'requested_at',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'is_error' => 'boolean',
    ];
}
