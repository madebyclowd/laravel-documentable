<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use MadeByClowd\Documentable\Enums\UploadKind;
use MadeByClowd\Documentable\Enums\UploadSessionStatus;

/**
 * The server-side record of one upload in progress. Its id is the only handle a client ever holds: the
 * storage path and the provider upload id stay on the server.
 *
 * @property string $id
 * @property string|null $user_id
 * @property string $document_type_id
 * @property string $disk
 * @property UploadKind $kind
 * @property string $path
 * @property string|null $upload_id
 * @property int|null $declared_size
 * @property string|null $declared_mime
 * @property string|null $declared_hash
 * @property UploadSessionStatus $status
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property string|null $storage_file_id
 * @property Carbon $expires_at
 *
 * @api Read-only. Change sessions through UploadSessionRepository so the lifecycle rules apply.
 */
class UploadSession extends Model
{
    use HasUlids;

    protected $fillable = [
        'user_id',
        'document_type_id',
        'disk',
        'kind',
        'path',
        'upload_id',
        'declared_size',
        'declared_mime',
        'declared_hash',
        'status',
        'owner_type',
        'owner_id',
        'storage_file_id',
        'expires_at',
    ];

    protected $attributes = [
        'status' => 'initiated',
    ];

    protected function casts(): array
    {
        return [
            'kind' => UploadKind::class,
            'status' => UploadSessionStatus::class,
            'declared_size' => 'integer',
            'expires_at' => 'datetime',
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
}
