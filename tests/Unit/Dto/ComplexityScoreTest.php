<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\ComplexityScore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ComplexityScore::class)]
final class ComplexityScoreTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $score = new ComplexityScore(
            score: 3,
            reason: 'Argument signature changed',
            automatable: false,
        );

        self::assertSame(3, $score->score);
        self::assertSame('Argument signature changed', $score->reason);
        self::assertFalse($score->automatable);
    }

    #[Test]
    public function trivialScoreIsAutomatable(): void
    {
        $score = new ComplexityScore(
            score: 1,
            reason: 'Class renamed with 1:1 mapping',
            automatable: true,
        );

        self::assertSame(1, $score->score);
        self::assertTrue($score->automatable);
    }

    #[Test]
    public function manualScoreIsNotAutomatable(): void
    {
        $score = new ComplexityScore(
            score: 5,
            reason: 'Architecture change without clear replacement',
            automatable: false,
        );

        self::assertSame(5, $score->score);
        self::assertFalse($score->automatable);
    }
}
