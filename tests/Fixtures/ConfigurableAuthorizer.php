<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Fixtures;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\UploadSession;

/**
 * Allows everything except what a test overrides. Each override is keyed by the contract method name and
 * receives that method's arguments, so a test states only the one answer it cares about.
 */
class ConfigurableAuthorizer extends AllowAllAuthorizer
{
    /** @var list<string> */
    public array $asked = [];

    /**
     * @param  array<string, Closure>  $rules
     */
    public function __construct(private array $rules = []) {}

    public static function denying(string ...$methods): self
    {
        return new self(array_fill_keys($methods, fn () => false));
    }

    /**
     * @param  array<string, Closure>  $rules
     */
    public static function with(array $rules): self
    {
        return new self($rules);
    }

    private function answer(string $method, bool $default, mixed ...$arguments): bool
    {
        $this->asked[] = $method;

        return isset($this->rules[$method]) ? (bool) ($this->rules[$method])(...$arguments) : $default;
    }

    public function canInitiateUpload(?Authenticatable $user, DocumentType $type): bool
    {
        return $this->answer(__FUNCTION__, parent::canInitiateUpload($user, $type), $user, $type);
    }

    public function canFinalize(?Authenticatable $user, UploadSession $session): bool
    {
        return $this->answer(__FUNCTION__, parent::canFinalize($user, $session), $user, $session);
    }

    public function canAttach(?Authenticatable $user, UploadSession|Document $source, Model $owner): bool
    {
        return $this->answer(__FUNCTION__, parent::canAttach($user, $source, $owner), $user, $source, $owner);
    }

    public function canView(?Authenticatable $user, Document $document): bool
    {
        return $this->answer(__FUNCTION__, parent::canView($user, $document), $user, $document);
    }

    public function canDelete(?Authenticatable $user, Document $document): bool
    {
        return $this->answer(__FUNCTION__, parent::canDelete($user, $document), $user, $document);
    }

    public function canViewMany(?Authenticatable $user, Collection $documents): Collection
    {
        $this->asked[] = __FUNCTION__;

        if (isset($this->rules[__FUNCTION__])) {
            return ($this->rules[__FUNCTION__])($user, $documents);
        }

        return $documents->filter(fn (Document $document) => $this->canView($user, $document))->values();
    }
}
