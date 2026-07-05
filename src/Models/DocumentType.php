<?php

namespace MadeByClowd\Documentable\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentType extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'max_size_mb',
        'allowed_mimes',
        'disk',
        'path_prefix',
        'requires_versioning',
        'allows_multiple',
        'max_versions_to_keep',
    ];

    protected $casts = [
        'allowed_mimes' => 'array',
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
