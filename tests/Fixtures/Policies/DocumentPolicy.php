<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Fixtures\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use MadeByClowd\Documentable\Models\Document;

/**
 * A policy an app registers for Document: user 1 may see and delete everything, user 2 only documents of the
 * type named in `$visibleType`. Counts how often each method runs so a test can tell a batch from a loop.
 */
class DocumentPolicy
{
    public static ?string $visibleType = null;

    /** @var array<string, int> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$visibleType = null;
        self::$calls = [];
    }

    private function record(string $method): void
    {
        self::$calls[$method] = (self::$calls[$method] ?? 0) + 1;
    }

    private function sees(Authenticatable $user, Document $document): bool
    {
        return $user->getAuthIdentifier() === 1 || $document->document_type_id === self::$visibleType;
    }

    public function view(Authenticatable $user, Document $document): bool
    {
        $this->record('view');

        return $this->sees($user, $document);
    }

    public function delete(Authenticatable $user, Document $document): bool
    {
        $this->record('delete');

        return $user->getAuthIdentifier() === 1;
    }

    public function attach(Authenticatable $user, Document $source, Model $owner): bool
    {
        $this->record('attach');

        return $user->getAuthIdentifier() === 1;
    }

    /**
     * @param  Collection<int, Document>  $documents
     * @return Collection<int, Document>
     */
    public function viewMany(Authenticatable $user, Collection $documents): Collection
    {
        $this->record('viewMany');

        return $documents->filter(fn (Document $document) => $this->sees($user, $document))->values();
    }
}
