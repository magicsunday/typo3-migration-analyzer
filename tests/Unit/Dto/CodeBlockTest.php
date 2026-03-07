<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\CodeBlock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CodeBlockTest extends TestCase
{
    #[Test]
    public function constructSetsAllProperties(): void
    {
        $block = new CodeBlock(
            language: 'php',
            code: 'echo "hello";',
            label: 'Before',
        );

        self::assertSame('php', $block->language);
        self::assertSame('echo "hello";', $block->code);
        self::assertSame('Before', $block->label);
    }

    #[Test]
    public function labelIsNullable(): void
    {
        $block = new CodeBlock(
            language: 'yaml',
            code: 'key: value',
            label: null,
        );

        self::assertNull($block->label);
    }
}
