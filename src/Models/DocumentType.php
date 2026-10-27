<?php

namespace MadeByClowd\Documentable\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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
 * @property int|null $max_versions_to_keep
 */
class DocumentType extends Model
{
    use HasUuids, SoftDeletes;

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
        'max_versions_to_keep',
    ];

    protected $casts = [
        'allowed_mimes' => 'array',
        'allowed_extensions' => 'array',
        'trust_extension_for_generic_mime' => 'boolean',
        'max_size_mb' => 'integer',
        'requires_versioning' => 'boolean',
        'allows_multiple' => 'boolean',
        'max_versions_to_keep' => 'integer',
    ];

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
