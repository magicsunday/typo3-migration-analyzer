<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\LlmRectorRule;
use App\Dto\RectorRuleType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the LlmRectorRule DTO.
 */
#[CoversClass(LlmRectorRule::class)]
final class LlmRectorRuleTest extends TestCase
{
    #[Test]
    public function configRuleHasConfigPhpButNoRulePhp(): void
    {
        $rule = new LlmRectorRule(
            filename: 'Deprecation-12345-Test.rst',
            type: RectorRuleType::RenameClass,
            ruleClassName: 'RenameClassRector',
            configEntry: ['type' => 'rename_class', 'old' => 'Old\Class', 'new' => 'New\Class'],
            rulePhp: null,
            testPhp: null,
            fixtureBeforePhp: null,
            fixtureAfterPhp: null,
        );

        self::assertSame(RectorRuleType::RenameClass, $rule->type);
        self::assertNotNull($rule->configEntry);
        self::assertNull($rule->rulePhp);
    }

    #[Test]
    public function skeletonRuleHasRulePhpAndTestPhp(): void
    {
        $rule = new LlmRectorRule(
            filename: 'Breaking-12345-Test.rst',
            type: RectorRuleType::Skeleton,
            ruleClassName: 'TestRector',
            configEntry: null,
            rulePhp: '<?php class TestRector {}',
            testPhp: '<?php class TestRectorTest {}',
            fixtureBeforePhp: '<?php $old->method();',
            fixtureAfterPhp: '<?php $new->method();',
        );

        self::assertSame(RectorRuleType::Skeleton, $rule->type);
        self::assertNull($rule->configEntry);
        self::assertNotNull($rule->rulePhp);
        self::assertNotNull($rule->testPhp);
    }
}
