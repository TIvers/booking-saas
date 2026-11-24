<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleException extends Model
{
    protected $fillable = [
        'staff_id',
        'date',
        'is_day_off',
        'start_time',
        'end_time',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_day_off' => 'boolean',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
