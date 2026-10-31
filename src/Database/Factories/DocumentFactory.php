<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use MadeByClowd\Documentable\Enums\DocumentStatus;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\StorageFile;

/**
 * Factory for creating Document models in tests.
 *
 * The default document is NOT latest unless asked, and latest() for a group
 * that already has a latest is the caller's problem.
 *
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'storage_file_id' => StorageFile::factory(),
            'document_type_id' => DocumentType::factory(),
            'document_group_id' => (string) Str::uuid(),
            'documentable_type' => 'test_model',
            'documentable_id' => (string) Str::uuid(),
            'client_filename' => 'document.pdf',
            'metadata' => [
                'size' => 1024,
                'mime_type' => 'application/pdf',
            ],
            'version' => 1,
            'is_latest' => false,
            'latest_marker' => null,
            'status' => DocumentStatus::Committed,
            'expires_at' => null,
            'created_by' => null,
            'deleted_by' => null,
        ];
    }

    public function committed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DocumentStatus::Committed,
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DocumentStatus::Processing,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DocumentStatus::Rejected,
        ]);
    }

    public function superseded(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_latest' => false,
            'latest_marker' => null,
        ]);
    }

    /**
     * The current version of its group or slot. The marker is the type id for a single-slot type and the group id
     * for an `allows_multiple` type, as `DocumentAttacher` writes it.
     */
    public function latest(): static
    {
        return $this->state(fn (array $attributes) => ['is_latest' => true])
            ->afterMaking(function (Document $document) {
                $type = DocumentType::find($document->document_type_id);

                $document->latest_marker = $type?->allows_multiple ? $document->document_group_id : $document->document_type_id;
            });
    }
}
