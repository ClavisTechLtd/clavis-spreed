<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Chat;

use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Chat\CommentsManager;
use OCA\Talk\Chat\Notifier;
use OCA\Talk\Exceptions\ThreadProperty\LockedException;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\AttendeeMapper;
use OCA\Talk\Model\Invitation;
use OCA\Talk\Model\Thread;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\AttachmentService;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\Service\PollService;
use OCA\Talk\Service\RoomService;
use OCA\Talk\Service\ThreadService;
use OCA\Talk\Share\RoomShareProvider;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Collaboration\Reference\IReferenceManager;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;
use OCP\Security\RateLimiting\ILimiter;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

#[Group('DB')]
class ChatManagerTest extends TestCase {
	protected CommentsManager|ICommentsManager|MockObject $commentsManager;
	protected IEventDispatcher&MockObject $dispatcher;
	protected INotificationManager&MockObject $notificationManager;
	protected IManager&MockObject $shareManager;
	protected RoomShareProvider&MockObject $shareProvider;
	protected ParticipantService&MockObject $participantService;
	protected RoomService&MockObject $roomService;
	protected PollService&MockObject $pollService;
	protected ThreadService&MockObject $threadService;
	protected Notifier&MockObject $notifier;
	protected ITimeFactory&MockObject $timeFactory;
	protected AttachmentService&MockObject $attachmentService;
	protected IReferenceManager&MockObject $referenceManager;
	protected ILimiter&MockObject $rateLimiter;
	protected IRequest&MockObject $request;
	protected IJobList&MockObject $jobList;
	protected LoggerInterface&MockObject $logger;
	protected IL10N&MockObject $l;
	protected ?ChatManager $chatManager = null;

	public function setUp(): void {
		parent::setUp();

		$this->commentsManager = $this->createMock(CommentsManager::class);
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->shareManager = $this->createMock(IManager::class);
		$this->shareProvider = $this->createMock(RoomShareProvider::class);
		$this->participantService = $this->createMock(ParticipantService::class);
		$this->roomService = $this->createMock(RoomService::class);
		$this->pollService = $this->createMock(PollService::class);
		$this->threadService = $this->createMock(ThreadService::class);
		$this->notifier = $this->createMock(Notifier::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->attachmentService = $this->createMock(AttachmentService::class);
		$this->referenceManager = $this->createMock(IReferenceManager::class);
		$this->rateLimiter = $this->createMock(ILimiter::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->request = $this->createMock(IRequest::class);
		$this->l = $this->createMock(IL10N::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->l->method('n')
			->willReturnCallback(function (string $singular, string $plural, int $count, array $parameters = []) {
				$text = $count === 1 ? $singular : $plural;
				return vsprintf(str_replace('%n', (string)$count, $text), $parameters);
			});

		$this->chatManager = $this->getManager();
	}

	/**
	 * @param string[] $methods
	 * @return ChatManager|MockObject
	 */
	protected function getManager(array $methods = []): ChatManager {
		$cacheFactory = $this->createMock(ICacheFactory::class);

		if (!empty($methods)) {
			return $this->getMockBuilder(ChatManager::class)
				->setConstructorArgs([
					$this->commentsManager,
					$this->dispatcher,
					\OCP\Server::get(IDBConnection::class),
					$this->notificationManager,
					$this->shareManager,
					$this->shareProvider,
					$this->participantService,
					$this->roomService,
					$this->pollService,
					$this->threadService,
					$this->notifier,
					$cacheFactory,
					$this->timeFactory,
					$this->attachmentService,
					$this->referenceManager,
					$this->rateLimiter,
					$this->request,
					$this->jobList,
					$this->l,
					$this->logger,
				])
				->onlyMethods($methods)
				->getMock();
		}

		return new ChatManager(
			$this->commentsManager,
			$this->dispatcher,
			\OCP\Server::get(IDBConnection::class),
			$this->notificationManager,
			$this->shareManager,
			$this->shareProvider,
			$this->participantService,
			$this->roomService,
			$this->pollService,
			$this->threadService,
			$this->notifier,
			$cacheFactory,
			$this->timeFactory,
			$this->attachmentService,
			$this->referenceManager,
			$this->rateLimiter,
			$this->request,
			$this->jobList,
			$this->l,
			$this->logger,
		);
	}

	private function newComment($id, string $actorType, string $actorId, \DateTime $creationDateTime, string $message): IComment {
		$comment = $this->createMock(IComment::class);

		$id = (string)$id;

		$comment->method('getId')->willReturn($id);
		$comment->method('getActorType')->willReturn($actorType);
		$comment->method('getActorId')->willReturn($actorId);
		$comment->method('getCreationDateTime')->willReturn($creationDateTime);
		$comment->method('getMessage')->willReturn($message);

		return $comment;
	}

	/**
	 * @param array $data
	 * @return IComment|MockObject
	 */
	private function newCommentFromArray(array $data): IComment {
		$comment = $this->createMock(IComment::class);

		foreach ($data as $key => $value) {
			if ($key === 'id') {
				$value = (string)$value;
			}
			$comment->method('get' . ucfirst((string)$key))->willReturn($value);
		}

		return $comment;
	}

	protected function assertCommentEquals(array $data, IComment $comment): void {
		if (isset($data['id'])) {
			$id = $data['id'];
			unset($data['id']);
			$this->assertEquals($id, $comment->getId());
		}

		$this->assertEquals($data, [
			'actorType' => $comment->getActorType(),
			'actorId' => $comment->getActorId(),
			'creationDateTime' => $comment->getCreationDateTime(),
			'message' => $comment->getMessage(),
			'referenceId' => $comment->getReferenceId(),
			'parentId' => $comment->getParentId(),
		]);
	}

	public static function dataSendMessage(): array {
		return [
			'simple message' => ['testUser1', 'testMessage1', '', '0'],
			'reference id' => ['testUser2', 'testMessage2', 'referenceId2', '0'],
			'as a reply' => ['testUser3', 'testMessage3', '', '23'],
			'reply w/ ref' => ['testUser4', 'testMessage4', 'referenceId4', '23'],
		];
	}

	#[DataProvider('dataSendMessage')]
	public function testSendMessage(string $userId, string $message, string $referenceId, string $parentId): void {
		$creationDateTime = new \DateTime();

		$commentExpected = [
			'actorType' => 'users',
			'actorId' => $userId,
			'creationDateTime' => $creationDateTime,
			'message' => $message,
			'referenceId' => $referenceId,
			'parentId' => $parentId,
		];

		$comment = $this->newCommentFromArray($commentExpected);

		if ($parentId !== '0') {
			$replyTo = $this->newCommentFromArray([
				'id' => $parentId,
			]);

			$comment->expects($this->once())
				->method('setParentId')
				->with($parentId);
		} else {
			$replyTo = null;
		}

		$this->commentsManager->expects($this->once())
			->method('create')
			->with('users', $userId, 'chat', 1234)
			->willReturn($comment);

		$comment->expects($this->once())
			->method('setMessage')
			->with($message);

		$comment->expects($this->once())
			->method('setCreationDateTime')
			->with($creationDateTime);

		$comment->expects($referenceId === '' ? $this->never() : $this->once())
			->method('setReferenceId')
			->with($referenceId);

		$comment->expects($this->once())
			->method('setVerb')
			->with('comment');

		$this->commentsManager->expects($this->once())
			->method('save')
			->with($comment);

		$chat = $this->createMock(Room::class);
		$chat->expects($this->any())
			->method('getId')
			->willReturn(1234);

		$this->notifier->expects($this->once())
			->method('notifyMentionedUsers')
			->with($chat, $comment);

		$participant = $this->createMock(Participant::class);

		$return = $this->chatManager->sendMessage($chat, $participant, 'users', $userId, $message, $creationDateTime, $replyTo, $referenceId, false);

		$this->assertCommentEquals($commentExpected, $return);
	}

	/**
	 * Story 1.5, AC1, AC3, AC4: sendMessage()'s reply branch already
	 * resolves an effective thread id (via validateThread()) before
	 * commentsManager->save(); once updateLastMessageInfoAfterReply()
	 * confirms it names a real Thread, ThreadService::reviveIfClosed() is
	 * called with that same id - no second resolution, no new
	 * findByThreadId()/validateThread() call added at this call site.
	 */
	public function testSendMessageRevivesThreadOnReply(): void {
		$creationDateTime = new \DateTime();

		$comment = $this->newCommentFromArray([
			'id' => '99',
			'actorType' => 'users',
			'actorId' => 'testUser1',
			'creationDateTime' => $creationDateTime,
			'message' => 'testMessage1',
			'referenceId' => '',
			'parentId' => '23',
		]);
		$replyTo = $this->newCommentFromArray([
			'id' => '23',
			'topmostParentId' => '0',
		]);

		$this->commentsManager->method('create')->willReturn($comment);

		$chat = $this->createMock(Room::class);
		$chat->method('getId')->willReturn(1234);

		$this->threadService->method('validateThread')->with(1234, 23)->willReturn(true);
		$this->threadService->method('updateLastMessageInfoAfterReply')->with(23, 99, 1234)->willReturn(true);
		$this->threadService->expects($this->once())->method('reviveIfClosed')->with(1234, 23);

		$participant = $this->createMock(Participant::class);

		$this->chatManager->sendMessage($chat, $participant, 'users', 'testUser1', 'testMessage1', $creationDateTime, $replyTo, '', false);
	}

	/**
	 * Story 1.5, AC1, AC3, AC4: the explicit-$threadId branch (no
	 * $replyTo) is the shape used by the bot API, an in-process bot
	 * answering an invocation event, and a scheduled message firing -
	 * every one of them must revive a Closed Thread the same way.
	 */
	public function testSendMessageRevivesThreadOnExplicitThreadId(): void {
		$creationDateTime = new \DateTime();

		$comment = $this->newCommentFromArray([
			'id' => '99',
			'actorType' => 'bots',
			'actorId' => 'sample_bot',
			'creationDateTime' => $creationDateTime,
			'message' => 'testMessage1',
			'referenceId' => '',
			'parentId' => '55',
		]);

		$this->commentsManager->method('create')->willReturn($comment);

		$chat = $this->createMock(Room::class);
		$chat->method('getId')->willReturn(1234);

		// Story 1.6, AC2: the explicit-$threadId branch now validates
		// before resolving, mirroring the reply branch - required for
		// $threadId to resolve to anything other than THREAD_NONE.
		$this->threadService->method('validateThread')->with(1234, 55)->willReturn(true);
		$this->threadService->method('updateLastMessageInfoAfterReply')->with(55, 99, 1234)->willReturn(true);
		$this->threadService->expects($this->once())->method('reviveIfClosed')->with(1234, 55);

		$this->chatManager->sendMessage($chat, null, 'bots', 'sample_bot', 'testMessage1', $creationDateTime, null, '', false, threadId: 55);
	}

	/**
	 * Story 1.5, AC3: a stale or fabricated thread id - one
	 * updateLastMessageInfoAfterReply() reports does not name a real
	 * Thread - must not trigger a revival attempt.
	 */
	public function testSendMessageDoesNotReviveWhenThreadIdIsStale(): void {
		$creationDateTime = new \DateTime();

		$comment = $this->newCommentFromArray([
			'id' => '99',
			'actorType' => 'users',
			'actorId' => 'testUser1',
			'creationDateTime' => $creationDateTime,
			'message' => 'testMessage1',
			'referenceId' => '',
			'parentId' => '55',
		]);

		$this->commentsManager->method('create')->willReturn($comment);

		$chat = $this->createMock(Room::class);
		$chat->method('getId')->willReturn(1234);

		$this->threadService->method('updateLastMessageInfoAfterReply')->willReturn(false);
		$this->threadService->expects($this->never())->method('reviveIfClosed');

		$participant = $this->createMock(Participant::class);

		$this->chatManager->sendMessage($chat, $participant, 'users', 'testUser1', 'testMessage1', $creationDateTime, null, '', false, threadId: 55);
	}

	/**
	 * Story 1.5, AC3: a plain, non-thread message (no $replyTo, no
	 * $threadId) never attempts a revival at all.
	 */
	public function testSendMessageDoesNotReviveWhenNotAThreadMessage(): void {
		$creationDateTime = new \DateTime();

		$comment = $this->newCommentFromArray([
			'id' => '99',
			'actorType' => 'users',
			'actorId' => 'testUser1',
			'creationDateTime' => $creationDateTime,
			'message' => 'testMessage1',
			'referenceId' => '',
			'parentId' => '0',
		]);

		$this->commentsManager->method('create')->willReturn($comment);

		$chat = $this->createMock(Room::class);
		$chat->method('getId')->willReturn(1234);

		$this->threadService->expects($this->never())->method('updateLastMessageInfoAfterReply');
		$this->threadService->expects($this->never())->method('reviveIfClosed');

		$participant = $this->createMock(Participant::class);

		$this->chatManager->sendMessage($chat, $participant, 'users', 'testUser1', 'testMessage1', $creationDateTime, null, '', false);
	}

	/**
	 * Story 1.5, AC3: creating a brand-new Thread from this message
	 * (THREAD_CREATE) never attempts a revival - there is no existing
	 * Thread to revive.
	 */
	public function testSendMessageDoesNotReviveWhenCreatingNewThread(): void {
		$creationDateTime = new \DateTime();

		$comment = $this->newCommentFromArray([
			'id' => '99',
			'actorType' => 'users',
			'actorId' => 'testUser1',
			'creationDateTime' => $creationDateTime,
			'message' => 'testMessage1',
			'referenceId' => '',
			'parentId' => '0',
		]);

		$this->commentsManager->method('create')->willReturn($comment);

		$chat = $this->createMock(Room::class);
		$chat->method('getId')->willReturn(1234);

		$this->threadService->expects($this->never())->method('updateLastMessageInfoAfterReply');
		$this->threadService->expects($this->never())->method('reviveIfClosed');

		$participant = $this->createMock(Participant::class);

		$this->chatManager->sendMessage($chat, $participant, 'users', 'testUser1', 'testMessage1', $creationDateTime, null, '', false, threadId: Thread::THREAD_CREATE, threadTitle: 'New thread');
	}

	/**
	 * Story 1.6, AC1, AC3: a reply into a Locked Thread is refused
	 * before commentsManager->save() is ever called - a refusal after
	 * the save would not be a refusal.
	 */
	public function testSendMessageRefusesReplyIntoLockedThread(): void {
		$creationDateTime = new \DateTime();

		$replyTo = $this->newCommentFromArray([
			'id' => '23',
			'topmostParentId' => '0',
		]);

		$chat = $this->createMock(Room::class);
		$chat->method('getId')->willReturn(1234);

		$this->threadService->method('validateThread')->with(1234, 23)->willReturn(true);
		$this->threadService->method('ensureNotLocked')->with(1234, 23)->willThrowException(new LockedException(LockedException::REASON_LOCKED));
		$this->commentsManager->expects($this->never())->method('save');

		$participant = $this->createMock(Participant::class);

		$this->expectException(LockedException::class);
		$this->chatManager->sendMessage($chat, $participant, 'users', 'testUser1', 'testMessage1', $creationDateTime, $replyTo, '', false);
	}

	/**
	 * Story 1.6, AC1, AC2, AC3: the explicit-$threadId branch (bot API,
	 * scheduled message) is refused identically - this is the literal
	 * test for AC2's closed validateThread() asymmetry: without it, this
	 * branch would never resolve a real Thread and could never observe
	 * Locked at all.
	 */
	public function testSendMessageRefusesExplicitThreadIdIntoLockedThread(): void {
		$creationDateTime = new \DateTime();

		$chat = $this->createMock(Room::class);
		$chat->method('getId')->willReturn(1234);

		$this->threadService->method('validateThread')->with(1234, 55)->willReturn(true);
		$this->threadService->method('ensureNotLocked')->with(1234, 55)->willThrowException(new LockedException(LockedException::REASON_LOCKED));
		$this->commentsManager->expects($this->never())->method('save');

		$this->expectException(LockedException::class);
		$this->chatManager->sendMessage($chat, null, 'bots', 'sample_bot', 'testMessage1', $creationDateTime, null, '', false, threadId: 55);
	}

	/**
	 * Story 1.6, AC2: a stale/foreign explicit $threadId that fails
	 * validateThread() falls back to THREAD_NONE (posts into the main
	 * chat, the existing tolerant behaviour) rather than being treated as
	 * Locked - AC2 closes the *validation* gap, it does not change what
	 * an invalid id does.
	 */
	public function testSendMessageDoesNotRefuseExplicitThreadIdWhenStale(): void {
		$creationDateTime = new \DateTime();

		$comment = $this->newCommentFromArray([
			'id' => '99',
			'actorType' => 'users',
			'actorId' => 'testUser1',
			'creationDateTime' => $creationDateTime,
			'message' => 'testMessage1',
			'referenceId' => '',
			'parentId' => '0',
		]);
		$this->commentsManager->method('create')->willReturn($comment);

		$chat = $this->createMock(Room::class);
		$chat->method('getId')->willReturn(1234);

		$this->threadService->method('validateThread')->with(1234, 55)->willReturn(false);
		$this->threadService->expects($this->never())->method('ensureNotLocked');
		$this->threadService->expects($this->never())->method('updateLastMessageInfoAfterReply');

		$participant = $this->createMock(Participant::class);

		$this->chatManager->sendMessage($chat, $participant, 'users', 'testUser1', 'testMessage1', $creationDateTime, null, '', false, threadId: 55);
	}

	/**
	 * Story 1.6, AC3: an Ongoing (or Closed - Story 1.5's asymmetry, a
	 * reply revives it) Thread never refuses.
	 */
	public function testSendMessageDoesNotRefuseWhenThreadIsNotLocked(): void {
		$creationDateTime = new \DateTime();

		$comment = $this->newCommentFromArray([
			'id' => '99',
			'actorType' => 'users',
			'actorId' => 'testUser1',
			'creationDateTime' => $creationDateTime,
			'message' => 'testMessage1',
			'referenceId' => '',
			'parentId' => '23',
		]);
		$replyTo = $this->newCommentFromArray([
			'id' => '23',
			'topmostParentId' => '0',
		]);
		$this->commentsManager->method('create')->willReturn($comment);

		$chat = $this->createMock(Room::class);
		$chat->method('getId')->willReturn(1234);

		$this->threadService->method('validateThread')->with(1234, 23)->willReturn(true);
		$this->threadService->expects($this->once())->method('ensureNotLocked')->with(1234, 23);
		$this->threadService->method('updateLastMessageInfoAfterReply')->willReturn(true);
		$this->commentsManager->expects($this->once())->method('save');

		$participant = $this->createMock(Participant::class);

		$this->chatManager->sendMessage($chat, $participant, 'users', 'testUser1', 'testMessage1', $creationDateTime, $replyTo, '', false);
	}

	/**
	 * Story 1.6: creating a brand-new Thread (THREAD_CREATE) never
	 * reaches the guard - a Thread that does not exist yet cannot be
	 * Locked.
	 */
	public function testSendMessageDoesNotCheckLockForThreadCreate(): void {
		$creationDateTime = new \DateTime();

		$comment = $this->newCommentFromArray([
			'id' => '99',
			'actorType' => 'users',
			'actorId' => 'testUser1',
			'creationDateTime' => $creationDateTime,
			'message' => 'testMessage1',
			'referenceId' => '',
			'parentId' => '0',
		]);
		$this->commentsManager->method('create')->willReturn($comment);

		$chat = $this->createMock(Room::class);
		$chat->method('getId')->willReturn(1234);

		$this->threadService->expects($this->never())->method('ensureNotLocked');
		$this->threadService->expects($this->never())->method('validateThread');

		$participant = $this->createMock(Participant::class);

		$this->chatManager->sendMessage($chat, $participant, 'users', 'testUser1', 'testMessage1', $creationDateTime, null, '', false, threadId: Thread::THREAD_CREATE, threadTitle: 'New thread');
	}

	/**
	 * Story 1.5, AC1, AC3: addSystemMessage()'s explicit-$threadId branch
	 * (the object-shared/poll-created shape - no $replyTo) already
	 * resolves the effective thread id after commentsManager->save() via
	 * getTopmostParentId(); once updateLastMessageInfoAfterReply()
	 * confirms it names a real Thread, ThreadService::reviveIfClosed() is
	 * called with that same id.
	 */
	public function testAddSystemMessageRevivesThreadOnExplicitThreadId(): void {
		$creationDateTime = new \DateTime();

		$comment = $this->newCommentFromArray([
			'id' => '99',
			'topmostParentId' => '55',
		]);

		$this->commentsManager->method('create')->willReturn($comment);

		$chat = $this->createMock(Room::class);
		$chat->method('getId')->willReturn(1234);
		$chat->method('getToken')->willReturn('token1234');

		$this->threadService->method('updateLastMessageInfoAfterReply')->with(55, 99, 1234)->willReturn(true);
		$this->threadService->expects($this->once())->method('reviveIfClosed')->with(1234, 55);

		$message = json_encode(['message' => 'object_shared', 'parameters' => []]);

		$this->chatManager->addSystemMessage($chat, null, 'users', 'testUser1', $message, $creationDateTime, false, null, null, false, false, 55);
	}

	/**
	 * Story 1.5, AC1, AC3: the $replyTo-plus-explicit-$threadId shape
	 * (file-share-into-a-reply / attachment-upload) also revives.
	 */
	public function testAddSystemMessageRevivesThreadOnReplyToRootComment(): void {
		$creationDateTime = new \DateTime();

		$comment = $this->newCommentFromArray([
			'id' => '99',
			'topmostParentId' => '55',
		]);
		$replyTo = $this->newCommentFromArray([
			'id' => '55',
		]);

		$this->commentsManager->method('create')->willReturn($comment);

		$chat = $this->createMock(Room::class);
		$chat->method('getId')->willReturn(1234);
		$chat->method('getToken')->willReturn('token1234');

		$this->threadService->method('updateLastMessageInfoAfterReply')->with(55, 99, 1234)->willReturn(true);
		$this->threadService->expects($this->once())->method('reviveIfClosed')->with(1234, 55);

		$message = json_encode(['message' => 'file_shared', 'parameters' => ['fileId' => '42']]);

		$this->chatManager->addSystemMessage($chat, null, 'users', 'testUser1', $message, $creationDateTime, false, null, $replyTo, false, false, 55);
	}

	/**
	 * Story 1.5, AC3: a stale/fabricated thread id never triggers a
	 * revival attempt.
	 */
	public function testAddSystemMessageDoesNotReviveWhenThreadIdIsStale(): void {
		$creationDateTime = new \DateTime();

		$comment = $this->newCommentFromArray([
			'id' => '99',
			'topmostParentId' => '55',
		]);

		$this->commentsManager->method('create')->willReturn($comment);

		$chat = $this->createMock(Room::class);
		$chat->method('getId')->willReturn(1234);
		$chat->method('getToken')->willReturn('token1234');

		$this->threadService->method('updateLastMessageInfoAfterReply')->willReturn(false);
		$this->threadService->expects($this->never())->method('reviveIfClosed');

		$message = json_encode(['message' => 'object_shared', 'parameters' => []]);

		$this->chatManager->addSystemMessage($chat, null, 'users', 'testUser1', $message, $creationDateTime, false, null, null, false, false, 55);
	}

	/**
	 * Story 1.5, AC2: Story 1.4's state-change/rename system messages
	 * (thread_closed, thread_locked, thread_reopened, thread_unlocked,
	 * thread_renamed) pass $shouldSkipLastMessageUpdate: true and so
	 * never reach updateLastMessageInfoAfterReply()/reviveIfClosed() at
	 * all - by the time they post, the Thread has already transitioned
	 * via ThreadController::setState()'s own changeState() call, and a
	 * rename is not content in the FR-3 sense.
	 */
	public function testAddSystemMessageDoesNotReviveWhenSkippingLastMessageUpdate(): void {
		$creationDateTime = new \DateTime();

		$comment = $this->newCommentFromArray([
			'id' => '99',
			'topmostParentId' => '55',
		]);
		$replyTo = $this->newCommentFromArray([
			'id' => '55',
		]);

		$this->commentsManager->method('create')->willReturn($comment);

		$chat = $this->createMock(Room::class);
		$chat->method('getId')->willReturn(1234);
		$chat->method('getToken')->willReturn('token1234');

		$this->threadService->expects($this->never())->method('updateLastMessageInfoAfterReply');
		$this->threadService->expects($this->never())->method('reviveIfClosed');

		$message = json_encode(['message' => 'thread_closed', 'parameters' => ['thread' => 55]]);

		$this->chatManager->addSystemMessage($chat, null, 'users', 'testUser1', $message, $creationDateTime, false, null, $replyTo, true, true, 55);
	}

	/**
	 * Story 1.5, AC3: a system message unrelated to any Thread (no
	 * $replyTo, no $threadId) never attempts a revival.
	 */
	public function testAddSystemMessageDoesNotReviveWhenNotAThreadMessage(): void {
		$creationDateTime = new \DateTime();

		$comment = $this->newCommentFromArray([
			'id' => '99',
			'topmostParentId' => '0',
		]);

		$this->commentsManager->method('create')->willReturn($comment);

		$chat = $this->createMock(Room::class);
		$chat->method('getId')->willReturn(1234);
		$chat->method('getToken')->willReturn('token1234');

		$this->threadService->expects($this->never())->method('updateLastMessageInfoAfterReply');
		$this->threadService->expects($this->never())->method('reviveIfClosed');

		$message = json_encode(['message' => 'call_started', 'parameters' => []]);

		$this->chatManager->addSystemMessage($chat, null, 'users', 'testUser1', $message, $creationDateTime, false);
	}

	/**
	 * Story 1.6, AC1, AC5: a rich object (or poll, same 'object_shared'
	 * verb - PollController::createPoll() and
	 * ChatController::shareObjectToChat() share it) shared into a Locked
	 * Thread is refused before commentsManager->save().
	 */
	public function testAddSystemMessageRefusesObjectSharedIntoLockedThread(): void {
		$creationDateTime = new \DateTime();

		$chat = $this->createMock(Room::class);
		$chat->method('getId')->willReturn(1234);
		$chat->method('getToken')->willReturn('token1234');

		$this->threadService->method('ensureNotLocked')->with(1234, 55)->willThrowException(new LockedException(LockedException::REASON_LOCKED));
		$this->commentsManager->expects($this->never())->method('save');

		$message = json_encode(['message' => 'object_shared', 'parameters' => []]);

		$this->expectException(LockedException::class);
		$this->chatManager->addSystemMessage($chat, null, 'users', 'testUser1', $message, $creationDateTime, false, null, null, false, false, 55);
	}

	/**
	 * Story 1.6, AC1, AC6: a file share (the $replyTo-plus-explicit-
	 * $threadId shape) into a Locked Thread is refused identically.
	 */
	public function testAddSystemMessageRefusesFileSharedIntoLockedThread(): void {
		$creationDateTime = new \DateTime();

		$replyTo = $this->newCommentFromArray([
			'id' => '55',
			'topmostParentId' => '0',
		]);

		$chat = $this->createMock(Room::class);
		$chat->method('getId')->willReturn(1234);
		$chat->method('getToken')->willReturn('token1234');

		$this->threadService->method('ensureNotLocked')->with(1234, 55)->willThrowException(new LockedException(LockedException::REASON_LOCKED));
		$this->commentsManager->expects($this->never())->method('save');

		$message = json_encode(['message' => 'file_shared', 'parameters' => ['fileId' => '42']]);

		$this->expectException(LockedException::class);
		$this->chatManager->addSystemMessage($chat, null, 'users', 'testUser1', $message, $creationDateTime, false, null, $replyTo, false, false, 55);
	}

	/**
	 * Story 1.6, AC12: the four lifecycle-transition verbs succeed into
	 * a Locked Thread - by the time this call happens the Thread has
	 * already transitioned via ThreadController::setState()'s own
	 * changeState() call (traced, not re-tested here - ThreadService is
	 * mocked in this test file). The exemption is an explicit allow-list
	 * (Listener::THREAD_MESSAGE_TYPES_WITH_CONTEXT), never "system
	 * messages are exempt".
	 */
	#[DataProvider('dataLifecycleAndManagementVerbsExemptFromTheLockGuard')]
	public function testAddSystemMessageDoesNotRefuseLifecycleAndManagementVerbs(string $verb): void {
		$creationDateTime = new \DateTime();

		$comment = $this->newCommentFromArray([
			'id' => '99',
			'topmostParentId' => '55',
		]);
		$replyTo = $this->newCommentFromArray([
			'id' => '55',
		]);

		$this->commentsManager->method('create')->willReturn($comment);
		$this->commentsManager->expects($this->once())->method('save');

		$chat = $this->createMock(Room::class);
		$chat->method('getId')->willReturn(1234);
		$chat->method('getToken')->willReturn('token1234');

		$this->threadService->expects($this->never())->method('ensureNotLocked');

		$message = json_encode(['message' => $verb, 'parameters' => ['thread' => 55]]);

		$this->chatManager->addSystemMessage($chat, null, 'users', 'testUser1', $message, $creationDateTime, false, null, $replyTo, true, true, 55);
	}

	public static function dataLifecycleAndManagementVerbsExemptFromTheLockGuard(): array {
		return [
			'thread_closed' => ['thread_closed'],
			'thread_locked' => ['thread_locked'],
			'thread_reopened' => ['thread_reopened'],
			'thread_unlocked' => ['thread_unlocked'],
			// Documented, deliberate widening beyond AC12's literal four
			// verbs (see Dev Notes): renaming is a management operation,
			// not content, and would otherwise regress.
			'thread_renamed' => ['thread_renamed'],
			'thread_created' => ['thread_created'],
		];
	}

	/**
	 * Story 1.6: a system message with no effective thread id at all
	 * (no $replyTo, no $threadId) never reaches the guard, regardless of
	 * message type - mirrors testAddSystemMessageDoesNotReviveWhenNotAThreadMessage.
	 */
	public function testAddSystemMessageDoesNotCheckLockWhenNotAThreadMessage(): void {
		$creationDateTime = new \DateTime();

		$comment = $this->newCommentFromArray([
			'id' => '99',
			'topmostParentId' => '0',
		]);

		$this->commentsManager->method('create')->willReturn($comment);

		$chat = $this->createMock(Room::class);
		$chat->method('getId')->willReturn(1234);
		$chat->method('getToken')->willReturn('token1234');

		$this->threadService->expects($this->never())->method('ensureNotLocked');

		$message = json_encode(['message' => 'object_shared', 'parameters' => []]);

		$this->chatManager->addSystemMessage($chat, null, 'users', 'testUser1', $message, $creationDateTime, false);
	}

	public function testGetHistory(): void {
		$offset = 1;
		$limit = 42;
		$expected = [
			$this->newComment(110, 'users', 'testUnknownUser', new \DateTime('@' . 1000000042), 'testMessage3'),
			$this->newComment(109, 'guests', 'testSpreedSession', new \DateTime('@' . 1000000023), 'testMessage2'),
			$this->newComment(108, 'users', 'testUser', new \DateTime('@' . 1000000016), 'testMessage1')
		];

		$chat = $this->createMock(Room::class);
		$chat->expects($this->any())
			->method('getId')
			->willReturn(1234);

		$this->commentsManager->expects($this->once())
			->method('getCommentsWithVerbForObjectSinceComment')
			->with('chat', 1234, [], $offset, 'desc', $limit)
			->willReturn($expected);

		$comments = $this->chatManager->getHistory($chat, $offset, $limit, false);

		$this->assertEquals($expected, $comments);
	}

	public function testWaitForNewMessages(): void {
		$offset = 1;
		$limit = 42;
		$timeout = 23;
		$expected = [
			$this->newComment(108, 'users', 'testUser', new \DateTime('@' . 1000000016), 'testMessage1'),
			$this->newComment(109, 'guests', 'testSpreedSession', new \DateTime('@' . 1000000023), 'testMessage2'),
			$this->newComment(110, 'users', 'testUnknownUser', new \DateTime('@' . 1000000042), 'testMessage3'),
		];

		$chat = $this->createMock(Room::class);
		$chat->expects($this->any())
			->method('getId')
			->willReturn(1234);

		$this->commentsManager->expects($this->once())
			->method('getCommentsWithVerbForObjectSinceComment')
			->with('chat', 1234, [], $offset, 'asc', $limit)
			->willReturn($expected);

		$this->notifier->expects($this->once())
			->method('markMentionNotificationsRead')
			->with($chat, 'userId');

		/** @var IUser&MockObject $user */
		$user = $this->createMock(IUser::class);
		$user->expects($this->any())
			->method('getUID')
			->willReturn('userId');

		$comments = $this->chatManager->waitForNewMessages($chat, $offset, $limit, $timeout, $user, false, true);

		$this->assertEquals($expected, $comments);
	}

	public function testWaitForNewMessagesWithWaiting(): void {
		$offset = 1;
		$limit = 42;
		$timeout = 23;
		$expected = [
			$this->newComment(108, 'users', 'testUser', new \DateTime('@' . 1000000016), 'testMessage1'),
			$this->newComment(109, 'guests', 'testSpreedSession', new \DateTime('@' . 1000000023), 'testMessage2'),
			$this->newComment(110, 'users', 'testUnknownUser', new \DateTime('@' . 1000000042), 'testMessage3'),
		];

		$chat = $this->createMock(Room::class);
		$chat->expects($this->any())
			->method('getId')
			->willReturn(1234);

		$this->commentsManager->expects($this->exactly(2))
			->method('getCommentsWithVerbForObjectSinceComment')
			->with('chat', 1234, [], $offset, 'asc', $limit)
			->willReturnOnConsecutiveCalls(
				[],
				$expected
			);

		$this->notifier->expects($this->once())
			->method('markMentionNotificationsRead')
			->with($chat, 'userId');

		/** @var IUser&MockObject $user */
		$user = $this->createMock(IUser::class);
		$user->expects($this->any())
			->method('getUID')
			->willReturn('userId');

		$comments = $this->chatManager->waitForNewMessages($chat, $offset, $limit, $timeout, $user, false, true);

		$this->assertEquals($expected, $comments);
	}

	public function testGetUnreadCount(): void {
		/** @var Room&MockObject $chat */
		$chat = $this->createMock(Room::class);
		$chat->expects($this->atLeastOnce())
			->method('getId')
			->willReturn(23);

		$this->commentsManager->expects($this->once())
			->method('getNumberOfCommentsWithVerbsForObjectSinceComment')
			->with('chat', 23, 42, ['comment', 'object_shared']);

		$this->chatManager->getUnreadCount($chat, 42);
	}

	public function testDeleteMessages(): void {
		$chat = $this->createMock(Room::class);
		$chat->expects($this->any())
			->method('getId')
			->willReturn(1234);

		$this->commentsManager->expects($this->once())
			->method('deleteCommentsAtObject')
			->with('chat', 1234);

		$this->notifier->expects($this->once())
			->method('removePendingNotificationsForRoom')
			->with($chat);

		$this->chatManager->deleteMessages($chat);
	}

	public function testDeleteMessage(): void {
		$mapper = new AttendeeMapper(\OCP\Server::get(IDBConnection::class));
		$attendee = $mapper->createAttendeeFromRow([
			'a_id' => 1,
			'room_id' => 123,
			'actor_type' => Attendee::ACTOR_USERS,
			'actor_id' => 'user',
			'display_name' => 'user-display',
			'pin' => '',
			'participant_type' => Participant::USER,
			'favorite' => true,
			'notification_level' => Participant::NOTIFY_MENTION,
			'notification_calls' => Participant::NOTIFY_CALLS_ON,
			'last_joined_call' => 0,
			'last_read_message' => 0,
			'last_mention_message' => 0,
			'last_mention_direct' => 0,
			'read_privacy' => Participant::PRIVACY_PUBLIC,
			'permissions' => Attendee::PERMISSIONS_DEFAULT,
			'access_token' => '',
			'remote_id' => '',
			'phone_number' => '',
			'call_id' => '',
			'invited_cloud_id' => '',
			'state' => Invitation::STATE_ACCEPTED,
			'unread_messages' => 0,
			'last_attendee_activity' => 0,
			'archived' => 0,
			'important' => 0,
			'sensitive' => 0,
			'tag_ids' => null,
			'has_unread_threads' => false,
			'has_unread_thread_mentions' => false,
			'has_unread_thread_directs' => false,
			'hidden_pinned_id' => 0,
			'has_scheduled_messages' => 0,
		]);
		$chat = $this->createMock(Room::class);
		$chat->expects($this->any())
			->method('getId')
			->willReturn(1234);
		$participant = new Participant($chat, $attendee, null);

		$date = new \DateTime();

		$comment = $this->createMock(IComment::class);
		$comment->method('getId')
			->willReturn('123456');
		$comment->method('getVerb')
			->willReturn('comment');
		$comment->expects($this->once())
			->method('setMessage');
		$comment->expects($this->once())
			->method('setVerb')
			->with('comment_deleted');

		$this->commentsManager->expects($this->once())
			->method('save')
			->with($comment);

		$systemMessage = $this->createMock(IComment::class);

		$chatManager = $this->getManager(['addSystemMessage']);
		$chatManager->expects($this->once())
			->method('addSystemMessage')
			->with($chat, $participant, Attendee::ACTOR_USERS, 'user', $this->anything(), $this->anything(), false, null, $comment)
			->willReturn($systemMessage);

		$this->assertSame($systemMessage, $chatManager->deleteMessage($chat, $comment, $participant, $date));
	}

	public function testDeleteMessageFileShare(): void {
		$mapper = new AttendeeMapper(\OCP\Server::get(IDBConnection::class));
		$attendee = $mapper->createAttendeeFromRow([
			'a_id' => 1,
			'room_id' => 123,
			'actor_type' => Attendee::ACTOR_USERS,
			'actor_id' => 'user',
			'display_name' => 'user-display',
			'pin' => '',
			'participant_type' => Participant::USER,
			'favorite' => true,
			'notification_level' => Participant::NOTIFY_MENTION,
			'notification_calls' => Participant::NOTIFY_CALLS_ON,
			'last_joined_call' => 0,
			'last_read_message' => 0,
			'last_mention_message' => 0,
			'last_mention_direct' => 0,
			'read_privacy' => Participant::PRIVACY_PUBLIC,
			'permissions' => Attendee::PERMISSIONS_DEFAULT,
			'access_token' => '',
			'remote_id' => '',
			'phone_number' => '',
			'call_id' => '',
			'invited_cloud_id' => '',
			'state' => Invitation::STATE_ACCEPTED,
			'unread_messages' => 0,
			'last_attendee_activity' => 0,
			'archived' => 0,
			'important' => 0,
			'sensitive' => 0,
			'tag_ids' => null,
			'has_unread_threads' => false,
			'has_unread_thread_mentions' => false,
			'has_unread_thread_directs' => false,
			'hidden_pinned_id' => 0,
			'has_scheduled_messages' => 0,
		]);
		$chat = $this->createMock(Room::class);
		$chat->expects($this->any())
			->method('getId')
			->willReturn(1234);
		$chat->expects($this->any())
			->method('getToken')
			->willReturn('T0k3N');
		$participant = new Participant($chat, $attendee, null);

		$date = new \DateTime();

		$comment = $this->createMock(IComment::class);
		$comment->method('getId')
			->willReturn('123456');
		$comment->method('getVerb')
			->willReturn('object_shared');
		$comment->expects($this->once())
			->method('getMessage')
			->willReturn(json_encode(['message' => 'file_shared', 'parameters' => ['share' => '42']]));
		$comment->expects($this->once())
			->method('setMessage');
		$comment->expects($this->once())
			->method('setVerb')
			->with('comment_deleted');

		$share = $this->createMock(IShare::class);
		$share->method('getShareType')
			->willReturn(IShare::TYPE_ROOM);
		$share->method('getSharedWith')
			->willReturn('T0k3N');
		$share->method('getShareOwner')
			->willReturn('user');

		$this->shareManager->method('getShareById')
			->with('ocRoomShare:42')
			->willReturn($share);

		$this->shareManager->expects($this->once())
			->method('deleteShare')
			->with($share);

		$this->commentsManager->expects($this->once())
			->method('save')
			->with($comment);

		$systemMessage = $this->createMock(IComment::class);

		$chatManager = $this->getManager(['addSystemMessage']);
		$chatManager->expects($this->once())
			->method('addSystemMessage')
			->with($chat, $participant, Attendee::ACTOR_USERS, 'user', $this->anything(), $this->anything(), false, null, $comment)
			->willReturn($systemMessage);

		$this->assertSame($systemMessage, $chatManager->deleteMessage($chat, $comment, $participant, $date));
	}

	public function testDeleteMessageFileShareNotFound(): void {
		$mapper = new AttendeeMapper(\OCP\Server::get(IDBConnection::class));
		$attendee = $mapper->createAttendeeFromRow([
			'a_id' => 1,
			'room_id' => 123,
			'actor_type' => Attendee::ACTOR_USERS,
			'actor_id' => 'user',
			'display_name' => 'user-display',
			'pin' => '',
			'participant_type' => Participant::USER,
			'favorite' => true,
			'notification_level' => Participant::NOTIFY_MENTION,
			'notification_calls' => Participant::NOTIFY_CALLS_ON,
			'last_joined_call' => 0,
			'last_read_message' => 0,
			'last_mention_message' => 0,
			'last_mention_direct' => 0,
			'read_privacy' => Participant::PRIVACY_PUBLIC,
			'permissions' => Attendee::PERMISSIONS_DEFAULT,
			'access_token' => '',
			'remote_id' => '',
			'phone_number' => '',
			'call_id' => '',
			'invited_cloud_id' => '',
			'state' => Invitation::STATE_ACCEPTED,
			'unread_messages' => 0,
			'last_attendee_activity' => 0,
			'archived' => 0,
			'important' => 0,
			'sensitive' => 0,
			'tag_ids' => null,
			'has_unread_threads' => false,
			'has_unread_thread_mentions' => false,
			'has_unread_thread_directs' => false,
			'hidden_pinned_id' => 0,
			'has_scheduled_messages' => 0,
		]);
		$chat = $this->createMock(Room::class);
		$chat->expects($this->any())
			->method('getId')
			->willReturn(1234);
		$participant = new Participant($chat, $attendee, null);

		$date = new \DateTime();

		$comment = $this->createMock(IComment::class);
		$comment->method('getId')
			->willReturn('123456');
		$comment->method('getVerb')
			->willReturn('object_shared');
		$comment->expects($this->once())
			->method('getMessage')
			->willReturn(json_encode(['message' => 'file_shared', 'parameters' => ['share' => '42']]));

		$this->shareManager->method('getShareById')
			->with('ocRoomShare:42')
			->willThrowException(new ShareNotFound());

		$this->commentsManager->expects($this->never())
			->method('save');

		$systemMessage = $this->createMock(IComment::class);

		$chatManager = $this->getManager(['addSystemMessage']);
		$chatManager->expects($this->never())
			->method('addSystemMessage');

		$this->expectException(ShareNotFound::class);
		$this->assertSame($systemMessage, $chatManager->deleteMessage($chat, $comment, $participant, $date));
	}

	/**
	 * Story 1.7, AC1, AC3, AC7: the third enforcement seam - deleteMessage()
	 * reaches commentsManager->save() directly (never through
	 * sendMessage()/addSystemMessage()), so it needs its own call to the
	 * single shared guard, evaluated before any write - the primary
	 * delete write must never happen when the Thread is Locked.
	 */
	public function testDeleteMessageRefusesLockedThread(): void {
		$chat = $this->createMock(Room::class);
		$chat->method('getId')->willReturn(1234);

		$participant = $this->createMock(Participant::class);

		$comment = $this->newCommentFromArray([
			'id' => 55,
			'topmostParentId' => '0',
		]);

		$this->threadService->method('ensureNotLocked')->with(1234, 55)
			->willThrowException(new LockedException(LockedException::REASON_LOCKED));
		$this->commentsManager->expects($this->never())->method('save');

		$this->expectException(LockedException::class);
		$this->chatManager->deleteMessage($chat, $comment, $participant, new \DateTime());
	}

	/**
	 * Story 1.7, AC1, AC2, AC7: same third-seam guard for editMessage(),
	 * evaluated before any other validation (including the empty-message
	 * check) or write.
	 */
	public function testEditMessageRefusesLockedThread(): void {
		$chat = $this->createMock(Room::class);
		$chat->method('getId')->willReturn(1234);

		$participant = $this->createMock(Participant::class);

		$comment = $this->newCommentFromArray([
			'id' => 55,
			'topmostParentId' => '0',
		]);

		$this->threadService->method('ensureNotLocked')->with(1234, 55)
			->willThrowException(new LockedException(LockedException::REASON_LOCKED));
		$this->commentsManager->expects($this->never())->method('save');

		$this->expectException(LockedException::class);
		$this->chatManager->editMessage($chat, $comment, $participant, new \DateTime(), 'New message');
	}

	/**
	 * Story 1.7, AC1, AC4, AC7: same third-seam guard for pinMessage(),
	 * evaluated before the already-pinned no-op check and any write.
	 */
	public function testPinMessageRefusesLockedThread(): void {
		$chat = $this->createMock(Room::class);
		$chat->method('getId')->willReturn(1234);

		$participant = $this->createMock(Participant::class);

		$comment = $this->newCommentFromArray([
			'id' => 55,
			'topmostParentId' => '0',
		]);

		$this->threadService->method('ensureNotLocked')->with(1234, 55)
			->willThrowException(new LockedException(LockedException::REASON_LOCKED));
		$this->commentsManager->expects($this->never())->method('save');

		$this->expectException(LockedException::class);
		$this->chatManager->pinMessage($chat, $comment, $participant, 0);
	}

	/**
	 * Story 1.7, AC1, AC4, AC7: same third-seam guard for unpinMessage(),
	 * evaluated before the not-pinned no-op check and any write.
	 */
	public function testUnpinMessageRefusesLockedThread(): void {
		$chat = $this->createMock(Room::class);
		$chat->method('getId')->willReturn(1234);

		$participant = $this->createMock(Participant::class);

		$comment = $this->newCommentFromArray([
			'id' => 55,
			'topmostParentId' => '0',
		]);

		$this->threadService->method('ensureNotLocked')->with(1234, 55)
			->willThrowException(new LockedException(LockedException::REASON_LOCKED));
		$this->commentsManager->expects($this->never())->method('save');

		$this->expectException(LockedException::class);
		$this->chatManager->unpinMessage($chat, $comment, $participant);
	}

	/**
	 * Story 1.7, AC6: once the Thread is unlocked (or was never Locked),
	 * pinMessage() proceeds normally - ensureNotLocked() is called with
	 * the resolved thread id (proving the guard is wired in) but does not
	 * throw, and the primary write happens as before. Representative of
	 * all four operations at this layer: deleteMessage()'s equivalent
	 * "not blocked" path is already covered by the pre-existing
	 * testDeleteMessage() above (an unconfigured ensureNotLocked() mock is
	 * a safe void no-op, so that test is unaffected by this story and
	 * still proves save() happens); editMessage()'s and unpinMessage()'s
	 * "not blocked" paths are exercised end-to-end by this story's new
	 * Behat AC6 scenario, since ensureNotLocked()'s own state-based
	 * behaviour (Ongoing/Closed never throw) is already unit-tested in
	 * ThreadServiceTest.php (Story 1.6) independently of the caller.
	 */
	public function testPinMessageProceedsWhenThreadIsNotLocked(): void {
		$chat = $this->createMock(Room::class);
		$chat->method('getId')->willReturn(1234);

		$attendee = $this->createMock(Attendee::class);
		$attendee->method('getActorType')->willReturn(Attendee::ACTOR_USERS);
		$attendee->method('getActorId')->willReturn('user1');
		$participant = $this->createMock(Participant::class);
		$participant->method('getAttendee')->willReturn($attendee);

		$comment = $this->newCommentFromArray([
			'id' => 55,
			'topmostParentId' => '0',
			'metaData' => [],
		]);

		$this->threadService->expects($this->once())->method('ensureNotLocked')->with(1234, 55);

		$message = $this->createMock(IComment::class);
		$message->method('getExpireDate')->willReturn(null);
		$message->method('getId')->willReturn('99');

		$chatManager = $this->getManager(['addSystemMessage']);
		$chatManager->expects($this->once())
			->method('addSystemMessage')
			->willReturn($message);

		$this->commentsManager->expects($this->once())->method('save')->with($comment);
		$this->roomService->expects($this->once())->method('setLastPinnedId')->with($chat, 55);
		$this->attachmentService->expects($this->once())->method('createAttachmentEntryGeneric');

		$result = $chatManager->pinMessage($chat, $comment, $participant, 0);
		$this->assertSame($message, $result);
	}

	public function testClearHistory(): void {
		$chat = $this->createMock(Room::class);
		$chat->expects($this->any())
			->method('getId')
			->willReturn(1234);
		$chat->expects($this->any())
			->method('getToken')
			->willReturn('t0k3n');

		$this->commentsManager->expects($this->once())
			->method('deleteCommentsAtObject')
			->with('chat', 1234);

		$this->shareProvider->expects($this->once())
			->method('deleteInRoom')
			->with('t0k3n');

		$this->notifier->expects($this->once())
			->method('removePendingNotificationsForRoom')
			->with($chat, true);

		$this->participantService->expects($this->once())
			->method('resetChatDetails')
			->with($chat);

		$date = new \DateTime();
		$this->timeFactory->method('getDateTime')
			->willReturn($date);

		$manager = $this->getManager(['addSystemMessage']);
		$manager->expects($this->once())
			->method('addSystemMessage')
			->with(
				$chat,
				null,
				'users',
				'admin',
				json_encode(['message' => 'history_cleared', 'parameters' => []]),
				$date,
				false
			);
		$manager->clearHistory($chat, 'users', 'admin');
	}

	public static function dataSearchIsPartOfConversationNameOrAtAll(): array {
		return [
			'found a in all' => [
				'a', 'room', true
			],
			'found h in here' => [
				'h', 'room', true
			],
			'case sensitive, not found A in all' => [
				'A', 'room', false
			],
			'case sensitive, not found H in here' => [
				'H', 'room', false
			],
			'non case sensitive, found r in room' => [
				'R', 'room', true
			],
			'found r in begin of room' => [
				'r', 'room', true
			],
			'found o in middle of room' => [
				'o', 'room', true
			],
			'not found all in middle of text' => [
				'notbeginingall', 'room', false
			],
			'not found here in middle of text' => [
				'notbegininghere', 'room', false
			],
			'not found room in middle of text' => [
				'notbeginingroom', 'room', false
			],
		];
	}

	#[DataProvider('dataSearchIsPartOfConversationNameOrAtAll')]
	public function testSearchIsPartOfConversationNameOrAtAll(string $search, string $roomDisplayName, bool $expected): void {
		$actual = self::invokePrivate($this->chatManager, 'searchIsPartOfConversationNameOrAtAll', [$search, $roomDisplayName]);
		$this->assertEquals($expected, $actual);
	}

	public static function dataAddConversationNotify(): array {
		return [
			[
				'',
				['getType' => Room::TYPE_ONE_TO_ONE],
				[],
				null,
				[],
			],
			[
				'',
				['getDisplayName' => 'test', 'getMentionPermissions' => 0],
				['getAttendee' => Attendee::fromRow([
					'actor_type' => Attendee::ACTOR_USERS,
					'actor_id' => 'user',
				])],
				324,
				[['id' => 'all', 'label' => 'test', 'source' => 'calls', 'mentionId' => 'all', 'details' => 'All 324 participants']]
			],
			[
				'',
				['getMentionPermissions' => 1],
				['hasModeratorPermissions' => false],
				null,
				[],
			],
			[
				'all',
				['getDisplayName' => 'test', 'getMentionPermissions' => 0],
				['getAttendee' => Attendee::fromRow([
					'actor_type' => Attendee::ACTOR_USERS,
					'actor_id' => 'user',
				])],
				1,
				[['id' => 'all', 'label' => 'test', 'source' => 'calls', 'mentionId' => 'all']],
			],
			[
				'all',
				['getDisplayName' => 'test', 'getMentionPermissions' => 1],
				[
					'getAttendee' => Attendee::fromRow([
						'actor_type' => Attendee::ACTOR_USERS,
						'actor_id' => 'user',
					]),
					'hasModeratorPermissions' => true,
				],
				8,
				[['id' => 'all', 'label' => 'test', 'source' => 'calls', 'mentionId' => 'all', 'details' => 'All 8 participants']],
			],
			[
				'here',
				['getDisplayName' => 'test', 'getMentionPermissions' => 0],
				['getAttendee' => Attendee::fromRow([
					'actor_type' => Attendee::ACTOR_GUESTS,
					'actor_id' => 'guest',
				])],
				12,
				[['id' => 'all', 'label' => 'test', 'source' => 'calls', 'mentionId' => 'all', 'details' => 'All 12 participants']],
			],
		];
	}

	#[DataProvider('dataAddConversationNotify')]
	public function testAddConversationNotify(string $search, array $roomMocks, array $participantMocks, ?int $totalCount, array $expected): void {
		$room = $this->createMock(Room::class);
		foreach ($roomMocks as $method => $return) {
			$room->expects($this->once())
				->method($method)
				->willReturn($return);
		}

		$participant = $this->createMock(Participant::class);
		foreach ($participantMocks as $method => $return) {
			$participant->expects($this->once())
				->method($method)
				->willReturn($return);
		}

		if ($totalCount !== null) {
			$this->participantService->method('getNumberOfUsers')
				->willReturn($totalCount);
		}

		$actual = $this->chatManager->addConversationNotify([], $search, $room, $participant);
		$this->assertEquals($expected, $actual);
	}

	#[DataProvider('dataIsSharedFile')]
	public function testIsSharedFile(string $message, bool $expected): void {
		$actual = $this->chatManager->isSharedFile($message);
		$this->assertEquals($expected, $actual);
	}

	public static function dataIsSharedFile(): array {
		return [
			['', false],
			[json_encode([]), false],
			[json_encode(['parameters' => '']), false],
			[json_encode(['parameters' => []]), false],
			[json_encode(['parameters' => ['share' => null]]), false],
			[json_encode(['parameters' => ['share' => '']]), false],
			[json_encode(['parameters' => ['share' => []]]), false],
			[json_encode(['parameters' => ['share' => 0]]), false],
			[json_encode(['parameters' => ['share' => 1]]), true],
		];
	}

	#[DataProvider('dataFilterCommentsWithNonExistingFiles')]
	public function testFilterCommentsWithNonExistingFiles(array $list, int $expectedCount): void {
		// Transform text messages in instance of comment and mock with the message
		foreach ($list as $key => $message) {
			$list[$key] = $this->createMock(IComment::class);
			$list[$key]->method('getMessage')
				->willReturn($message);
			$messageDecoded = json_decode((string)$message, true);
			if (isset($messageDecoded['parameters']['share']) && $messageDecoded['parameters']['share'] === 'notExists') {
				$this->shareProvider->expects($this->once())
					->method('getShareById')
					->with('notExists')
					->willThrowException(new ShareNotFound());
			}
		}
		if (count($list) !== $expectedCount) {
		}
		$result = $this->chatManager->filterCommentsWithNonExistingFiles($list);
		$this->assertCount($expectedCount, $result);
	}

	public static function dataFilterCommentsWithNonExistingFiles(): array {
		return [
			[[], 0],
			[[json_encode(['parameters' => ['not a shared file']])], 1],
			[[json_encode(['parameters' => ['share' => 'notExists']])], 0],
			[[json_encode(['parameters' => ['share' => 1]])], 1],
		];
	}
}
