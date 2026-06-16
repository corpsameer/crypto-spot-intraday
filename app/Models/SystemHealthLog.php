<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SystemHealthLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_name',
        'status',
        'message',
        'checked_at',
        'meta'
    ];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'meta' => 'array'
        ];
    }

}
