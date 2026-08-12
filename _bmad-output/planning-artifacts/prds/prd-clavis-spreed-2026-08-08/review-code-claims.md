# Code-Claim Review — `addendum.md` and `prd.md` §§4.8, 5, 6, 7, 11

Fact-check of every code-grounded claim against the working tree of `clavis-spreed` at
branch `stable34`, commit `6819859` (the only commit in the shallow clone).

Scope: **truth of claims about the code only.** Writing, structure and product decisions
are out of scope and are not commented on.

Verdict key — CONFIRMED / WRONG / DRIFTED (right fact, wrong line or detail) / UNVERIFIABLE.

Line numbers below are the *actual* ones. Where the document's pointer differs, both are given.

---

## 1. WRONG

### W1 — The second `setObject()` does **not** overwrite the message id. It appends.

**Claimed** (addendum §2, problem 2; restated in PRD §4.8 and PRD §7):

> "**The message id is destroyed.** The second `setObject` overwrites the first, replacing
> `{token}/{messageId}` with `{token}/{threadId}`. Where the thread *is* known, the specific
> message is not."

> PRD §4.8: "the same code overwrites the message identifier with the thread identifier, so
> even where the thread is known the specific message is lost."

> PRD §7: "FR-33 exists because the current code violates this by overwriting one identifier
> with another."

**Actual** — `lib/Notification/Notifier.php:633-642`:

```php
$notification->setObject($notification->getObjectType(), $notification->getObjectId() . '/' . $message->getMessageId());
if (isset($messageParameters['threadId'])) {
    $notification->setObject($notification->getObjectType(), $notification->getObjectId() . '/' . $messageParameters['threadId']);
}
```

The second call re-reads `$notification->getObjectId()`, which by then already holds the
value the *first* call wrote. `INotification::setObject()` is a plain setter
(`vendor/nextcloud/ocp/OCP/Notification/INotification.php:68`; its docblock describes no
merge, append or reset semantics) and `getObjectId()` is a plain accessor at `:80`.
So the composition is:

| step | object id |
|---|---|
| `lib/Chat/Notifier.php:643` `setObject('chat', $chat->getToken())` | `{token}` |
| `Notifier.php:638` | `{token}/{messageId}` |
| `Notifier.php:640` | `{token}/{messageId}/{threadId}` |

Both identifiers survive. Three independent pieces of in-repo evidence:

1. **The repository's own integration test asserts the three-part shape.**
   `tests/integration/features/chat-4/threads.feature:222-223`:

   ```
   | app    | object_type | object_id                | subject ...
   | spreed | chat        | room1/Message 2/Thread 1 | participant2-displayname replied to your message in conversation room1 |
   ```

   `room1` is the token, `Message 2` the message, `Thread 1` the thread. This is the only
   three-segment `object_id` in the whole integration suite, and it is a thread reply.

2. **The shipped Android client already parses both segments by position.**
   `clavis-talk-android/app/src/main/java/com/nextcloud/talk/jobs/NotificationWorker.kt`:

   ```kotlin
   private fun parseMessageId(objectId: String): Int {           // :1016
       val objectIdParts = objectId.split("/".toRegex()).toTypedArray()
       return if (objectIdParts.size < 2) { throw NumberFormatException(...) }
              else { objectIdParts[1].toInt() }                   // index 1 = messageId
   }
   private fun parseThreadId(objectId: String?): Long? =          // :1025
       objectId?.split("/")?.getOrNull(2)?.toLongOrNull()          // index 2 = threadId
   ```

   A client that reads index 1 for the message and index 2 for the thread is reading
   `{token}/{messageId}/{threadId}`. It could not work at all if the thread id had replaced
   the message id.

3. The 64-character limit `setObject` enforces is nowhere near reached by
   `{8-char token}/{id}/{id}`, so there is no truncation path that would produce an overwrite.

**Consequence for the documents.** FR-33's stated defect does not exist. Both identifiers are
already present and independently readable in the in-app notification, and the shipped clients
already read them. The real gap FR-33 could be about — that neither identifier reaches a *push*
payload — is W2's subject and is already covered by FR-32.

### W2 — Push object ids carry neither identifier, not just "not the thread"

**Claimed** (addendum §2, problem 1): "Push payloads never carry the thread."

**Actual**: true, but understated in a way that matters. Because the guard at
`Notifier.php:633` wraps *both* `setObject` calls, a push payload's object id is left at
whatever `lib/Chat/Notifier.php:643` set — the bare room token. Push payloads carry
**neither the message id nor the thread id**.

Confirmed downstream: `clavis-talk-android/.../NotificationWorker.kt:875-879` wraps
`parseMessageId(pushMessage.objectId!!)` in a `NumberFormatException` catch that logs
"Failed to parse messageId from objectId, skip adding mark-as-read action" — exactly the
path a token-only object id takes.

This strengthens FR-32 rather than weakening it, but the enumeration in the documents is
incomplete as written.

### W3 — "Mobile clients therefore cannot route into a thread no matter what they implement"

**Claimed** (addendum §2, problem 1): the object-id mechanism "is skipped for push. Mobile
clients therefore cannot route into a thread no matter what they implement."

**Actual**: the Android client already routes into threads today. It does not rely on the
push payload's object id — on receiving a push it fetches the full notification over OCS and
reads the object id from *that*:

`clavis-talk-android/.../NotificationWorker.kt:484-493`

```kotlin
override fun onNext(notificationOverall: NotificationOverall) {
    val ncNotification = notificationOverall.ocs!!.notification
    if (ncNotification != null) {
        enrichPushMessageByNcNotificationData(ncNotification)
        val threadId = parseThreadId(ncNotification.objectId)
        threadId?.let { intent.putExtra(KEY_THREAD_ID, it) }
        showNotification(intent, ncNotification)
    }
}
```

The OCS-fetched notification *does* carry the thread id, because the guarded branch at
`Notifier.php:633` runs when the payload is not a push payload.

The underlying concern is still real and narrower than stated: the fallback at
`NotificationWorker.kt:496-513` fires when the OCS fetch fails (offline, unreachable server,
expired notification) and then only push data is available — at which point the thread id is
gone. So the correct statement is "clients lose thread routing whenever they cannot reach the
server", not "clients cannot route at all".

### W4 — The Locked write-path enumeration misses the actual file-share path

**Claimed** (addendum §3.2, and PRD §12 risk "Locked leaks through an unguarded entry point"):

> | File share / rich object | `lib/Controller/ChatController.php:745` `shareObjectToChat()` |

**Actual**: `shareObjectToChat()` handles **rich objects only** — it runs
`$this->richObjectValidator->validate('{object}', ...)` (`:768`) and emits verb
`object_shared` (`:780`). Sharing a *file* into a conversation never reaches it.

File shares arrive over the Files Sharing API (`shareType` = ROOM) and are turned into a chat
message by an event listener:

`lib/Chat/SystemMessage/Listener.php` — `handle()` at `:120` routes
`ShareCreatedEvent | BeforeDuplicateShareSentEvent` to `fixMimeTypeOfVoiceMessage()` at
`:150`; that method (`:400`, upstream's name is misleading) does:

```php
$metaData = $this->request->getParam('talkMetaData') ?? '';   // :403
...
$threadId = null;
if (isset($metaData['threadId'])) {                            // :435
    $threadId = (int)$metaData['threadId'];
    unset($metaData['threadId']);
}
$threadTitle = '';
if (isset($metaData['threadTitle'])) { ... }                   // :441
$comment = $this->sendSystemMessage(
    $room, 'file_shared', ['share' => $share->getId(), 'metaData' => $metaData],
    silent: $silent, replyTo: $replyTo, threadId: $threadId,   // :448-455
);
if ($threadTitle !== '' && $comment->getTopmostParentId() === '0') {
    $thread = $this->threadService->createThread($room, $messageId, $threadTitle);  // :459
    ...
}
```

So this path can both **post into an existing thread** and **create a new one**. Validation is
at `Listener.php:574-577` (`validateThread`, silent reset to `null`) — the same silent-reset
pattern the addendum flags for the other two.

This is not hypothetical: the repository's own test exercises it.
`tests/integration/features/chat-4/threads.feature:238-243`

```
When user "participant2" shares "welcome.txt" with room "room1"
  | talkMetaData.caption      | Message 3 |
  | talkMetaData.threadId     | Message 1 |
```

and the step definition maps `talkMetaData.threadId` at
`tests/integration/features/bootstrap/SharingContext.php:92` and `:785`.

**This is the missed seventh path.** A guard added only to the six rows in §3.2 leaves file
sharing into a Locked thread open.

### W5 — An eighth path: the draft-attachment endpoint, with no thread validation at all

`lib/Controller/ChatController.php:2505` `postAttachmentToRoom()`
(`POST /ocs/v2.php/apps/spreed/api/v1/chat/{token}/attachment`) reads `threadId` out of
`talkMetaData` and posts into the thread:

```php
$threadId = isset($metaData['threadId']) ? (int)$metaData['threadId'] : 0;   // :2574
...
$this->chatManager->addSystemMessage(
    $this->room, $this->participant, Attendee::ACTOR_USERS, $uid,
    json_encode(['message' => 'file_shared', 'parameters' => [...]]),
    $this->timeFactory->getDateTime(), true,
    $referenceId !== '' ? $referenceId : null, $replyToComment, false, $silent,
    $threadId,                                                               // :2601
);
```

It is genuinely distinct from W4's path — `SystemMessage\Listener` explicitly bails out for
this route at `:382-386`:

```php
$route = strtolower($this->request->getParam('_route') ?? '');
if ($route === 'ocs.spreed.chat.postattachmenttoroom'
    || $route === 'ocs.spreed.chat.probeattachmentfolder') {
    return;
}
```

Note additionally that this controller performs **no** `validateThread()` /
`findByThreadId()` check on `$threadId` — unlike `shareObjectToChat()` (`:788`),
`createPoll()` (`:127`), `sendMessage()` (`:406`), `scheduleMessage()` (`:563`),
`BotController::sendMessage()` (`:193`) and `SendScheduledMessages` (`:146`). The
unvalidated value reaches `ChatManager::addSystemMessage()`, which does
`$comment->setParentId((string)$threadId)` at `:179` *before* the save; the thread-row check
only happens afterwards, at `:209-227`, and merely resets the in-memory `$threadId` to 0 —
the comment's `parent_id` has already been written. This is a pre-existing upstream gap
independent of FR-5, and it makes this the weakest of the write paths to leave unguarded.

### W6 — A ninth path: event-based bot answers bypass `BotController`

`lib/Service/BotService.php:305` — reached from `afterChatMessageSent()` (`:92`) /
`afterSystemMessageSent()` (`:149`) / `afterReactionAdded()` (`:178`) via `invokeBots()`
(`:247`), i.e. from a `BotInvokeEvent` listener's answer, **not** from the OCS endpoint:

```php
} elseif ($answer['thread'] === true) {
    $threadId = (int)$comment->getTopmostParentId() ?: (int)$comment->getId();   // :300
}
...
$botComment = $chatManager->sendMessage(
    $room, null, Attendee::ACTOR_BOTS, ...,
    threadId: $threadId, threadTitle: $threadTitle,                              // :316-317
);
```

The addendum's "Bot message" row points only at `lib/Controller/BotController.php:169`, which
is the webhook/OCS route. In-process bots reach the thread through `BotService` instead, and
that call site has no `validateThread()` either.

**Net for §3.2:** the six enumerated paths all exist and all do post into a thread
(verified individually in §3 below), but the enumeration is **three short** — W4, W5, W6.
The correct count is nine, not six. This matters most for W4, which is the one an
implementer would assume is covered by the "File share" row.

---

## 2. DRIFTED

| # | Claim | Correction |
|---|---|---|
| D1 | addendum §2: "`lib/Notification/Notifier.php:604-610` sets the link" | The quoted block is **`:602-610`** (`// Set the link to the specific message` at 602, `$urlParams = [` at 603). The quoted code and the substance are exact. |
| D2 | addendum §2: "`:633-642` sets the object, inside a push guard" | Exact — 633 is the `if`, 642 the closing brace. Listed here only because D1 was off; **this one is CONFIRMED**, see §3. |
| D3 | addendum §4.2: "`talk_attendees.last_read_message` … added by `Version7000Date20190724121136.php:41`" | `Version7000Date20190724121136.php:40-44` adds `last_read_message` to **`talk_participants`**, not `talk_attendees` (`$schema->getTable('talk_participants')` at `:39`). The column on `talk_attendees` is created by `Version10000Date20201015134000.php:99`, in the `createTable('talk_attendees')` block that starts at `:47-48`, with data copied over at `:176` / `:201`. The lineage claim is right; the table attribution on the first migration is not. |
| D4 | addendum §4.1: the four columns were dropped "three weeks later" | 23 June 2025 → 10 July 2025 = **17 days**. |
| D5 | addendum §3.2: "silently resets an invalid thread to 0 at `:788-794`" (shareObjectToChat) | The block is **`:788-795`** (`}` at 795). |
| D6 | addendum §3.2: "Same silent-reset pattern at `:127-133`" (PollController) | The block is **`:127-134`**. |
| D7 | addendum §2: "`notifyOtherParticipant()` at `:253-293`" | The method is **`lib/Chat/Notifier.php:253-317`**. Line 293 is mid-loop; the next method starts at `:319`. |
| D8 | addendum §6: "The proxy layer for the **four** existing thread endpoints" | There are **five** thread endpoints (addendum §1.3 lists five, correctly). `lib/Federation/Proxy/TalkV1/Controller/ThreadController.php` proxies **four** of them — `getRecentActiveThreads` (:40), `getThread` (:69), `renameThread` (:106), `setNotificationLevel` (:155). `getSubscribedThreads` is **not** proxied. |
| D9 | addendum §7: "`threads.feature` — **thirteen** scenarios" | **Twelve.** Lines 6, 16, 50, 74, 102, 127, 144, 167, 205, 228, 261, 290. (Two are both named "Post a location to a thread"; the second, at `:290`, is the poll one — so the claim that polls are covered is correct despite the count.) |
| D10 | addendum §7: "The table columns the steps already use are `t.id`, `t.token`, `t.title`, `t.numReplies`, `t.lastMessage`, `a.notificationLevel`" | All six are used. The list is incomplete: the step tables also carry **`firstMessage`** and **`lastMessage`** (e.g. `threads.feature:13`, `:219`). |
| D11 | addendum §1.4: "`CommentsManager.php:129-133` … marked `FIXME: TEMPORARY method until nextcloud/server#53896 is merged`" | The filter at `:129-133` is correct. The FIXME lives on the enclosing methods' docblocks, at **`:54`** and **`:88`**, and cites `https://github.com/nextcloud/server/pull/53896`. |
| D12 | addendum §6: "`internal/proxy/handler.go:19-26` documents that `Subject` and `Signature` are ciphertext" | In `/home/tinxu-luna/clavis-tech/clavis-push`, the comment is at **`:18-20`**; `:21-26` is the `Sender` interface it documents. Substance exact. |
| D13 | addendum §2: "Push subject construction is at `:686-689`" | `:686` is the `} elseif ($this->notificationManager->isPreparingPushNotification()) {`, `:687` the `shortenMultibyteString($parsedMessage, 100)`. Correct. The example subject string `'{user} in {call}' . "\n{message}"` is at **`:744`**. |
| D14 | addendum §1.5: `useGetThreadId` is "Used in thirteen files" | **Twelve** consumer files import it. Thirteen files contain the identifier, but one of those is its own definition (`src/composables/useGetThreadId.ts:14`). The "21 lines" and "writable ref over the `threadId` query parameter" parts are exact. |
| D15 | addendum §1.6: "`RightSidebar.vue:47-55` renders it in a `v-else-if`" | The block is **`:48-55`** (`<NcAppSidebarTab` opens at 48, `v-else-if="contentState === 'threads'"` at 49, closes at 55). Line 47 is the previous block's closing tag. |
| D16 | addendum §1.6: "`handleUpdateState('threads')` at `:550-562`" | `handleUpdateState(value)` is **`RightSidebar.vue:551-563`**; the `'threads'` branch is `:557-559`. |
| D17 | addendum §1.6: 'a "Threads" nav button at `LeftSidebar.vue:254-260`' | The `LeftSidebarButton` element spans **`:253-260`** (`:254` is its `v-else-if`). |
| D18 | addendum §3.4: a new system message needs "a constant in `src/constants.ts:279-280`, a parser branch, **classification** in `src/utils/message.ts:50-51`, and **relay-list membership**" | The line numbers are exact, but `src/utils/message.ts:44` is itself `const SYSTEM_MESSAGE_TYPE_RELAY = [`, carrying the comment `/** Sync with server-side constant SYSTEM_MESSAGE_TYPE_RELAY in lib/Signaling/Listener.php */`. So `:50-51` **is** the client-side mirror of the relay list, not a separate classification step. The four listed items are really three: server constant + parser branch + relay list (server `Listener.php:87-88` **and** its client mirror `message.ts:50-51`). |
| D19 | addendum §1.6 describes the threads-list rendering only in `RightSidebar.vue` | Incomplete. A second component carries the same state machine: `src/components/RightSidebar/RightSidebarContent.vue:47` declares `type SidebarContentState = 'default' \| 'search' \| 'threads'` and `:104` handles `props.state === 'threads'`. Anything changing the threads content-state needs both files. |

---

## 3. CONFIRMED

### 3.1 Notifier — the push guard (priority claim 1, the part that holds)

- `lib/Notification/Notifier.php:633` — **both** `setObject()` calls sit inside
  `if (!$this->notificationManager->isPreparingPushNotification() && !$participant->getAttendee()->isSensitive())`.
  Push payloads therefore never receive the thread id through the object-id mechanism. **CONFIRMED.**
- `Notifier.php:602-610` — `setLink()` is **outside** any push guard and *does* carry
  `threadId` when present. **CONFIRMED.**
- **Is `setLink()` part of push payloads? No.** The decrypted push payload's fields are fixed
  and contain no link. Authoritative in-repo evidence from the shipped client:
  `clavis-talk-android/app/src/main/java/com/nextcloud/talk/models/json/push/DecryptedPushMessage.kt`
  declares exactly `app`, `type`, `subject`, `id`, `nid`, `nids`, `delete`, `delete-all`,
  `delete-multiple`, `text` — **no `link` field**. `clavis-push/internal/proxy/handler.go:29-35`
  shows the outer envelope (`deviceIdentifier`, `pushTokenHash`, `subject`, `signature`,
  `priority`, `type`) likewise carries no link. So the document's framing is correct: the
  `threadId` on `setLink()` is irrelevant to push, and the object id is the only routing hint
  that would have reached a device.
- `lib/Chat/Notifier.php:628` `createNotification()`, with `threadId` written into the message
  parameters at `:636-638` only when non-null and non-zero. **CONFIRMED.**
- `Notifier.php:686-687` — push preview truncated to 100 characters via
  `Util::shortenMultibyteString($parsedMessage, 100)`. **CONFIRMED.**
- Sensitive-conversation withholding at `Notifier.php:645-670`. **CONFIRMED.**

### 3.2 The dead columns (priority claim 2) — all four pointers exact, zero callers

| Claimed | Actual |
|---|---|
| created by `Version22000Date20250623142327.php:110-128` | exact — `getTable('talk_attendees')` at `:110`, three `hasColumn`-guarded `addColumn` blocks at `:111-128` |
| declared on `lib/Model/Attendee.php:150-152` | exact — `hasUnreadThreads`, `hasUnreadThreadMentions`, `hasUnreadThreadDirects` |
| hydrated at `lib/Model/AttendeeMapper.php:315-317` | exact |
| selected at `lib/Model/SelectHelper.php:112-114` | exact |

**Zero callers — CONFIRMED.** An exhaustive case-insensitive grep for
`hasunreadthread|has_unread_thread` across `*.php *.ts *.js *.vue *.json *.md *.feature`
(excluding `node_modules` and `_bmad-output`) returns only:

- the three declaration sites above,
- `lib/Model/Attendee.php:69-74` — the `@method` docblock stubs for the magic accessors,
- `lib/Model/Attendee.php:184-186` — `addType(...)` in the constructor,
- `tests/php/Chat/ChatManagerTest.php:443-445, 514-516, 607-609` — `'has_unread_threads' => false`
  row fixtures only.

No `setHasUnreadThreads*` / `getHasUnreadThreads*` call anywhere in `lib/` or `src/`. The
addendum's precise wording holds. (The test fixtures are row-array keys, not accessor calls,
so they do not contradict it; they are worth knowing about because the migration is already
reflected in test data.)

### 3.3 The read-marker migrations (priority claim 3) — exact

- **Created:** `Version22000Date20250623142327.php:87-103` adds `last_read_message` (`:87`),
  `last_mention_message` (`:91`), `last_mention_direct` (`:95`) and `read_privacy` (`:99`) to
  `talk_thread_attendees`. **Line range exact.**
- **Dropped:** `Version22000Date20250710124258.php:49-60` drops all four, each behind a
  `hasColumn` guard. **Line range exact.**
- The same second migration adds `talk_threads.last_activity` (`:36`) and `name` (`:39`) and
  the `talkthread_lastactive` index (`:45`) — matching addendum §1.1.
- **`talk_attendees.last_read_message` is a different, still-live column — CONFIRMED.** It is
  on `talk_attendees`, not `talk_thread_attendees`; selected at `SelectHelper.php:95`,
  hydrated at `AttendeeMapper.php:298`, and read/written in `ParticipantService.php:1609`,
  `:1637`, `:1660`. Untouched by either 2025 migration. (Attribution nuance: see D3.)
- `ChatManager::getUnreadCount(Room $chat, int $lastReadMessage): int` at
  `lib/Chat/ChatManager.php:993`. **CONFIRMED.**

### 3.4 The six named write paths (priority claim 4) — each exists and posts into a thread

| Path | Claimed | Verified |
|---|---|---|
| Direct message | `ChatController.php:376` `sendMessage()` | exact; `threadId` param present, validated at `:406`, passed at `:426` |
| Bot message | `BotController.php:169` `sendMessage()` | exact; validated at `:193`, sent at `:204` |
| Rich object | `ChatController.php:745` `shareObjectToChat()` | exact; silent reset `:788-795`; `addSystemMessage(..., threadId:)` at `:798`. **But see W4** — this is not the file-share path |
| Poll | `PollController.php:89` `createPoll()` | exact; silent reset `:127-134`; `addSystemMessage(..., threadId:)` at `:150` |
| Scheduled (accept) | `ChatController.php:527` `scheduleMessage()` | exact; validated at request time at `:563`; `METADATA_THREAD_ID` persisted at `:582` |
| Scheduled (fire) | `SendScheduledMessages.php:145-179` | exact; no live request; `validateThread` at `:146`, `markAsFailed` + `ERROR_SCHEDULED_MESSAGE` at `:149-150`, `sendMessage(..., threadId:)` at `:161-174` |

- The proposed guard seams are where the document says: `ChatManager::sendMessage()` at
  `lib/Chat/ChatManager.php:383`, `addSystemMessage()` at `:147`. **CONFIRMED.**
- **Caveat on the document's own reasoning** (not a factual error, but load-bearing):
  the rich-object path (`:798`), the poll path (`:150`), the file-share path (W4, `:580`) and
  the attachment path (W5, `:2589`) *all* post via `addSystemMessage()`. An `addSystemMessage`
  guard that exempts system messages — which §3.2 correctly says it must, so state-change
  messages can be written to a Locked thread — would therefore exempt four of the nine write
  paths. The exemption cannot be "is a system message"; it has to be narrower.

### 3.5 `ThreadService` caching (priority claim 5) — every detail exact

`lib/Service/ThreadService.php`:

- `$this->cache = $this->cacheFactory->createDistributed('talk.threads');` — `:37`. **Cache name exact.**
- `private const CACHE_PREFIX = 'thread/';` — `:29`. **Prefix exact.**
- Key `thread/{roomId}/{threadId}` — `:51`, `:62`, `:74`, `:76`, `:126`, `:266`. **Exact.**
- TTL `60 * 15` = 900s at every `set()`. **Exact.**
- **Negative caching:** `:76` stores `''` on `DoesNotExistException`; `:68-70` turns a cached
  `''` back into `throw new DoesNotExistException('No thread found')`. **Exact.**
- Refresh/invalidate on mutation: `createThread` `:51` (set), `renameThread` `:126` (set),
  `updateLastMessageInfoAfterReply` `:266` (remove), `deleteByRoom` `:271` (`clear` on prefix).
  **CONFIRMED.**

### 3.6 `ThreadController::renameThread` permission rule (priority claim 6) — exact

`lib/Controller/ThreadController.php:200`. The rule is "own root message OR moderator", and
the expression is:

```php
$attendee = $this->participant->getAttendee();
$isOwnMessage = false;
try {
    $comment = $this->chatManager->getComment($this->room, (string)$threadId);
    $isOwnMessage = $comment->getActorType() === $attendee->getActorType()
        && $comment->getActorId() === $attendee->getActorId();
} catch (NotFoundException) {
    // Root message expired, only moderators can edit
}

if (!$isOwnMessage
    && !$this->participant->hasModeratorPermissions(false)) {
    // Actor is not a moderator or not the owner of the message
    return new DataResponse(['error' => 'permission'], Http::STATUS_FORBIDDEN);
}
```

Note the two details the document does not mention but which matter for reuse: the moderator
check is `hasModeratorPermissions(false)`, and if the root comment has expired `$isOwnMessage`
stays `false`, so only moderators can rename. Federated conversations are proxied out at the
top of the method before any of this.

### 3.7 The two upstream defects (priority claim 7) — both real

**Defect 1 — `lib/Service/BotService.php`. CONFIRMED, line numbers exact.**

```php
if ($threadTitle !== '') {
    $thread = $this->threadService->createThread($room, (int)$comment->getId(), $threadTitle);   // :321
    $this->chatManager->addSystemMessage(
        ...
        json_encode(['message' => 'thread_created', 'parameters' => ['thread' => (int)$botComment->getId(), 'title' => $thread->getName()]]),  // :328
        ...
    );
}
```

`createThread` is keyed on `$comment` (the message that invoked the bot); the `thread_created`
system message announces `$botComment` (the bot's own reply). They disagree about the root.

Additional detail confirming the defect rather than contradicting it: `sendMessage` was called
at `:305` with `threadId: 0` (= `Thread::THREAD_NONE`) and `threadTitle: $threadTitle`. The
`THREAD_NONE → THREAD_CREATE` promotion lives in the *controllers*
(`ChatController.php:569-570`, `BotController.php:202-203`), not in `ChatManager`, so
`ChatManager::sendMessage`'s create branch at `:458` never fires here. `$botComment` is not
enrolled in the thread at all, and `BotController::sendMessage` at `:206`/`:213` — the
webhook route — gets this right by using `$comment->getId()` for both.

**Defect 2 — `lib/Signaling/Listener.php:621-635`. CONFIRMED, line numbers exact.**

```php
if ($messageType === 'thread_created' || $messageType === 'thread_renamed') {
    $data['chat']['comment']['threadInfo']['thread'] = [ ... ];        // :622-629
    $data['chat']['comment']['threadInfo']['attendee'] = ['notificationLevel' => 0];  // :630
    $data['chat']['comment']['threadInfo']['first'] = $thread->toArray($room);        // :631
    $data['chat']['comment']['threadInfo']['last'] = null;                            // :632
```

The REST contract at `lib/ResponseDefinitions.php:744-753` is:

```
 * @psalm-type TalkThreadInfo = array{
 *      thread: TalkThread,
 *      attendee: TalkThreadAttendee,
 *      first: ?TalkChatMessage,      // :750
 *      last: ?TalkChatMessage,       // :752
 * }
```

So `first` is declared `?TalkChatMessage` and the signalling payload puts a `Thread` there
(`Thread::toArray(Room)` at `lib/Model/Thread.php:102`), and `last` is always `null`. The
shape does not match `TalkThreadInfo`. **CONFIRMED.**

Corroborating: `lib/Federation/Proxy/TalkV1/UserConverter.php:170-179` `convertThreadInfo()`
runs `convertMessageParameters()` over `first` and `last` — i.e. the rest of the codebase
treats them as messages.

### 3.8 Repository state (priority claim 8) — CONFIRMED

- `git rev-parse --is-shallow-repository` → `true`
- `.git/shallow` present, listing `6819859953c32f32ccaecf13f4b11f1ad382db00` and
  `be183da10edbf1c1ab72d11a6f0a486961853f13`
- `git log --oneline | wc -l` → `1`; the single commit is
  `6819859 Merge pull request #18949 from nextcloud/dependabot/npm_and_yarn/stable34/rspack/cli-2.1.7`,
  dated 2026-08-08.

The PRD §6.1 statement ("a shallow clone with a single commit of history") and addendum §6
("Shallow clone, depth 1 … `git rev-list --count HEAD` returns 1") are both accurate.

### 3.9 Capability flag (priority claim 9) — CONFIRMED

`lib/Capabilities.php:130` is `'threads',` inside `public const FEATURES = [` (`:35`–`:138`).
It is **not** in `CONDITIONAL_FEATURES` (`:140`) or `LOCAL_FEATURES` (`:148`). Being a plain
array constant member, it is declared unconditionally. `docs/capabilities.md:198` documents it
as "`threads` - Whether the chat supports threads".

### 3.10 Schema (addendum §1.1) — CONFIRMED

`lib/Migration/Version22000Date20250623142327.php`:

- `talk_threads` created at `:36`; `id` BIGINT `notnull` with **no `autoincrement`** (`:37-40`)
  — the "not autoincrement" claim is exact; `room_id` `:41`; `last_message_id` default 0 `:45`;
  `num_replies` default 0 `:49`; PK `['id']` `:54`; index `tt_room_threads` on `room_id` `:55`.
- `talk_thread_attendees` created at `:57`; `id` BIGINT **autoincrement** PK `:58-62`;
  `room_id` `:63`, `thread_id` `:67`, `attendee_id` `:71`, `actor_type`/`actor_id` STRING(255)
  `:75+`; `notification_level` INTEGER nullable default `Participant::NOTIFY_DEFAULT` `:83-86`.
- Index `tta_room_attendee` on `(room_id, actor_type, actor_id)` at `:108`; the narrower
  `tta_thread_attendee` unique index is commented out at `:107` with
  `/** Replaced by @see Version22001Date20250927174738 */`.
- `Version22001Date20250927174738.php:31-36` removes `tta_thread_attendee` and adds unique
  index `tta_throom_attendee` on `['thread_id', 'room_id', 'actor_type', 'actor_id']`. **Exact,
  including the column order.**
- After the drops in `Version22000Date20250710124258`, `notification_level` is indeed the only
  per-participant thread setting left on the table. **CONFIRMED.**

### 3.11 Entities, mappers, service (addendum §1.2) — every pointer CONFIRMED

- `lib/Model/Thread.php` — `THREAD_NONE = 0` `:31`, `THREAD_CREATE = -1` `:32`;
  `getName()` `:90` falls back to `'Thread #' . $this->getId()` `:96` under
  `// FIXME temporary workaround against empty titles` `:95`; `toArray(Room $room)` **`:102`**.
  Also present, as §3.1 of the addendum requires: `createFromRow` `:47`, `fromJson` `:63`,
  `toJson` `:79`, `addType` calls in the constructor `:39`.
- `lib/Model/ThreadAttendee.php` — `jsonSerialize()` **`:75`**, returning only
  `['notificationLevel' => min(3, max(0, ...))]`. **Exact.**
- `lib/Model/ThreadMapper.php` — `findById` `:32`, `findByIds` `:55`, `getForIds` `:77`
  (chunks by 1000 at `:82`, **no room filter** — confirmed, it filters on `id` only),
  `getRecentByRoomId` `:98` (`orderBy('last_activity', 'DESC')` at `:107`),
  `deleteByRoomId` `:113`.
- `lib/Model/ThreadAttendeeMapper.php` — `findAttendeeByThreadIds` `:44`,
  `findAttendeeByThreadId` `:72`, `findAttendeesForNotification` **`:99`** (filters
  `neq('notification_level', NOTIFY_DEFAULT)` at `:111-114` — exact), `deleteByRoomId` `:28`.
- `lib/Service/ThreadService.php` — all sixteen named methods present:
  `createThread` `:40`, `findByThreadId` `:61`, `findByThreadIds` `:87`,
  `preloadThreadsForConversationList` `:101`, `renameThread` `:116`,
  `getRecentByRoomId` `:134` (clamps `min(50, max(1, $limit))` at `:135` — **1–50 confirmed**),
  `getRecentByActor` **`:143`**, `findAttendeeByThreadIds` `:183`,
  `findAttendeesForNotificationByThreadId` `:196`, `setNotificationLevel` **`:206`**,
  `ensureIsThreadAttendee` **`:226`**, `removeThreadAttendeesByAttendeeIds` **`:246`**,
  `updateLastMessageInfoAfterReply` **`:256`**, `deleteByRoom` `:270`, `validateThread` `:276`.
- `lib/Model/SelectHelper.php:53` — `selectThreadsTable(IQueryBuilder $query, string $alias = 'th', bool $aliasAll = false)`,
  with the aliased branch at `:58-67` and the unaliased at `:69-75`. **Both branches exist as claimed.**
- `lib/Listener/ThreadListener.php` — handles **only** `AttendeesRemovedEvent` (`:28`), calling
  `removeThreadAttendeesByAttendeeIds` (`:30`); registered at
  `lib/AppInfo/Application.php:356` under the comment `// Threads listeners`. **Exact.**

### 3.12 Endpoints (addendum §1.3) — CONFIRMED

`appinfo/` contains only `info.xml` — **no `appinfo/routes.php`**; routes come from
`#[ApiRoute]` attributes. All five endpoints and handlers exact:

| Verb | `#[ApiRoute]` url | Method | Line |
|---|---|---|---|
| GET | `/api/{apiVersion}/chat/{token}/threads/recent` | `getRecentActiveThreads` | 74 / 78 |
| GET | `/api/{apiVersion}/chat/subscribed-threads` | `getSubscribedThreads` | 102 / 105 |
| GET | `/api/{apiVersion}/chat/{token}/threads/{threadId}` | `getThread` | 151 / 156 |
| PUT | `/api/{apiVersion}/chat/{token}/threads/{threadId}` | `renameThread` | 195 / 200 |
| POST | `/api/{apiVersion}/chat/{token}/threads/{messageId}/notify` | `setNotificationLevel` | 349 / 354 |

`prepareListOfThreads()` at **`:269`**, called from `:86`, `:131`, `:169`, `:258`, `:385`. **Exact.**

### 3.13 Message ↔ thread linkage (addendum §1.4) — CONFIRMED

- No new column on the comments table; linkage is the three mechanisms claimed.
- The idiom `(int)$comment->getTopmostParentId() ?: (int)$comment->getId()` appears at
  `ChatController.php:319`, `:1435`, `:1559`, `:2285`, `MessageSearch.php:292`,
  `BotService.php:300`, `Message.php` (as `?: $id`), and others.
- `$comment->setParentId((string)$threadId)` at **`lib/Chat/ChatManager.php:179`**
  (`addSystemMessage`) and **`:418`** (`sendMessage`). **Exact.**
- `Message::METADATA_THREAD_ID = 'thread_id'` `:27`, `METADATA_THREAD_TITLE = 'thread_title'`
  `:28`. **Exact.**
- `lib/Chat/CommentsManager.php:129-133`:
  ```php
  if ($topmostParentId !== '') {
      $query->andWhere($query->expr()->orX(
          $query->expr()->eq('id', $query->createNamedParameter($topmostParentId)),
          $query->expr()->eq('topmost_parent_id', $query->createNamedParameter($topmostParentId)),
      ));
  }
  ```
  **Exact.** (FIXME location: see D11.)
- `Message::toArray(string $format, ?Thread $thread)` at **`:187`**. The parameter has **no
  default**, so every call site passes it explicitly — **confirmed**. `threadId` is always
  emitted; `isThread`, `threadTitle`, `threadReplies` only inside `if ($thread !== null)`.
  **CONFIRMED.**

### 3.14 System messages (addendum §3.4) — CONFIRMED

- `thread_created` parsed at `lib/Chat/Parser/SystemMessage.php:587-596`, `thread_renamed` at
  `:597-606`. The claimed range `:587-606` is **exact**.
- `SYSTEM_MESSAGE_TYPE_RELAY` at `lib/Signaling/Listener.php:81`, with `'thread_created'` at
  **`:87`** and `'thread_renamed'` at **`:88`**. **Exact.**

### 3.15 Reply bump and reopen-by-reply (addendum §3.3) — CONFIRMED

`ThreadService::updateLastMessageInfoAfterReply()` at `:256` does exactly what is claimed:
increments `num_replies` (`:261`), sets `last_message_id` (`:262`) and `last_activity` (`:263`),
removes the cache entry (`:266`), and returns `(bool)$query->executeStatement()` (`:267`).
Every reply passes through it — `ChatManager.php:217` (system messages) and `:464`
(`sendMessage`) — and both use the bool as an "is this a real thread" test, resetting
`$threadId` to 0/`THREAD_NONE` when it is false (`:226`, `:466`). **CONFIRMED.**

### 3.16 Row creation and reclamation (addendum §4.3) — CONFIRMED

- `ensureIsThreadAttendee()` at **`:226`**, inserting with `NOTIFY_DEFAULT` on reply; called
  from `ChatManager.php:222` and `:469`. **CONFIRMED.**
- `setNotificationLevel()` at **`:206`** upserts. **CONFIRMED.**
- `findAttendeesForNotification` filters `!= NOTIFY_DEFAULT`
  (`ThreadAttendeeMapper.php:111-114`) and `getRecentByActor` filters `!= NOTIFY_NEVER`
  (`ThreadService.php:156`). So a row created on read with `NOTIFY_DEFAULT` leaks into
  neither. **The addendum's load-bearing observation is CONFIRMED.**
- `removeThreadAttendeesByAttendeeIds()` at **`:246`**, reached from
  `ThreadListener` on `AttendeesRemovedEvent`. **CONFIRMED.**

### 3.17 Pins, pagination, search (addendum §5.2–5.4) — CONFIRMED

- Sort sites: `ThreadMapper::getRecentByRoomId()` **`:98`** (`orderBy('last_activity','DESC')`
  at `:107`) and `ThreadService::getRecentByActor()` **`:143`**, which is indeed raw
  `QueryBuilder` (`:146-163`) and orders at `:158`.
- The FIXME `// FIXME ORDER BY last_activity and subscription moment of the user for better
  sorting?` is at **`ThreadService.php:157`**. **Exact.**
- `getRecentByRoomId` takes no offset; `getRecentByActor` takes `limit` and `offset`
  (`:143`, `setFirstResult` at `:162`); neither takes a state or label filter. **CONFIRMED.**
  (Note: `getRecentByActor` clamps to `min(100, max(1, ...))` at `:144`, i.e. 1–100, not 1–50 —
  the addendum only claims 1–50 for `getRecentByRoomId`, which is correct.)
- `lib/Search/MessageSearch.php` — `threadId` added to the deep link at `:292-295` and to the
  result attributes at `:315-317`. The claimed range `:292-316` is **essentially exact**.

### 3.18 Test surface (addendum §7) — CONFIRMED apart from D9/D10

- `tests/integration/features/chat-4/threads.feature` exists and covers creation, replies,
  non-moderator rename permission (`:50`), notification levels (`:74`, `:144`), title trimming
  (`:102`), sort by last activity (`:127`), subscribed threads with offset paging (`:167`),
  attachment replies (`:205`, `:228`), geo-location (`:261`) and polls into threads (`:290`,
  with `creates a poll in room` at `:300`/`:306`). **All content claims CONFIRMED** (count: D9).
- Step definitions in `tests/integration/features/bootstrap/FeatureContext.php` (`:3309`,
  `:3319`, `:3329`) and `SharingContext.php` (`:92`, `:785`). **CONFIRMED.**
- Test DB reset covers `talk_threads` at
  `tests/integration/spreedcheats/lib/Controller/ApiController.php:102` and
  `talk_thread_attendees` at **`:105`**. **Exact.**
- **No `tests/php/Service/ThreadServiceTest.php`** — `tests/php/Service/` contains 13 test
  files, none of them for `ThreadService`. **No `tests/php/Controller/ThreadControllerTest.php`**
  — that directory holds only `ChatControllerTest.php` and `SignalingControllerTest.php`.
  **CONFIRMED.**

### 3.19 Federation and limits (addendum §6) — CONFIRMED

- `// FIXME support threads in federation $threadId,` at
  `lib/Controller/ChatController.php:898` and `:1273`. **Both exact.**
- `lib/Federation/Proxy/TalkV1/UserConverter.php:170` `convertThreadInfo()` rewrites
  `$threadInfo['thread']['roomToken'] = $room->getToken()` (`:171`) and runs
  `convertMessageParameters()` over `first` (`:173`) and `last` (`:176`). **CONFIRMED**
  (endpoint count: D8).
- **clavis-push** (`/home/tinxu-luna/clavis-tech/clavis-push`): `internal/proxy/handler.go`
  documents that the proxy cannot read what it sends — "subject and signature are ciphertext
  produced by the customer's own Nextcloud server for that device's public key" (`:18-20`,
  see D12). `README.md:26` records `| Proxy → APNs / FCM | **not implemented** — needs
  credentials Clavis does not have yet |`; `:28-29` names the blockers as an APNs `.p8` key
  and a Clavis Firebase project, and names `NotImplementedSender`. **CONFIRMED.**

### 3.20 Front end (addendum §1.5, §1.6, §3.4, §5.4) — CONFIRMED apart from D14–D19

- `src/components/RightSidebar/Threads/` contains exactly `ThreadsTab.vue`, `ThreadItem.vue`,
  `ThreadHeader.vue`, `threadsConstants.ts`. **Exact, nothing else in the directory.**
- `src/stores/chatExtras.ts` — `threads` is
  `ref<Record<string, Record<number, ThreadInfo>>>({})` at `:55`, i.e. **token → threadId →
  ThreadInfo exactly as claimed**; `followedThreads` is `ref<Set<number>>(new Set())` at `:56`
  — **a Set, as claimed**. All nine named members exist: `addThread`, `fetchSingleThread`,
  `fetchRecentThreadsList`, `fetchFollowedThreadsList`, `setThreadNotificationLevel`,
  `updateThread`, `renameThread`, `clearThreads`, `removeMessageFromThread`. **CONFIRMED.**
- `src/stores/chat.ts` — `checkIfBelongsToContext()` at **`:55-61`**, exact, and it is the
  client-side thread filter (in-thread: `threadId === message.threadId`; main chat: excludes
  thread messages except roots and `temp-` ids). `threadBlocks` at `:81`, typed
  `reactive<TokenIdMap<Set<number>[]>>({})` — token → threadId → *array of* message-id Sets.
  **CONFIRMED** (the addendum's "message-id sets", plural, is accurate).
- `src/services/messagesService.ts` — **exactly five** thread API functions, and the claimed
  `:330-390` range brackets them precisely: `getRecentThreadsForConversation` `:330`,
  `getSingleThreadForConversation` `:346`, `getSubscribedThreads` `:358`,
  `setThreadNotificationLevel` `:376`, `renameThread` `:390`. **CONFIRMED.**
- `src/composables/useGetThreadId.ts` — **21 lines**, a writable ref over the `threadId` query
  parameter whose setter navigates. **CONFIRMED** (consumer count: D14).
- `src/types/openapi/openapi.ts` — `Thread:` at `:3293`, `ThreadAttendee:` at `:3319`,
  `ThreadInfo:` at `:3327` closing at `:3336`. The claimed range **`:3293-3336` is exact.**
- `src/router/router.ts` — the main router declares exactly `root` `:47`, `notfound` `:53`,
  `forbidden` `:59`, `duplicatesession` `:65`, `conversation` `:71`, `recording` `:77`.
  **No thread route. CONFIRMED.** (A separate `createMemoryRouter()` for the Files Sidebar
  integration declares one route, also named `conversation`, at `:98`; it adds no thread route
  either.)
- **"Opened only from `SharedItemsTab.vue:138`" — CONFIRMED, and the "only" is exact.** An
  exhaustive grep for `showThreadsTab` across `src/` returns three hits and no more: the emit
  declaration `SharedItemsTab.vue:49`, the single `@click="emit('showThreadsTab')"` at
  `SharedItemsTab.vue:138`, and the single listener `RightSidebar.vue:131`. The
  `contentState === 'threads'` state is set nowhere else.
- The **three-item preview** is exact: `SharedItemsTab.vue:73`
  `chatExtrasStore.getThreadsList(token.value).slice(0, 3)` and `:74`
  `hasMoreThreads = ... .length > 3`, under the `Recent threads` caption at `:128`, with the
  `Show more threads` label at `:142`. **CONFIRMED.**
- The threads list is rendered **instead of the whole normal tab set** — `RightSidebar.vue:56`
  is `<template v-else>` wrapping the normal tabs. **CONFIRMED.** (It is nonetheless an
  `NcAppSidebarTab id="threads"`; "not a registered sidebar tab" is accurate in substance —
  it is not part of the normal set — but literally it is a tab element.)
- Left sidebar second list: `LeftSidebar.vue:313-331` — **range exact**. It iterates
  `followedThreads`, which is the computed at `:725-726` returning
  `this.chatExtrasStore.followedThreadsList`. **CONFIRMED** (`followedThreadsList` is the
  computed at `chatExtras.ts:110-117`, filtered by the `followedThreads` Set). Nav button:
  D17.
- `src/constants.ts:279-280` — `THREAD_CREATED: 'thread_created'`, `THREAD_RENAMED:
  'thread_renamed'` inside `SYSTEM_TYPE`. **Exact.**
- `src/utils/message.ts:50-51` — `MESSAGE.SYSTEM_TYPE.THREAD_CREATED` / `THREAD_RENAMED`.
  **Exact** (characterisation: D18).
- `src/components/RightSidebar/SearchMessages/SearchMessagesTab.vue:223-235` — **exact**;
  reads `entry.attributes.threadId`, suppresses it when it equals `messageId` (i.e. a root
  message), and puts it in the route query. FR-18's handoff target is real. **CONFIRMED.**
- `hasTalkFeature(token, 'threads')` is how the client reads the capability —
  `MessageItem.vue:325`, `NewMessage.vue:720`, `SharedItemsTab.vue:72`, plus
  `hasTalkFeature('local', 'threads')` at `LeftSidebar.vue:490`. **CONFIRMED.**

### 3.21 PRD prose claims

| PRD | Claim | Verdict |
|---|---|---|
| §4.8 | "the server already attaches the thread identifier to notifications, but the code that does so sits inside a branch skipped when the payload being built is a push payload" | **CONFIRMED** (`Notifier.php:633-642`) |
| §4.8 | "So web notifications carry the thread and push notifications do not" | **CONFIRMED** |
| §4.8 | "the same code overwrites the message identifier with the thread identifier … the specific message is lost" | **WRONG** — see W1 |
| §4.8 / §11 | the Clavis push proxy "relays payloads it cannot read, encrypted for the device by the customer's own server" | **CONFIRMED** (`clavis-push/internal/proxy/handler.go:18-20`) |
| §5 | "Thread data is served from a distributed cache with a fifteen-minute lifetime and negative caching for misses" | **CONFIRMED** (`ThreadService.php:37`, `:68-76`, TTL `60 * 15`) |
| §5 | "over the signalling path Talk already uses for thread events" | **CONFIRMED** (`Signaling/Listener.php:81`, `:87-88`, `:621-635`) |
| §6.1 | "upstream added those columns and removed them three weeks later" | **CONFIRMED** in substance; interval is 17 days (D4) |
| §6.1 | "The repository is currently a shallow clone with a single commit of history" | **CONFIRMED** |
| §7 | "Thread endpoints are currently undocumented outside the generated OpenAPI specification" | **CONFIRMED** — `docs/` mentions threads only at `chat.md:208-209` (the `threadId`/`threadTitle` request params) and `capabilities.md:198`; no endpoint documentation. `openapi.json` does contain them |
| §7 | "FR-33 exists because the current code violates this by overwriting one identifier with another" | **WRONG** — see W1 |
| §11 | "its delivery path to Apple and Google is unimplemented and blocked on an Apple push key and a Firebase project that Clavis does not yet have" | **CONFIRMED** (`clavis-push/README.md:26-29`) |
| §12 | "Content reaches a Thread through at least six paths" | **CONFIRMED as a lower bound** ("at least"), but the enumeration behind it is three short — W4, W5, W6 |

---

## 4. UNVERIFIABLE

| # | Claim | Why |
|---|---|---|
| U1 | addendum §6: "RSA encryption of that kind bounds the plaintext to a couple of hundred bytes" and the whole push size budget | The Nextcloud `notifications` app is not present in this repo or on this machine. The 100-character truncation constant is verified (`Notifier.php:687`); the encryption limit it is attributed to is not checkable here. The document already flags this as open question 8. |
| U2 | addendum §10 (all of it) — Discord / Zulip / Slack / Teams behaviour, and every external URL and issue number | External product documentation, outside this codebase. Not fact-checked. |
| U3 | addendum §8: the quoted requester statement and the "rejected by the requester" attributions | Conversational provenance, not in the repo. |
| U4 | addendum §5.4: "the deployment targets are not all one engine" (collation portability) | Deployment fact, not a code fact. |
| U5 | addendum §3.2 / §4.x: assertions about what a Locked thread *must* do, what a migration *must* restore, etc. | Requirements, not claims about existing code. Excluded by scope. |

---

## 5. Summary

| Verdict | Count |
|---|---|
| WRONG | 6 |
| DRIFTED | 18 |
| CONFIRMED | ~137 |
| UNVERIFIABLE | 5 |

Counts are of the distinct claims enumerated in §§1–4 above; the CONFIRMED figure is
approximate because several claims bundle a list (e.g. "all sixteen `ThreadService` methods"
is counted once per method).

The document is unusually accurate on line numbers. Of roughly 155 checkable pointers, most
are exact and 18 are off by one to a few lines or slightly mis-attributed — none of the drifts
would send a reader to the wrong function. Two findings change what should be built:

1. **W1** — FR-33's premise is false. The object id is already `{token}/{messageId}/{threadId}`,
   proven by the repo's own integration test and by the shipped Android parser. The real
   remaining defect is that *neither* id reaches a push payload (W2), which FR-32 already covers.
2. **W4/W5/W6** — the Locked write-path matrix is three entry points short, and the missing
   one that matters is **file sharing** (`lib/Chat/SystemMessage/Listener.php:400-455`), which
   the "File share / rich object" row misidentifies as `shareObjectToChat()`. A guard built
   from §3.2 as written would leave file shares, draft attachments and in-process bot answers
   able to post into a Locked thread.
