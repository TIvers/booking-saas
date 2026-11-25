<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationWorkSchedule extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'location_id',
        'weekday',
        'is_closed',
        'start_time',
        'end_time',
        'lunch_start_time',
        'lunch_end_time',
    ];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'is_closed' => 'boolean',
        ];
    }

    /**
     * @return list<array{
     *     weekday: int,
     *     is_closed: bool,
     *     start_time: ?string,
     *     end_time: ?string,
     *     lunch_start_time: ?string,
     *     lunch_end_time: ?string
     * }>
     */
    public static function defaultWeek(): array
    {
        return array_map(
            static fn (int $weekday): array => [
                'weekday' => $weekday,
                'is_closed' => $weekday > 5,
                'start_time' => $weekday <= 5 ? '10:00' : null,
                'end_time' => $weekday <= 5 ? '18:00' : null,
                'lunch_start_time' => null,
                'lunch_end_time' => null,
            ],
            range(1, 7),
        );
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
