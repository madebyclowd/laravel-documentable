<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use MadeByClowd\Documentable\Models\StorageFile;

/**
 * @extends Factory<StorageFile>
 */
class StorageFileFactory extends Factory
{
    protected $model = StorageFile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $hash = hash('sha256', (string) Str::uuid());

        return [
            'file_hash' => $hash,
            'content_hash' => $hash,
            'disk' => 's3',
            'path' => 'uploads/'.Str::random(40).'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'metadata' => null,
        ];
    }
}
