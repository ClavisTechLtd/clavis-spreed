# Reviewer Gate — Reality & Version Check

**Target:** `_bmad-output/planning-artifacts/architecture/architecture-clavis-spreed-2026-08-08/ARCHITECTURE-SPINE.md`
**Repo:** `/home/tinxu-luna/clavis-tech/clavis-spreed` @ `stable34` / `6819859` (Talk 24.0.3)
**Lens:** every committed decision must be reality-checked, not asserted. Brownfield — the repository is the authority.
**Date:** 2026-08-08

---

## Overall verdict

**Conditional pass.** The spine is unusually well-grounded: the stack table is read off the manifests rather than remembered, and the great majority of its code assertions survive a line-by-line check against the source. But five claims are **wrong**, and three of those are load-bearing premises under ADs — an AD built on a false premise is worse than no AD, because it produces a test that cannot pass, a copied helper that does the opposite of what it promises, and a registry checklist that misses the one entry that actually silences the feature.

**Counts:** 31 CONFIRMED · 6 DRIFTED · 5 WRONG · 2 UNVERIFIABLE

*Cross-verification:* the front-end claims in §B.7 and §B.8 were independently checked by a second reviewer session against the same commit. Their findings agree with mine on every verdict; three of my evidence citations were a line off and are corrected here (`SYSTEM_MESSAGE_TYPE_HIDDEN` `:90-91`, `createMemoryRouter` route `:96-102`, `useGetThreadId` `:14-21`). Two of their observations are folded in as new rows/findings (the `SearchMessagesTab` precedent, F21). One correction runs the other way: they repeat the spine's "the Files sidebar's factory" framing for `createMemoryRouter`, which F15 shows is four entry points, not one.

---

## A. Stack table verdict

Every row was checked against `appinfo/info.xml`, `composer.json`, `package.json` **and** `package-lock.json` (resolved versions, not just ranges).

| Spine row | Spine value | Repo evidence | Verdict |
| --- | --- | --- | --- |
| Nextcloud Talk (`spreed`) | 24.0.3 | `appinfo/info.xml:21` | CONFIRMED |
| Nextcloud server | 34 (`min` = `max` = 34) | `appinfo/info.xml:65` | CONFIRMED |
| PHP | 8.2 (composer platform pin) | `composer.json:14-16`; same pin repeated in all six `vendor-bin/*/composer.json` | CONFIRMED |
| Database | MySQL/MariaDB, PostgreSQL or SQLite | **Oracle is missing.** `.github/workflows/phpunit-oci.yml` runs on *every* PR; `.github/workflows/integration-oci.yml` runs nightly | **DRIFTED — see F5** |
| Vue | ^3.5.40 | `package.json:76`; lock resolves 3.5.40 | CONFIRMED |
| Pinia | ^3.0.3 | `package.json:72`; lock resolves **3.0.4** | CONFIRMED (range correct; note the resolved patch differs) |
| vue-router | ^5.2.0 | `package.json:80`; lock 5.2.0 | CONFIRMED |
| @nextcloud/vue | ^9.9.0 | `package.json:48`; lock 9.9.0 | CONFIRMED |
| TypeScript | ^5.9.3 | `package.json:109`; lock 5.9.3 | CONFIRMED |
| Vitest | ^4.1.10 | `package.json:110`; lock 4.1.10 | CONFIRMED |
| rspack / @rspack/cli | ^2.1.7 | `package.json:93-94` (`@rspack/core` also ^2.1.7); lock 2.1.7 | CONFIRMED |
| Behat + PHPUnit | "as vendored in `tests/`" | Behat `^3.31.0` + PHPUnit `^11.5` in `tests/integration/composer.json`; PHPUnit `^11.5` in `vendor-bin/phpunit/composer.json` — **not** in `tests/` | DRIFTED — see F13 |

**Does the table name the right things?** Mostly, but four load-bearing entries are absent — see F14. The most consequential is `@vueuse/core` / `@vueuse/router` `^14.3.0` (`package.json:52-53`), because **AD-16 is built directly on them**: `src/composables/useGetThreadId.ts` is `createSharedComposable(useRouteQuery(...))`. An AD that mandates "a shared composable in the `useGetThreadId` mould" without naming the library that mould comes from is under-specified.

**Good:** the header line "Verified from the repository at `stable34` / `6819859`, not from memory" is itself true. Every checkable version matched. No invented package, no hallucinated version.

---

## B. Claim-by-claim table

### B.1 `ThreadService` cache (AD-1)

| Claim | Verdict | Evidence |
| --- | --- | --- |
| Distributed cache | CONFIRMED | `lib/Service/ThreadService.php:37` — `createDistributed('talk.threads')` |
| Prefix `thread/{roomId}/{threadId}` | CONFIRMED | `lib/Service/ThreadService.php:29`, keys built at `:51`, `:62`, `:74`, `:126`, `:266` |
| 900s TTL | CONFIRMED | `60 * 15` at `:51`, `:74`, `:76`, `:126` |
| Negative caching | CONFIRMED | `:76` writes `''` on miss; `:66-68` throws `DoesNotExistException` on `$row === ''` |
| Mutators re-set rather than remove | **DRIFTED** | `createThread` re-sets (`:51`), `renameThread` re-sets (`:126`) — but `updateLastMessageInfoAfterReply` **removes** (`:266`) and `deleteByRoom` **clears** (`:271`). See F8 |
| Six-place field checklist (`addType`, `createFromRow`, `fromJson`, `toJson`, `toArray`, `SelectHelper` both branches) | CONFIRMED — exact | `lib/Model/Thread.php:40-44, 47, 63, 79, 102`; `lib/Model/SelectHelper.php:53-77`, aliased branch `:58-67`, unaliased `:69-76` |

### B.2 `ChatManager` as the write choke point (AD-2)

| Claim | Verdict | Evidence |
| --- | --- | --- |
| Path 1 — typed message | CONFIRMED | `lib/Controller/ChatController.php:416` → `sendMessage` |
| Path 2 — bot message via API | CONFIRMED | `lib/Controller/BotController.php:204` |
| Path 3 — in-process bot answer | CONFIRMED | `lib/Service/BotService.php:305` |
| Path 4 — rich object share | CONFIRMED | `lib/Controller/ChatController.php:798` → `addSystemMessage` |
| Path 5 — poll | CONFIRMED | `lib/Controller/PollController.php:150` |
| Path 6 — file share, can also create a Thread | CONFIRMED | `lib/Chat/SystemMessage/Listener.php:447` → `:580` `addSystemMessage`; thread creation at `:462` |
| Path 7 — attachment upload, **no thread validation today** | CONFIRMED | `lib/Controller/ChatController.php:2505` `postAttachmentToRoom()`; `threadId` read from `talkMetaData` at `:2574` and passed straight to `addSystemMessage` at `:2589-2601` with no `validateThread()`. Contrast `sendMessage` `:406`, `scheduleMessage` `:563`, `receiveMessages` `:903`, `getMessageContext` `:1278` |
| Path 8 — scheduled message, accepted → `sendMessage` | **WRONG** | `lib/Controller/ChatController.php:527-593` writes a `talk_scheduled_messages` row via `scheduledMessageManager`. It never calls `ChatManager`. See F7 |
| Path 9 — scheduled message, fired, no request context | CONFIRMED | `lib/BackgroundJob/SendScheduledMessages.php:161` `sendMessage` + `:180` `addSystemMessage`; thread re-validated at fire time `:146` |
| "four of the nine paths post through `addSystemMessage()`" | CONFIRMED | Paths 4, 5, 6, 7 |
| "the **only** place Thread write policy is enforced" | **WRONG** | `lib/Chat/ReactionManager.php:81` writes a comment with `setParentId($parentMessage->getId())` directly via `commentsManager->save()`, bypassing both methods. So do `ChatManager::editMessage()` (`:729`), `deleteMessage()` (`:664`), `pinMessage()` (`:809`), `unpinMessage()` (`:850`). See F4 |
| Revival hook at `updateLastMessageInfoAfterReply` reachable from both methods | CONFIRMED for content paths | `sendMessage` `:466` unconditional; `addSystemMessage` `:217` **inside** `if (!$shouldSkipLastMessageUpdate)` at `:211`. All four ASM content paths pass `false`, so the hook fires. Only lifecycle/tombstone messages pass `true` |

### B.3 Authority expression (AD-3)

| Claim | Verdict | Evidence |
| --- | --- | --- |
| Expression is *root-message author OR `hasModeratorPermissions(false)`* | CONFIRMED | `lib/Controller/ThreadController.php:224-228` |
| Degrades to moderators-only when the root comment cannot be loaded | CONFIRMED | `:216-222` — `NotFoundException` leaves `$isOwnMessage = false`; comment reads "Root message expired, only moderators can edit" |
| `(false)` excludes guest moderators | CONFIRMED | `lib/Participant.php:80-87` — `$guestModeratorAllowed = false` drops `GUEST_MODERATOR` |
| Returns `['error' => 'permission']` today (AD-4's "not folded into" target) | CONFIRMED | `lib/Controller/ThreadController.php:229` |

### B.4 Unread and `RoomFormatter` (AD-8)

| Claim | Verdict | Evidence |
| --- | --- | --- |
| `unreadMessages !== 0` gates `unreadMention` and `unreadMentionDirect` | CONFIRMED | `lib/Service/RoomFormatter.php:335-336` |
| `lastReadMessage === lastMessage` short-circuit immediately above | CONFIRMED | `lib/Service/RoomFormatter.php:325-330` |
| ...but there is only one such site | DRIFTED | Three more assignment sites: federated conversation `:310-313`, federated actor `:359-365` (**no gate**), lobby reset `:383-385`. See F11 |
| Unread-count cache key exists and survives upgrade | CONFIRMED | `lib/Chat/ChatManager.php:997` key `= roomId . '-' . lastReadMessage`; distributed cache `:134`; TTL 1800 `:1003` |
| Talk's `CommentsManager` already applies a `topmost_parent_id` filter in its *read* override | CONFIRMED | `lib/Chat/CommentsManager.php:101` `getCommentsWithVerbForObjectSinceComment()`, filter at `:132` |
| No counting override exists yet | CONFIRMED | `getNumberOfCommentsWithVerbsForObjectSinceComment` is called at `lib/Chat/ChatManager.php:1002` and is **not** overridden in `lib/Chat/CommentsManager.php` — only declared in `vendor/nextcloud/ocp/OCP/Comments/ICommentsManager.php:231` |
| Three `talk_attendees.has_unread_thread*` columns fully migrated | CONFIRMED | `lib/Migration/Version22000Date20250623142327.php:111-127` |
| ...with **zero callers** | DRIFTED | No value is ever read or written, but the plumbing is already in place: `lib/Model/Attendee.php:69-74, 150-152, 184-186`, `lib/Model/SelectHelper.php:112-114`, `lib/Model/AttendeeMapper.php:315-317`. See F10 |
| No migration needed for them | CONFIRMED | Columns and entity mapping both exist |

### B.5 Per-Thread read state (AD-6, AD-7)

| Claim | Verdict | Evidence |
| --- | --- | --- |
| `talk_thread_attendees` was created with `last_read_message`, `last_mention_message`, `last_mention_direct`, `read_privacy` | CONFIRMED | `lib/Migration/Version22000Date20250623142327.php:57-103` |
| ...and all four dropped in a later migration | CONFIRMED | `lib/Migration/Version22000Date20250710124258.php:48-60` |
| "three weeks later" | DRIFTED | 2025-06-23 → 2025-07-10 = **17 days**. See F12 |
| `findAttendeesForNotification()` filters on `notification_level` | CONFIRMED | `lib/Model/ThreadAttendeeMapper.php:111-114` — `neq(NOTIFY_DEFAULT)` |
| `getRecentByActor()` filters on `notification_level` | CONFIRMED | `lib/Service/ThreadService.php:158` — `neq(NOTIFY_NEVER)` |
| "a read-created row at `NOTIFY_DEFAULT` appears in **neither**" | **WRONG** | `NOTIFY_DEFAULT = 0`, `NOTIFY_NEVER = 3` (`lib/Participant.php:30, 33`). `getRecentByActor()` excludes only `NOTIFY_NEVER`, so a `NOTIFY_DEFAULT` row **does** appear. Behavioural proof: `tests/integration/features/chat-4/threads.feature:224` asserts a thread with `a.notificationLevel = 0` under "sees the following subscribed threads". `getRecentByActor()` backs `ThreadController::getSubscribedThreads()` (`lib/Controller/ThreadController.php:105-106`). See F1 |

### B.6 Deletion vs expiry (AD-5)

| Claim | Verdict | Evidence |
| --- | --- | --- |
| Deletion is a tombstone; row survives with `VERB_MESSAGE_DELETED` | CONFIRMED | `lib/Chat/ChatManager.php:640` `setVerb(self::VERB_MESSAGE_DELETED)` then `:652` `save()` — an update, never a delete |
| Expiry hard-deletes comment rows | CONFIRMED | `lib/Chat/ChatManager.php:1321-1323` → `deleteCommentsExpiredAtObject()`, documented as "Delete comments with field expire_date less than current date" in `vendor/nextcloud/ocp/OCP/Comments/ICommentsManager.php:483-491` |
| Nothing reaps orphaned `talk_threads` today | CONFIRMED | `lib/Model/ThreadMapper.php` exposes only `deleteByRoomId()` (`:113`); no expiry-driven caller anywhere in `lib/` |
| The expiry job runs every five minutes | CONFIRMED | `lib/BackgroundJob/ExpireChatMessages.php:26` — `setInterval(5 * 60)` |
| A tombstoned root still resolves for authority checks | CONFIRMED (implied, holds) | `ChatManager::getComment()` (`:946-958`) does not filter by verb, so the tombstone row loads |

### B.7 Client and routing (AD-15, AD-16)

| Claim | Verdict | Evidence |
| --- | --- | --- |
| A second factory `createMemoryRouter` exists and declares its own `conversation` route | CONFIRMED | `src/router/router.ts:94` (function), route declared at `:96-102` — `/call/:token`, name `conversation`, component `ChatView` (vs `MainView` in `createTalkRouter` at `:68-72`) |
| It is "the Files sidebar's factory" | DRIFTED | Four entry points use it: `src/mainFilesSidebar.js:23`, `src/mainPublicShareSidebar.js:69`, `src/mainPublicShareAuthSidebar.js:42`, `src/mainFloatingCall.ts:43`. See F15 |
| Addresses are query params, read through `useGetThreadId` | CONFIRMED | `src/composables/useGetThreadId.ts:14-21` (21-line file) — `createSharedComposable(useRouteQuery<...>('threadId', '0', …))`; 12 consumers |
| `useChatExtrasStore` owns Thread state; components never import `messagesService` | CONFIRMED | `src/stores/chatExtras.ts` — owns `threads` (`:55`) and `followedThreads` (`:56`), actions exported `:837-878`; no file under `src/components/` imports `services/messagesService` |
| AD-16's "no new named route, extend the query scheme" already has a working precedent | CONFIRMED (strengthening) | `src/components/RightSidebar/SearchMessages/SearchMessagesTab.vue:223-235` builds `to: { name: 'conversation', params: { token }, query: { threadId } }` — reusing the one route name **both** factories declare. This is the FR-19 handoff target and the exact pattern AD-16 mandates |

### B.8 Registries (AD-14)

| Claim | Verdict | Evidence |
| --- | --- | --- |
| Three lists in `src/utils/message.ts` | CONFIRMED | `SYSTEM_MESSAGE_TYPE_RELAY:44`, `SYSTEM_MESSAGE_TYPE_UNTRANSLATED:70`, `SYSTEM_MESSAGE_TYPE_HIDDEN:83` — `thread_created`/`thread_renamed` appear in all three (`:50-51`, `:76-77`, `:90-91`) |
| `SYSTEM_MESSAGE_TYPE_RELAY` in `lib/Signaling/Listener.php` | CONFIRMED | `:81-101`, consumed at `:571` |
| Parser in `lib/Chat/Parser/SystemMessage.php` | CONFIRMED | `thread_created` `:587`, `thread_renamed` `:597` |
| Constant in `src/constants.ts` | CONFIRMED | `:280` `THREAD_RENAMED` |
| "all **five** registries" | DRIFTED (arithmetic) | The sentence enumerates six items. See F9 |
| The enumeration is complete | **WRONG** | A **seventh** gate exists and is decisive: `lib/Signaling/Listener.php:557-560` returns early when `shouldSkipLastActivityUpdate()` is true unless the verb is in the inline array `['message_deleted', 'message_edited', 'thread_created', 'thread_renamed']`. `ThreadController::renameThread()` passes `shouldSkipLastMessageUpdate = true` (`lib/Controller/ThreadController.php:253`) — `thread_renamed` relays **only** because it is hard-coded at `:558`. See F2 |
| Relay-list membership is what makes a change visible without reload | DRIFTED | Non-relay verbs still emit `{'chat': {'refresh': true}}` (`lib/Signaling/Listener.php:571-574`). The relay list controls whether the parsed message body rides along, not whether anything is sent. The true "silent drop" is the `:558` gate. See F17 |

### B.9 Tags (AD-10, AD-11)

| Claim | Verdict | Evidence |
| --- | --- | --- |
| Every `ConversationTagService` method is keyed by `userId` | CONFIRMED | `lib/Service/ConversationTagService.php:82, 86, 95, 151, 172, 181` |
| The three exception classes exist | CONFIRMED | `:12-14` |
| `MAX_TAG_IDS_PER_CONVERSATION = 20` | CONFIRMED (value) | `:27`. But it caps *assignments per conversation*, not vocabulary size; the vocabulary ceiling is `MAX_CUSTOM_TAGS_PER_USER = 100` (`:24`). See F16 |
| `updateTag`/`deleteTag` are the v2 surface to copy | CONFIRMED | `:151`, `:172` |
| Copying `normalizeTagName()` gives AD-11's fold | **WRONG** | `lib/Service/ConversationTagService.php:190-196` is `trim()` + a length check. **No case-fold, no diacritic-fold**, and it is `private`. See F3 |

### B.10 API surface and federation (AD-12, AD-18)

| Claim | Verdict | Evidence |
| --- | --- | --- |
| `GET .../threads/recent` exists | CONFIRMED | `lib/Controller/ThreadController.php:74` — `/api/{apiVersion}/chat/{token}/threads/recent` |
| Composed notification object id is positional | CONFIRMED | `lib/Notification/Notifier.php:638-641` — `objectId . '/' . messageId`, then appended `. '/' . threadId` |
| An existing integration assertion pins it | CONFIRMED | `tests/integration/features/chat-4/threads.feature:86, 93, 98, 223` assert exactly `room/Message 1-2/Thread 1` etc. |
| The shipped `threads` flag is unconditional | CONFIRMED | `lib/Capabilities.php:130` is in `FEATURES`, not `CONDITIONAL_FEATURES` (`:140-146`) and not `LOCAL_FEATURES` (`:148-173`) |
| `pinned-messages` ships (so "Featured", not "pinned") | CONFIRMED | `lib/Capabilities.php:131` |
| `openapi*.json` and `src/types/openapi/*.ts` exist and are regenerated | CONFIRMED | 8 JSON specs at repo root, 8 `.ts` under `src/types/openapi/`, CI gate `.github/workflows/openapi.yml` |
| Upstream carries explicit not-supported markers on both thread message-fetch paths | CONFIRMED | `lib/Controller/ChatController.php:898` and `:1273` — `// FIXME support threads in federation $threadId,` |

### B.11 Fork hygiene and process (AD-17, AD-19, Tests)

| Claim | Verdict | Evidence |
| --- | --- | --- |
| The clone is depth-1 | CONFIRMED | `git rev-list --count HEAD` = `1`; `.git/shallow` present |
| `ThreadService` has no PHPUnit test today | CONFIRMED | `find tests -iname "*Thread*"` returns only `tests/integration/features/chat-4/threads.feature` |
| `tests/integration/features/chat-4/threads.feature` exists and is the place to extend | CONFIRMED | 327 lines |
| AD-19 "**No data backfill**" | **WRONG (internally)** | The same AD then mandates seeding the thread-read baseline "from each participant's existing conversation read marker" — a table-wide `UPDATE` on `talk_attendees`, which is the exact operation the AD's *Prevents* clause forbids. See F6 |
| Upstream has not announced thread close/lock lifecycle work | CONFIRMED (best effort) | No upstream issue or release note found for thread close/lock/state. Adjacent open work exists: `nextcloud/spreed#17146` (Feb 2026, "Thread deletion is impossible — threads persist even if all their messages were deleted"). See F18 |
| Clavis push proxy APNs/FCM hop unimplemented | UNVERIFIABLE here | Lives outside this repository |
| "no product telemetry flowing back" | UNVERIFIABLE here | Deployment-side claim |

---

## C. Findings

### Blocker — an AD rests on a false premise

**F1 — AD-7's mandated test cannot pass, and the defect it claims is absent is present.**
AD-7 asserts `findAttendeesForNotification()` and `getRecentByActor()` "continue to filter on `notification_level` explicitly, and each carries a test asserting that a read-created row at `NOTIFY_DEFAULT` appears in neither." They filter on different predicates: `neq(NOTIFY_DEFAULT)` at `lib/Model/ThreadAttendeeMapper.php:113` vs `neq(NOTIFY_NEVER)` at `lib/Service/ThreadService.php:158`. Since `NOTIFY_DEFAULT = 0` and `NOTIFY_NEVER = 3`, a `NOTIFY_DEFAULT` row **is** returned by `getRecentByActor()`, which backs the Subscribed/Followed Thread List (`lib/Controller/ThreadController.php:105`). `tests/integration/features/chat-4/threads.feature:224` already asserts a `notificationLevel = 0` thread appearing there. Upstream's own comments ("Add to subscribed threads list", `lib/Chat/ChatManager.php:221`, `:466`) show `NOTIFY_DEFAULT` **means** subscribed-by-participation.
Consequence: AD-6's "write a row only when a participant reads that Thread" would silently enrol every read Thread into every participant's Followed Thread List — precisely the failure AD-7 exists to prevent.
**Fix:** AD-7 must state the asymmetry and choose a mechanism that does not overload `notification_level` — e.g. a distinct `NOTIFY_NONE`/`read-only` sentinel, or a separate `subscribed` flag, with `getRecentByActor()` changed to an explicit allow-list of subscribed levels. Then the two tests are writable.

**F2 — AD-14's registry list misses the gate that actually silences a lifecycle message.**
AD-14 enumerates the registries a new system-message verb must touch. It omits `lib/Signaling/Listener.php:557-560`:
```php
if ($event->shouldSkipLastActivityUpdate() === true
    && !in_array($messageType, ['message_deleted', 'message_edited', 'thread_created', 'thread_renamed'], true)
) {
    return;
}
```
Lifecycle messages will follow the `thread_renamed` pattern, which passes `shouldSkipLastMessageUpdate = true` (`lib/Controller/ThreadController.php:253`) precisely so a rename does not bump conversation activity. A new verb emitted that way and **not** added to this inline array is dropped before the relay list is ever consulted — no signalling message at all, not even a refresh. This is exactly AD-14's stated failure mode, sitting in a registry AD-14 does not name.
**Fix:** add `lib/Signaling/Listener.php:558` to the registry list (and note the `threadInfo` enrichment block at `:621-635` if lifecycle payloads should carry thread data). Also make the list a named constant rather than an inline array, so it is greppable.

**F3 — AD-10 tells the implementer to copy a helper that does not do what AD-11 requires.**
AD-10: "What is copied: its `normalizeTagName()`". AD-11: "One normalisation function (trim, case-fold, diacritic-fold)". Reality — `lib/Service/ConversationTagService.php:190-196`:
```php
private function normalizeTagName(string $name): string {
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > self::MAX_TAG_NAME_LENGTH) {
        throw new InvalidTagNameException();
    }
    return $name;
}
```
Trim only. No case fold, no diacritic fold — and it is `private`, so it cannot be reused without editing an upstream file (against AD-17). Copying it verbatim yields exactly the defect AD-10's *Prevents* clause names: "case or diacritic variants becoming distinct tags."
**Fix:** AD-10 should say the *shape* is copied but the fold is new, and AD-11 should own the single implementation (name it, say where it lives, and say it serves both `talk_threads.name` and tag names). Note that AD-11's fold must be PHP-side (`mb_strtolower` + a transliteration step), since it explicitly must not rely on collation.

### Major

**F4 — AD-2's "the only place Thread write policy is enforced" is false, and it makes the reactions deferral wrong.**
`lib/Chat/ReactionManager.php:81` creates a comment with `setParentId($parentMessage->getId())` and calls `commentsManager->save()` directly — never touching `sendMessage`/`addSystemMessage`. So do `ChatManager::editMessage()` (`:729`), `deleteMessage()` (`:664`), `pinMessage()` (`:809`), `unpinMessage()` (`:850`). All five are writes into a Thread that a Locked gate at the two named methods will not see. The Deferred item says "AD-2 makes it a one-line change to the exemption either way" for reactions — it does not; enforcing Locked for reactions needs a third enforcement point in `ReactionManager`.
**Fix:** scope AD-2 honestly — "the choke point for *posting new content*" — and either add `ReactionManager::addReactionMessage()` as a named third seam or state explicitly that reactions, edits, deletes and pins are out of Locked's scope by design. Correct the Deferred item's cost estimate.

**F5 — The Database row omits Oracle, which this repo's own CI tests on every PR.**
`.github/workflows/phpunit-oci.yml` runs `on: pull_request`; `.github/workflows/integration-oci.yml` runs nightly. Oracle changes the answer for AD-11 (case/accent-insensitive comparison and normalised-column indexing), AD-19 (additive-migration behaviour and identifier length limits) and AD-9 (grouped queries with `setMaxResults`). A stack table that says "MySQL/MariaDB, PostgreSQL or SQLite" tells an implementer they may ignore a database the merge gate will fail on.
**Fix:** add Oracle to the row; add a line to the Tests convention that new queries and migrations must survive the oci workflow.

**F6 — AD-19 contradicts itself: "No data backfill" then mandates a table-wide backfill.**
AD-19's *Prevents* clause is "an in-place upgrade that locks a large customer table with no maintenance window." Its rule says "**No data backfill**" and then: "The single initialisation that does happen is AD-6's thread-read baseline, seeded from each participant's existing conversation read marker." Seeding from `talk_attendees.last_read_message` requires a full-table `UPDATE` on the largest per-user table in Talk — the operation the AD forbids one sentence earlier.
**Fix:** resolve it. Either (a) add the column with a sentinel default and resolve the baseline lazily at read time (`COALESCE(thread_read_baseline, last_read_message)`), which costs nothing at upgrade, or (b) keep the seed but state it as a chunked background job, not a migration step, and say so in the rule rather than in a subordinate clause.

**F7 — AD-2's write-path 8 does not reach `ChatManager`.**
`ChatController::scheduleMessage()` (`lib/Controller/ChatController.php:527-593`) validates the thread (`:563`) and writes a scheduled-message row via `scheduledMessageManager->scheduleMessage()`. No `ChatManager` call. The diagram's `P8 --> SM` edge does not exist in the code, and path 8 and path 9 are the same eventual write.
**Fix:** relabel path 8 as a *precondition* check, not a write path — and note the genuinely interesting consequence, which the spine currently misses: a Thread Locked **after** acceptance but **before** firing is caught only at `SendScheduledMessages`, where the failure is swallowed into `markAsFailed()` (`lib/BackgroundJob/SendScheduledMessages.php`, `catch (\Exception)`). AD-4's "typed and distinguishable refusals" needs to say what the user sees in that case. The same applies to path 3: `lib/Service/BotService.php:336` catches `\Exception` and only logs, so a `ThreadLocked` refusal is invisible to the bot.

### Minor

**F8 — AD-1's cache rule is unachievable as absolutely stated, and it papers over a real ordering bug.**
"Every `ThreadService` mutator ends by re-writing the cache entry … not by removing it" cannot hold for `deleteByRoom()` (`lib/Service/ThreadService.php:271`) or for AD-5's new reaper. Separately, `updateLastMessageInfoAfterReply` calls `$this->cache->remove(...)` at `:266` **before** `executeStatement()` at `:267` — a concurrent reader can repopulate the entry with the pre-update row between the two. If AD-1 is going to legislate cache discipline, that ordering is the bug worth naming.
**Fix:** phrase the rule as "mutators that produce a Thread re-set from the fresh entity; mutators that destroy one remove", and add "cache writes happen after the database write commits."

**F9 — "all five registries" enumerates six items.** `src/constants.ts` (1) + three lists in `src/utils/message.ts` (3) + `Signaling/Listener.php` relay (1) + `Chat/Parser/SystemMessage.php` (1) = 6. With F2 it becomes 7. An AD whose whole job is an exhaustive checklist should not miscount its own checklist.

**F10 — "currently-uncalled" understates what already exists for `has_unread_thread*`.** No value is read or written, but entity properties, magic accessors, `addType` registration, `SelectHelper` selection and `AttendeeMapper` hydration are all in place (`lib/Model/Attendee.php:69-74, 150-152, 184-186`; `lib/Model/SelectHelper.php:112-114`; `lib/Model/AttendeeMapper.php:315-317`). Say "migrated and mapped, never read or written" — it makes AD-8's "writing their callers is the whole of that storage work" more credible, not less.

**F11 — AD-8 speaks of "the" gate; `RoomFormatter` has four assignment sites.** `:310-313` (federated conversation, taken from the attendee), `:335-336` (local, gated), `:359-365` (federated actor, **ungated**), `:383-385` (lobby reset). Removing the gate at `:335-336` alone leaves `:359-365` on different semantics. That may be the intent under AD-18, but the spine should say so rather than leave it to be discovered.

**F12 — "created and dropped three weeks later" is 17 days.** `Version22000Date20250623142327` → `Version22000Date20250710124258`. Harmless, but this is a document that trades on precision.

**F13 — The Behat + PHPUnit row is vaguer than the repo.** Behat `^3.31.0` and PHPUnit `^11.5` (`tests/integration/composer.json`); PHPUnit `^11.5` also in `vendor-bin/phpunit/composer.json`. They are not "vendored in `tests/`" — PHPUnit lives under `vendor-bin/`. Give the versions; the table's purpose is versions.

**F14 — Four load-bearing entries missing from the Stack table.**
- `@vueuse/core` / `@vueuse/router` `^14.3.0` (`package.json:52-53`) — **AD-16 depends on these directly**.
- `nextcloud/ocp` `dev-stable34` (`composer.json` require-dev) — the entire server API surface AD-1 (`ICacheFactory`) and AD-8 (`ICommentsManager`) compile against, and it tracks a moving branch.
- Node `^24.0.0` / npm `^11.3.0` (`package.json:115-116`) — a hard build prerequisite.
- `nextcloud/openapi-extractor` `^1.8` (`vendor-bin/openapi-extractor/composer.json`) — AD-12 mandates regenerating OpenAPI in the same change; name the tool. (Strengthening: `.github/workflows/openapi.yml` already enforces it, which is worth citing in AD-12.)

**F15 — `createMemoryRouter` is not only the Files sidebar's factory.** Also `mainPublicShareSidebar.js:69`, `mainPublicShareAuthSidebar.js:42`, `mainFloatingCall.ts:43`. AD-16's acceptance condition should name four surfaces, not one.

**F16 — `MAX_TAG_IDS_PER_CONVERSATION = 20` is the wrong analogue for a room-scoped vocabulary.** It caps tags *assigned to* one conversation (`lib/Service/ConversationTagService.php:27, 203`); the vocabulary ceiling is `MAX_CUSTOM_TAGS_PER_USER = 100` (`:24`). AD-10 creates a room-scoped tag *vocabulary*, so it needs to say which of the two limits it is reusing and for what. The Deferred item's "twenty-tag ceiling" trigger inherits the ambiguity.

**F17 — The relay list does not decide whether participants see a change without reloading.** Non-relay verbs still send `{'chat': {'refresh': true}}` (`lib/Signaling/Listener.php:571-574`); the list decides whether the parsed comment rides along. The claim is right about the *symptom* but points at the wrong mechanism — and the actual silent-drop is F2's gate.

**F18 — Upstream has open, adjacent thread work worth noting under AD-17.** `nextcloud/spreed#17146` (opened Feb 2026) reports that thread deletion is impossible and that threads persist after all their messages are deleted. That is the same territory as AD-5's reaper. No upstream close/lock lifecycle work was found, so AD-17's assumption holds — but a fork-owned reaper is a plausible future merge collision and should be called out in AD-17's marked-diff discipline.

**F19 — AD-14's "touch all three lists" needs a caveat.** `SYSTEM_MESSAGE_TYPE_HIDDEN` means "not shown separately in the chat" (`src/utils/message.ts:81-82`). `thread_created`/`thread_renamed` are hidden, so the precedent supports the rule — but if the PRD wants "X closed this thread" *visible* in the stream, adding lifecycle verbs to HIDDEN is wrong. Say "classify in all three; classification, not blanket inclusion."

**F21 — The "Where the work lands" tree misfiles the thread header.** `ThreadHeader.vue` lives at `src/components/RightSidebar/Threads/ThreadHeader.vue` but renders in `src/components/TopBar/TopBar.vue:54` and `src/components/ChatView.vue:27` (the `standalone` sidebar variant) — never in the right sidebar. AD-15 names "the thread header" as one of three surfaces that must not diverge, and the spine's tree points an implementer at `components/.../Threads/` as though that were where the header renders. Add a note, or the AD-15 work gets done in the wrong component.

**F20 — Path-3 and path-9 refusals are swallowed.** `lib/Service/BotService.php:336` catches `\Exception` and logs; `SendScheduledMessages` catches `\Exception` and calls `markAsFailed()`. AD-4 promises distinguishable refusals; on those two paths there is no user-visible refusal at all. Cross-references F7.

---

## D. What this review confirms most strongly

Worth recording, because it is the part an epic can build on without re-verification:

- The **AD-1 six-place field checklist** is exact. `Thread::addType/createFromRow/fromJson/toJson/toArray` and `SelectHelper::selectThreadsTable()`'s two branches are all real and all as described.
- The **cache shape** — distributed, `thread/{roomId}/{threadId}`, 900s, negative entries — is exact.
- The **AD-3 authority expression and its degradation** are exact, down to the `hasModeratorPermissions(false)` guest-moderator exclusion.
- **AD-5's tombstone/expiry distinction** is exact, including that nothing reaps `talk_threads` and that the expiry job is a five-minute `TimedJob`.
- **AD-12's positional notification object id** is exact and already pinned by four integration assertions.
- **Path 7's missing `validateThread()`** is exact, and it is a genuine hole today, not a hypothetical.
- **AD-16's second router factory** is exact, including that it declares a same-named `conversation` route with a different component.
- The **shallow clone** is exact — `git rev-list --count HEAD` = 1.
