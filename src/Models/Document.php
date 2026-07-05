<?php

namespace MadeByClowd\Documentable\Models;

use Illuminate\Database\Eloquent\Builder;
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
        'document_group_id',
        'documentable_type',
        'documentable_id',
        'client_filename',
        'metadata',
        'version',
        'is_latest',
        'latest_marker',
        'status',
        'expires_at',
        'created_by',
        'deleted_by',
    ];

    protected $casts = [
        'metadata' => 'array',
        'version' => 'integer',
        'is_latest' => 'boolean',
        'expires_at' => 'datetime',
    ];

    /**
     * Transition a 'pending' document to permanent 'committed' status,
     * clearing its expiry — the explicit alternative to inferring lifecycle
     * intent from a nullable documentable_id (bugs.md #5 / best-practices.md
     * §2). Consumer calls this once the document is actually attached to its
     * real owner; if never called before expires_at lapses, the
     * documents:clean-orphaned reaper purges it.
     */
    public function commit(): static
    {
        $this->update(['status' => 'committed', 'expires_at' => null]);

        return $this;
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

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
