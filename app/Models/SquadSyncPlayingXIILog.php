<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SquadSyncPlayingXIILog extends Model
{
    public $timestamps = false;

    protected $table = "squad_sync_playing_xii_logs";

    protected $fillable = [
        'run_id',
        'action',
        'status',
        'message',
        'context',
        'created_at',
    ];

    protected $casts = [
        'context'    => 'array',
        'created_at' => 'datetime',
    ];
}
