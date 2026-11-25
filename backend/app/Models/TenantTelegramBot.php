<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class TenantTelegramBot extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'bot_id',
        'bot_username',
        'bot_token',
        'webhook_secret_hash',
    ];

    protected function casts(): array
    {
        return [
            'bot_token' => 'encrypted',
            'webhook_ready' => 'boolean',
        ];
    }
}
