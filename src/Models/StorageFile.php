<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MadeByClowd\Documentable\Database\Factories\StorageFileFactory;

/**
 * @property string $id
 * @property string $file_hash
 * @property string|null $content_hash
 * @property string $disk
 * @property string $path
 * @property string $mime_type
 * @property int $size_bytes
 * @property array<string, mixed>|null $metadata
 */
class StorageFile extends Model
{
    /** @use HasFactory<StorageFileFactory> */
    use HasFactory, HasUuids;

    protected static function newFactory(): StorageFileFactory
    {
        return StorageFileFactory::new();
    }

    protected $fillable = [
        'file_hash',
        'content_hash',
        'disk',
        'path',
        'mime_type',
        'size_bytes',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'size_bytes' => 'integer',
    ];

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
