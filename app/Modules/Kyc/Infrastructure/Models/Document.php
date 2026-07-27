<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Infrastructure\Models;

use App\Modules\Kyc\Domain\DocumentStatus;
use App\Modules\Kyc\Domain\DocumentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $organization_id
 * @property DocumentType $type
 * @property DocumentStatus $status
 * @property string $storage_path
 * @property string $file_hash
 */
class Document extends Model
{
    protected $table = 'documents';

    protected $guarded = ['id'];

    /** The path is unguessable by design; do not leak it in API payloads. */
    protected $hidden = ['storage_path'];

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'size_bytes' => 'integer',
            'verified_at' => 'datetime',
            'expires_at' => 'date',
        ];
    }

    /** @param  Builder<self>  $query */
    public function scopeProvided(Builder $query): Builder
    {
        return $query->whereIn('status', [
            DocumentStatus::PENDING->value,
            DocumentStatus::VERIFIED->value,
        ]);
    }
}
