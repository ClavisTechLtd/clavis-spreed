<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Chat;

use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Chat\CommentsManager;
use OCA\Talk\Chat\MessageParser;
use OCA\Talk\Chat\Notifier;
use OCA\Talk\Chat\ReactionManager;
use OCA\Talk\Exceptions\ThreadProperty\LockedException;
use OCA\Talk\Room;
use OCA\Talk\Service\ThreadService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\IComment;
use OCP\Comments\NotFoundException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * Story 1.7, AC1, AC5, AC7: ReactionManager::addReactionMessage() and
 * deleteReactionMessage() reach commentsManager->save() directly, never
 * through ChatManager::sendMessage()/addSystemMessage(), so they are their
 * own call sites of the shared ThreadService::ensureNotLocked() guard - the
 * third enforcement seam this story introduces, alongside
 * ChatManager::editMessage()/deleteMessage()/pinMessage()/unpinMessage()
 * (see ChatManagerTest.php).
 */
class ReactionManagerTest extends TestCase {
	protected ChatManager&MockObject $chatManager;
	protected CommentsManager&MockObject $commentsManager;
	protected IL10N&MockObject $l;
	protected MessageParser&MockObject $messageParser;
	protected Notifier&MockObject $notifier;
	protected IEventDispatcher&MockObject $dispatcher;
	protected ITimeFactory&MockObject $timeFactory;
	protected ThreadService&MockObject $threadService;
	protected ?ReactionManager $reactionManager = null;

	public function setUp(): void {
		parent::setUp();

		$this->chatManager = $this->createMock(ChatManager::class);
		$this->commentsManager = $this->createMock(CommentsManager::class);
		$this->l = $this->createMock(IL10N::class);
		$this->messageParser = $this->createMock(MessageParser::class);
		$this->notifier = $this->createMock(Notifier::class);
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->threadService = $this->createMock(ThreadService::class);

		$this->reactionManager = new ReactionManager(
			$this->chatManager,
			$this->commentsManager,
			$this->l,
			$this->messageParser,
			$this->notifier,
			$this->dispatcher,
			$this->timeFactory,
			$this->threadService,
		);
	}

	/**
	 * @param array $data
	 * @return IComment&MockObject
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

	public function testAddReactionMessageRefusesLockedThread(): void {
		$chat = $this->createMock(Room::class);
		$chat->method('getId')->willReturn(1234);

		$this->commentsManager->method('supportReactions')->willReturn(true);
		$parentMessage = $this->newCommentFromArray([
			'id' => 55,
			'topmostParentId' => '0',
			'objectType' => 'chat',
			'objectId' => '1234',
			'verb' => ChatManager::VERB_MESSAGE,
		]);
		$this->commentsManager->method('get')->with('55')->willReturn($parentMessage);

		$this->threadService->method('ensureNotLocked')->with(1234, 55)
			->willThrowException(new LockedException(LockedException::REASON_LOCKED));

		$this->commentsManager->expects($this->never())->method('getReactionComment');
		$this->commentsManager->expects($this->never())->method('save');

		$this->expectException(LockedException::class);
		$this->reactionManager->addReactionMessage($chat, 'users', 'user1', 'User One', 55, '👍');
	}

	public function testDeleteReactionMessageRefusesLockedThread(): void {
		$chat = $this->createMock(Room::class);
		$chat->method('getId')->willReturn(1234);

		$this->commentsManager->method('supportReactions')->willReturn(true);
		$parentComment = $this->newCommentFromArray([
			'id' => 55,
			'topmostParentId' => '0',
			'objectType' => 'chat',
			'objectId' => '1234',
			'verb' => ChatManager::VERB_MESSAGE,
		]);
		$this->commentsManager->method('get')->with('55')->willReturn($parentComment);

		$this->threadService->method('ensureNotLocked')->with(1234, 55)
			->willThrowException(new LockedException(LockedException::REASON_LOCKED));

		$this->dispatcher->expects($this->never())->method('dispatchTyped');
		$this->commentsManager->expects($this->never())->method('getReactionComment');
		$this->commentsManager->expects($this->never())->method('save');

		$this->expectException(LockedException::class);
		$this->reactionManager->deleteReactionMessage($chat, 'users', 'user1', 'User One', 55, '👍');
	}

	/**
	 * Story 1.7, AC6: once the Thread is unlocked (or was never Locked),
	 * addReactionMessage() proceeds normally - ensureNotLocked() is
	 * called with the resolved thread id (proving the guard is wired in)
	 * but does not throw, and the reaction is created and saved as
	 * before. deleteReactionMessage()'s equivalent "not blocked" path
	 * relies on the same, already-unit-tested ensureNotLocked()
	 * behaviour (Story 1.6's ThreadServiceTest.php) and is additionally
	 * exercised end-to-end by this story's Behat AC6 scenario.
	 */
	public function testAddReactionMessageProceedsWhenThreadIsNotLocked(): void {
		$chat = $this->createMock(Room::class);
		$chat->method('getId')->willReturn(1234);

		$this->commentsManager->method('supportReactions')->willReturn(true);
		$parentMessage = $this->newCommentFromArray([
			'id' => 55,
			'topmostParentId' => '0',
			'objectType' => 'chat',
			'objectId' => '1234',
			'verb' => ChatManager::VERB_MESSAGE,
			'expireDate' => null,
		]);
		$this->commentsManager->method('get')->with('55')->willReturn($parentMessage);

		$this->threadService->expects($this->once())->method('ensureNotLocked')->with(1234, 55);

		$this->commentsManager->method('getReactionComment')
			->willThrowException(new NotFoundException());

		$newComment = $this->createMock(IComment::class);
		$this->commentsManager->method('create')
			->with('users', 'user1', 'chat', '1234')
			->willReturn($newComment);

		$this->commentsManager->expects($this->once())->method('save')->with($newComment);
		$this->notifier->expects($this->once())->method('notifyReacted')->with($chat, $parentMessage, $newComment);

		$result = $this->reactionManager->addReactionMessage($chat, 'users', 'user1', 'User One', 55, '👍');
		$this->assertSame($newComment, $result);
	}
}
