<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use MadeByClowd\Documentable\Enums\OnDeleteLatest;
use MadeByClowd\Documentable\Models\DocumentType;

/**
 * @extends Factory<DocumentType>
 */
class DocumentTypeFactory extends Factory
{
    protected $model = DocumentType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'TYPE_'.strtoupper(Str::random(8)),
            'name' => 'Document Type '.Str::random(5),
            'max_size_mb' => 10,
            'allowed_mimes' => ['application/pdf', 'image/png', 'image/jpeg'],
            'allowed_extensions' => ['pdf', 'png', 'jpg', 'jpeg'],
            'trust_extension_for_generic_mime' => false,
            'disk' => 's3',
            'path_prefix' => 'uploads',
            'requires_versioning' => false,
            'allows_multiple' => false,
            'versions_kept' => null,
            'versions_retain_days' => null,
            'on_delete_latest' => OnDeleteLatest::Promote,
        ];
    }
}
