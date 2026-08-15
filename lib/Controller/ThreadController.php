<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Controller;

use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Chat\MessageParser;
use OCA\Talk\Chat\Notifier;
use OCA\Talk\Exceptions\ThreadProperty\AuthorityException;
use OCA\Talk\Exceptions\ThreadProperty\StateException;
use OCA\Talk\Manager;
use OCA\Talk\Middleware\Attribute\FederationSupported;
use OCA\Talk\Middleware\Attribute\RequireModeratorOrNoLobby;
use OCA\Talk\Middleware\Attribute\RequireParticipant;
use OCA\Talk\Middleware\Attribute\RequirePermission;
use OCA\Talk\Middleware\Attribute\RequireReadWriteConversation;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\Thread;
use OCA\Talk\Model\ThreadAttendee;
use OCA\Talk\Participant;
use OCA\Talk\ResponseDefinitions;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\Service\ThreadService;
use OCA\Talk\Share\Helper\Preloader;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\RequestHeader;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\NotFoundException;
use OCP\IL10N;
use OCP\IRequest;

/**
 * @psalm-import-type TalkThreadInfo from ResponseDefinitions
 */
class ThreadController extends AEnvironmentAwareOCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly Manager $manager,
		private readonly ChatManager $chatManager,
		private readonly Notifier $chatNotifier,
		private readonly Preloader $sharePreloader,
		private readonly MessageParser $messageParser,
		private readonly ParticipantService $participantService,
		private readonly ThreadService $threadService,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l,
		private readonly ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Get recent active threads in a conversation
	 *
	 * Required capability: `threads`
	 *
	 * @param int<1, 50> $limit Number of threads to return
	 * @return DataResponse<Http::STATUS_OK, list<TalkThreadInfo>, array{}>
	 *
	 * 200: List of threads returned
	 */
	#[FederationSupported]
	#[PublicPage]
	#[RequireModeratorOrNoLobby]
	#[RequireParticipant]
	#[RequestHeader(name: 'x-nextcloud-federation', description: 'Set to 1 when the request is performed by another Nextcloud Server to indicate a federation request', indirect: true)]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/chat/{token}/threads/recent', requirements: [
		'apiVersion' => '(v1)',
		'token' => '[a-z0-9]{4,30}',
	])]
	public function getRecentActiveThreads(int $limit = 50): DataResponse {
		if ($this->room->isFederatedConversation()) {
			/** @var \OCA\Talk\Federation\Proxy\TalkV1\Controller\ThreadController $proxy */
			$proxy = \OCP\Server::get(\OCA\Talk\Federation\Proxy\TalkV1\Controller\ThreadController::class);
			return $proxy->getRecentActiveThreads($this->room, $this->participant, $limit);
		}

		$threads = $this->threadService->getRecentByRoomId($this->room, $limit);
		$list = $this->prepareListOfThreads($threads);
		return new DataResponse($list);
	}

	/**
	 * Get subscribed threads for a user
	 *
	 * Required capability: `threads`
	 *
	 * @param int<1, 100> $limit Number of threads to return
	 * @param non-negative-int $offset Offset in the threads list
	 * @return DataResponse<Http::STATUS_OK, list<TalkThreadInfo>, array{}>
	 *
	 * 200: List of threads returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/chat/subscribed-threads', requirements: [
		'apiVersion' => '(v1)',
	])]
	public function getSubscribedThreads(int $limit = 100, int $offset = 0): DataResponse {
		$results = $this->threadService->getRecentByActor(Attendee::ACTOR_USERS, $this->userId, $limit, $offset);

		$roomIds = array_keys($results);
		$rooms = $this->manager->getRoomsByIdForUser($roomIds, $this->userId);

		$threads = $threadAttendees = [];
		foreach ($results as $roomId => $data) {
			if (!isset($rooms[$roomId])) {
				continue;
			}

			foreach ($data as $threadData) {
				$threads[] = $threadData['thread'];
				$threadAttendees[$threadData['thread']->getId()] = $threadData['attendee'];
			}
		}

		// Sort by last activity again
		usort($threads, static function (Thread $a, Thread $b): int {
			if ($b->getLastActivity() === $a->getLastActivity()) {
				return $b->getId() <=> $a->getId();
			}
			return $b->getLastActivity() <=> $a->getLastActivity();
		});

		return new DataResponse($this->prepareListOfThreads($threads, $threadAttendees, $rooms));
	}

	/**
	 * Get thread info of a single thread
	 *
	 * Required capability: `threads`
	 *
	 * @param int $threadId The thread ID to get the info for
	 * @psalm-param non-negative-int $threadId
	 * @return DataResponse<Http::STATUS_OK, TalkThreadInfo, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{error: 'thread'|'status'}, array{}>
	 *
	 * 200: Thread info returned
	 * 404: Thread not found
	 */
	#[FederationSupported]
	#[PublicPage]
	#[RequireModeratorOrNoLobby]
	#[RequireParticipant]
	#[RequestHeader(name: 'x-nextcloud-federation', description: 'Set to 1 when the request is performed by another Nextcloud Server to indicate a federation request', indirect: true)]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/chat/{token}/threads/{threadId}', requirements: [
		'apiVersion' => '(v1)',
		'token' => '[a-z0-9]{4,30}',
		'threadId' => '[0-9]+',
	])]
	public function getThread(int $threadId): DataResponse {
		if ($this->room->isFederatedConversation()) {
			/** @var \OCA\Talk\Federation\Proxy\TalkV1\Controller\ThreadController $proxy */
			$proxy = \OCP\Server::get(\OCA\Talk\Federation\Proxy\TalkV1\Controller\ThreadController::class);
			return $proxy->getThread($this->room, $this->participant, $threadId);
		}

		try {
			$thread = $this->threadService->findByThreadId($this->room->getId(), $threadId);
		} catch (DoesNotExistException) {
			return new DataResponse(['error' => 'thread'], Http::STATUS_NOT_FOUND);
		}

		$list = $this->prepareListOfThreads([$thread]);
		/** @var TalkThreadInfo $threadInfo */
		$threadInfo = array_shift($list);
		return new DataResponse($threadInfo);
	}

	/**
	 * Rename a thread
	 *
	 * Required capability: `threads`
	 *
	 * @param int $threadId The thread ID to get the info for
	 * @psalm-param non-negative-int $threadId
	 * @param string $threadTitle New thread title, must not be empty
	 * @return DataResponse<Http::STATUS_OK, TalkThreadInfo, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: 'title'}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: 'permission'}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{error: 'thread'}, array{}>
	 *
	 * 200: Thread renamed successfully
	 * 400: When the provided title is empty
	 * 403: Not allowed, either not the original author or not a moderator
	 * 404: Thread not found
	 */
	#[FederationSupported]
	#[PublicPage]
	#[RequireModeratorOrNoLobby]
	#[RequireParticipant]
	#[RequestHeader(name: 'x-nextcloud-federation', description: 'Set to 1 when the request is performed by another Nextcloud Server to indicate a federation request', indirect: true)]
	#[ApiRoute(verb: 'PUT', url: '/api/{apiVersion}/chat/{token}/threads/{threadId}', requirements: [
		'apiVersion' => '(v1)',
		'token' => '[a-z0-9]{4,30}',
		'threadId' => '[0-9]+',
	])]
	public function renameThread(int $threadId, string $threadTitle): DataResponse {
		$threadTitle = trim($threadTitle);
		if ($this->room->isFederatedConversation()) {
			/** @var \OCA\Talk\Federation\Proxy\TalkV1\Controller\ThreadController $proxy */
			$proxy = \OCP\Server::get(\OCA\Talk\Federation\Proxy\TalkV1\Controller\ThreadController::class);
			return $proxy->renameThread($this->room, $this->participant, $threadId, $threadTitle);
		}

		try {
			$thread = $this->threadService->findByThreadId($this->room->getId(), $threadId);
		} catch (DoesNotExistException) {
			return new DataResponse(['error' => 'thread'], Http::STATUS_NOT_FOUND);
		}

		try {
			// Story 1.3, AC1: the single authority implementation (AD-3) -
			// root-message author OR moderator - lives in ThreadService,
			// not re-derived here.
			$this->threadService->ensureThreadManager($thread, $this->participant);
		} catch (AuthorityException $e) {
			return new DataResponse(['error' => $e->getReason()], Http::STATUS_FORBIDDEN);
		}

		try {
			$this->threadService->renameThread($thread, $threadTitle);
		} catch (\InvalidArgumentException) {
			return new DataResponse(['error' => 'title'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$comment = $this->chatManager->getComment($this->room, (string)$threadId);
		} catch (NotFoundException) {
			// Root message expired, continuing without replying
			$comment = null;
		}

		$this->chatManager->addSystemMessage(
			$this->room,
			$this->participant,
			$this->participant->getAttendee()->getActorType(),
			$this->participant->getAttendee()->getActorId(),
			json_encode(['message' => 'thread_renamed', 'parameters' => ['thread' => $threadId, 'title' => $thread->getName()]]),
			$this->timeFactory->getDateTime(),
			false,
			null,
			$comment,
			true,
			true,
			$threadId,
		);

		$list = $this->prepareListOfThreads([$thread]);
		/** @var TalkThreadInfo $threadInfo */
		$threadInfo = array_shift($list);
		return new DataResponse($threadInfo);
	}

	/**
	 * Change the lifecycle state of a thread
	 *
	 * One endpoint serves all four transitions - close, lock, reopen from
	 * Closed, reopen from Locked (AD-12) - the target state and the
	 * Thread's current state together determine which transition, and
	 * therefore which system message verb, applies.
	 *
	 * Required capability: `thread-management`
	 *
	 * @param int $threadId The thread ID to change the state for
	 * @psalm-param non-negative-int $threadId
	 * @param int $state New state
	 * @psalm-param Thread::STATE_* $state
	 * @param string|null $reason Optional reason, only meaningful (and only validated) when locking; ignored for every other target state (max. 4000 characters, see `config => threads => lock-reason-length`)
	 * @return DataResponse<Http::STATUS_OK, TalkThreadInfo, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: 'value'|'reason'}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: 'permission'}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{error: 'thread'}, array{}>
	 *
	 * 200: Thread state changed successfully
	 * 400: The provided state or reason was invalid
	 * 403: Not allowed, either not the original author or not a moderator
	 * 404: Thread not found
	 */
	#[PublicPage]
	#[RequireModeratorOrNoLobby]
	#[RequireParticipant]
	#[ApiRoute(verb: 'PUT', url: '/api/{apiVersion}/chat/{token}/threads/{threadId}/state', requirements: [
		'apiVersion' => '(v1)',
		'token' => '[a-z0-9]{4,30}',
		'threadId' => '[0-9]+',
	])]
	public function setState(int $threadId, int $state, ?string $reason = null): DataResponse {
		// Story 1.4, AD-18: deliberately no #[FederationSupported] here -
		// no new Thread endpoint is proxied over federation. Omitting the
		// attribute makes InjectionMiddleware::checkFederationSupport()
		// refuse the request outright for a federated room.
		try {
			$thread = $this->threadService->findByThreadId($this->room->getId(), $threadId);
		} catch (DoesNotExistException) {
			return new DataResponse(['error' => 'thread'], Http::STATUS_NOT_FOUND);
		}

		try {
			// Story 1.3, AC1: the single authority implementation (AD-3) -
			// root-message author OR moderator - lives in ThreadService,
			// not re-derived here.
			$this->threadService->ensureThreadManager($thread, $this->participant);
		} catch (AuthorityException $e) {
			return new DataResponse(['error' => $e->getReason()], Http::STATUS_FORBIDDEN);
		}

		$previousState = $thread->getState();

		try {
			$thread = $this->threadService->changeState($thread, $state, $reason);
		} catch (StateException $e) {
			return new DataResponse(['error' => $e->getReason()], Http::STATUS_BAD_REQUEST);
		} catch (\InvalidArgumentException $e) {
			/** @var 'reason' $message */
			$message = $e->getMessage();
			return new DataResponse(['error' => $message], Http::STATUS_BAD_REQUEST);
		}

		if ($previousState !== $thread->getState()) {
			// AC8: requesting the state the Thread already has never
			// reaches here - ThreadService::changeState() is a no-op in
			// that case - so the system message is never duplicated.
			if ($thread->getState() === Thread::STATE_LOCKED) {
				$verb = 'thread_locked';
			} elseif ($thread->getState() === Thread::STATE_CLOSED) {
				$verb = 'thread_closed';
			} elseif ($previousState === Thread::STATE_LOCKED) {
				$verb = 'thread_unlocked';
			} else {
				$verb = 'thread_reopened';
			}

			try {
				$comment = $this->chatManager->getComment($this->room, (string)$threadId);
			} catch (NotFoundException) {
				// Root message expired, continuing without replying
				$comment = null;
			}

			$parameters = ['thread' => $threadId, 'title' => $thread->getName()];
			if ($verb === 'thread_locked' && $thread->getLockReason() !== null) {
				// AC10: the reason travels as message parameter data, never
				// concatenated into the rendered message text.
				$parameters['reason'] = $thread->getLockReason();
			}

			$this->chatManager->addSystemMessage(
				$this->room,
				$this->participant,
				$this->participant->getAttendee()->getActorType(),
				$this->participant->getAttendee()->getActorId(),
				json_encode(['message' => $verb, 'parameters' => $parameters]),
				$this->timeFactory->getDateTime(),
				false,
				null,
				$comment,
				true,
				true,
				$threadId,
			);

			// Story 4.2, AC1: the Thread's followers are told about the transition
			// here, inside the guard that emits the system message and with the
			// very same $parameters array (AD-14) - so the lock reason is read
			// from parameter data and the revival-by-reply path through
			// ThreadService::reviveIfClosed(), which emits no system message,
			// stays silent without needing a condition of its own.
			$this->chatNotifier->notifyThreadStateChange(
				$this->room,
				$this->participant,
				$threadId,
				$verb,
				$parameters,
			);
		}

		$list = $this->prepareListOfThreads([$thread]);
		/** @var TalkThreadInfo $threadInfo */
		$threadInfo = array_shift($list);
		return new DataResponse($threadInfo);
	}

	/**
	 * @param list<Thread> $threads
	 * @param ?list<ThreadAttendee> $attendees
	 * @return list<TalkThreadInfo>
	 */
	protected function prepareListOfThreads(array $threads, ?array $attendees = null, ?array $rooms = null): array {
		$threadIds = array_map(static fn (Thread $thread) => $thread->getId(), $threads);
		if ($attendees === null) {
			$attendees = $this->threadService->findAttendeeByThreadIds($this->participant->getAttendee(), $threadIds);
		}
		if ($rooms === null) {
			$rooms = [$this->room->getId() => $this->room];
			$participants = [$this->room->getId() => $this->participant];
		}

		$messageIds = [];
		foreach ($threads as $thread) {
			$messageIds[] = $thread->getId();
			$messageIds[] = $thread->getLastMessageId();
		}

		$comments = $this->chatManager->getMessagesById($messageIds);
		$this->sharePreloader->preloadShares($comments);

		$list = [];
		foreach ($threads as $thread) {
			if (!isset($rooms[$thread->getRoomId()])) {
				continue;
			}

			$room = $rooms[$thread->getRoomId()];
			// The getParticipant here should read only from the cache, so it's no problem inside the loop
			$participant = $participants[$thread->getRoomId()] ?? $this->participantService->getParticipant($room, $this->userId);

			$firstMessage = $lastMessage = null;
			$attendee = $attendees[$thread->getId()] ?? null;
			if ($attendee === null) {
				$attendee = ThreadAttendee::createFromParticipant($thread->getId(), $participant);
			}

			$first = $comments[$thread->getId()] ?? null;
			if ($first !== null) {
				$firstMessage = $this->messageParser->createMessage($room, $participant, $first, $this->l);
				$this->messageParser->parseMessage($firstMessage);
			}

			$last = $comments[$thread->getLastMessageId()] ?? null;
			if ($last !== null) {
				$lastMessage = $this->messageParser->createMessage($room, $participant, $last, $this->l);
				$this->messageParser->parseMessage($lastMessage);
			}

			$list[] = [
				'thread' => $thread->toArray($room),
				'attendee' => $attendee->jsonSerialize(),
				// Story 1.3, AC6: a per-request, per-actor computed value,
				// not a persisted Thread column - it must never be added
				// to Thread::toJson()/toArray()/the distributed cache
				// (AD-1), which is shared across every participant
				// reading this Thread, or one actor's authority answer
				// would leak to every other reader for up to 900s (NFR-4).
				// Costs a per-row root-comment query for a non-moderator
				// viewing a Thread they did not start (see the story's Dev
				// Notes "Known tradeoff - per-row authority query"); free
				// for a moderator, who short-circuits before that lookup.
				'canManage' => $this->threadService->isThreadManager($thread, $participant),
				'first' => $firstMessage?->toArray($this->getResponseFormat(), $thread),
				'last' => $lastMessage?->toArray($this->getResponseFormat(), $thread),
			];
		}

		return $list;
	}

	/**
	 * Set notification level for a specific thread
	 *
	 * Required capability: `threads`
	 *
	 * @param int $messageId The message to create a thread for (Doesn't have to be the root)
	 * @psalm-param non-negative-int $messageId
	 * @param int $level New level
	 * @psalm-param Participant::NOTIFY_* $level
	 * @return DataResponse<Http::STATUS_OK, TalkThreadInfo, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND, array{error: 'level'|'message'|'status'|'top-most'}, array{}>
	 *
	 * 200: Successfully set notification level for thread
	 * 400: Notification level was invalid
	 * 404: Message or top most message not found
	 */
	#[FederationSupported]
	#[PublicPage]
	#[RequireModeratorOrNoLobby]
	#[RequireParticipant]
	#[RequirePermission(permission: RequirePermission::CHAT)]
	#[RequireReadWriteConversation]
	#[RequestHeader(name: 'x-nextcloud-federation', description: 'Set to 1 when the request is performed by another Nextcloud Server to indicate a federation request', indirect: true)]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/chat/{token}/threads/{messageId}/notify', requirements: [
		'apiVersion' => '(v1)',
		'token' => '[a-z0-9]{4,30}',
		'messageId' => '[0-9]+',
	])]
	public function setNotificationLevel(int $messageId, int $level): DataResponse {
		if ($this->room->isFederatedConversation()) {
			/** @var \OCA\Talk\Federation\Proxy\TalkV1\Controller\ThreadController $proxy */
			$proxy = \OCP\Server::get(\OCA\Talk\Federation\Proxy\TalkV1\Controller\ThreadController::class);
			$response = $proxy->setNotificationLevel($this->room, $this->participant, $messageId, $level);

			if ($response->getStatus() === Http::STATUS_OK) {
				// Also save locally, for later handling when receiving a federated message
				$this->threadService->setNotificationLevel($this->participant->getAttendee(), $messageId, $level);
			}

			return $response;
		}

		if (!\in_array($level, [
			Participant::NOTIFY_DEFAULT,
			Participant::NOTIFY_ALWAYS,
			Participant::NOTIFY_MENTION,
			Participant::NOTIFY_NEVER,
		], true)) {
			return new DataResponse(['error' => 'level'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$thread = $this->threadService->findByThreadId($this->room->getId(), $messageId);
		} catch (DoesNotExistException) {
			return new DataResponse(['error' => 'message'], Http::STATUS_NOT_FOUND);
		}

		$threadAttendee = $this->threadService->setNotificationLevel($this->participant->getAttendee(), $thread->getId(), $level);
		$attendees = [$thread->getId() => $threadAttendee];
		$list = $this->prepareListOfThreads([$thread], $attendees);

		/** @var TalkThreadInfo $threadInfo */
		$threadInfo = array_shift($list);
		return new DataResponse($threadInfo);
	}
}
