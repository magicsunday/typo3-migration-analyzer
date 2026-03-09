<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\LlmRectorAssessment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LlmRectorAssessment.
 */
#[CoversClass(LlmRectorAssessment::class)]
final class LlmRectorAssessmentTest extends TestCase
{
    #[Test]
    public function constructSetsProperties(): void
    {
        $assessment = new LlmRectorAssessment(
            feasible: true,
            ruleType: 'RenameClassRector',
            notes: 'Straightforward 1:1 rename.',
        );

        self::assertTrue($assessment->feasible);
        self::assertSame('RenameClassRector', $assessment->ruleType);
        self::assertSame('Straightforward 1:1 rename.', $assessment->notes);
    }

    #[Test]
    public function constructAllowsNullRuleType(): void
    {
        $assessment = new LlmRectorAssessment(
            feasible: false,
            ruleType: null,
            notes: 'No automation possible.',
        );

        self::assertFalse($assessment->feasible);
        self::assertNull($assessment->ruleType);
    }
}
