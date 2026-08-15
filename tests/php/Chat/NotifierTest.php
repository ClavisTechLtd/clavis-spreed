<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Chat;

use OC\Comments\Comment;
use OCA\Talk\Chat\Notifier;
use OCA\Talk\Exceptions\ParticipantNotFoundException;
use OCA\Talk\Files\Util;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\Session;
use OCA\Talk\Model\Thread;
use OCA\Talk\Model\ThreadAttendee;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\Service\ThreadService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\IComment;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class NotifierTest extends TestCase {
	protected INotificationManager&MockObject $notificationManager;
	protected IUserManager&MockObject $userManager;
	protected IGroupManager&MockObject $groupManager;
	protected ParticipantService&MockObject $participantService;
	protected ThreadService&MockObject $threadService;
	protected IConfig&MockObject $config;
	protected ITimeFactory&MockObject $timeFactory;
	protected Util&MockObject $util;
	protected LoggerInterface&MockObject $logger;

	public function setUp(): void {
		parent::setUp();

		$this->notificationManager = $this->createMock(INotificationManager::class);

		$this->userManager = $this->createMock(IUserManager::class);
		$this->userManager
			->method('userExists')
			->willReturnCallback(fn ($userId) => $userId !== 'unknownUser');
		$this->groupManager = $this->createMock(IGroupManager::class);

		$this->participantService = $this->createMock(ParticipantService::class);
		$this->threadService = $this->createMock(ThreadService::class);
		$this->config = $this->createMock(IConfig::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->util = $this->createMock(Util::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	/**
	 * @param string[] $methods
	 * @return Notifier|MockObject
	 */
	protected function getNotifier(array $methods = []) {
		if (!empty($methods)) {
			return $this->getMockBuilder(Notifier::class)
				->setConstructorArgs([
					$this->notificationManager,
					$this->userManager,
					$this->groupManager,
					$this->participantService,
					$this->threadService,
					$this->config,
					$this->timeFactory,
					$this->util,
					$this->logger,
				])
				->onlyMethods($methods)
				->getMock();
		}
		return new Notifier(
			$this->notificationManager,
			$this->userManager,
			$this->groupManager,
			$this->participantService,
			$this->threadService,
			$this->config,
			$this->timeFactory,
			$this->util,
			$this->logger
		);
	}

	private function newComment($id, $actorType, $actorId, $creationDateTime, $message): IComment {
		$comment = new Comment([
			'id' => $id,
			'object_id' => '1234',
			'object_type' => 'chat',
			'actor_type' => $actorType,
			'actor_id' => $actorId,
			'creation_date_time' => $creationDateTime,
			'message' => $message,
			'verb' => 'comment',
		]);

		return $comment;
	}

	/**
	 * @return Room|MockObject
	 */
	private function getRoom($settings = []) {
		/** @var Room|MockObject */
		$room = $this->createMock(Room::class);

		$this->participantService->expects($this->any())
			->method('getParticipant')
			->willReturnCallback(function (Room $room, string $actorId) use ($settings): Participant {
				if ($actorId === 'userNotInOneToOneChat') {
					throw new ParticipantNotFoundException();
				}
				$attendeeRow = [
					'actor_type' => 'user',
					'actor_id' => $actorId,
				];
				if (isset($settings['attendee'][$actorId])) {
					$attendeeRow = array_merge($attendeeRow, $settings['attendee'][$actorId]);
				}
				$attendee = Attendee::fromRow($attendeeRow);
				return new Participant($room, $attendee, null);
			});

		return $room;
	}

	public static function dataNotifyMentionedUsers(): array {
		return [
			'no notifications' => [
				'No mentions', [], [], [],
			],
			'notify a mentioned user' => [
				'Mention @anotherUser', [], [['id' => 'anotherUser', 'type' => 'users', 'reason' => 'direct']], [['id' => 'anotherUser', 'type' => 'users', 'reason' => 'direct']],
			],
			'not notify mentioned user if already notified' => [
				'Mention @anotherUser', [['id' => 'anotherUser', 'type' => 'users', 'reason' => 'reply']], [], [['id' => 'anotherUser', 'type' => 'users', 'reason' => 'reply']],
			],
			'notify mentioned Users With Long Message Start Mention' => [
				'123456789 @anotherUserWithOddLengthName 123456789-123456789-123456789-123456789-123456789-123456789', [], [['id' => 'anotherUserWithOddLengthName', 'type' => 'users', 'reason' => 'direct']], [['id' => 'anotherUserWithOddLengthName', 'type' => 'users', 'reason' => 'direct']],
			],
			'notify mentioned users with long message middle mention' => [
				'123456789-123456789-123456789-1234 @anotherUserWithOddLengthName 6789-123456789-123456789-123456789', [], [['id' => 'anotherUserWithOddLengthName', 'type' => 'users', 'reason' => 'direct']], [['id' => 'anotherUserWithOddLengthName', 'type' => 'users', 'reason' => 'direct']],
			],
			'notify mentioned users with long message end mention' => [
				'123456789-123456789-123456789-123456789-123456789-123456789 @anotherUserWithOddLengthName 123456789', [], [['id' => 'anotherUserWithOddLengthName', 'type' => 'users', 'reason' => 'direct']], [['id' => 'anotherUserWithOddLengthName', 'type' => 'users', 'reason' => 'direct']],
			],
			'mention herself' => [
				'Mention @testUser', [], [], [],
			],
			'not notify unknownuser' => [
				'Mention @unknownUser', [], [], [],
			],
			'notify mentioned users several mentions' => [
				'Mention @anotherUser, and @unknownUser, and @testUser, and @userAbleToJoin', [],
				[['id' => 'anotherUser', 'type' => 'users', 'reason' => 'direct'], ['id' => 'userAbleToJoin', 'type' => 'users', 'reason' => 'direct']],
				[['id' => 'anotherUser', 'type' => 'users', 'reason' => 'direct'], ['id' => 'userAbleToJoin', 'type' => 'users', 'reason' => 'direct']],
			],
			'notify mentioned users to user not invited to chat' => [
				'Mention @userNotInOneToOneChat', [], [], [],
			]
		];
	}

	#[DataProvider('dataNotifyMentionedUsers')]
	public function testNotifyMentionedUsers(string $message, array $alreadyNotifiedUsers, array $notify, array $expectedReturn): void {
		if (count($notify)) {
			$this->notificationManager->expects($this->exactly(count($notify)))
				->method('notify');
		}

		$room = $this->getRoom();
		$comment = $this->newComment('108', 'users', 'testUser', new \DateTime('@' . 1000000016), $message);
		$notifier = $this->getNotifier([]);
		$participant = $this->createMock(Participant::class);
		$actual = $notifier->notifyMentionedUsers($room, $comment, $alreadyNotifiedUsers, false, $participant);

		$this->assertEqualsCanonicalizing($expectedReturn, $actual);
	}

	public static function dataShouldParticipantBeNotified(): array {
		return [
			[Attendee::ACTOR_GROUPS, 'test1', null, Attendee::ACTOR_USERS, 'test1', [], false, Notifier::PRIORITY_NONE],
			[Attendee::ACTOR_USERS, 'test1', null, Attendee::ACTOR_USERS, 'test1', [], false, Notifier::PRIORITY_NONE],
			[Attendee::ACTOR_USERS, 'test1', null, Attendee::ACTOR_USERS, 'test2', [], false, Notifier::PRIORITY_NORMAL],
			[Attendee::ACTOR_USERS, 'test1', null, Attendee::ACTOR_USERS, 'test2', [['id' => 'test1', 'type' => Attendee::ACTOR_USERS]], false, Notifier::PRIORITY_NONE],
			[Attendee::ACTOR_USERS, 'test1', null, Attendee::ACTOR_USERS, 'test2', [['id' => 'test1', 'type' => Attendee::ACTOR_FEDERATED_USERS]], false, Notifier::PRIORITY_NORMAL],
			[Attendee::ACTOR_USERS, 'test1', Session::SESSION_TIMEOUT - 5, Attendee::ACTOR_USERS, 'test2', [], false, Notifier::PRIORITY_NONE],
			[Attendee::ACTOR_USERS, 'test1', Session::SESSION_TIMEOUT + 5, Attendee::ACTOR_USERS, 'test2', [], false, Notifier::PRIORITY_NORMAL],

			// Marked as important, still blocked by session and being the author, but otherwise with PRIORITY_IMPORTANT
			[Attendee::ACTOR_USERS, 'test1', null, Attendee::ACTOR_USERS, 'test1', [], true, Notifier::PRIORITY_NONE],
			[Attendee::ACTOR_USERS, 'test1', null, Attendee::ACTOR_USERS, 'test2', [], true, Notifier::PRIORITY_IMPORTANT],
			[Attendee::ACTOR_USERS, 'test1', Session::SESSION_TIMEOUT - 5, Attendee::ACTOR_USERS, 'test2', [], true, Notifier::PRIORITY_NONE],
			[Attendee::ACTOR_USERS, 'test1', Session::SESSION_TIMEOUT + 5, Attendee::ACTOR_USERS, 'test2', [], true, Notifier::PRIORITY_IMPORTANT],
		];
	}

	#[DataProvider('dataShouldParticipantBeNotified')]
	public function testShouldParticipantBeNotified(string $actorType, string $actorId, ?int $sessionAge, string $commentActorType, string $commentActorId, array $alreadyNotifiedUsers, bool $isImportant, int $expected): void {
		$comment = $this->createMock(IComment::class);
		$comment->method('getActorType')
			->willReturn($commentActorType);
		$comment->method('getActorId')
			->willReturn($commentActorId);

		$room = $this->createMock(Room::class);
		$attendee = Attendee::fromRow([
			'actor_type' => $actorType,
			'actor_id' => $actorId,
			'important' => $isImportant,
		]);
		$session = null;
		if ($sessionAge !== null) {
			$current = 1234567;
			$this->timeFactory->method('getTime')
				->willReturn($current);

			$session = Session::fromRow([
				'last_ping' => $current - $sessionAge,
			]);
		}
		$participant = new Participant($room, $attendee, $session);

		self::assertSame($expected, self::invokePrivate($this->getNotifier(), 'shouldParticipantBeNotified', [$participant, $comment, $alreadyNotifiedUsers]));
	}

	public function testRemovePendingNotificationsForRoom(): void {
		$notification = $this->createMock(INotification::class);

		$room = $this->createMock(Room::class);
		$room->expects($this->any())
			->method('getToken')
			->willReturn('Token123');

		$this->notificationManager->expects($this->once())
			->method('createNotification')
			->willReturn($notification);

		$notification->expects($this->once())
			->method('setApp')
			->with('spreed')
			->willReturnSelf();

		$notification->expects($this->atLeastOnce())
			->method('setObject')
			->with($this->anything(), 'Token123')
			->willReturnSelf();

		$this->notificationManager->expects($this->atLeastOnce())
			->method('markProcessed')
			->with($notification);

		$this->getNotifier()->removePendingNotificationsForRoom($room);
	}

	public function testRemovePendingNotificationsForChatOnly(): void {
		$notification = $this->createMock(INotification::class);

		$room = $this->createMock(Room::class);
		$room->expects($this->any())
			->method('getToken')
			->willReturn('Token123');

		$this->notificationManager->expects($this->once())
			->method('createNotification')
			->willReturn($notification);

		$notification->expects($this->once())
			->method('setApp')
			->with('spreed')
			->willReturnSelf();

		$notification->expects($this->atLeastOnce())
			->method('setObject')
			->with($this->anything(), 'Token123')
			->willReturnSelf();

		$this->notificationManager->expects($this->atLeastOnce())
			->method('markProcessed')
			->with($notification);

		$this->getNotifier()->removePendingNotificationsForRoom($room, true);
	}

	public static function dataAddMentionAllToList(): array {
		return [
			'not notify' => [
				[],
				[],
				0,
				true,
				[],
			],
			'preserve notify list and do not notify all' => [
				[
					['id' => 'user1', 'type' => Attendee::ACTOR_USERS, 'reason' => 'direct'],
				],
				[],
				0,
				true,
				[
					['id' => 'user1', 'type' => Attendee::ACTOR_USERS, 'reason' => 'direct'],
				],
			],
			'mention all' => [
				[
					['id' => 'user1', 'type' => Attendee::ACTOR_USERS, 'reason' => 'direct'],
					['id' => 'all', 'type' => Attendee::ACTOR_USERS, 'reason' => 'direct'],
				],
				[
					Attendee::fromRow(['actor_id' => 'user1', 'actor_type' => Attendee::ACTOR_USERS]),
					Attendee::fromRow(['actor_id' => 'user2', 'actor_type' => Attendee::ACTOR_USERS]),
				],
				0,
				false,
				[
					['id' => 'user1', 'type' => Attendee::ACTOR_USERS, 'reason' => 'direct'],
					['id' => 'user2', 'type' => Attendee::ACTOR_USERS, 'reason' => 'all'],
				],
			],
			'prevent non-moderator to notify all' => [
				[
					['id' => 'user1', 'type' => Attendee::ACTOR_USERS, 'reason' => 'direct'],
					['id' => 'all', 'type' => Attendee::ACTOR_USERS, 'reason' => 'direct'],
				],
				[
					Attendee::fromRow(['actor_id' => 'user1', 'actor_type' => Attendee::ACTOR_USERS]),
					Attendee::fromRow(['actor_id' => 'user2', 'actor_type' => Attendee::ACTOR_USERS]),
				],
				1,
				false,
				[
					['id' => 'user1', 'type' => Attendee::ACTOR_USERS, 'reason' => 'direct'],
				],
			],
		];
	}

	#[DataProvider('dataAddMentionAllToList')]
	public function testAddMentionAllToList(array $usersToNotify, array $participants, int $mentionPermissions, bool $moderatorPermissions, array $return): void {
		$room = $this->createMock(Room::class);
		$room->method('getMentionPermissions')
			->willReturn($mentionPermissions);

		$this->participantService
			->method('getActorsByType')
			->willReturn($participants);

		$participant = $this->createMock(Participant::class);
		$participant->method('hasModeratorPermissions')
			->willReturn($moderatorPermissions);

		$actual = self::invokePrivate($this->getNotifier(), 'addMentionAllToList', [$room, $usersToNotify, $participant]);
		$this->assertCount(count($return), $actual);
		foreach ($actual as $key => $value) {
			$this->assertIsArray($value);
			if (array_key_exists('attendee', $value)) {
				$this->assertInstanceOf(Attendee::class, $value['attendee']);
				unset($value['attendee']);
			}
			$this->assertEqualsCanonicalizing($return[$key], $value);
		}
	}

	public static function dataNotifyReacted(): array {
		return [
			'author react to own message'
				=> [0, Participant::NOTIFY_MENTION, Room::TYPE_GROUP, 'testUser'],
			'notify never'
				=> [0, Participant::NOTIFY_NEVER, Room::TYPE_GROUP, 'testUser2'],
			'notify default, not one to one'
				=> [0, Participant::NOTIFY_DEFAULT, Room::TYPE_GROUP, 'testUser2'],
			'notify default, one to one'
				=> [1, Participant::NOTIFY_DEFAULT, Room::TYPE_ONE_TO_ONE, 'testUser2'],
			'notify always'
				=> [1, Participant::NOTIFY_ALWAYS, Room::TYPE_GROUP, 'testUser2'],
		];
	}

	#[DataProvider('dataNotifyReacted')]
	public function testNotifyReacted(int $notify, int $notifyType, int $roomType, string $authorId): void {
		$this->notificationManager->expects($this->exactly($notify))
			->method('notify');

		$room = $this->getRoom([
			'attendee' => [
				'testUser' => [
					'notificationLevel' => $notifyType,
				]
			]
		]);
		$room->method('getType')
			->willReturn($roomType);
		$comment = $this->newComment('108', 'users', 'testUser', new \DateTime('@' . 1000000016), 'message');
		$reaction = $this->newComment('108', 'users', $authorId, new \DateTime('@' . 1000000016), 'message');

		$notifier = $this->getNotifier([]);
		$notifier->notifyReacted($room, $comment, $reaction);
	}

	/**
	 * Returns an INotification mock whose `setMessage()` calls are recorded into
	 * $capturedMessageData, so the message parameter data `createNotification()`
	 * composes can be asserted.
	 */
	private function getCapturingNotification(?array &$capturedMessageData): INotification&MockObject {
		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setPriorityNotification')->willReturnSelf();
		$notification->method('setMessage')
			->willReturnCallback(function (string $verb, array $data) use ($notification, &$capturedMessageData): INotification {
				$capturedMessageData = $data;
				return $notification;
			});

		return $notification;
	}

	/**
	 * Story 4.1, AC1: the Thread Title is resolved once at emit time and stored
	 * beside the existing `threadId`, so the per-recipient render path needs no
	 * Thread lookup of its own.
	 */
	public function testCreateNotificationAddsThreadNameNextToThreadId(): void {
		$capturedMessageData = null;
		$this->notificationManager->method('createNotification')
			->willReturn($this->getCapturingNotification($capturedMessageData));

		$room = $this->createMock(Room::class);
		$room->method('getId')->willReturn(1234);
		$room->method('getToken')->willReturn('Token123');

		$thread = $this->createMock(Thread::class);
		$thread->method('getName')->willReturn('Thread 1');

		$this->threadService->expects($this->once())
			->method('findByThreadId')
			->with(1234, 42)
			->willReturn($thread);

		$comment = $this->newComment('108', 'users', 'testUser', new \DateTime('@' . 1000000016), 'message');

		self::invokePrivate($this->getNotifier(), 'createNotification', [$room, $comment, 'chat', [], null, 42]);

		$this->assertSame([
			'commentId' => '108',
			'threadId' => 42,
			'threadName' => 'Thread 1',
		], $capturedMessageData);
	}

	/**
	 * Story 4.1: posting into a Thread creates one notification per recipient,
	 * so the title lookup is memoised for the request.
	 */
	public function testCreateNotificationResolvesTheThreadTitleOnlyOnce(): void {
		$capturedMessageData = null;
		$this->notificationManager->method('createNotification')
			->willReturn($this->getCapturingNotification($capturedMessageData));

		$room = $this->createMock(Room::class);
		$room->method('getId')->willReturn(1234);
		$room->method('getToken')->willReturn('Token123');

		$thread = $this->createMock(Thread::class);
		$thread->method('getName')->willReturn('Thread 1');

		$this->threadService->expects($this->once())
			->method('findByThreadId')
			->with(1234, 42)
			->willReturn($thread);

		$comment = $this->newComment('108', 'users', 'testUser', new \DateTime('@' . 1000000016), 'message');

		$notifier = $this->getNotifier();
		self::invokePrivate($notifier, 'createNotification', [$room, $comment, 'chat', [], null, 42]);
		self::invokePrivate($notifier, 'createNotification', [$room, $comment, 'reply', [], null, 42]);

		$this->assertSame('Thread 1', $capturedMessageData['threadName']);
	}

	/**
	 * Story 4.1, edge case "Thread row gone at emit time": the notification is
	 * still emitted, only without the title.
	 */
	public function testCreateNotificationOmitsThreadNameWhenTheThreadIsGone(): void {
		$capturedMessageData = null;
		$this->notificationManager->method('createNotification')
			->willReturn($this->getCapturingNotification($capturedMessageData));

		$room = $this->createMock(Room::class);
		$room->method('getId')->willReturn(1234);
		$room->method('getToken')->willReturn('Token123');

		$this->threadService->expects($this->once())
			->method('findByThreadId')
			->with(1234, 42)
			->willThrowException(new DoesNotExistException('No thread found'));

		$comment = $this->newComment('108', 'users', 'testUser', new \DateTime('@' . 1000000016), 'message');

		self::invokePrivate($this->getNotifier(), 'createNotification', [$room, $comment, 'chat', [], null, 42]);

		$this->assertSame([
			'commentId' => '108',
			'threadId' => 42,
		], $capturedMessageData);
		$this->assertArrayNotHasKey('threadName', $capturedMessageData);
	}

	/**
	 * Story 4.1: `Thread::THREAD_CREATE` (-1) is a sentinel that survives the
	 * notification dispatch when a message creates its own Thread, so it must
	 * never reach the message parameters, the deep link or the object id.
	 */
	public function testCreateNotificationIgnoresTheThreadCreateSentinel(): void {
		$capturedMessageData = null;
		$this->notificationManager->method('createNotification')
			->willReturn($this->getCapturingNotification($capturedMessageData));

		$room = $this->createMock(Room::class);
		$room->method('getToken')->willReturn('Token123');

		$this->threadService->expects($this->never())
			->method('findByThreadId');

		$comment = $this->newComment('108', 'users', 'testUser', new \DateTime('@' . 1000000016), 'message');

		self::invokePrivate($this->getNotifier(), 'createNotification', [$room, $comment, 'chat', [], null, Thread::THREAD_CREATE]);

		$this->assertSame(['commentId' => '108'], $capturedMessageData);
		$this->assertArrayNotHasKey('threadId', $capturedMessageData);
	}

	/**
	 * Story 4.1: activity outside any Thread gains neither key.
	 */
	public function testCreateNotificationWithoutThreadIsUnchanged(): void {
		$capturedMessageData = null;
		$this->notificationManager->method('createNotification')
			->willReturn($this->getCapturingNotification($capturedMessageData));

		$room = $this->createMock(Room::class);
		$room->method('getToken')->willReturn('Token123');

		$this->threadService->expects($this->never())
			->method('findByThreadId');

		$comment = $this->newComment('108', 'users', 'testUser', new \DateTime('@' . 1000000016), 'message');

		self::invokePrivate($this->getNotifier(), 'createNotification', [$room, $comment, 'chat']);

		$this->assertSame(['commentId' => '108'], $capturedMessageData);
	}

	/**
	 * Story 4.1: `reaction` is one of the nine gated subjects, so it has to carry
	 * the thread id of the reacted-to message - it did not before.
	 */
	public function testNotifyReactedCarriesTheThreadOfTheReactedToMessage(): void {
		$capturedMessageData = null;
		$this->notificationManager->method('createNotification')
			->willReturn($this->getCapturingNotification($capturedMessageData));

		$room = $this->getRoom([
			'attendee' => [
				'testUser' => [
					'notificationLevel' => Participant::NOTIFY_ALWAYS,
				],
			],
		]);
		$room->method('getType')
			->willReturn(Room::TYPE_GROUP);
		$room->method('getId')
			->willReturn(1234);

		$thread = $this->createMock(Thread::class);
		$thread->method('getName')->willReturn('Thread 1');

		$this->threadService->expects($this->once())
			->method('validateThread')
			->with(1234, 42)
			->willReturn(true);
		$this->threadService->expects($this->once())
			->method('findByThreadId')
			->with(1234, 42)
			->willReturn($thread);

		$comment = $this->newComment('108', 'users', 'testUser', new \DateTime('@' . 1000000016), 'message');
		$comment->setTopmostParentId('42');
		$reaction = $this->newComment('109', 'users', 'testUser2', new \DateTime('@' . 1000000016), '👍');

		$this->getNotifier()->notifyReacted($room, $comment, $reaction);

		$this->assertSame([
			'commentId' => '108',
			'threadId' => 42,
			'threadName' => 'Thread 1',
		], $capturedMessageData);
	}

	/**
	 * Story 4.1: a Thread's *root* message has `topmost_parent_id = 0` and names
	 * the Thread by its own id - and it is the most common reaction target of
	 * all. Without the `?: getId()` fallback it would carry no Thread.
	 */
	public function testNotifyReactedOnAThreadRootCarriesTheThread(): void {
		$capturedMessageData = null;
		$this->notificationManager->method('createNotification')
			->willReturn($this->getCapturingNotification($capturedMessageData));

		$room = $this->getRoom([
			'attendee' => [
				'testUser' => [
					'notificationLevel' => Participant::NOTIFY_ALWAYS,
				],
			],
		]);
		$room->method('getType')
			->willReturn(Room::TYPE_GROUP);
		$room->method('getId')
			->willReturn(1234);

		$thread = $this->createMock(Thread::class);
		$thread->method('getName')->willReturn('Thread 1');

		$this->threadService->expects($this->once())
			->method('validateThread')
			->with(1234, 108)
			->willReturn(true);
		$this->threadService->expects($this->once())
			->method('findByThreadId')
			->with(1234, 108)
			->willReturn($thread);

		// A root message: `topmost_parent_id` stays at its default of 0.
		$comment = $this->newComment('108', 'users', 'testUser', new \DateTime('@' . 1000000016), 'message');
		$reaction = $this->newComment('109', 'users', 'testUser2', new \DateTime('@' . 1000000016), '👍');

		$this->getNotifier()->notifyReacted($room, $comment, $reaction);

		$this->assertSame([
			'commentId' => '108',
			'threadId' => 108,
			'threadName' => 'Thread 1',
		], $capturedMessageData);
	}

	/**
	 * Story 4.1: the derived id is validated before it is carried, because it
	 * reaches the deep link and the composed notification object id. A plain
	 * reply chain that is not a Thread must therefore carry no thread id.
	 */
	public function testNotifyReactedCarriesNoThreadIdWhenValidationFails(): void {
		$capturedMessageData = null;
		$this->notificationManager->method('createNotification')
			->willReturn($this->getCapturingNotification($capturedMessageData));

		$room = $this->getRoom([
			'attendee' => [
				'testUser' => [
					'notificationLevel' => Participant::NOTIFY_ALWAYS,
				],
			],
		]);
		$room->method('getType')
			->willReturn(Room::TYPE_GROUP);
		$room->method('getId')
			->willReturn(1234);

		$this->threadService->expects($this->once())
			->method('validateThread')
			->with(1234, 42)
			->willReturn(false);
		$this->threadService->expects($this->never())
			->method('findByThreadId');

		$comment = $this->newComment('108', 'users', 'testUser', new \DateTime('@' . 1000000016), 'message');
		$comment->setTopmostParentId('42');
		$reaction = $this->newComment('109', 'users', 'testUser2', new \DateTime('@' . 1000000016), '👍');

		$this->getNotifier()->notifyReacted($room, $comment, $reaction);

		$this->assertSame(['commentId' => '108'], $capturedMessageData);
		$this->assertArrayNotHasKey('threadId', $capturedMessageData);
	}

	public static function dataGetMentionedUsers(): array {
		return [
			'mention one user' => [
				'Mention @anotherUser',
				[
					['id' => 'anotherUser', 'type' => Attendee::ACTOR_USERS, 'reason' => 'direct'],
				],
			],
			'mention two user' => [
				'Mention @anotherUser, and @unknownUser',
				[
					['id' => 'anotherUser', 'type' => Attendee::ACTOR_USERS, 'reason' => 'direct'],
					['id' => 'unknownUser', 'type' => Attendee::ACTOR_USERS, 'reason' => 'direct'],
				],
			],
			'mention all' => [
				'Mention @all',
				[
					['id' => 'all', 'type' => Attendee::ACTOR_USERS, 'reason' => 'direct'],
				],
			],
			'mention user, all, guest and group' => [
				'mention @test, @all, @"guest/1" @"group/1"',
				[
					['id' => 'test', 'type' => Attendee::ACTOR_USERS, 'reason' => 'direct'],
					['id' => 'all', 'type' => Attendee::ACTOR_USERS, 'reason' => 'direct'],
				],
			],
		];
	}

	#[DataProvider('dataGetMentionedUsers')]
	public function testGetMentionedUsers(string $message, array $expectedReturn): void {
		$comment = $this->newComment('108', 'users', 'testUser', new \DateTime('@' . 1000000016), $message);
		$actual = self::invokePrivate($this->getNotifier(), 'getMentionedUsers', [$comment]);
		$this->assertEqualsCanonicalizing($expectedReturn, $actual);
	}

	public static function dataGetMentionedUserIds(): array {
		$return = self::dataGetMentionedUsers();
		array_walk($return, function (array &$scenario) {
			array_walk($scenario[1], function (array &$params): void {
				$params = $params['id'];
			});
			return $scenario;
		});
		return $return;
	}

	#[DataProvider('dataGetMentionedUserIds')]
	public function testGetMentionedUserIds(string $message, array $expectedReturn): void {
		$comment = $this->newComment('108', 'users', 'testUser', new \DateTime('@' . 1000000016), $message);
		$actual = self::invokePrivate($this->getNotifier(), 'getMentionedUserIds', [$comment]);
		$this->assertEqualsCanonicalizing($expectedReturn, $actual);
	}

	private function newThreadAttendee(int $attendeeId, int $notificationLevel): ThreadAttendee {
		$threadAttendee = new ThreadAttendee();
		$threadAttendee->setThreadId(42);
		$threadAttendee->setRoomId(1234);
		$threadAttendee->setAttendeeId($attendeeId);
		$threadAttendee->setNotificationLevel($notificationLevel);
		return $threadAttendee;
	}

	private function newParticipant(Room $room, int $attendeeId, string $actorType, string $actorId, string $displayName = ''): Participant {
		return new Participant($room, Attendee::fromRow([
			'id' => $attendeeId,
			'actor_type' => $actorType,
			'actor_id' => $actorId,
			'display_name' => $displayName,
		]), null);
	}

	/**
	 * Sets up the notification manager so the recipients of a thread state change
	 * and the single notification built for them can be inspected.
	 *
	 * @param list<string> $notifiedUsers
	 */
	private function captureThreadStateNotification(array &$notifiedUsers, ?string &$capturedSubject, ?array &$capturedSubjectData, ?array &$capturedObject): void {
		$currentUser = null;

		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')
			->willReturnCallback(function (string $type, string $id) use ($notification, &$capturedObject): INotification {
				$capturedObject = [$type, $id];
				return $notification;
			});
		$notification->method('setSubject')
			->willReturnCallback(function (string $subject, array $parameters) use ($notification, &$capturedSubject, &$capturedSubjectData): INotification {
				$capturedSubject = $subject;
				$capturedSubjectData = $parameters;
				return $notification;
			});
		$notification->method('setUser')
			->willReturnCallback(function (string $user) use ($notification, &$currentUser): INotification {
				$currentUser = $user;
				return $notification;
			});

		$this->notificationManager->method('createNotification')
			->willReturn($notification);
		$this->notificationManager->method('notify')
			->willReturnCallback(function () use (&$currentUser, &$notifiedUsers): void {
				$notifiedUsers[] = $currentUser;
			});
	}

	/**
	 * Story 4.2, AC1: a follower at Participant::NOTIFY_ALWAYS is notified, the
	 * notification is stored under the `room` object type with the bare room token
	 * as object id (AD-12) - never `chat`, which
	 * Notifier::markMentionNotificationsRead() would erase, and never a new type
	 * such as `thread`, which shipped Android and iOS clients drop - and the lock
	 * reason travels in the subject parameters as the system message carried it
	 * (AD-14).
	 */
	public function testNotifyThreadStateChangeNotifiesFollowerAtNotifyAlways(): void {
		$notifiedUsers = [];
		$capturedSubject = $capturedSubjectData = $capturedObject = null;
		$this->captureThreadStateNotification($notifiedUsers, $capturedSubject, $capturedSubjectData, $capturedObject);

		$room = $this->createMock(Room::class);
		$room->method('getId')->willReturn(1234);
		$room->method('getToken')->willReturn('Token123');

		$this->threadService->expects($this->once())
			->method('findAttendeesForNotificationByThreadId')
			->with(1234, 42)
			->willReturn([
				11 => $this->newThreadAttendee(11, Participant::NOTIFY_ALWAYS),
			]);
		$this->participantService->expects($this->once())
			->method('getParticipantsByAttendeeId')
			->with($room, [11])
			->willReturn([
				$this->newParticipant($room, 11, Attendee::ACTOR_USERS, 'follower'),
			]);

		$actor = $this->newParticipant($room, 1, Attendee::ACTOR_USERS, 'actor', 'actor-displayname');

		$this->getNotifier()->notifyThreadStateChange($room, $actor, 42, 'thread_locked', [
			'thread' => 42,
			'title' => 'Thread 1',
			'reason' => 'Off topic',
		]);

		$this->assertSame(['follower'], $notifiedUsers);
		$this->assertSame(['room', 'Token123'], $capturedObject);
		$this->assertSame('thread_locked', $capturedSubject);
		$this->assertSame([
			'userType' => Attendee::ACTOR_USERS,
			'userId' => 'actor',
			'userDisplayName' => 'actor-displayname',
			'thread' => 42,
			'title' => 'Thread 1',
			'reason' => 'Off topic',
		], $capturedSubjectData);
	}

	/**
	 * Story 4.2, AD-14: the reason is copied out of the system message's parameter
	 * array - not trimmed, not escaped, not re-derived from the Thread. Only its
	 * length is bounded, see testNotifyThreadStateChangeBoundsTheStoredReason().
	 */
	public function testNotifyThreadStateChangePassesTheReasonThroughUntouched(): void {
		$notifiedUsers = [];
		$capturedSubject = $capturedSubjectData = $capturedObject = null;
		$this->captureThreadStateNotification($notifiedUsers, $capturedSubject, $capturedSubjectData, $capturedObject);

		$room = $this->createMock(Room::class);
		$room->method('getId')->willReturn(1234);
		$room->method('getToken')->willReturn('Token123');

		$this->threadService->method('findAttendeesForNotificationByThreadId')
			->willReturn([
				11 => $this->newThreadAttendee(11, Participant::NOTIFY_ALWAYS),
			]);
		$this->participantService->method('getParticipantsByAttendeeId')
			->willReturn([
				$this->newParticipant($room, 11, Attendee::ACTOR_USERS, 'follower'),
			]);

		$actor = $this->newParticipant($room, 1, Attendee::ACTOR_USERS, 'actor');
		$reason = "  Chủ đề đã <b>đóng</b> {call}\n";

		$this->getNotifier()->notifyThreadStateChange($room, $actor, 42, 'thread_locked', [
			'thread' => 42,
			'title' => 'Bảo trì hệ thống',
			'reason' => $reason,
		]);

		$this->assertSame($reason, $capturedSubjectData['reason']);
		$this->assertSame('Bảo trì hệ thống', $capturedSubjectData['title']);
	}

	/**
	 * Story 4.2: without a reason there is no `reason` parameter at all - an empty
	 * string would make the parser pick the with-reason template.
	 */
	public function testNotifyThreadStateChangeWithoutReasonCarriesNoReasonParameter(): void {
		$notifiedUsers = [];
		$capturedSubject = $capturedSubjectData = $capturedObject = null;
		$this->captureThreadStateNotification($notifiedUsers, $capturedSubject, $capturedSubjectData, $capturedObject);

		$room = $this->createMock(Room::class);
		$room->method('getId')->willReturn(1234);
		$room->method('getToken')->willReturn('Token123');

		$this->threadService->method('findAttendeesForNotificationByThreadId')
			->willReturn([
				11 => $this->newThreadAttendee(11, Participant::NOTIFY_ALWAYS),
			]);
		$this->participantService->method('getParticipantsByAttendeeId')
			->willReturn([
				$this->newParticipant($room, 11, Attendee::ACTOR_USERS, 'follower'),
			]);

		$actor = $this->newParticipant($room, 1, Attendee::ACTOR_USERS, 'actor');

		$this->getNotifier()->notifyThreadStateChange($room, $actor, 42, 'thread_closed', [
			'thread' => 42,
			'title' => 'Thread 1',
		]);

		$this->assertSame(['follower'], $notifiedUsers);
		$this->assertSame('thread_closed', $capturedSubject);
		$this->assertArrayNotHasKey('reason', $capturedSubjectData);
	}

	public static function dataNotifyThreadStateChangeMutedFollower(): array {
		return [
			'muted to mentions' => [Participant::NOTIFY_MENTION],
			'muted entirely' => [Participant::NOTIFY_NEVER],
		];
	}

	/**
	 * Story 4.2, AC3: only an explicit Participant::NOTIFY_ALWAYS row counts as
	 * following a Thread - the same rule notifyOtherParticipant() applies.
	 */
	#[DataProvider('dataNotifyThreadStateChangeMutedFollower')]
	public function testNotifyThreadStateChangeSkipsMutedFollowers(int $notificationLevel): void {
		$notifiedUsers = [];
		$capturedSubject = $capturedSubjectData = $capturedObject = null;
		$this->captureThreadStateNotification($notifiedUsers, $capturedSubject, $capturedSubjectData, $capturedObject);

		$room = $this->createMock(Room::class);
		$room->method('getId')->willReturn(1234);
		$room->method('getToken')->willReturn('Token123');

		$this->threadService->expects($this->once())
			->method('findAttendeesForNotificationByThreadId')
			->willReturn([
				11 => $this->newThreadAttendee(11, $notificationLevel),
			]);
		$this->participantService->expects($this->never())
			->method('getParticipantsByAttendeeId');

		$actor = $this->newParticipant($room, 1, Attendee::ACTOR_USERS, 'actor');

		$this->getNotifier()->notifyThreadStateChange($room, $actor, 42, 'thread_locked', [
			'thread' => 42,
			'title' => 'Thread 1',
		]);

		$this->assertSame([], $notifiedUsers);
	}

	/**
	 * Story 4.2, AC4: a room participant without a `talk_thread_attendees` row is
	 * not following the Thread and hears nothing. The backing query already
	 * excludes Participant::NOTIFY_DEFAULT, so such a row never comes back.
	 */
	public function testNotifyThreadStateChangeSkipsNonFollowers(): void {
		$notifiedUsers = [];
		$capturedSubject = $capturedSubjectData = $capturedObject = null;
		$this->captureThreadStateNotification($notifiedUsers, $capturedSubject, $capturedSubjectData, $capturedObject);

		$room = $this->createMock(Room::class);
		$room->method('getId')->willReturn(1234);
		$room->method('getToken')->willReturn('Token123');

		$this->threadService->expects($this->once())
			->method('findAttendeesForNotificationByThreadId')
			->willReturn([]);
		$this->participantService->expects($this->never())
			->method('getParticipantsByAttendeeId');

		$actor = $this->newParticipant($room, 1, Attendee::ACTOR_USERS, 'actor');

		$this->getNotifier()->notifyThreadStateChange($room, $actor, 42, 'thread_reopened', [
			'thread' => 42,
			'title' => 'Thread 1',
		]);

		$this->assertSame([], $notifiedUsers);
	}

	/**
	 * Story 4.2, AC5: the Thread Manager performing the transition is never
	 * notified of their own action, even while following the Thread.
	 */
	public function testNotifyThreadStateChangeSkipsTheActor(): void {
		$notifiedUsers = [];
		$capturedSubject = $capturedSubjectData = $capturedObject = null;
		$this->captureThreadStateNotification($notifiedUsers, $capturedSubject, $capturedSubjectData, $capturedObject);

		$room = $this->createMock(Room::class);
		$room->method('getId')->willReturn(1234);
		$room->method('getToken')->willReturn('Token123');

		$this->threadService->method('findAttendeesForNotificationByThreadId')
			->willReturn([
				1 => $this->newThreadAttendee(1, Participant::NOTIFY_ALWAYS),
				11 => $this->newThreadAttendee(11, Participant::NOTIFY_ALWAYS),
			]);
		$this->participantService->expects($this->once())
			->method('getParticipantsByAttendeeId')
			->with($room, [1, 11])
			->willReturn([
				$this->newParticipant($room, 1, Attendee::ACTOR_USERS, 'actor'),
				$this->newParticipant($room, 11, Attendee::ACTOR_USERS, 'follower'),
			]);

		$actor = $this->newParticipant($room, 1, Attendee::ACTOR_USERS, 'actor');

		$this->getNotifier()->notifyThreadStateChange($room, $actor, 42, 'thread_unlocked', [
			'thread' => 42,
			'title' => 'Thread 1',
		]);

		$this->assertSame(['follower'], $notifiedUsers);
	}

	/**
	 * Story 4.2: only Attendee::ACTOR_USERS have a notification inbox, so a group,
	 * guest or federated attendee following the Thread is skipped.
	 */
	public function testNotifyThreadStateChangeSkipsNonUserAttendees(): void {
		$notifiedUsers = [];
		$capturedSubject = $capturedSubjectData = $capturedObject = null;
		$this->captureThreadStateNotification($notifiedUsers, $capturedSubject, $capturedSubjectData, $capturedObject);

		$room = $this->createMock(Room::class);
		$room->method('getId')->willReturn(1234);
		$room->method('getToken')->willReturn('Token123');

		$this->threadService->method('findAttendeesForNotificationByThreadId')
			->willReturn([
				11 => $this->newThreadAttendee(11, Participant::NOTIFY_ALWAYS),
				12 => $this->newThreadAttendee(12, Participant::NOTIFY_ALWAYS),
				13 => $this->newThreadAttendee(13, Participant::NOTIFY_ALWAYS),
			]);
		$this->participantService->method('getParticipantsByAttendeeId')
			->willReturn([
				$this->newParticipant($room, 11, Attendee::ACTOR_GUESTS, 'guest-hash'),
				$this->newParticipant($room, 12, Attendee::ACTOR_FEDERATED_USERS, 'remote@example.tld'),
				$this->newParticipant($room, 13, Attendee::ACTOR_USERS, 'follower'),
			]);

		$actor = $this->newParticipant($room, 1, Attendee::ACTOR_USERS, 'actor');

		$this->getNotifier()->notifyThreadStateChange($room, $actor, 42, 'thread_closed', [
			'thread' => 42,
			'title' => 'Thread 1',
		]);

		$this->assertSame(['follower'], $notifiedUsers);
	}

	/**
	 * Story 4.2: the subject parameters are persisted once per follower, so the
	 * reason - up to Thread::LOCK_REASON_MAX_LENGTH characters - is bounded at
	 * emit time. Only Notification\Notifier::THREAD_LOCK_REASON_MAX_LENGTH
	 * characters are ever rendered, so the stored bound is invisible to the
	 * reader.
	 */
	public function testNotifyThreadStateChangeBoundsTheStoredReason(): void {
		$notifiedUsers = [];
		$capturedSubject = $capturedSubjectData = $capturedObject = null;
		$this->captureThreadStateNotification($notifiedUsers, $capturedSubject, $capturedSubjectData, $capturedObject);

		$room = $this->createMock(Room::class);
		$room->method('getId')->willReturn(1234);
		$room->method('getToken')->willReturn('Token123');

		$this->threadService->method('findAttendeesForNotificationByThreadId')
			->willReturn([
				11 => $this->newThreadAttendee(11, Participant::NOTIFY_ALWAYS),
			]);
		$this->participantService->method('getParticipantsByAttendeeId')
			->willReturn([
				$this->newParticipant($room, 11, Attendee::ACTOR_USERS, 'follower'),
			]);

		$actor = $this->newParticipant($room, 1, Attendee::ACTOR_USERS, 'actor');

		$this->getNotifier()->notifyThreadStateChange($room, $actor, 42, 'thread_locked', [
			'thread' => 42,
			'title' => 'Thread 1',
			// Multibyte on purpose: the bound is in characters, not bytes.
			'reason' => str_repeat('ả', Thread::LOCK_REASON_MAX_LENGTH),
		]);

		$this->assertSame(
			str_repeat('ả', Notifier::THREAD_LOCK_REASON_STORE_MAX_LENGTH),
			$capturedSubjectData['reason'],
		);
	}

	/**
	 * Story 4.2: INotificationManager::notify() throws
	 * \InvalidArgumentException for an unusable recipient. One such recipient must
	 * not skip the followers behind it in the loop, nor the flush() that delivers
	 * the notifications deferred so far.
	 */
	public function testNotifyThreadStateChangeContinuesAfterAFailingRecipient(): void {
		$notifiedUsers = [];
		$currentUser = null;

		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->willReturnSelf();
		$notification->method('setUser')
			->willReturnCallback(function (string $user) use ($notification, &$currentUser): INotification {
				$currentUser = $user;
				return $notification;
			});

		$this->notificationManager->method('createNotification')
			->willReturn($notification);
		$this->notificationManager->method('defer')
			->willReturn(true);
		$this->notificationManager->method('notify')
			->willReturnCallback(function () use (&$currentUser, &$notifiedUsers): void {
				if ($currentUser === 'brokenFollower') {
					throw new \InvalidArgumentException('The given user is invalid');
				}
				$notifiedUsers[] = $currentUser;
			});
		$this->notificationManager->expects($this->once())
			->method('flush');
		$this->logger->expects($this->once())
			->method('error');

		$room = $this->createMock(Room::class);
		$room->method('getId')->willReturn(1234);
		$room->method('getToken')->willReturn('Token123');

		$this->threadService->method('findAttendeesForNotificationByThreadId')
			->willReturn([
				11 => $this->newThreadAttendee(11, Participant::NOTIFY_ALWAYS),
				12 => $this->newThreadAttendee(12, Participant::NOTIFY_ALWAYS),
			]);
		$this->participantService->method('getParticipantsByAttendeeId')
			->willReturn([
				$this->newParticipant($room, 11, Attendee::ACTOR_USERS, 'brokenFollower'),
				$this->newParticipant($room, 12, Attendee::ACTOR_USERS, 'follower'),
			]);

		$actor = $this->newParticipant($room, 1, Attendee::ACTOR_USERS, 'actor');

		$this->getNotifier()->notifyThreadStateChange($room, $actor, 42, 'thread_locked', [
			'thread' => 42,
			'title' => 'Thread 1',
		]);

		$this->assertSame(['follower'], $notifiedUsers);
	}

	/**
	 * Story 4.2: the actor is excluded by attendee id. A different participant who
	 * happens to share the actor's actor id in another actor type is a different
	 * attendee and still hears about the transition.
	 */
	public function testNotifyThreadStateChangeExcludesTheActorByAttendeeId(): void {
		$notifiedUsers = [];
		$capturedSubject = $capturedSubjectData = $capturedObject = null;
		$this->captureThreadStateNotification($notifiedUsers, $capturedSubject, $capturedSubjectData, $capturedObject);

		$room = $this->createMock(Room::class);
		$room->method('getId')->willReturn(1234);
		$room->method('getToken')->willReturn('Token123');

		$this->threadService->method('findAttendeesForNotificationByThreadId')
			->willReturn([
				11 => $this->newThreadAttendee(11, Participant::NOTIFY_ALWAYS),
			]);
		$this->participantService->method('getParticipantsByAttendeeId')
			->willReturn([
				// Same actor id, different attendee - a user account that also joined
				// the room from a federated server is not the acting moderator.
				$this->newParticipant($room, 11, Attendee::ACTOR_USERS, 'actor'),
			]);

		$actor = $this->newParticipant($room, 1, Attendee::ACTOR_FEDERATED_USERS, 'actor');

		$this->getNotifier()->notifyThreadStateChange($room, $actor, 42, 'thread_closed', [
			'thread' => 42,
			'title' => 'Thread 1',
		]);

		$this->assertSame(['actor'], $notifiedUsers);
	}
}
