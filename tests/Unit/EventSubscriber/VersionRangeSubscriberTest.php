<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\Analyzer\MatcherCoverageAnalyzer;
use App\EventSubscriber\VersionRangeSubscriber;
use App\Parser\MatcherConfigParser;
use App\Parser\RstFileLocator;
use App\Parser\RstParser;
use App\Service\DocumentService;
use App\Service\VersionRangeProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tests for the VersionRangeSubscriber.
 */
#[CoversClass(VersionRangeSubscriber::class)]
final class VersionRangeSubscriberTest extends TestCase
{
    #[Test]
    public function subscribesToControllerEvent(): void
    {
        $events = VersionRangeSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(KernelEvents::CONTROLLER, $events);
        self::assertSame('onController', $events[KernelEvents::CONTROLLER]);
    }

    #[Test]
    public function setsVersionRangeFromQueryParameters(): void
    {
        $documentService = $this->createDocumentService();
        $subscriber      = new VersionRangeSubscriber($documentService);

        $request = new Request(['migration_source' => '11', 'migration_target' => '12']);
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);

        $event = new ControllerEvent(
            $this->createStub(HttpKernelInterface::class),
            static fn (): null => null,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        $subscriber->onController($event);

        $range = $documentService->getVersionRange();
        self::assertSame(11, $range->sourceVersion);
        self::assertSame(12, $range->targetVersion);

        // Verify session was updated
        $stored = $session->get('selected_version_range');
        self::assertSame(['source' => 11, 'target' => 12], $stored);
    }

    #[Test]
    public function restoresVersionRangeFromSession(): void
    {
        $documentService = $this->createDocumentService();
        $subscriber      = new VersionRangeSubscriber($documentService);

        $request = new Request();
        $session = new Session(new MockArraySessionStorage());
        $session->set('selected_version_range', ['source' => 10, 'target' => 11]);

        $request->setSession($session);

        $event = new ControllerEvent(
            $this->createStub(HttpKernelInterface::class),
            static fn (): null => null,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        $subscriber->onController($event);

        $range = $documentService->getVersionRange();
        self::assertSame(10, $range->sourceVersion);
        self::assertSame(11, $range->targetVersion);
    }

    #[Test]
    public function doesNothingWithoutSession(): void
    {
        $documentService = $this->createDocumentService();
        $defaultRange    = $documentService->getVersionRange();
        $subscriber      = new VersionRangeSubscriber($documentService);

        $request = new Request();

        $event = new ControllerEvent(
            $this->createStub(HttpKernelInterface::class),
            static fn (): null => null,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        $subscriber->onController($event);

        // Version range should remain unchanged
        self::assertSame($defaultRange->sourceVersion, $documentService->getVersionRange()->sourceVersion);
        self::assertSame($defaultRange->targetVersion, $documentService->getVersionRange()->targetVersion);
    }

    #[Test]
    public function doesNothingForSubRequests(): void
    {
        $documentService = $this->createDocumentService();
        $defaultRange    = $documentService->getVersionRange();
        $subscriber      = new VersionRangeSubscriber($documentService);

        $request = new Request();
        $session = new Session(new MockArraySessionStorage());
        $session->set('selected_version_range', ['source' => 7, 'target' => 8]);

        $request->setSession($session);

        $event = new ControllerEvent(
            $this->createStub(HttpKernelInterface::class),
            static fn (): null => null,
            $request,
            HttpKernelInterface::SUB_REQUEST,
        );

        $subscriber->onController($event);

        // Version range should remain unchanged
        self::assertSame($defaultRange->sourceVersion, $documentService->getVersionRange()->sourceVersion);
        self::assertSame($defaultRange->targetVersion, $documentService->getVersionRange()->targetVersion);
    }

    /**
     * Creates a real DocumentService instance for testing.
     */
    private function createDocumentService(): DocumentService
    {
        return new DocumentService(
            new RstFileLocator(new RstParser()),
            new MatcherConfigParser(),
            new MatcherCoverageAnalyzer(),
            new VersionRangeProvider(),
            new ArrayAdapter(),
        );
    }
}
