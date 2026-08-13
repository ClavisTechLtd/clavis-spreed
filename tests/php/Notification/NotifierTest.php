<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Notification;

use OCA\FederatedFileSharing\AddressHandler;
use OCA\Talk\Chat\CommentsManager;
use OCA\Talk\Chat\MessageParser;
use OCA\Talk\Config;
use OCA\Talk\Exceptions\ParticipantNotFoundException;
use OCA\Talk\Exceptions\RoomNotFoundException;
use OCA\Talk\Federation\FederationManager;
use OCA\Talk\Manager;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\BotServerMapper;
use OCA\Talk\Model\Message;
use OCA\Talk\Model\ProxyCacheMessageMapper;
use OCA\Talk\Notification\Notifier;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\AvatarService;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\Webinary;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\IComment;
use OCP\Federation\ICloudId;
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
use OCP\Share\IManager as IShareManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class NotifierTest extends TestCase {
	protected IFactory&MockObject $lFactory;
	protected IURLGenerator&MockObject $url;
	protected Config&MockObject $config;
	protected IAppManager&MockObject $appManager;
	protected IUserManager&MockObject $userManager;
	protected IGroupManager&MockObject $groupManager;
	protected IShareManager&MockObject $shareManager;
	protected Manager&MockObject $manager;
	protected ParticipantService&MockObject $participantService;
	protected AvatarService&MockObject $avatarService;
	protected INotificationManager&MockObject $notificationManager;
	protected CommentsManager&MockObject $commentsManager;
	protected ProxyCacheMessageMapper&MockObject $proxyCacheMessageMapper;
	protected MessageParser&MockObject $messageParser;
	protected IRootFolder&MockObject $rootFolder;
	protected ITimeFactory&MockObject $timeFactory;
	protected AddressHandler&MockObject $addressHandler;
	protected BotServerMapper&MockObject $botServerMapper;
	protected FederationManager&MockObject $federationManager;
	protected ICloudIdManager&MockObject $cloudIdManager;
	protected LoggerInterface&MockObject $logger;
	protected ?Notifier $notifier = null;

	public function setUp(): void {
		parent::setUp();

		$this->lFactory = $this->createMock(IFactory::class);
		$this->url = $this->createMock(IURLGenerator::class);
		$this->config = $this->createMock(Config::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->shareManager = $this->createMock(IShareManager::class);
		$this->manager = $this->createMock(Manager::class);
		$this->participantService = $this->createMock(ParticipantService::class);
		$this->avatarService = $this->createMock(AvatarService::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->commentsManager = $this->createMock(CommentsManager::class);
		$this->proxyCacheMessageMapper = $this->createMock(ProxyCacheMessageMapper::class);
		$this->messageParser = $this->createMock(MessageParser::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->addressHandler = $this->createMock(AddressHandler::class);
		$this->botServerMapper = $this->createMock(BotServerMapper::class);
		$this->federationManager = $this->createMock(FederationManager::class);
		$this->cloudIdManager = $this->createMock(ICloudIdManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->notifier = new Notifier(
			$this->lFactory,
			$this->url,
			$this->config,
			$this->appManager,
			$this->userManager,
			$this->groupManager,
			$this->shareManager,
			$this->manager,
			$this->participantService,
			$this->avatarService,
			$this->notificationManager,
			$this->commentsManager,
			$this->proxyCacheMessageMapper,
			$this->messageParser,
			$this->rootFolder,
			$this->timeFactory,
			$this->addressHandler,
			$this->botServerMapper,
			$this->federationManager,
			$this->cloudIdManager,
			$this->logger,
		);
	}

	public function getNotificationMock(string $parsedSubject, string $uid, string $displayName) {
		/** @var INotification&MockObject $n */
		$n = $this->createMock(INotification::class);
		$n->expects($this->once())
			->method('setIcon')
			->willReturnSelf();
		$n->expects($this->once())
			->method('setLink')
			->willReturnSelf();
		$n->expects($this->once())
			->method('setParsedSubject')
			->with($parsedSubject)
			->willReturnSelf();
		$n->expects($this->once())
			->method('setRichSubject')
			->with('{user} invited you to a private conversation', [
				'user' => [
					'type' => 'user',
					'id' => $uid,
					'name' => $displayName,
				],
				'call' => [
					'type' => 'call',
					'id' => 1234,
					'name' => $displayName,
					'call-type' => 'one2one',
					'icon-url' => '',
				],
			])
			->willReturnSelf();

		$n->expects($this->exactly(2))
			->method('getUser')
			->willReturn('recipient');
		$n->expects($this->once())
			->method('getApp')
			->willReturn('spreed');
		$n->expects($this->once())
			->method('getSubject')
			->willReturn('invitation');
		$n->expects($this->once())
			->method('getSubjectParameters')
			->willReturn([$uid]);
		$n->method('getObjectType')
			->willReturn('room');
		$n->method('getObjectId')
			->willReturn('roomToken');

		return $n;
	}

	public static function dataPrepareGroup(): array {
		return [
			[Room::TYPE_GROUP, 'admin', 'Admin', 'Group', 'Admin invited you to a group conversation: Group'],
			[Room::TYPE_PUBLIC, 'test', 'Test user', 'Public', 'Test user invited you to a group conversation: Public'],
		];
	}

	#[DataProvider('dataPrepareGroup')]
	public function testPrepareGroup(int $type, string $uid, string $displayName, string $name, string $parsedSubject): void {
		$roomId = $type;
		/** @var INotification&MockObject $n */
		$n = $this->createMock(INotification::class);
		$l = $this->createMock(IL10N::class);
		$l->expects($this->any())
			->method('t')
			->willReturnCallback(fn ($text, $parameters = []) => vsprintf($text, $parameters));

		$room = $this->createMock(Room::class);
		$room->expects($this->atLeastOnce())
			->method('getType')
			->willReturn($type);
		$room->expects($this->atLeastOnce())
			->method('getDisplayName')
			->with('recipient')
			->willReturn($name);
		$this->manager->expects($this->once())
			->method('getRoomByToken')
			->with('roomToken')
			->willReturn($room);

		$this->lFactory->expects($this->once())
			->method('get')
			->with('spreed', 'de')
			->willReturn($l);

		$recipient = $this->createMock(IUser::class);
		$this->userManager->expects($this->once())
			->method('get')
			->with('recipient')
			->willReturn($recipient);

		$this->userManager->expects($this->once())
			->method('getDisplayName')
			->with($uid)
			->willReturn($displayName);

		$n->expects($this->once())
			->method('setIcon')
			->willReturnSelf();
		$n->expects($this->once())
			->method('setLink')
			->willReturnSelf();
		$n->expects($this->once())
			->method('setParsedSubject')
			->with($parsedSubject)
			->willReturnSelf();

		$room->expects($this->exactly(2))
			->method('getId')
			->willReturn($roomId);

		$this->avatarService->method('getAvatarUrl')
			->with($room)
			->willReturn('getAvatarUrl');

		if ($type === Room::TYPE_GROUP) {
			$n->expects($this->once())
				->method('setRichSubject')
				->with('{user} invited you to a group conversation: {call}', [
					'user' => [
						'type' => 'user',
						'id' => $uid,
						'name' => $displayName,
					],
					'call' => [
						'type' => 'call',
						'id' => $roomId,
						'name' => $name,
						'call-type' => 'group',
						'icon-url' => 'getAvatarUrl',
					],
				])
				->willReturnSelf();
		} else {
			$n->expects($this->once())
				->method('setRichSubject')
				->with('{user} invited you to a group conversation: {call}', [
					'user' => [
						'type' => 'user',
						'id' => $uid,
						'name' => $displayName,
					],
					'call' => [
						'type' => 'call',
						'id' => $roomId,
						'name' => $name,
						'call-type' => 'public',
						'icon-url' => 'getAvatarUrl',
					],
				])
				->willReturnSelf();
		}

		$n->expects($this->exactly(2))
			->method('getUser')
			->willReturn('recipient');
		$n->expects($this->once())
			->method('getApp')
			->willReturn('spreed');
		$n->expects($this->once())
			->method('getSubject')
			->willReturn('invitation');
		$n->expects($this->once())
			->method('getSubjectParameters')
			->willReturn([$uid]);
		$n->method('getObjectType')
			->willReturn('room');
		$n->method('getObjectId')
			->willReturn('roomToken');

		$this->notifier->prepare($n, 'de');
	}

	#[DataProvider('dataPrepareGroup')]
	public function testPrepareGroupMultipleTimesOnlyGetsTheRoomOnce(int $type, string $uid, string $displayName, string $name, string $parsedSubject): void {
		$roomId = $type;
		/** @var INotification&MockObject $n */
		$n = $this->createMock(INotification::class);
		$l = $this->createMock(IL10N::class);
		$l->expects($this->any())
			->method('t')
			->willReturnCallback(fn ($text, $parameters = []) => vsprintf($text, $parameters));

		$room = $this->createMock(Room::class);
		$room->expects($this->atLeastOnce())
			->method('getType')
			->willReturn($type);
		$room->expects($this->atLeastOnce())
			->method('getDisplayName')
			->with('recipient')
			->willReturn($name);
		$this->manager->expects($this->once())
			->method('getRoomByToken')
			->with('roomToken')
			->willReturn($room);

		$this->lFactory->method('get')
			->with('spreed', 'de')
			->willReturn($l);

		$recipient = $this->createMock(IUser::class);
		$this->userManager->method('get')
			->with('recipient')
			->willReturn($recipient);

		$this->userManager->method('getDisplayName')
			->with($uid)
			->willReturn($displayName);

		$n->method('setIcon')
			->willReturnSelf();
		$n->method('setLink')
			->willReturnSelf();
		$n->method('setParsedSubject')
			->with($parsedSubject)
			->willReturnSelf();

		$room->method('getId')
			->willReturn($roomId);

		$this->avatarService->method('getAvatarUrl')
			->with($room)
			->willReturn('getAvatarUrl');

		if ($type === Room::TYPE_GROUP) {
			$n->method('setRichSubject')
				->with('{user} invited you to a group conversation: {call}', [
					'user' => [
						'type' => 'user',
						'id' => $uid,
						'name' => $displayName,
					],
					'call' => [
						'type' => 'call',
						'id' => $roomId,
						'name' => $name,
						'call-type' => 'group',
						'icon-url' => 'getAvatarUrl',
					],
				])
				->willReturnSelf();
		} else {
			$n->method('setRichSubject')
				->with('{user} invited you to a group conversation: {call}', [
					'user' => [
						'type' => 'user',
						'id' => $uid,
						'name' => $displayName,
					],
					'call' => [
						'type' => 'call',
						'id' => $roomId,
						'name' => $name,
						'call-type' => 'public',
						'icon-url' => 'getAvatarUrl',
					],
				])
				->willReturnSelf();
		}

		$n->method('getUser')
			->willReturn('recipient');
		$n->method('getApp')
			->willReturn('spreed');
		$n->method('getSubject')
			->willReturn('invitation');
		$n->method('getSubjectParameters')
			->willReturn([$uid]);
		$n->method('getObjectType')
			->willReturn('room');
		$n->method('getObjectId')
			->willReturn('roomToken');

		$this->notifier->prepare($n, 'de');
		$this->notifier->prepare($n, 'de');
		$this->notifier->prepare($n, 'de');
		$this->notifier->prepare($n, 'de');
		$this->notifier->prepare($n, 'de');
	}

	public static function dataPrepareChatMessage(): array {
		return [
			'one-to-one mention' => [
				$subject = 'mention', Room::TYPE_ONE_TO_ONE, ['userType' => 'users', 'userId' => 'testUser'], 'Test user', 'Test user',
				'Test user mentioned you in a private conversation',
				[
					'{user} mentioned you in a private conversation',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Test user', 'call-type' => 'one2one', 'icon-url' => 'getAvatarUrl'],
					],
				],
			],
			'user mention' => [
				$subject = 'mention', Room::TYPE_GROUP,      ['userType' => 'users', 'userId' => 'testUser'], 'Test user', 'Room name',
				'Test user mentioned you in conversation Room name',
				[
					'{user} mentioned you in conversation {call}',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Room name', 'call-type' => 'group', 'icon-url' => 'getAvatarUrl'],
					],
				],
			],
			'deleted user mention' => [
				$subject = 'mention', Room::TYPE_GROUP,      ['userType' => 'users', 'userId' => 'testUser'], null,        'Room name',
				'A deleted user mentioned you in conversation Room name',
				[
					'A deleted user mentioned you in conversation {call}',
					[
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Room name', 'call-type' => 'group', 'icon-url' => 'getAvatarUrl'],
					],
				],
				$deletedUser = true,
			],
			'user mention public' => [
				$subject = 'mention', Room::TYPE_PUBLIC,     ['userType' => 'users', 'userId' => 'testUser'], 'Test user', 'Room name',
				'Test user mentioned you in conversation Room name',
				[
					'{user} mentioned you in conversation {call}',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Room name', 'call-type' => 'public', 'icon-url' => 'getAvatarUrl'],
					],
				],
			],
			'deleted user mention public' => [
				$subject = 'mention', Room::TYPE_PUBLIC,     ['userType' => 'users', 'userId' => 'testUser'], null,        'Room name',
				'A deleted user mentioned you in conversation Room name',
				[
					'A deleted user mentioned you in conversation {call}',
					[
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Room name', 'call-type' => 'public', 'icon-url' => 'getAvatarUrl'],
					],
				],
				$deletedUser = true,
			],
			'guest mention' => [
				$subject = 'mention', Room::TYPE_PUBLIC,     ['userType' => 'guests', 'userId' => 'testSpreedSession'], null,        'Room name',
				'A guest mentioned you in conversation Room name',
				[
					'A guest mentioned you in conversation {call}',
					[
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Room name', 'call-type' => 'public', 'icon-url' => 'getAvatarUrl'],
					],
				],
				$deletedUser = false, $guestName = null,
			],
			'named guest mention' => [
				$subject = 'mention', Room::TYPE_PUBLIC,     ['userType' => 'guests', 'userId' => 'testSpreedSession'], null,    'Room name',
				'MyNameIs (guest) mentioned you in conversation Room name',
				[
					'{guest} (guest) mentioned you in conversation {call}',
					[
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Room name', 'call-type' => 'public', 'icon-url' => 'getAvatarUrl'],
						'guest' => ['type' => 'guest', 'id' => 'random-hash', 'name' => 'MyNameIs'],
					]
				],
				$deletedUser = false, $guestName = 'MyNameIs',
			],
			'empty named guest mention' => [
				$subject = 'mention', Room::TYPE_PUBLIC,     ['userType' => 'guests', 'userId' => 'testSpreedSession'], null,    'Room name',
				'A guest mentioned you in conversation Room name',
				[
					'A guest mentioned you in conversation {call}',
					[
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Room name', 'call-type' => 'public', 'icon-url' => 'getAvatarUrl'],
					],
				],
				$deletedUser = false, $guestName = '',
			],

			// Normal messages
			'one-to-one message' => [
				$subject = 'chat', Room::TYPE_ONE_TO_ONE, ['userType' => 'users', 'userId' => 'testUser'], 'Test user', 'Test user',
				'Test user sent you a private message',
				[
					'{user} sent you a private message',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Test user', 'call-type' => 'one2one', 'icon-url' => 'getAvatarUrl'],
					],
				],
			],
			'user message' => [
				$subject = 'chat', Room::TYPE_GROUP,      ['userType' => 'users', 'userId' => 'testUser'], 'Test user', 'Room name',
				'Test user sent a message in conversation Room name',
				[
					'{user} sent a message in conversation {call}',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Room name', 'call-type' => 'group', 'icon-url' => 'getAvatarUrl'],
					],
				],
			],
			'deleted user message' => [
				$subject = 'chat', Room::TYPE_GROUP,      ['userType' => 'users', 'userId' => 'testUser'], null,        'Room name',
				'A deleted user sent a message in conversation Room name',
				[
					'A deleted user sent a message in conversation {call}',
					[
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Room name', 'call-type' => 'group', 'icon-url' => 'getAvatarUrl'],
					],
				],
				$deletedUser = true,
			],
			'user message public' => [
				$subject = 'chat', Room::TYPE_PUBLIC,     ['userType' => 'users', 'userId' => 'testUser'], 'Test user', 'Room name',
				'Test user sent a message in conversation Room name',
				[
					'{user} sent a message in conversation {call}',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Room name', 'call-type' => 'public', 'icon-url' => 'getAvatarUrl'],
					]
				],
			],
			'deleted user message public' => [
				$subject = 'chat', Room::TYPE_PUBLIC,     ['userType' => 'users', 'userId' => 'testUser'], null,        'Room name',
				'A deleted user sent a message in conversation Room name',
				[
					'A deleted user sent a message in conversation {call}',
					[
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Room name', 'call-type' => 'public', 'icon-url' => 'getAvatarUrl'],
					],
				],
				$deletedUser = true
			],
			'guest message' => [
				$subject = 'chat', Room::TYPE_PUBLIC,     ['userType' => 'guests', 'userId' => 'testSpreedSession'], null,        'Room name',
				'A guest sent a message in conversation Room name',
				['A guest sent a message in conversation {call}',
					[
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Room name', 'call-type' => 'public', 'icon-url' => 'getAvatarUrl'],
					],
				],
				$deletedUser = false, $guestName = null,
			],
			'named guest message' => [
				$subject = 'chat', Room::TYPE_PUBLIC,     ['userType' => 'guests', 'userId' => 'testSpreedSession'], null,    'Room name',
				'MyNameIs (guest) sent a message in conversation Room name',
				[
					'{guest} (guest) sent a message in conversation {call}',
					[
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Room name', 'call-type' => 'public', 'icon-url' => 'getAvatarUrl'],
						'guest' => ['type' => 'guest', 'id' => 'random-hash', 'name' => 'MyNameIs'],
					],
				],
				$deletedUser = false, $guestName = 'MyNameIs',
			],
			'empty named guest message' => [
				$subject = 'chat', Room::TYPE_PUBLIC,     ['userType' => 'guests', 'userId' => 'testSpreedSession'], null,    'Room name',
				'A guest sent a message in conversation Room name',
				[
					'A guest sent a message in conversation {call}',
					[
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Room name', 'call-type' => 'public', 'icon-url' => 'getAvatarUrl'],
					],
				],
				$deletedUser = false, $guestName = '',
			],

			// Reply
			'one-to-one reply' => [
				$subject = 'reply', Room::TYPE_ONE_TO_ONE, ['userType' => 'users', 'userId' => 'testUser'], 'Test user', 'Test user',
				'Test user replied to your private message',
				[
					'{user} replied to your private message',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Test user', 'call-type' => 'one2one', 'icon-url' => 'getAvatarUrl'],
					],
				],
			],
			'user reply' => [
				$subject = 'reply', Room::TYPE_GROUP,      ['userType' => 'users', 'userId' => 'testUser'], 'Test user', 'Room name',
				'Test user replied to your message in conversation Room name',
				[
					'{user} replied to your message in conversation {call}',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Room name', 'call-type' => 'group', 'icon-url' => 'getAvatarUrl'],
					],
				],
			],
			'deleted user reply' => [
				$subject = 'reply', Room::TYPE_GROUP,      ['userType' => 'users', 'userId' => 'testUser'], null,        'Room name',
				'A deleted user replied to your message in conversation Room name',
				[
					'A deleted user replied to your message in conversation {call}',
					[
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Room name', 'call-type' => 'group', 'icon-url' => 'getAvatarUrl'],
					],
				],
				$deletedUser = true,
			],
			'user message reply' => [
				$subject = 'reply', Room::TYPE_PUBLIC,     ['userType' => 'users', 'userId' => 'testUser'], 'Test user', 'Room name',
				'Test user replied to your message in conversation Room name',
				[
					'{user} replied to your message in conversation {call}',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Room name', 'call-type' => 'public', 'icon-url' => 'getAvatarUrl'],
					]
				],
			],
			'deleted user message reply' => [
				$subject = 'reply', Room::TYPE_PUBLIC,     ['userType' => 'users', 'userId' => 'testUser'], null,        'Room name',
				'A deleted user replied to your message in conversation Room name',
				[
					'A deleted user replied to your message in conversation {call}',
					[
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Room name', 'call-type' => 'public', 'icon-url' => 'getAvatarUrl'],
					],
				],
				$deletedUser = true
			],
			'guest reply' => [
				$subject = 'reply', Room::TYPE_PUBLIC,     ['userType' => 'guests', 'userId' => 'testSpreedSession'], null,        'Room name',
				'A guest replied to your message in conversation Room name',
				['A guest replied to your message in conversation {call}',
					[
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Room name', 'call-type' => 'public', 'icon-url' => 'getAvatarUrl'],
					],
				],
				$deletedUser = false, $guestName = null,
			],
			'named guest reply' => [
				$subject = 'reply', Room::TYPE_PUBLIC,     ['userType' => 'guests', 'userId' => 'testSpreedSession'], null,    'Room name',
				'MyNameIs (guest) replied to your message in conversation Room name',
				[
					'{guest} (guest) replied to your message in conversation {call}',
					[
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Room name', 'call-type' => 'public', 'icon-url' => 'getAvatarUrl'],
						'guest' => ['type' => 'guest', 'id' => 'random-hash', 'name' => 'MyNameIs'],
					],
				],
				$deletedUser = false, $guestName = 'MyNameIs',
			],
			'empty named guest reply' => [
				$subject = 'reply', Room::TYPE_PUBLIC,     ['userType' => 'guests', 'userId' => 'testSpreedSession'], null,    'Room name',
				'A guest replied to your message in conversation Room name',
				[
					'A guest replied to your message in conversation {call}',
					[
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Room name', 'call-type' => 'public', 'icon-url' => 'getAvatarUrl'],
					],
				],
				$deletedUser = false, $guestName = '',
			],

			// Push messages
			'one-to-one push' => [
				$subject = 'chat', Room::TYPE_ONE_TO_ONE, ['userType' => 'users', 'userId' => 'testUser'], 'Test user', 'Test user',
				'Test user' . "\n" . 'Hi @Administrator',
				[
					'{user}' . "\n" . '{message}',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Test user', 'call-type' => 'one2one', 'icon-url' => 'getAvatarUrl'],
						'message' => ['type' => 'highlight', 'id' => '123456789', 'name' => 'Hi @Administrator'],
					],
				],
				$deletedUser = false, $guestName = null, $isPushNotification = true,
			],
			'user push' => [
				$subject = 'chat', Room::TYPE_GROUP,      ['userType' => 'users', 'userId' => 'testUser'], 'Test user', 'Room name',
				'Test user in Room name' . "\n" . 'Hi @Administrator',
				[
					'{user} in {call}' . "\n" . '{message}',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Room name', 'call-type' => 'group', 'icon-url' => 'getAvatarUrl'],
						'message' => ['type' => 'highlight', 'id' => '123456789', 'name' => 'Hi @Administrator'],
					],
				],
				$deletedUser = false, $guestName = null, $isPushNotification = true,
			],
			'deleted user push' => [
				$subject = 'chat', Room::TYPE_GROUP,      ['userType' => 'users', 'userId' => 'testUser'], null,        'Room name',
				'Deleted user in Room name' . "\n" . 'Hi @Administrator',
				[
					'Deleted user in {call}' . "\n" . '{message}',
					[
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Room name', 'call-type' => 'group', 'icon-url' => 'getAvatarUrl'],
						'message' => ['type' => 'highlight', 'id' => '123456789', 'name' => 'Hi @Administrator'],
					],
				],
				$deletedUser = true, $guestName = null, $isPushNotification = true,
			],
			'user push public' => [
				$subject = 'chat', Room::TYPE_PUBLIC,     ['userType' => 'users', 'userId' => 'testUser'], 'Test user', 'Room name',
				'Test user in Room name' . "\n" . 'Hi @Administrator',
				[
					'{user} in {call}' . "\n" . '{message}',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Room name', 'call-type' => 'public', 'icon-url' => 'getAvatarUrl'],
						'message' => ['type' => 'highlight', 'id' => '123456789', 'name' => 'Hi @Administrator'],
					],
				],
				$deletedUser = false, $guestName = null, $isPushNotification = true,
			],
			'deleted user push public' => [
				$subject = 'chat', Room::TYPE_PUBLIC,     ['userType' => 'users', 'userId' => 'testUser'], null,        'Room name',
				'Deleted user in Room name' . "\n" . 'Hi @Administrator',
				[
					'Deleted user in {call}' . "\n" . '{message}',
					[
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Room name', 'call-type' => 'public', 'icon-url' => 'getAvatarUrl'],
						'message' => ['type' => 'highlight', 'id' => '123456789', 'name' => 'Hi @Administrator'],
					],
				],
				$deletedUser = true, $guestName = null, $isPushNotification = true,
			],
			'guest push public' => [
				$subject = 'chat', Room::TYPE_PUBLIC,     ['userType' => 'guests', 'userId' => 'testSpreedSession'], null,        'Room name',
				'Guest in Room name' . "\n" . 'Hi @Administrator',
				[
					'Guest in {call}' . "\n" . '{message}',
					[
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Room name', 'call-type' => 'public', 'icon-url' => 'getAvatarUrl'],
						'message' => ['type' => 'highlight', 'id' => '123456789', 'name' => 'Hi @Administrator'],
					],
				],
				$deletedUser = false, $guestName = null, $isPushNotification = true,
			],
			'named guest push public' => [
				$subject = 'chat', Room::TYPE_PUBLIC,     ['userType' => 'guests', 'userId' => 'testSpreedSession'], null,    'Room name',
				'MyNameIs (guest) in Room name' . "\n" . 'Hi @Administrator',
				[
					'{guest} (guest) in {call}' . "\n" . '{message}',
					[
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Room name', 'call-type' => 'public', 'icon-url' => 'getAvatarUrl'],
						'guest' => ['type' => 'guest', 'id' => 'random-hash', 'name' => 'MyNameIs'],
						'message' => ['type' => 'highlight', 'id' => '123456789', 'name' => 'Hi @Administrator'],
					],
				],
				$deletedUser = false, $guestName = 'MyNameIs', $isPushNotification = true,
			],
			'empty named guest push public' => [
				$subject = 'chat', Room::TYPE_PUBLIC,     ['userType' => 'guests', 'userId' => 'testSpreedSession'], null,    'Room name',
				'Guest in Room name' . "\n" . 'Hi @Administrator',
				[
					'Guest in {call}' . "\n" . '{message}',
					[
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Room name', 'call-type' => 'public', 'icon-url' => 'getAvatarUrl'],
						'message' => ['type' => 'highlight', 'id' => '123456789', 'name' => 'Hi @Administrator'],
					],
				],
				$deletedUser = false, $guestName = '', $isPushNotification = true,
			],

			// Story 1.9, AC1/AC5: the message-specific link carries the thread id
			// whenever the message belongs to a Thread, and this holds unconditionally -
			// including for push notifications, since the link is set outside the
			// `isPreparingPushNotification()` guard (`Notifier::parseChatMessage()`).
			'push notification includes threadId outside the push guard' => [
				$subject = 'chat', Room::TYPE_GROUP,      ['userType' => 'users', 'userId' => 'testUser'], 'Test user', 'Room name',
				'Test user in Room name' . "\n" . 'Hi @Administrator',
				[
					'{user} in {call}' . "\n" . '{message}',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Room name', 'call-type' => 'group', 'icon-url' => 'getAvatarUrl'],
						'message' => ['type' => 'highlight', 'id' => '123456789', 'name' => 'Hi @Administrator'],
					],
				],
				$deletedUser = false, $guestName = null, $isPushNotification = true, $threadId = 55,
			],
			'in-app notification includes threadId' => [
				$subject = 'mention', Room::TYPE_GROUP,      ['userType' => 'users', 'userId' => 'testUser'], 'Test user', 'Room name',
				'Test user mentioned you in conversation Room name',
				[
					'{user} mentioned you in conversation {call}',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Room name', 'call-type' => 'group', 'icon-url' => 'getAvatarUrl'],
					],
				],
				$deletedUser = false, $guestName = null, $isPushNotification = false, $threadId = 77,
			],

			// Story 4.1, AC1: every subject routed to `parseChatMessage()` by the
			// gate in `Notifier::prepare()` names the Thread, by composing its title
			// into the `call` parameter - the only parameter both shipped mobile
			// clients read when they rebuild the title. The gate is the
			// authoritative enumeration, so there is one case per subject below -
			// `reply`, `mention`, `mention_direct`, `mention_group`, `mention_team`,
			// `mention_all`, `chat`, `reaction` and `reminder`.
			'thread title on reply' => [
				$subject = 'reply', Room::TYPE_GROUP, ['userType' => 'users', 'userId' => 'testUser'], 'Test user', 'Room name',
				'Test user (#Thread 1, Room name)',
				[
					'{user} ({call})',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 1234, 'name' => '#Thread 1, Room name', 'call-type' => 'group', 'icon-url' => 'getAvatarUrl'],
					],
				],
				$deletedUser = false, $guestName = null, $isPushNotification = false, $threadId = 77, $threadName = 'Thread 1',
			],
			'thread title on mention' => [
				$subject = 'mention', Room::TYPE_GROUP, ['userType' => 'users', 'userId' => 'testUser'], 'Test user', 'Room name',
				'Test user (#Thread 1, Room name)',
				[
					'{user} ({call})',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 1234, 'name' => '#Thread 1, Room name', 'call-type' => 'group', 'icon-url' => 'getAvatarUrl'],
					],
				],
				$deletedUser = false, $guestName = null, $isPushNotification = false, $threadId = 77, $threadName = 'Thread 1',
			],
			'thread title on mention_direct' => [
				$subject = 'mention_direct', Room::TYPE_GROUP, ['userType' => 'users', 'userId' => 'testUser'], 'Test user', 'Room name',
				'Test user (#Thread 1, Room name)',
				[
					'{user} ({call})',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 1234, 'name' => '#Thread 1, Room name', 'call-type' => 'group', 'icon-url' => 'getAvatarUrl'],
					],
				],
				$deletedUser = false, $guestName = null, $isPushNotification = false, $threadId = 77, $threadName = 'Thread 1',
			],
			'thread title on mention_group' => [
				$subject = 'mention_group', Room::TYPE_GROUP, ['userType' => 'users', 'userId' => 'testUser', 'sourceId' => 'test-group'], 'Test user', 'Room name',
				'Test user (#Thread 1, Room name)',
				[
					'{user} ({call})',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 1234, 'name' => '#Thread 1, Room name', 'call-type' => 'group', 'icon-url' => 'getAvatarUrl'],
					],
				],
				$deletedUser = false, $guestName = null, $isPushNotification = false, $threadId = 77, $threadName = 'Thread 1',
			],
			'thread title on mention_team' => [
				$subject = 'mention_team', Room::TYPE_GROUP, ['userType' => 'users', 'userId' => 'testUser', 'sourceId' => 'test-team'], 'Test user', 'Room name',
				'Test user (#Thread 1, Room name)',
				[
					'{user} ({call})',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 1234, 'name' => '#Thread 1, Room name', 'call-type' => 'group', 'icon-url' => 'getAvatarUrl'],
					],
				],
				$deletedUser = false, $guestName = null, $isPushNotification = false, $threadId = 77, $threadName = 'Thread 1',
			],
			'thread title on mention_all' => [
				$subject = 'mention_all', Room::TYPE_GROUP, ['userType' => 'users', 'userId' => 'testUser'], 'Test user', 'Room name',
				'Test user (#Thread 1, Room name)',
				[
					'{user} ({call})',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 1234, 'name' => '#Thread 1, Room name', 'call-type' => 'group', 'icon-url' => 'getAvatarUrl'],
					],
				],
				$deletedUser = false, $guestName = null, $isPushNotification = false, $threadId = 77, $threadName = 'Thread 1',
			],
			'thread title on chat' => [
				$subject = 'chat', Room::TYPE_GROUP, ['userType' => 'users', 'userId' => 'testUser'], 'Test user', 'Room name',
				'Test user (#Thread 1, Room name)',
				[
					'{user} ({call})',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 1234, 'name' => '#Thread 1, Room name', 'call-type' => 'group', 'icon-url' => 'getAvatarUrl'],
					],
				],
				$deletedUser = false, $guestName = null, $isPushNotification = false, $threadId = 77, $threadName = 'Thread 1',
			],
			'thread title on reaction' => [
				$subject = 'reaction', Room::TYPE_GROUP, ['userType' => 'users', 'userId' => 'testUser', 'reaction' => '👍'], 'Test user', 'Room name',
				'Test user reacted with 👍 (#Thread 1, Room name)',
				[
					'{user} reacted with {reaction} ({call})',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 1234, 'name' => '#Thread 1, Room name', 'call-type' => 'group', 'icon-url' => 'getAvatarUrl'],
						'reaction' => ['type' => 'highlight', 'id' => '👍', 'name' => '👍'],
					],
				],
				$deletedUser = false, $guestName = null, $isPushNotification = false, $threadId = 77, $threadName = 'Thread 1',
			],
			'thread title on reminder' => [
				$subject = 'reminder', Room::TYPE_GROUP, ['userType' => 'users', 'userId' => 'testUser'], 'Test user', 'Room name',
				'Reminder: Test user (#Thread 1, Room name)',
				[
					'Reminder: {user} ({call})',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 1234, 'name' => '#Thread 1, Room name', 'call-type' => 'group', 'icon-url' => 'getAvatarUrl'],
					],
				],
				$deletedUser = false, $guestName = null, $isPushNotification = false, $threadId = 77, $threadName = 'Thread 1',
			],

			// Story 4.1: a threaded push uses the bracketed location shape, so the
			// header names the Thread and the conversation while the message
			// preview keeps its own line.
			'thread title on a push notification touches the first line only' => [
				$subject = 'chat', Room::TYPE_GROUP, ['userType' => 'users', 'userId' => 'testUser'], 'Test user', 'Room name',
				'Test user (#Thread 1, Room name)' . "\n" . 'Hi @Administrator',
				[
					'{user} ({call})' . "\n" . '{message}',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 1234, 'name' => '#Thread 1, Room name', 'call-type' => 'group', 'icon-url' => 'getAvatarUrl'],
						'message' => ['type' => 'highlight', 'id' => '123456789', 'name' => 'Hi @Administrator'],
					],
				],
				$deletedUser = false, $guestName = null, $isPushNotification = true, $threadId = 77, $threadName = 'Thread 1',
			],

			// Story 4.1: activity outside any Thread is byte-identical to before.
			'no thread leaves the conversation name alone' => [
				$subject = 'chat', Room::TYPE_GROUP, ['userType' => 'users', 'userId' => 'testUser'], 'Test user', 'Room name',
				'Test user sent a message in conversation Room name',
				[
					'{user} sent a message in conversation {call}',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Room name', 'call-type' => 'group', 'icon-url' => 'getAvatarUrl'],
					],
				],
				$deletedUser = false, $guestName = null, $isPushNotification = false, $threadId = null, $threadName = null,
			],

			// Story 4.1: notifications persisted before this change carry `threadId`
			// but no `threadName` and must render exactly as they do today.
			'legacy notification with threadId but no threadName' => [
				$subject = 'chat', Room::TYPE_GROUP, ['userType' => 'users', 'userId' => 'testUser'], 'Test user', 'Room name',
				'Test user sent a message in conversation Room name',
				[
					'{user} sent a message in conversation {call}',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Room name', 'call-type' => 'group', 'icon-url' => 'getAvatarUrl'],
					],
				],
				$deletedUser = false, $guestName = null, $isPushNotification = false, $threadId = 77, $threadName = null,
			],

			// Story 4.1: the title is length bounded before it is composed into the
			// conversation name, with a visible truncation
			// indicator, and the bound is applied to the title alone - the 100
			// character message preview budget is untouched. The bound counts
			// *characters*, so the three cases below (ASCII, Vietnamese, Japanese)
			// all keep exactly `THREAD_NAME_MAX_LENGTH` characters.
			'over-length thread title is truncated with an ellipsis' => [
				$subject = 'chat', Room::TYPE_GROUP, ['userType' => 'users', 'userId' => 'testUser'], 'Test user', 'Room name',
				'Test user (#' . str_repeat('a', 64) . '…' . ', Room name)',
				[
					'{user} ({call})',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 1234, 'name' => '#' . str_repeat('a', 64) . '…' . ', Room name', 'call-type' => 'group', 'icon-url' => 'getAvatarUrl'],
					],
				],
				$deletedUser = false, $guestName = null, $isPushNotification = false, $threadId = 77, $threadName = str_repeat('a', 70),
			],

			// Story 4.1: a Vietnamese title is shortened on a character boundary,
			// never mid-character, and keeps the same *character* count as the
			// ASCII case above - it is not bounded by its JSON-escaped byte length,
			// which would have left it with roughly a third of the characters.
			'over-length Vietnamese thread title keeps the full character budget' => [
				$subject = 'chat', Room::TYPE_GROUP, ['userType' => 'users', 'userId' => 'testUser'], 'Test user', 'Room name',
				'Test user (#Chủ đề thảo luận về việc triển khai tính năng mới của sản phẩm t…, Room name)',
				[
					'{user} ({call})',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 1234, 'name' => '#Chủ đề thảo luận về việc triển khai tính năng mới của sản phẩm t…, Room name', 'call-type' => 'group', 'icon-url' => 'getAvatarUrl'],
					],
				],
				$deletedUser = false, $guestName = null, $isPushNotification = false, $threadId = 77,
				$threadName = 'Chủ đề thảo luận về việc triển khai tính năng mới của sản phẩm trong quý này',
			],

			// Story 4.1: the same for a CJK title - 64 characters kept, exactly as
			// many as the ASCII case, where a byte bound would have kept about 9.
			'over-length Japanese thread title keeps the full character budget' => [
				$subject = 'chat', Room::TYPE_GROUP, ['userType' => 'users', 'userId' => 'testUser'], 'Test user', 'Room name',
				'Test user (#' . str_repeat('あ', 64) . '…' . ', Room name)',
				[
					'{user} ({call})',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 1234, 'name' => '#' . str_repeat('あ', 64) . '…' . ', Room name', 'call-type' => 'group', 'icon-url' => 'getAvatarUrl'],
					],
				],
				$deletedUser = false, $guestName = null, $isPushNotification = false, $threadId = 77,
				$threadName = str_repeat('あ', 70),
			],

			// Story 4.1, security: the push subject shape is "{header}\n{message}"
			// and push clients split on the line break to form the notification
			// title and body. A Thread Title is user-authored and only trimmed on
			// input, so a line break inside it would let its author forge the body
			// shown on a lock screen. Control characters are collapsed to a single
			// space, so the rendered subject still has exactly one line break - the
			// one the push shape itself contributes.
			'thread title with a line break can not forge a second push line' => [
				$subject = 'chat', Room::TYPE_GROUP, ['userType' => 'users', 'userId' => 'testUser'], 'Test user', 'Room name',
				'Test user (#Thread 1 Your account was accessed, Room name)' . "\n" . 'Hi @Administrator',
				[
					'{user} ({call})' . "\n" . '{message}',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 1234, 'name' => '#Thread 1 Your account was accessed, Room name', 'call-type' => 'group', 'icon-url' => 'getAvatarUrl'],
						'message' => ['type' => 'highlight', 'id' => '123456789', 'name' => 'Hi @Administrator'],
					],
				],
				$deletedUser = false, $guestName = null, $isPushNotification = true, $threadId = 77,
				$threadName = "Thread 1\r\nYour account was accessed",
			],

			// Story 4.1: `createThread()` does not trim, so a whitespace-only title
			// can reach the renderer. It must not render "#   , Room name".
			'whitespace-only thread title leaves the conversation name alone' => [
				$subject = 'chat', Room::TYPE_GROUP, ['userType' => 'users', 'userId' => 'testUser'], 'Test user', 'Room name',
				'Test user sent a message in conversation Room name',
				[
					'{user} sent a message in conversation {call}',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 1234, 'name' => 'Room name', 'call-type' => 'group', 'icon-url' => 'getAvatarUrl'],
					],
				],
				$deletedUser = false, $guestName = null, $isPushNotification = false, $threadId = 77, $threadName = " \t \n ",
			],

			// Story 4.1 / AD-20: a Thread Title is user-authored content of message
			// grade, so it is withheld exactly where the shipped sensitive
			// conversation mechanism already withholds the message preview.
			'sensitive conversation withholds the thread title' => [
				$subject = 'chat', Room::TYPE_GROUP, ['userType' => 'users', 'userId' => 'testUser'], 'Test user', 'Room name',
				'You received a message in a private conversation',
				[
					'You received a message in a private conversation',
					[],
				],
				$deletedUser = false, $guestName = null, $isPushNotification = false, $threadId = 77, $threadName = 'Thread 1', $isSensitive = true,
			],

			// Story 4.1 / AD-20: the push shape is where a withheld title matters
			// most - a lock screen - so the sensitive branch has to hold there too.
			'sensitive conversation withholds the thread title on a push notification' => [
				$subject = 'chat', Room::TYPE_GROUP, ['userType' => 'users', 'userId' => 'testUser'], 'Test user', 'Room name',
				'Private conversation' . "\n" . 'New message',
				[
					'Private conversation' . "\n" . 'New message',
					[],
				],
				$deletedUser = false, $guestName = null, $isPushNotification = true, $threadId = 77, $threadName = 'Thread 1', $isSensitive = true,
			],
		];
	}

	#[DataProvider('dataPrepareChatMessage')]
	public function testPrepareChatMessage(string $subject, int $roomType, array $subjectParameters, ?string $displayName, string $roomName, string $parsedSubject, array $richSubject, bool $deletedUser = false, ?string $guestName = null, bool $isPushNotification = false, ?int $threadId = null, ?string $threadName = null, bool $isSensitive = false): void {
		/** @var INotification&MockObject $notification */
		$notification = $this->createMock(INotification::class);
		$l = $this->createMock(IL10N::class);
		$l->expects($this->any())
			->method('t')
			->willReturnCallback(fn ($text, $parameters = []) => vsprintf($text, $parameters));

		$this->notificationManager->method('isPreparingPushNotification')
			->willReturn($isPushNotification);

		// Story 1.9, AC1/AC2/AC5: capture every `linkToRouteAbsolute()` call so the
		// message-specific link (identified below by its `_fragment` key) can be
		// asserted to carry `threadId` when the message belongs to a Thread, and
		// omit it otherwise - the query scheme the client's `useGetThreadId()`
		// reads. `prepare()` also sets one generic link with no `_fragment`
		// before `parseChatMessage()` overrides it, hence "exactly 2" below.
		$capturedLinkParams = [];
		$this->url->expects($this->exactly(2))
			->method('linkToRouteAbsolute')
			->willReturnCallback(function (string $routeName, array $parameters = []) use (&$capturedLinkParams) {
				$this->assertSame('spreed.Page.showCall', $routeName);
				$capturedLinkParams[] = $parameters;
				return 'https://example.tld/index.php/call/' . ($parameters['token'] ?? '');
			});

		$room = $this->createMock(Room::class);
		$room->expects($this->atLeastOnce())
			->method('getType')
			->willReturn($roomType);
		$room->expects($this->any())
			->method('getId')
			->willReturn(1234);
		$room->expects($this->atLeastOnce())
			->method('getDisplayName')
			->with('recipient')
			->willReturn($roomName);

		$this->avatarService->method('getAvatarUrl')
			->with($room)
			->willReturn('getAvatarUrl');

		$attendee = Attendee::fromRow([
			'important' => false,
			'sensitive' => $isSensitive,
		]);
		$participant = $this->createMock(Participant::class);
		$participant->method('getAttendee')
			->willReturn($attendee);
		$this->participantService->expects($this->once())
			->method('getParticipant')
			->with($room, 'recipient')
			->willReturn($participant);

		if ($roomName !== '') {
			$room->expects($this->atLeastOnce())
				->method('getId')
				->willReturn(1234);
		}
		$this->manager->expects($this->once())
			->method('getRoomByToken')
			->with('roomToken')
			->willReturn($room);

		$this->lFactory->expects($this->once())
			->method('get')
			->with('spreed', 'de')
			->willReturn($l);

		$recipient = $this->createMock(IUser::class);
		$this->userManager->expects($this->once())
			->method('get')
			->with('recipient')
			->willReturn($recipient);

		$userManagerGet = [
			'with' => [],
			'willReturn' => [],
		];
		if ($subjectParameters['userType'] === 'users' && !$deletedUser) {
			$userManagerGet['with'][] = [$subjectParameters['userId']];
			$userManagerGet['willReturn'][] = $displayName;
		} elseif ($subjectParameters['userType'] === 'users' && $deletedUser) {
			$userManagerGet['with'][] = [$subjectParameters['userId']];
			$userManagerGet['willReturn'][] = null;
		}
		$i = 0;
		$this->userManager->expects($this->exactly(count($userManagerGet['with'])))
			->method('getDisplayName')
			->willReturnCallback(function () use ($userManagerGet, &$i) {
				$this->assertArrayHasKey($i, $userManagerGet['with']);
				$this->assertSame($userManagerGet['with'][$i], func_get_args());
				$i++;
				return $userManagerGet['willReturn'][$i - 1];
			});

		$comment = $this->createMock(IComment::class);
		$comment->expects($this->any())
			->method('getActorId')
			->willReturn('random-hash');
		$comment->expects($this->any())
			->method('getActorType')
			->willReturn(Attendee::ACTOR_GUESTS);
		$comment->expects($this->any())
			->method('getObjectType')
			->willReturn('chat');
		$comment->expects($this->any())
			->method('getObjectId')
			->willReturn('1234');
		$this->commentsManager->expects($this->once())
			->method('get')
			->with('23')
			->willReturn($comment);

		if (is_string($guestName)) {
			$participant2 = $this->createMock(Participant::class);
			$this->participantService->method('getParticipantByActor')
				->with($room, Attendee::ACTOR_GUESTS, 'random-hash')
				->willReturn($participant2);

			$attendee = Attendee::fromRow([
				'actor_type' => 'guests',
				'actor_id' => 'random-hash',
				'display_name' => $guestName,
			]);
			$participant2->method('getAttendee')
				->willReturn($attendee);
		} else {
			$this->participantService->method('getParticipantByActor')
				->with($room, Attendee::ACTOR_GUESTS, 'random-hash')
				->willThrowException(new ParticipantNotFoundException());
		}

		$chatMessage = $this->createMock(Message::class);
		$chatMessage->expects($this->atLeastOnce())
			->method('getMessage')
			->willReturn('Hi {mention-user1}');
		$chatMessage->expects($this->atLeastOnce())
			->method('getMessageParameters')
			->willReturn([
				'mention-user1' => [
					'type' => 'user',
					'id' => 'admin',
					'name' => 'Administrator',
				],
			]);
		$chatMessage->expects($this->once())
			->method('getVisibility')
			->willReturn(true);
		$chatMessage->method('getComment')
			->willReturn($comment);
		$chatMessage->expects($this->any())
			->method('getMessageId')
			->willReturn(123456789);
		$chatMessage->expects($this->any())
			->method('getActorId')
			->willReturn('random-hash');
		$chatMessage->expects($this->any())
			->method('getActorType')
			->willReturn(Attendee::ACTOR_GUESTS);

		$this->messageParser->expects($this->once())
			->method('createMessage')
			->with($room, $participant, $comment, $l)
			->willReturn($chatMessage);
		$this->messageParser->expects($this->once())
			->method('parseMessage')
			->with($chatMessage);

		$notification->expects($this->once())
			->method('setIcon')
			->willReturnSelf();
		$notification->expects($this->exactly(2))
			->method('setLink')
			->willReturnSelf();
		$notification->expects($this->once())
			->method('setParsedSubject')
			->with($parsedSubject)
			->willReturnSelf();
		$notification->expects($this->once())
			->method('setRichSubject')
			->with($richSubject[0], $richSubject[1])
			->willReturnSelf();
		if ($isPushNotification || $isSensitive) {
			$notification->expects($this->never())
				->method('setParsedMessage');
		} else {
			$notification->expects($this->once())
				->method('setParsedMessage')
				->with('Hi @Administrator')
				->willReturnSelf();
		}

		// The `reminder` subject compares the message author against the recipient
		// to pick between the "You" and the "{user}" wording, which is one
		// `getUser()` call on top of the two every subject makes.
		$notification->expects($this->exactly($subject === 'reminder' && !$isSensitive ? 3 : 2))
			->method('getUser')
			->willReturn('recipient');
		$notification->expects($this->once())
			->method('getApp')
			->willReturn('spreed');
		$notification->expects($this->atLeast(2))
			->method('getSubject')
			->willReturn($subject);
		$notification->expects($this->once())
			->method('getSubjectParameters')
			->willReturn($subjectParameters);
		$notification->method('getObjectType')
			->willReturn('chat');
		$notification->method('getObjectId')
			->willReturn('roomToken');
		$messageParameters = ['commentId' => '23'];
		if ($threadId !== null) {
			$messageParameters['threadId'] = $threadId;
		}
		if ($threadName !== null) {
			$messageParameters['threadName'] = $threadName;
		}
		$notification->expects($this->once())
			->method('getMessageParameters')
			->willReturn($messageParameters);

		$this->assertEquals($notification, $this->notifier->prepare($notification, 'de'));

		// Story 1.9, AC1/AC2/AC5: the message-specific link (identified by its
		// `_fragment` key, as opposed to `prepare()`'s generic `['token' => ...]`
		// link) carries `threadId` when the message belongs to a Thread, and
		// omits it otherwise.
		$messageLinkParams = null;
		foreach ($capturedLinkParams as $params) {
			if (array_key_exists('_fragment', $params)) {
				$messageLinkParams = $params;
			}
		}
		$this->assertNotNull($messageLinkParams, 'Expected one linkToRouteAbsolute() call with a message fragment');
		$this->assertSame('message_123456789', $messageLinkParams['_fragment']);
		if ($threadId !== null) {
			$this->assertArrayHasKey('threadId', $messageLinkParams);
			$this->assertSame($threadId, $messageLinkParams['threadId']);
		} else {
			$this->assertArrayNotHasKey('threadId', $messageLinkParams);
		}
	}

	/**
	 * Story 4.2: the `call` rich object every non-sensitive lifecycle subject
	 * carries, spelled out once.
	 */
	private static function threadStateCallParameter(): array {
		return [
			'type' => 'call',
			'id' => '1234',
			'name' => 'room1',
			'call-type' => 'group',
			'icon-url' => 'getAvatarUrl',
		];
	}

	private static function threadStateUserParameter(): array {
		return [
			'type' => 'user',
			'id' => 'actor',
			'name' => 'actor-displayname',
		];
	}

	private static function threadStateThreadParameter(string $name = 'Thread 1'): array {
		return [
			'type' => 'highlight',
			'id' => 'thread/42',
			'name' => $name,
		];
	}

	public static function dataPrepareThreadStateChange(): array {
		$baseParameters = [
			'userType' => 'users',
			'userId' => 'actor',
			'userDisplayName' => 'actor-displayname',
			'thread' => 42,
			'title' => 'Thread 1',
		];

		return [
			// AC1: the Thread, the actor and the reason.
			'locked with a reason' => [
				'thread_locked',
				array_merge($baseParameters, ['reason' => 'Off topic']),
				'actor-displayname locked thread Thread 1 in conversation room1 (Off topic)',
				'{user} locked thread {thread} in conversation {call} ({reason})',
				[
					'user' => self::threadStateUserParameter(),
					'thread' => self::threadStateThreadParameter(),
					'call' => self::threadStateCallParameter(),
					'reason' => [
						'type' => 'highlight',
						'id' => 'thread-lock-reason',
						'name' => 'Off topic',
					],
				],
			],
			// AC1: no reason supplied - a separate template, not an empty tail.
			'locked without a reason' => [
				'thread_locked',
				$baseParameters,
				'actor-displayname locked thread Thread 1 in conversation room1',
				'{user} locked thread {thread} in conversation {call}',
				[
					'user' => self::threadStateUserParameter(),
					'thread' => self::threadStateThreadParameter(),
					'call' => self::threadStateCallParameter(),
				],
			],
			'closed' => [
				'thread_closed',
				$baseParameters,
				'actor-displayname closed thread Thread 1 in conversation room1',
				'{user} closed thread {thread} in conversation {call}',
				[
					'user' => self::threadStateUserParameter(),
					'thread' => self::threadStateThreadParameter(),
					'call' => self::threadStateCallParameter(),
				],
			],
			'unlocked' => [
				'thread_unlocked',
				$baseParameters,
				'actor-displayname unlocked thread Thread 1 in conversation room1',
				'{user} unlocked thread {thread} in conversation {call}',
				[
					'user' => self::threadStateUserParameter(),
					'thread' => self::threadStateThreadParameter(),
					'call' => self::threadStateCallParameter(),
				],
			],
			'reopened' => [
				'thread_reopened',
				$baseParameters,
				'actor-displayname reopened thread Thread 1 in conversation room1',
				'{user} reopened thread {thread} in conversation {call}',
				[
					'user' => self::threadStateUserParameter(),
					'thread' => self::threadStateThreadParameter(),
					'call' => self::threadStateCallParameter(),
				],
			],
			// NFR-10: a Thread Title is user content and is never translated, hence
			// the multibyte title here.
			'title is user content' => [
				'thread_reopened',
				array_merge($baseParameters, ['title' => 'Bảo trì hệ thống']),
				'actor-displayname reopened thread Bảo trì hệ thống in conversation room1',
				'{user} reopened thread {thread} in conversation {call}',
				[
					'user' => self::threadStateUserParameter(),
					'thread' => self::threadStateThreadParameter('Bảo trì hệ thống'),
					'call' => self::threadStateCallParameter(),
				],
			],
			// A Thread that never got a title is named by its id, mirroring
			// \OCA\Talk\Chat\Parser\SystemMessage.
			'untitled thread falls back to its id' => [
				'thread_closed',
				array_merge($baseParameters, ['title' => '']),
				'actor-displayname closed thread 42 in conversation room1',
				'{user} closed thread {thread} in conversation {call}',
				[
					'user' => self::threadStateUserParameter(),
					'thread' => self::threadStateThreadParameter('42'),
					'call' => self::threadStateCallParameter(),
				],
			],
			// AC7 / AD-20: a sensitive conversation withholds the Thread Title and
			// the lock reason together, and names neither.
			'sensitive locked with a reason' => [
				'thread_locked',
				array_merge($baseParameters, ['reason' => 'Off topic']),
				'A thread was locked in a private conversation',
				'A thread was locked in a private conversation',
				[],
				$isSensitive = true,
			],
			'sensitive closed' => [
				'thread_closed',
				$baseParameters,
				'A thread was closed in a private conversation',
				'A thread was closed in a private conversation',
				[],
				$isSensitive = true,
			],
			'sensitive unlocked' => [
				'thread_unlocked',
				$baseParameters,
				'A thread was unlocked in a private conversation',
				'A thread was unlocked in a private conversation',
				[],
				$isSensitive = true,
			],
			'sensitive reopened' => [
				'thread_reopened',
				$baseParameters,
				'A thread was reopened in a private conversation',
				'A thread was reopened in a private conversation',
				[],
				$isSensitive = true,
			],
			// AD-20: the push shape is where a withheld title matters most - it is
			// the one a lock screen renders.
			'sensitive locked as a push notification' => [
				'thread_locked',
				array_merge($baseParameters, ['reason' => 'Off topic']),
				"Private conversation\nA thread was locked",
				"Private conversation\nA thread was locked",
				[],
				$isSensitive = true, $isPushNotification = true,
			],
			// A title or reason may contain line breaks, and push clients split the
			// subject on "\n" to form the notification title and body, so a C0
			// control character must never survive into the subject.
			'control characters in the title and reason are collapsed' => [
				'thread_locked',
				array_merge($baseParameters, [
					'title' => "Thread 1\r\nYour account was accessed",
					'reason' => "Off topic\nPlease enter your password",
				]),
				"actor-displayname in room1\nLocked thread Thread 1 Your account was accessed (Off topic Please enter your password)",
				"{user} in {call}\nLocked thread {thread} ({reason})",
				[
					'user' => self::threadStateUserParameter(),
					'thread' => self::threadStateThreadParameter('Thread 1 Your account was accessed'),
					'call' => self::threadStateCallParameter(),
					'reason' => [
						'type' => 'highlight',
						'id' => 'thread-lock-reason',
						'name' => 'Off topic Please enter your password',
					],
				],
				$isSensitive = false, $isPushNotification = true,
			],
			// A lock reason may be up to Thread::LOCK_REASON_MAX_LENGTH characters,
			// which is unusable in a subject line - it is bounded on a character
			// boundary with a visible truncation marker.
			'an over-long title and reason are bounded' => [
				'thread_locked',
				array_merge($baseParameters, [
					'title' => str_repeat('a', 70),
					'reason' => str_repeat('ả', 140),
				]),
				'actor-displayname locked thread ' . str_repeat('a', 64) . '… in conversation room1 (' . str_repeat('ả', 128) . '…)',
				'{user} locked thread {thread} in conversation {call} ({reason})',
				[
					'user' => self::threadStateUserParameter(),
					'thread' => self::threadStateThreadParameter(str_repeat('a', 64) . '…'),
					'call' => self::threadStateCallParameter(),
					'reason' => [
						'type' => 'highlight',
						'id' => 'thread-lock-reason',
						'name' => str_repeat('ả', 128) . '…',
					],
				],
			],
			// The actor may be gone - or may never have been a user account, since a
			// Thread Manager can be a moderator - so the display name frozen at emit
			// time is the fallback.
			'deleted actor falls back to the frozen display name' => [
				'thread_closed',
				array_merge($baseParameters, ['userId' => 'deletedActor']),
				'actor-displayname closed thread Thread 1 in conversation room1',
				'{user} closed thread {thread} in conversation {call}',
				[
					'user' => [
						'type' => 'highlight',
						'id' => 'deletedActor',
						'name' => 'actor-displayname',
					],
					'thread' => self::threadStateThreadParameter(),
					'call' => self::threadStateCallParameter(),
				],
			],
			// Nothing left to name the actor with: the established wording of this
			// file is used and no `user` parameter is emitted at all, rather than
			// putting a bare account id in front of the reader.
			'deleted actor without a frozen display name is not named' => [
				'thread_closed',
				array_merge($baseParameters, ['userId' => 'deletedActor', 'userDisplayName' => '']),
				'A deleted user closed thread Thread 1 in conversation room1',
				'A deleted user closed thread {thread} in conversation {call}',
				[
					'thread' => self::threadStateThreadParameter(),
					'call' => self::threadStateCallParameter(),
				],
			],
			'deleted actor without a frozen display name, locked with a reason' => [
				'thread_locked',
				array_merge($baseParameters, ['userId' => 'deletedActor', 'userDisplayName' => '', 'reason' => 'Off topic']),
				'A deleted user locked thread Thread 1 in conversation room1 (Off topic)',
				'A deleted user locked thread {thread} in conversation {call} ({reason})',
				[
					'thread' => self::threadStateThreadParameter(),
					'call' => self::threadStateCallParameter(),
					'reason' => [
						'type' => 'highlight',
						'id' => 'thread-lock-reason',
						'name' => 'Off topic',
					],
				],
			],
			// A federated participant can be a moderator and therefore a Thread
			// Manager, and is rendered as a `user` rich object with its remote, the
			// way parseChatMessage() renders federated actors.
			'federated actor renders as a remote user' => [
				'thread_unlocked',
				array_merge($baseParameters, [
					'userType' => Attendee::ACTOR_FEDERATED_USERS,
					'userId' => 'remote@example.tld',
					'userDisplayName' => 'Remote Moderator',
				]),
				'Remote Moderator unlocked thread Thread 1 in conversation room1',
				'{user} unlocked thread {thread} in conversation {call}',
				[
					'user' => [
						'type' => 'user',
						'id' => 'remote',
						'name' => 'Remote Moderator',
						'server' => 'example.tld',
					],
					'thread' => self::threadStateThreadParameter(),
					'call' => self::threadStateCallParameter(),
				],
			],
			// "0" is falsy in PHP but is a perfectly legitimate Thread Title, and
			// must not be swapped for the thread id.
			'a thread titled "0" keeps its title' => [
				'thread_closed',
				array_merge($baseParameters, ['title' => '0']),
				'actor-displayname closed thread 0 in conversation room1',
				'{user} closed thread {thread} in conversation {call}',
				[
					'user' => self::threadStateUserParameter(),
					'thread' => self::threadStateThreadParameter('0'),
					'call' => self::threadStateCallParameter(),
				],
			],
			// A non-sensitive push notification gets the same "{header}\n{body}"
			// shape the rest of this file uses, so iOS has a body to split off.
			'push notification carries a header and a body line' => [
				'thread_locked',
				array_merge($baseParameters, ['reason' => 'Off topic']),
				"actor-displayname in room1\nLocked thread Thread 1 (Off topic)",
				"{user} in {call}\nLocked thread {thread} ({reason})",
				[
					'user' => self::threadStateUserParameter(),
					'thread' => self::threadStateThreadParameter(),
					'call' => self::threadStateCallParameter(),
					'reason' => [
						'type' => 'highlight',
						'id' => 'thread-lock-reason',
						'name' => 'Off topic',
					],
				],
				$isSensitive = false, $isPushNotification = true,
			],
			'push notification without a reason' => [
				'thread_reopened',
				$baseParameters,
				"actor-displayname in room1\nReopened thread Thread 1",
				"{user} in {call}\nReopened thread {thread}",
				[
					'user' => self::threadStateUserParameter(),
					'thread' => self::threadStateThreadParameter(),
					'call' => self::threadStateCallParameter(),
				],
				$isSensitive = false, $isPushNotification = true,
			],
			'push notification for a deleted actor' => [
				'thread_closed',
				array_merge($baseParameters, ['userId' => 'deletedActor', 'userDisplayName' => '']),
				"Deleted user in room1\nClosed thread Thread 1",
				"Deleted user in {call}\nClosed thread {thread}",
				[
					'thread' => self::threadStateThreadParameter(),
					'call' => self::threadStateCallParameter(),
				],
				$isSensitive = false, $isPushNotification = true,
			],
			// Unicode line separators and bidi overrides are the same forged-body
			// and reversed-rendering vector as a C0 control character.
			'unicode line separators and bidi overrides are collapsed' => [
				'thread_locked',
				array_merge($baseParameters, [
					'title' => "Thread 1\u{2028}Your account was accessed",
					'reason' => "Off topic\u{202E}drowssap ruoy retne esaelP",
				]),
				'actor-displayname locked thread Thread 1 Your account was accessed in conversation room1 (Off topic drowssap ruoy retne esaelP)',
				'{user} locked thread {thread} in conversation {call} ({reason})',
				[
					'user' => self::threadStateUserParameter(),
					'thread' => self::threadStateThreadParameter('Thread 1 Your account was accessed'),
					'call' => self::threadStateCallParameter(),
					'reason' => [
						'type' => 'highlight',
						'id' => 'thread-lock-reason',
						'name' => 'Off topic drowssap ruoy retne esaelP',
					],
				],
			],
		];
	}

	/**
	 * Story 4.2, AC1/AC2/AC7: the four Thread lifecycle subjects are parsed by
	 * parseThreadStateChange() and never reach parseChatMessage() - they carry no
	 * comment at all, so reaching it would be fatal.
	 */
	#[DataProvider('dataPrepareThreadStateChange')]
	public function testPrepareThreadStateChange(string $subject, array $subjectParameters, string $parsedSubject, string $richSubject, array $richSubjectParameters, bool $isSensitive = false, bool $isPushNotification = false): void {
		/** @var INotification&MockObject $notification */
		$notification = $this->createMock(INotification::class);
		$l = $this->createMock(IL10N::class);
		$l->expects($this->any())
			->method('t')
			->willReturnCallback(fn ($text, $parameters = []) => vsprintf($text, $parameters));

		$this->notificationManager->method('isPreparingPushNotification')
			->willReturn($isPushNotification);

		$notification->method('getApp')->willReturn('spreed');
		$notification->method('getUser')->willReturn('recipient');
		$notification->method('getObjectType')->willReturn('room');
		$notification->method('getObjectId')->willReturn('roomToken');
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($subjectParameters);
		$notification->method('setIcon')->willReturnSelf();
		$notification->method('setPriorityNotification')->willReturnSelf();
		$notification->method('addParsedAction')->willReturnSelf();

		$action = $this->createMock(IAction::class);
		$action->method('setLabel')->willReturnSelf();
		$action->method('setParsedLabel')->willReturnSelf();
		$action->method('setLink')->willReturnSelf();
		$action->method('setPrimary')->willReturnSelf();
		$notification->method('createAction')->willReturn($action);

		// AD-20: routing identifiers are not content, so the deep link carries the
		// Thread even for a sensitive conversation.
		$capturedLinkParameters = [];
		$this->url->method('linkToRouteAbsolute')
			->willReturnCallback(function (string $routeName, array $parameters = []) use (&$capturedLinkParameters) {
				$this->assertSame('spreed.Page.showCall', $routeName);
				$capturedLinkParameters[] = $parameters;
				return 'https://example.tld/index.php/call/' . ($parameters['token'] ?? '');
			});
		$notification->method('setLink')->willReturnSelf();
		$notification->method('getLink')->willReturn('https://example.tld/index.php/call/roomToken');

		$room = $this->createMock(Room::class);
		$room->method('getId')->willReturn(1234);
		$room->method('getToken')->willReturn('roomToken');
		$room->method('getType')->willReturn(Room::TYPE_GROUP);
		$room->method('getLobbyState')->willReturn(Webinary::LOBBY_NONE);
		$room->method('getDisplayName')->with('recipient')->willReturn('room1');
		$this->manager->expects($this->once())
			->method('getRoomByToken')
			->with('roomToken')
			->willReturn($room);
		$this->avatarService->method('getAvatarUrl')
			->with($room)
			->willReturn('getAvatarUrl');

		$participant = $this->createMock(Participant::class);
		$participant->method('getAttendee')
			->willReturn(Attendee::fromRow([
				'important' => false,
				'sensitive' => $isSensitive,
			]));
		$this->participantService->expects($this->once())
			->method('getParticipant')
			->with($room, 'recipient')
			->willReturn($participant);

		$this->userManager->expects($this->once())
			->method('get')
			->with('recipient')
			->willReturn($this->createMock(IUser::class));
		$this->userManager->method('getDisplayName')
			->willReturnCallback(static fn (string $userId): ?string => $userId === 'actor' ? 'actor-displayname' : null);

		$this->cloudIdManager->method('resolveCloudId')
			->willReturnCallback(function (string $cloudId): ICloudId {
				if (!str_contains($cloudId, '@')) {
					throw new \InvalidArgumentException('Invalid cloud id');
				}
				[$user, $remote] = explode('@', $cloudId, 2);

				$resolved = $this->createMock(ICloudId::class);
				$resolved->method('getUser')->willReturn($user);
				$resolved->method('getRemote')->willReturn($remote);
				$resolved->method('getDisplayId')->willReturn($cloudId);
				return $resolved;
			});

		$this->lFactory->expects($this->once())
			->method('get')
			->with('spreed', 'de')
			->willReturn($l);

		$notification->expects($this->once())
			->method('setParsedSubject')
			->with($parsedSubject)
			->willReturnSelf();
		$notification->expects($this->once())
			->method('setRichSubject')
			->with($richSubject, $richSubjectParameters)
			->willReturnSelf();

		$this->notifier->prepare($notification, 'de');

		$this->assertContains([
			'token' => 'roomToken',
			'threadId' => 42,
		], $capturedLinkParameters, 'The deep link has to name the Thread, even in a sensitive conversation');
	}

	public static function dataPrepareThrows(): array {
		return [
			['Incorrect app', 'invalid-app', null, null, null, null, null],
			'User can not use Talk' => [AlreadyProcessedException::class, 'spreed', true, null, null, null, null],
			'Invalid room' => [AlreadyProcessedException::class, 'spreed', false, false, null, null, null, '12345'],
			'Invalid room without token' => [AlreadyProcessedException::class, 'spreed', false, false, null, null, null],
			['Unknown subject', 'spreed', false, true, 'invalid-subject', null, null],
			['Unknown object type', 'spreed', false, true, 'invitation', null, 'invalid-object-type'],
			'Calling user does not exist anymore' => [AlreadyProcessedException::class, 'spreed', false, true, 'invitation', ['admin'], 'room'],
			['Unknown object type', 'spreed', false, true, 'mention', null, 'invalid-object-type'],
			// Story 4.2: the lifecycle subjects only ever live under the `room`
			// object type - never under `chat`, which
			// \OCA\Talk\Chat\Notifier::markMentionNotificationsRead() would erase,
			// and never under a type shipped mobile clients cannot dispatch. A
			// notification that can never be rendered is marked processed and
			// dismissed rather than re-thrown on every fetch.
			'Thread lifecycle subject under a foreign object type' => [AlreadyProcessedException::class, 'spreed', false, true, 'thread_locked', null, 'invalid-object-type'],
		];
	}

	#[DataProvider('dataPrepareThrows')]
	public function testPrepareThrows(string $message, string $app, ?bool $isDisabledForUser, ?bool $validRoom, ?string $subject, ?array $params, ?string $objectType, string $token = 'roomToken'): void {
		/** @var INotification&MockObject $n */
		$n = $this->createMock(INotification::class);
		$l = $this->createMock(IL10N::class);

		if ($validRoom === null) {
			$this->manager->expects($this->never())
				->method('getRoomByToken');
		} elseif ($validRoom === true) {
			$room = $this->createMock(Room::class);
			$room->expects($this->never())
				->method('getType');
			$n->method('getObjectId')
				->willReturn($token);
			$this->manager->expects($this->once())
				->method('getRoomByToken')
				->with($token)
				->willReturn($room);
		} elseif ($validRoom === false) {
			$n->method('getObjectId')
				->willReturn($token);
			$this->manager->expects($this->once())
				->method('getRoomByToken')
				->with($token)
				->willThrowException(new RoomNotFoundException());
			$this->manager->expects($token !== 'roomToken' ? $this->once() : $this->never())
				->method('getRoomById')
				->willThrowException(new RoomNotFoundException());
		}

		$this->lFactory->expects($validRoom === null ? $this->never() : $this->once())
			->method('get')
			->with('spreed', 'de')
			->willReturn($l);

		$n->expects($validRoom !== true ? $this->never() : $this->once())
			->method('setIcon')
			->willReturnSelf();
		$n->expects($validRoom !== true ? $this->never() : $this->once())
			->method('setLink')
			->willReturnSelf();

		if ($isDisabledForUser === null) {
			$n->expects($this->never())
				->method('getUser');
		} else {
			$n->expects($this->once())
				->method('getUser')
				->willReturn('recipient');
			$r = $this->createMock(IUser::class);
			$this->userManager->expects($this->atLeastOnce())
				->method('get')
				->willReturnMap([
					['recipient', $r],
					['admin', null],
				]);

			$this->config->expects($this->once())
				->method('isDisabledForUser')
				->willReturn($isDisabledForUser);
		}

		$n->expects($this->once())
			->method('getApp')
			->willReturn($app);
		if ($subject === null) {
			$n->expects($this->never())
				->method('getSubject');
		} else {
			$n->expects($this->once())
				->method('getSubject')
				->willReturn($subject);
		}
		if ($params === null) {
			$n->expects($this->never())
				->method('getSubjectParameters');
		} else {
			$n->expects($this->once())
				->method('getSubjectParameters')
				->willReturn($params);
		}
		if (($objectType === null && $app !== 'spreed') || $isDisabledForUser) {
			$n->expects($this->never())
				->method('getObjectType');
		} elseif ($objectType === null && $app === 'spreed') {
			$n->method('getObjectType')
				->willReturn('');
		} else {
			$n->expects($this->any())
				->method('getObjectType')
				->willReturn($objectType);
		}

		if ($message === AlreadyProcessedException::class) {
			$this->expectException(AlreadyProcessedException::class);
		} else {
			$this->expectException(\InvalidArgumentException::class);
			$this->expectExceptionMessage($message);
		}
		$this->notifier->prepare($n, 'de');
	}
}
