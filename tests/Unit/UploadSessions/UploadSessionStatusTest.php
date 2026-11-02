<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Unit\UploadSessions;

use MadeByClowd\Documentable\Enums\UploadSessionStatus;
use MadeByClowd\Documentable\Exceptions\IllegalUploadSessionTransition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UploadSessionStatusTest extends TestCase
{
    private const LEGAL = [
        'initiated' => ['uploaded', 'expired', 'aborted'],
        'uploaded' => ['attached', 'expired', 'aborted'],
        'attached' => [],
        'expired' => [],
        'aborted' => [],
    ];

    /**
     * @return array<string, array{0: UploadSessionStatus, 1: UploadSessionStatus, 2: bool}>
     */
    public static function everyPair(): array
    {
        $pairs = [];

        foreach (UploadSessionStatus::cases() as $from) {
            foreach (UploadSessionStatus::cases() as $to) {
                $pairs["{$from->value} to {$to->value}"] = [$from, $to, in_array($to->value, self::LEGAL[$from->value], true)];
            }
        }

        return $pairs;
    }

    #[DataProvider('everyPair')]
    public function test_transition_rules(UploadSessionStatus $from, UploadSessionStatus $to, bool $legal): void
    {
        $this->assertSame($legal, $from->canTransitionTo($to));

        if ($legal) {
            $this->assertSame($to, $from->transitionTo($to));

            return;
        }

        $this->expectException(IllegalUploadSessionTransition::class);
        $this->expectExceptionMessage("{$from->value} to {$to->value}");

        $from->transitionTo($to);
    }

    public function test_terminal_states_are_exactly_attached_expired_and_aborted(): void
    {
        $terminal = array_values(array_map(
            fn (UploadSessionStatus $s) => $s->value,
            array_filter(UploadSessionStatus::cases(), fn (UploadSessionStatus $s) => $s->isTerminal())
        ));

        $this->assertEqualsCanonicalizing(['attached', 'expired', 'aborted'], $terminal);
    }

    public function test_open_states_are_the_ones_the_reaper_may_expire(): void
    {
        $open = array_values(array_map(
            fn (UploadSessionStatus $s) => $s->value,
            array_filter(UploadSessionStatus::cases(), fn (UploadSessionStatus $s) => $s->isOpen())
        ));

        $this->assertEqualsCanonicalizing(['initiated', 'uploaded'], $open);
    }
}
