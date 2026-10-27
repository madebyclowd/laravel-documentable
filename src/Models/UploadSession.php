<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;
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
 * @property string $client_filename
 * @property string $path
 * @property string|null $upload_id
 * @property int|null $declared_size
 * @property string|null $declared_mime
 * @property string|null $declared_hash
 * @property int|null $object_size
 * @property string|null $object_mime
 * @property string|null $object_hash
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
        'client_filename',
        'path',
        'upload_id',
        'declared_size',
        'declared_mime',
        'declared_hash',
        'object_size',
        'object_mime',
        'object_hash',
        'status',
        'owner_type',
        'owner_id',
        'storage_file_id',
        'expires_at',
    ];

    /**
     * The path and the provider upload id are server-side details: a client holds the session id and nothing else.
     */
    protected $hidden = ['path', 'upload_id'];

    protected $attributes = [
        'status' => 'initiated',
    ];

    protected function casts(): array
    {
        return [
            'kind' => UploadKind::class,
            'status' => UploadSessionStatus::class,
            'declared_size' => 'integer',
            'object_size' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Whether the stored object was hashed and fully checked. An object above the synchronous limit is
     * `uploaded` first and verified later by a job, so its hash is not known yet.
     */
    public function isVerified(): bool
    {
        return $this->object_hash !== null;
    }

    /**
     * @return BelongsTo<DocumentType, $this>
     */
    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    /**
     * The session's document type. A session is created with one and the foreign key keeps it, so a missing
     * type means the row was tampered with; fail loudly rather than carry a null around.
     */
    public function documentTypeOrFail(): DocumentType
    {
        return $this->documentType ?? throw new LogicException("Upload session {$this->id} has no document type.");
    }

    /**
     * @return BelongsTo<StorageFile, $this>
     */
    public function storageFile(): BelongsTo
    {
        return $this->belongsTo(StorageFile::class);
    }
}
