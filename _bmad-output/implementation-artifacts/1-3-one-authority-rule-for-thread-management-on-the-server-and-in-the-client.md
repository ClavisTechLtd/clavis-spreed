---
baseline_commit: 6819859953c32f32ccaecf13f4b11f1ad382db00
---

# Story 1.3: One authority rule for Thread management, on the server and in the client

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a member who started a Thread,
I want authority over my own Thread without being made a moderator,
So that management follows the rule renaming already uses instead of inventing a second one.

## Acceptance Criteria

**AC1**
**Given** the authority expression currently inline in `ThreadController::renameThread()`
**When** it is lifted into `ThreadService::isThreadManager(Thread, Participant): bool`
**Then** that single method holds *root-message author OR `hasModeratorPermissions(false)`*
**And** no endpoint re-derives the expression anywhere else in the codebase.

**AC2**
**Given** `isThreadManager()` needs to load the root comment
**When** the dependency is wired
**Then** it takes the low-level `CommentsManager` rather than `ChatManager`, which already injects `ThreadService`
**And** the object-type/object-id check that lookup needs lives in one private helper beside it.

**AC3**
**Given** a Thread whose root comment cannot be loaded
**When** authority is evaluated
**Then** only moderators qualify, preserving the degradation upstream already implements.

**AC4**
**Given** `renameThread` refactored to call the shared method
**When** the existing thread integration coverage runs
**Then** renaming behaviour and permissions are identical before and after, verified by that coverage passing unchanged. *(FR-11)*

**AC5**
**Given** three distinct failure causes — Thread is Locked, actor lacks authority, Thread does not exist
**When** each is triggered
**Then** each carries its own exception class and its own OCS error identifier in the existing `['error' => '<identifier>']` convention
**And** Locked is **not** folded into the existing `permission` value.

**AC6**
**Given** the `TalkThreadInfo` representation gains a field stating whether the current actor may manage this Thread
**When** the client store reads a Thread
**Then** exactly one computed getter exposes that answer and every later control consumes it
**And** components never call the service layer to ask.

**AC7**
**Given** a member who is not a moderator, viewing a list containing both a Thread they started and a Thread someone else started
**When** the existing rename control is rendered
**Then** it appears on their own Thread and is **absent — not disabled —** on the other, within the same list. *(FR-10)*

**AC8**
**Given** a Conversation moderator viewing the same list
**When** the controls are rendered
**Then** they appear on every Thread in the Conversation.

**AC9**
**Given** a guest session
**When** any management operation is attempted on any Thread
**Then** it is refused regardless of who created the Thread.

**AC10**
**Given** a member who is demoted from moderator between opening a menu and using it
**When** the request arrives
**Then** it is refused on the request rather than at session end, because authority is evaluated per request.

## Tasks / Subtasks

- [x] **Task 1 — `ThreadService::isThreadManager()`, the single authority implementation (AC1, AC2, AC3, AC9, AC10)**
  - [x] 1.1 Read `lib/Controller/ThreadController.php::renameThread()` (lines ~200-262, especially the `$isOwnMessage`/`hasModeratorPermissions(false)` block at ~214-228), `lib/Service/ThreadService.php` in full, `lib/Chat/ChatManager.php::getComment()` (~946-958, the object-type/object-id precedent to mirror), `lib/Participant.php` (`hasModeratorPermissions()` ~80-87, `isGuest()` ~66-69), `lib/Model/Attendee.php` (actor type constants), and `vendor/nextcloud/ocp/OCP/Comments/ICommentsManager.php::get()` (confirms `@throws NotFoundException`) before writing anything — see Dev Notes "Current state of the files this story touches".
  - [x] 1.2 Add a **failing** PHPUnit test first (red) in `tests/php/Service/ThreadServiceTest.php` for the moderator case, the root-author case, the neither case, and the guest/bot exclusion case (see Task 7 for the full test list) — written against the not-yet-existing `isThreadManager()` method, so it cannot pass yet.
  - [x] 1.3 Add `ThreadService::isThreadManager(Thread $thread, Participant $participant): bool` (green): guests (`Participant::isGuest()` — covers both `GUEST` and `GUEST_MODERATOR`) and bots (`Attendee::getActorType() === Attendee::ACTOR_BOTS`) return `false` immediately, regardless of authorship (AC9). Otherwise: `hasModeratorPermissions(false)` true → `true`. Otherwise: load the root comment via the new private helper (Task 1.4); if it cannot be loaded, `false` (AC3 — only the moderator branch above still qualifies); if loaded, `true` iff its `actorType`/`actorId` match the participant's attendee.
  - [x] 1.4 Add `private function getRootComment(Thread $thread): IComment` beside it (AC2): calls `$this->commentsManager->get((string)$thread->getId())` (Thread id **is** the root comment id, per AD-5/Consistency Conventions) and mirrors `ChatManager::getComment()`'s object-type/object-id check (`getObjectType() !== 'chat' || getObjectId() !== (string)$thread->getRoomId()`), throwing `\OCP\Comments\NotFoundException` on mismatch exactly as `ChatManager::getComment()` does — without depending on `ChatManager` itself, since `ChatManager` already injects `ThreadService` and the reverse edge would be a container cycle (AD-3).
  - [x] 1.5 Wire the new constructor dependency: `private readonly \OCA\Talk\Chat\CommentsManager $commentsManager` (the low-level one `ChatManager` itself uses — **not** `ChatManager`). No explicit DI registration needed; Nextcloud autowires concrete app classes the same way `ChatManager`'s own dependencies are wired.
  - [x] 1.6 Refactor (still green): re-run the Task 1.2 tests, confirm they pass without modification.
  - [x] 1.7 `php -l` and `psalm` on `lib/Service/ThreadService.php`.

- [x] **Task 2 — Typed authority refusal: `AuthorityException` + `ThreadService::ensureThreadManager()` (AC1, AC5)**
  - [x] 2.1 Read `lib/Exceptions/RoomProperty/TypeException.php` and `lib/Exceptions/ThreadProperty/StateException.php` (Story 1.2's precedent, identical shape) before writing.
  - [x] 2.2 Add a failing test in `ThreadServiceTest.php` asserting `ensureThreadManager()` throws `AuthorityException` with `getReason() === AuthorityException::REASON_PERMISSION` when `isThreadManager()` would return false, and does not throw when it would return true (red — class does not exist yet).
  - [x] 2.3 Add `lib/Exceptions/ThreadProperty/AuthorityException.php`, mirroring `StateException`'s shape exactly: `extends \InvalidArgumentException`, `public const REASON_PERMISSION = 'permission';` — reusing the string value `'permission'` that `renameThread()` already returns today, so AC4's "identical before and after" holds byte-for-byte on the wire. This is the "actor lacks authority" identifier AD-4 keeps distinct from a future Locked identifier ("Locked is not folded into `permission`") and AC5 requires to exist as its own exception class.
  - [x] 2.4 Add `ThreadService::ensureThreadManager(Thread $thread, Participant $participant): void` — throws `AuthorityException(AuthorityException::REASON_PERMISSION)` when `!$this->isThreadManager(...)`. This is the throw-and-catch seam every future management endpoint (Story 1.4's four transitions) reuses, matching the `RoomService::setType()` → `TypeException` → `RoomController` catch precedent, and matching Story 1.2's `validateState()` precedent of building the seam ahead of every caller.
  - [x] 2.5 Green: run the Task 2.2 test, confirm it passes.
  - [x] 2.6 `php -l` and `psalm` on the new exception file and `lib/Service/ThreadService.php`.
  - [x] 2.7 Document in Dev Notes / Completion Notes (see "Assumption — AC5's Locked branch" below) that the third failure cause named in AC5 — Thread is Locked — is **not** built in this story: nothing enforces Locked yet (that is Stories 1.6/1.7's AD-2 seam), so there is nothing to throw it. "Thread does not exist" already has its own distinct class (`\OCP\AppFramework\Db\DoesNotExistException`, mapped to `['error' => 'thread']`) and is unchanged. This story establishes the *authority* identifier Story 1.4 AC7 explicitly reuses ("refused with the authority error identifier from Story 1.3").

- [x] **Task 3 — Refactor `ThreadController::renameThread()` onto the shared seam (AC1, AC4, AC5)**
  - [x] 3.1 Replace the inline `$attendee = $this->participant->getAttendee(); $isOwnMessage = false; try { ... } catch (NotFoundException) { ... } if (!$isOwnMessage && !hasModeratorPermissions(false)) { return ['error' => 'permission'], 403 }` block (current lines ~214-228) with:
    ```php
    try {
        $this->threadService->ensureThreadManager($thread, $this->participant);
    } catch (AuthorityException $e) {
        return new DataResponse(['error' => $e->getReason()], Http::STATUS_FORBIDDEN);
    }
    ```
    Add `use OCA\Talk\Exceptions\ThreadProperty\AuthorityException;` to the controller's imports. Leave the **second**, unrelated `$comment = $this->chatManager->getComment(...)` call later in the method (used as the system message's reply-target after the rename succeeds) untouched — it serves a different purpose and is not part of the authority check.
  - [x] 3.2 Confirm the `catch (NotFoundException)` import is still needed for that second, remaining `getComment()` call (it is) — do not remove the `use OCP\Comments\NotFoundException;` import from the controller.
  - [x] 3.3 `php -l` and `psalm` on `lib/Controller/ThreadController.php`.

- [x] **Task 4 — `canManage` on `TalkThreadInfo` (AC6)**
  - [x] 4.1 Read `lib/ResponseDefinitions.php`'s `TalkThreadInfo` psalm-type (~746-755) and `ThreadController::prepareListOfThreads()` (~269-325, the single choke point all four Thread-returning endpoints share) before editing — this field is **not** a persisted `Thread` entity column (unlike Story 1.2's `state`), it is a per-request, per-actor computed value, so it must **not** be added to `Thread::addType()`/`createFromRow()`/`fromJson()`/`toJson()`/`toArray()` or cached — doing so would leak one participant's authority answer to every other participant reading the same Thread through the shared 900s distributed cache (NFR-4). It belongs on `TalkThreadInfo` itself (sibling of `thread`/`attendee`/`first`/`last`), assembled fresh in `prepareListOfThreads()`.
  - [x] 4.2 Add `canManage: bool,` to the `TalkThreadInfo` psalm-type in `lib/ResponseDefinitions.php`, positioned after `attendee`, with a doc comment.
  - [x] 4.3 In `ThreadController::prepareListOfThreads()`, add `'canManage' => $this->threadService->isThreadManager($thread, $participant),` to the `$list[]` array built per thread (after `'attendee' => ...`), reusing the `$participant` already resolved earlier in that loop iteration. Document the known N+1-shaped cost (see Dev Notes "Known tradeoff — per-row authority query") right at this call site as a code comment.
  - [x] 4.4 Determine which generated `openapi*.json` declare `ThreadInfo` (Story 1.2 already established: only `openapi.json` and `openapi-full.json`). Add `"canManage": {"type": "boolean", "description": "..."}` to `components.schemas.ThreadInfo.properties` in both, and add `"canManage"` to `ThreadInfo`'s `required` array.
  - [x] 4.5 Add the matching `canManage: boolean;` member (with JSDoc) to the `ThreadInfo` interface in `src/types/openapi/openapi.ts` and `src/types/openapi/openapi-full.ts`.
  - [x] 4.6 Verify the manual JSON/TS edits against the project's real `generate-spec` / `openapi-typescript` tools, following Story 1.2's precedent exactly: run `generate-spec` in the `clavis-deploy-nextcloud-1` container against a **container-local writable copy** of the repo (never through the read-only bind mount), diff against the committed `openapi*.json`, reconcile; run `npx openapi-typescript -t` natively on the host for the `.ts` files. Record results in Dev Agent Record.
  - [x] 4.7 `php -l` and `psalm` on `lib/ResponseDefinitions.php` and `lib/Controller/ThreadController.php`.

- [x] **Task 5 — One client-side getter for the authority answer (AC6)**
  - [x] 5.1 Read `src/stores/chatExtras.ts` in full (existing `getThread`/`getThreadsList`/`addThread`/`updateThread` functions), `src/components/RightSidebar/Threads/ThreadHeader.vue`, and `src/components/RightSidebar/Threads/ThreadItem.vue` before editing — both components currently **independently** re-derive an `isModeratorOrOwner` computed (different logic in each: `ThreadHeader` uses `store.getters.isModerator` + `actorStore` comparison against `currentThread.first`; `ThreadItem` uses a Vuex `conversation` lookup + `PARTICIPANT.TYPE.*` comparison), which is exactly the "component calls the service layer to ask" pattern AC6 forbids and the "no endpoint re-derives the expression" spirit of AC1 extended to the client.
  - [x] 5.2 Add `function canManageThread(token: string, threadId: number): boolean { return threads.value[token]?.[threadId]?.canManage ?? false }` to `useChatExtrasStore` (`src/stores/chatExtras.ts`) and export it from the store's returned object. This is the **one** computed-getter seam AC6 requires — components read this, never Vuex `isModerator`/`conversation` state or their own actor comparison, for thread-management authority.
  - [x] 5.3 Update `updateThread()` (chatExtras.ts, ~344-361) to also carry `canManage` through a partial update: `canManage: payload.canManage ?? threads.value[token][threadId].canManage,` — otherwise a partial `updateThread()` call would silently drop the field for an already-loaded Thread, the client-side analogue of AD-1's "field missing from X vanishes" checklist.
  - [x] 5.4 In `ThreadHeader.vue`: replace the `isModeratorOrOwner` computed with `const canManageThread = computed(() => chatExtrasStore.canManageThread(token.value, threadId.value))`; update the template's `v-if="isModeratorOrOwner"` to `v-if="canManageThread"`. Remove now-unused `useActorStore`/`useStore` imports and the `actorStore`/`store` consts **only if** nothing else in the file still uses them (re-check `threadNotification`/`PARTICIPANT` usage — `PARTICIPANT` import stays, it is still used by `threadNotification`).
  - [x] 5.5 In `ThreadItem.vue`: same replacement — `const canManageThread = computed(() => chatExtrasStore.canManageThread(thread.thread.roomToken, thread.thread.id))`; `v-if="isModeratorOrOwner"` → `v-if="canManageThread"`. Remove the now-unused `useStore`/`useActorStore` imports/consts and the `PARTICIPANT` import if nothing else in the file references it (check `AVATAR` import stays — still used for icon sizing).
  - [x] 5.6 `npm run lint` and `npm run ts:check` after both component edits.

- [x] **Task 6 — Tests (AC1–AC10, TDD red-green-refactor) — consolidated list, individual red/green steps already called out per-task above**
  - [x] 6.1 `tests/php/Service/ThreadServiceTest.php`: extend the Story 1.2 file. Constructor mock gains `CommentsManager&MockObject $commentsManager` (stub `createDistributed` already present; add the new dependency to the `new ThreadService(...)` call). Cases: moderator (true, `hasModeratorPermissions(false)` mocked true, `commentsManager` **not** expected to be called — the cheap short-circuit); root-message author, non-moderator (true — `commentsManager->get()` returns a matching `IComment` mock); neither author nor moderator (false); guest attendee who authored the root message (false — AC9, proves guest exclusion overrides authorship); bot attendee (false); root comment `get()` throws `NotFoundException`, actor non-moderator (false — AC3); root comment `get()` throws `NotFoundException`, actor moderator (true — AC3, the moderator branch alone still qualifies, comment lookup never reached); root comment loaded but `getObjectType()`/`getObjectId()` mismatch (false, exercises the private helper's own check). `ensureThreadManager()`: throws `AuthorityException` with `REASON_PERMISSION` on refusal; does not throw on success.
  - [x] 6.2 `tests/php/Service/ThreadServiceTest.php`: add a case proving distinctness (mirrors the existing `testValidateStateRefusalIsDistinctFromOtherThreadExceptionTypes` pattern) — `AuthorityException` is not an instance of `StateException` and vice versa, and both are distinct from `DoesNotExistException`.
  - [x] 6.3 `src/stores/__tests__/chatExtras.spec.js`: extend with a `describe('thread management authority')` block — `canManageThread()` returns `false` for an unknown token/threadId; returns the stored `canManage` value once `addThread()` has stored a `ThreadInfo` with `canManage: true`/`false`; `updateThread()` on an already-loaded Thread preserves `canManage` when the partial payload omits it, and overwrites it when the payload includes it.
  - [x] 6.4 Trace (cannot execute — see Dev Notes → Environment constraints) that `tests/integration/features/chat-4/threads.feature`'s "Non moderators can only rename their own threads" and the moderator-rename scenarios (~50-68) produce identical `200`/`403` outcomes under the refactored `renameThread()` — verified by manual reasoning in Dev Notes "AC4 regression trace", since no guest actor appears in any existing rename scenario (confirmed by `grep -n "guest" tests/integration/features/chat-4/threads.feature` returning nothing), so the new guest-exclusion branch cannot regress existing coverage.
  - [x] 6.5 `php -l` and `psalm` on every new/changed PHP test file.

- [x] **Task 7 — Regression pass and housekeeping**
  - [x] 7.1 Re-run `grep -rn "isThreadManager\|hasModeratorPermissions(false)" lib/` to confirm `renameThread()` is the only call site touched and no other inline authority expression was missed (AC1's "no endpoint re-derives the expression").
  - [x] 7.2 `git status` / `git diff --stat` to confirm the File List below is exhaustive and no unrelated file was touched.
  - [x] 7.3 `npm run lint`, `npm run ts:check`, and (if time/stability allow in this sandbox) `npm run test -- chatExtras` for the JS-side changes.
  - [x] 7.4 Update Dev Agent Record, File List, Change Log; set Status to `review`.

## Dev Notes

### Current state of the files this story touches (read in full during story creation)

**`lib/Controller/ThreadController.php`** — `renameThread()` (~200-262) is the **only** place the authority expression exists today: it fetches the root comment via `ChatManager::getComment()`, compares actor type/id to determine `$isOwnMessage`, then refuses with `['error' => 'permission']`, 403 unless `$isOwnMessage || hasModeratorPermissions(false)`. `prepareListOfThreads()` (~269-325) is the single choke point all four Thread-returning endpoints (`getRecentActiveThreads`, `getSubscribedThreads`, `getThread`, `renameThread`) share — confirmed unchanged since Story 1.2, which relied on the same fact for `state`. Building `$list[]` there already has `$participant` resolved per iteration (from `$participants[$roomId]` for the single-room case, or freshly fetched via `participantService->getParticipant()` for the multi-room `getSubscribedThreads()` case) and the batch-fetched `$comments` map (via `ChatManager::getMessagesById()`), though the latter is **not** reusable for the authority check — see "Known tradeoff" below.

**`lib/Service/ThreadService.php`** — no authority method exists yet. Constructor currently takes `IDBConnection`, `ThreadMapper`, `ThreadAttendeeMapper`, `ITimeFactory`, `ICacheFactory` — none of these can load a comment, so a new `CommentsManager` dependency is required (AC2).

**`lib/Chat/ChatManager.php::getComment()`** (~946-958) is the exact precedent to mirror for the object-type/object-id check (`getObjectType() !== 'chat' || getObjectId() !== (string)$chat->getId()`, throwing `NotFoundException`), but cannot be called directly: `ChatManager` already injects `ThreadService` (confirmed via `use OCA\Talk\Service\ThreadService;` and constructor param in `lib/Chat/ChatManager.php`), so the reverse dependency is a container cycle (AD-3's own stated reason).

**`lib/Chat/CommentsManager.php`** — `OCA\Talk\Chat\CommentsManager extends \OC\Comments\Manager`, the "low-level" class AD-3 names. `get($id)` throws `\OCP\Comments\NotFoundException` (confirmed via `vendor/nextcloud/ocp/OCP/Comments/ICommentsManager.php`'s docblock) and is request-scoped-cached (`CappedMemoryCache`, 256 entries) **inside `get()` only** — the app's own `getCommentsById()` bulk method (used by `ChatManager::getMessagesById()`, which `prepareListOfThreads()` already calls to batch-fetch root+last messages) does **not** populate that cache, so a subsequent `get()` call for the same id is a fresh query, not a cache hit. This matters for Task 4 — see "Known tradeoff" below.

**`lib/Participant.php`** — `hasModeratorPermissions(bool $guestModeratorAllowed = true)`: with `false`, true only for `OWNER`/`MODERATOR` (excludes `GUEST_MODERATOR`) — this alone already excludes guest-moderators from the moderator branch, but does **not** exclude a plain-guest **root-message author** from the *authorship* branch, which is why AC9 needs an explicit guest check. `isGuest()` (~66-69) returns true for both `GUEST` and `GUEST_MODERATOR` participant types — the existing, idiomatic way this class already expresses "is this a guest session" (used by `canStartCall()`).

**`lib/Model/Attendee.php`** — `ACTOR_USERS = 'users'`, `ACTOR_GUESTS = 'guests'`, `ACTOR_BOTS = 'bots'`. Precedent for checking `getActorType() === Attendee::ACTOR_GUESTS`/`ACTOR_BOTS` exists throughout the codebase (e.g. `lib/Controller/RoomController.php:1292`, `lib/Chat/MessageParser.php:171`).

**`lib/Exceptions/ThreadProperty/StateException.php`** (Story 1.2) — the direct shape precedent: `extends \InvalidArgumentException`, one `REASON_*` constant, constructor takes the reason, `getReason(): string`. `AuthorityException` copies this shape exactly.

**`lib/ResponseDefinitions.php`** — `TalkThreadInfo` (~746-755) is `{thread: TalkThread, attendee: TalkThreadAttendee, first: ?TalkChatMessage, last: ?TalkChatMessage}` today. `TalkThread` (~724-739) already gained `state` in Story 1.2 and is the wrong place for `canManage` — see the field-placement reasoning in Task 4.1.

**`src/stores/chatExtras.ts`** — `ThreadInfo` objects are stored keyed by `[token][threadId]`. `getThread()`/`getThreadsList()` are plain functions (not Vue `computed()` refs) — the established pattern for parameterised lookups in this store, which `canManageThread()` follows. `updateThread()` (~344-361) manually reconstructs the `{thread, attendee, first, last}` shape from a partial payload — any field not explicitly threaded through here silently disappears from an already-loaded Thread on a partial update.

**`src/components/RightSidebar/Threads/ThreadHeader.vue`** and **`ThreadItem.vue`** — both independently compute `isModeratorOrOwner`, with **different** logic (`ThreadHeader` compares `currentThread.first.actorId/actorType` to `useActorStore()` and ORs with the current conversation's `store.getters.isModerator`; `ThreadItem` compares `thread.first.actorId/actorType` to `useActorStore()` and ORs with `store.getters.conversation(...).participantType` being `OWNER`/`MODERATOR`/`GUEST_MODERATOR`). Neither excludes a guest root-message author. `ThreadsTab.vue` renders one `ThreadItem` per entry of `chatExtrasStore.getThreadsList(token)` — the "current threads list" AC7/AC8 test against.

### Known tradeoff — per-row authority query (documented, not silently accepted)

`ThreadService::isThreadManager()`'s signature is fixed by AD-3's own text — `ThreadService::isThreadManager(Thread $thread, Participant $participant): bool`, no third parameter — so it cannot receive a pre-fetched `IComment` from `prepareListOfThreads()`'s already-batched `$comments` map, even though that map contains the exact root comment `isThreadManager()` will independently re-fetch via `CommentsManager::get()` for every **non-moderator** viewer of every **non-own** Thread in a list. For a moderator, `hasModeratorPermissions(false)` short-circuits before any comment lookup (O(1) per row). For a non-moderator, this is a genuine per-row query — an N+1 pattern NFR-2/AD-9 exist to prevent.

This is accepted for this story, not overlooked: NFR-2's "no unbounded work per request" is explicitly scoped in `epics.md`'s Non-functional coverage section to **Epic 2's** query design ("owned by Epic 2's query design and re-tested by Epic 3's unread counting"), and today's two affected endpoints are hard-capped — `getRecentActiveThreads` at 50 (`ThreadService::getRecentByRoomId()`), `getSubscribedThreads` at 100 — so the worst case today is ≤100 extra single-row `comments` queries per request, not the 1000-Thread NFR-1 scale target a future, unpaginated call would hit. Epic 2's Directory work (which does own NFR-2) will need either a batched authority lookup or to precompute `canManage` from the same joined query it already builds for other row fields, rather than calling `isThreadManager()` per row; this is flagged here so it is not rediscovered as a surprise regression later.

### Assumption — AC5's Locked branch (documented, no interactive user available)

AC5 names three failure causes as if all three are exercised by this story: Thread is Locked, actor lacks authority, Thread does not exist. Only the latter two are reachable in Story 1.3's scope — nothing in this codebase enforces Locked yet (that is Stories 1.6/1.7's AD-2 seam; Story 1.4 only *introduces* the Locked state, Story 1.6/1.7 make it refuse writes). Building a `LockedException` now, with no caller and no confirmed shape, risks guessing wrong ahead of the story that actually owns its design. Resolution, consistent with Story 1.2's "Assumption — AC4's scope" precedent: this story delivers the two failure causes it can actually exercise as fully typed, distinct classes — `AuthorityException` (new) and the existing `DoesNotExistException`/`['error' => 'thread']` (unchanged, already distinct) — and documents that the Locked identifier is Stories 1.6/1.7's responsibility. Story 1.4 AC7 ("refused with the authority error identifier from Story 1.3") confirms this story's authority identifier (`AuthorityException::REASON_PERMISSION`, value `'permission'`) is exactly what gets reused, not reinvented, by the next story.

### AC4 regression trace

`tests/integration/features/chat-4/threads.feature`'s two rename-permission scenarios (~34-45 moderator-owner rename with system-message assertion; ~50-68 "Non moderators can only rename their own threads") use only `actorType: users` participants — no guest scenario exists (`grep -n "guest" tests/integration/features/chat-4/threads.feature` returns nothing). Traced outcome under the refactor: `participant2` renaming `participant1`'s Thread (not their own, non-moderator) — old code: `isOwnMessage=false`, `hasModeratorPermissions(false)=false` → 403 `permission`; new code: `isThreadManager()` — not guest/bot, not moderator, `getRootComment()` succeeds, actor mismatch → `false` → `ensureThreadManager()` throws → 403 `permission`. Identical. `participant2` renaming their own Thread 2 — old: `isOwnMessage=true` → 200; new: actor match → `true` → no throw → 200. Identical. Moderator renaming either Thread — old: `hasModeratorPermissions(false)=true` → 200 regardless of authorship; new: same short-circuit → 200. Identical in all three cases; no scenario exercises a guest, so the new guest-exclusion branch introduces no regression.

### Architecture compliance

- **AD-3** — this story *is* AD-3: one method, `ThreadService::isThreadManager(Thread $thread, Participant $participant): bool`, exact signature, root-message author OR `hasModeratorPermissions(false)`, degrading to moderators-only when the root comment cannot be loaded, root-comment lookup via the low-level `CommentsManager` (not `ChatManager`), object-type/object-id check in one private helper beside it.
- **AD-4** — `AuthorityException` is a distinct exception class with its own OCS identifier (`permission`, unchanged from today's literal), consistent with "the existing string-keyed error convention is extended, not replaced."
- **AD-1** — `canManage` is deliberately **excluded** from the sole-write-seam field checklist (`addType`/`createFromRow`/`fromJson`/`toJson`/`toArray`/`SelectHelper`) because it is not a persisted Thread column; adding it there would leak one actor's authority answer into the shared 900s distributed cache for every other participant reading that Thread (NFR-4). It is computed fresh, per request, in `prepareListOfThreads()` instead — outside the cache entirely.
- **AD-12** — `TalkThreadInfo` remains the one Thread representation; `canManage` is added to it (not to the bare `TalkThread`), so every endpoint returning `TalkThreadInfo` carries it uniformly, and `openapi*.json`/`src/types/openapi/*.ts` regenerate in the same change.
- **AD-15** — client Thread state lives in `useChatExtrasStore` alone; `canManageThread()` is the one computed-getter seam, matching "components read computed getters... and never call the service layer to ask" verbatim from AC6.
- **AD-17** — `AuthorityException` lands in a new file (`lib/Exceptions/ThreadProperty/AuthorityException.php`); edits to upstream-owned files (`ThreadController.php`, `ThreadService.php`, `ResponseDefinitions.php`, `chatExtras.ts`, `ThreadHeader.vue`, `ThreadItem.vue`) are the smallest diff that satisfies each AC.
- **Consistency Conventions** — "Authorisation | `ThreadService::isThreadManager()` only (AD-3). Guests and bots are never Thread Managers" is implemented literally in Task 1.3.

### Environment constraints (same as Stories 1.1/1.2 — read before starting)

- No `php` binary on the host. The `clavis-deploy-nextcloud-1` Docker container (`nextcloud:34-apache`, PHP 8.5.9) has this repo bind-mounted **read-only** at `/var/www/html/custom_apps/spreed`, and also has the full Nextcloud **core** source (`/var/www/html/lib/private/Comments/Manager.php` etc.) available for reading — used during story creation to confirm `CommentsManager::get()`'s request-scoped-cache behaviour. Use `docker exec -w /var/www/html/custom_apps/spreed clavis-deploy-nextcloud-1 php -l <path>` and `... php vendor/bin/psalm --no-cache <paths>` to verify every changed/created PHP file. The sole accepted psalm gap is `Class Test\TestCase does not exist` (core's `tests/` tree isn't shipped in this image) — same as Stories 1.1/1.2.
- `vendor/bin/phpunit` cannot execute here (bootstrap needs core's `tests/autoload.php`, absent from the image). PHP tests are written and verified by careful manual reasoning against the actually-vendored `OCP` source and the project's own precedent code, not by execution. State this plainly in Completion Notes.
- Node/npm (v24.7.0) work natively on the **host**. `npm run lint`, `npm run ts:check`, and `npm run test` (Vitest) can actually run for the client-side changes — use them, do not skip on the assumption they can't run (unlike the PHP side).
- `vendor/bin/generate-spec` and `npx openapi-typescript -t` are both real, runnable tools (container and host respectively) per Story 1.2's established precedent for cross-checking hand-applied OpenAPI/TS edits.

### Testing standards summary

- `tests/php/Service/ThreadServiceTest.php` (Story 1.2) is the direct precedent to extend: `createMock()` per constructor dependency, `ICacheFactory::createDistributed()` stubbed unconditionally. Add `CommentsManager&MockObject $commentsManager` to the fixture.
- `Attendee::fromRow([...])` (precedent: `tests/php/Chat/ChatManagerTest.php`, `tests/php/Service/RecordingServiceTest.php`) is how to build a real `Attendee` with a specific `actor_type`/`actor_id` for a mocked `Participant::getAttendee()`.
- `src/stores/__tests__/chatExtras.spec.js` (existing, no thread-specific coverage yet) is the file to extend for the client getter; no component-level test infrastructure exists yet for `ThreadItem.vue`/`ThreadHeader.vue` (no `__tests__` sibling), so this story verifies those two components' mechanical refactor via `npm run lint`/`npm run ts:check` plus manual template/script review rather than introducing new component-mount test infrastructure — judged disproportionate for a like-for-like `v-if` binding swap, matching Story 1.2's precedent of judging a disproportionate test addition out of scope (Task 7.4 there, migration-shape tests).
- Behat: `tests/integration/features/chat-4/threads.feature`'s rename scenarios must stay green unchanged — see "AC4 regression trace" above.

### Project Structure Notes

- No conflicts with the unified project structure. `AuthorityException` follows the `lib/Exceptions/<Property>Property/<Name>Exception.php` convention `StateException` (Story 1.2) already established in the new `ThreadProperty` subnamespace.
- No new files on the client side; all client changes are edits to already-existing files (`chatExtras.ts`, `ThreadHeader.vue`, `ThreadItem.vue`).

### Previous story intelligence (Stories 1.1, 1.2)

- Row-key convention (`th_`-prefixed) is settled and untouched by this story — `canManage` is deliberately kept **out** of that convention entirely (see AD-1 compliance note above), so there is nothing to reconcile with `SelectHelper`.
- Story 1.2 established the `docker exec ... php -l` / `psalm --no-cache` verification loop, the `generate-spec`/`openapi-typescript` cross-check procedure for hand-applied OpenAPI edits, and the discipline of stating plainly which parts (server) could only be verified by manual reasoning versus which parts (this story's JS/TS side, and Story 1.2's OpenAPI regeneration) were genuinely executed. Follow the same discipline here — and note this story is the **first** in the epic with real client-side (Vue/Pinia/TS) changes, so `npm run lint`/`ts:check`/`test` are genuinely executable, unlike the PHP side.
- Story 1.2's post-review pass found a real psalm defect (`FalsableReturnStatement` in Story 1.1's fixture, `MoreSpecificReturnType` in Story 1.2's `Thread::getState()` docblock) that manual tracing alone missed both times — run psalm, don't skip it, and expect it to catch something.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-1.3] — story statement and AC1-AC10, verbatim.
- [Source: _bmad-output/planning-artifacts/epics.md#Story-1.4] — confirms Story 1.4 AC7 reuses "the authority error identifier from Story 1.3," resolving AC5's Locked-branch scope question.
- [Source: .../ARCHITECTURE-SPINE.md#AD-3] — the exact `isThreadManager()` signature and rule text, verbatim.
- [Source: .../ARCHITECTURE-SPINE.md#AD-4] — typed, distinguishable refusals; Locked not folded into `permission`.
- [Source: .../ARCHITECTURE-SPINE.md#AD-1] — sole write seam and field checklist; the reasoning `canManage` is deliberately excluded from it.
- [Source: .../ARCHITECTURE-SPINE.md#AD-12] — one Thread representation (`TalkThreadInfo`), regenerated OpenAPI/TS in the same change.
- [Source: .../ARCHITECTURE-SPINE.md#AD-15] — client Thread state lives in one store; components never call the service layer.
- [Source: .../ARCHITECTURE-SPINE.md#Consistency-Conventions] — "Guests and bots are never Thread Managers."
- [Source: lib/Controller/ThreadController.php:200-262] — `renameThread()`, the inline expression this story lifts out.
- [Source: lib/Controller/ThreadController.php:269-325] — `prepareListOfThreads()`, the single choke point for AC6.
- [Source: lib/Service/ThreadService.php] — current state, read in full.
- [Source: lib/Chat/ChatManager.php:946-958] — `getComment()`, the object-type/object-id precedent.
- [Source: lib/Chat/CommentsManager.php] — the low-level class AD-3 names; `getCommentsById()` does not warm `get()`'s request cache.
- [Source: /var/www/html/lib/private/Comments/Manager.php (container core, read-only)] — confirms `get()`'s `CappedMemoryCache` behaviour underlying the "Known tradeoff" note.
- [Source: lib/Participant.php:66-87] — `isGuest()`, `hasModeratorPermissions()`.
- [Source: lib/Model/Attendee.php] — actor type constants.
- [Source: lib/Exceptions/ThreadProperty/StateException.php] — shape precedent for `AuthorityException`.
- [Source: lib/ResponseDefinitions.php:746-755] — `TalkThreadInfo`, the field this story adds to.
- [Source: src/stores/chatExtras.ts] — current state, read in full.
- [Source: src/components/RightSidebar/Threads/ThreadHeader.vue, ThreadItem.vue] — the two duplicated `isModeratorOrOwner` computeds this story unifies.
- [Source: tests/integration/features/chat-4/threads.feature:34-68] — the rename scenarios AC4's regression trace is checked against.
- [Source: tests/php/Service/ThreadServiceTest.php, tests/php/Chat/ChatManagerTest.php] — test precedents.
- [Source: vendor/nextcloud/ocp/OCP/Comments/ICommentsManager.php] — `get()`'s `@throws NotFoundException` contract.

## Dev Agent Record

### Agent Model Used

Claude Sonnet 5 (claude-sonnet-5), via bmad-dev-story

### Debug Log References

- `docker exec -w /var/www/html/custom_apps/spreed clavis-deploy-nextcloud-1 php -l <file>` run on every created/modified PHP file individually and again together at the end — all clean.
- `docker exec ... php vendor/bin/psalm --no-cache <files>` run repeatedly across the PHP change set (`ThreadService.php`, `ThreadController.php`, `ResponseDefinitions.php`, `AuthorityException.php`, `ThreadServiceTest.php`) — zero real errors at every run. The only finding, `Class Test\TestCase does not exist` on `ThreadServiceTest.php:42`, is the same accepted environmental gap Stories 1.1/1.2 documented (container image lacks Nextcloud core's `tests/` source tree). Two pre-existing INFO-level items (`ArgumentTypeCoercion` in `ThreadService::validateThread()`) and one new INFO-level item (`PossiblyNullArgument` on `$this->participant` at the new `ensureThreadManager()` call site) were reviewed: the new one matches the exact same class of `$this->participant`-is-nullable-but-middleware-guaranteed pattern already present at 6+ other call sites in the same, unmodified-elsewhere file (e.g. `isFederatedConversation()` checks, `getAttendee()` at the existing `prepareListOfThreads()` call) — an established, accepted pattern in this codebase, not a new defect.
- Confirmed via `grep -n "function getComment" lib/Chat/ChatManager.php` and reading `lib/Chat/CommentsManager.php`/`vendor/nextcloud/ocp/OCP/Comments/ICommentsManager.php` that `CommentsManager::get()` throws `\OCP\Comments\NotFoundException` and that `OCA\Talk\Chat\CommentsManager extends \OC\Comments\Manager` has no `ThreadService` dependency of its own, confirming AD-3's "reverse injection is a container cycle" reasoning and that wiring it into `ThreadService` introduces no cycle.
- Read the container's actual Nextcloud **core** source (`/var/www/html/lib/private/Comments/Manager.php`, read-only, available alongside the app mount) to confirm `get()`'s request-scoped `CappedMemoryCache` behaviour, and confirmed via `lib/Chat/CommentsManager.php::getCommentsById()` that the app's own bulk-fetch method (used by `ChatManager::getMessagesById()`, which `prepareListOfThreads()` already calls) does **not** warm that cache — the basis for the story's documented "Known tradeoff - per-row authority query" note. This was verified by reading, not assumed.
- `grep -n "guest" tests/integration/features/chat-4/threads.feature` returned nothing, confirming no existing Behat scenario exercises a guest-authored-thread rename, so the new guest-exclusion branch in `isThreadManager()` cannot regress AC4's "identical before and after" requirement — traced in full in Dev Notes "AC4 regression trace".
- OpenAPI regeneration genuinely executed, not hand-typed and hoped correct, following Story 1.2's precedent: copied the (already hand-edited) repo into a container-writable directory (`/tmp/spreedgen`, never writing through the read-only bind mount) and ran `php vendor/bin/generate-spec` there. Diffed all 8 generated `openapi*.json` files against the bind-mounted (host-edited) versions: `diff -q` reported **zero differences** for every one of the 8, including `openapi.json` and `openapi-full.json` — the tool's actual output is byte-identical to the hand-applied `canManage` edit. `grep -c canManage` on both the bind-mounted and freshly-generated `openapi.json`/`openapi-full.json` confirmed 2 occurrences in each (one in `required`, one in `properties`).
- TypeScript regeneration used the real host toolchain directly: `npx openapi-typescript -t` (the exact `ts:generate` script). `git status --porcelain src/types/openapi/` showed only `openapi.ts` and `openapi-full.ts` as modified; diffing the freshly-regenerated files against a pre-run backup of my hand-applied edits (`diff /tmp/openapi.ts.before src/types/openapi/openapi.ts` and the `-full` equivalent) showed **zero differences** for both.
- `python3 -c "import json; json.load(...)"` on both regenerated `openapi.json` and `openapi-full.json` confirmed valid JSON.
- `npx eslint <changed files>` — zero errors on `chatExtras.ts`, `ThreadHeader.vue`, `ThreadItem.vue`, `chatExtras.spec.js` (the two generated `openapi*.ts` files are correctly excluded by the project's ignore config, confirmed by the "File ignored" warning rather than a scan).
- `npm run ts:check` (`vue-tsc --noEmit`) run across the whole project — clean, no output (no type errors).
- `npx vitest run src/stores/__tests__/chatExtras.spec.js` — 18/18 passed (13 pre-existing + 5 new, after first confirming the 13 pre-existing ones passed unmodified before adding the new ones).
- `npx vitest run` (full suite, 70 files / 1414 tests) — 1413 passed, 1 timeout failure in `src/store/messagesStore.spec.js` (`eases list from [1 - 400] to [101 - 300]...`), a file this story never touches. Isolated re-runs of that single spec file — both on the unmodified tree (via `git stash`) and on the tree with this story's changes restored — both passed 81/81 in ~30-45s, confirming the failure was a resource-contention timeout from running all 70 files' ~1400 tests concurrently in this sandbox, not a regression introduced here.
- `grep -rn "isThreadManager|ensureThreadManager" lib/` confirms exactly the expected call sites: the two definitions in `ThreadService.php`, plus `ThreadController.php`'s `ensureThreadManager()` call in `renameThread()` and `isThreadManager()` call in `prepareListOfThreads()`. `grep -rn "hasModeratorPermissions(false)" lib/` confirms the old inline expression is gone from `ThreadController.php` and every remaining call site is an unrelated, pre-existing moderator check in a different domain (message editing, polls, signalling, room actions) — AC1's "no endpoint re-derives the expression" holds.
- PHPUnit itself could not be executed in this sandbox — the confirmed, real environment limitation Stories 1.1/1.2 documented (the container's `nextcloud:34-apache` image ships without Nextcloud core's `tests/` directory). The new/changed PHP tests were verified by careful manual reasoning against the actual implementation and the vendored `ICommentsManager`/`Entity` source, not by execution.

### Completion Notes List

- **AC1 (single authority method):** `ThreadService::isThreadManager(Thread $thread, Participant $participant): bool` holds exactly *root-message author OR `hasModeratorPermissions(false)`*, matching AD-3's exact signature. The prior inline expression in `ThreadController::renameThread()` is gone; grep confirms no other endpoint re-derives it.
- **AC2 (dependency wiring):** `ThreadService` gained a new constructor dependency on the low-level `OCA\Talk\Chat\CommentsManager` (not `ChatManager`, which already injects `ThreadService` - confirmed no container cycle by reading `CommentsManager`'s own constructor). The object-type/object-id check lives in one private helper, `getRootComment()`, beside `isThreadManager()`.
- **AC3 (degradation):** when the root comment cannot be loaded (`NotFoundException`, either "no such id" or "wrong object type/id"), `isThreadManager()` returns `false` for a non-moderator; a moderator still qualifies via the earlier, comment-lookup-free branch. Both directions covered by dedicated tests.
- **AC4 (regression-free refactor):** traced by hand against `tests/integration/features/chat-4/threads.feature`'s two rename scenarios (moderator rename, "Non moderators can only rename their own threads") - both produce identical 200/403 outcomes under the refactor. No guest scenario exists in that file today, so the new guest-exclusion branch (AC9) introduces no regression risk there. Full trace in the story's Dev Notes.
- **AC5 (typed, distinguishable refusals):** added `lib/Exceptions/ThreadProperty/AuthorityException.php`, mirroring `StateException`'s shape, reusing the OCS identifier `'permission'` `renameThread()` already returned (byte-identical wire behaviour). "Thread does not exist" is unchanged (`DoesNotExistException` / `['error' => 'thread']`). "Thread is Locked" is **not** built here - documented as an explicit, out-of-scope assumption (nothing enforces Locked before Stories 1.6/1.7); Story 1.4 AC7's "refused with the authority error identifier from Story 1.3" confirms this story's `permission` identifier is what gets reused, not reinvented.
- **AC6 (client authority getter):** `TalkThreadInfo` gained `canManage: bool` as a **top-level sibling** of `thread`/`attendee`/`first`/`last` - deliberately **not** added to `Thread::toArray()`/`toJson()`/the entity/cache (per-actor value, would leak across the shared 900s distributed cache otherwise, violating NFR-4). Computed in `ThreadController::prepareListOfThreads()`, the single choke point all four Thread-returning endpoints share, via `ThreadService::isThreadManager()`. Client: one function, `chatExtrasStore.canManageThread(token, threadId)`, is now the sole source of this answer; `updateThread()` threads it through partial updates so it survives a rename-triggered refresh. `ThreadHeader.vue` and `ThreadItem.vue` - previously two **different** local re-derivations of "is moderator or owner" (one Vuex-`isModerator`-based, one Vuex-`conversation`-participantType-based, neither excluding guests) - now both consume the one getter and nothing else.
- **AC7/AC8 (absent, not disabled; visible to moderators):** unchanged `v-if` structural pattern in both components (already absent-not-disabled before this story); now driven by the server's `canManage` answer via the one getter instead of two divergent client-side derivations.
- **AC9 (guests never qualify):** `isThreadManager()` checks `Participant::isGuest()` (covers `GUEST` and `GUEST_MODERATOR`) and `Attendee::ACTOR_BOTS` **before** the moderator/authorship checks, so a guest or bot who happens to have authored the Thread Root Message is still refused. Dedicated tests assert this for both guest and bot actors, including the case where the guest's actor id matches the root comment's actor id (i.e. proving exclusion overrides authorship, not just absence of it).
- **AC10 (per-request evaluation):** satisfied by construction - `isThreadManager()`/`ensureThreadManager()` read the live `Participant` object passed to them on every call (itself resolved fresh per request by the framework's participant middleware) and cache nothing about the authority decision anywhere. No additional code was needed for this AC.
- **Known, documented tradeoff (not an AC, flagged for Epic 2):** computing `canManage` per row in `prepareListOfThreads()` costs a `comments` table query per Thread for a **non-moderator** viewing a Thread they did not author (a moderator short-circuits before any lookup). Today's two affected endpoints are hard-capped at 50/100 results, so the worst case is bounded; NFR-2's "no unbounded work per request" is explicitly Epic 2's gate per `epics.md`'s coverage map, not Epic 1's. Documented in the story's Dev Notes so Epic 2's Directory work does not rediscover this as a surprise when it removes the 50/100 cap.
- **Execution constraint:** no host `php` binary; PHPUnit cannot run in this sandbox (confirmed, same root cause as Stories 1.1/1.2 - the container image lacks Nextcloud core's `tests/` source tree the bootstrap needs). Every PHP change was verified with `php -l` (all clean) and `psalm --no-cache` (all clean except the one accepted `Test\TestCase` gap) via the `clavis-deploy-nextcloud-1` container, and every new PHP test was verified by manual reasoning against the actual implementation and vendored `ICommentsManager` source. **This story's client-side (Vue/Pinia/TS) changes are the first in this epic that could be, and were, genuinely executed:** `npm run lint`, `npm run ts:check`, and `npx vitest run` all actually ran, and the new JS tests actually passed (18/18 in `chatExtras.spec.js`). The OpenAPI/TypeScript regeneration also genuinely executed (both `generate-spec` and `openapi-typescript`), matching Story 1.2's precedent, with byte-identical diffs confirming the hand-applied edits were correct.
- **No HALT condition was triggered.** All ambiguities (AC5's Locked-branch scope, the `canManage` field's placement on `TalkThreadInfo` vs. `Thread`, the guest/bot exclusion mechanism, the per-row query tradeoff) were resolved by direct engineering judgment grounded in reading the existing codebase's own precedents and the architecture spine's exact wording, and are documented at the point of each decision in the story's Dev Notes.

### File List

**Created:**
- `lib/Exceptions/ThreadProperty/AuthorityException.php` — typed authority-refusal exception (AC1, AC5), mirrors `StateException`'s shape.

**Modified:**
- `lib/Service/ThreadService.php` — new `isThreadManager()`, `getRootComment()` (private), `ensureThreadManager()`; new `CommentsManager` constructor dependency (AC1, AC2, AC3, AC5, AC9, AC10).
- `lib/Controller/ThreadController.php` — `renameThread()` refactored onto `ThreadService::ensureThreadManager()`, replacing the inline authority expression (AC1, AC4, AC5); `prepareListOfThreads()` computes and adds `canManage` to every `TalkThreadInfo` it builds (AC6).
- `lib/ResponseDefinitions.php` — `TalkThreadInfo` psalm-type gains `canManage: bool,` (AC6).
- `openapi.json`, `openapi-full.json` — `ThreadInfo` schema gains `canManage` (properties + required); regenerated via the project's real `generate-spec` tool and verified byte-identical to the hand-applied edit (AC6).
- `src/types/openapi/openapi.ts`, `src/types/openapi/openapi-full.ts` — `ThreadInfo` type gains `canManage: boolean;`; regenerated via the project's real `openapi-typescript` tool and verified byte-identical to the hand-applied edit (AC6).
- `src/stores/chatExtras.ts` — new `canManageThread(token, threadId)` getter function, exported from the store; `updateThread()` threads `canManage` through partial updates (AC6).
- `src/components/RightSidebar/Threads/ThreadHeader.vue` — `isModeratorOrOwner` (Vuex `isModerator` + actor comparison) replaced with `canManageThread` (reads `chatExtrasStore.canManageThread()`); removed now-unused `useStore`/`useActorStore` imports and instances (AC6, AC7, AC8, AC9).
- `src/components/RightSidebar/Threads/ThreadItem.vue` — `isModeratorOrOwner` (Vuex `conversation`/`PARTICIPANT.TYPE` + actor comparison) replaced with `canManageThread`; removed now-unused `useStore`/`useActorStore`/`PARTICIPANT` imports and instances (AC6, AC7, AC8, AC9).
- `tests/php/Service/ThreadServiceTest.php` — extended (Story 1.2's file): new `CommentsManager` mock in the fixture; `createParticipant()`/`createThread()`/`createRootComment()` test helpers; 11 new test methods covering `isThreadManager()` (moderator, root-author, neither, guest, bot, root-comment-missing × moderator/non-moderator, object-id mismatch), `ensureThreadManager()` (throws/does not throw), and `AuthorityException`'s distinctness from `StateException` (AC1-AC3, AC5, AC9, AC10).
- `src/stores/__tests__/chatExtras.spec.js` — new `describe('thread management authority')` block: 5 new tests covering `canManageThread()` for an unknown thread, a loaded thread (true/false), and `updateThread()` preserving/overwriting `canManage` on a partial update (AC6).

## Change Log

- 2026-08-09 — Story implemented end-to-end (Tasks 1-7). AC1-AC3: `ThreadService::isThreadManager()` is the single authority implementation (AD-3's exact signature), taking the low-level `CommentsManager` (AC2) and degrading to moderators-only when the root comment cannot be loaded (AC3). AC4: `ThreadController::renameThread()` refactored onto the shared seam; hand-traced against the two existing rename Behat scenarios, confirmed byte-identical outcomes. AC5: new `AuthorityException` (reuses the existing `permission` OCS identifier) plus `ThreadService::ensureThreadManager()`; the Locked branch is explicitly out of scope (documented assumption, Stories 1.6/1.7's responsibility). AC6: `canManage` added to `TalkThreadInfo` (not `Thread`, to avoid leaking a per-actor value into the shared distributed cache); `openapi*.json`/`src/types/openapi/*.ts` regenerated and verified byte-identical against the project's real `generate-spec`/`openapi-typescript` tools; client gets one store getter, `canManageThread()`, replacing two divergent, guest-blind local re-derivations in `ThreadHeader.vue` and `ThreadItem.vue`. AC7/AC8: unchanged absent-not-disabled `v-if` structure, now server-driven. AC9: guests (`Participant::isGuest()`) and bots excluded ahead of the authorship check, proven by a dedicated test where the guest is, in fact, the root-message author. AC10: satisfied by construction (no authority caching). A documented, non-blocking tradeoff was flagged for Epic 2: computing `canManage` per row costs a per-row comment query for non-moderators, bounded today by the 50/100 result caps, not yet a genuine NFR-2 violation. 11 new PHP tests (`ThreadServiceTest.php`) and 5 new JS tests (`chatExtras.spec.js`) added; PHPUnit still cannot execute in this environment (same confirmed limitation as Stories 1.1/1.2), but this story's JS-side tests, lint, and type-check **did** genuinely execute (`npx vitest run`, `npm run lint`, `npm run ts:check`), as did the OpenAPI/TS regeneration. Full-suite Vitest run showed one unrelated timeout flake (`messagesStore.spec.js`, a file this story never touches), confirmed non-regressive by isolated reruns before and after this story's changes. Status set to `review`.
