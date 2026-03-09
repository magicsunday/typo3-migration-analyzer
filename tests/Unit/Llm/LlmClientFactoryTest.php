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
use App\Llm\ClaudeClient;
use App\Llm\LlmClientFactory;
use App\Llm\OpenAiClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Tests for the LlmClientFactory.
 */
#[CoversClass(LlmClientFactory::class)]
final class LlmClientFactoryTest extends TestCase
{
    #[Test]
    public function createReturnsClaudeClientForClaudeProvider(): void
    {
        $factory = new LlmClientFactory(new MockHttpClient());
        $client  = $factory->create(LlmProvider::Claude, 'test-key');

        self::assertInstanceOf(ClaudeClient::class, $client);
    }

    #[Test]
    public function createReturnsOpenAiClientForOpenAiProvider(): void
    {
        $factory = new LlmClientFactory(new MockHttpClient());
        $client  = $factory->create(LlmProvider::OpenAi, 'test-key');

        self::assertInstanceOf(OpenAiClient::class, $client);
    }
}
