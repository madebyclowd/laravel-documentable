<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use MadeByClowd\Documentable\Database\Factories\DocumentTypeFactory;
use MadeByClowd\Documentable\Enums\OnDeleteLatest;

/**
 * @property string $id
 * @property string $code
 * @property string $name
 * @property int $max_size_mb
 * @property array<int, string>|null $allowed_mimes
 * @property array<int, string>|null $allowed_extensions
 * @property bool $trust_extension_for_generic_mime
 * @property string $disk
 * @property string $path_prefix
 * @property bool $requires_versioning
 * @property bool $allows_multiple
 * @property int|null $versions_kept
 * @property int|null $versions_retain_days
 * @property OnDeleteLatest $on_delete_latest
 */
class DocumentType extends Model
{
    /** @use HasFactory<DocumentTypeFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected static function newFactory(): DocumentTypeFactory
    {
        return DocumentTypeFactory::new();
    }

    protected $attributes = [
        'on_delete_latest' => 'promote',
    ];

    protected $fillable = [
        'code',
        'name',
        'max_size_mb',
        'allowed_mimes',
        'allowed_extensions',
        'trust_extension_for_generic_mime',
        'disk',
        'path_prefix',
        'requires_versioning',
        'allows_multiple',
        'versions_kept',
        'versions_retain_days',
        'on_delete_latest',
    ];

    protected $casts = [
        'allowed_mimes' => 'array',
        'allowed_extensions' => 'array',
        'trust_extension_for_generic_mime' => 'boolean',
        'max_size_mb' => 'integer',
        'requires_versioning' => 'boolean',
        'allows_multiple' => 'boolean',
        'versions_kept' => 'integer',
        'versions_retain_days' => 'integer',
        'on_delete_latest' => OnDeleteLatest::class,
    ];

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
