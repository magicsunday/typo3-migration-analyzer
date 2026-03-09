<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\LlmModel;
use App\Dto\LlmProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the LlmModel DTO.
 */
#[CoversClass(LlmModel::class)]
final class LlmModelTest extends TestCase
{
    #[Test]
    public function constructionSetsAllProperties(): void
    {
        $model = new LlmModel(
            provider: LlmProvider::Claude,
            modelId: 'claude-haiku-4-5-20251001',
            label: 'Claude Haiku 4.5',
            inputCostPerMillion: 0.80,
            outputCostPerMillion: 4.00,
        );

        self::assertSame(LlmProvider::Claude, $model->provider);
        self::assertSame('claude-haiku-4-5-20251001', $model->modelId);
        self::assertSame('Claude Haiku 4.5', $model->label);
        self::assertSame(0.80, $model->inputCostPerMillion);
        self::assertSame(4.00, $model->outputCostPerMillion);
    }

    #[Test]
    public function estimateCostCalculatesCorrectly(): void
    {
        $model = new LlmModel(
            provider: LlmProvider::OpenAi,
            modelId: 'gpt-4o-mini',
            label: 'GPT-4o Mini',
            inputCostPerMillion: 0.15,
            outputCostPerMillion: 0.60,
        );

        // 1M input + 1M output = $0.15 + $0.60 = $0.75
        self::assertSame(0.75, $model->estimateCost(1_000_000, 1_000_000));
    }

    #[Test]
    public function estimateCostWithZeroTokensReturnsZero(): void
    {
        $model = new LlmModel(
            provider: LlmProvider::Claude,
            modelId: 'claude-sonnet-4-6',
            label: 'Claude Sonnet 4.6',
            inputCostPerMillion: 3.00,
            outputCostPerMillion: 15.00,
        );

        self::assertSame(0.0, $model->estimateCost(0, 0));
    }

    #[Test]
    public function estimateCostReturnsNullWhenPricingUnknown(): void
    {
        $model = new LlmModel(LlmProvider::Claude, 'claude-unknown', 'Unknown', null, null);

        self::assertNull($model->estimateCost(1_000_000, 500_000));
    }

    #[Test]
    public function constructionAllowsNullPricing(): void
    {
        $model = new LlmModel(LlmProvider::OpenAi, 'gpt-new', 'GPT New', null, null);

        self::assertNull($model->inputCostPerMillion);
        self::assertNull($model->outputCostPerMillion);
    }

    #[Test]
    public function estimateCostWithTypicalDocumentTokens(): void
    {
        $model = new LlmModel(
            provider: LlmProvider::Claude,
            modelId: 'claude-haiku-4-5-20251001',
            label: 'Claude Haiku 4.5',
            inputCostPerMillion: 0.80,
            outputCostPerMillion: 4.00,
        );

        // 1500 input + 500 output (typical document analysis)
        $expected = (1500 / 1_000_000) * 0.80 + (500 / 1_000_000) * 4.00;

        self::assertEqualsWithDelta($expected, $model->estimateCost(1500, 500), 0.0000001);
    }
}
