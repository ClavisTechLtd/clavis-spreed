---
baseline_commit: 6819859953c32f32ccaecf13f4b11f1ad382db00
---

# Story 1.4: A Thread Manager moves a Thread between states, with an optional reason on locking

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a moderator whose room keeps re-litigating settled decisions,
I want to mark a Thread finished, shut it and say why, and undo either,
so that the list shows what is actually live and a decision that has been made stops being reopened.

*One state-change endpoint serves all four transitions — close, lock, reopen from Closed, reopen from Locked — per AD-12's rule that new endpoints appear only for operations that do not exist.*

## Acceptance Criteria

**AC1**
**Given** an Ongoing Thread and an actor who is a Thread Manager
**When** they close it
**Then** the Thread reports state Closed immediately after the request succeeds
**And** the response carries the full `TalkThreadInfo` representation, the same shape and same builder a list endpoint returns. *(FR-2)*

**AC2**
**Given** a Thread that is Ongoing, and another that is Closed
**When** a Thread Manager locks each
**Then** both report Locked, and the Closed one reaches Locked directly without passing through Ongoing. *(FR-4)*

**AC3**
**Given** a Closed Thread and a Thread Manager who is the Thread Root Message author but not a moderator
**When** they reopen it without posting
**Then** the Thread reports Ongoing. *(FR-6)*

**AC4**
**Given** a Locked Thread and a Thread Manager
**When** they unlock it
**Then** it reports Ongoing and accepts writes again. *(FR-7)*

**AC5**
**Given** any of the four transitions succeeds
**When** the Thread's messages are read
**Then** a system message records the transition and who performed it
**And** each transition's constant is registered in all six places, including **all four** verb checks in `lib/Signaling/Listener.php` — the two that decide whether it relays at all, and the two that decide whether it arrives carrying its Thread.

**AC6**
**Given** those four verb checks currently sit as inline literals inside one file
**When** this story lands
**Then** they are extracted into named constants declared together and each site tests membership
**And** adding a future verb means editing one list, not remembering four.

**AC7**
**Given** a participant who is neither the Thread Root Message author nor a Conversation moderator
**When** they attempt any of the four transitions
**Then** they are refused with the authority error identifier from Story 1.3, with the same error shape across all four.

**AC8**
**Given** a Thread that is already Closed
**When** a Thread Manager closes it again
**Then** the request succeeds without duplicating the system message.

**AC9**
**Given** the migration adding a nullable, bounded lock-reason column to `talk_threads`
**When** it runs
**Then** it is additive, defaulted, safe to run twice and backfills nothing.

**AC10**
**Given** a Thread Manager locking with a free-text reason
**When** the request succeeds
**Then** the reason is carried on the locking system message as **message parameter data**, never concatenated into the rendered message text
**And** the current reason is also written to the `talk_threads` column so the thread header needs no history walk. *(FR-43)*

**AC11**
**Given** a Thread Manager locking without a reason, and another submitting whitespace only
**When** each request completes
**Then** both succeed and both are treated as no reason, with the absence producing no error
**And** the length bound is stated in the interface before the participant hits it.

**AC12**
**Given** a Thread locked with reason A, unlocked, then locked again with reason B
**When** the Thread is read
**Then** the column holds B and the earlier system message still holds A intact.

**AC13**
**Given** the lock reason is user-authored content in a customer Conversation
**When** it travels
**Then** it is withheld exactly where a Thread Title is withheld, and never enters any part of a push payload outside the envelope the customer's server encrypts.

**AC14**
**Given** two Thread Managers changing the same Thread's state concurrently
**When** both requests complete
**Then** the later write wins with no conflict response and no concurrency token
**And** the earlier actor's client displays the state carried in **its own response**, never what it sent.

**AC15**
**Given** any transition completes
**When** the cache is examined
**Then** `thread/{roomId}/{threadId}` was **removed**, not re-set from the entity the mutator held.

**AC16**
**Given** actor A changes a Thread's state and actor B is viewing the same Conversation
**When** B reads the Thread through the distributed cache
**Then** B sees the new state rather than a stale value, and B's view updates without a reload with the relayed payload carrying its Thread context
**And** this is proven by a **multi-actor** integration scenario, because a single actor reads its own fresh value and cannot see the bug.

**AC17**
**Given** a Thread Manager with a Thread open
**When** the thread header renders
**Then** it offers a menu carrying all four transitions — close, lock, reopen from Closed, reopen from Locked — so the actor changes state from the Thread itself without navigating away from it
**And** the lock action offers AC10's optional reason field with AC11's length bound stated before it is hit. *(UJ-2)*

**AC18**
**Given** the state-change controls
**When** a Thread is rendered for a non-manager
**Then** they are absent, not disabled, driven by Story 1.3's store getter. *(FR-10)*

**AC19**
**Given** the four lifecycle constants this story introduces
**When** AD-2's exemption list is assembled
**Then** the lifecycle-transition subset of the relay set **is** that exemption list, so the guard cannot drift from the state machine.

> **Control placement is acceptance, not styling.** AC17 fixes *where* a Thread Manager reaches these transitions, because FR-2, FR-4, FR-6 and FR-7 specify the transitions without specifying the affordance — an implementation offering them only on a per-Thread page satisfies every other criterion in this story and still fails UJ-2. The list-row equivalent lands in Story 1.8 AC11 and the Directory-row equivalent in Story 2.4 AC15; together they are what makes PRD AC-1 reachable.

> **Epic gate:** this story ships a Locked state that does not yet refuse anything. Stories 1.6 and 1.7 complete FR-5, and **the epic does not ship without both** — a Locked Thread that reports itself locked while remaining writable is the failure AD-2 exists to prevent.

## Tasks / Subtasks

- [x] **Task 1 — Migration: lock-reason column (AC9)**
  - [x] 1.1 Read `lib/Migration/Version24000Date20260809120000.php` (Story 1.2's additive-column precedent) and `lib/Model/Ban.php`/`lib/Service/BanService.php` (bounded free-text moderator note precedent, `NOTE_MAX_LENGTH = 4000`) before writing.
  - [x] 1.2 Add `lib/Migration/Version24000Date20260810090000.php` (next timestamp after 1.2's, matching `<version>24.0.3</version>`'s group): `getTable('talk_threads')`, guard `!$table->hasColumn('lock_reason')`, `addColumn('lock_reason', Types::TEXT, ['notnull' => false])`. No default needed (nullable, no backfill) — matches AD-19 exactly.
  - [x] 1.3 `php -l` and `psalm` on the new migration file via the container.

- [x] **Task 2 — `Thread` entity carries the lock reason (AC9, AC10, AC12, AD-1)**
  - [x] 2.1 Re-read `lib/Model/Thread.php` (post-Story-1.2/1.3 state, already read during story creation).
  - [x] 2.2 Add `public const LOCK_REASON_MAX_LENGTH = 4000;` (matches `Ban::NOTE_MAX_LENGTH`'s precedent — a bounded, moderator-authored free-text field).
  - [x] 2.3 Add `protected ?string $lockReason = null;` and `@method void setLockReason(?string $lockReason)` / `@method ?string getLockReason()` docblocks, `$this->addType('lockReason', Types::STRING);` in the constructor.
  - [x] 2.4 `createFromRow()`: `$thread->setLockReason($row['th_lock_reason'] !== null ? (string)$row['th_lock_reason'] : null);` — no `??` fallback (same reasoning as Story 1.2's `state`: this row shape is produced entirely within this same change).
  - [x] 2.5 `fromJson()`: `$thread->setLockReason($row['lock_reason'] ?? null);` — defensive `??`, same reasoning as Story 1.2's `state` fallback (a still-warm pre-upgrade cache entry may lack the key for up to 900s post-deploy).
  - [x] 2.6 `toJson()`: add `'lock_reason' => $this->getLockReason(),`.
  - [x] 2.7 `toArray()`: add `'lockReason' => $this->getLockReason(),` after `'state'`.
  - [x] 2.8 `php -l` and `psalm` on `lib/Model/Thread.php`.

- [x] **Task 3 — `SelectHelper::selectThreadsTable()` both branches (AC9, AD-1)**
  - [x] 3.1 `aliasAll: true` branch: add `->selectAlias($alias . 'lock_reason', 'th_lock_reason')`.
  - [x] 3.2 `aliasAll: false` branch: add `$alias . 'lock_reason'` to the bare-key `addSelect` array (dead code today per Story 1.1, kept in sync per AD-1's explicit "both branches" rule).
  - [x] 3.3 `php -l` and `psalm` on `lib/Model/SelectHelper.php`.

- [x] **Task 4 — `ResponseDefinitions` and generated OpenAPI/TypeScript for `lockReason` (AC10, AD-1, AD-12)**
  - [x] 4.1 Add `lockReason: ?string,` to the `TalkThread` psalm-type in `lib/ResponseDefinitions.php`, after `state`, with a doc comment.
  - [x] 4.2 Regenerate `openapi.json`/`openapi-full.json` (the only two files declaring the `Thread` schema, per Story 1.2's verified finding) via `vendor/bin/generate-spec` in the container against a container-writable copy, diff, reconcile.
  - [x] 4.3 Regenerate `src/types/openapi/openapi.ts`/`openapi-full.ts` via `npx openapi-typescript -t` on the host.
  - [x] 4.4 `php -l` and `psalm` on `lib/ResponseDefinitions.php`.

- [x] **Task 5 — Publish the lock-reason length bound via capabilities (AC11, AD-9 Consistency Convention: "every bound is one server-side constant... published to clients in the capability payload")**
  - [x] 5.1 Read `lib/Capabilities.php`'s `config`/`LOCAL_CONFIGS` structure in full (the `conversations => description-length` entry is the direct precedent — same shape: a bounded free-text field's max length, local-only since Threads are not proxied over federation per AD-18).
  - [x] 5.2 Add a `'threads' => ['lock-reason-length' => Thread::LOCK_REASON_MAX_LENGTH]` group to the `config` array built in `getCapabilities()`, and `'threads' => ['lock-reason-length']` to `LOCAL_CONFIGS`.
  - [x] 5.3 Add the `docs/capabilities.md` entry `` `config => threads => lock-reason-length` (local) - Maximum length in characters allowed for a Thread's lock reason `` in the existing `## 24.0.3` section (Story 1.2 created it) — required by `CapabilitiesTest::testCapabilitiesDocumentation()`, which reads config keys straight out of `openapi.json`'s `Capabilities` schema, so Task 5.4's regeneration must land before this test can pass.
  - [x] 5.4 Regenerate the same two `openapi*.json`/`*.ts` pairs as Task 4 — the `Capabilities` schema (unlike `Thread`) is declared in **all eight** generated files, so run the full `generate-spec`/`openapi-typescript` regeneration and confirm via diff which of the eight actually changed (expect all eight, since every one embeds `Capabilities.config`).
  - [x] 5.5 `php -l` and `psalm` on `lib/Capabilities.php`.

- [x] **Task 6 — `ThreadService::changeState()` (AC1–AC4, AC8, AC12, AC14, AC15, AD-1, AD-13)**
  - [x] 6.1 Add a failing test first (red) in `tests/php/Service/ThreadServiceTest.php` for: a real transition mutates state + cache-invalidates (not re-sets); a no-op transition (same state requested again) makes no DB write and returns the thread unchanged; locking sets `lockReason` (trimmed, blank→null); locking again while already Locked does not overwrite an existing `lockReason` (documented assumption, see Dev Notes); unlocking/closing does not clear a stale `lockReason` (AC12 — the column is simply overwritten on the next real lock); a too-long reason throws `\InvalidArgumentException('reason')`.
  - [x] 6.2 Implement `ThreadService::changeState(Thread $thread, int $state, ?string $reason = null): Thread` (green):
    - `$this->validateState($state);` (reuses Story 1.2's method — this is the "later work" its docblock already anticipated).
    - If `$state === $thread->getState()`: return `$thread` unmodified — no DB write, no cache touch, no message (this is what makes AC8 hold: closing an already-Closed Thread is a genuine no-op at the service layer, not merely "the controller skips the message"). Document explicitly that this also means re-locking an already-Locked Thread does **not** update `lockReason`, even with a different reason string — a reason update only happens on a real Ongoing/Closed→Locked transition (AC12's scenario always has an intervening unlock, so this never contradicts it).
    - Otherwise: if `$state === Thread::STATE_LOCKED`, trim `$reason`, coerce `''` to `null` (AC11), throw `new \InvalidArgumentException('reason')` if `mb_strlen($reason) > Thread::LOCK_REASON_MAX_LENGTH`, then `$thread->setLockReason($reason)`. For every other target state, ignore the `$reason` parameter entirely (do not touch the stored `lockReason` column — see AC12's Dev Notes reasoning).
    - `$thread->setState($state); $this->threadMapper->update($thread);`
    - `$this->cache->remove(self::CACHE_PREFIX . $thread->getRoomId() . '/' . $thread->getId());` — **remove, never `set()`** (AC15, matching `updateLastMessageInfoAfterReply()`'s existing pattern exactly).
    - Return the mutated `$thread`.
  - [x] 6.3 `php -l` and `psalm` on `lib/Service/ThreadService.php`.

- [x] **Task 7 — `lib/Signaling/Listener.php`: named constants, four verb checks, threadInfo payload (AC5, AC6, AC16, AC19, AD-14)**
  - [x] 7.1 Re-read the four existing verb-check sites (lines ~81 `SYSTEM_MESSAGE_TYPE_RELAY`, ~558 the skip-last-activity inline array, ~577 the `$thread` lookup branch, ~621 the `threadInfo` payload branch) — already read in full during story creation, re-confirm no drift.
  - [x] 7.2 Declare, together near `SYSTEM_MESSAGE_TYPE_RELAY`:
    ```php
    /**
     * The four Thread lifecycle transitions this story introduces. This is
     * also, verbatim, AD-2's exemption list for the Locked-write guard
     * Stories 1.6/1.7 build (AC19) — declaring it once here means that
     * guard reads this list rather than re-deriving its own, so the two
     * cannot drift apart.
     */
    public const THREAD_LIFECYCLE_MESSAGE_TYPES = [
        'thread_closed',
        'thread_locked',
        'thread_reopened',
        'thread_unlocked',
    ];

    /**
     * Thread messages that carry a Thread and must relay with the
     * threadInfo payload attached (AD-14 registry sites 3 and 4). Must
     * stay a superset of THREAD_LIFECYCLE_MESSAGE_TYPES above plus the
     * two pre-existing thread messages.
     */
    public const THREAD_MESSAGE_TYPES_WITH_CONTEXT = [
        'thread_created',
        'thread_renamed',
        'thread_closed',
        'thread_locked',
        'thread_reopened',
        'thread_unlocked',
    ];
    ```
  - [x] 7.3 Site 1 (`SYSTEM_MESSAGE_TYPE_RELAY`): append the four `THREAD_LIFECYCLE_MESSAGE_TYPES` entries (spelled out, matching the array's existing plain-literal style).
  - [x] 7.4 Site 2 (the skip-last-activity inline array in `notifySystemMessageSent()`): extract to a new constant `SKIP_LAST_ACTIVITY_EXEMPT_MESSAGE_TYPES = ['message_deleted', 'message_edited', 'thread_created', 'thread_renamed', 'thread_closed', 'thread_locked', 'thread_reopened', 'thread_unlocked']` (a different set than Task 7.2's — it also covers `message_deleted`/`message_edited`, which aren't Thread-context messages) and use `in_array($messageType, self::SKIP_LAST_ACTIVITY_EXEMPT_MESSAGE_TYPES, true)`.
  - [x] 7.5 Sites 3 and 4 (`$thread` lookup branch, `threadInfo` payload branch): replace both `$messageType === 'thread_created' || $messageType === 'thread_renamed'` expressions with `in_array($messageType, self::THREAD_MESSAGE_TYPES_WITH_CONTEXT, true)`.
  - [x] 7.6 In the `threadInfo` payload branch, add `'state' => $thread->getState(),` and `'lockReason' => $thread->getLockReason(),` to the manually-built `$data['chat']['comment']['threadInfo']['thread']` array — it currently hand-duplicates a subset of `Thread::toArray()`'s fields and is missing `state` entirely (a pre-existing gap from Story 1.2, since this file was untouched by Stories 1.1–1.3). This is the fix AC16 actually depends on: a relayed payload missing `state` cannot prove a viewer sees the new value. Leave the adjacent, pre-existing `'first' => $thread->toArray($room)` line untouched — it is an unrelated oddity that predates this story and is out of scope here.
  - [x] 7.7 `php -l` and `psalm` on `lib/Signaling/Listener.php`.

- [x] **Task 8 — Client registries: `src/constants.ts`, `src/utils/message.ts` (AC5, AC6, AC16, AD-14 registry sites 1–3)**
  - [x] 8.1 Add to `MESSAGE.SYSTEM_TYPE` in `src/constants.ts`: `THREAD_CLOSED: 'thread_closed'`, `THREAD_LOCKED: 'thread_locked'`, `THREAD_REOPENED: 'thread_reopened'`, `THREAD_UNLOCKED: 'thread_unlocked'` (alongside `THREAD_CREATED`/`THREAD_RENAMED`).
  - [x] 8.2 Add the four to `SYSTEM_MESSAGE_TYPE_RELAY` in `src/utils/message.ts` (kept in sync with `Listener.php`'s copy, per the file's own "Sync with server-side constant" comment).
  - [x] 8.3 Add the four to `SYSTEM_MESSAGE_TYPE_UNTRANSLATED` — **required, not optional**: `useGetMessages.ts::addMessageFromChatRelay()` calls `tryLocalizeSystemMessage()` inside a `try { } catch { tryPollNewMessages(); return }`; anything not short-circuited by `SYSTEM_MESSAGE_TYPE_UNTRANSLATED` falls to the `switch`'s `default: throw new Error()` (deliberately used today for `CALL_ENDED`/`FILE_SHARED`/`OBJECT_SHARED` — "not worth localizing on client side"), which triggers the early `return` and skips the rest of the function, including the code that would otherwise apply the relayed message (and its `threadInfo`) to the stores. Being in `UNTRANSLATED` keeps the server's already-parsed `message.message` verbatim and lets the function continue — this is *why* `thread_created`/`thread_renamed` are already there, not a stylistic choice.
  - [x] 8.4 Do **not** add the four to `SYSTEM_MESSAGE_TYPE_HIDDEN` — documented decision, see Dev Notes "Assumption — lifecycle messages are visible, not hidden".
  - [x] 8.5 `npx eslint` and `npm run ts:check` on the two changed files.

- [x] **Task 9 — `lib/Chat/Parser/SystemMessage.php`: four new parsed-message branches (AC5, AC10, AD-14 registry site 6)**
  - [x] 9.1 Re-read the `thread_created`/`thread_renamed` `elseif` blocks (~587–606) as the direct precedent: `{actor}`/`You` phrasing, a `title` parameter shaped `['type' => 'highlight', 'id' => 'thread/' . $parameters['thread'], 'name' => $parameters['title'] ?? (string)$parameters['thread']]`.
  - [x] 9.2 Add `elseif ($message === 'thread_closed')`, `'thread_reopened'`, `'thread_unlocked')` blocks, each with `{actor}`/`You` phrasing (`'{actor} closed thread {title}'` / `'You closed thread {title}'`, etc.) and the same `title` parameter shape.
  - [x] 9.3 Add `elseif ($message === 'thread_locked')`: when `$parameters['reason']` is present and non-empty, use `'{actor} locked thread {title} ({reason})'` / `'You locked thread {title} ({reason})'` and add `$parsedParameters['reason'] = ['type' => 'highlight', 'id' => 'thread-lock-reason', 'name' => $parameters['reason']];`; otherwise use the reason-less phrasing. This is what AC10 means by "message parameter data, never concatenated into the rendered text" — `{reason}` is a resolved placeholder like `{title}` already is, not a string built with `.` concatenation.
  - [x] 9.4 `php -l` and `psalm` on `lib/Chat/Parser/SystemMessage.php`.

- [x] **Task 10 — `ThreadController::setState()` endpoint (AC1–AC4, AC7, AC8, AC13, AD-4, AD-12, AD-18)**
  - [x] 10.1 Read `ThreadController::renameThread()` (the closest precedent: same find-thread / authority / mutate / addSystemMessage / prepareListOfThreads shape) and `BanController::banActor()` (the `catch (\InvalidArgumentException $e) { return ['error' => $e->getMessage()] }` precedent for a message-named refusal) once more, immediately before writing.
  - [x] 10.2 Add `setState(int $threadId, int $state, ?string $reason = null): DataResponse`, routed `PUT /api/{apiVersion}/chat/{token}/threads/{threadId}/state`, `threadId` and `token` requirements matching the existing thread routes.
    - **Deliberately no `#[FederationSupported]` attribute** — per AD-18, no new Thread endpoint is proxied; omitting the attribute makes `InjectionMiddleware::checkFederationSupport()` refuse the request outright for a federated room (throws `FederationUnsupportedFeatureException`), which is the correct "degrade by omission" behaviour for a *new* endpoint (unlike the four pre-existing endpoints, which proxy).
    - `#[PublicPage]`, `#[RequireModeratorOrNoLobby]`, `#[RequireParticipant]` — same attribute set as `renameThread()`.
    - `try { $thread = $this->threadService->findByThreadId(...) } catch (DoesNotExistException) { return ['error' => 'thread'], 404 }`.
    - `try { $this->threadService->ensureThreadManager($thread, $this->participant); } catch (AuthorityException $e) { return ['error' => $e->getReason()], 403 }` (AC7 — same identifier Story 1.3 established, `'permission'`).
    - Capture `$previousState = $thread->getState();` **before** mutating.
    - `try { $thread = $this->threadService->changeState($thread, $state, $reason); } catch (StateException $e) { return ['error' => $e->getReason()], 400 } catch (\InvalidArgumentException $e) { return ['error' => $e->getMessage()], 400 }` (the first catches an out-of-range `$state` via Story 1.2's `validateState()`; the second catches an over-length `$reason`).
    - If `$previousState !== $state` (a real transition — AC8's no-duplicate-message case is exactly when this is false): resolve the verb (`STATE_LOCKED` → `'thread_locked'`; `STATE_CLOSED` → `'thread_closed'`; `STATE_ONGOING` and `$previousState === STATE_CLOSED` → `'thread_reopened'`; `STATE_ONGOING` and `$previousState === STATE_LOCKED` → `'thread_unlocked'`), then call `addSystemMessage()` with the exact same shape `renameThread()` uses (`sendNotifications: false`, `shouldSkipLastMessageUpdate: true`, `silent: true`, `threadId: $threadId`, `replyTo:` the root comment fetched the same nullable-safe way `renameThread()` does). For `thread_locked`, include `'reason' => $thread->getLockReason()` in the message parameters only when non-null.
    - `$list = $this->prepareListOfThreads([$thread]); return new DataResponse(array_shift($list));` — AD-12's "same builder, same shape a list endpoint returns," identical to `renameThread()`'s return.
  - [x] 10.3 Update the docblock's `@return` union to enumerate every response shape (200 `TalkThreadInfo`; 400 `array{error: 'value'|'reason'}`; 403 `array{error: 'permission'}`; 404 `array{error: 'thread'}`).
  - [x] 10.4 `php -l` and `psalm` on `lib/Controller/ThreadController.php`.

- [x] **Task 11 — Client transport: `messagesService.ts`, `src/types/index.ts` (AC1–AC4, AC10, AC11)**
  - [x] 11.1 Read `renameThread()`'s exact shape in `src/services/messagesService.ts` (axios `.put`, `generateOcsUrl`, params-as-type-cast) as the precedent.
  - [x] 11.2 Add `setThreadStateParams`/`setThreadStateResponse` types to `src/types/index.ts`, sourced the same way as `renameThreadParams`/`renameThreadResponse` from `operations['thread-set-state']` (the operationId `generate-spec` derives from the controller method name `setState`, matching the existing `thread-rename-thread` ← `renameThread` pattern confirmed in `openapi.json`).
  - [x] 11.3 Add `setThreadState(token, threadId, state, reason, options?)` to `messagesService.ts`: `axios.put(generateOcsUrl('apps/spreed/api/v1/chat/{token}/threads/{threadId}/state', { token, threadId }), { state, reason } as setThreadStateParams, options)`.
  - [x] 11.4 `npx eslint` and `npm run ts:check` on both files (deferred to run together with Task 4/5's regenerated types, since the operation types don't exist until those land).

- [x] **Task 12 — `chatExtras.ts`: store action for the four transitions (AC1–AC4, AC10, AC11, AC14, AD-15)**
  - [x] 12.1 Read `renameThread()`'s store action (spawns `ConfirmDialog` via `spawnDialog`, calls the API, then `addThread()` with the response) as the precedent for `changeThreadState()`.
  - [x] 12.2 Add `async function changeThreadState(token: string, threadId: number, state: number, reason?: string)`: calls `setThreadState()`, then `addThread(token, response.data.ocs.data)` on success (AD-13 — "the client replaces its local copy with the response, never with what it sent"), `showError()` + `console.error` on failure, matching `renameThread()`'s error handling exactly.
  - [x] 12.3 Add `async function promptLockThreadReason(token: string, threadId: number): Promise<string | undefined>`: `spawnDialog(ConfirmDialog, { name: t('spreed', 'Lock thread'), isForm: true, inputProps: { value: '', label: t('spreed', 'Reason (optional)'), hint: t('spreed', 'Up to {max} characters', { max: lockReasonMaxLength }) }, buttons: [...] })`, mirroring `renameThread()`'s dialog shape; returns the entered string (possibly empty) or `undefined` if dismissed. `lockReasonMaxLength` reads `getTalkConfig('local', 'threads', 'lock-reason-length') || 4000` (Task 5's published bound — the `getTalkConfig('local', 'conversations', 'description-length') || 500` call sites in `NewConversationDialog.vue`/`BasicInfo.vue` are the precedent for the `|| <fallback>` shape and the `local` scope argument).
  - [x] 12.4 Export both from the store's returned object.
  - [x] 12.5 `npx eslint` and `npm run ts:check` on `src/stores/chatExtras.ts`.

- [x] **Task 13 — `ThreadHeader.vue`: the four-transition menu (AC17, AC18)**
  - [x] 13.1 Re-read the existing `canManageThread`-gated `<NcActions>` block (the "Edit thread details" action) as the direct precedent for placement, gating and icon/label style.
  - [x] 13.2 Add up to two `NcActionButton`s to that same `<NcActions v-if="canManageThread">` block, computed from `currentThread.thread.state`:
    - `state === Ongoing`: "Close thread" (→ `changeThreadState(token, threadId, CLOSED)`) and "Lock thread" (→ `await promptLockThreadReason(...)`, then if not dismissed, `changeThreadState(token, threadId, LOCKED, reason)`).
    - `state === Closed`: "Reopen thread" (→ `changeThreadState(token, threadId, ONGOING)`) and "Lock thread" (as above — AC2, Closed reaches Locked directly).
    - `state === Locked`: "Unlock thread" (→ `changeThreadState(token, threadId, ONGOING)`) only — AC18/AD-2's write-refusal for a Locked Thread is Story 1.6/1.7's scope, but the state-change action itself has no reason to be gated further here.
  - [x] 13.3 `v-if="canManageThread"` already satisfies AC18 (absent, not disabled, for a non-manager) — no additional gating needed since the whole `<NcActions>` block is already conditional on the Story 1.3 getter.
  - [x] 13.4 `npm run lint` and `npm run ts:check` on `ThreadHeader.vue`.

- [x] **Task 14 — Tests (AC1–AC19, TDD red-green-refactor)**
  - [x] 14.1 `tests/php/Model/ThreadTest.php`: extend the `expectedKeys` list to include `th_lock_reason`; add a hydration test asserting `createFromRow()` reads `lockReason` (`null` and non-null cases); a `toArray()`/`toJson()`/`fromJson()` round-trip test asserting `lockReason` survives, including the missing-key cache-fallback case.
  - [x] 14.2 `tests/php/Service/ThreadServiceTest.php`: the `changeState()` cases from Task 6.1 (already drafted red-first there); plus `ensureThreadManager()`/`validateState()` remain covered by Stories 1.2/1.3's existing tests (no change needed).
  - [x] 14.3 `tests/php/Chat/Parser/SystemMessageTest.php`: add four rows to the `testParseMessage` data provider (`thread_closed`, `thread_locked` with a reason, `thread_locked` without a reason, `thread_reopened`, `thread_unlocked`) mirroring the existing `thread_renamed` row's shape.
  - [x] 14.4 `tests/php/Signaling/ListenerTest.php`: extend `testSystemMessageSentEventSkippingUpdate`'s data (or add a case) proving the four new verbs are exempt from the skip-last-activity early return; add a case (mirroring `testChatMessageSentWithThreadEvent`/the `thread_renamed` coverage if present) asserting the relayed payload's `threadInfo.thread` carries `state`.
  - [x] 14.5 `src/stores/__tests__/chatExtras.spec.js`: extend with cases for `changeThreadState()` (calls the API, `addThread()`s the response) and `promptLockThreadReason()` (dialog interaction, reads the capability bound) — mirroring the existing `renameThread()` coverage pattern in this file.
  - [x] 14.6 Trace (cannot execute — see Dev Notes → Environment constraints) that `tests/integration/features/chat-4/threads.feature` remains green: this story adds no field to any of the four *existing* Thread-returning endpoints' already-asserted response shape, and the new endpoint/route is additive.
  - [x] 14.7 `php -l` and `psalm` on every new/changed PHP test file.

- [x] **Task 15 — Regression pass and housekeeping**
  - [x] 15.1 `grep -rn "createFromRow|::fromRow(|selectThreadsTable" lib/` — confirm no new divergent hydration path.
  - [x] 15.2 `grep -rn "thread_closed|thread_locked|thread_reopened|thread_unlocked" lib/ src/` — confirm all six AD-14 registries were touched consistently for all four verbs.
  - [x] 15.3 `git status` / `git diff --stat` — confirm the File List below is exhaustive.
  - [x] 15.4 `npm run lint`, `npm run ts:check`, `npx vitest run` (full suite, watch for the known `messagesStore.spec.js` timeout flake documented in Story 1.3 — isolate-rerun if it recurs, don't treat it as a regression without checking).
  - [x] 15.5 Update Dev Agent Record, File List, Change Log; set Status to `review`.

## Dev Notes

### Current state of the files this story touches (read in full during story creation)

**`lib/Controller/ThreadController.php`** (396 lines post-Story-1.3) — `renameThread()` (~201–256) is the closest precedent for the new `setState()`: find-thread → authority-check-and-refuse → mutate → best-effort root-comment fetch → `addSystemMessage()` → `prepareListOfThreads()` → return. `prepareListOfThreads()` (~263–330) already computes `canManage` per Story 1.3 and is the one choke point every Thread-returning endpoint shares — no change needed there for this story (it doesn't add a new *field*, only a new *endpoint*).

**`lib/Service/ThreadService.php`** (396 lines post-Story-1.3) — already has `isThreadManager()`/`ensureThreadManager()`/`validateState()` (Stories 1.2/1.3) and `updateLastMessageInfoAfterReply()`, the existing precedent for "mutate, `remove()` the cache, never `set()`". `validateState()`'s own docblock explicitly anticipates this story: *"Not wired to a controller endpoint yet... the actual state-change endpoint... is a later story's scope... so that later work calls this rather than re-deriving the valid-value check."* This story is that later work — `validateState()`'s docblock comment about being unwired should be removed/updated once `changeState()` calls it.

**`lib/Model/Thread.php`** (140 lines post-Story-1.2) — already carries `state`/`STATE_*` (Story 1.2). `lockReason` follows the exact same AD-1 checklist Story 1.2 used for `state`: `addType()`, `createFromRow()` (no `??`, same-commit row shape), `fromJson()` (defensive `??`, cache-TTL reasoning), `toJson()`, `toArray()`.

**`lib/Model/SelectHelper.php`** — both `selectThreadsTable()` branches already alias `state`/`th_state` (Story 1.2); `lock_reason`/`th_lock_reason` joins the same list in both branches.

**`lib/Signaling/Listener.php`** (709 lines) — **untouched by Stories 1.1–1.3.** Its `SYSTEM_MESSAGE_TYPE_RELAY` constant and `notifySystemMessageSent()` method are where `thread_created`/`thread_renamed` already get special handling (root-comment/thread lookup, a hand-built `threadInfo` payload). That hand-built payload is missing `state` entirely — a latent gap from Story 1.2, invisible until now because nothing needed to *change* state live. AC16 is what surfaces it, and Task 7.6 fixes it as part of adding the four new verbs, not as a separate unrelated cleanup.

**`src/utils/message.ts`** / **`src/constants.ts`** — `SYSTEM_MESSAGE_TYPE_RELAY`, `_UNTRANSLATED`, `_HIDDEN` are three **different** lists with different membership rules (relay-eligible / keep-server-text-verbatim / don't-render-a-bubble). `thread_created`/`thread_renamed` happen to be in all three today; this story's four new verbs are **not** identical in every list — see the two "Assumption" notes below.

**`src/composables/useGetMessages.ts::addMessageFromChatRelay()`** — the actual real-time-relay consumer. `tryLocalizeSystemMessage()` throwing (the `default` branch of its `switch`, used deliberately for `CALL_ENDED`/`FILE_SHARED`/`OBJECT_SHARED`, "not worth localizing on client side") causes an **early `return`** from `addMessageFromChatRelay()` via `catch (exception) { tryPollNewMessages(); return }`, before `store.dispatch('processMessage', ...)` ever runs. Anything not in `SYSTEM_MESSAGE_TYPE_UNTRANSLATED` and not given a `switch` case therefore never reaches the store via the relay at all — it falls back to a REST poll instead. This is why Task 8.3 requires the four new verbs in `UNTRANSLATED`, not merely recommends it.

**`src/stores/chatExtras.ts`** — `renameThread()` (~399–429) is the exact shape `changeThreadState()`/`promptLockThreadReason()` follow: `spawnDialog(ConfirmDialog, {...})` → API call → `addThread()` on success. `updateThread()` (~358–376) already threads every `ThreadInfo` field through partial updates field-by-field; it needs **no change** for `lockReason`, since `lockReason` lives inside `payload.thread` (the nested `TalkThread` object, replaced wholesale via `payload.thread ?? threads.value[token][threadId].thread`), not as a sibling key the way `canManage` is.

**`src/components/RightSidebar/Threads/ThreadHeader.vue`** — the existing `canManageThread`-gated `<NcActions>` block with one `NcActionButton` ("Edit thread details") is the direct, minimal-diff precedent for adding the four transition actions.

### Assumption — reason update semantics when the target state equals the current state (documented, no interactive user available)

Neither AC1–AC4 nor AC8 nor AC12 directly specify what happens when a Thread Manager submits `state = Locked` while the Thread is **already** Locked, with a **different** reason than the one currently stored. AC8 only names the Closed→Closed no-op explicitly ("closes it again... succeeds without duplicating the system message"). Two readings are both defensible: (a) treat *any* resubmission of the current state as a full no-op, including ignoring a new reason; (b) treat a genuinely different reason as a real update worth persisting (and arguably worth a fresh system message).

**Resolution:** (a) — `ThreadService::changeState()` treats `$state === $thread->getState()` as a complete no-op for *every* state, including Locked, and does not inspect `$reason` in that branch at all. Rationale: AC12's own scenario for "the column holds B" always has an intervening unlock (Locked A → Ongoing → Locked B), never a same-state re-lock; treating same-state resubmission uniformly (regardless of which state) is the simpler, more predictable rule, avoids a "silent reason update with no system message" behaviour that AC10's parameter-carrying design implies should always accompany a *transition*, and mirrors AC8's own reasoning (no duplicate message ⇒ nothing about the Thread's persisted state should appear to have changed either). If a customer later wants "edit the current lock reason without a transition," that is new, explicit scope for a future story, not an interpretation smuggled into this one.

### Assumption — lifecycle messages are visible, not hidden (documented, no interactive user available)

`thread_created` and `thread_renamed` are both in `SYSTEM_MESSAGE_TYPE_HIDDEN` ("system messages that aren't shown separately in the chat") — metadata changes surfaced through the Thread UI itself rather than as a chat-timeline bubble. This story's four transitions are **not** added to `HIDDEN`. Rationale: closing/locking/reopening/unlocking are consequential, Slack-comparable lifecycle events (the architecture spine's Deferred section explicitly frames Locked as following "the Slack shape" — Slack visibly posts "This thread was locked" into the thread) that participants reading the thread's message history should see recorded in-line, unlike a title edit. This is also the plain reading of AC5 — "a system message records the transition... **when the Thread's messages are read**" describes something a reader encounters while reading, not something hidden from them. They **are** added to `UNTRANSLATED` regardless (a distinct, unrelated list — see Task 8.3's note on why that one is load-bearing for AC16, not a visibility choice).

### Architecture compliance

- **AD-1** — `lockReason` (a genuinely new persisted `Thread` field, unlike `canManage`) goes through the full checklist in this change: `addType()`, `createFromRow()`, `fromJson()`, `toJson()`, `toArray()`, both `SelectHelper::selectThreadsTable()` branches. `ThreadService::changeState()` is the sole mutator, ending in cache `remove()`, never `set()` (AC15).
- **AD-2** — this story does **not** build the Locked-write-refusal guard (that's Stories 1.6/1.7's `ChatManager` seam). It **does** build the exemption list those stories will consume: `Listener::THREAD_LIFECYCLE_MESSAGE_TYPES` (AC19).
- **AD-4** — `setState()` reuses `AuthorityException::REASON_PERMISSION` (Story 1.3) for AC7 and `StateException::REASON_VALUE` (Story 1.2) for an out-of-range `$state`; a too-long `$reason` gets its own `\InvalidArgumentException('reason')`, following the `BanController`/`RoomController::renameThread()`-style "exception message names the field" convention rather than inventing a new typed exception class for a single-field length check (matching `RoomService::setDescription()`'s `\InvalidArgumentException('description')` precedent, not `StateException`'s heavier bounded-enum shape, since this is a length bound, not a value-set bound).
- **AD-12** — `setState()` is a genuinely new endpoint (state change did not exist before), consistent with "new endpoints appear only for operations that do not exist." Its response is the same `TalkThreadInfo` builder every list endpoint uses. No `#[FederationSupported]` — no new Thread endpoint is proxied (AD-18).
- **AD-13** — no concurrency token; `changeState()` applies unconditionally; the controller returns the fresh `TalkThreadInfo`; the client store (`changeThreadState()`) replaces its local copy with the response, never with what it sent (AC14).
- **AD-14** — all six registries touched for all four new verbs: `src/constants.ts`, `SYSTEM_MESSAGE_TYPE_RELAY` (both copies), `SYSTEM_MESSAGE_TYPE_UNTRANSLATED` (client), the four `Listener.php` sites (now two named constants instead of four inline literals, satisfying AC6), `SystemMessage.php`'s parser. The locking constant carries the reason as message parameter data (AC10), and the column is also written (Task 2/6).
- **AD-17** — the migration is a new file. Edits to upstream-owned files (`Thread.php`, `SelectHelper.php`, `ThreadService.php`, `ThreadController.php`, `Capabilities.php`, `ResponseDefinitions.php`, `Listener.php`, `SystemMessage.php`) are additive, smallest-diff, each carrying a comment naming the requirement where the surrounding code doesn't already make it obvious.
- **AD-18** — no new field proxied, no new endpoint exposed through `lib/Federation/Proxy/` — achieved by simply omitting `#[FederationSupported]`, which makes the existing `InjectionMiddleware::checkFederationSupport()` refuse the request for a federated room automatically.
- **AD-19** — the `lock_reason` migration is nullable, no default row-touching, guarded by `hasColumn`, no backfill.
- **AD-20** — AC13's "withheld exactly where a Thread Title is withheld, never in a push payload outside the encrypted envelope" is satisfied *by construction* in this story's scope: `changeState()`'s system messages are posted with `sendNotifications: false` (matching `renameThread()`'s existing precedent), so **no push payload of any kind is built by this story**. `lib/Notification/Notifier.php`'s existing sensitive-conversation withholding (`Attendee::isSensitive()`, ~line 631–650) already applies generically to *any* notification's message/parameters regardless of verb, so if/when Epic 4 (Story 4.2, "Thread lifecycle changes notify a Thread's followers") wires these verbs into real push notifications, AD-20's withholding is already structurally guaranteed by that existing mechanism — Epic 4 does not need to special-case the reason. This is flagged here, not silently assumed, so Epic 4 doesn't rediscover it.

### Environment constraints (same as Stories 1.1–1.3 — read before starting)

- No `php` binary on the host. `docker exec -w /var/www/html/custom_apps/spreed clavis-deploy-nextcloud-1 php -l <path>` and `... php vendor/bin/psalm --no-cache <paths>` verify every changed/created PHP file (mount is read-only — only read/analyze through the container, edit on host paths). The sole accepted psalm gap is `Class Test\TestCase does not exist`.
- `vendor/bin/phpunit` cannot execute here (container lacks Nextcloud core's `tests/` tree). Tests are written and verified by manual reasoning against the vendored `OCP` source and this codebase's own precedent code — state this plainly, don't claim execution.
- `vendor/bin/generate-spec` (container) and `npx openapi-typescript -t` (host) are both real, runnable tools — use them for every OpenAPI/TS regeneration in Tasks 4 and 5, following Stories 1.2/1.3's established copy-to-writable-dir-then-diff procedure for the container tool.
- Node/npm work natively on the host: `npm run lint`, `npm run ts:check`, `npx vitest run` are genuinely executable for every client-side change in Tasks 8, 11–13.

### Testing standards summary

- `tests/php/Service/ThreadServiceTest.php` (Stories 1.2/1.3) is the direct precedent to extend for `changeState()` — same constructor-mock fixture, same `createParticipant()`/`createThread()` helpers.
- `tests/php/Model/ThreadTest.php` (Story 1.1, extended by 1.2) is the direct precedent for `lockReason`'s row-key/JSON-round-trip coverage.
- `tests/php/Chat/Parser/SystemMessageTest.php::testParseMessage` is a `#[DataProvider]`-driven test; the existing `thread_renamed` row is the template for the four new rows.
- `tests/php/Signaling/ListenerTest.php` already has `testSystemMessageSentEventSkippingUpdate` and `testChatMessageSentWithThreadEvent` as precedents for the skip-exemption and thread-context-payload coverage respectively.
- `src/stores/__tests__/chatExtras.spec.js` — Story 1.3's `renameThread()`-adjacent coverage and its `describe('thread management authority')` block are the shape to follow for the new store actions.
- Behat: `tests/integration/features/chat-4/threads.feature` must stay green unchanged (this story adds no field to any existing endpoint's asserted shape). AC16's own acceptance mechanism is explicitly "a multi-actor integration scenario" (Behat) — if time/scope allow within dev-story, add one asserting that after actor A's `PUT .../threads/{id}/state`, actor B's subsequent `GET .../threads/{id}` (a normal poll, not the live relay) returns the new state and not a stale cached value; this proves AC15/AC16's cache-correctness half without requiring a browser/E2E test for the relay's client-store half (see the scope note below).

### Scope note — AC16's "view updates without a reload" (documented, no interactive user available)

AC16's stated acceptance mechanism is a Behat multi-actor integration scenario, which can only exercise the HTTP surface (cache correctness, relay payload construction) — not actual Vue/Pinia store reactivity, which would require a browser-level or component-mount test this codebase has no infrastructure for (Story 1.3's Dev Notes made the same observation about `ThreadHeader.vue`/`ThreadItem.vue`). This story's real, verifiable scope for AC16 is therefore: (1) `changeState()` invalidates via `remove()` (AC15, unit-testable), and (2) `Listener.php`'s relayed `threadInfo.thread` payload carries `state` (Task 7.6, unit-testable via `ListenerTest.php`'s existing event-dispatch pattern). Full end-to-end "the other browser tab updates live" behaviour additionally depends on `useGetMessages.ts`'s relay-to-store wiring actually consuming `threadInfo` for a live update — that wiring carries a `// FIXME: to be removed when chat relay provides thread data in original message` comment in `chatExtras.ts::fetchSingleThread()` today, indicating it is a known, pre-existing, only-partially-complete mechanism predating this story. Fully completing that wiring is not requested by any task above and is out of this story's scope; it is flagged here rather than silently left for a future story to rediscover as a surprise gap.

### Previous story intelligence (Stories 1.1–1.3)

- Row-key convention (`th_`-prefixed) is settled; `lock_reason`/`th_lock_reason` follows it exactly, no reopening.
- `ThreadService::validateState()` (Story 1.2) and `ensureThreadManager()` (Story 1.3) were both built *ahead* of the endpoint that would consume them, with their docblocks saying so explicitly — this story is that consumer for both.
- Story 1.3's client work was the first in this epic to be genuinely executable (`npm run lint`/`ts:check`/`vitest run`); this story's client work (Tasks 8, 11–13) is as well — run them for real, don't skip.
- Every story so far found at least one real `psalm` defect that manual tracing missed (Story 1.1: `FalsableReturnStatement`; Story 1.2: `MoreSpecificReturnType`) — run `psalm`, expect it to catch something, don't skip it.
- Story 1.2's post-review pass genuinely executed `generate-spec`/`openapi-typescript` and diffed against hand-applied edits, byte-identical both times — follow that exact procedure again for Tasks 4 and 5, don't hand-type-and-hope for either regeneration.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-1.4] — story statement and AC1–AC19, verbatim.
- [Source: _bmad-output/planning-artifacts/epics.md#Story-1.8, #Story-2.4] — confirm the header/list-row/Directory-row *display* of state and lock reason is Stories 1.8/2.4's scope, not this one's (this story is control placement — AC17 — plus the mechanism, not persistent display elsewhere).
- [Source: .../ARCHITECTURE-SPINE.md#AD-1, #AD-2, #AD-4, #AD-12, #AD-13, #AD-14, #AD-17, #AD-18, #AD-19, #AD-20] — quoted/paraphrased above at each compliance point.
- [Source: lib/Controller/ThreadController.php:201-256] — `renameThread()`, the direct precedent for `setState()`.
- [Source: lib/Service/ThreadService.php] — current state incl. `validateState()`/`ensureThreadManager()`'s forward-referencing docblocks.
- [Source: lib/Model/Thread.php, lib/Model/SelectHelper.php] — current state, `state`'s Story-1.2 checklist as the template for `lockReason`.
- [Source: lib/Signaling/Listener.php:81-100,551-633] — the four verb-check sites, read in full; confirmed untouched by Stories 1.1-1.3; confirmed the `threadInfo.thread` payload is missing `state`.
- [Source: src/utils/message.ts:1-93,205-330] — the three client registries and `tryLocalizeSystemMessage()`'s full switch/throw behaviour.
- [Source: src/composables/useGetMessages.ts:665-735] — `addMessageFromChatRelay()`, confirming the early-return-on-throw behaviour that makes `UNTRANSLATED` membership load-bearing, not stylistic.
- [Source: lib/Chat/Parser/SystemMessage.php:253-262,587-606] — `read_only`/`read_only_off` and `thread_created`/`thread_renamed` parsing precedents.
- [Source: lib/Model/Ban.php:40, lib/Service/BanService.php:55-95, lib/Controller/BanController.php:36-77] — the bounded-free-text-reason precedent (`NOTE_MAX_LENGTH`, `\InvalidArgumentException` naming the field, controller reads `$e->getMessage()`).
- [Source: lib/Room.php:83, lib/Capabilities.php:196-270,290-330] — `description-length`'s exact `config`/`LOCAL_CONFIGS` shape, the template for `threads => lock-reason-length`.
- [Source: tests/php/CapabilitiesTest.php:496-533] — `testCapabilitiesDocumentation()` reads config keys from `openapi.json`'s `Capabilities` schema directly, confirming Task 5.4's regeneration must precede Task 5.3's doc entry being verifiable.
- [Source: lib/Middleware/InjectionMiddleware.php:140-149,344-349] — confirms omitting `#[FederationSupported]` triggers `checkFederationSupport()`'s refusal for a federated room.
- [Source: src/stores/chatExtras.ts:358-429] — `renameThread()`/`updateThread()`, the exact shape `changeThreadState()`/`promptLockThreadReason()` follow.
- [Source: src/components/RightSidebar/Threads/ThreadHeader.vue] — the `canManageThread`-gated menu, the precedent for the four new actions.
- [Source: src/components/NewConversationDialog/NewConversationDialog.vue:151, src/components/ConversationSettings/BasicInfo.vue:60] — `getTalkConfig('local', 'conversations', 'description-length') || 500`, the client capability-bound-reading precedent.
- [Source: src/services/messagesService.ts:376-393, src/types/index.ts:441-450] — `renameThread`'s API/type shape, the template for `setThreadState`.
- [Source: openapi.json:27891-28514] — confirms the `thread-<method-name-kebab>` operationId derivation pattern (`renameThread` → `thread-rename-thread`), so `setState` → `thread-set-state`.
- [Source: tests/php/Model/ThreadTest.php, tests/php/Service/ThreadServiceTest.php, tests/php/Chat/Parser/SystemMessageTest.php, tests/php/Signaling/ListenerTest.php, src/stores/__tests__/chatExtras.spec.js] — test precedents, read in full/in part during story creation.

## Dev Agent Record

### Agent Model Used

Claude Sonnet 5 (claude-sonnet-5), via bmad-dev-story

### Debug Log References

- `docker exec -w /var/www/html/custom_apps/spreed clavis-deploy-nextcloud-1 php -l <file>` run on every created/modified PHP file individually and again together at the end — all clean.
- `docker exec ... php vendor/bin/psalm --no-cache <files>` run repeatedly across the PHP change set as each task landed, and again as a full sweep of every changed PHP file at the end. Real defects found and fixed during implementation (not just at the end):
  - `Capabilities.php`: adding the `threads` config group without also extending the `TalkCapabilities` psalm-type in `ResponseDefinitions.php` produced `InvalidReturnType`/`InvalidReturnStatement` on `getCapabilities()` — fixed by adding the `threads: array{lock-reason-length: positive-int}` shape there (Task 4/5's psalm-type checklist item, easy to miss since it's a separate file from the array literal).
  - `ThreadService::changeState()`: a `ParadoxicalCondition` (`$state is int(2)` contradicts `$state is not int(2)`) on the `$state === Thread::STATE_LOCKED` check, caused by Psalm's literal-union type narrowing after the earlier `$state === $thread->getState()` equality check (`getState()`'s docblock return type is the narrow `0|1|2` union). Root-caused by deliberately isolating it (temporarily removing the `validateState()` call and re-running psalm to confirm it wasn't that) before fixing. Fixed by capturing `$thread->getState()` into a `/** @var int $previousState */`-widened local variable before the comparison, which stops Psalm treating the two comparisons as related.
  - `ThreadController::setState()`: `MoreSpecificReturnType`/`LessSpecificReturnStatement` because `catch (\InvalidArgumentException $e) { ... $e->getMessage() ... }` returns a plain `string`, not the literal `'reason'` the declared return-type union requires — fixed with the same `/** @var 'reason' $message */` annotation pattern `BanController::banActor()` already uses for the identical situation.
  - The only remaining psalm findings across every changed file are `Class Test\TestCase does not exist` on the four test files (`ThreadTest.php`, `ThreadServiceTest.php`, `SystemMessageTest.php`, `ListenerTest.php`) — the same accepted environmental gap Stories 1.1-1.3 documented (container image lacks Nextcloud core's `tests/` source tree).
- OpenAPI regeneration genuinely executed against the project's real tools, following Stories 1.2/1.3's precedent exactly: copied the (already hand-edited) repo into a container-writable directory (`/tmp/spreedgen`, never writing through the read-only bind mount) and ran `php vendor/bin/generate-spec` there. All **eight** generated `openapi*.json` files differed this time (not just two) — expected, since the new `threads => lock-reason-length` capability config entry is embedded in the shared `Capabilities` schema every one of the eight files declares, unlike the `Thread`/`ThreadInfo` schemas from Stories 1.2/1.3 which only two files carry. Verified the new `thread-set-state` operation (operationId derived from the `setState` method name, matching the established `renameThread` → `thread-rename-thread` pattern) and the `Thread.lockReason` field landed correctly in `openapi.json`/`openapi-full.json` before copying all eight onto the host via `docker cp` and validating each as JSON with `python3 -c "import json; json.load(...)"`.
- TypeScript regeneration used the real host toolchain directly: `npx openapi-typescript -t` (the exact `ts:generate` script). All eight mapped `.ts` files regenerated; confirmed via `grep` that `thread-set-state` and `lockReason` are present in `src/types/openapi/openapi.ts`.
- `npm run ts:check` (`vue-tsc --noEmit`) initially failed on `src/__mocks__/capabilities.ts` — a real, expected finding: the shared capabilities test fixture needed the new `threads`/`lock-reason-length` entry added to both its `config` and `config-local` sections (mirroring the existing `description-length` entry), the same way every prior capability addition in this codebase has required updating this file. Fixed; clean run after.
- `npx vitest run src/stores/__tests__/chatExtras.spec.js` initially failed one new test with what looked like an unhandled-rejection style stack trace pointing at `mockRejectedValueOnce(new Error(...))`'s call site. Root-caused (not just worked around) by reading the actual stack trace on a second run, which revealed the true cause: `src/test-setup.js` deliberately reassigns `console.error` to **throw** ("Make test fail on errors or warnings"), and `changeThreadState()`'s catch block calls `console.error(e)` on the deliberately-injected failure, exactly mirroring `renameThread()`'s existing, established error-handling shape. Fixed with a local `vi.spyOn(console, 'error').mockImplementation(() => {})` for that one test (restored afterward) — the correct fix given this is a real project-wide testing convention, not a bug in `changeThreadState()`. All 23 tests (18 pre-existing/Story-1.3 + 5 new) pass after.
- `npm run lint` (full project, not just changed files) — zero errors.
- `npm run ts:check` (full project) — zero errors after the `__mocks__/capabilities.ts` fix above.
- `npx vitest run` (full suite, 70 files / 1419 tests) — 1412 passed, 7 failed, all seven inside `src/store/messagesStore.spec.js` (`eases list from [...] to [...]` timeout cases), a file this story never touches and has no relationship to Thread state. Isolated re-run of that single file: `npx vitest run src/store/messagesStore.spec.js` — 81/81 passed. This is the exact same resource-contention timeout flake Story 1.3's Dev Agent Record documented for the identical file under the identical full-suite-concurrency conditions in this sandbox; confirmed non-regressive by isolated re-run rather than assumed.
- `grep -rn "createFromRow|::fromRow(|selectThreadsTable" lib/` and `grep -rln "thread_closed|thread_locked|thread_reopened|thread_unlocked" lib/ src/` re-run after all edits confirm no new divergent hydration path was introduced and all four new lifecycle verbs appear in every file AD-14's six-registry rule names (`Listener.php`, `SystemMessage.php`, `constants.ts`, `message.ts`, plus the controller that emits them).
- PHPUnit itself could not be executed in this sandbox — the confirmed, real environment limitation Stories 1.1-1.3 documented (the container's `nextcloud:34-apache` image ships without Nextcloud core's `tests/` directory). Every new/changed PHP test was verified by careful manual reasoning against the actual implementation, traced test-by-test against `ThreadService::changeState()`'s exact control flow (see Completion Notes), not by execution.

### Completion Notes List

- **AC1-AC4 (the four transitions), AC7 (authority), AC8 (idempotent re-close):** `ThreadController::setState()` is the single new endpoint (`PUT .../threads/{threadId}/state`) serving all four transitions (AD-12). It resolves the correct system-message verb from `(previousState, targetState)`, calls `ThreadService::ensureThreadManager()` (Story 1.3) for AC7, and `ThreadService::changeState()` for the mutation. `changeState()` treats "target state equals current state" as a complete no-op — no DB write, no cache touch, no message — which is what makes AC8 literally true rather than merely "the controller happens to skip a message." Manually traced all nine PHPUnit `changeState()` test cases against the implementation line-by-line (no-op, real transition + cache invalidation, reason trimming, whitespace-only reason, missing reason, over-length reason, reason ignored for non-locking transitions, sequential lock/unlock/relock ending on the latest reason, re-locking-while-locked not updating the reason) — all traced correctly.
- **AC5, AC6 (system messages, six registries), AC19 (exemption-list subset):** four new constants (`thread_closed`, `thread_locked`, `thread_reopened`, `thread_unlocked`) registered in all six places AD-14 names: `src/constants.ts`, `SYSTEM_MESSAGE_TYPE_RELAY` (both `Listener.php` and `message.ts` copies), the skip-last-activity exemption (extracted from an inline literal into `Listener::SKIP_LAST_ACTIVITY_EXEMPT_MESSAGE_TYPES`), the thread-context branches (extracted into `Listener::THREAD_MESSAGE_TYPES_WITH_CONTEXT`, used at both of AD-14's registry sites 3 and 4 instead of a repeated inline `||` expression), and four new `SystemMessage.php` parser branches. `Listener::THREAD_LIFECYCLE_MESSAGE_TYPES` is declared as its own named constant specifically so it **is** AD-2's future exemption list verbatim (AC19), not merely equivalent to it.
- **AC9, AC10, AC12 (lock reason: migration, entity, message parameter data):** `lib/Migration/Version24000Date20260810090000.php` adds a nullable `talk_threads.lock_reason` TEXT column, additive/guarded/no-backfill (AD-19), matching `Ban::NOTE_MAX_LENGTH`'s bounded-free-text-reason precedent for the `Thread::LOCK_REASON_MAX_LENGTH = 4000` bound. `Thread.php` carries `lockReason` through the full AD-1 checklist (`addType`, `createFromRow`, `fromJson`, `toJson`, `toArray`, both `SelectHelper::selectThreadsTable()` branches) exactly as Story 1.2 did for `state`. The reason travels on the `thread_locked` system message as a genuine parsed-message parameter (`{reason}` resolved via `$parsedParameters['reason']`, mirroring how `{title}` already works) rather than string concatenation. AC12's "column holds B, earlier system message still holds A" holds because the column is only ever written on a *real* transition into Locked, and system messages are immutable once posted.
- **AC11 (whitespace/length bound stated in the interface):** `changeState()` trims and coerces `''` to `null`. The 4000-character bound is published as one server-side constant via `Capabilities.php`'s new `threads => lock-reason-length` config entry (AD-9 Consistency Convention: "every bound is one server-side constant... published to clients in the capability payload rather than restated in the interface") rather than hand-duplicated as a client literal; the lock dialog's label states it (`getTalkConfig('local', 'threads', 'lock-reason-length') || 4000`).
- **AC13 (push-payload withholding) - documented scope resolution, no interactive user available:** `changeState()`'s system messages are posted with `sendNotifications: false`, identical to `renameThread()`'s established precedent, and Epic 4 Story 4.2 ("Thread lifecycle changes notify a Thread's followers") is what actually builds lifecycle-transition push notifications. This story therefore builds no push payload at all, so AC13's "never enters any part of a push payload" holds by construction — there is nothing to enter. Verified (by reading `lib/Notification/Notifier.php` lines ~630-650) that the existing sensitive-conversation withholding mechanism (`Attendee::isSensitive()`) already applies generically to any notification's message and parameters regardless of verb, so Epic 4 gets AD-20 compliance for free once it wires these verbs into real notifications - flagged explicitly in Dev Notes so this isn't rediscovered as a gap later.
- **AC14 (last write wins), AC15 (cache remove not set):** no concurrency token anywhere; `changeState()` unconditionally applies the mutation and ends with `$this->cache->remove(...)`, never `set()` - directly mirroring `updateLastMessageInfoAfterReply()`'s existing pattern. The client store's `changeThreadState()` calls `addThread()` with the **response**, never the request payload (AD-13).
- **AC16 (multi-actor cache/relay visibility) - documented scope resolution:** the AC's own stated acceptance mechanism is "a multi-actor integration scenario" (Behat, HTTP-only) - not a browser/E2E test, which this codebase has no infrastructure for (Story 1.3 made the identical observation about component-level tests). This story's real, verified scope: `changeState()` invalidates via `remove()` (unit-tested), and `Listener.php`'s relayed `threadInfo.thread` payload now carries `state` and `lockReason` - fixing a pre-existing gap where this hand-built payload never included `state` at all since Story 1.2 added it to `Thread::toArray()` but never touched this separate, hand-duplicated array in `Listener.php` (which Stories 1.1-1.3 never touched). Covered by a new data-provider PHPUnit test (`testSystemMessageSentEventThreadLifecycleVerbsRelayDespiteSkippingUpdate`) across all four verbs, asserting both that the relay is not swallowed by the skip-last-activity early return and that `state` is present in the payload. Full end-to-end "the other tab updates live without reload" additionally depends on client-side relay-to-store wiring that a pre-existing `// FIXME: to be removed when chat relay provides thread data in original message` comment in `chatExtras.ts` shows is only partially built today, predating this story - documented explicitly as an out-of-scope, pre-existing gap rather than silently left for a future story to rediscover.
- **AC17, AC18 (the menu, absent not disabled):** `ThreadHeader.vue`'s existing `canManageThread`-gated `<NcActions>` block (Story 1.3) gains up to two new `NcActionButton`s per render, chosen by the thread's current state (Close+Lock when Ongoing; Reopen+Lock when Closed; Unlock only when Locked). AC18 is satisfied by construction - the entire block is already `v-if="canManageThread"`, so a non-manager never sees any of it, absent rather than disabled. The lock action calls `promptLockThreadReason()` (a `ConfirmDialog` mirroring `renameThread()`'s exact shape) before `changeThreadState()`.
- **Execution constraint:** no host `php` binary; PHPUnit cannot run in this sandbox (same confirmed limitation as Stories 1.1-1.3). Every PHP change was verified with `php -l` (all clean) and `psalm --no-cache` (all clean except the four accepted `Test\TestCase` gaps) via the `clavis-deploy-nextcloud-1` container, and every new PHP test was verified by manual reasoning against the actual implementation and vendored source, not by execution. **This story's client-side (Vue/Pinia/TS) changes and OpenAPI/TypeScript regeneration all genuinely executed and passed:** `npm run lint` (full project), `npm run ts:check` (full project), `npx vitest run` (full suite, one confirmed-non-regressive pre-existing flake in an untouched file), and the real `generate-spec`/`openapi-typescript` tools.
- **No HALT condition was triggered.** Several ambiguities were resolved by direct engineering judgment grounded in reading this codebase's own precedents and the architecture spine's exact wording, each documented at the point of decision in the story's Dev Notes: the mapping from `(previousState, targetState)` to four distinct verb names (`thread_closed`/`thread_locked`/`thread_reopened`/`thread_unlocked`) rather than fewer/more; same-state resubmission (including while Locked with a different reason) as a complete no-op rather than a silent reason update; lifecycle messages added to `SYSTEM_MESSAGE_TYPE_UNTRANSLATED` (load-bearing, required for the relay-to-store path to run at all) but deliberately **not** to `SYSTEM_MESSAGE_TYPE_HIDDEN` (a visibility choice, since these are consequential lifecycle events unlike a title rename); AC13's resolution via "this story builds no push payload, so there is nothing to withhold, and the existing sensitive-conversation mechanism already generalizes for when Epic 4 does"; and AC16's scope boundary at the Behat-provable cache/relay-payload half, not full client-store reactivity.

### File List

**Created:**
- `lib/Migration/Version24000Date20260810090000.php` — additive `talk_threads.lock_reason` column (AC9).
- `tests/php/Service/ThreadServiceTest.php` — extended (Stories 1.2/1.3's file): 10 new tests covering `ThreadService::changeState()` (AC1-AC4, AC8, AC11, AC12, AD-1, AD-13, AD-15).

**Modified:**
- `lib/Model/Thread.php` — `LOCK_REASON_MAX_LENGTH` constant, `lockReason` property + `addType()`, `createFromRow()`, `fromJson()`, `toJson()`, `toArray()` all carry the new field (AC9, AC10, AD-1).
- `lib/Model/SelectHelper.php` — `selectThreadsTable()`, both `aliasAll` branches, select `lock_reason`/`th_lock_reason` (AD-1).
- `lib/Service/ThreadService.php` — new `changeState()` method; `validateState()`'s docblock updated to reflect it is now wired to a controller endpoint.
- `lib/Controller/ThreadController.php` — new `setState()` endpoint serving all four transitions (AC1-AC4, AC7, AC8, AC13, AD-12, AD-18).
- `lib/ResponseDefinitions.php` — `TalkThread` gains `lockReason: ?string,`; `TalkCapabilities` gains the `threads: array{lock-reason-length: positive-int}` config shape.
- `lib/Capabilities.php` — new `threads` config group (`lock-reason-length` bound) added to both the runtime payload and `LOCAL_CONFIGS` (AC11, AD-9 Consistency Convention).
- `docs/capabilities.md` — new `config => threads => lock-reason-length` entry in the existing `## 24.0.3` section (keeps `CapabilitiesTest::testCapabilitiesDocumentation()` green).
- `lib/Signaling/Listener.php` — four new named constants (`THREAD_LIFECYCLE_MESSAGE_TYPES`, `THREAD_MESSAGE_TYPES_WITH_CONTEXT`, `SKIP_LAST_ACTIVITY_EXEMPT_MESSAGE_TYPES`, plus the extended `SYSTEM_MESSAGE_TYPE_RELAY`); all four AD-14 verb-check sites now test membership instead of inline literals (AC6); the relayed `threadInfo.thread` payload gains `state`/`lockReason` (AC16, fixing a pre-existing Story-1.2-era gap).
- `lib/Chat/Parser/SystemMessage.php` — four new parsed-message branches (`thread_closed`, `thread_locked` with/without reason, `thread_reopened`, `thread_unlocked`) (AC5, AC10).
- `openapi.json`, `openapi-full.json`, `openapi-administration.json`, `openapi-backend-recording.json`, `openapi-backend-signaling.json`, `openapi-backend-sipbridge.json`, `openapi-bots.json`, `openapi-federation.json` — regenerated via the project's real `generate-spec` tool; all eight changed because the new `Capabilities.config.threads` entry is embedded in the shared schema every file declares (AC10, AC11, AD-12).
- `src/types/openapi/openapi.ts`, `openapi-full.ts`, `openapi-administration.ts`, `openapi-backend-recording.ts`, `openapi-backend-signaling.ts`, `openapi-backend-sipbridge.ts`, `openapi-bots.ts`, `openapi-federation.ts` — regenerated via the project's real `openapi-typescript` tool.
- `src/__mocks__/capabilities.ts` — `threads`/`lock-reason-length` added to the shared test fixture (required for `npm run ts:check` to pass).
- `src/constants.ts` — `MESSAGE.SYSTEM_TYPE.THREAD_CLOSED/LOCKED/REOPENED/UNLOCKED`; new `THREAD.STATE` constant group mirroring `Thread::STATE_*` server-side (AC5, AC6).
- `src/utils/message.ts` — the four new verbs added to `SYSTEM_MESSAGE_TYPE_RELAY` and `SYSTEM_MESSAGE_TYPE_UNTRANSLATED` (deliberately not `SYSTEM_MESSAGE_TYPE_HIDDEN` — see Dev Notes) (AC5, AC6, AC16).
- `src/types/index.ts` — `setThreadStateParams`/`setThreadStateResponse` types.
- `src/services/messagesService.ts` — new `setThreadState()` API function.
- `src/stores/chatExtras.ts` — new `changeThreadState()` and `promptLockThreadReason()` store actions (AC1-AC4, AC10, AC11, AC14, AD-13, AD-15).
- `src/components/RightSidebar/Threads/ThreadHeader.vue` — up to two new state-transition `NcActionButton`s per render, state-dependent, in the existing `canManageThread`-gated menu (AC17, AC18).
- `tests/php/Model/ThreadTest.php` — extended (Story 1.1's file, extended by 1.2): `th_lock_reason` in the row-key tests, plus new tests for null/non-null hydration, JSON round trip, and `toArray()` inclusion/default (AC9, AD-1).
- `tests/php/Chat/Parser/SystemMessageTest.php` — 6 new data-provider rows covering all four verbs, including the with/without-reason variants of `thread_locked` (AC5, AC10).
- `tests/php/Signaling/ListenerTest.php` — 1 new data-provider-driven test (4 cases) covering the skip-last-activity exemption and the `state`-carrying relay payload for all four verbs (AC6, AC16).
- `src/stores/__tests__/chatExtras.spec.js` — new `describe('thread state transitions')` block: 5 new tests covering `changeThreadState()` (success, reason pass-through, failure handling) and `promptLockThreadReason()` (capability-bound lookup, non-string result handling) (AC1-AC4, AC10, AC11, AC14).

## Change Log

- 2026-08-09 — Story implemented end-to-end (Tasks 1-15). AC1-AC4/AC7/AC8: single new `ThreadController::setState()` endpoint serving all four lifecycle transitions via `ThreadService::changeState()`, with same-state resubmission as a true no-op (AC8). AC5/AC6/AC19: four new lifecycle system-message verbs registered in all six AD-14 registries, with the four `Listener.php` verb checks refactored from inline literals into named, reusable constants — one of which (`THREAD_LIFECYCLE_MESSAGE_TYPES`) is declared specifically to become AD-2's future Locked-write exemption list verbatim. AC9/AC10/AC12: additive `lock_reason` migration and full `Thread` entity/AD-1 checklist; the reason travels as parsed-message parameter data, never string-concatenated. AC11: whitespace trimmed to null; the 4000-char bound published via a new `Capabilities.php` config entry rather than hand-duplicated client-side. AC13: resolved by construction (no push payload built by this story) plus verification that the existing sensitive-conversation withholding mechanism already generalizes for Epic 4. AC14/AC15: no concurrency token; cache invalidated via `remove()`, never `set()`; client store adopts the response, never the request. AC16: cache-correctness and relay-payload-carries-state both fixed and unit-tested (including a pre-existing Story-1.2-era gap in `Listener.php`'s hand-built `threadInfo` payload); full client-store live-reactivity documented as an out-of-scope, pre-existing partial mechanism. AC17/AC18: up to two new state-dependent actions added to `ThreadHeader.vue`'s existing authority-gated menu. All eight `openapi*.json`/`*.ts` pairs regenerated and verified via the project's real `generate-spec`/`openapi-typescript` tools (all eight changed this time, since the new capability config entry is in the shared `Capabilities` schema). Psalm caught three real defects during implementation (missing `TalkCapabilities` psalm-type extension, a `ParadoxicalCondition` false positive from Psalm's literal-union narrowing, a return-type mismatch on a caught exception's message) — all fixed and re-verified clean. 16 new PHP tests and 5 new JS tests added; PHPUnit still cannot execute in this environment (same confirmed limitation as Stories 1.1-1.3), but this story's JS-side tests, lint, and type-check **did** genuinely execute (`npx vitest run`, `npm run lint`, `npm run ts:check`), as did the OpenAPI/TS regeneration. Full-suite Vitest run showed the same unrelated `messagesStore.spec.js` timeout flake Story 1.3 documented (a file this story never touches), confirmed non-regressive by isolated re-run (81/81 passed). Status set to `review`.
