<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\LlmCodeMapping;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LlmCodeMapping.
 */
#[CoversClass(LlmCodeMapping::class)]
final class LlmCodeMappingTest extends TestCase
{
    #[Test]
    public function constructSetsProperties(): void
    {
        $mapping = new LlmCodeMapping(
            old: 'TYPO3\CMS\Core\OldClass',
            new: 'TYPO3\CMS\Core\NewClass',
            type: 'class_rename',
        );

        self::assertSame('TYPO3\CMS\Core\OldClass', $mapping->old);
        self::assertSame('TYPO3\CMS\Core\NewClass', $mapping->new);
        self::assertSame('class_rename', $mapping->type);
    }

    #[Test]
    public function constructAllowsNullNew(): void
    {
        $mapping = new LlmCodeMapping(
            old: 'TYPO3\CMS\Core\Removed::method',
            new: null,
            type: 'method_removal',
        );

        self::assertNull($mapping->new);
    }
}
