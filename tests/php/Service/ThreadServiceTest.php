<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Service;

use OCA\Talk\Chat\CommentsManager;
use OCA\Talk\Exceptions\ThreadProperty\AuthorityException;
use OCA\Talk\Exceptions\ThreadProperty\LockedException;
use OCA\Talk\Exceptions\ThreadProperty\StateException;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\Thread;
use OCA\Talk\Model\ThreadAttendeeMapper;
use OCA\Talk\Model\ThreadMapper;
use OCA\Talk\Participant;
use OCA\Talk\Service\ThreadService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\IComment;
use OCP\Comments\NotFoundException;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * Story 1.2, AC4: ThreadService::validateState() is the typed refusal seam
 * for an out-of-range Thread state, added ahead of the state-change endpoint
 * (a later story's scope) so the refusal is real and testable now.
 *
 * Story 1.3, AC1-AC3, AC9, AC10: ThreadService::isThreadManager() is the
 * single implementation of Thread Manager authority (AD-3) - root-message
 * author OR moderator, with guests and bots excluded regardless of
 * authorship, degrading to moderators-only when the root comment cannot be
 * loaded. Story 1.3, AC5: ThreadService::ensureThreadManager() is the
 * throw-and-catch seam built on top of it.
 */
class ThreadServiceTest extends TestCase {
	protected IDBConnection&MockObject $connection;
	protected ThreadMapper&MockObject $threadMapper;
	protected ThreadAttendeeMapper&MockObject $threadAttendeeMapper;
	protected ITimeFactory&MockObject $timeFactory;
	protected ICacheFactory&MockObject $cacheFactory;
	protected ICache&MockObject $cache;
	protected CommentsManager&MockObject $commentsManager;
	protected ThreadService $service;

	public function setUp(): void {
		parent::setUp();

		$this->connection = $this->createMock(IDBConnection::class);
		$this->threadMapper = $this->createMock(ThreadMapper::class);
		$this->threadAttendeeMapper = $this->createMock(ThreadAttendeeMapper::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->cache = $this->createMock(ICache::class);
		$this->commentsManager = $this->createMock(CommentsManager::class);

		// ThreadService's constructor unconditionally calls createDistributed(),
		// so it must be stubbed even for a test that never touches the cache.
		$this->cacheFactory->method('createDistributed')
			->with('talk.threads')
			->willReturn($this->cache);

		$this->service = new ThreadService(
			$this->connection,
			$this->threadMapper,
			$this->threadAttendeeMapper,
			$this->timeFactory,
			$this->cacheFactory,
			$this->commentsManager,
		);
	}

	/**
	 * @param Attendee::ACTOR_* $actorType
	 */
	protected function createParticipant(bool $isGuest, bool $hasModeratorPermissions, string $actorType, string $actorId): Participant&MockObject {
		$attendee = Attendee::fromRow([
			'actor_type' => $actorType,
			'actor_id' => $actorId,
		]);

		$participant = $this->createMock(Participant::class);
		$participant->method('isGuest')->willReturn($isGuest);
		$participant->method('hasModeratorPermissions')->with(false)->willReturn($hasModeratorPermissions);
		$participant->method('getAttendee')->willReturn($attendee);

		return $participant;
	}

	protected function createThread(int $threadId, int $roomId): Thread {
		$thread = new Thread();
		$thread->setId($threadId);
		$thread->setRoomId($roomId);
		return $thread;
	}

	protected function createRootComment(string $actorType, string $actorId, ?int $roomId = 7): IComment&MockObject {
		$comment = $this->createMock(IComment::class);
		$comment->method('getObjectType')->willReturn('chat');
		$comment->method('getObjectId')->willReturn((string)$roomId);
		$comment->method('getActorType')->willReturn($actorType);
		$comment->method('getActorId')->willReturn($actorId);
		return $comment;
	}

	public static function dataValidStates(): array {
		return [
			'Ongoing' => [Thread::STATE_ONGOING],
			'Closed' => [Thread::STATE_CLOSED],
			'Locked' => [Thread::STATE_LOCKED],
		];
	}

	#[DataProvider('dataValidStates')]
	public function testValidateStateAcceptsEveryDefinedState(int $state): void {
		$this->service->validateState($state);

		// No exception thrown is the assertion; this line simply proves
		// execution reached past validateState() without throwing.
		$this->addToAssertionCount(1);
	}

	public static function dataInvalidStates(): array {
		return [
			'negative' => [-1],
			'one past the last defined value' => [3],
			'far out of range' => [999],
		];
	}

	#[DataProvider('dataInvalidStates')]
	public function testValidateStateRefusesOutOfRangeValues(int $state): void {
		try {
			$this->service->validateState($state);
			$this->fail('Expected StateException to be thrown for state ' . $state);
		} catch (StateException $e) {
			$this->assertSame(StateException::REASON_VALUE, $e->getReason());
		}
	}

	public function testValidateStateRefusalIsDistinctFromOtherThreadExceptionTypes(): void {
		// AC4: the refusal must be distinguishable from a permission failure
		// and from a Thread-not-found failure. Those failures are raised
		// through entirely different exception classes elsewhere in the
		// codebase (e.g. \OCP\AppFramework\Db\DoesNotExistException for
		// not-found); StateException is its own class specifically so a
		// catch (StateException) block can never accidentally also catch one
		// of those.
		$this->expectException(StateException::class);
		$this->expectExceptionMessage(StateException::REASON_VALUE);

		$this->service->validateState(42);
	}

	/**
	 * AC1: hasModeratorPermissions(false) alone is sufficient, and is
	 * checked first - the root comment must never be loaded for a
	 * moderator, since that would be an avoidable query on every row of a
	 * list (see Dev Notes "Known tradeoff - per-row authority query").
	 */
	public function testIsThreadManagerTrueForModeratorRegardlessOfAuthorship(): void {
		$thread = $this->createThread(42, 7);
		$participant = $this->createParticipant(isGuest: false, hasModeratorPermissions: true, actorType: Attendee::ACTOR_USERS, actorId: 'moderator1');

		$this->commentsManager->expects($this->never())->method('get');

		$this->assertTrue($this->service->isThreadManager($thread, $participant));
	}

	/**
	 * AC1: the Thread Root Message author qualifies without being a
	 * moderator.
	 */
	public function testIsThreadManagerTrueForRootMessageAuthorWhoIsNotModerator(): void {
		$thread = $this->createThread(42, 7);
		$participant = $this->createParticipant(isGuest: false, hasModeratorPermissions: false, actorType: Attendee::ACTOR_USERS, actorId: 'author1');
		$comment = $this->createRootComment(Attendee::ACTOR_USERS, 'author1');
		$this->commentsManager->method('get')->with('42')->willReturn($comment);

		$this->assertTrue($this->service->isThreadManager($thread, $participant));
	}

	public function testIsThreadManagerFalseForNeitherAuthorNorModerator(): void {
		$thread = $this->createThread(42, 7);
		$participant = $this->createParticipant(isGuest: false, hasModeratorPermissions: false, actorType: Attendee::ACTOR_USERS, actorId: 'someoneElse');
		$comment = $this->createRootComment(Attendee::ACTOR_USERS, 'author1');
		$this->commentsManager->method('get')->with('42')->willReturn($comment);

		$this->assertFalse($this->service->isThreadManager($thread, $participant));
	}

	/**
	 * AC9: a guest session is refused regardless of who created the
	 * Thread - even when the guest is, in fact, the Thread Root Message
	 * author. The root comment is never loaded for a guest either, since
	 * the outcome cannot change.
	 */
	public function testIsThreadManagerFalseForGuestEvenWhenRootMessageAuthor(): void {
		$thread = $this->createThread(42, 7);
		$participant = $this->createParticipant(isGuest: true, hasModeratorPermissions: false, actorType: Attendee::ACTOR_GUESTS, actorId: 'guest1');

		$this->commentsManager->expects($this->never())->method('get');

		$this->assertFalse($this->service->isThreadManager($thread, $participant));
	}

	/**
	 * Consistency Conventions: "Guests and bots are never Thread
	 * Managers." A bot is never a guest (Participant::isGuest() is false
	 * for it), so this exercises the separate bot-actor-type exclusion.
	 */
	public function testIsThreadManagerFalseForBotActor(): void {
		$thread = $this->createThread(42, 7);
		$participant = $this->createParticipant(isGuest: false, hasModeratorPermissions: false, actorType: Attendee::ACTOR_BOTS, actorId: 'bot1');

		$this->commentsManager->expects($this->never())->method('get');

		$this->assertFalse($this->service->isThreadManager($thread, $participant));
	}

	/**
	 * AC3: when the root comment cannot be loaded at all, only moderators
	 * qualify - a non-moderator is refused.
	 */
	public function testIsThreadManagerFalseWhenRootCommentCannotBeLoadedAndActorIsNotModerator(): void {
		$thread = $this->createThread(42, 7);
		$participant = $this->createParticipant(isGuest: false, hasModeratorPermissions: false, actorType: Attendee::ACTOR_USERS, actorId: 'someone');
		$this->commentsManager->method('get')->with('42')->willThrowException(new NotFoundException());

		$this->assertFalse($this->service->isThreadManager($thread, $participant));
	}

	/**
	 * AC3: "only moderators qualify" - a moderator still qualifies when
	 * the root comment cannot be loaded, because the moderator branch is
	 * evaluated first and never needs the comment at all.
	 */
	public function testIsThreadManagerTrueWhenRootCommentCannotBeLoadedButActorIsModerator(): void {
		$thread = $this->createThread(42, 7);
		$participant = $this->createParticipant(isGuest: false, hasModeratorPermissions: true, actorType: Attendee::ACTOR_USERS, actorId: 'moderator1');

		$this->commentsManager->expects($this->never())->method('get');

		$this->assertTrue($this->service->isThreadManager($thread, $participant));
	}

	/**
	 * AC2: the object-type/object-id check mirrors
	 * ChatManager::getComment() - a comment id that resolves to a comment
	 * belonging to a different room must be treated exactly as "cannot be
	 * loaded" (AC3), not as a match.
	 */
	public function testIsThreadManagerFalseWhenRootCommentBelongsToADifferentRoom(): void {
		$thread = $this->createThread(42, 7);
		$participant = $this->createParticipant(isGuest: false, hasModeratorPermissions: false, actorType: Attendee::ACTOR_USERS, actorId: 'author1');
		// Same actor, but the comment's object id (room 99) does not match
		// the Thread's room id (7) - e.g. a stale/reused comment id.
		$comment = $this->createRootComment(Attendee::ACTOR_USERS, 'author1', roomId: 99);
		$this->commentsManager->method('get')->with('42')->willReturn($comment);

		$this->assertFalse($this->service->isThreadManager($thread, $participant));
	}

	/**
	 * AC1, AC5: ensureThreadManager() throws the typed, distinguishable
	 * refusal Story 1.4 AC7 reuses.
	 */
	public function testEnsureThreadManagerThrowsAuthorityExceptionOnRefusal(): void {
		$thread = $this->createThread(42, 7);
		$participant = $this->createParticipant(isGuest: false, hasModeratorPermissions: false, actorType: Attendee::ACTOR_USERS, actorId: 'someoneElse');
		$comment = $this->createRootComment(Attendee::ACTOR_USERS, 'author1');
		$this->commentsManager->method('get')->with('42')->willReturn($comment);

		try {
			$this->service->ensureThreadManager($thread, $participant);
			$this->fail('Expected AuthorityException to be thrown');
		} catch (AuthorityException $e) {
			$this->assertSame(AuthorityException::REASON_PERMISSION, $e->getReason());
		}
	}

	public function testEnsureThreadManagerDoesNotThrowWhenAuthorized(): void {
		$thread = $this->createThread(42, 7);
		$participant = $this->createParticipant(isGuest: false, hasModeratorPermissions: true, actorType: Attendee::ACTOR_USERS, actorId: 'moderator1');

		$this->service->ensureThreadManager($thread, $participant);

		// No exception thrown is the assertion; this line simply proves
		// execution reached past ensureThreadManager() without throwing.
		$this->addToAssertionCount(1);
	}

	/**
	 * AC5: the authority refusal is distinguishable from a state-value
	 * refusal and from a not-found refusal (mirrors
	 * testValidateStateRefusalIsDistinctFromOtherThreadExceptionTypes()
	 * above, for the new exception class this story introduces).
	 */
	public function testAuthorityExceptionIsDistinctFromOtherThreadExceptionTypes(): void {
		$authorityException = new AuthorityException(AuthorityException::REASON_PERMISSION);
		$stateException = new StateException(StateException::REASON_VALUE);

		$this->assertNotInstanceOf(StateException::class, $authorityException);
		$this->assertNotInstanceOf(AuthorityException::class, $stateException);
	}

	/**
	 * Story 1.4, AC15: a real transition invalidates the cache entry - the
	 * mapper is updated and the cache entry is removed, never re-set from
	 * the entity the mutator held (AD-1).
	 */
	public function testChangeStateMutatesStateAndInvalidatesCacheOnRealTransition(): void {
		$thread = $this->createThread(42, 7);
		$thread->setState(Thread::STATE_ONGOING);

		$this->threadMapper->expects($this->once())->method('update')
			->with($this->callback(static fn (Thread $t): bool => $t->getState() === Thread::STATE_CLOSED))
			->willReturnArgument(0);
		$this->cache->expects($this->once())->method('remove')->with('thread/7/42');
		$this->cache->expects($this->never())->method('set');

		$result = $this->service->changeState($thread, Thread::STATE_CLOSED);

		$this->assertSame(Thread::STATE_CLOSED, $result->getState());
	}

	/**
	 * Story 1.4, AC8: requesting the state a Thread already has is a
	 * complete no-op - no mapper write, no cache invalidation, no error.
	 * This is what makes "closing an already-Closed Thread succeeds
	 * without duplicating the system message" true one layer up, in the
	 * controller: there is nothing here for it to react to.
	 */
	public function testChangeStateIsNoOpWhenTargetEqualsCurrentState(): void {
		$thread = $this->createThread(42, 7);
		$thread->setState(Thread::STATE_CLOSED);

		$this->threadMapper->expects($this->never())->method('update');
		$this->cache->expects($this->never())->method('remove');
		$this->cache->expects($this->never())->method('set');

		$result = $this->service->changeState($thread, Thread::STATE_CLOSED);

		$this->assertSame(Thread::STATE_CLOSED, $result->getState());
	}

	/**
	 * Story 1.4, AC10: a lock reason is trimmed and stored on the entity.
	 */
	public function testChangeStateLockingStoresTrimmedReason(): void {
		$thread = $this->createThread(42, 7);
		$thread->setState(Thread::STATE_ONGOING);

		$this->threadMapper->method('update')->willReturnArgument(0);

		$result = $this->service->changeState($thread, Thread::STATE_LOCKED, '  Repeated off-topic discussion  ');

		$this->assertSame(Thread::STATE_LOCKED, $result->getState());
		$this->assertSame('Repeated off-topic discussion', $result->getLockReason());
	}

	/**
	 * Story 1.4, AC11: whitespace-only is treated as no reason, and
	 * produces no error.
	 */
	public function testChangeStateLockingWithWhitespaceOnlyReasonStoresNull(): void {
		$thread = $this->createThread(42, 7);
		$thread->setState(Thread::STATE_ONGOING);

		$this->threadMapper->method('update')->willReturnArgument(0);

		$result = $this->service->changeState($thread, Thread::STATE_LOCKED, "   \t  ");

		$this->assertNull($result->getLockReason());
	}

	/**
	 * Story 1.4, AC11: omitting a reason entirely also succeeds and stores
	 * null.
	 */
	public function testChangeStateLockingWithoutReasonStoresNull(): void {
		$thread = $this->createThread(42, 7);
		$thread->setState(Thread::STATE_ONGOING);

		$this->threadMapper->method('update')->willReturnArgument(0);

		$result = $this->service->changeState($thread, Thread::STATE_LOCKED);

		$this->assertNull($result->getLockReason());
	}

	/**
	 * Story 1.4, AC11: the length bound is enforced, distinguishable by
	 * the exception message naming the field (mirrors
	 * BanService::createBan()'s `\InvalidArgumentException('internalNote')`
	 * precedent).
	 */
	public function testChangeStateRefusesReasonLongerThanTheBound(): void {
		$thread = $this->createThread(42, 7);
		$thread->setState(Thread::STATE_ONGOING);
		$tooLong = str_repeat('a', Thread::LOCK_REASON_MAX_LENGTH + 1);

		$this->threadMapper->expects($this->never())->method('update');

		try {
			$this->service->changeState($thread, Thread::STATE_LOCKED, $tooLong);
			$this->fail('Expected \InvalidArgumentException to be thrown');
		} catch (\InvalidArgumentException $e) {
			$this->assertSame('reason', $e->getMessage());
		}
	}

	/**
	 * Story 1.4: a reason submitted alongside a non-locking transition is
	 * silently ignored - it is only meaningful, and only validated, when
	 * the target state is Locked.
	 */
	public function testChangeStateIgnoresReasonForNonLockingTransitions(): void {
		$thread = $this->createThread(42, 7);
		$thread->setState(Thread::STATE_ONGOING);
		$this->threadMapper->method('update')->willReturnArgument(0);

		$result = $this->service->changeState($thread, Thread::STATE_CLOSED, str_repeat('a', Thread::LOCK_REASON_MAX_LENGTH + 1));

		$this->assertSame(Thread::STATE_CLOSED, $result->getState());
		$this->assertNull($result->getLockReason());
	}

	/**
	 * Story 1.4, AC12: locked with reason A, unlocked, then locked again
	 * with reason B - the column ends up holding B. Unlocking in between
	 * does not clear the stale value itself (nothing reads lockReason for
	 * a non-Locked Thread); the next real lock simply overwrites it.
	 */
	public function testChangeStateSequentialLockUnlockRelockEndsWithLatestReason(): void {
		$thread = $this->createThread(42, 7);
		$thread->setState(Thread::STATE_ONGOING);
		$this->threadMapper->method('update')->willReturnArgument(0);

		$thread = $this->service->changeState($thread, Thread::STATE_LOCKED, 'Reason A');
		$this->assertSame('Reason A', $thread->getLockReason());

		$thread = $this->service->changeState($thread, Thread::STATE_ONGOING);
		$thread = $this->service->changeState($thread, Thread::STATE_LOCKED, 'Reason B');

		$this->assertSame('Reason B', $thread->getLockReason());
	}

	/**
	 * Story 1.4: assumption documented in the story's Dev Notes -
	 * resubmitting the state a Locked Thread already has, even with a
	 * different reason string, is treated as a full no-op and does not
	 * update the stored reason.
	 */
	public function testChangeStateRelockingWhileAlreadyLockedDoesNotUpdateReason(): void {
		$thread = $this->createThread(42, 7);
		$thread->setState(Thread::STATE_LOCKED);
		$thread->setLockReason('Original reason');

		$this->threadMapper->expects($this->never())->method('update');

		$result = $this->service->changeState($thread, Thread::STATE_LOCKED, 'A different reason');

		$this->assertSame('Original reason', $result->getLockReason());
	}

	/**
	 * Story 1.4: changeState() reuses Story 1.2's validateState() rather
	 * than re-deriving the valid-value check.
	 */
	public function testChangeStateRefusesOutOfRangeState(): void {
		$thread = $this->createThread(42, 7);
		$thread->setState(Thread::STATE_ONGOING);

		$this->threadMapper->expects($this->never())->method('update');

		try {
			$this->service->changeState($thread, 999);
			$this->fail('Expected StateException to be thrown');
		} catch (StateException $e) {
			$this->assertSame(StateException::REASON_VALUE, $e->getReason());
		}
	}

	/**
	 * Story 1.5, AC1, AC2, AC6: reviveIfClosed() is the single shared
	 * mutation both ChatManager::sendMessage() and
	 * ChatManager::addSystemMessage() call whenever content is posted
	 * into an existing Thread. A Closed Thread transitions to Ongoing via
	 * changeState() - mapper update plus cache remove, never set (AD-1) -
	 * with no authority check, matching FR-3's "any participant who may
	 * post" rule (unlike Story 1.4's manager-initiated reopen).
	 */
	public function testReviveIfClosedTransitionsClosedThreadToOngoing(): void {
		$thread = $this->createThread(42, 7);
		$thread->setState(Thread::STATE_CLOSED);
		$this->cache->method('get')->with('thread/7/42')->willReturn($thread->toJson());

		$this->threadMapper->expects($this->once())->method('update')
			->with($this->callback(static fn (Thread $t): bool => $t->getState() === Thread::STATE_ONGOING))
			->willReturnArgument(0);
		$this->cache->expects($this->once())->method('remove')->with('thread/7/42');
		$this->cache->expects($this->never())->method('set');

		$this->service->reviveIfClosed(7, 42);
	}

	/**
	 * Story 1.5, AC5: the asymmetry with Closed is deliberate - a Locked
	 * Thread is never revived by a post reaching it.
	 */
	public function testReviveIfClosedDoesNothingForLockedThread(): void {
		$thread = $this->createThread(42, 7);
		$thread->setState(Thread::STATE_LOCKED);
		$this->cache->method('get')->with('thread/7/42')->willReturn($thread->toJson());

		$this->threadMapper->expects($this->never())->method('update');
		$this->cache->expects($this->never())->method('remove');

		$this->service->reviveIfClosed(7, 42);
	}

	/**
	 * Story 1.5: an already-Ongoing Thread is left untouched - no
	 * redundant mapper write or cache invalidation.
	 */
	public function testReviveIfClosedDoesNothingForOngoingThread(): void {
		$thread = $this->createThread(42, 7);
		$thread->setState(Thread::STATE_ONGOING);
		$this->cache->method('get')->with('thread/7/42')->willReturn($thread->toJson());

		$this->threadMapper->expects($this->never())->method('update');
		$this->cache->expects($this->never())->method('remove');

		$this->service->reviveIfClosed(7, 42);
	}

	/**
	 * Story 1.5: an id that does not name a real Thread (e.g. the
	 * explicit-threadId branch of ChatManager::sendMessage()/
	 * addSystemMessage() carrying a stale or fabricated value) is a
	 * silent no-op, not an error - reviveIfClosed() must never throw.
	 * The empty-string cache value is findByThreadId()'s own negative
	 * cache marker ("we already looked and found nothing").
	 */
	public function testReviveIfClosedDoesNothingWhenThreadDoesNotExist(): void {
		$this->cache->method('get')->with('thread/7/999')->willReturn('');

		$this->threadMapper->expects($this->never())->method('update');
		$this->cache->expects($this->never())->method('remove');

		$this->service->reviveIfClosed(7, 999);
	}

	/**
	 * Story 1.6, AC1, AC3, AD-4: ensureNotLocked() is the single shared
	 * write-refusal guard both ChatManager::sendMessage() and
	 * ChatManager::addSystemMessage() call before every write. A Locked
	 * Thread throws LockedException with REASON_LOCKED, distinguishable
	 * from AuthorityException's 'permission' and StateException's
	 * 'value' (AD-4's three named, distinct failure causes).
	 */
	public function testEnsureNotLockedThrowsForLockedThread(): void {
		$thread = $this->createThread(42, 7);
		$thread->setState(Thread::STATE_LOCKED);
		$this->cache->method('get')->with('thread/7/42')->willReturn($thread->toJson());

		$this->expectException(LockedException::class);
		$this->expectExceptionMessage(LockedException::REASON_LOCKED);

		try {
			$this->service->ensureNotLocked(7, 42);
		} catch (LockedException $e) {
			$this->assertSame(LockedException::REASON_LOCKED, $e->getReason());
			throw $e;
		}
	}

	/**
	 * Story 1.6: an Ongoing Thread never refuses a write.
	 */
	public function testEnsureNotLockedDoesNotThrowForOngoingThread(): void {
		$thread = $this->createThread(42, 7);
		$thread->setState(Thread::STATE_ONGOING);
		$this->cache->method('get')->with('thread/7/42')->willReturn($thread->toJson());

		$this->service->ensureNotLocked(7, 42);
		$this->addToAssertionCount(1);
	}

	/**
	 * Story 1.6, AC1 (Story 1.5's own asymmetry note): a Closed Thread
	 * still accepts writes - it is the *reply* that revives it
	 * (reviveIfClosed(), Story 1.5), not this guard's concern.
	 */
	public function testEnsureNotLockedDoesNotThrowForClosedThread(): void {
		$thread = $this->createThread(42, 7);
		$thread->setState(Thread::STATE_CLOSED);
		$this->cache->method('get')->with('thread/7/42')->willReturn($thread->toJson());

		$this->service->ensureNotLocked(7, 42);
		$this->addToAssertionCount(1);
	}

	/**
	 * Story 1.6: an id that does not name a real Thread is a silent
	 * no-op, mirroring reviveIfClosed()'s identical tolerance - an
	 * unresolvable id is "not this guard's concern", not "locked".
	 * Callers that need existence enforced call validateThread()
	 * themselves (e.g. sendMessage()'s now-symmetric AC2 branches).
	 */
	public function testEnsureNotLockedDoesNotThrowWhenThreadDoesNotExist(): void {
		$this->cache->method('get')->with('thread/7/999')->willReturn('');

		$this->service->ensureNotLocked(7, 999);
		$this->addToAssertionCount(1);
	}

	/**
	 * Story 1.10, AC2, AC3: nothing to reap is a complete no-op - no cache
	 * touch, no attendee delete, no thread delete - and the mapper is
	 * queried exactly once per call (the caller, ReapOrphanedThreads,
	 * decides whether/how to loop; this method itself never loops).
	 */
	public function testReapOrphanedThreadsReturnsZeroWhenNothingToReap(): void {
		$this->threadMapper->expects($this->once())->method('findOrphanedThreadIds')
			->with(1000)
			->willReturn([]);

		$this->cache->expects($this->never())->method('remove');
		$this->threadAttendeeMapper->expects($this->never())->method('deleteByThreadIds');
		$this->threadMapper->expects($this->never())->method('deleteByIds');

		$this->assertSame(0, $this->service->reapOrphanedThreads(1000));
	}

	/**
	 * Story 1.10, AC2, AC4: a single orphaned Thread has its cache entry
	 * removed (the exact 'thread/{room_id}/{id}' key, matching every other
	 * cache-invalidation call site in this class), its
	 * talk_thread_attendees rows deleted, and its talk_threads row
	 * deleted - and the returned count is the mapper's own delete count
	 * (AD-1: never assumed to equal the candidate count).
	 */
	public function testReapOrphanedThreadsInvalidatesCacheAndDeletesSingleThread(): void {
		$this->threadMapper->expects($this->once())->method('findOrphanedThreadIds')
			->with(1000)
			->willReturn([['id' => 42, 'room_id' => 7]]);

		$this->cache->expects($this->once())->method('remove')->with('thread/7/42');
		$this->threadAttendeeMapper->expects($this->once())->method('deleteByThreadIds')->with([42])->willReturn(1);
		$this->threadMapper->expects($this->once())->method('deleteByIds')->with([42])->willReturn(1);

		$this->assertSame(1, $this->service->reapOrphanedThreads(1000));
	}

	/**
	 * Story 1.10, AC4: every orphaned Thread's own room-scoped cache key
	 * is removed, not just the first - a Thread's cache key is
	 * room-scoped (AD-1), so a fixed/wrong room id here would leave a
	 * stale cache entry behind for a Thread that was just deleted.
	 */
	public function testReapOrphanedThreadsInvalidatesCacheForEveryRoomAcrossMultipleThreads(): void {
		$this->threadMapper->method('findOrphanedThreadIds')->with(1000)->willReturn([
			['id' => 42, 'room_id' => 7],
			['id' => 99, 'room_id' => 12],
		]);

		$removedKeys = [];
		$this->cache->expects($this->exactly(2))->method('remove')
			->willReturnCallback(function (string $key) use (&$removedKeys): void {
				$removedKeys[] = $key;
			});

		$this->threadAttendeeMapper->expects($this->once())->method('deleteByThreadIds')->with([42, 99])->willReturn(2);
		$this->threadMapper->expects($this->once())->method('deleteByIds')->with([42, 99])->willReturn(2);

		$this->assertSame(2, $this->service->reapOrphanedThreads(1000));
		$this->assertSame(['thread/7/42', 'thread/12/99'], $removedKeys);
	}

	/**
	 * Story 1.10, AC4: cache invalidation happens *before* either mapper
	 * delete, in this same pass - never after. This is the ordering that
	 * makes AC4's "validateThread() no longer answers from a warm entry"
	 * true: a reader racing this method can only ever observe "cache
	 * empty, row still there for a moment", never "cache still warm, row
	 * already gone".
	 */
	public function testReapOrphanedThreadsInvalidatesCacheBeforeDeletingRows(): void {
		$this->threadMapper->method('findOrphanedThreadIds')->with(1000)->willReturn([['id' => 42, 'room_id' => 7]]);

		$cacheRemoved = false;
		$this->cache->expects($this->once())->method('remove')
			->willReturnCallback(function () use (&$cacheRemoved): void {
				$cacheRemoved = true;
			});

		$this->threadAttendeeMapper->method('deleteByThreadIds')
			->willReturnCallback(function (array $ids) use (&$cacheRemoved): int {
				$this->assertTrue($cacheRemoved, 'cache must already be invalidated before attendee rows are deleted');
				return count($ids);
			});
		$this->threadMapper->method('deleteByIds')
			->willReturnCallback(function (array $ids) use (&$cacheRemoved): int {
				$this->assertTrue($cacheRemoved, 'cache must already be invalidated before the thread row is deleted');
				return count($ids);
			});

		$this->service->reapOrphanedThreads(1000);
	}
}
