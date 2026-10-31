<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use LogicException;
use MadeByClowd\Documentable\Database\Factories\DocumentFactory;
use MadeByClowd\Documentable\Enums\DocumentStatus;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\ValueObjects\UrlOptions;

/**
 * @property string $id
 * @property string $storage_file_id
 * @property string $document_type_id
 * @property string $document_group_id
 * @property string|null $documentable_type
 * @property string|null $documentable_id
 * @property string $client_filename
 * @property array<string, mixed>|null $metadata
 * @property int $version
 * @property bool $is_latest
 * @property string|null $latest_marker
 * @property DocumentStatus $status
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property string|null $created_by
 * @property string|null $deleted_by
 * @property-read string $filename
 * @property-read int $size
 * @property-read string $mime
 * @property-read string $extension
 */
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected static function newFactory(): DocumentFactory
    {
        return DocumentFactory::new();
    }

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
        'status' => DocumentStatus::class,
        'expires_at' => 'datetime',
    ];

    /**
     * @return Attribute<string, never>
     */
    protected function filename(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->client_filename,
        );
    }

    /**
     * @return Attribute<int, never>
     */
    protected function size(): Attribute
    {
        return Attribute::make(
            get: fn (): int => $this->storageFileOrFail()->size_bytes,
        );
    }

    /**
     * @return Attribute<string, never>
     */
    protected function mime(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->storageFileOrFail()->mime_type,
        );
    }

    /**
     * @return Attribute<string, never>
     */
    protected function extension(): Attribute
    {
        return Attribute::make(
            get: fn (): string => strtolower(pathinfo($this->client_filename, PATHINFO_EXTENSION)),
        );
    }

    /**
     * @return BelongsTo<StorageFile, $this>
     */
    public function storageFile(): BelongsTo
    {
        return $this->belongsTo(StorageFile::class);
    }

    /**
     * The document's storage file.
     */
    public function storageFileOrFail(): StorageFile
    {
        return $this->storageFile ?? throw new LogicException("Document {$this->id} has no storage file.");
    }

    /**
     * @return BelongsTo<DocumentType, $this>
     */
    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    /**
     * The document's type. A document is created with one and the foreign key keeps it, so a missing type means
     * the row was tampered with; fail loudly rather than carry a null around.
     */
    public function documentTypeOrFail(): DocumentType
    {
        return $this->documentType ?? throw new LogicException("Document {$this->id} has no document type.");
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Presign a time-limited download URL for this document.
     */
    public function temporaryUrl(DateTimeInterface|UrlOptions|null $options = null): string
    {
        return app(DocumentService::class)->getUrl($this, $options);
    }

    /**
     * Only the current version of each group or slot. Not named `latest`: Eloquent's own `latest()` orders by
     * `created_at` and would always win over a scope of that name.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeLatestVersion(Builder $query): void
    {
        $query->whereNotNull($query->qualifyColumn('latest_marker'));
    }

    /**
     * Filter by committed status.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeCommitted(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), DocumentStatus::Committed);
    }

    /**
     * Filter by document type code in a single query subquery.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOfType(Builder $query, string $code): void
    {
        $query->whereIn($query->qualifyColumn('document_type_id'), DocumentType::query()->where('code', $code)->select('id'));
    }
}
