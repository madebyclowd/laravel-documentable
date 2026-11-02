<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Doctor;

/**
 * The immutable result of executing a single doctor probe.
 *
 * @internal
 */
final readonly class ProbeResult
{
    public function __construct(
        public ProbeStatus $status,
        public string $name,
        public string $message,
        public ?string $fix = null,
    ) {}

    public static function pass(string $name, string $message): self
    {
        return new self(ProbeStatus::Pass, $name, $message);
    }

    public static function fail(string $name, string $message, string $fix): self
    {
        return new self(ProbeStatus::Fail, $name, $message, $fix);
    }

    public static function skipped(string $name, string $message, ?string $fix = null): self
    {
        return new self(ProbeStatus::Skipped, $name, $message, $fix);
    }
}
