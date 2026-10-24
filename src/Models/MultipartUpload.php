<?php

namespace MadeByClowd\Documentable\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $path
 * @property string $upload_id
 * @property string $user_id
 * @property string $document_type_id
 * @property Carbon|null $expires_at
 */
class MultipartUpload extends Model
{
    use HasUuids;

    protected $fillable = [
        'path',
        'upload_id',
        'user_id',
        'document_type_id',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
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
}
