<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Integrity;

/**
 * One line of a repair plan.
 *
 * @internal
 */
final readonly class RepairStep
{
    public function __construct(
        public RepairAction $action,
        public string $subjectId,
        public ?string $targetId = null,
        public string $description = '',
    ) {}

    public static function keepDocument(string $documentId): self
    {
        return new self(RepairAction::Keep, $documentId, description: "Keep document {$documentId} as the latest of its slot");
    }

    public static function demoteDocument(string $documentId, string $keptDocumentId): self
    {
        return new self(RepairAction::DemoteDocument, $documentId, $keptDocumentId, "Demote document {$documentId} (superseded by {$keptDocumentId})");
    }

    public static function keepStorageFile(string $storageFileId): self
    {
        return new self(RepairAction::Keep, $storageFileId, description: "Keep storage file {$storageFileId}");
    }

    public static function repointStorageFile(string $fromId, string $toId): self
    {
        return new self(RepairAction::RepointStorageFile, $fromId, $toId, "Repoint documents of storage file {$fromId} to {$toId}");
    }

    public static function removeStorageFile(string $storageFileId, string $keptStorageFileId): self
    {
        return new self(RepairAction::RemoveStorageFile, $storageFileId, $keptStorageFileId, "Remove duplicate storage file {$storageFileId} (its blob only if {$keptStorageFileId} does not use it)");
    }

    public function changesData(): bool
    {
        return $this->action !== RepairAction::Keep;
    }
}
