<?php

namespace MadeByClowd\Documentable\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'storage_file_id',
        'document_type_id',
        'documentable_type',
        'documentable_id',
        'client_filename',
        'metadata',
        'version',
        'is_latest',
    ];

    protected $casts = [
        'metadata' => 'array',
        'version' => 'integer',
        'is_latest' => 'boolean',
    ];

    public function storageFile(): BelongsTo
    {
        return $this->belongsTo(StorageFile::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }
}
