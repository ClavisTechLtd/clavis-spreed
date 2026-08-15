<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Notification;

use OCA\Circles\CirclesManager;
use OCA\FederatedFileSharing\AddressHandler;
use OCA\Talk\AppInfo\Application;
use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Chat\CommentsManager;
use OCA\Talk\Chat\MessageParser;
use OCA\Talk\Config;
use OCA\Talk\Exceptions\ParticipantNotFoundException;
use OCA\Talk\Exceptions\RoomNotFoundException;
use OCA\Talk\Federation\FederationManager;
use OCA\Talk\Manager;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\BotServerMapper;
use OCA\Talk\Model\ProxyCacheMessageMapper;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\AvatarService;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\Webinary;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\NotFoundException;
use OCP\Federation\ICloudIdManager;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\IAction;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;
use OCP\Server;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use OCP\Util;
use Psr\Log\LoggerInterface;

class Notifier implements INotifier {

	/**
	 * Story 4.1: upper bound, in *characters*, for the Thread Title rendered
	 * into a notification subject - matching how {@see ThreadService} itself
	 * bounds a title (`mb_strlen()`/`mb_substr()`), so a Vietnamese or CJK title
	 * keeps as many characters as an English one.
	 *
	 * Deliberately independent of - and well below - the 100 character message
	 * preview budget, so bounding the title never shortens the preview.
	 *
	 * This is *not* a byte budget: the push payload size limit is Story 4.3's
	 * responsibility, which trims the assembled payload as a whole, and must
	 * not be smuggled into this per-field character bound.
	 */
	public const THREAD_NAME_MAX_LENGTH = 64;

	/**
	 * Marks a Thread Title where it is composed into the conversation name of a
	 * notification subject, so a reader can tell "#Release plan, Team" apart from
	 * a conversation that happens to be called "Release plan, Team".
	 *
	 * Deliberately not translated: it is a sigil rather than a word, it is the
	 * convention the users of this fork already read as "thread" from other chat
	 * clients, and it must stay stable for the mobile clients that render the
	 * composed name verbatim.
	 */
	public const THREAD_LOCATION_PREFIX = '#';

	/**
	 * Story 4.2: a lock reason may be up to
	 * {@see \OCA\Talk\Model\Thread::LOCK_REASON_MAX_LENGTH} characters, which is
	 * unusable in a notification subject. Like the Thread Title bound above this
	 * is a readability bound in characters, not a byte budget.
	 */
	public const THREAD_LOCK_REASON_MAX_LENGTH = 128;

	/** @var Room[] */
	protected array $rooms = [];
	/** @var Participant[][] */
	protected array $participants = [];
	/** @var array<string, string> */
	protected array $circleNames = [];
	/** @var array<string, string> */
	protected array $circleLinks = [];

	public function __construct(
		private readonly IFactory $lFactory,
		private readonly IURLGenerator $url,
		private readonly Config $config,
		private readonly IAppManager $appManager,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly IShareManager $shareManager,
		private readonly Manager $manager,
		private readonly ParticipantService $participantService,
		private readonly AvatarService $avatarService,
		private readonly INotificationManager $notificationManager,
		private readonly CommentsManager $commentManager,
		private readonly ProxyCacheMessageMapper $proxyCacheMessageMapper,
		private readonly MessageParser $messageParser,
		private readonly IRootFolder $rootFolder,
		private readonly ITimeFactory $timeFactory,
		private readonly AddressHandler $addressHandler,
		private readonly BotServerMapper $botServerMapper,
		private readonly FederationManager $federationManager,
		private readonly ICloudIdManager $cloudIdManager,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Identifier of the notifier, only use [a-z0-9_]
	 *
	 * @return string
	 * @since 17.0.0
	 */
	#[\Override]
	public function getID(): string {
		return 'talk';
	}

	/**
	 * Human readable name describing the notifier
	 *
	 * @return string
	 * @since 17.0.0
	 */
	#[\Override]
	public function getName(): string {
		return $this->lFactory->get(Application::APP_ID)->t('Talk');
	}

	/**
	 * @param string $objectId
	 * @param string $userId
	 * @return Room
	 * @throws RoomNotFoundException
	 */
	protected function getRoom(string $objectId, string $userId): Room {
		if (array_key_exists($objectId, $this->rooms)) {
			if ($this->rooms[$objectId] === null) {
				throw new RoomNotFoundException('Room does not exist');
			}

			return $this->rooms[$objectId];
		}

		try {
			$room = $this->manager->getRoomByToken($objectId, $userId);
			$this->rooms[$objectId] = $room;
			return $room;
		} catch (RoomNotFoundException $e) {
			if (!is_numeric($objectId)) {
				// Room does not exist
				$this->rooms[$objectId] = null;
				throw $e;
			}

			try {
				// Before 3.2.3 the id was passed in notifications
				$room = $this->manager->getRoomById((int)$objectId);
				$this->rooms[$objectId] = $room;
				return $room;
			} catch (RoomNotFoundException $e) {
				// Room does not exist
				$this->rooms[$objectId] = null;
				throw $e;
			}
		}
	}

	/**
	 * @param Room $room
	 * @param string $userId
	 * @return Participant
	 * @throws ParticipantNotFoundException
	 */
	protected function getParticipant(Room $room, string $userId): Participant {
		$roomId = $room->getId();
		if (array_key_exists($roomId, $this->participants) && array_key_exists($userId, $this->participants[$roomId])) {
			if ($this->participants[$roomId][$userId] === null) {
				throw new ParticipantNotFoundException('Participant does not exist');
			}

			return $this->participants[$roomId][$userId];
		}

		try {
			$participant = $this->participantService->getParticipant($room, $userId, false);
			$this->participants[$roomId][$userId] = $participant;
			return $participant;
		} catch (ParticipantNotFoundException $e) {
			// Participant does not exist
			$this->participants[$roomId][$userId] = null;
			throw $e;
		}
	}

	/**
	 * @param INotification $notification
	 * @param string $languageCode The code of the language that should be used to prepare the notification
	 * @return INotification
	 * @throws AlreadyProcessedException
	 * @throws UnknownNotificationException
	 * @since 9.0.0
	 */
	#[\Override]
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException('Incorrect app');
		}

		if (!($this->notificationManager->isPreparingPushNotification() && $notification->getSubject() === 'call')) {
			$userId = $notification->getUser();
			$user = $this->userManager->get($userId);
			if (!$user instanceof IUser || $this->config->isDisabledForUser($user)) {
				throw new AlreadyProcessedException();
			}
		}

		$l = $this->lFactory->get(Application::APP_ID, $languageCode);

		if ($notification->getObjectType() === 'hosted-signaling-server') {
			return $this->parseHostedSignalingServer($notification, $l);
		}

		if ($notification->getObjectType() === 'remote_talk_share') {
			return $this->parseRemoteInvitationMessage($notification, $l);
		}

		if ($notification->getObjectType() === 'certificate_expiration') {
			return $this->parseCertificateExpiration($notification, $l);
		}

		if ($this->notificationManager->isPreparingPushNotification() && $notification->getSubject() === 'call') {
			try {
				$room = $this->manager->getRoomByToken($notification->getObjectId());
			} catch (RoomNotFoundException) {
				// Room does not exist
				throw new AlreadyProcessedException();
			}

			// Skip the participant check when we generate push notifications
			// we just looped over the participants to create the notification,
			// they can not be removed between these 2 steps, but we can save
			// n queries.
			$participant = null;
		} else {
			try {
				$room = $this->getRoom($notification->getObjectId(), $userId);
			} catch (RoomNotFoundException) {
				// Room does not exist
				throw new AlreadyProcessedException();
			}

			try {
				$participant = $this->getParticipant($room, $userId);
			} catch (ParticipantNotFoundException) {
				// Room does not exist
				throw new AlreadyProcessedException();
			}
		}

		$notification
			->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg')))
			->setLink($this->url->linkToRouteAbsolute('spreed.Page.showCall', ['token' => $room->getToken()]));

		if ($participant instanceof Participant && $this->notificationManager->isPreparingPushNotification()) {
			$notification->setPriorityNotification($participant->getAttendee()->isImportant());
		}

		$subject = $notification->getSubject();
		if ($subject === 'record_file_stored' || $subject === 'transcript_file_stored' || $subject === 'transcript_failed' || $subject === 'summary_file_stored' || $subject === 'summary_failed') {
			return $this->parseStoredRecording($notification, $room, $participant, $l);
		}
		if ($subject === 'record_file_store_fail') {
			return $this->parseStoredRecordingFail($notification, $room, $participant, $l);
		}
		if ($subject === 'invitation') {
			return $this->parseInvitation($notification, $room, $l);
		}
		if ($subject === 'call') {
			if ($participant instanceof Participant
				&& $room->getLobbyState() !== Webinary::LOBBY_NONE
				&& !($participant->getPermissions() & Attendee::PERMISSIONS_LOBBY_IGNORE)) {
				// User is blocked by the lobby, remove notification
				throw new AlreadyProcessedException();
			}

			if ($room->getObjectType() === 'share:password') {
				return $this->parsePasswordRequest($notification, $room, $l);
			}
			return $this->parseCall($notification, $room, $l);
		}
		// Story 4.2: the four Thread lifecycle subjects branch off before the
		// nine-subject gate below. They carry no comment at all, so they must
		// never reach parseChatMessage().
		if ($subject === 'thread_locked' || $subject === 'thread_closed' || $subject === 'thread_unlocked' || $subject === 'thread_reopened') {
			if ($participant instanceof Participant
				&& $room->getLobbyState() !== Webinary::LOBBY_NONE
				&& !($participant->getPermissions() & Attendee::PERMISSIONS_LOBBY_IGNORE)) {
				// User is blocked by the lobby, remove notification
				throw new AlreadyProcessedException();
			}

			return $this->parseThreadStateChange($notification, $room, $participant, $l);
		}
		if ($subject === 'reply' || $subject === 'mention' || $subject === 'mention_direct' || $subject === 'mention_group' || $subject === 'mention_team' || $subject === 'mention_all' || $subject === 'chat' || $subject === 'reaction' || $subject === 'reminder') {
			if ($participant instanceof Participant
				&& $room->getLobbyState() !== Webinary::LOBBY_NONE
				&& !($participant->getPermissions() & Attendee::PERMISSIONS_LOBBY_IGNORE)) {
				// User is blocked by the lobby, remove notification
				throw new AlreadyProcessedException();
			}

			return $this->parseChatMessage($notification, $room, $participant, $l);
		}

		$this->notificationManager->markProcessed($notification);
		throw new UnknownNotificationException('Unknown subject');
	}

	protected function parseStoredRecordingFail(
		INotification $notification,
		Room $room,
		Participant $participant,
		IL10N $l,
	): INotification {
		$notification
			->setRichSubject(
				$l->t('Failed to upload call recording'),
			)
			->setRichMessage(
				$l->t('The recording server failed to upload recording of call {call}. Please reach out to the administration.'),
				[
					'call' => [
						'type' => 'call',
						'id' => (string)$room->getId(),
						'name' => $room->getDisplayName($participant->getAttendee()->getActorId()),
						'call-type' => $this->getRoomType($room),
						'icon-url' => $this->avatarService->getAvatarUrl($room),
					],
				]
			);
		return $notification;
	}

	protected function parseStoredRecording(
		INotification $notification,
		Room $room,
		Participant $participant,
		IL10N $l,
	): INotification {
		$parameters = $notification->getSubjectParameters();
		try {
			$userFolder = $this->rootFolder->getUserFolder($notification->getUser());
			/** @var \OCP\Files\File[] */
			$files = $userFolder->getById($parameters['objectId']);
			/** @var \OCP\Files\File $file */
			$file = array_shift($files);
			$path = $userFolder->getRelativePath($file->getPath());
		} catch (\Throwable) {
			throw new AlreadyProcessedException();
		}

		$shareAction = $notification->createAction()
			->setParsedLabel($l->t('Share to chat'))
			->setPrimary(true)
			->setLink(
				$this->url->linkToOCSRouteAbsolute(
					'spreed.Recording.shareToChat',
					[
						'apiVersion' => 'v1',
						'fileId' => $file->getId(),
						'timestamp' => $notification->getDateTime()->getTimestamp(),
						'token' => $room->getToken()
					]
				),
				IAction::TYPE_POST
			);
		$dismissAction = $notification->createAction()
			->setParsedLabel($l->t('Dismiss notification'))
			->setLink(
				$this->url->linkToOCSRouteAbsolute(
					'spreed.Recording.notificationDismiss',
					[
						'apiVersion' => 'v1',
						'token' => $room->getToken(),
						'timestamp' => $notification->getDateTime()->getTimestamp(),
					]
				),
				IAction::TYPE_DELETE
			);

		if ($notification->getSubject() === 'record_file_stored') {
			$subject = $l->t('Call recording now available');
			$message = $l->t('The recording for the call in {call} was uploaded to {file}.');
		} elseif ($notification->getSubject() === 'transcript_file_stored') {
			$subject = $l->t('Transcript now available');
			$message = $l->t('The transcript for the call in {call} was uploaded to {file}.');
		} elseif ($notification->getSubject() === 'transcript_failed') {
			$subject = $l->t('Failed to transcript call recording');
			$message = $l->t('The server failed to transcript the recording at {file} for the call in {call}. Please reach out to the administration.');
		} elseif ($notification->getSubject() === 'summary_file_stored') {
			$subject = $l->t('Call summary now available');
			$message = $l->t('The summary for the call in {call} was uploaded to {file}.');
		} else {
			$subject = $l->t('Failed to summarize call recording');
			$message = $l->t('The server failed to summarize the recording at {file} for the call in {call}. Please reach out to the administration.');
		}

		$notification
			->setRichSubject($subject)
			->setRichMessage(
				$message,
				[
					'call' => [
						'type' => 'call',
						'id' => (string)$room->getId(),
						'name' => $room->getDisplayName($participant->getAttendee()->getActorId()),
						'call-type' => $this->getRoomType($room),
						'icon-url' => $this->avatarService->getAvatarUrl($room),
					],
					'file' => [
						'type' => 'file',
						'id' => (string)$file->getId(),
						'name' => $file->getName(),
						'path' => (string)$path,
						'link' => $this->url->linkToRouteAbsolute('files.viewcontroller.showFile', ['fileid' => $file->getId()]),
					],
				]);

		if ($notification->getSubject() !== 'transcript_failed' && $notification->getSubject() !== 'summary_failed') {
			$notification->addParsedAction($shareAction);
			$notification->addParsedAction($dismissAction);
		}

		return $notification;
	}

	protected function parseRemoteInvitationMessage(INotification $notification, IL10N $l): INotification {
		$subjectParameters = $notification->getSubjectParameters();

		try {
			$invite = $this->federationManager->getRemoteShareById((int)$notification->getObjectId());
			if ($invite->getUserId() !== $notification->getUser()) {
				throw new AlreadyProcessedException();
			}
			$room = $this->manager->getRoomById($invite->getLocalRoomId());
		} catch (DoesNotExistException) {
			// Invitation does not exist
			throw new AlreadyProcessedException();
		} catch (RoomNotFoundException) {
			// Room does not exist
			throw new AlreadyProcessedException();
		}

		[$sharedById, $sharedByServer] = $this->addressHandler->splitUserRemote($subjectParameters['sharedByFederatedId']);

		$message = $l->t('{user1} invited you to join {roomName} on {remoteServer}');

		$rosParameters = [
			'user1' => [
				'type' => 'user',
				'id' => $sharedById,
				'name' => $subjectParameters['sharedByDisplayName'],
				'server' => $sharedByServer,
			],
			'roomName' => [
				'type' => 'highlight',
				'id' => $subjectParameters['serverUrl'] . '::' . $subjectParameters['roomToken'],
				'name' => $room->getName(),
			],
			'remoteServer' => [
				'type' => 'highlight',
				'id' => $subjectParameters['serverUrl'],
				'name' => $subjectParameters['serverUrl'],
			]
		];

		$acceptAction = $notification->createAction();
		$acceptAction->setParsedLabel($l->t('Accept'));
		$acceptAction->setLink($this->url->linkToOCSRouteAbsolute(
			'spreed.Federation.acceptShare',
			['apiVersion' => 'v1', 'id' => (int)$notification->getObjectId()]
		), IAction::TYPE_POST);
		$acceptAction->setPrimary(true);
		$notification->addParsedAction($acceptAction);

		$declineAction = $notification->createAction();
		$declineAction->setParsedLabel($l->t('Decline'));
		$declineAction->setLink($this->url->linkToOCSRouteAbsolute(
			'spreed.Federation.rejectShare',
			['apiVersion' => 'v1', 'id' => (int)$notification->getObjectId()]
		), IAction::TYPE_DELETE);
		$notification->addParsedAction($declineAction);

		$notification->setRichSubject($l->t('{user1} invited you to a federated conversation'), ['user1' => $rosParameters['user1']]);
		$notification->setRichMessage($message, $rosParameters);

		return $notification;
	}

	/**
	 * @param INotification $notification
	 * @param Room $room
	 * @param Participant $participant
	 * @param IL10N $l
	 * @return INotification
	 * @throws AlreadyProcessedException
	 * @throws UnknownNotificationException
	 */
	protected function parseChatMessage(INotification $notification, Room $room, Participant $participant, IL10N $l): INotification {
		if ($notification->getObjectType() !== 'chat' && $notification->getObjectType() !== 'reminder') {
			throw new UnknownNotificationException('Unknown object type');
		}

		$messageParameters = $notification->getMessageParameters();
		if (!isset($messageParameters['commentId']) && !isset($messageParameters['proxyId'])) {
			throw new AlreadyProcessedException();
		}

		if (isset($messageParameters['commentId'])) {
			if (!$this->notificationManager->isPreparingPushNotification()
				&& $notification->getObjectType() === 'chat'
				/**
				 * Notification only contains the message id of the target comment
				 * not the one of the reaction, so we can't determine if it was read.
				 * @see Listener::markReactionNotificationsRead()
				 */
				&& $notification->getSubject() !== 'reaction'
				&& ((int)$messageParameters['commentId']) <= $participant->getAttendee()->getLastReadMessage()) {
				// Mark notifications of messages that are read as processed
				throw new AlreadyProcessedException();
			}

			try {
				$comment = $this->commentManager->get($messageParameters['commentId']);
			} catch (NotFoundException) {
				throw new AlreadyProcessedException();
			}

			if ($comment->getObjectType() !== 'chat'
				|| $room->getId() !== (int)$comment->getObjectId()) {
				$this->logger->warning('Ignoring ' . $notification->getSubject() . ' notification for user ' . $notification->getUser() . ' as messages #' . $comment->getId() . ' could not be found for conversation ' . $room->getToken());
				throw new AlreadyProcessedException();
			}

			$message = $this->messageParser->createMessage($room, $participant, $comment, $l);
			$this->messageParser->parseMessage($message, true);

			if (!$message->getVisibility()) {
				throw new AlreadyProcessedException();
			}
		} else {
			try {
				$proxy = $this->proxyCacheMessageMapper->findById($room, $messageParameters['proxyId']);
				$message = $this->messageParser->createMessageFromProxyCache($room, $participant, $proxy, $l);
			} catch (DoesNotExistException) {
				throw new AlreadyProcessedException();
			}
		}

		$subjectParameters = $notification->getSubjectParameters();

		$richSubjectUser = null;
		$isGuest = false;
		if ($subjectParameters['userType'] === Attendee::ACTOR_USERS) {
			$userId = $subjectParameters['userId'];
			$userDisplayName = $this->userManager->getDisplayName($userId);

			if ($userDisplayName !== null) {
				$richSubjectUser = [
					'type' => 'user',
					'id' => $userId,
					'name' => $userDisplayName,
				];
			}
		} elseif ($subjectParameters['userType'] === Attendee::ACTOR_FEDERATED_USERS) {
			try {
				if (isset($subjectParameters['userId']) && $subjectParameters['userId'] !== $message->getActorId()) {
					$cloudId = $this->cloudIdManager->resolveCloudId($subjectParameters['userId']);
					try {
						$reactionUser = $this->participantService->getParticipantByActor($message->getRoom(), Attendee::ACTOR_FEDERATED_USERS, $subjectParameters['userId']);
						$displayName = $reactionUser->getAttendee()->getDisplayName();
					} catch (ParticipantNotFoundException) {
						$displayName = $cloudId->getDisplayId();
					}
				} else {
					$cloudId = $this->cloudIdManager->resolveCloudId($message->getActorId());
					$displayName = $message->getActorDisplayName();
				}

				$richSubjectUser = [
					'type' => 'user',
					'id' => $cloudId->getUser(),
					'name' => $displayName,
					'server' => $cloudId->getRemote(),
				];
			} catch (\InvalidArgumentException) {
				$richSubjectUser = [
					'type' => 'highlight',
					'id' => $subjectParameters['userId'] ?? $message->getActorId(),
					'name' => $subjectParameters['userId'] ?? $message->getActorId(),
				];
			}
		} elseif ($subjectParameters['userType'] === Attendee::ACTOR_BOTS) {
			$botId = $subjectParameters['userId'];
			try {
				$bot = $this->botServerMapper->findByUrlHash(substr((string)$botId, strlen(Attendee::ACTOR_BOT_PREFIX)));
				$richSubjectUser = [
					'type' => 'highlight',
					'id' => $botId,
					'name' => $bot->getName() . ' (Bot)',
				];
			} catch (DoesNotExistException) {
				$richSubjectUser = [
					'type' => 'highlight',
					'id' => $botId,
					'name' => 'Bot',
				];
			}
		} else {
			$isGuest = true;
		}

		$richSubjectCall = [
			'type' => 'call',
			'id' => (string)$room->getId(),
			'name' => $room->getDisplayName($notification->getUser()),
			'call-type' => $this->getRoomType($room),
			'icon-url' => $this->avatarService->getAvatarUrl($room),
		];

		// Set the link to the specific message
		$urlParams = [
			'token' => $room->getToken(),
			'_fragment' => 'message_' . $message->getMessageId(),
		];
		if (isset($messageParameters['threadId'])) {
			$urlParams['threadId'] = $messageParameters['threadId'];
		}
		$notification->setLink($this->url->linkToRouteAbsolute('spreed.Page.showCall', $urlParams));

		$now = $this->timeFactory->getDateTime();
		$expireDate = $message->getExpirationDateTime();
		if ($expireDate instanceof \DateTimeInterface && $expireDate < $now) {
			throw new AlreadyProcessedException();
		}

		if ($message->getMessageType() === ChatManager::VERB_MESSAGE_DELETED) {
			throw new AlreadyProcessedException();
		}

		$placeholders = $replacements = [];
		foreach ($message->getMessageParameters() as $placeholder => $parameter) {
			$placeholders[] = '{' . $placeholder . '}';
			if ($parameter['type'] === 'user' || $parameter['type'] === 'guest') {
				$replacements[] = '@' . $parameter['name'];
			} else {
				$replacements[] = $parameter['name'];
			}
		}

		$parsedMessage = str_replace($placeholders, $replacements, $message->getMessage());
		if (!$this->notificationManager->isPreparingPushNotification() && !$participant->getAttendee()->isSensitive()) {
			$notification->setParsedMessage($parsedMessage);
			$notification->setRichMessage($message->getMessage(), $message->getMessageParameters());

			// Forward the message ID as well to the clients, so they can quote the message on replies
			$notification->setObject($notification->getObjectType(), $notification->getObjectId() . '/' . $message->getMessageId());
			if (isset($messageParameters['threadId'])) {
				$notification->setObject($notification->getObjectType(), $notification->getObjectId() . '/' . $messageParameters['threadId']);
			}
		}

		// Story 4.1: the Thread Title arrives as message parameter data
		// (@see \OCA\Talk\Chat\Notifier::createNotification()), so no Thread is
		// looked up here.
		//
		// It is composed into the `call` parameter rather than carried as a
		// separate `{thread}` placeholder, because both shipped mobile clients
		// rebuild the notification title from the rich subject parameters and
		// discard the server's rendered subject entirely:
		// - Android `NotificationWorker::enrichPushMessageByNcNotificationData()`
		//   sets the title to `call.name` alone for the `chat` object type.
		// - iOS `NCNotification::chatMessageTitle` renders `user.name` + "in" +
		//   `call.name` and ignores every other placeholder it finds.
		// Both fetch the notification from the OCS endpoint, so this holds for the
		// in-app shape as well as the push one. A placeholder the clients do not
		// know about is therefore invisible on mobile, which is the constraint
		// that blocked Story 4.3; naming the Thread inside `call.name` reaches
		// Android, iOS, web push and the in-app inbox without a client release.
		//
		// AD-20: a Thread Title is user-authored content of message grade, so it
		// is withheld wherever the sensitive-conversation branch below already
		// withholds the message preview - that branch resets
		// `$richSubjectParameters` and must stay authoritative.
		// Notifications persisted before this change carry no `threadName` and
		// therefore render exactly as they did.
		$isThreaded = false;
		if (!$participant->getAttendee()->isSensitive()
			&& isset($messageParameters['threadId'], $messageParameters['threadName'])
			&& is_string($messageParameters['threadName'])) {
			// A Thread Title is user-authored and only trimmed on input (creation
			// does not even trim), so it can contain line breaks and other
			// presentation-altering characters. Sanitising and bounding it is
			// {@see self::shortenThreadText()}, shared with the Thread lifecycle
			// subjects of Story 4.2 so there is exactly one implementation of that
			// rule. A title that is empty once sanitised names no Thread at all,
			// and the conversation name is then left exactly as it was.
			$threadName = $this->shortenThreadText($messageParameters['threadName'], self::THREAD_NAME_MAX_LENGTH);

			if ($threadName !== '') {
				$isThreaded = true;
				$threadLocation = self::THREAD_LOCATION_PREFIX . $threadName;
				if ($room->getType() !== Room::TYPE_ONE_TO_ONE && $room->getType() !== Room::TYPE_ONE_TO_ONE_FORMER) {
					// A one-to-one conversation is named after the other participant,
					// who is already the `{user}` of every subject below, so repeating
					// it would only spend budget on a line the push transport may
					// still truncate.
					$threadLocation .= ', ' . $richSubjectCall['name'];
				}
				$richSubjectCall['name'] = $threadLocation;
			}
		}

		$richSubjectParameters = [
			'user' => $richSubjectUser,
			'call' => $richSubjectCall,
		];

		if ($participant->getAttendee()->isSensitive()) {
			// Prevent message preview and conversation name in sensitive conversations

			if ($this->notificationManager->isPreparingPushNotification()) {
				$translatedPrivateConversation = $l->t('Private conversation');

				if ($notification->getSubject() === 'reaction') {
					// TRANSLATORS Someone reacted in a private conversation
					$subject = $translatedPrivateConversation . "\n" . $l->t('Someone reacted');
				} elseif ($notification->getSubject() === 'chat') {
					// TRANSLATORS You received a new message in a private conversation
					$subject = $translatedPrivateConversation . "\n" . $l->t('New message');
				} elseif ($notification->getSubject() === 'reminder') {
					// TRANSLATORS Reminder for a message in a private conversation
					$subject = $translatedPrivateConversation . "\n" . $l->t('Reminder');
				} elseif (str_starts_with($notification->getSubject(), 'mention_')) {
					// TRANSLATORS Someone mentioned you in a private conversation
					$subject = $translatedPrivateConversation . "\n" . $l->t('Someone mentioned you');
				} else {
					// TRANSLATORS There's a notification in a private conversation
					$subject = $translatedPrivateConversation . "\n" . $l->t('Notification');
				}
			} else {
				if ($notification->getSubject() === 'reaction') {
					$subject = $l->t('Someone reacted in a private conversation');
				} elseif ($notification->getSubject() === 'chat') {
					$subject = $l->t('You received a message in a private conversation');
				} elseif ($notification->getSubject() === 'reminder') {
					$subject = $l->t('Reminder in a private conversation');
				} elseif (str_starts_with($notification->getSubject(), 'mention_')) {
					$subject = $l->t('Someone mentioned you in a private conversation');
				} else {
					$subject = $l->t('Notification in a private conversation');
				}
			}

			$richSubjectParameters = [];
		} elseif ($this->notificationManager->isPreparingPushNotification() || $isThreaded) {
			// A threaded notification takes the compact bracketed shape on *every*
			// surface, not only on push. The desktop popup a browser raises is built
			// from the OCS subject, not from the push payload: the notifications
			// app's service worker only renders the pushed subject itself when no
			// tab is open, and otherwise hands the event to the page, which fetches
			// the notification over OCS and calls
			// `new Notification(subject, {body: message})`. Two shapes would
			// therefore show two different texts for the same event depending on
			// whether a tab happened to be open.
			$isPushNotification = $this->notificationManager->isPreparingPushNotification();

			// Only a push carries its own message preview - every other surface
			// renders the parsed message that was set above.
			$messageLine = '';
			if ($isPushNotification) {
				$shortenMessage = Util::shortenMultibyteString($parsedMessage, 100);
				if ($shortenMessage !== $parsedMessage) {
					$shortenMessage .= '…';
				}
				$richSubjectParameters['message'] = [
					'type' => 'highlight',
					'id' => (string)$message->getMessageId(),
					'name' => $shortenMessage,
				];
				$messageLine = "\n{message}";
			}

			if ($notification->getSubject() === 'reminder') {
				if ($message->getActorId() === $notification->getUser()) {
					// TRANSLATORS Reminder for a message you sent in the conversation {call}
					$subject = ($isThreaded ? $l->t('Reminder: You ({call})') : $l->t('Reminder: You in {call}')) . $messageLine;
				} elseif ($room->getType() === Room::TYPE_ONE_TO_ONE || $room->getType() === Room::TYPE_ONE_TO_ONE_FORMER) {
					// TRANSLATORS Reminder for a message from {user} in conversation {call}
					$subject = ($isThreaded ? $l->t('Reminder: {user} ({call})') : $l->t('Reminder: {user} in {call}')) . $messageLine;
				} elseif ($richSubjectUser) {
					// TRANSLATORS Reminder for a message from {user} in conversation {call}
					$subject = ($isThreaded ? $l->t('Reminder: {user} ({call})') : $l->t('Reminder: {user} in {call}')) . $messageLine;
				} elseif (!$isGuest) {
					// TRANSLATORS Reminder for a message from a deleted user in conversation {call}
					$subject = ($isThreaded ? $l->t('Reminder: Deleted user ({call})') : $l->t('Reminder: Deleted user in {call}')) . $messageLine;
				} else {
					try {
						$richSubjectParameters['guest'] = $this->getGuestParameter($room, $message->getActorType(), $message->getActorId());
						// TRANSLATORS Reminder for a message from a guest in conversation {call}
						$subject = ($isThreaded ? $l->t('Reminder: {guest} (guest) ({call})') : $l->t('Reminder: {guest} (guest) in {call}')) . $messageLine;
					} catch (ParticipantNotFoundException) {
						// TRANSLATORS Reminder for a message from a guest in conversation {call}
						$subject = ($isThreaded ? $l->t('Reminder: Guest ({call})') : $l->t('Reminder: Guest in {call}')) . $messageLine;
					}
				}
			} elseif ($notification->getSubject() === 'reaction') {
				$richSubjectParameters['reaction'] = [
					'type' => 'highlight',
					'id' => $subjectParameters['reaction'],
					'name' => $subjectParameters['reaction'],
				];

				if ($room->getType() === Room::TYPE_ONE_TO_ONE || $room->getType() === Room::TYPE_ONE_TO_ONE_FORMER) {
					$subject = ($isThreaded ? $l->t('{user} reacted with {reaction} ({call})') : $l->t('{user} reacted with {reaction}')) . $messageLine;
				} elseif ($richSubjectUser) {
					$subject = ($isThreaded ? $l->t('{user} reacted with {reaction} ({call})') : $l->t('{user} reacted with {reaction} in {call}')) . $messageLine;
				} elseif (!$isGuest) {
					$subject = ($isThreaded ? $l->t('Deleted user reacted with {reaction} ({call})') : $l->t('Deleted user reacted with {reaction} in {call}')) . $messageLine;
				} else {
					try {
						$richSubjectParameters['guest'] = $this->getGuestParameter($room, $message->getActorType(), $message->getActorId());
						$subject = ($isThreaded ? $l->t('{guest} (guest) reacted with {reaction} ({call})') : $l->t('{guest} (guest) reacted with {reaction} in {call}')) . $messageLine;
					} catch (ParticipantNotFoundException) {
						$subject = ($isThreaded ? $l->t('Guest reacted with {reaction} ({call})') : $l->t('Guest reacted with {reaction} in {call}')) . $messageLine;
					}
				}
			} else {
				if ($room->getType() === Room::TYPE_ONE_TO_ONE || $room->getType() === Room::TYPE_ONE_TO_ONE_FORMER) {
					// A threaded one-to-one still needs the location line, because
					// `{call}` is the only place the Thread Title is named.
					$subject = ($isThreaded ? '{user} ({call})' : '{user}') . $messageLine;
				} elseif ($richSubjectUser) {
					// Not translated when threaded: the string is punctuation and two
					// placeholders, so there is nothing for a translator to move.
					$subject = ($isThreaded ? '{user} ({call})' : $l->t('{user} in {call}')) . $messageLine;
				} elseif (!$isGuest) {
					$subject = ($isThreaded ? $l->t('Deleted user ({call})') : $l->t('Deleted user in {call}')) . $messageLine;
				} else {
					try {
						$richSubjectParameters['guest'] = $this->getGuestParameter($room, $message->getActorType(), $message->getActorId());
						$subject = ($isThreaded ? $l->t('{guest} (guest) ({call})') : $l->t('{guest} (guest) in {call}')) . $messageLine;
					} catch (ParticipantNotFoundException) {
						$subject = ($isThreaded ? $l->t('Guest ({call})') : $l->t('Guest in {call}')) . $messageLine;
					}
				}
			}
		} elseif ($notification->getSubject() === 'chat') {
			if ($room->getType() === Room::TYPE_ONE_TO_ONE || $room->getType() === Room::TYPE_ONE_TO_ONE_FORMER) {
				$subject = $l->t('{user} sent you a private message');
			} elseif ($richSubjectUser) {
				$subject = $l->t('{user} sent a message in conversation {call}');
			} elseif (!$isGuest) {
				$subject = $l->t('A deleted user sent a message in conversation {call}');
			} else {
				try {
					$richSubjectParameters['guest'] = $this->getGuestParameter($room, $message->getActorType(), $message->getActorId());
					$subject = $l->t('{guest} (guest) sent a message in conversation {call}');
				} catch (ParticipantNotFoundException) {
					$subject = $l->t('A guest sent a message in conversation {call}');
				}
			}
		} elseif ($notification->getSubject() === 'reply') {
			if ($room->getType() === Room::TYPE_ONE_TO_ONE || $room->getType() === Room::TYPE_ONE_TO_ONE_FORMER) {
				$subject = $l->t('{user} replied to your private message');
			} elseif ($richSubjectUser) {
				$subject = $l->t('{user} replied to your message in conversation {call}');
			} elseif (!$isGuest) {
				$subject = $l->t('A deleted user replied to your message in conversation {call}');
			} else {
				try {
					$richSubjectParameters['guest'] = $this->getGuestParameter($room, $message->getActorType(), $message->getActorId());
					$subject = $l->t('{guest} (guest) replied to your message in conversation {call}');
				} catch (ParticipantNotFoundException) {
					$subject = $l->t('A guest replied to your message in conversation {call}');
				}
			}
		} elseif ($notification->getSubject() === 'reminder') {
			if ($room->getType() === Room::TYPE_ONE_TO_ONE || $room->getType() === Room::TYPE_ONE_TO_ONE_FORMER) {
				if ($message->getActorId() === $notification->getUser()) {
					$subject = $l->t('Reminder: You in private conversation {call}');
				} elseif ($room->getType() === Room::TYPE_ONE_TO_ONE_FORMER) {
					$subject = $l->t('Reminder: A deleted user in private conversation {call}');
				} else {
					$subject = $l->t('Reminder: {user} in private conversation');
				}
			} elseif ($richSubjectUser) {
				if ($message->getActorId() === $notification->getUser()) {
					$subject = $l->t('Reminder: You in conversation {call}');
				} else {
					$subject = $l->t('Reminder: {user} in conversation {call}');
				}
			} elseif (!$isGuest) {
				$subject = $l->t('Reminder: A deleted user in conversation {call}');
			} else {
				try {
					$richSubjectParameters['guest'] = $this->getGuestParameter($room, $message->getActorType(), $message->getActorId());
					$subject = $l->t('Reminder: {guest} (guest) in conversation {call}');
				} catch (ParticipantNotFoundException) {
					$subject = $l->t('Reminder: A guest in conversation {call}');
				}
			}
		} elseif ($notification->getSubject() === 'reaction') {
			$richSubjectParameters['reaction'] = [
				'type' => 'highlight',
				'id' => $subjectParameters['reaction'],
				'name' => $subjectParameters['reaction'],
			];

			if ($room->getType() === Room::TYPE_ONE_TO_ONE || $room->getType() === Room::TYPE_ONE_TO_ONE_FORMER) {
				$subject = $l->t('{user} reacted with {reaction} to your private message');
			} elseif ($richSubjectUser) {
				$subject = $l->t('{user} reacted with {reaction} to your message in conversation {call}');
			} elseif (!$isGuest) {
				$subject = $l->t('A deleted user reacted with {reaction} to your message in conversation {call}');
			} else {
				try {
					$richSubjectParameters['guest'] = $this->getGuestParameter($room, $message->getActorType(), $message->getActorId());
					$subject = $l->t('{guest} (guest) reacted with {reaction} to your message in conversation {call}');
				} catch (ParticipantNotFoundException) {
					$subject = $l->t('A guest reacted with {reaction} to your message in conversation {call}');
				}
			}
		} elseif ($room->getType() === Room::TYPE_ONE_TO_ONE || $room->getType() === Room::TYPE_ONE_TO_ONE_FORMER) {
			$subject = $l->t('{user} mentioned you in a private conversation');
		} elseif ($richSubjectUser) {
			if ($notification->getSubject() === 'mention_group') {
				$groupName = $this->groupManager->getDisplayName($subjectParameters['sourceId']) ?? $subjectParameters['sourceId'];
				$richSubjectParameters['group'] = [
					'type' => 'user-group',
					'id' => $subjectParameters['sourceId'],
					'name' => $groupName,
				];

				$subject = $l->t('{user} mentioned group {group} in conversation {call}');
			} elseif ($notification->getSubject() === 'mention_team') {
				$richSubjectParameters['team'] = $this->getCircle($subjectParameters['sourceId']);
				$subject = $l->t('{user} mentioned team {team} in conversation {call}');
			} elseif ($notification->getSubject() === 'mention_all') {
				$subject = $l->t('{user} mentioned everyone in conversation {call}');
			} else {
				$subject = $l->t('{user} mentioned you in conversation {call}');
			}
		} elseif (!$isGuest) {
			if ($notification->getSubject() === 'mention_group') {
				$groupName = $this->groupManager->getDisplayName($subjectParameters['sourceId']) ?? $subjectParameters['sourceId'];
				$richSubjectParameters['group'] = [
					'type' => 'user-group',
					'id' => $subjectParameters['sourceId'],
					'name' => $groupName,
				];

				$subject = $l->t('A deleted user mentioned group {group} in conversation {call}');
			} elseif ($notification->getSubject() === 'mention_team') {
				$richSubjectParameters['team'] = $this->getCircle($subjectParameters['sourceId']);
				$subject = $l->t('A deleted user mentioned team {team} in conversation {call}');
			} elseif ($notification->getSubject() === 'mention_all') {
				$subject = $l->t('A deleted user mentioned everyone in conversation {call}');
			} else {
				$subject = $l->t('A deleted user mentioned you in conversation {call}');
			}
		} else {
			try {
				$richSubjectParameters['guest'] = $this->getGuestParameter($room, $message->getActorType(), $message->getActorId());
				if ($notification->getSubject() === 'mention_group') {
					$groupName = $this->groupManager->getDisplayName($subjectParameters['sourceId']) ?? $subjectParameters['sourceId'];
					$richSubjectParameters['group'] = [
						'type' => 'user-group',
						'id' => $subjectParameters['sourceId'],
						'name' => $groupName,
					];

					$subject = $l->t('{guest} (guest) mentioned group {group} in conversation {call}');
				} elseif ($notification->getSubject() === 'mention_team') {
					$richSubjectParameters['team'] = $this->getCircle($subjectParameters['sourceId']);
					$subject = $l->t('{guest} (guest) mentioned team {team} in conversation {call}');
				} elseif ($notification->getSubject() === 'mention_all') {
					$subject = $l->t('{guest} (guest) mentioned everyone in conversation {call}');
				} else {
					$subject = $l->t('{guest} (guest) mentioned you in conversation {call}');
				}
			} catch (ParticipantNotFoundException) {
				if ($notification->getSubject() === 'mention_group') {
					$groupName = $this->groupManager->getDisplayName($subjectParameters['sourceId']) ?? $subjectParameters['sourceId'];
					$richSubjectParameters['group'] = [
						'type' => 'user-group',
						'id' => $subjectParameters['sourceId'],
						'name' => $groupName,
					];

					$subject = $l->t('A guest mentioned group {group} in conversation {call}');
				} elseif ($notification->getSubject() === 'mention_team') {
					$richSubjectParameters['team'] = $this->getCircle($subjectParameters['sourceId']);
					$subject = $l->t('A guest mentioned team {team} in conversation {call}');
				} elseif ($notification->getSubject() === 'mention_all') {
					$subject = $l->t('A guest mentioned everyone in conversation {call}');
				} else {
					$subject = $l->t('A guest mentioned you in conversation {call}');
				}
			}
		}

		if ($notification->getObjectType() === 'reminder') {
			$notification = $this->addActionButton($notification, 'message_view', $l->t('View message'));

			$action = $notification->createAction();
			$action->setLabel('reminder_dismiss')
				->setParsedLabel($l->t('Dismiss reminder'))
				->setLink(
					$this->url->linkToOCSRouteAbsolute(
						'spreed.Chat.deleteReminder',
						[
							'apiVersion' => 'v1',
							'token' => $room->getToken(),
							'messageId' => $message->getMessageId(),
						]
					),
					IAction::TYPE_DELETE
				);

			$notification->addParsedAction($action);
		} else {
			$notification = $this->addActionButton($notification, 'chat_view', $l->t('View chat'), false);
		}

		if (array_key_exists('user', $richSubjectParameters) && $richSubjectParameters['user'] === null) {
			unset($richSubjectParameters['user']);
		}

		// `strtr()` rather than `str_replace()` with array arguments: the latter
		// applies its pairs sequentially and rescans the output of earlier
		// replacements, so a conversation name or guest display name containing
		// the literal text "{thread}" would be overwritten by a later pair.
		// `strtr()` substitutes in a single pass and never rescans its own output.
		$placeholderMap = [];
		foreach ($richSubjectParameters as $placeholder => $parameter) {
			$placeholderMap['{' . $placeholder . '}'] = $parameter['name'];
		}

		$notification->setParsedSubject(strtr($subject, $placeholderMap))
			->setRichSubject($subject, $richSubjectParameters);

		return $notification;
	}

	/**
	 * Story 4.2: a Thread lifecycle transition - locked, closed, unlocked or
	 * reopened - as seen by a follower of that Thread.
	 *
	 * Everything this notification says arrives in the subject parameters that
	 * {@see \OCA\Talk\Chat\Notifier::notifyThreadStateChange()} copied from the
	 * system message's own parameter array (AD-14), so nothing is looked up and
	 * no rendered message text is re-parsed here.
	 *
	 * @throws AlreadyProcessedException
	 */
	protected function parseThreadStateChange(INotification $notification, Room $room, Participant $participant, IL10N $l): INotification {
		if ($notification->getObjectType() !== 'room') {
			// A notification that can never be rendered has to be marked processed
			// first, exactly as the unknown-subject fallthrough of self::prepare()
			// does - otherwise it is re-thrown and logged on every single fetch and
			// the user has no way to dismiss it.
			$this->notificationManager->markProcessed($notification);
			throw new AlreadyProcessedException();
		}

		$parameters = $notification->getSubjectParameters();
		$threadId = (int)($parameters['thread'] ?? 0);
		if ($threadId <= 0) {
			// AD-12: a position is either present with a real value or absent
			// entirely - 0 is never a valid thread id.
			$this->notificationManager->markProcessed($notification);
			throw new AlreadyProcessedException();
		}

		// AD-20: routing identifiers are explicitly not content - they name a
		// destination without disclosing what is in it - so the deep link keeps
		// the Thread even for a sensitive conversation.
		$notification->setLink($this->url->linkToRouteAbsolute('spreed.Page.showCall', [
			'token' => $room->getToken(),
			'threadId' => $threadId,
		]));
		$notification = $this->addActionButton($notification, 'chat_view', $l->t('View chat'), false);

		$verb = $notification->getSubject();

		if ($participant->getAttendee()->isSensitive()) {
			// AD-20: the Thread Title and the lock reason are user-authored content
			// of message grade, so they are withheld exactly where the shipped
			// sensitive-conversation setting already withholds the message preview
			// - and they are withheld together, as one decision.
			if ($this->notificationManager->isPreparingPushNotification()) {
				$translatedPrivateConversation = $l->t('Private conversation');

				if ($verb === 'thread_locked') {
					// TRANSLATORS A thread was locked in a private conversation
					$subject = $translatedPrivateConversation . "\n" . $l->t('A thread was locked');
				} elseif ($verb === 'thread_closed') {
					// TRANSLATORS A thread was closed in a private conversation
					$subject = $translatedPrivateConversation . "\n" . $l->t('A thread was closed');
				} elseif ($verb === 'thread_unlocked') {
					// TRANSLATORS A thread was unlocked in a private conversation
					$subject = $translatedPrivateConversation . "\n" . $l->t('A thread was unlocked');
				} else {
					// TRANSLATORS A thread was reopened in a private conversation
					$subject = $translatedPrivateConversation . "\n" . $l->t('A thread was reopened');
				}
			} else {
				if ($verb === 'thread_locked') {
					$subject = $l->t('A thread was locked in a private conversation');
				} elseif ($verb === 'thread_closed') {
					$subject = $l->t('A thread was closed in a private conversation');
				} elseif ($verb === 'thread_unlocked') {
					$subject = $l->t('A thread was unlocked in a private conversation');
				} else {
					$subject = $l->t('A thread was reopened in a private conversation');
				}
			}

			$notification->setParsedSubject($subject)
				->setRichSubject($subject, []);

			return $notification;
		}

		// Mirrors {@see \OCA\Talk\Chat\Parser\SystemMessage} - a Thread that never
		// got a title is named by its id. Compared against '' rather than tested
		// for truthiness, because the string "0" is a legitimate Thread Title and
		// is falsy in PHP.
		$threadName = $this->shortenThreadText((string)($parameters['title'] ?? ''), self::THREAD_NAME_MAX_LENGTH);
		if ($threadName === '') {
			$threadName = (string)$threadId;
		}

		$richSubjectParameters = [];
		$actorParameter = $this->getThreadActorParameter($parameters);
		if ($actorParameter !== null) {
			$richSubjectParameters['user'] = $actorParameter;
		}
		$richSubjectParameters['thread'] = [
			'type' => 'highlight',
			'id' => 'thread/' . $threadId,
			'name' => $threadName,
		];
		$richSubjectParameters['call'] = [
			'type' => 'call',
			'id' => (string)$room->getId(),
			'name' => $room->getDisplayName($notification->getUser()),
			'call-type' => $this->getRoomType($room),
			'icon-url' => $this->avatarService->getAvatarUrl($room),
		];

		// Two templates rather than one with an optional tail, mirroring
		// {@see \OCA\Talk\Chat\Parser\SystemMessage}: the reason is user content
		// and is a rich object, never interpolated into the translatable string.
		$reason = '';
		if ($verb === 'thread_locked') {
			$reason = $this->shortenThreadText((string)($parameters['reason'] ?? ''), self::THREAD_LOCK_REASON_MAX_LENGTH);
			if ($reason !== '') {
				$richSubjectParameters['reason'] = [
					'type' => 'highlight',
					'id' => 'thread-lock-reason',
					'name' => $reason,
				];
			}
		}

		if ($this->notificationManager->isPreparingPushNotification()) {
			// The push subject convention throughout this file is "{header}\n{body}",
			// which iOS splits into the notification title and body. A single long
			// line would give an ordinary conversation an over-long title and an
			// empty body, while the sensitive branch above is already well-formed.
			// Line 1 therefore carries the actor and the conversation, exactly as
			// {@see self::parseChatMessage()} does, and line 2 the thread action.
			if ($actorParameter !== null) {
				$header = $l->t('{user} in {call}');
			} else {
				$header = $l->t('Deleted user in {call}');
			}

			if ($verb === 'thread_locked' && $reason !== '') {
				$body = $l->t('Locked thread {thread} ({reason})');
			} elseif ($verb === 'thread_locked') {
				$body = $l->t('Locked thread {thread}');
			} elseif ($verb === 'thread_closed') {
				$body = $l->t('Closed thread {thread}');
			} elseif ($verb === 'thread_unlocked') {
				$body = $l->t('Unlocked thread {thread}');
			} else {
				$body = $l->t('Reopened thread {thread}');
			}

			$subject = $header . "\n" . $body;
		} elseif ($actorParameter !== null) {
			if ($verb === 'thread_locked' && $reason !== '') {
				$subject = $l->t('{user} locked thread {thread} in conversation {call} ({reason})');
			} elseif ($verb === 'thread_locked') {
				$subject = $l->t('{user} locked thread {thread} in conversation {call}');
			} elseif ($verb === 'thread_closed') {
				$subject = $l->t('{user} closed thread {thread} in conversation {call}');
			} elseif ($verb === 'thread_unlocked') {
				$subject = $l->t('{user} unlocked thread {thread} in conversation {call}');
			} else {
				$subject = $l->t('{user} reopened thread {thread} in conversation {call}');
			}
		} else {
			// The account is gone and no display name was frozen at emit time. The
			// established wording of this file names no uid in that case.
			if ($verb === 'thread_locked' && $reason !== '') {
				$subject = $l->t('A deleted user locked thread {thread} in conversation {call} ({reason})');
			} elseif ($verb === 'thread_locked') {
				$subject = $l->t('A deleted user locked thread {thread} in conversation {call}');
			} elseif ($verb === 'thread_closed') {
				$subject = $l->t('A deleted user closed thread {thread} in conversation {call}');
			} elseif ($verb === 'thread_unlocked') {
				$subject = $l->t('A deleted user unlocked thread {thread} in conversation {call}');
			} else {
				$subject = $l->t('A deleted user reopened thread {thread} in conversation {call}');
			}
		}

		// `strtr()` rather than `str_replace()` with array arguments, for the same
		// reason {@see self::parseChatMessage()} uses it: a Thread Title or lock
		// reason containing the literal text "{call}" must not be rescanned.
		$placeholderMap = [];
		foreach ($richSubjectParameters as $placeholder => $parameter) {
			$placeholderMap['{' . $placeholder . '}'] = $parameter['name'];
		}

		$notification->setParsedSubject(strtr($subject, $placeholderMap))
			->setRichSubject($subject, $richSubjectParameters);

		return $notification;
	}

	/**
	 * Story 4.2: the actor of a Thread lifecycle transition. A Thread Manager is
	 * the root-message author or a moderator, and a moderator need not hold a user
	 * account, so the display name frozen at emit time is the fallback whenever the
	 * actor cannot be resolved as a user anymore.
	 *
	 * Returns null when the actor cannot be named at all - a local account that no
	 * longer resolves and no frozen display name. The caller then picks a
	 * "A deleted user ..." template, which is what the rest of this file does
	 * instead of putting a bare account id in front of a user.
	 *
	 * @param array $parameters Subject parameters of the notification
	 * @return array{type: string, id: string, name: string, server?: string}|null
	 */
	protected function getThreadActorParameter(array $parameters): ?array {
		$actorType = (string)($parameters['userType'] ?? Attendee::ACTOR_USERS);
		$actorId = (string)($parameters['userId'] ?? '');
		$fallbackName = (string)($parameters['userDisplayName'] ?? '');

		if ($actorType === Attendee::ACTOR_USERS) {
			$displayName = $this->userManager->getDisplayName($actorId);
			if ($displayName !== null) {
				return [
					'type' => 'user',
					'id' => $actorId,
					'name' => $displayName,
				];
			}
		} elseif ($actorType === Attendee::ACTOR_FEDERATED_USERS) {
			// A federated participant can be a moderator and therefore a Thread
			// Manager. Rendered as a `user` rich object with its remote, the way
			// {@see self::parseChatMessage()} renders federated actors, so clients
			// show a real user chip rather than a raw cloud id.
			try {
				$cloudId = $this->cloudIdManager->resolveCloudId($actorId);
				return [
					'type' => 'user',
					'id' => $cloudId->getUser(),
					'name' => $fallbackName !== '' ? $fallbackName : $cloudId->getDisplayId(),
					'server' => $cloudId->getRemote(),
				];
			} catch (\InvalidArgumentException) {
				// Not a resolvable cloud id, fall through to the highlight below
			}
		}

		if ($fallbackName === '') {
			if ($actorType === Attendee::ACTOR_USERS || $actorId === '') {
				// The account is gone and nothing was frozen at emit time
				return null;
			}

			// A non-user actor (guest, bot, ...) still has a meaningful id
			return [
				'type' => 'highlight',
				'id' => $actorId,
				'name' => $actorId,
			];
		}

		return [
			'type' => 'highlight',
			'id' => $actorId,
			'name' => $fallbackName,
		];
	}

	/**
	 * Sanitises and bounds a piece of user-authored Thread text for use in a
	 * notification subject. The single implementation for both the Thread Title
	 * of Story 4.1 ({@see self::parseChatMessage()}) and the Thread Title and
	 * lock reason of Story 4.2 ({@see self::parseThreadStateChange()}).
	 *
	 * The push subject shape is "{header}\n{message}" and push clients split on
	 * "\n" to form the notification title and body, so an embedded line break
	 * would let the author of a title - or of a lock reason, which may be up to
	 * {@see \OCA\Talk\Model\Thread::LOCK_REASON_MAX_LENGTH} characters long -
	 * forge a body of their choosing on a lock screen. Bidirectional overrides
	 * are the same class of attack against a single line: they reverse the
	 * rendering of everything that follows them in the subject.
	 *
	 * Collapsed to a single space, therefore:
	 * - every C0 control character and DEL, `[\x00-\x1F\x7F]`;
	 * - U+0085 NEL (`\xC2\x85`), U+2028 LINE SEPARATOR and U+2029 PARAGRAPH
	 *   SEPARATOR (`\xE2\x80\xA8`, `\xE2\x80\xA9`), all line-break equivalents in
	 *   common renderers;
	 * - the bidi overrides and embeddings U+202A-U+202E (`\xE2\x80\xAA-\xAE`,
	 *   contiguous with the two separators above) and the bidi isolates
	 *   U+2066-U+2069 (`\xE2\x81\xA6-\xA9`).
	 *
	 * The pattern is written byte-wise and deliberately carries no `u` modifier:
	 * a `/u` pattern returns null on malformed UTF-8, which would silently turn
	 * the whole text into an empty string. UTF-8 is self-synchronising - `\xC2`,
	 * `\xE2` are lead bytes and can never occur as continuation bytes - so
	 * matching these sequences byte-wise cannot cut into an unrelated character.
	 * Bounding afterwards is on character boundaries, never mid-character.
	 */
	protected function shortenThreadText(string $text, int $maxLength): string {
		$text = trim((string)preg_replace('/(?:[\x00-\x1F\x7F]|\xC2\x85|\xE2\x80[\xA8-\xAE]|\xE2\x81[\xA6-\xA9])+/', ' ', $text));
		if ($text === '') {
			return '';
		}

		if (mb_strlen($text) > $maxLength) {
			$text = mb_substr($text, 0, $maxLength) . '…';
		}

		return $text;
	}

	/**
	 * @param Room $room
	 * @param Attendee::ACTOR_* $actorType
	 * @param string $actorId
	 * @return array
	 * @throws ParticipantNotFoundException
	 */
	protected function getGuestParameter(Room $room, string $actorType, string $actorId): array {
		if (!in_array($actorType, [Attendee::ACTOR_GUESTS, Attendee::ACTOR_EMAILS], true)) {
			throw new ParticipantNotFoundException('Not a guest actor type');
		}

		$participant = $this->participantService->getParticipantByActor($room, $actorType, $actorId);
		$name = $participant->getAttendee()->getDisplayName();
		if (trim($name) === '') {
			throw new ParticipantNotFoundException('Empty name');
		}

		return [
			'type' => 'guest',
			'id' => $actorId,
			'name' => $name,
		];
	}

	/**
	 * @param Room $room
	 * @return string
	 * @throws \InvalidArgumentException
	 */
	protected function getRoomType(Room $room): string {
		return match ($room->getType()) {
			Room::TYPE_ONE_TO_ONE,
			Room::TYPE_ONE_TO_ONE_FORMER => 'one2one',
			Room::TYPE_GROUP,
			Room::TYPE_NOTE_TO_SELF => 'group',
			Room::TYPE_PUBLIC => 'public',
			default => throw new \InvalidArgumentException('Unknown room type'),
		};
	}

	/**
	 * @param INotification $notification
	 * @param Room $room
	 * @param IL10N $l
	 * @return INotification
	 * @throws AlreadyProcessedException
	 * @throws UnknownNotificationException
	 */
	protected function parseInvitation(INotification $notification, Room $room, IL10N $l): INotification {
		if ($notification->getObjectType() !== 'room') {
			throw new UnknownNotificationException('Unknown object type');
		}

		$parameters = $notification->getSubjectParameters();
		$uid = $parameters['actorId'] ?? $parameters[0];

		$userDisplayName = $this->userManager->getDisplayName($uid);
		if ($userDisplayName === null) {
			throw new AlreadyProcessedException();
		}

		$roomName = $room->getDisplayName($notification->getUser());
		if (\in_array($room->getType(), [Room::TYPE_GROUP, Room::TYPE_PUBLIC], true)) {
			$subject = $l->t('{user} invited you to a group conversation: {call}');
			if ($this->participantService->hasActiveSessionsInCall($room)) {
				$notification = $this->addActionButton($notification, 'call_view', $l->t('Join call'), true, true);
			} else {
				$notification = $this->addActionButton($notification, 'chat_view', $l->t('View chat'), false);
			}

			$notification
				->setParsedSubject(str_replace(['{user}', '{call}'], [$userDisplayName, $roomName], $subject))
				->setRichSubject(
					$subject, [
						'user' => [
							'type' => 'user',
							'id' => $uid,
							'name' => $userDisplayName,
						],
						'call' => [
							'type' => 'call',
							'id' => (string)$room->getId(),
							'name' => $roomName,
							'call-type' => $this->getRoomType($room),
							'icon-url' => $this->avatarService->getAvatarUrl($room),
						],
					]
				);
		} else {
			throw new AlreadyProcessedException();
		}

		return $notification;
	}

	/**
	 * @param INotification $notification
	 * @param Room $room
	 * @param IL10N $l
	 * @return INotification
	 * @throws AlreadyProcessedException
	 * @throws UnknownNotificationException
	 */
	protected function parseCall(INotification $notification, Room $room, IL10N $l): INotification {
		if ($notification->getObjectType() !== 'call') {
			throw new UnknownNotificationException('Unknown object type');
		}

		$roomName = $room->getDisplayName($notification->getUser());
		if ($room->getType() === Room::TYPE_ONE_TO_ONE || $room->getType() === Room::TYPE_ONE_TO_ONE_FORMER) {
			$parameters = $notification->getSubjectParameters();
			$calleeId = $parameters['callee']; // TODO can be null on federated conversations, so needs to be changed once we have federated 1-1
			$userDisplayName = $this->userManager->getDisplayName($calleeId);
			if ($userDisplayName !== null) {
				if ($this->notificationManager->isPreparingPushNotification() || $this->participantService->hasActiveSessionsInCall($room)) {
					$notification = $this->addActionButton($notification, 'call_view', $l->t('Answer call'), true, true);
					$subject = $l->t('{user} would like to talk with you');
				} else {
					$notification = $this->addActionButton($notification, 'call_view', $l->t('Call back'));
					$subject = $l->t('You missed a call from {user}');
				}

				$notification
					->setParsedSubject(str_replace('{user}', $userDisplayName, $subject))
					->setRichSubject(
						$subject, [
							'user' => [
								'type' => 'user',
								'id' => $calleeId,
								'name' => $userDisplayName,
							],
							'call' => [
								'type' => 'call',
								'id' => (string)$room->getId(),
								'name' => $roomName,
								'call-type' => $this->getRoomType($room),
								'icon-url' => $this->avatarService->getAvatarUrl($room),
							],
						]
					);
			} else {
				throw new AlreadyProcessedException();
			}
		} elseif ($room->getObjectId() === Room::OBJECT_ID_PHONE_INCOMING
			&& in_array($room->getObjectType(), [Room::OBJECT_TYPE_PHONE_PERSIST, Room::OBJECT_TYPE_PHONE_TEMPORARY, Room::OBJECT_TYPE_PHONE_LEGACY], true)) {
			if ($this->notificationManager->isPreparingPushNotification()
				|| (!$room->isFederatedConversation() && $this->participantService->hasActiveSessionsInCall($room))
				|| ($room->isFederatedConversation() && $room->getActiveSince())
			) {
				$notification = $this->addActionButton($notification, 'call_view', $l->t('Accept call'), true, true);
				$subject = $l->t('Incoming phone call from {call}');
			} else {
				$notification = $this->addActionButton($notification, 'chat_view', $l->t('View chat'), false);
				$subject = $l->t('You missed a phone call from {call}');
			}

			$notification
				->setParsedSubject(str_replace('{call}', $roomName, $subject))
				->setRichSubject(
					$subject, [
						'call' => [
							'type' => 'call',
							'id' => (string)$room->getId(),
							'name' => $roomName,
							'call-type' => $this->getRoomType($room),
							'icon-url' => $this->avatarService->getAvatarUrl($room),
						],
					]
				);
		} elseif (\in_array($room->getType(), [Room::TYPE_GROUP, Room::TYPE_PUBLIC], true)) {
			if ($this->notificationManager->isPreparingPushNotification()
				|| (!$room->isFederatedConversation() && $this->participantService->hasActiveSessionsInCall($room))
				|| ($room->isFederatedConversation() && $room->getActiveSince())
			) {
				$notification = $this->addActionButton($notification, 'call_view', $l->t('Join call'), true, true);
				$subject = $l->t('A group call has started in {call}');
			} else {
				$notification = $this->addActionButton($notification, 'chat_view', $l->t('View chat'), false);
				$subject = $l->t('You missed a group call in {call}');
			}

			$notification
				->setParsedSubject(str_replace('{call}', $roomName, $subject))
				->setRichSubject(
					$subject, [
						'call' => [
							'type' => 'call',
							'id' => (string)$room->getId(),
							'name' => $roomName,
							'call-type' => $this->getRoomType($room),
							'icon-url' => $this->avatarService->getAvatarUrl($room),
						],
					]
				);
		} else {
			throw new AlreadyProcessedException();
		}

		return $notification;
	}

	/**
	 * @param INotification $notification
	 * @param Room $room
	 * @param IL10N $l
	 * @return INotification
	 * @throws AlreadyProcessedException
	 * @throws UnknownNotificationException
	 */
	protected function parsePasswordRequest(INotification $notification, Room $room, IL10N $l): INotification {
		if ($notification->getObjectType() !== 'call') {
			throw new UnknownNotificationException('Unknown object type');
		}

		try {
			$share = $this->shareManager->getShareByToken($room->getObjectId());
		} catch (ShareNotFound) {
			throw new AlreadyProcessedException();
		}

		try {
			$file = [
				'type' => 'highlight',
				'id' => (string)$share->getNodeId(),
				'name' => $share->getNode()->getName(),
			];
		} catch (\OCP\Files\NotFoundException) {
			throw new AlreadyProcessedException();
		}

		$callIsActive = $this->notificationManager->isPreparingPushNotification() || $this->participantService->hasActiveSessionsInCall($room);
		if ($callIsActive) {
			$notification = $this->addActionButton($notification, 'call_view', $l->t('Answer call'), true, true);
		} else {
			$notification = $this->addActionButton($notification, 'call_view', $l->t('Call back'));
		}

		if ($share->getShareType() === IShare::TYPE_EMAIL) {
			$sharedWith = $share->getSharedWith();
			if ($callIsActive) {
				$subject = $l->t('{email} is requesting the password to access {file}');
			} else {
				$subject = $l->t('{email} tried to request the password to access {file}');
			}

			$notification
				->setParsedSubject(str_replace(['{email}', '{file}'], [$sharedWith, $file['name']], $subject))
				->setRichSubject($subject, [
					'email' => [
						'type' => 'email',
						'id' => $sharedWith,
						'name' => $sharedWith,
					],
					'file' => $file,
				]
				);
		} else {
			if ($callIsActive) {
				$subject = $l->t('Someone is requesting the password to access {file}');
			} else {
				$subject = $l->t('Someone tried to request the password to access {file}');
			}

			$notification
				->setParsedSubject(str_replace('{file}', $file['name'], $subject))
				->setRichSubject($subject, ['file' => $file]);
		}

		return $notification;
	}

	protected function addActionButton(INotification $notification, string $labelKey, string $label, bool $primary = true, bool $directCallLink = false): INotification {
		$link = $notification->getLink();
		if ($directCallLink) {
			$link .= '#direct-call';
		}

		$action = $notification->createAction();
		$action->setLabel($labelKey)
			->setParsedLabel($label)
			->setLink($link, IAction::TYPE_WEB)
			->setPrimary($primary);

		$notification->addParsedAction($action);

		return $notification;
	}

	/**
	 * @throws UnknownNotificationException
	 */
	protected function parseHostedSignalingServer(INotification $notification, IL10N $l): INotification {
		$action = $notification->createAction();
		$action->setLabel('open_settings')
			->setParsedLabel($l->t('Open settings'))
			->setLink($notification->getLink(), IAction::TYPE_WEB)
			->setPrimary(true);

		$parsedParameters = [];
		$icon = '';
		switch ($notification->getSubject()) {
			case 'added':
				$subject = $l->t('Hosted signaling server added');
				$message = $l->t('The hosted signaling server is now configured and will be used.');
				$icon = $this->url->getAbsoluteURL($this->url->imagePath('core', 'actions/video.svg'));
				break;
			case 'removed':
				$subject = $l->t('Hosted signaling server removed');
				$message = $l->t('The hosted signaling server was removed and will not be used anymore.');
				$icon = $this->url->getAbsoluteURL($this->url->imagePath('core', 'actions/video-off.svg'));
				break;
			case 'changed-status':
				$subject = $l->t('Hosted signaling server changed');
				$message = $l->t('The hosted signaling server account has changed the status from "{oldstatus}" to "{newstatus}".');
				$icon = $this->url->getAbsoluteURL($this->url->imagePath('core', 'actions/video-switch.svg'));

				$parameters = $notification->getSubjectParameters();
				$parsedParameters = [
					'oldstatus' => $this->createHPBParameter($parameters['oldstatus'], $l),
					'newstatus' => $this->createHPBParameter($parameters['newstatus'], $l),
				];
				break;
			default:
				throw new UnknownNotificationException('Unknown subject');
		}

		return $notification
			->setRichSubject($subject)
			->setRichMessage($message, $parsedParameters)
			->setIcon($icon)
			->addParsedAction($action);
	}

	protected function hostedHPBStatusToLabel(string $status, IL10N $l): string {
		return match ($status) {
			'pending' => $l->t('pending'),
			'active' => $l->t('active'),
			'expired' => $l->t('expired'),
			'blocked' => $l->t('blocked'),
			'error' => $l->t('error'),
			default => $status,
		};
	}

	protected function createHPBParameter(string $status, IL10N $l): array {
		return [
			'type' => 'highlight',
			'id' => $status,
			'name' => $this->hostedHPBStatusToLabel($status, $l),
		];
	}

	protected function parseCertificateExpiration(INotification $notification, IL10N $l): INotification {
		$subjectParameters = $notification->getSubjectParameters();

		$host = $subjectParameters['host'];
		$daysToExpire = $subjectParameters['days_to_expire'];

		if ($daysToExpire > 0) {
			$subject = $l->t('The certificate of {host} expires in {days} days');
		} else {
			$subject = $l->t('The certificate of {host} expired');
		}

		$subject = str_replace(
			['{host}', '{days}'],
			[$host, $daysToExpire],
			$subject
		);

		$notification->setParsedSubject($subject);

		return $notification;
	}

	protected function getCircle(string $circleId): array {
		if (!$this->appManager->isEnabledForUser('circles')) {
			return [
				'type' => 'highlight',
				'id' => $circleId,
				'name' => $circleId,
			];
		}

		if (!isset($this->circleNames[$circleId])) {
			$this->loadCircleDetails($circleId);
		}

		if (!isset($this->circleNames[$circleId])) {
			return [
				'type' => 'highlight',
				'id' => $circleId,
				'name' => $circleId,
			];
		}

		return [
			'type' => 'circle',
			'id' => $circleId,
			'name' => $this->circleNames[$circleId],
			'link' => $this->circleLinks[$circleId],
		];
	}

	protected function loadCircleDetails(string $circleId): void {
		try {
			$circlesManager = Server::get(CirclesManager::class);
			$circlesManager->startSuperSession();
			$circle = $circlesManager->getCircle($circleId);

			$this->circleNames[$circleId] = $circle->getDisplayName();
			$this->circleLinks[$circleId] = $circle->getUrl();
		} catch (\Exception) {
		} finally {
			$circlesManager?->stopSession();
		}
	}
}
