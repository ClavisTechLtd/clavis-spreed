# Addendum — Clavis Talk Thread Management

Implementation-level material for the architecture workflow. Companion to `prd.md`; the PRD stays at capability level and points here for mechanism.

All paths are relative to the `clavis-spreed` repository root. Line numbers are against branch `stable34` at commit `6819859` (Talk 24.0.3). They drift — treat them as pointers to the right function, not as addresses.

**This is revision 2.** A four-reviewer pass checked every code claim in revision 1 against the repository: roughly 137 confirmed, 18 with drifted line numbers, 5 unverifiable, and **6 wrong**. The wrong ones mattered — one killed a requirement, three added code paths to a security-relevant enumeration. Each is marked **[corrected r2]** where it appears; `addendum.superseded-r1.md` keeps revision 1 so the corrections can be checked against what they replaced.

---

## 1. What already exists

Threads are not new in upstream Talk. Substantial machinery ships already, and the PRD extends it rather than building alongside it.

### 1.1 Schema

Two tables, created by `lib/Migration/Version22000Date20250623142327.php` and amended by `Version22000Date20250710124258.php`.

**`talk_threads`**

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT, PK | **Not autoincrement.** The id *is* the root comment id. |
| `room_id` | BIGINT | Indexed as `tt_room_threads` |
| `last_message_id` | BIGINT, default 0 | |
| `num_replies` | BIGINT, default 0 | |
| `last_activity` | DATETIME, nullable | Added in the second migration; indexed as `talkthread_lastactive` |
| `name` | STRING(255), default `''` | The Thread Title |

Thread State, the featured flag and Thread Tag associations all need somewhere to live; none of these columns will do.

**`talk_thread_attendees`**

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT, PK, autoincrement | |
| `room_id`, `thread_id`, `attendee_id` | BIGINT | |
| `actor_type`, `actor_id` | STRING(255) | |
| `notification_level` | INTEGER, nullable, default `Participant::NOTIFY_DEFAULT` | The only per-participant thread setting that exists today |

Unique index `tta_throom_attendee` on `(thread_id, room_id, actor_type, actor_id)`, set by `Version22001Date20250927174738.php`. Index `tta_room_attendee` on `(room_id, actor_type, actor_id)`.

### 1.2 Entities, mappers, service

- `lib/Model/Thread.php` — constants `THREAD_NONE = 0`, `THREAD_CREATE = -1`. `getName()` falls back to `'Thread #' . id` with a `// FIXME temporary workaround against empty titles`. `toArray(Room $room)` at :102 is where new API fields attach.
- `lib/Model/ThreadAttendee.php` — `jsonSerialize()` at :75 exposes **only** `notificationLevel`.
- `lib/Model/ThreadMapper.php` — `findById`, `findByIds`, `getForIds` (chunks by 1000, no room filter), `getRecentByRoomId` (orders by `last_activity DESC`), `deleteByRoomId`.
- `lib/Model/ThreadAttendeeMapper.php` — `findAttendeeByThreadIds`, `findAttendeeByThreadId`, `findAttendeesForNotification` (filters `notification_level != NOTIFY_DEFAULT`), `deleteByRoomId`.
- `lib/Service/ThreadService.php` — the seam for almost everything in this PRD. `createThread`, `findByThreadId`, `findByThreadIds`, `preloadThreadsForConversationList`, `renameThread`, `getRecentByRoomId` (clamps limit to 1–50), `getRecentByActor` (the followed-threads query), `findAttendeeByThreadIds`, `findAttendeesForNotificationByThreadId`, `setNotificationLevel`, `ensureIsThreadAttendee`, `removeThreadAttendeesByAttendeeIds`, `updateLastMessageInfoAfterReply`, `deleteByRoom`, `validateThread`.
- `lib/Model/SelectHelper.php:53` — `selectThreadsTable()`. New columns must be added here or they will be absent from joined reads even after the entity knows about them.
- `lib/Listener/ThreadListener.php` — handles only `AttendeesRemovedEvent`. Registered at `lib/AppInfo/Application.php:356`.

### 1.3 Endpoints

`lib/Controller/ThreadController.php`. No `appinfo/routes.php` — routes come from `#[ApiRoute]` attributes.

| Verb | Path | Method |
|---|---|---|
| GET | `/ocs/v2.php/apps/spreed/api/v1/chat/{token}/threads/recent` | `getRecentActiveThreads` |
| GET | `/ocs/v2.php/apps/spreed/api/v1/chat/subscribed-threads` | `getSubscribedThreads` |
| GET | `/ocs/v2.php/apps/spreed/api/v1/chat/{token}/threads/{threadId}` | `getThread` |
| PUT | `/ocs/v2.php/apps/spreed/api/v1/chat/{token}/threads/{threadId}` | `renameThread` |
| POST | `/ocs/v2.php/apps/spreed/api/v1/chat/{token}/threads/{messageId}/notify` | `setNotificationLevel` |

`prepareListOfThreads()` at :269 assembles the `TalkThreadInfo` shape all list endpoints return.

`renameThread` at :200 implements the authority rule the PRD's Thread Manager mirrors: own root message OR `hasModeratorPermissions(false)`, at :224-228. Copy that expression rather than reinventing it.

### 1.4 Message ↔ thread linkage

There is **no new column on the comments table**. Linkage is threefold, and all three matter:

1. **`oc_comments.topmost_parent_id`** — the platform column holding the canonical thread id. The idiom throughout is `(int)$comment->getTopmostParentId() ?: (int)$comment->getId()`, so a root message is its own thread. Talk enrols a message in a thread without quoting via `$comment->setParentId((string)$threadId)` — `lib/Chat/ChatManager.php:179` and `:418`.
2. **`oc_comments.meta_data` JSON** — `Message::METADATA_THREAD_ID = 'thread_id'` and `METADATA_THREAD_TITLE = 'thread_title'` (`lib/Model/Message.php:27-28`), surfaced as `threadId` / `threadTitle`. This is what federation and ActivityPub read, and — important for §3.2 — what the file-share and attachment paths read out of `talkMetaData`.
3. **`talk_threads.id == root comment id`**, which is why the id column is deliberately not autoincrement. It is also why open question 8 (root message deleted or expired) could not be dodged: the Thread has no identity of its own. **AD-5 settled it** — author or moderator deletion tombstones the comment so the Thread survives, while message expiry hard-deletes and needs a bounded reaper for the orphaned thread rows.

The query-level thread filter is `lib/Chat/CommentsManager.php:129-133` — `WHERE (id = :topmostParentId OR topmost_parent_id = :topmostParentId)`, marked `FIXME: TEMPORARY method until nextcloud/server#53896 is merged`. Anything that filters messages within a thread goes through this. It filters *reads*; there is no counting equivalent, which is §4.5's problem.

`Message::toArray(string $format, ?Thread $thread)` at `lib/Model/Message.php:187` always emits `threadId`, and emits `isThread`, `threadTitle`, `threadReplies` **only when a `Thread` entity is passed in**. The parameter has no default, so every call site passes it explicitly — which means a new field cannot be silently forgotten.

### 1.5 Front end

- `src/components/RightSidebar/Threads/` — `ThreadsTab.vue` (the list), `ThreadItem.vue` (a row), `ThreadHeader.vue`, `threadsConstants.ts`. **[corrected r2]** `ThreadHeader.vue` is filed under `RightSidebar/` but does not render there — it renders in `TopBar.vue:54` and `ChatView.vue:27`. The directory layout misleads; do not infer from it.
- `src/stores/chatExtras.ts` — `useChatExtrasStore` holds `threads` (:55) keyed token → threadId → `ThreadInfo`, `followedThreads` as a `Set` (:56), plus `addThread` (:234), `fetchSingleThread` (:248), `fetchRecentThreadsList` (:274), `fetchFollowedThreadsList` (:290), `setThreadNotificationLevel` (:322), `updateThread` (:344), `renameThread` (:384), `clearThreads` (:422), `removeMessageFromThread` (:443). Exported at :837-878.
- `src/stores/chat.ts` — `threadBlocks` at :81 (`reactive<TokenIdMap<Set<number>[]>>({})`) and `checkIfBelongsToContext()` at :55-61, the client-side thread filter. Read it before FR-40: it is why a Thread Root Message stays permanently in the main timeline no matter what state the Thread is in.
- `src/services/messagesService.ts` — **[corrected r2]** the thread block is **:322-394**, not :330-390. Five functions: `getRecentThreadsForConversation` (:330), `getSingleThreadForConversation` (:346), `getSubscribedThreads` (:358), `setThreadNotificationLevel` (:376), `renameThread` (:390).
- `src/composables/useGetThreadId.ts` — 21 lines, the whole routing mechanism: `createSharedComposable` over `useRouteQuery('threadId', '0', {transform})`. A writable ref; assigning to it navigates. **[corrected r2]** twelve consumers, not thirteen — the earlier count included the definition itself.
- `src/types/openapi/openapi.ts:3293-3336` — generated `Thread`, `ThreadAttendee`, `ThreadInfo` schemas.
- `src/constants.ts:279-280` — `THREAD_CREATED`, `THREAD_RENAMED`.

**Routing scheme today:** `/call/{token}?threadId={id}#message_{messageId}`. There is no thread route. `createTalkRouter` defines `root` (:47), `notfound` (:53), `forbidden` (:59), `duplicatesession` (:65), `conversation` (:71, `/call/:token`), `recording` (:77).

**[corrected r2] There is a second router factory.** `createMemoryRouter()` at `src/router/router.ts:96-102` serves the Files sidebar and declares its own route also named `conversation`. Any thread or Directory address must work in both factories — this is FR-42, which exists only because the review found it.

### 1.6 Where the thread list lives now

The concrete shape of the PRD's item 4: the threads list is **not a registered sidebar tab**. `src/components/RightSidebar/RightSidebar.vue:48-55` renders it in a `v-else-if` on `contentState === 'threads'`, *instead of* the whole normal tab set, which sits in a `<template v-else>` opening at :56. **[corrected r2]** the block is :48-55, not :47-55; line 47 closes the search tab above.

It is opened from exactly one place, confirmed by repo-wide grep. `RightSidebar/SharedItems/SharedItemsTab.vue` declares the emit at :49 and fires it at :138, from a "Show more threads" button under a three-item preview (`slice(0, 3)` and `length > 3` at :73-74). `RightSidebar.vue:131` wires it to `handleUpdateState('threads')` at **:551-563** (threads branch :557-559). **[corrected r2]** :551-563, not :550-562. `RightSidebarContent.vue:104` only reads the state to build the title.

The current path: open right sidebar → Shared items tab → scroll to Recent threads → Show more threads. Three levels deep behind a tab about files, and it replaces the tab set rather than joining it. FR-12 changes both facts, which is why its assumption tag calls it a change in kind.

A second, global list already exists in the left sidebar: `LeftSidebar.vue:313-331`, `v-for` at :317 over a local computed `followedThreads` (:725-727) that returns `chatExtrasStore.followedThreadsList`, with a "Threads" nav button at **:253-260** **[corrected r2]**. That is the PRD's Followed Thread List; FR-20 and FR-32 extend its rows rather than replacing it. `hasTalkFeature('local', 'threads')` at :490 is tokenless, because the sidebar has no conversation context.

---

## 2. Notification behaviour — what is actually wrong [corrected r2]

Revision 1 of this PRD made two claims here and **both were wrong**. The corrected picture is narrower and changes what is worth building.

### 2.1 The two `setObject` calls append; neither overwrites

`lib/Notification/Notifier.php:633-642`:

```php
if (!$this->notificationManager->isPreparingPushNotification() && !$participant->getAttendee()->isSensitive()) {
    $notification->setParsedMessage($parsedMessage);
    $notification->setRichMessage($message->getMessage(), $message->getMessageParameters());

    // Forward the message ID as well to the clients, so they can quote the message on replies
    $notification->setObject($notification->getObjectType(), $notification->getObjectId() . '/' . $message->getMessageId());
    if (isset($messageParameters['threadId'])) {
        $notification->setObject($notification->getObjectType(), $notification->getObjectId() . '/' . $messageParameters['threadId']);
    }
}
```

The second call re-reads `getObjectId()`, which by then **already contains the first call's result**. So the value is `{token}/{messageId}/{threadId}`, not `{token}/{threadId}`. Nothing is lost. Three independent proofs:

1. `INotification::setObject` is a plain setter with no accumulation semantics — the accumulation is in the `getObjectId()` re-read.
2. The repository's own integration test asserts the composed value: `tests/integration/features/chat-4/threads.feature:223` expects `object_id = room1/Message 2/Thread 1`.
3. The shipped Android client parses it positionally, reading index `[1]` as the message id and `[2]` as the thread id — `clavis-talk-android/.../NotificationWorker.kt:1016-1025`.

**Consequence:** the requirement revision 1 wrote to "stop the overwrite" described a defect that does not exist and has been deleted. What replaced it is the append-only rule in the PRD's §7 for this composed value — the property that is currently correct and must stay correct.

### 2.2 Push payloads carry neither identifier, not merely no thread id

The guard at :633 wraps **both** `setObject` calls. So a push payload's object id is the bare room token — no message id either. Revision 1 understated this. That strengthens FR-35 rather than weakening it: the push payload is missing more than one field.

`setLink()` at :604-610 *does* carry `threadId` and sits outside the guard — but that is irrelevant to push, because push payloads have no link field at all (confirmed from `DecryptedPushMessage.kt`, which declares none). The web and in-app path uses the link; the push path uses the object id. That framing in the PRD is sound.

### 2.3 Mobile clients are not blocked, and the real failure is narrower

Revision 1 claimed mobile clients "cannot route into a thread no matter what they implement". **False.** Android already routes into threads from a push: on receiving one it fetches the notification over OCS — which is *not* push-prepared, so its object id carries both identifiers — and reads the thread id from that (`NotificationWorker.kt:484-493`).

The issue's reported symptom, landing in the main chat, comes from the **fallback at `NotificationWorker.kt:496-513`** when that OCS fetch fails. That is a client-side failure mode, not a missing server capability.

This is why the PRD demotes the whole of §4.8's second half to groundwork. Putting the identifiers in the push payload removes the client's dependence on a second network call, which removes the fallback's reason to exist. It does not, on its own, change what a user sees.

### 2.4 Push subject construction and the size budget

`Notifier.php:686-689`:

```php
} elseif ($this->notificationManager->isPreparingPushNotification()) {
    $shortenMessage = Util::shortenMultibyteString($parsedMessage, 100);
```

A hundred characters for the message preview, then subjects like `'{user} in {call}' . "\n{message}"`. FR-33 adds a thread line; FR-37 governs what yields. The hard limit is the platform notifications app's encryption budget, not this constant — see §6.

### 2.5 Where `threadId` enters, and the nine subjects

`lib/Chat/Notifier.php:628-638` `createNotification()` puts `threadId` into the message parameters when non-null and non-zero. That part works.

`lib/Notification/Notifier.php:275` gates the subjects that route to `parseChatMessage()`:

```php
if ($subject === 'reply' || $subject === 'mention' || $subject === 'mention_direct' || $subject === 'mention_group' || $subject === 'mention_team' || $subject === 'mention_all' || $subject === 'chat' || $subject === 'reaction' || $subject === 'reminder') {
```

Nine subjects. FR-33 requires the Thread Title on all nine, with a test each — revision 1 covered two.

The subscription logic deciding *who* is notified is `lib/Chat/Notifier.php:253-293` and is unchanged by this PRD. FR-34's lifecycle notifications should reuse `findAttendeesForNotificationByThreadId()` (`ThreadService:196`) rather than inventing a recipient set.

---

## 3. Thread state (PRD §4.1)

### 3.1 Storage

`talk_threads` has no state column. A new one is needed, plus:

- `lib/Model/Thread.php` — property, `addType` in the constructor, `createFromRow`, `fromJson`, `toJson` (the distributed-cache serialisation — miss it and state vanishes on a cache round trip), and `toArray()`.
- `lib/Model/SelectHelper.php:53` — `selectThreadsTable()`, both the aliased and unaliased branches.
- `lib/ResponseDefinitions.php:724-737` — the `TalkThread` psalm type.
- Regenerate `openapi.json` / `openapi-full.json` / `openapi-federation.json` and the `src/types/openapi/*.ts` they produce.

An integer column with named constants matches how `notification_level` is already handled and leaves room for a fourth state without another migration.

Zulip's counter-example: they implemented their equivalent as a mutation of the topic *name* and paid for it in link-breakage bugs (§10.4). Put the state in a column.

### 3.2 The nine write paths Locked must block (FR-5) [corrected r2]

Revision 1 enumerated six. Code review found **nine**. The three that were missing are marked; the one that matters most is the file share, because an implementer would assume the rich-object row covered it.

| # | Path | Entry point | Notes |
|---|---|---|---|
| 1 | Typed message | `lib/Controller/ChatController.php:376` `sendMessage()` | The obvious one |
| 2 | Bot message via API | `lib/Controller/BotController.php:169` `sendMessage()` | |
| 3 | **In-process bot answer** | `lib/Service/BotService.php:305` | **[missed in r1]** Bots answering a `BotInvokeEvent` post with `threadId:` derived at :300, never touching `BotController`. Unvalidated. |
| 4 | Rich object share | `lib/Controller/ChatController.php:745` `shareObjectToChat()` | Rich objects **only** — it runs `richObjectValidator`. Does not cover file shares. Silently resets an invalid thread to 0 at :788-794; a Locked thread must error instead. |
| 5 | Poll | `lib/Controller/PollController.php:89` `createPoll()` | Same silent-reset pattern at :127-133 |
| 6 | **File share** | `lib/Chat/SystemMessage/Listener.php` `handle()` :120 → `fixMimeTypeOfVoiceMessage()` :400 | **[missed in r1]** Reads `talkMetaData.threadId` at :435 and `threadTitle` at :441, posts `file_shared` into the thread at :448-455, and **can create a Thread** at :459. Already exercised by `threads.feature:238-243`. |
| 7 | **Attachment upload** | `lib/Controller/ChatController.php:2505` `postAttachmentToRoom()` | **[missed in r1]** Reads `threadId` from `talkMetaData` at :2574, passes it to `addSystemMessage` at :2601, and performs **no `validateThread()` at all**. Genuinely separate — the listener in row 6 explicitly bails out for this route at :382-386. Validation must be added before a guard can exist. |
| 8 | Scheduled message, accepted | `lib/Controller/ChatController.php:527` `scheduleMessage()` | Validates at request time |
| 9 | Scheduled message, fired | `lib/BackgroundJob/SendScheduledMessages.php:145-179` | **No live request, no acting participant.** A message scheduled before locking fires later; must fail with a visible reason |

**The guard's exemption cannot be "system messages are exempt."** Rows 4, 5, 6 and 7 all post their content *via* `addSystemMessage()`. Exempting system messages leaves four holes. The exemption must name the specific state-change messages FR-4, FR-6 and FR-7 introduce.

That also constrains where the guard goes. A single choke point at `ChatManager::sendMessage()` (:383) plus `addSystemMessage()` (:147) covers more paths than nine controller-level guards, but it is exactly the point where the exemption gets hard. Whichever seam is chosen, the table above is the test matrix.

FR-3's reopen-by-posting applies to all nine paths too — a file dropped into a Closed Thread should revive it just as a typed message does.

Editing, deleting and reacting (FR-5's assumption-tagged consequences) live on further paths and need locating if those assumptions survive review. `ChatManager::deleteMessage()` is at :626.

### 3.3 Reopen-by-reply (FR-3)

Every reply passes through `ThreadService::updateLastMessageInfoAfterReply()` (:256), which bumps `num_replies`, `last_message_id` and `last_activity`, and invalidates the cache entry. It returns a bool used elsewhere as an "is this a real thread" test. Extending it to clear a Closed state puts the transition on the one path every reply takes and reuses an invalidation that is already correct.

Check it is reached by all nine paths in §3.2 before relying on that.

### 3.4 System messages [corrected r2]

`thread_created` and `thread_renamed` are the pattern. Emitted via `ChatManager::addSystemMessage(..., threadId: $threadId)`; parsed in `lib/Chat/Parser/SystemMessage.php:587-606`; relayed because they appear in `SYSTEM_MESSAGE_TYPE_RELAY` at `lib/Signaling/Listener.php:87-88`.

On the client, a new state-change message needs a constant in `src/constants.ts:279-280` and classification in `src/utils/message.ts` — **and revision 1 understated this**: the thread constants appear in **three** lists in that file, not one. `SYSTEM_MESSAGE_TYPE_RELAY` at :50-51, `SYSTEM_MESSAGE_TYPE_UNTRANSLATED` at :76-77, and `SYSTEM_MESSAGE_TYPE_HIDDEN` at :90-91. Miss the relay list and other participants see nothing without a reload, which breaks the PRD's real-time NFR.

---

## 4. Per-thread unread (PRD §4.7)

### 4.1 Reinstating what upstream dropped

`Version22000Date20250623142327.php:87-103` created `last_read_message`, `last_mention_message`, `last_mention_direct` and `read_privacy` on `talk_thread_attendees`. `Version22000Date20250710124258.php:49-60` dropped all four, three weeks later, deliberately. The PRD reinstates the read-marker columns because deriving unread from the conversation marker cannot deliver the per-Thread independence FR-28 requires. Read both migrations before writing a third — the column definitions to restore are already written there.

Not everything comes back. `read_privacy` governs read receipts, not unread counts, and nothing here needs it. `last_mention_message` **is** needed, because FR-32 and FR-31 require distinguishing an unread mention from an unread reply. `last_mention_direct` **does not come back**: the requester settled the split as two-way at the client — unread mention versus unread reply, with direct and group mentions not distinguished — closing PRD open question 8's dependent. One less column and one less divergence on a table upstream still changes.

This is the fork's largest deliberate divergence (PRD §6.1). Leave a comment in the migration saying why the columns are back, so whoever merges the next upstream release does not read them as an accident.

### 4.2 What the marker is layered over

`talk_attendees.last_read_message` exists and is live — added by `Version7000Date20190724121136.php:41`, carried through `Version10000Date20201015134000.php:99`. One value per participant per conversation. The dropped columns were on the *other* table; this one was never touched.

The fallback in PRD §4.7 rests on that column: a Thread with no `talk_thread_attendees` row for this participant reads its unread state from this conversation-level value. That is what stops a member joining a room with five hundred old Threads from seeing five hundred badges — on join the conversation marker is already at the latest message, so every pre-existing Thread computes to zero.

### 4.3 Where rows get created, and what that disturbs

Rows in `talk_thread_attendees` already exist for a different purpose: `ThreadService::ensureIsThreadAttendee()` (:226) inserts one with `NOTIFY_DEFAULT` on any reply, and `setNotificationLevel()` (:206) upserts one. The table is already keyed correctly and already has an insert-if-missing path.

The new write is on **read**, not on reply. Two consequences:

- **Subscription and read state come to share a row.** A row's existence currently means "this participant is a thread attendee", which drives `findAttendeesForNotification()` (:99, filtered on `notification_level != NOTIFY_DEFAULT`) and `getRecentByActor()` (:143, filtered on `notification_level != NOTIFY_NEVER` — the Followed Thread List). Because both filter on `notification_level`, a row created on read with `NOTIFY_DEFAULT` does not leak into either. But that correctness is **incidental**, not designed: a future change to either filter would silently turn every read Thread into a followed one. Write a test that asserts it.
- **Row reclamation already exists.** `ThreadListener` handles `AttendeesRemovedEvent` → `removeThreadAttendeesByAttendeeIds()` (:246), so leaving a conversation drops the rows. That satisfies FR-28's last consequence and the NFR on bounded growth for free.

### 4.4 The unspecified case (PRD open question 1) — **resolved by AD-6**

FR-28 says reading the main chat must not clear thread unread. But a Thread with no row falls back to the conversation marker, and reading the main chat advances that marker — so for never-opened Threads it would clear them. The two requirements meet here and the PRD leaves it open deliberately.

Options:

1. **Accept it.** No extra writes. A participant who reads only the main chat loses badges on Threads they never opened. Defensible — they have read the conversation — but it is the shared-marker defect surviving in a corner.
2. **Materialise on marker advance.** When the conversation marker moves, write rows for Threads that currently have unread, freezing them at their pre-advance value. Correct, bounded by threads-with-unread at that instant, but adds a write burst to a very common operation.
3. **A separate fallback baseline** on `talk_attendees`, advanced only by FR-29's mark-all-threads-read. Cheapest correct option: one extra column, no per-thread writes. Cost is a third notion of "read" in a document already managing two.

Option 3 is the one to argue for first, and it composes with FR-29, which the PRD already requires. **The architecture took it.** AD-6 adds the nullable baseline column and names the one seam that freezes it — `ParticipantService::updateLastReadMessage()`, on **any** advance including a post, not only on reading the main chat. That detail is load-bearing and is not visible in the option as sketched above: wiring the freeze to "reading the main chat" would let a participant whose first action is a thread reply jump their own marker past everything and resolve every never-opened Thread as read.

### 4.5 Splitting the conversation unread count (FR-30)

This is the change with the most blast radius, and the code makes the problem exact.

`lib/Service/RoomFormatter.php:335`:

```php
$roomData['unreadMention'] = $roomData['unreadMessages'] !== 0 && $lastMention !== 0 && $lastReadMessage < $lastMention;
```

The mention flag is **gated on the count being non-zero**. FR-28 forces thread replies out of `unreadMessages` — otherwise the count can never reach zero without opening every Thread. That exclusion silently disables thread mentions on every client unless this expression changes with it. `unreadMentionDirect` on the next line has the same shape. FR-30's second consequence exists precisely to pin this.

The count itself comes from `ChatManager::getUnreadCount()` (:993), which calls the platform method:

```php
$unreadCount = $this->commentsManager->getNumberOfCommentsWithVerbsForObjectSinceComment('chat', (string)$chat->getId(), $lastReadMessage, [self::VERB_MESSAGE, self::VERB_OBJECT_SHARED]);
```

That is an `ICommentsManager` method with **no thread filter**. Talk already overrides the *read* equivalent in its own `CommentsManager` to add a `topmost_parent_id` filter (:101-133, the `FIXME` awaiting `nextcloud/server#53896`) — so the pattern for a counting override exists, but the override itself does not, and writing it is work no revision-1 scope list contained.

Two more details. The result is cached in `unreadCountCache` keyed `{roomId}-{lastReadMessage}` for 1800s, so a main-chat-only count needs its own key or it will collide with the old semantics across an upgrade. And `RoomFormatter.php:325-330` short-circuits the count to 0 when the last read message equals the room's last message — that shortcut becomes wrong once the last message may be a thread reply.

### 4.6 The dead columns FR-31 needs

`talk_attendees.has_unread_threads`, `has_unread_thread_mentions`, `has_unread_thread_directs` were created by `Version22000Date20250623142327.php:110-128`, are declared on `lib/Model/Attendee.php:150-152`, hydrated at `lib/Model/AttendeeMapper.php:315-317`, and selected at `lib/Model/SelectHelper.php:112-114` — and have **zero callers**. Grep finds no `setHasUnreadThreads*` or `getHasUnreadThreads*` anywhere in `lib/` or `src/`.

They are exactly what FR-31 needs, and require no migration. Writing the callers is the whole of FR-31's storage work. Two of the three are what FR-31 needs — `has_unread_threads` and `has_unread_thread_mentions` — which is what distinguishes an unread reply from an unread mention. `has_unread_thread_directs` stays uncalled: the client split is two-way by the requester's decision, so nothing separates a direct mention from a group one. A separate boolean on the participant row is precisely what expresses "main chat fully read, but a Thread is not".

### 4.7 The performance trap

The naive per-Thread count is one query per Directory row. At the scale this feature exists for, that is the Directory being slow at the moment it matters. Options: a single grouped query across the page's thread ids; deriving a has-unread boolean from `talk_threads.last_message_id` versus the marker and counting only where a badge will show; or a bounded count that stops at the display cap, since FR-32 caps the rendered number anyway. AC-2 catches it if none is done — but only if the baseline is captured before shipping.

---

## 5. Tags, featuring, and the Directory

### 5.1 Thread Tags — and the precedent revision 1 denied existed [corrected r2]

Revision 1 stated there was "no conversation-level label or tag mechanism in Talk to extend". **Wrong.** `lib/Service/ConversationTagService.php` ships, with `lib/Model/ConversationTag.php` and a mapper. Its constants are instructive:

```php
public const MAX_CUSTOM_TAGS_PER_USER = 100;
public const MAX_TAG_IDS_PER_CONVERSATION = 20;
public const MAX_TAG_NAME_LENGTH = 250;
```

Its surface: `getTags(string $userId)`, `getTag`, `createTag(string $userId, string $name)`, `updateTag`, `deleteTag`, `deleteAllTagsByUserId`, `validateTagIdsForUser`, `reorderTags`, `setCollapsed`. It throws `InvalidTagNameException`, `TagLimitExceededException` and `TagNameAlreadyInUseException`, and normalises names through `normalizeTagName()`.

**But it is per-user, not shared.** Every method is keyed by `$userId`; `reorderTags` and `setCollapsed` exist because these tags are how one person groups their own conversation list into collapsible sidebar sections. It is Gmail labels, not a shared taxonomy. Thread Tags are shared within the Conversation (FR-23, FR-24), so this service **cannot be extended directly** — the ownership model is wrong.

What it still gives:

- **The vocabulary.** Talk already says "tag" to users (`conversation-tags` in `lib/Capabilities.php:136`). This is why the PRD renamed Thread Label to Thread Tag: one product should not ship both words.
- **The numbers.** `MAX_TAG_IDS_PER_CONVERSATION = 20` is the same cap FR-23 takes; this codebase has already settled on it.
- **Name handling to copy.** `normalizeTagName()`, `TagNameAlreadyInUseException`, and the trimming FR-26 requires all exist here already.
- **The v2 surface.** `updateTag` and `deleteTag` are the rename and delete that PRD §9.2 defers. When tag administration is picked up, copy this rather than designing it.

Two relationships are needed and neither exists: tag text → colour scoped per Conversation (FR-24), and Thread → tag (FR-23). FR-24's Conversation-scoped colour binding is what forces tag identity to be a row rather than a string on the thread. Case-insensitive identity (FR-24, assumption 13) means the comparison key needs deciding — a normalised column, as `ConversationTagService` already does, rather than an application-level `strtolower` a second code path will forget.

### 5.2 Featured Threads (FR-21, FR-22)

A boolean or nullable ordering value on `talk_threads`, plus the featured-first sort in `ThreadMapper::getRecentByRoomId()` (:98) and in `ThreadService::getRecentByActor()` (:143, raw QueryBuilder). FR-22's bound must be enforced at write time — a sort cannot enforce a count.

FR-14 requires Featured Threads to survive the state filter. That makes the Directory query a filter with an OR on the featured flag, not a plain `WHERE state = ?`. Getting this wrong is what produced the contradiction the adversarial review found in revision 1: featuring a Closed Thread would have hidden it, making its unread badge unclearable.

The naming matters beyond aesthetics: `pinned-messages` is a shipped capability (`lib/Capabilities.php:131`) with `PinnedMessage.vue` and `PinnedMessageItem.vue` on the client. A "pinned thread" and a "pinned message" in the same room, both in the sidebar, is a support burden. Hence "featured".

### 5.3 Directory pagination and filtering (FR-14, FR-15, FR-17)

`ThreadService::getRecentByRoomId()` clamps its limit to 1–50 and takes no offset. `getRecentByActor()` takes limit and offset. Neither takes a state or tag filter. FR-17's requirement that filtering and search apply across all Threads rather than only loaded ones puts these in the query, not the client store — so the existing `getRecentActiveThreads` endpoint gains parameters or a new endpoint appears. The additive-only discipline in the PRD's §7 favours parameters with defaults that preserve current behaviour.

`getRecentByActor()` carries `// FIXME ORDER BY last_activity and subscription moment of the user for better sorting?` at :157 — an upstream open question adjacent to FR-13's ordering. Read it before choosing a sort.

### 5.4 Search (FR-18 … FR-20)

Title search is a new query over `talk_threads.name`. The diacritic-insensitivity in FR-18 (assumption 9) is the substantive constraint: it depends on the database's collation, and deployment targets are not all one engine. Settle it early — a normalised search column written at create and rename time is portable; relying on collation is not.

Message search already exists and is thread-aware: `lib/Search/MessageSearch.php:292-316` adds `threadId` to the deep link and the result attributes, and `src/components/RightSidebar/SearchMessages/SearchMessagesTab.vue:223-235` already reads it (`:224` derives it, treating `threadId === messageId` as no thread; `:232` puts it in the route query). FR-19's handoff has a working target.

### 5.5 Cache invalidation (PRD §5, and the top risk in §12)

`ThreadService` caches through `ICacheFactory` under `talk.threads`, prefix `thread/`, key `thread/{roomId}/{threadId}`, TTL 900s, **with negative caching** — a miss stores `''` and later reads throw `DoesNotExistException` from that cached empty value. `createThread`, `renameThread` and `updateLastMessageInfoAfterReply` all refresh or invalidate.

Every new mutation must do the same. The negative caching means a bug here can make a *newly visible* Thread invisible, not only leave a stale one stale. And because the failure is invisible to the actor who caused it, only a multi-actor test finds it.

---

## 6. Dependencies and limits

**Push payload size.** The platform notifications app encrypts each payload for the target device's public key, which bounds the plaintext to a couple of hundred bytes — that is why `Notifier.php:686-689` truncates the preview to 100 characters. FR-37's budget question was open question 7 and is now **closed as non-blocking**: FR-37 already states the policy, and the measurement is acceptance criteria inside the notification story. It must still be measured against the notifications app's actual limit, not inferred from this constant — it just no longer gates the epic.

**Clavis push proxy** (`clavis-push`, Go). Cannot participate: `internal/proxy/handler.go:19-26` documents that `Subject` and `Signature` are ciphertext the proxy cannot read. Its `README.md` records that the proxy → APNs/FCM hop is unimplemented, blocked on an Apple `.p8` key and a Firebase project, with `NotImplementedSender` failing loudly rather than pretending. FR-35 and FR-37 are verifiable at payload construction but not on a device.

**Federation.** `lib/Controller/ChatController.php:898` and `:1273` carry `// FIXME support threads in federation`. The proxy layer for the four existing thread endpoints is `lib/Federation/Proxy/TalkV1/Controller/ThreadController.php`, and `UserConverter::convertThreadInfo()` at :170 rewrites the room token and message params. New thread fields would need handling there if federation were in scope. It is not.

**Repository state.** Shallow clone, depth 1 (`.git/shallow`; `git rev-list --count HEAD` returns 1). `git fetch --unshallow` is a prerequisite — without history, rebasing onto upstream releases is impossible, and PRD §6.1 depends on it.

**Capability flags.** `lib/Capabilities.php:130` declares `threads` unconditionally in `FEATURES`. Clients read it via `hasTalkFeature(token, 'threads')`. Reusing it would make a new client believe an old server supports state and tags, so thread management needs its own flag — and FR-30 needs a *second*, separate one, because a client must be able to learn the unread semantics changed independently of whether thread management is present. The neighbours already declared there: `archived-conversations-v2` (:114), `pinned-messages` (:131), `conversation-tags` (:136) — the three names that drove the PRD's vocabulary choices.

---

## 7. Test surface

**Existing:** `tests/integration/features/chat-4/threads.feature` — thirteen scenarios covering creation, replies, non-moderator rename permission, notification levels, title trimming, sort by last activity, the subscribed-threads list with offset paging, attachment replies, geo-location and polls into threads. Step definitions in `tests/integration/features/bootstrap/FeatureContext.php` and `SharingContext.php`. Test DB reset covers `talk_threads` and `talk_thread_attendees` at `tests/integration/spreedcheats/lib/Controller/ApiController.php:102,105`.

This is the file to extend. Existing step columns are `t.id`, `t.token`, `t.title`, `t.numReplies`, `t.lastMessage`, `a.notificationLevel`; state, tags, featuring and unread all need adding to those definitions.

Two existing scenarios are load-bearing and must keep passing:
- **:223** asserts the composed notification object id `room1/Message 2/Thread 1` — the assertion that disproves revision 1's overwrite claim, and the regression guard FR-35 must not break.
- **:238-243** exercises the file-share-into-thread path, which is §3.2 row 6 — the path revision 1 missed. A Locked-thread scenario belongs beside it.

**Missing:** there is no `tests/php/Service/ThreadServiceTest.php` and no `ThreadControllerTest.php`. `ThreadService` is the seam for most of this work and holds the cache logic, so write unit tests for it rather than relying on integration tests that cannot easily observe cache state.

**Cache staleness needs a multi-actor test.** Actor A mutates and reads their own fresh value; the failure only appears when actor B reads through a cache A did not invalidate.

**FR-30 needs a mention-specific test.** The regression is not "the count is wrong" but "a thread mention stops being a mention", and only a test that asserts the mention flag with a zero main-chat count will catch it.

---

## 8. Rejected alternatives

**Deriving unread from the conversation read marker alone.** Considered first and rejected by the requester. No storage cost and no divergence, but one marker per participant per conversation cannot express "read thread A, not thread B" — reading any Thread clears every badge, which is the uselessness the feature exists to fix. Accepted cost of rejecting it: per-participant row growth, the highest-write-rate table in this work, one changed API field, and permanent divergence at the point upstream reversed itself (PRD §6.3).

**Reinstating `read_privacy` with the read-marker columns.** Rejected: it governs read receipts, not unread counts (§4.1). Restoring the dropped columns wholesale would be easier and would take on divergence for nothing.

**A plugin or app-level implementation.** Rejected on the issue itself: *"kiến trúc con talk & spreed này ko làm kiểu plugin first được mà phải chọc vào code."* Talk has no extension point for thread semantics, message-send interception, or list surfaces. Hence PRD §6.1's divergence guardrails.

**Extending `ConversationTagService` for Thread Tags.** Rejected after review found the service exists (§5.1): it is per-user, and Thread Tags are shared within the Conversation. The ownership model is wrong at the root; its name handling, caps and rename/delete surface are still worth copying.

**A moderated per-Conversation tag set.** Rejected by the requester in favour of free text with colour. Accepted cost: near-duplicate tags, mitigated by FR-26's input suggestion rather than validation, watched by AC-W2, with tag administration named as the v2 answer.

**Reusing the existing `threads` capability flag.** Rejected: it ships unconditionally upstream, so a client could not distinguish a server with thread state from one without.

**Migration-time reclassification of old Threads, and bulk close in MVP.** Both offered to the requester as mitigations for the day-one clutter in PRD §9.3; both declined. Recorded because AC-1 and AC-W2 are what decide whether they come back.

**Guarding Locked in each controller only.** Not rejected, but the weaker option — nine paths, one of them a background job with no request context. A `ChatManager`-level guard covers more, at the cost of a narrowly-scoped exemption for state-change system messages (§3.2).

---

## 9. Unrelated upstream defects found while mapping

Neither is in scope. Both are worth reporting upstream, and the second will confuse anyone implementing FR-8's real-time propagation.

1. **`lib/Service/BotService.php:321`** — when a bot answer requests a thread, `createThread()` is called with `(int)$comment->getId()` (the comment that *invoked* the bot) while the `thread_created` system message at :328 uses `(int)$botComment->getId()` (the bot's own reply). The thread row and the system message disagree about which message is the root.

2. **`lib/Signaling/Listener.php:621-635`** — the `threadInfo` payload emitted over signalling sets `'first' => $thread->toArray($room)`, putting a `Thread` where the REST shape puts a `ChatMessage`, and `'last' => null`. The signalling shape does not match `TalkThreadInfo`.

---

## 10. Comparable products — what the four analogues actually do

Researched to settle naming and to check the lifecycle design against products whose users our customers may be arriving from. Sources cited inline.

**A caveat this section earned the hard way.** It surveyed four competitors and did not survey the product being changed. That is how revision 1 arrived at a rule forbidding the word "tag" while Talk already shipped `conversation-tags`, and at "Pinned Thread" while Talk already shipped `pinned-messages`. Competitor research is not a substitute for reading `lib/Capabilities.php`. §5.1 and §6 carry what the local survey found.

### 10.1 Lifecycle: only two of four have one at all

| Product | "Done but reopenable" | Blocks posting? | "No more posting" | Scope |
|---|---|---|---|---|
| Discord | **Close Thread** (= `archived`) | No — typing reopens it | **Lock** (`locked`) | per thread, `MANAGE_THREADS` |
| Zulip | **Resolve** (`✔` name prefix) | **No — deliberately** | none (requested, [#26944](https://github.com/zulip/zulip/issues/26944)) | per topic, group-gated |
| Slack | none | — | **Archive** — channel-scoped, "closed to new activity" | channel |
| Teams | none | — | `replyRestriction: authorAndModerators` | channel |

This PRD's Closed matches Discord's archive exactly, and its Locked matches Discord's lock exactly. Both have a shipped precedent.

**Discord keeps `archived` and `locked` orthogonal**, not a single enum: `thread_metadata` carries both booleans plus `archive_timestamp` and `auto_archive_duration` ([threads](https://docs.discord.com/developers/topics/threads)). This PRD collapses that 2×2 into a three-value line, losing one cell: locked-but-still-in-the-live-list.

Revision 1 claimed that cell was "covered by featuring" — which was **circular**, because revision 1's filter would have hidden a featured Closed or Locked Thread. FR-14 now makes Featured Threads survive the state filter, so the claim is true; it was not before. If FR-14 is ever weakened, the dropped cell comes back as a real gap.

Discord also took a complaint for the *opposite* coupling — [Allow moderators to archive threads without locking them](https://support.discord.com/hc/en-us/community/posts/4409500393367-Allow-moderators-to-archive-threads-without-locking-them-) — because a moderator archiving effectively locked. FR-2 gives moderators a close that does not lock, so this PRD does not inherit that complaint.

⚠️ Discord's help centre contradicts itself on forum posts: one paragraph says "Unless a forum post is locked, the post can be reopened at any time. Even if it's closed," while a Q&A on the same page says Close Post "will cause the post to no longer appear in the new posts section of the list **and also lock the post so that only moderators can re-open it**" ([Forum Channels FAQ](https://support.discord.com/hc/en-us/articles/6208479917079-Forum-Channels-FAQ)). Unresolved. Do not cite Discord as authority for coupling close with lock.

### 10.2 The permission rule, already written by someone else

Discord's documented rule is FR-3 and FR-7 in one sentence:

> "When setting `archived` to `false`, when `locked` is also `false`, only the `SEND_MESSAGES` permission is required. Otherwise, requires the `MANAGE_THREADS` permission." ([channel resource](https://docs.discord.com/developers/resources/channel))

Discord's split of creator versus moderator authority is close to this PRD's Thread Manager. Creator can rename, archive, edit auto-archive duration; only `MANAGE_THREADS` can **unarchive**, delete, or set slowmode ([Threads Moderation FAQ](https://support.discord.com/hc/en-us/articles/4404809613847-Threads-Moderation-FAQ)). This PRD is more permissive: FR-6 and FR-7 give the root-message author the same state authority as a moderator on their own Thread. Deliberate — the issue asks for it — but note the divergence from the closest precedent.

### 10.3 A "done" marker does not need to block posting

Zulip shipped Resolve as advisory on purpose, and published the reasoning: "You can send messages to a resolved topic, which is handy for *'thank you'* messages, or to discuss whether a topic was incorrectly marked as resolved," plus repeat alerts from integrations continuing to post ([Resolve a topic](https://zulip.com/help/resolve-a-topic)). Their earlier proposal [#11154](https://github.com/zulip/zulip/issues/11154) said the marker "would not actually block users from writing more", and [#8041 "Allow admins to lock topics"](https://github.com/zulip/zulip/issues/8041) was closed as not planned.

That is FR-2 and FR-3's design with a shipped product behind it.

Where this PRD goes further: Zulip does **not** hide resolved topics — filtering is all you get, and hiding them is still an open request ([#31029](https://github.com/zulip/zulip/issues/31029), open since July 2024). FR-14 hides Closed Threads by default, which is what the issue asks for and stronger than any of the four.

### 10.4 State belongs in a column — Zulip paid for learning this

Zulip implemented Resolve as a **mutation of the topic name**: prepend `✔`, via `update-message` with `propagate_mode: change_all` ([API](https://zulip.com/api/update-message)). The cost was link breakage — [#21738](https://github.com/zulip/zulip/issues/21738) and [#19651](https://github.com/zulip/zulip/issues/19651) — survivable only because Zulip topic URLs encode a message id, so "Topic links will still work even when the topic is renamed, moved to another channel, or resolved" ([Link to a conversation](https://zulip.com/help/link-to-a-message-or-conversation)).

Talk has real thread rows keyed by root comment id. Put the state in a column (§3.1). Never encode it in `talk_threads.name`.

### 10.5 Tags: this PRD is the outlier

Only Discord has per-thread tags, and they are **predefined per channel with no free-text path** anywhere in API or UI ([channel resource](https://docs.discord.com/developers/resources/channel)):

- `available_tags` — "limited to **20**"; tag `name` "(0-20 characters)"; editing requires `MANAGE_CHANNELS`
- `applied_tags` — "limited to **5**" per thread
- per-tag `moderated` flag — that tag can only be applied or removed with `MANAGE_THREADS`
- channel flag `REQUIRE_TAG` — a tag must be specified when creating a thread

FR-23's bounds of five per Thread and twenty per Conversation come from these caps, and the twenty independently matches `ConversationTagService::MAX_TAG_IDS_PER_CONVERSATION` (§5.1). Two unrelated products landing on the same number is a reasonable basis for keeping it. The `moderated` flag and `REQUIRE_TAG` are natural v2 requests if free text disappoints; both have a precedent to copy.

Zulip's answer is that the topic name *is* the label — no tag concept. Slack and Teams have none.

### 10.6 Naming — including the words this build has already taken

- **"Tag" is Talk's own word.** `conversation-tags` ships (`lib/Capabilities.php:136`). Microsoft Teams uses "tag" for @-mentionable groups of *people* ([Use tags](https://support.microsoft.com/en-us/teams/teams-channels/use-tags-to-mention-groups-in-microsoft-teams)), so a Teams refugee will misread it — but internal consistency wins, and the PRD's glossary states the per-user versus shared difference explicitly.
- **"Pin" is Talk's own word too**, for messages (`pinned-messages`, `lib/Capabilities.php:131`). Hence Featured Threads. This is the collision revision 1 missed while surveying competitors.
- **"Archive" is triply ambiguous** and avoided: Slack users read a permanently read-only channel, Discord users read an auto-hidden self-reviving thread, and Talk itself ships `archived-conversations-v2` (`lib/Capabilities.php:114`).
- **"Closed" is contested but confirmed** by the requester, because the issue names Discord as its reference and Discord's Close Thread is exactly this behaviour. FR-8 carries the mitigation.
- **"Locked" is safe** — the only word all four audiences read as "readable, not postable". Both Discord's shipped feature and Zulip's proposed one use it.
- **"Follow" is universal** across Slack, Teams and Zulip. Talk's per-thread `notification_level` is already this axis under a different name; the interface should say *follow* if that wording is ever revisited. Out of scope here.

### 10.7 Unread models

Zulip's unreads are per conversation (channel + topic) and aggregate upward, visible in the muting rule: "Unread messages in muted topics do not contribute to channel unread counts" ([Mute a topic](https://zulip.com/help/mute-a-topic)). Resolving a topic does not touch unread state. Its per-topic attention ladder — `muted` / `default` / `unmuted` / `followed`, with auto-follow on starting, posting, reacting, or being mentioned ([Follow a topic](https://zulip.com/help/follow-a-topic)) — is the richest of the four and the only one defining precedence against the channel-level setting. Talk's four `NOTIFY_*` levels are the same shape.

Slack orders by unread rather than by activity: "Threads with unread replies will appear at the top of the list" ([Use threads](https://slack.com/help/articles/115000769927-Use-threads-to-organize-discussions-in-Slack)). FR-13 orders featured-first then by activity. Unread-first is a live alternative if AC-3 shows the ordering failing; it is not currently a requirement.

**Zulip aggregates topic unreads into the channel count** — the opposite of FR-30, which splits them. Zulip can do that because it has no separate main-chat concept: every message is in a topic. Talk has both, which is what forces the split.

### 10.8 Premises that did not survive

- **Microsoft Teams has no documented per-conversation lock/unlock.** Searched support.microsoft.com, learn.microsoft.com, Graph, Message Center and the roadmap. What exists is channel-scoped moderation — `replyRestriction` with values `everyone` / `authorAndModerators` ([channelModerationSettings](https://learn.microsoft.com/en-us/graph/api/resources/channelmoderationsettings)) — and **Viva Engage**, a different product, which does have Close conversation (Roadmap 382930, GA April 2024, [MC776193](https://mc.merill.net/message/MC776193)). Do not cite Teams as precedent for per-thread locking.
- **Teams shipped threads in 2025** (Roadmap 488300, GA Aug 2025; from May 2026 the first channel in a new team defaults to threaded — [MC1088172](https://mc.merill.net/message/MC1088172), [MC1283811](https://mc.merill.net/message/MC1283811)). Its thread features are subscription and attention only, with no lock, close or resolve, and Microsoft positions the *older* layout as the one with "more flexible moderation capabilities" ([Threads FAQ PDF](https://adoption.microsoft.com/files/microsoft-teams/Threads-layout-in-Microsoft-Teams-channels_FAQ.pdf)). The largest competitor shipped threads without lifecycle — either an opportunity or a signal, depending on how much weight the issue's evidence carries.
