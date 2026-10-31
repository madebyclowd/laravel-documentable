<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Upgrade;

/**
 * One line-level check of the {@see CodeScanner}. `{match}` in the message becomes the first capture group.
 *
 * @internal
 */
final readonly class Rule
{
    public const ANY_FILE = 'any';

    public const CONFIG_ONLY = 'config';

    public const DOCUMENT_FILES = 'documents';

    public const SERVICE_SUBCLASSES = 'subclass';

    public function __construct(
        public string $id,
        public string $pattern,
        public string $message,
        public string $replacement,
        public string $anchor,
        public string $scope = self::ANY_FILE,
    ) {}
}
