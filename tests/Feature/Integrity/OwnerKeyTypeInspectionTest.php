<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Integrity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use MadeByClowd\Documentable\Config\DocumentableConfig;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Tests\TestCase;

class OwnerKeyTypeInspectionTest extends TestCase
{
    use BuildsIntegrityFixtures;
    use RefreshDatabase;

    private function useKeyType(string $keyType): void
    {
        config()->set('documentable.key_type', $keyType);
        $this->app->forgetInstance(DocumentableConfig::class);
    }

    public function test_a_string_key_type_accepts_any_owner_key(): void
    {
        $document = $this->latestDocument($this->singleSlotType(), $this->owner());
        Document::whereKey($document->id)->update(['documentable_id' => 'anything-goes']);

        $this->artisan('documents:upgrade-check')->assertExitCode(0);
    }

    public function test_an_int_key_type_flags_owner_keys_that_are_not_integers_and_names_the_documents(): void
    {
        $type = $this->singleSlotType();
        $fits = $this->latestDocument($type, $this->owner());
        $misfit = $this->latestDocument($this->multiSlotType(), $this->owner());
        Document::whereKey($misfit->id)->update(['documentable_id' => (string) Str::uuid()]);
        $this->useKeyType('int');

        $this->artisan('documents:upgrade-check')
            ->expectsOutputToContain($misfit->id)
            ->expectsOutputToContain("key_type 'int'")
            ->assertExitCode(1);

        $this->assertNotNull($fits);
    }

    public function test_it_is_not_repairable_so_repair_keeps_failing(): void
    {
        $document = $this->latestDocument($this->singleSlotType(), $this->owner());
        Document::whereKey($document->id)->update(['documentable_id' => 'abc']);
        $this->useKeyType('int');

        $this->artisan('documents:repair', ['--apply' => true])->assertExitCode(1);
    }

    public function test_uuid_and_ulid_keys_are_checked_by_shape(): void
    {
        $document = $this->latestDocument($this->singleSlotType(), $this->owner());
        Document::whereKey($document->id)->update(['documentable_id' => (string) Str::uuid()]);

        $this->useKeyType('uuid');
        $this->artisan('documents:upgrade-check')->assertExitCode(0);

        $this->useKeyType('ulid');
        $this->artisan('documents:upgrade-check')->assertExitCode(1);
    }
}
