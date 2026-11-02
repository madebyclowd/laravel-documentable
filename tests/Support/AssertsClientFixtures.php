<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Support;

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

/**
 * Asserts HTTP responses match committed client contract fixtures in resources/client/fixtures/.
 *
 * Volatile values (ULIDs, UUIDs, ISO timestamps, presigned URLs, nonces, ranges) are replaced
 * with stable placeholders so the fixtures serve as a reproducible source of truth across
 * PHP test suites and the TypeScript client helper.
 */
trait AssertsClientFixtures
{
    /**
     * @param  TestResponse|array<string, mixed>  $json
     */
    public function assertMatchesClientFixture(string $name, TestResponse|array $json): void
    {
        $data = $json instanceof TestResponse ? $json->json() : $json;

        $normalized = $this->normalizeClientFixtureData($data);
        $normalized = $this->sortFixtureKeysRecursively($normalized);

        $fixturePath = dirname(__DIR__, 2).'/resources/client/fixtures/'.$name.'.json';

        $shouldUpdate = (string) (getenv('UPDATE_CLIENT_FIXTURES') ?: ($_ENV['UPDATE_CLIENT_FIXTURES'] ?? ($_SERVER['UPDATE_CLIENT_FIXTURES'] ?? ''))) === '1';

        if ($shouldUpdate) {
            $dir = dirname($fixturePath);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents(
                $fixturePath,
                (string) json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
            );
        }

        Assert::assertFileExists(
            $fixturePath,
            "Fixture file `{$fixturePath}` does not exist. Run with UPDATE_CLIENT_FIXTURES=1 to generate it."
        );

        $expectedJson = (string) file_get_contents($fixturePath);
        $expected = json_decode($expectedJson, true);

        Assert::assertSame(
            $expected,
            $normalized,
            "Response for `{$name}` does not match client fixture in `resources/client/fixtures/{$name}.json`."
        );
    }

    private function normalizeClientFixtureData(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            if ($key === 'ranges' && ! empty($value) && is_array($value[0] ?? null)) {
                return [
                    [
                        'length' => '<length>',
                        'offset' => '<offset>',
                    ],
                ];
            }

            $result = [];
            foreach ($value as $k => $v) {
                $result[$k] = $this->normalizeClientFixtureData($v, is_string($k) ? $k : null);
            }

            return $result;
        }

        if (! is_string($value)) {
            return $value;
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://') || str_starts_with($value, 'fake://')) {
            return '<url>';
        }

        if ($key === 'nonce' || $key === 'challenge_nonce') {
            return '<nonce>';
        }

        if (Str::isUuid($value) || Str::isUlid($value)) {
            return '<id>';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})$/', $value)) {
            return '<timestamp>';
        }

        return $value;
    }

    private function sortFixtureKeysRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $k => $v) {
            $result[$k] = $this->sortFixtureKeysRecursively($v);
        }

        if (! array_is_list($result)) {
            ksort($result, SORT_STRING);
        }

        return $result;
    }
}
