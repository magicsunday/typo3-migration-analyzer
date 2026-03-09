<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Llm;

use App\Dto\LlmProvider;
use App\Llm\ClaudeModelProvider;
use App\Llm\LlmModelProviderFactory;
use App\Llm\OpenAiModelProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Tests for the LlmModelProviderFactory.
 */
#[CoversClass(LlmModelProviderFactory::class)]
final class LlmModelProviderFactoryTest extends TestCase
{
    #[Test]
    public function createReturnsClaudeModelProvider(): void
    {
        $factory  = new LlmModelProviderFactory(new MockHttpClient());
        $provider = $factory->create(LlmProvider::Claude);

        self::assertInstanceOf(ClaudeModelProvider::class, $provider);
    }

    #[Test]
    public function createReturnsOpenAiModelProvider(): void
    {
        $factory  = new LlmModelProviderFactory(new MockHttpClient());
        $provider = $factory->create(LlmProvider::OpenAi);

        self::assertInstanceOf(OpenAiModelProvider::class, $provider);
    }
}
