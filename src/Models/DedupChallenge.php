<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $user_id
 * @property string $document_type_id
 * @property string|null $storage_file_id
 * @property string $scope_key
 * @property string $nonce
 * @property list<array{offset: int, length: int}> $ranges
 * @property int $attempts
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read DocumentType $documentType
 * @property-read StorageFile|null $storageFile
 */
class DedupChallenge extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'document_type_id',
        'storage_file_id',
        'scope_key',
        'nonce',
        'ranges',
        'attempts',
        'expires_at',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'ranges' => 'array',
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DocumentType, $this>
     */
    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    /**
     * @return BelongsTo<StorageFile, $this>
     */
    public function storageFile(): BelongsTo
    {
        return $this->belongsTo(StorageFile::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isDead(): bool
    {
        return $this->attempts >= 3;
    }
}
