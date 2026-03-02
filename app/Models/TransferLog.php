<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class TransferLog extends Model
{
    protected $fillable = [
        'user_id',
        'direction',
        'status',
        'original_name',
        'filename',
        'ftp_path',
        'size_bytes',
        'speed_kbps',
        'started_at',
        'finished_at',
        'client_ip',
        'message',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'speed_kbps' => 'float',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
