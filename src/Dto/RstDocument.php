<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Dto;

final readonly class RstDocument
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
