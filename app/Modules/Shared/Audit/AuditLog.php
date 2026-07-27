<?php

declare(strict_types=1);

namespace App\Modules\Shared\Audit;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only audit record.
 *
 * The application database user has INSERT and SELECT on this table only;
 * UPDATE and DELETE are revoked at the database level in production
 * (docs/02-architecture/04-security.md §4.7). The guards here are a second
 * line of defence, not the primary one.
 */
final class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'audit_logs';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'occurred_at' => 'datetime',
        'before_state' => 'array',
        'after_state' => 'array',
    ];

    protected static function booted(): void
    {
        self::updating(static fn () => throw new LogicException('audit_logs is append-only'));
        self::deleting(static fn () => throw new LogicException('audit_logs is append-only'));
    }
}
