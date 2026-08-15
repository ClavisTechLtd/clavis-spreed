<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Service;

use OC\Comments\Comment;
use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Manager;
use OCA\Talk\Model\Reminder;
use OCA\Talk\Model\ReminderMapper;
use OCA\Talk\Model\Thread;
use OCA\Talk\Room;
use OCA\Talk\Service\ProxyCacheMessageService;
use OCA\Talk\Service\ReminderService;
use OCA\Talk\Service\ThreadService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Comments\IComment;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * Story 4.1: `reminder` is one of the nine gated notification subjects, but it
 * is composed here rather than in {@see \OCA\Talk\Chat\Notifier}, so the Thread
 * context has to be resolved by this service.
 */
class ReminderServiceTest extends TestCase {
	protected INotificationManager&MockObject $notificationManager;
	protected ReminderMapper&MockObject $reminderMapper;
	protected ChatManager&MockObject $chatManager;
	protected ProxyCacheMessageService&MockObject $pcmService;
	protected Manager&MockObject $manager;
	protected ThreadService&MockObject $threadService;
	protected LoggerInterface&MockObject $logger;

	public function setUp(): void {
		parent::setUp();

		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->reminderMapper = $this->createMock(ReminderMapper::class);
		$this->chatManager = $this->createMock(ChatManager::class);
		$this->pcmService = $this->createMock(ProxyCacheMessageService::class);
		$this->manager = $this->createMock(Manager::class);
		$this->threadService = $this->createMock(ThreadService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	protected function getService(): ReminderService {
		return new ReminderService(
			$this->notificationManager,
			$this->reminderMapper,
			$this->chatManager,
			$this->pcmService,
			$this->manager,
			$this->threadService,
			$this->logger,
		);
	}

	/**
	 * Returns an INotification mock whose `setMessage()` calls are recorded into
	 * $capturedMessageData, so the message parameter data the service composes
	 * can be asserted.
	 */
	private function getCapturingNotification(?array &$capturedMessageData): INotification&MockObject {
		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setSubject')->willReturnSelf();
		$notification->method('setMessage')
			->willReturnCallback(function (string $verb, array $data) use ($notification, &$capturedMessageData): INotification {
				$capturedMessageData = $data;
				return $notification;
			});

		return $notification;
	}

	private function newComment(string $id, string $topmostParentId = '0'): IComment {
		$comment = new Comment([
			'id' => $id,
			'object_id' => '1234',
			'object_type' => 'chat',
			'actor_type' => 'users',
			'actor_id' => 'testUser',
			'creation_date_time' => new \DateTime('@' . 1000000016),
			'message' => 'message',
			'verb' => 'comment',
		]);
		$comment->setTopmostParentId($topmostParentId);

		return $comment;
	}

	/**
	 * Drives one full `executeReminders()` pass for a single, non-federated
	 * reminder on $comment and returns the message parameter data of the
	 * notification it emitted.
	 */
	private function executeReminderFor(IComment $comment): ?array {
		$reminder = new Reminder();
		$reminder->setUserId('recipient');
		$reminder->setToken('Token123');
		$reminder->setMessageId((int)$comment->getId());
		$reminder->setDateTime(new \DateTime('@' . 1000000020));

		$this->reminderMapper->expects($this->once())
			->method('findRemindersToExecute')
			->willReturn([$reminder]);
		$this->notificationManager->method('defer')
			->willReturn(false);

		$room = $this->createMock(Room::class);
		$room->method('getId')->willReturn(1234);
		$room->method('isFederatedConversation')->willReturn(false);
		$this->manager->expects($this->once())
			->method('getRoomsByToken')
			->with(['Token123'])
			->willReturn(['Token123' => $room]);

		$this->chatManager->expects($this->once())
			->method('getMessagesById')
			->willReturn([(int)$comment->getId() => $comment]);

		$capturedMessageData = null;
		$this->notificationManager->expects($this->once())
			->method('createNotification')
			->willReturn($this->getCapturingNotification($capturedMessageData));
		$this->notificationManager->expects($this->once())
			->method('notify');

		$this->getService()->executeReminders(new \DateTime('@' . 1000000100));

		return $capturedMessageData;
	}

	/**
	 * A Thread's root message has `topmost_parent_id = 0` and names the Thread
	 * by its own id, hence the `?: getId()` fallback - without it a reminder on
	 * the single most common target of all would carry no Thread.
	 */
	public function testReminderOnAThreadRootResolvesTheThread(): void {
		$thread = $this->createMock(Thread::class);
		$thread->method('getName')->willReturn('Thread 1');

		$this->threadService->expects($this->once())
			->method('findByThreadId')
			->with(1234, 108)
			->willReturn($thread);

		$capturedMessageData = $this->executeReminderFor($this->newComment('108'));

		$this->assertSame([
			'commentId' => 108,
			'threadId' => 108,
			'threadName' => 'Thread 1',
		], $capturedMessageData);
	}

	/**
	 * A reply inside a Thread resolves through its `topmost_parent_id`.
	 */
	public function testReminderOnAThreadReplyResolvesTheThread(): void {
		$thread = $this->createMock(Thread::class);
		$thread->method('getName')->willReturn('Thread 1');

		$this->threadService->expects($this->once())
			->method('findByThreadId')
			->with(1234, 42)
			->willReturn($thread);

		$capturedMessageData = $this->executeReminderFor($this->newComment('109', '42'));

		$this->assertSame([
			'commentId' => 109,
			'threadId' => 42,
			'threadName' => 'Thread 1',
		], $capturedMessageData);
	}

	/**
	 * A reply chain with no `talk_threads` row is not a Thread. One lookup
	 * decides both keys, so such a reminder gains neither - an unresolved id
	 * would otherwise still reach the deep link and the notification object id.
	 */
	public function testReminderOnANonThreadReplySetsNeitherKey(): void {
		$this->threadService->expects($this->once())
			->method('findByThreadId')
			->with(1234, 42)
			->willThrowException(new DoesNotExistException('No thread found'));

		$capturedMessageData = $this->executeReminderFor($this->newComment('109', '42'));

		$this->assertSame(['commentId' => 109], $capturedMessageData);
		$this->assertArrayNotHasKey('threadId', $capturedMessageData);
		$this->assertArrayNotHasKey('threadName', $capturedMessageData);
	}

	/**
	 * The Thread was reaped or deleted between setting and firing the reminder:
	 * the reminder is still worth sending, only without any Thread context.
	 */
	public function testReminderWithAGoneThreadSetsNeitherKey(): void {
		$this->threadService->expects($this->once())
			->method('findByThreadId')
			->with(1234, 108)
			->willThrowException(new DoesNotExistException('No thread found'));

		$capturedMessageData = $this->executeReminderFor($this->newComment('108'));

		$this->assertSame(['commentId' => 108], $capturedMessageData);
		$this->assertArrayNotHasKey('threadId', $capturedMessageData);
		$this->assertArrayNotHasKey('threadName', $capturedMessageData);
	}
}
