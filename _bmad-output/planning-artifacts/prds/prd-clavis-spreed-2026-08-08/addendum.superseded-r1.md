# Addendum — Clavis Talk Thread Management

Implementation-level material for the architecture workflow. Companion to `prd.md`; the PRD stays at capability level and points here for mechanism.

All paths are relative to the `clavis-spreed` repository root. Line numbers are against the state of branch `stable34` at commit `6819859` (Talk 24.0.3). They will drift — treat them as pointers to the right function, not as addresses.

---

## 1. What already exists

Threads are not new in upstream Talk. Substantial machinery ships already, and the PRD extends it rather than building alongside it. Knowing exactly what is there changes the shape of the work.

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

**`talk_thread_attendees`**

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT, PK, autoincrement | |
| `room_id`, `thread_id`, `attendee_id` | BIGINT | |
| `actor_type`, `actor_id` | STRING(255) | |
| `notification_level` | INTEGER, nullable, default `Participant::NOTIFY_DEFAULT` | The only per-participant thread setting that exists |

Unique index `tta_throom_attendee` on `(thread_id, room_id, actor_type, actor_id)`, set by `Version22001Date20250927174738.php` replacing an earlier narrower one. Index `tta_room_attendee` on `(room_id, actor_type, actor_id)`.

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

`prepareListOfThreads()` at :269 assembles the `TalkThreadInfo` shape returned by all list endpoints.

### 1.4 Message ↔ thread linkage

There is **no new column on the comments table**. Linkage is threefold, and all three matter:

1. **`oc_comments.topmost_parent_id`** — the core column, maintained by the platform's comments backend, is the canonical thread id. The idiom throughout is `(int)$comment->getTopmostParentId() ?: (int)$comment->getId()`, so a root message is its own thread. Talk enrols a message in a thread without quoting by calling `$comment->setParentId((string)$threadId)` — `lib/Chat/ChatManager.php:179` and `:418`.
2. **`oc_comments.meta_data` JSON** — `Message::METADATA_THREAD_ID = 'thread_id'` and `METADATA_THREAD_TITLE = 'thread_title'` (`lib/Model/Message.php:27-28`), surfaced to the API as `threadId` / `threadTitle`. This is what federation and ActivityPub read.
3. **`talk_threads.id == root comment id`**, which is why the id column is deliberately not autoincrement.

The actual query-level thread filter is `lib/Chat/CommentsManager.php:129-133` — `WHERE (id = :topmostParentId OR topmost_parent_id = :topmostParentId)`, marked `FIXME: TEMPORARY method until nextcloud/server#53896 is merged`. Anything that filters or counts messages within a thread goes through this.

`Message::toArray(string $format, ?Thread $thread)` at `lib/Model/Message.php:187` always emits `threadId`, and emits `isThread`, `threadTitle`, `threadReplies` **only when a `Thread` entity is passed in**. The parameter has no default, so every call site passes it explicitly — useful, because a new field on the message representation cannot be silently forgotten at one call site.

### 1.5 Front end

- `src/components/RightSidebar/Threads/` — `ThreadsTab.vue` (the list), `ThreadItem.vue` (a row), `ThreadHeader.vue` (open-thread header), `threadsConstants.ts`.
- `src/stores/chatExtras.ts` — `useChatExtrasStore` holds `threads` keyed token → threadId → `ThreadInfo`, `followedThreads` as a `Set`, plus `addThread`, `fetchSingleThread`, `fetchRecentThreadsList`, `fetchFollowedThreadsList`, `setThreadNotificationLevel`, `updateThread`, `renameThread`, `clearThreads`, `removeMessageFromThread`.
- `src/stores/chat.ts` — `threadBlocks` (token → threadId → message-id sets) and `checkIfBelongsToContext()` at :55-61, which is the client-side thread filter.
- `src/services/messagesService.ts:330-390` — the five thread API functions.
- `src/composables/useGetThreadId.ts` — the whole routing mechanism, 21 lines: a writable ref over the `threadId` query parameter. Assigning to it navigates. Used in thirteen files.
- `src/types/openapi/openapi.ts:3293-3336` — generated `Thread`, `ThreadAttendee`, `ThreadInfo` schemas.

**Routing scheme today:** `/call/{token}?threadId={id}#message_{messageId}`. There is no thread route — `src/router/router.ts` has only `root`, `notfound`, `forbidden`, `duplicatesession`, `conversation`, `recording`. FR-39's linkability requirement is mostly already satisfied by this scheme; the Directory needs an address added to it.

### 1.6 Where the thread list lives now

This is the concrete shape of the PRD's item 4. The threads list is **not a registered sidebar tab**. `src/components/RightSidebar/RightSidebar.vue:47-55` renders it in a `v-else-if` on `contentState === 'threads'`, *instead of* the whole normal tab set. It is opened only from `SharedItemsTab.vue:138`, which emits `showThreadsTab` from a "Show more threads" button under a three-item preview, wired at `RightSidebar.vue:131` to `handleUpdateState('threads')` at `:550-562`.

So the current path is: open right sidebar → Shared items tab → scroll to Recent threads → Show more threads. Three levels deep behind a tab that is about files. FR-11 removes this path; FR-36 through FR-38 replace it.

A second, global list already exists in the left sidebar — `LeftSidebar.vue:313-331` over `chatExtrasStore.followedThreadsList`, with a "Threads" nav button at `:254-260`. That is the Followed Thread List in the PRD's glossary, and FR-19 and FR-28 extend its rows rather than replacing it.

---

## 2. The notification defect (PRD §4.8, FR-32, FR-33)

The PRD asserts that item 8 of the issue is a server-side defect rather than a missing client feature. The evidence:

`lib/Notification/Notifier.php:604-610` sets the link, **outside** any push guard:

```php
$urlParams = [
    'token' => $room->getToken(),
    '_fragment' => 'message_' . $message->getMessageId(),
];
if (isset($messageParameters['threadId'])) {
    $urlParams['threadId'] = $messageParameters['threadId'];
}
$notification->setLink($this->url->linkToRouteAbsolute('spreed.Page.showCall', $urlParams));
```

`:633-642` sets the object, **inside** a push guard:

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

Two distinct problems:

1. **Push payloads never carry the thread.** The object-id mechanism is the clients' routing hint, and it is skipped for push. Mobile clients therefore cannot route into a thread no matter what they implement. FR-32.
2. **The message id is destroyed.** The second `setObject` overwrites the first, replacing `{token}/{messageId}` with `{token}/{threadId}`. Where the thread *is* known, the specific message is not. The comment on the line above — "Forward the message ID as well to the clients, so they can quote the message on replies" — states the intent that the next four lines break. FR-33.

FR-33's backward-compatibility consequence is load-bearing here: shipped clients parse this object id, and the shape cannot simply change. Composing both ids into one string, adding a separate field, or moving the thread hint elsewhere are all open to the architecture workflow — the PRD only requires both survive and existing readers keep working.

**Push subject construction** is at `:686-689`:

```php
} elseif ($this->notificationManager->isPreparingPushNotification()) {
    $shortenMessage = Util::shortenMultibyteString($parsedMessage, 100);
```

A hundred characters for the message preview, then subject strings like `'{user} in {call}' . "\n{message}"`. FR-31 adds a thread line; FR-35 governs what yields when it does not fit. The hard limit is the platform notifications app's encryption budget, not this constant — see §6.

**Where `threadId` enters the notification in the first place:** `lib/Chat/Notifier.php:628-638`, `createNotification()`, which puts it in the message parameters when non-null and non-zero. That part works. The thread-subscription logic that decides *who* gets notified is `notifyOtherParticipant()` at `:253-293` and is unaffected by this PRD.

---

## 3. Thread state (PRD §4.1)

### 3.1 Storage

`talk_threads` has no state column. A new one is needed, plus:

- `lib/Model/Thread.php` — property, `addType` in the constructor, `createFromRow`, `fromJson`, `toJson` (the distributed-cache serialisation — miss it and state vanishes on a cache round trip), and `toArray()`.
- `lib/Model/SelectHelper.php:53` — `selectThreadsTable()`, both the aliased and unaliased branches.
- `lib/ResponseDefinitions.php:724-737` — `TalkThread` psalm type.
- Regenerate `openapi.json` / `openapi-full.json` / `openapi-federation.json` and the `src/types/openapi/*.ts` they produce.

An integer column with named constants matches how `notification_level` is already handled and keeps room for a fourth state without another migration.

### 3.2 The six write paths Locked must block (FR-5)

Content reaches a thread through all of these. The PRD enumerates them as separate testable consequences precisely so a missed one fails a test.

| Path | Entry point | Notes |
|---|---|---|
| Direct message | `lib/Controller/ChatController.php:376` `sendMessage()` | The obvious one |
| Bot message | `lib/Controller/BotController.php:169` `sendMessage()` | Separate controller, separate guard |
| File share / rich object | `lib/Controller/ChatController.php:745` `shareObjectToChat()` | Currently *silently* resets an invalid thread to 0 at `:788-794` — a Locked thread must error, not silently post to the main chat |
| Poll | `lib/Controller/PollController.php:89` `createPoll()` | Same silent-reset pattern at `:127-133` |
| Scheduled message (accept) | `lib/Controller/ChatController.php:527` `scheduleMessage()` | Validates at request time |
| Scheduled message (fire) | `lib/BackgroundJob/SendScheduledMessages.php:145-179` | **No live request.** A message scheduled before locking fires later; must fail with a visible reason |

A guard placed in `ChatManager::sendMessage()` (`lib/Chat/ChatManager.php:383`) and `addSystemMessage()` (`:147`) would cover more of these at once than guarding each controller, but system messages must stay permitted — state-change messages are themselves system messages written to a Locked thread. Whichever seam is chosen, the enumeration above is the test matrix.

Editing, deleting and reacting (FR-5's assumption-tagged consequences) live on other paths again and need locating if those assumptions survive review.

### 3.3 Reopen-by-reply (FR-3)

Every reply already passes through `ThreadService::updateLastMessageInfoAfterReply()` (`:256`), which bumps `num_replies`, `last_message_id` and `last_activity`, and invalidates the cache entry. It returns a bool used elsewhere as an "is this a real thread" test. Extending it to also clear a Closed state puts the transition on the one path every reply takes, and reuses an invalidation that is already correct.

### 3.4 System messages

`thread_created` and `thread_renamed` are the pattern. Emitted via `ChatManager::addSystemMessage(..., threadId: $threadId)`; parsed in `lib/Chat/Parser/SystemMessage.php:587-606`; relayed through signalling because they appear in `SYSTEM_MESSAGE_TYPE_RELAY` at `lib/Signaling/Listener.php:87-88`. New state-change messages need a constant in `src/constants.ts:279-280`, a parser branch, classification in `src/utils/message.ts:50-51`, and relay-list membership — otherwise other participants will not see the change without a reload, which breaks the PRD's real-time NFR.

---

## 4. Unread counts (PRD §4.7)

### 4.1 Reinstating what upstream dropped

`Version22000Date20250623142327.php:87-103` created `last_read_message`, `last_mention_message`, `last_mention_direct` and `read_privacy` on `talk_thread_attendees`. `Version22000Date20250710124258.php:49-60` dropped all four, three weeks later, deliberately. The PRD reinstates the read-marker columns because deriving unread from the conversation marker cannot deliver per-thread independence, which FR-27 requires. Read the two migrations before writing a third — the column definitions to restore are already written there.

Note what is *not* being reinstated. `read_privacy` governs read receipts, not unread counts, and nothing in this PRD needs it. `last_mention_message` is needed, because FR-28 and FR-30 require distinguishing an unread mention from an unread reply. `last_mention_direct` is only needed if the three-way split in §4.4 is kept end to end.

This is the fork's largest deliberate divergence (PRD §6.1). Upstream may change this table again; whoever writes the migration should leave a comment saying why the columns are back, so the next person merging an upstream release does not read them as an accident.

### 4.2 What the marker is layered over

`talk_attendees.last_read_message` exists and is live — added by `Version7000Date20190724121136.php:41`, carried through `Version10000Date20201015134000.php:99`. One value per participant per conversation. The dropped columns were on the *other* table; this one was never touched.

It matters because of the fallback in PRD §4.7: a thread with no `talk_thread_attendees` row for this participant reads its unread state from this conversation-level value. That is what stops a member joining a room with five hundred old threads from seeing five hundred badges — on join, the conversation marker is already at the latest message, so every pre-existing thread computes to zero.

`ChatManager::getUnreadCount(Room $chat, int $lastReadMessage): int` at `:993` is the existing conversation-scoped count. A thread-scoped variant is the same query plus the thread filter from `CommentsManager.php:129-133`.

### 4.3 Where rows get created, and where they must not

Rows in `talk_thread_attendees` already exist for a different purpose: `ThreadService::ensureIsThreadAttendee()` (`:226`) inserts one with `NOTIFY_DEFAULT` on any reply, and `setNotificationLevel()` (`:206`) upserts one. So the table is already keyed the right way and already has an insert-if-missing path — the read marker rides on rows that in many cases exist.

The new write is on read, not on reply. PRD §4.7 requires materialising a row when a participant reads a thread that has none. Two consequences worth flagging early:

- **Subscription and read state now share a row.** A row's existence currently means "this participant is a thread attendee", which drives `findAttendeesForNotification()` (`:99`, filtered on `notification_level != NOTIFY_DEFAULT`) and `getRecentByActor()` (`:143`, filtered on `notification_level != NOTIFY_NEVER`, which is the Followed Thread List). Creating rows on read means rows appear for participants who only read. Because both queries filter on `notification_level`, a row created on read with `NOTIFY_DEFAULT` does not leak into either — but that is load-bearing behaviour that is currently incidental, and a future change to those filters would silently turn every read thread into a followed one. Worth a test that asserts it.
- **Row reclamation already exists.** `ThreadListener` handles `AttendeesRemovedEvent` → `removeThreadAttendeesByAttendeeIds()` (`:246`), so leaving a conversation drops the rows. That satisfies FR-27's last consequence (no stale read state on rejoin) and the NFR on bounded growth, for free.

### 4.4 The unspecified case (PRD open question 1)

FR-27 says reading the main chat must not clear thread unread. But a thread with no row falls back to the conversation marker, and reading the main chat advances that marker — so for never-opened threads, it would clear them. The two requirements meet here and the PRD leaves it open deliberately.

The options, for whoever resolves it:

1. **Accept it.** Simplest, no extra writes. A participant who reads only the main chat loses badges on threads they never opened. Arguably defensible — they have read the conversation — but it is the shared-marker defect surviving in a corner.
2. **Materialise on marker advance.** When the conversation marker moves, write rows for threads that currently have unread, freezing them at their pre-advance value. Correct, bounded by threads-with-unread at that instant, but adds a write burst to a very common operation.
3. **Store a separate thread fallback baseline** on `talk_attendees`, advanced only by an explicit "mark all threads read". Cheapest correct option; one extra column, no per-thread writes, but it adds a third notion of "read" to a document that already worries about two.

Option 3 is the one to argue for first.

### 4.5 The performance trap

The naive implementation issues one count query per row in the Directory. Under the scale the feature exists for, that is the Directory being slow at the moment it matters. Options for the architecture workflow: a single grouped query across the page's thread ids; deriving from `talk_threads.last_message_id` for the has-unread boolean and counting only where the badge will show; or a bounded count that stops at the display cap since FR-28 caps the rendered number anyway. SM-C3 exists to catch it if none is done.

### 4.6 The dead columns

`talk_attendees.has_unread_threads`, `has_unread_thread_mentions`, `has_unread_thread_directs` were created by `Version22000Date20250623142327.php:110-128`, are declared on `lib/Model/Attendee.php:150-152`, hydrated at `lib/Model/AttendeeMapper.php:315-317`, and selected at `lib/Model/SelectHelper.php:112-114` — and have **zero callers**. Grep finds no `setHasUnreadThreads*` or `getHasUnreadThreads*` anywhere in `lib/` or `src/`.

They are exactly what FR-30 needs, and they need no migration. Writing the callers is the whole of FR-30's storage work. Note the three-way split — threads, thread mentions, thread directs — matches FR-30's requirement to distinguish unread replies from unread mentions, and FR-30's requirement that a fully-read main chat still report a thread-unread indication is precisely what a separate boolean on the participant row expresses.

---

## 5. Labels, pins, and the Directory

### 5.1 Labels (FR-22 … FR-25)

Nothing comparable exists; there is no conversation-level label or tag mechanism in Talk to extend. Two relationships are needed: label text → colour, scoped per conversation (FR-23); and thread → label (FR-22).

The conversation-scoped colour binding in FR-23 is what forces label identity to be a first-class row rather than a string on the thread. Case-insensitive identity (FR-23, assumption 11) means the comparison key needs deciding — a normalised column or a collation choice, not an application-level `strtolower` that a second code path will forget.

### 5.2 Pins (FR-20, FR-21)

A boolean or a nullable ordering value on `talk_threads`, plus the pinned-first sort in `ThreadMapper::getRecentByRoomId()` (`:98`) and in `ThreadService::getRecentByActor()` (`:143`, the followed-list query, which is raw QueryBuilder). The bound in FR-21 is a server-side constant; note it must be enforced at write time, since a sort cannot enforce a count.

### 5.3 Directory pagination and filtering (FR-13, FR-14, FR-16)

`ThreadService::getRecentByRoomId()` clamps its limit to 1–50 and takes no offset. `getRecentByActor()` takes limit and offset. Neither takes a state or label filter. FR-16's requirement that filtering and search apply across all threads rather than only loaded ones means these go in the query, not the client store — which in turn means the existing `getRecentActiveThreads` endpoint gains parameters or a new endpoint appears. Additive-only discipline from PRD §7 favours parameters with defaults that preserve current behaviour.

`getRecentByActor()` carries `// FIXME ORDER BY last_activity and subscription moment of the user for better sorting?` at `:157` — an upstream open question adjacent to FR-12's ordering, worth reading before choosing a sort.

### 5.4 Search (FR-17 … FR-19)

Title search is a new query over `talk_threads.name`. The diacritic-insensitivity in FR-17 (assumption 8) is the substantive constraint — it depends on the database's collation, and the deployment targets are not all one engine. This needs settling early: a normalised search column written at create and rename time is portable, while relying on collation is not.

Message search already exists and is already thread-aware: `lib/Search/MessageSearch.php:292-316` adds `threadId` to the deep link and to the result attributes, and `src/components/RightSidebar/SearchMessages/SearchMessagesTab.vue:223-235` already reads it. FR-18's handoff has a working target.

### 5.5 Cache invalidation (PRD §5, risk 1)

`ThreadService` caches through `ICacheFactory` under `talk.threads`, prefix `thread/`, key `thread/{roomId}/{threadId}`, TTL 900s, **with negative caching** — a miss stores `''` and subsequent reads throw `DoesNotExistException` from the cached empty value. `createThread`, `renameThread` and `updateLastMessageInfoAfterReply` all refresh or invalidate. Every new mutation must do the same, and the negative caching means a bug here can also make a *newly visible* thread invisible, not only a stale one stale.

---

## 6. Dependencies and limits

**Push payload size.** The platform notifications app encrypts each payload for the target device's public key. RSA encryption of that kind bounds the plaintext to a couple of hundred bytes, which is why `Notifier.php:686-689` truncates the preview to 100 characters. FR-35's budget question (open question 8) needs measuring against the notifications app's actual limit, not inferred from this constant.

**Clavis push proxy** (`clavis-push`, Go). Cannot participate: `internal/proxy/handler.go:19-26` documents that `Subject` and `Signature` are ciphertext the proxy cannot read. Its `README.md` also records that the proxy → APNs/FCM hop is unimplemented, blocked on an Apple `.p8` key and a Firebase project, with `NotImplementedSender` failing loudly rather than pretending. So FR-32 and FR-35 are verifiable at payload construction but not on a device.

**Federation.** `lib/Controller/ChatController.php:898` and `:1273` carry `// FIXME support threads in federation`. The proxy layer for the four existing thread endpoints is `lib/Federation/Proxy/TalkV1/Controller/ThreadController.php`, and `UserConverter::convertThreadInfo()` at `:170` rewrites the room token and message params. New thread fields would need handling there if federation were in scope. It is not.

**Repository state.** Shallow clone, depth 1 (`.git/shallow`; `git rev-list --count HEAD` returns 1). `git fetch --unshallow` is a prerequisite — without history, rebasing onto upstream releases is not possible, and PRD §6.1 depends on it.

**Capability flag.** `lib/Capabilities.php:130` declares `threads` unconditionally in `FEATURES`, not in `CONDITIONAL_FEATURES` or `LOCAL_FEATURES`. The client reads it via `hasTalkFeature(token, 'threads')`. PRD §7's capability gating needs a new flag for the new behaviour; reusing `threads` would make a new client believe an old server supports state and labels.

---

## 7. Test surface

**Existing:** `tests/integration/features/chat-4/threads.feature` — thirteen scenarios covering creation, replies, non-moderator rename permission, notification levels, title trimming, sort by last activity, the subscribed-threads list with offset paging, attachment replies, geo-location and polls into threads. Step definitions in `tests/integration/features/bootstrap/FeatureContext.php` and `SharingContext.php`. Test DB reset covers `talk_threads` and `talk_thread_attendees` at `tests/integration/spreedcheats/lib/Controller/ApiController.php:102,105`.

This is the file to extend. The table columns the steps already use are `t.id`, `t.token`, `t.title`, `t.numReplies`, `t.lastMessage`, `a.notificationLevel` — new columns for state, labels, pin and unread will need adding to those step definitions.

**Missing:** there is no `tests/php/Service/ThreadServiceTest.php` and no `ThreadControllerTest.php`. Given that `ThreadService` is the seam for most of this work and holds the cache logic, unit coverage for it is worth creating rather than relying on integration tests that cannot easily observe cache state.

**Cache staleness needs a multi-actor test.** PRD risk 1 is invisible to single-actor tests: actor A mutates, actor A reads their own fresh value. The failure only appears when actor B reads through a cache A did not invalidate.

---

## 8. Rejected alternatives

**Deriving unread from the conversation read marker alone, with no per-thread state.** Considered first and rejected by the requester. It costs no storage and no divergence, but one marker per participant per conversation cannot express "read thread A, not thread B" — reading any thread clears every badge, which is the uselessness the feature exists to fix. Accepted cost of the chosen alternative: per-participant row growth, the highest-write-rate table in this work, and permanent divergence at exactly the point upstream reversed itself (PRD §6.3).

**Reinstating `read_privacy` alongside the read-marker columns.** Rejected: it governs read receipts, not unread counts, and nothing in this PRD needs it. Restoring the dropped columns wholesale would be easier and would take on divergence for no benefit.

**A plugin or app-level implementation.** Rejected on the issue itself: *"kiến trúc con talk & spreed này ko làm kiểu plugin first được mà phải chọc vào code"* — Talk's architecture has no extension point for thread semantics, message-send interception or list surfaces. The fork is modified directly, which is why PRD §6.1's divergence guardrails exist.

**A moderated per-conversation label set.** Rejected by the requester in favour of free text with colour. Accepted cost: near-duplicate labels, mitigated by FR-25's input suggestion rather than validation, watched by SM-C2, with label administration named as the v2 answer.

**Reusing the existing `threads` capability flag.** Rejected: it ships unconditionally in upstream Talk, so a client could not distinguish a server with thread state from one without.

**Guarding Locked in each controller only.** Not rejected, but noted as the weaker option — six controllers plus a background job is six chances to miss one, and the background job has no request context. A guard at the `ChatManager` seam covers more paths, at the cost of having to exempt system messages.

---

## 9. Unrelated upstream defects found while mapping

Neither is in scope. Both are worth reporting upstream, and the second will confuse anyone implementing FR-7's real-time propagation.

1. **`lib/Service/BotService.php:321`** — when a bot answer requests a thread, `createThread()` is called with `(int)$comment->getId()` (the comment that *invoked* the bot) while the `thread_created` system message at `:328` uses `(int)$botComment->getId()` (the bot's own reply). The thread row and the system message disagree about which message is the root.

2. **`lib/Signaling/Listener.php:621-635`** — the `threadInfo` payload emitted over signalling sets `'first' => $thread->toArray($room)`, putting a `Thread` where the REST shape puts a `ChatMessage`, and `'last' => null`. The signalling shape does not match `TalkThreadInfo`. Anyone extending signalling for FR-7 will hit this.

---

## 10. Comparable products — what the four analogues actually do

Researched to settle naming and to check the lifecycle design against products whose users our customers may be arriving from. Sources cited inline. Two premises went in and came out changed, which is the point of doing it.

### 10.1 Lifecycle: only two of four have one at all

| Product | "Done but reopenable" | Blocks posting? | "No more posting" | Scope |
|---|---|---|---|---|
| Discord | **Close Thread** (= `archived`) | No — typing reopens it | **Lock** (`locked`) | per thread, `MANAGE_THREADS` |
| Zulip | **Resolve** (`✔` name prefix) | **No — deliberately** | none (requested, [#26944](https://github.com/zulip/zulip/issues/26944)) | per topic, group-gated |
| Slack | none | — | **Archive** — but channel-scoped, "closed to new activity" | channel |
| Teams | none | — | `replyRestriction: authorAndModerators` | channel |

So this PRD's Closed matches Discord's archive exactly, and its Locked matches Discord's lock exactly. Both behaviours have a shipped precedent; neither is invented.

**Discord keeps `archived` and `locked` orthogonal**, not a single enum: `thread_metadata` carries both booleans plus `archive_timestamp` and `auto_archive_duration`, and a thread can be locked-and-active or locked-and-archived ([threads](https://docs.discord.com/developers/topics/threads)). This PRD collapses that 2×2 into a three-value line, which loses one cell: locked-but-still-in-the-live-list. That case is covered by pinning a Locked Thread, since FR-20 makes pinning independent of state. Worth knowing the cell was dropped on purpose rather than missed.

Discord also took a complaint for the *opposite* coupling — [Allow moderators to archive threads without locking them](https://support.discord.com/hc/en-us/community/posts/4409500393367-Allow-moderators-to-archive-threads-without-locking-them-) — because a moderator archiving effectively locked. FR-2 gives moderators a close that does not lock, so this PRD does not inherit that complaint.

⚠️ Discord's own help centre contradicts itself on forum posts: one paragraph says "Unless a forum post is locked, the post can be reopened at any time. Even if it's closed," while a Q&A on the same page says Close Post "will cause the post to no longer appear in the new posts section of the list **and also lock the post so that only moderators can re-open it**" ([Forum Channels FAQ](https://support.discord.com/hc/en-us/articles/6208479917079-Forum-Channels-FAQ)). Unresolved. Do not cite Discord as authority for coupling close with lock.

### 10.2 The permission rule, already written by someone else

Discord's documented rule is FR-3 and FR-6 in one sentence:

> "When setting `archived` to `false`, when `locked` is also `false`, only the `SEND_MESSAGES` permission is required. Otherwise, requires the `MANAGE_THREADS` permission." ([channel resource](https://docs.discord.com/developers/resources/channel))

Discord's split of thread-creator versus moderator authority is also close to this PRD's Thread Manager. Creator can rename, archive, edit auto-archive duration; only `MANAGE_THREADS` can **unarchive**, delete, or set slowmode ([Threads Moderation FAQ](https://support.discord.com/hc/en-us/articles/4404809613847-Threads-Moderation-FAQ)). This PRD is more permissive: FR-2 and FR-6 give the Thread Root Message author the same state authority as a moderator on their own Thread. Deliberate — the issue asks for exactly that — but note the divergence from the closest precedent.

### 10.3 A "done" marker does not need to block posting

Zulip shipped Resolve as advisory on purpose, and published the reasoning: "You can send messages to a resolved topic, which is handy for *'thank you'* messages, or to discuss whether a topic was incorrectly marked as resolved," plus repeat alerts from integrations continuing to post to the original topic ([Resolve a topic](https://zulip.com/help/resolve-a-topic)). Their earlier proposal [#11154](https://github.com/zulip/zulip/issues/11154) said the marker "would not actually block users from writing more in that topic," and [#8041 "Allow admins to lock topics"](https://github.com/zulip/zulip/issues/8041) was closed as not planned.

That is FR-2 and FR-3's design, arrived at independently, with a shipped product behind it.

Where this PRD goes further: Zulip does **not** hide resolved topics — filtering is all you get, and hiding them is still an open request ([#31029](https://github.com/zulip/zulip/issues/31029), open since July 2024). FR-13 hides Closed Threads from the default filter, which is what the issue asks for and stronger than any of the four.

### 10.4 State belongs in a column — Zulip paid for learning this

Zulip implemented Resolve as a **mutation of the topic name**: prepend `✔`, done via `update-message` with `propagate_mode: change_all` ([API](https://zulip.com/api/update-message)). The cost was link breakage — [#21738](https://github.com/zulip/zulip/issues/21738) and [#19651](https://github.com/zulip/zulip/issues/19651) — fixable only because Zulip topic URLs encode a message id, so "Topic links will still work even when the topic is renamed, moved to another channel, or resolved" ([Link to a conversation](https://zulip.com/help/link-to-a-message-or-conversation)).

Talk has real thread rows keyed by root comment id. Put the state in a column (addendum §3.1). Never encode it in `talk_threads.name`.

### 10.5 Labels: this PRD is the outlier

Only Discord has per-thread labels, and they are **predefined per channel with no free-text path** anywhere in API or UI ([channel resource](https://docs.discord.com/developers/resources/channel)):

- `available_tags` — "limited to **20**"; tag `name` "(0-20 characters)"; editing requires `MANAGE_CHANNELS`
- `applied_tags` — "limited to **5**" per thread
- per-tag `moderated` flag — that tag can only be applied or removed with `MANAGE_THREADS`
- channel flag `REQUIRE_TAG` — a tag must be specified when creating a thread

FR-22's bounds of five per Thread and twenty per Conversation come straight from these caps. The `moderated` flag and `REQUIRE_TAG` are natural v2 requests if free text disappoints; both have a precedent to copy rather than design.

Zulip's answer is that the topic name *is* the label — no tag concept exists. Slack and Teams have none.

### 10.6 Naming — the two words to avoid

- **"Tag" is unusable in the interface.** Microsoft Teams tags are @-mentionable groups of *people*: "Tags in Microsoft Teams allow you to @mention a group of people at once" ([Use tags](https://support.microsoft.com/en-us/teams/teams-channels/use-tags-to-mention-groups-in-microsoft-teams)). A Teams user meeting a "tag" on a thread will read it as a mention group. Use **label** everywhere. The issue says "tag / label"; only the second word survives.
- **"Archive" is ambiguous and should be avoided.** Slack users read it as a permanently read-only container at channel scope; Discord users read it as an auto-hidden, self-reviving thread. Neither is what this PRD means.
- **"Closed" is confirmed by the requester** despite the collision, because the issue names Discord as its reference and Discord's "Close Thread" is exactly this behaviour. FR-7 carries the mitigation: the indicator must say that posting reopens the Thread.
- **"Locked" is safe** — the only word all four audiences read as "readable, not postable". Both Discord's shipped feature and Zulip's proposed one use it.
- **"Follow" is universal** across Slack, Teams and Zulip. Talk's existing per-thread `notification_level` is already this axis under a different name; the interface should say *follow*, not *notification level*, if that wording is ever revisited. Out of scope here, worth noting.

### 10.7 Unread models

Zulip's unreads are per conversation (channel + topic) and aggregate upward, which is visible in the muting rule: "Unread messages in muted topics do not contribute to channel unread counts" ([Mute a topic](https://zulip.com/help/mute-a-topic)). Resolving a topic does not touch unread state at all. Its per-topic attention ladder is the richest of the four — `muted` / `default` / `unmuted` / `followed`, with auto-follow on starting, posting, reacting, or being mentioned ([Follow a topic](https://zulip.com/help/follow-a-topic)) — and it is the only model that defines precedence against the channel-level setting. Talk's four `NOTIFY_*` levels are the same shape.

Slack orders by unread rather than by activity: "Threads with unread replies will appear at the top of the list" ([Use threads](https://slack.com/help/articles/115000769927-Use-threads-to-organize-discussions-in-Slack)). FR-12 orders pinned-first then by activity. Unread-first is a live alternative if SM-5 shows the ordering failing; it is not currently a requirement.

### 10.8 Premises that did not survive

- **Microsoft Teams has no documented per-conversation lock/unlock.** Searched support.microsoft.com, learn.microsoft.com, Graph, Message Center and the roadmap. What exists is channel-scoped moderation — `replyRestriction` with values `everyone` / `authorAndModerators` ([channelModerationSettings](https://learn.microsoft.com/en-us/graph/api/resources/channelmoderationsettings)) — and **Viva Engage**, a different product, which does have Close conversation (Roadmap 382930, GA April 2024, [MC776193](https://mc.merill.net/message/MC776193)). Do not cite Teams as precedent for per-thread locking.
- **Teams shipped threads in 2025** (Roadmap 488300, GA Aug 2025; from May 2026 the first channel in a new team defaults to threaded — [MC1088172](https://mc.merill.net/message/MC1088172), [MC1283811](https://mc.merill.net/message/MC1283811)). Its thread features are subscription and attention only, with no lock, close or resolve, and Microsoft positions the *older* layout as the one with "more flexible moderation capabilities" ([Threads FAQ PDF](https://adoption.microsoft.com/files/microsoft-teams/Threads-layout-in-Microsoft-Teams-channels_FAQ.pdf)). So the largest competitor shipped threads without lifecycle — which is either an opportunity or a signal, depending on how much weight the issue's evidence carries.
