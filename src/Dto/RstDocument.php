<?php

declare(strict_types=1);

namespace App\Dto;

readonly class RstDocument
{
    /**
     * @param list<CodeReference> $codeReferences
     * @param list<string>        $indexTags
     */
    public function __construct(
        public DocumentType $type,
        public int $issueId,
        public string $title,
        public string $version,
        public string $description,
        public ?string $impact,
        public ?string $migration,
        public array $codeReferences,
        public array $indexTags,
        public ScanStatus $scanStatus,
        public string $filename,
    ) {
    }
}
