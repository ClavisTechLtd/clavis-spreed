<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\BackgroundJob;

use OCA\Talk\BackgroundJob\ReapOrphanedThreads;
use OCA\Talk\Service\ThreadService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * Story 1.10, AC2, AC3: mirrors
 * {@see \OCA\Talk\Tests\BackgroundJob\CleanupStaleSessionsTest}'s structure -
 * the direct precedent for a bounded, chunked drain-loop background job in
 * this codebase.
 */
class ReapOrphanedThreadsTest extends TestCase {
	protected ITimeFactory&MockObject $timeFactory;
	protected ThreadService&MockObject $threadService;
	protected LoggerInterface&MockObject $logger;

	public function setUp(): void {
		parent::setUp();

		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->threadService = $this->createMock(ThreadService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	public function getBackgroundJob(): ReapOrphanedThreads {
		return new ReapOrphanedThreads(
			$this->timeFactory,
			$this->threadService,
			$this->logger,
		);
	}

	public function testNothingToReap(): void {
		$this->threadService->expects($this->once())
			->method('reapOrphanedThreads')
			->with(1000)
			->willReturn(0);

		$this->logger->expects($this->never())
			->method('info');

		self::invokePrivate($this->getBackgroundJob(), 'run', [null]);
	}

	public function testReapsSingleBatch(): void {
		$this->threadService->expects($this->once())
			->method('reapOrphanedThreads')
			->with(1000)
			->willReturn(5);

		$this->logger->expects($this->once())
			->method('info')
			->with('Reaped {numReaped} threads orphaned by message expiry', ['numReaped' => 5]);

		self::invokePrivate($this->getBackgroundJob(), 'run', [null]);
	}

	public function testReapsMultipleBatchesUntilPartialBatchEndsTheLoop(): void {
		$this->threadService->expects($this->exactly(2))
			->method('reapOrphanedThreads')
			->with(1000)
			->willReturnOnConsecutiveCalls(1000, 3);

		$this->logger->expects($this->once())
			->method('info')
			->with('Reaped {numReaped} threads orphaned by message expiry', ['numReaped' => 1003]);

		self::invokePrivate($this->getBackgroundJob(), 'run', [null]);
	}
}
