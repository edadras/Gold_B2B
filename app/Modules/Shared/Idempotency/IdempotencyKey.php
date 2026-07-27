<?php

declare(strict_types=1);

namespace App\Modules\Shared\Idempotency;

use Illuminate\Database\Eloquent\Model;

final class IdempotencyKey extends Model
{
    protected $table = 'idempotency_keys';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'response_body' => 'array',
        'locked_at' => 'datetime',
        'created_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
