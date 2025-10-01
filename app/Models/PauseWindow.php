<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PauseWindow extends Model
{
    use HasFactory;

    protected $fillable = [
        'starts_at',
        'ends_at',
        'timezone',
        'enabled',
    ];

    protected $casts = [
        'starts_at' => 'integer',
        'ends_at'   => 'integer',
        'enabled'   => 'boolean',
    ];
}
