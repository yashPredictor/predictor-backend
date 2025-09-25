<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeriesSyncLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'run_id',
        'action',
        'status',
        'message',
        'context',
        'created_at',
    ];

    protected $casts = [
        'context' => 'array',
        'created_at' => 'datetime',
    ];
}
