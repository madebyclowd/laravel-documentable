<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use MadeByClowd\Documentable\Enums\UploadKind;
use MadeByClowd\Documentable\Enums\UploadSessionStatus;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Models\UploadSession;

/**
 * @extends Factory<UploadSession>
 */
class UploadSessionFactory extends Factory
{
    protected $model = UploadSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $hash = hash('sha256', (string) Str::uuid());

        return [
            'user_id' => null,
            'document_type_id' => DocumentType::factory(),
            'disk' => 's3',
            'kind' => UploadKind::Single,
            'client_filename' => 'document.pdf',
            'path' => 'uploads/'.Str::random(40).'.pdf',
            'upload_id' => null,
            'declared_size' => 1024,
            'declared_mime' => 'application/pdf',
            'declared_hash' => $hash,
            'object_size' => null,
            'object_mime' => null,
            'object_hash' => null,
            'status' => UploadSessionStatus::Initiated,
            'owner_type' => null,
            'owner_id' => null,
            'storage_file_id' => null,
            'expires_at' => now()->addHour(),
        ];
    }

    public function initiated(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UploadSessionStatus::Initiated,
        ]);
    }

    public function uploaded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UploadSessionStatus::Uploaded,
            'object_size' => $attributes['declared_size'] ?? 1024,
            'object_mime' => $attributes['declared_mime'] ?? 'application/pdf',
            'object_hash' => $attributes['declared_hash'] ?? hash('sha256', (string) Str::uuid()),
        ]);
    }

    public function attached(): static
    {
        return $this->state(function (array $attributes) {
            $size = $attributes['declared_size'] ?? 1024;
            $mime = $attributes['declared_mime'] ?? 'application/pdf';
            $hash = $attributes['declared_hash'] ?? hash('sha256', (string) Str::uuid());

            return [
                'status' => UploadSessionStatus::Attached,
                'object_size' => $size,
                'object_mime' => $mime,
                'object_hash' => $hash,
                'owner_type' => 'test_model',
                'owner_id' => (string) Str::uuid(),
                'storage_file_id' => StorageFile::factory()->state([
                    'file_hash' => $hash,
                    'content_hash' => $hash,
                    'size_bytes' => $size,
                    'mime_type' => $mime,
                ]),
            ];
        });
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UploadSessionStatus::Expired,
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function aborted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UploadSessionStatus::Aborted,
        ]);
    }
}
