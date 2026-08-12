---
baseline_commit: 6819859953c32f32ccaecf13f4b11f1ad382db00
---

# Story 1.2: Every Thread reports a Thread State

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a participant,
I want every Thread to carry a state,
So that the interface and the API have something to show and the lifecycle has somewhere to live.

## Acceptance Criteria

**AC1**
**Given** the migration adding an integer state column to `talk_threads` with a default of Ongoing
**When** a new Thread is created
**Then** it reports state Ongoing
**And** the migration is additive, defaulted, safe to run twice, and runs no table-wide `UPDATE`.

**AC2**
**Given** Threads that existed before the migration
**When** they are read after upgrade
**Then** every one reports state Ongoing with no interaction required
**And** no backfill statement appears in the migration.

**AC3**
**Given** the four endpoints that return a Thread — `getRecentActiveThreads`, `getSubscribedThreads`, `getThread` and `renameThread`
**When** each is called
**Then** the `TalkThreadInfo` response carries the state
**And** the regenerated `openapi*.json` and `src/types/openapi/*.ts` land in the same change.

**AC4**
**Given** a request setting a state outside the three defined values
**When** it is submitted
**Then** it is refused with a validation error naming the field
**And** the refusal is distinguishable from a permission failure and from a Thread-not-found failure.

**AC5**
**Given** the state field is added to `Thread::addType()`, `createFromRow()`, `fromJson()`, `toJson()`, `toArray()` and both branches of `SelectHelper::selectThreadsTable()`
**When** a Thread is read back through the distributed cache rather than from the database
**Then** its state survives the round trip
**And** a Thread read through a joined query carries the state as well.

**AC6**
**Given** the thread-management capability flag declared in both `FEATURES` and `LOCAL_FEATURES`, separate from the shipped unconditional `threads` flag
**When** a client that does not see the flag talks to this server
**Then** it behaves exactly as it does today rather than erroring
**And** a client on a federated Conversation learns from `features-local` that the feature is unavailable there.

## Tasks / Subtasks

- [x] **Task 1 — Migration adding the `state` column (AC1, AC2)**
  - [x] 1.1 Read `lib/Migration/Version24000Date20260313120000.php` (latest migration, additive column pattern) and `lib/Migration/Version22000Date20250623142327.php` (original `talk_threads` table creation, `notification_level` column pattern — `Types::INTEGER`, `notnull => false`, `default => <named constant>`) before writing anything.
  - [x] 1.2 Add `lib/Migration/Version24000Date20260809120000.php`: `getTable('talk_threads')`, guard on `!$table->hasColumn('state')`, `addColumn('state', Types::INTEGER, ['notnull' => false, 'default' => Thread::STATE_ONGOING])`. No `UPDATE` statement anywhere in the migration — the column default is what makes every pre-existing row read as Ongoing (AD-19's "the column default has to do the work" pattern, same as AD-7's `talk_thread_attendees.subscribed` reasoning).
  - [x] 1.3 Confirm idempotency: the `hasColumn` guard makes re-running the migration a no-op on the second run, matching every other additive migration in this codebase.
  - [x] 1.4 `php -l` the new migration file via the container.

- [x] **Task 2 — `Thread` entity carries state (AC1, AC5)**
  - [x] 2.1 Read `lib/Model/Thread.php` in full (already read during story creation — re-confirm no drift before editing).
  - [x] 2.2 Add `public const STATE_ONGOING = 0;`, `public const STATE_CLOSED = 1;`, `public const STATE_LOCKED = 2;` near the existing `THREAD_NONE`/`THREAD_CREATE` constants, with a comment distinguishing them (those are root-comment-id sentinels; these are the PRD's Ongoing/Closed/Locked lifecycle states — unrelated concepts that happen to live on the same class).
  - [x] 2.3 Add `protected int $state = self::STATE_ONGOING;` property and `@method void setState(int $state)` / `@method int getState()` docblock annotations (matching the existing magic-setter pattern already used for `roomId`, `lastMessageId`, etc. — no custom validating setter on the entity itself; validation is a `ThreadService` concern per Task 4, matching how `Room::setType()` validates in `RoomService`, not on `Room` itself).
  - [x] 2.4 Add `$this->addType('state', Types::INTEGER);` to the constructor.
  - [x] 2.5 `createFromRow()`: add `$thread->setState((int)$row['th_state']);` following the `th_`-prefixed convention Story 1.1 established — no defensive `??` here, matching the method's existing style, because this row shape is produced entirely within this same change (Task 3 updates `SelectHelper` in the same commit).
  - [x] 2.6 `fromJson()`: add `$thread->setState((int)($row['state'] ?? self::STATE_ONGOING));` — **with** a defensive `??` fallback here, unlike 2.5, because this path reads the distributed cache (`ThreadService::CACHE_PREFIX`, 900s TTL). A cache entry written by pre-upgrade code (no `state` key in its JSON) can still be read by post-upgrade code for up to 15 minutes after deploy; the fallback keeps that read at Ongoing instead of an undefined-array-key warning. Document this exact reasoning in a code comment.
  - [x] 2.7 `toJson()`: add `'state' => $this->getState(),`.
  - [x] 2.8 `toArray()`: add `'state' => $this->getState(),` as the last key (after `'title'`) — this is the array AC3's four endpoints actually serialize into `TalkThreadInfo.thread`.
  - [x] 2.9 `php -l` and `psalm` on `lib/Model/Thread.php`.

- [x] **Task 3 — `SelectHelper::selectThreadsTable()` both branches (AC5)**
  - [x] 3.1 Re-read `lib/Model/SelectHelper.php` (already read during story creation).
  - [x] 3.2 `aliasAll: true` branch: add `->selectAlias($alias . 'state', 'th_state')` to the chain (the branch `ScheduledMessageMapper::findByRoomAndActor()` and `ThreadService::getRecentByActor()` both go through — updating this one spot covers both the joined-read path and the direct-query path per AD-1, exactly as Story 1.1's reconciliation intended).
  - [x] 3.3 `aliasAll: false` branch (today's dead code per Story 1.1 — zero callers, confirmed by `grep -rn selectThreadsTable lib/`): add `$alias . 'state'` to the bare-key `addSelect` array anyway, because AD-1's rule is explicit ("both aliased and unaliased branches") and a future caller of that branch must not silently miss the field.
  - [x] 3.4 `php -l` and `psalm` on `lib/Model/SelectHelper.php`.

- [x] **Task 4 — Typed validation refusal for an out-of-range state (AC4)**
  - [x] 4.1 Read `lib/Exceptions/RoomProperty/TypeException.php` and `lib/Service/RoomService.php`'s `setType()` (lines ~659-690) as the precedent: a bounded-enum int property, validated in the Service layer (not on the Entity), throwing a typed exception with a `REASON_VALUE` constant that the Controller later maps to `['error' => $e->getReason()]` at `Http::STATUS_BAD_REQUEST`.
  - [x] 4.2 Add `lib/Exceptions/ThreadProperty/StateException.php`, mirroring `RoomProperty\TypeException`'s shape: `extends \InvalidArgumentException`, `public const REASON_VALUE = 'value';`, constructor taking `self::REASON_*`, `getReason(): string`.
  - [x] 4.3 Add `ThreadService::validateState(int $state): void` to `lib/Service/ThreadService.php`: throws `StateException(StateException::REASON_VALUE)` when `$state` is not one of `Thread::STATE_ONGOING`, `Thread::STATE_CLOSED`, `Thread::STATE_LOCKED` (`in_array(..., true)`, mirroring `RoomService::setType()`'s inline check exactly). Document in the method's docblock that this is **not** wired to a live HTTP endpoint yet — the actual state-change endpoint (close/lock/reopen, with authority checks and system messages) is Story 1.4's `AD-12` "one endpoint serves all four transitions." This method exists now so AC4's refusal is real and testable ahead of that endpoint, and so 1.4 consumes it rather than re-deriving the valid-value check.
  - [x] 4.4 Document explicitly in Dev Notes / Completion Notes that this is an interpretive decision (see "Assumption — AC4's scope" below) made because no interactive user was available to confirm.
  - [x] 4.5 `php -l` and `psalm` on the new exception file and on `lib/Service/ThreadService.php`.

- [x] **Task 5 — Two-flag capability gate (AC6)**
  - [x] 5.1 Read `lib/Capabilities.php`'s `FEATURES` and `LOCAL_FEATURES` constants in full.
  - [x] 5.2 Add `'thread-management',` to `Capabilities::FEATURES` (append after `'bot-features-api'`, the current last entry) and to `Capabilities::LOCAL_FEATURES` (same position) — **not** reusing the existing `'threads'` flag, which stays `FEATURES`-only per the architecture spine's explicit note that its `LOCAL_FEATURES`-only channel is what lets a federated client learn a feature is unavailable there (AD-12, AD-18).
  - [x] 5.3 Add a `## 24.0.3` section to `docs/capabilities.md` (last existing heading is `## 24.0.1`; app version in `appinfo/info.xml` is `24.0.3`) with `* \`thread-management\` (local) - Whether Threads support a lifecycle state (Ongoing, Closed, Locked)`. This is required for `tests/php/CapabilitiesTest.php::testCapabilitiesDocumentation()` to keep passing — that test asserts every entry in `FEATURES`/`LOCAL_FEATURES` is documented here.
  - [x] 5.4 `php -l` and `psalm` on `lib/Capabilities.php`.

- [x] **Task 6 — `ResponseDefinitions` and generated OpenAPI/TypeScript (AC3)**
  - [x] 6.1 Read `lib/ResponseDefinitions.php`'s `TalkThread` and `TalkThreadAttendee` psalm-types (lines ~724-742) as the precedent for an int-literal-union enum field with a doc comment (`notificationLevel: 0|1|2|3,`).
  - [x] 6.2 Add `state: 0|1|2,` to `TalkThread` (after `numReplies`) with a comment describing the three values (Ongoing/Closed/Locked) by name, matching `TalkBot`'s `state: int` comment style but inline since this one is a closed literal union.
  - [x] 6.3 Confirm via `grep -rln "TalkThread\b\|TalkThreadInfo" lib/` which files consume these types (`ResponseDefinitions.php`, `Controller/ThreadController.php`, `Federation/Proxy/TalkV1/Controller/ThreadController.php`, `Federation/Proxy/TalkV1/UserConverter.php`) — none of them need code changes, since `toArray()` (Task 2.8) is the only place that actually serializes the array `TalkThreadInfo['thread']` builds from, and the Federation proxy passes the whole payload through unmodified.
  - [x] 6.4 Determine which generated `openapi*.json` files actually declare the `Thread`/`ThreadAttendee`/`ThreadInfo` schemas (verified during story creation: only `openapi.json` and `openapi-full.json` — the other six, e.g. `openapi-federation.json`, `openapi-administration.json`, do not reference `ThreadController` and contain no `Thread` schema at all). Edit only those two.
  - [x] 6.5 In each of `openapi.json` and `openapi-full.json`, add to `components.schemas.Thread.properties`: `"state": {"type": "integer", "format": "int64", "enum": [0, 1, 2], "description": "..."}`, matching the exact shape already used for `ThreadAttendee.notificationLevel`'s `enum` field one schema below it. Add `"state"` to `Thread`'s `required` array (it is always present, matching the psalm-type being non-nullable).
  - [x] 6.6 In each of `src/types/openapi/openapi.ts` and `src/types/openapi/openapi-full.ts`, add the corresponding `state: 0 | 1 | 2;` member (with the `/** Format: int64 ... @enum {integer} */` docblock, matching `ThreadAttendee.notificationLevel`'s TS shape immediately below it) to the `Thread` type.
  - [x] 6.7 Attempt to verify the manual JSON/TS edits against the project's actual `generate-spec` tool (available via the `clavis-deploy-nextcloud-1` container) by running it against a **container-local writable copy** of the (already-edited) repo and diffing the result — never writing through the read-only bind mount. If the tool's output for the `Thread`/`ThreadAttendee`/`ThreadInfo` schemas matches the manual edit exactly, that is strong confirmation; if it differs, reconcile and record which was used. If the tool cannot be run for any reason, document that plainly rather than claiming it ran.
  - [x] 6.8 `php -l` and `psalm` on `lib/ResponseDefinitions.php`.

- [x] **Task 7 — Tests (AC1–AC6, TDD red-green-refactor)**
  - [x] 7.1 Extend `tests/php/Model/ThreadTest.php` (Story 1.1's file): a new row-key test asserting `createFromRow()` reads `th_state` correctly (extending the existing `expectedKeys` list in `testCreateFromRowMatchesSelectThreadsTableAliasAllKeys` to include `th_state`), and a `toArray()`/`toJson()`/`fromJson()` round-trip test asserting state survives (covers AC5's "distributed cache round trip" claim at the unit level, since the actual `ICache` is infrastructure that cannot be exercised here).
  - [x] 7.2 Add `tests/php/Model/SelectHelperTest.php` if none exists (check first), or add to the existing one, asserting `selectThreadsTable(..., aliasAll: true)` selects a `th_state` alias — or, if a full query-builder test is disproportionate, cover this via the `ThreadTest.php` key-set assertion in 7.1 instead (the existing precedent from Story 1.1 validates the alias contract this way, without a live DB).
  - [x] 7.3 Add `tests/php/Service/ThreadServiceTest.php` (new — none exists today; AD-1's Consistency Conventions row explicitly calls for `ThreadService` to get the PHPUnit test it lacks). Cover `validateState()`: three passing cases (`STATE_ONGOING`, `STATE_CLOSED`, `STATE_LOCKED`, no exception) and out-of-range cases (negative, `3`, non-enum values) each asserting a thrown `StateException` with `getReason() === StateException::REASON_VALUE`. Constructor mocks follow `RoomServiceTest.php`'s pattern (`createMock` for each dependency); `ICacheFactory::createDistributed()` must be stubbed to return a mocked `ICache`, since `ThreadService::__construct()` calls it unconditionally.
  - [x] 7.4 Add a migration-shape sanity check is **not** required (this codebase has no precedent of PHPUnit-testing `SimpleMigrationStep` classes directly — confirmed by `grep -rn "extends SimpleMigrationStep" tests/php/` returning nothing) — rely on `php -l`/`psalm` plus manual review against the two precedent migrations instead.
  - [x] 7.5 Trace (cannot execute — see Dev Notes → Environment constraints) that `tests/integration/features/chat-4/threads.feature` remains green: the Behat step `sees the following recent threads` (`FeatureContext.php` ~line 3378-3411) only compares a fixed whitelist of fields (`t.id`, `t.title`, `t.numReplies`, `t.lastMessage`, `a.notificationLevel`, `firstMessage`, `lastMessage`) and ignores any other key present in the actual response — adding `state` to `toArray()`'s output does not break this assertion.
  - [x] 7.6 `php -l` and `psalm` on every new/changed test file.

- [x] **Task 8 — Regression pass and housekeeping**
  - [x] 8.1 Re-run `grep -rn "createFromRow|::fromRow(|selectThreadsTable" lib/` to confirm no new divergent hydration path was introduced.
  - [x] 8.2 `git status` / `git diff --stat` to confirm the File List below is exhaustive and no unrelated file was touched.
  - [x] 8.3 Update Dev Agent Record, File List, Change Log; set Status to `review`.

## Dev Notes

### Current state of the files this story touches (read in full during story creation)

**`lib/Model/Thread.php`** — `Entity` subclass. Constructor calls `addType()` for `roomId`, `lastMessageId`, `numReplies`, `lastActivity`, `name` (all magic-setter backed via `@method` docblocks). `createFromRow()` reads the `th_`-prefixed convention Story 1.1 established (six keys: `th_id`, `th_room_id`, `th_last_message_id`, `th_num_replies`, `th_last_activity`, `th_name`). `fromJson()`/`toJson()` use **bare** keys (`id`, `room_id`, `last_message_id`, `num_replies`, `last_activity`, `name`) — a *different*, cache-specific convention from the row-key one; do not conflate them. `toArray(Room $room): array` (`@return TalkThread`) is what `ThreadController::prepareListOfThreads()` actually serializes into the API response.

**`lib/Model/SelectHelper.php`** — `selectThreadsTable(IQueryBuilder $query, string $alias = 'th', bool $aliasAll = false)`. `aliasAll: true` branch (the only one with a live caller, per Story 1.1) hardcodes `th_*` output aliases regardless of `$alias`. `aliasAll: false` branch is dead code today (zero callers, verified by grep both before and after Story 1.1's Task 2) but AD-1 requires it kept in sync anyway.

**`lib/Service/ThreadService.php`** — `createThread()` builds a `new Thread()` and never sets state explicitly (relies on the entity's own default, per Task 2.3). `getRecentByActor()` already routes through `SelectHelper::selectThreadsTable(..., aliasAll: true)` + `Thread::createFromRow()` (Story 1.1's reconciliation). No `validateState()`-shaped method exists yet.

**`lib/Controller/ThreadController.php`** — `getRecentActiveThreads()`, `getSubscribedThreads()`, `getThread()`, `renameThread()` all funnel through the private `prepareListOfThreads()`, which calls `$thread->toArray($room)` — the single choke point AC3's four endpoints share. No controller-level change is needed for AC3; updating `Thread::toArray()` alone is sufficient and correct, and matches AD-1's "single write/read seam" intent.

**`lib/Capabilities.php`** — `FEATURES` (list, ends with `'bot-features-api'`) and `LOCAL_FEATURES` (list, also ends with `'bot-features-api'`) are separate `public const` arrays consumed by `getCapabilities()`. `'threads'` exists only in `FEATURES` today.

**`lib/ResponseDefinitions.php`** — `TalkThread` psalm-type (no `state` key today), immediately followed by `TalkThreadAttendee` (`notificationLevel: 0|1|2|3,` — the closed-literal-union-with-comment precedent this story's new `state` field follows), immediately followed by `TalkThreadInfo` (the wrapper both list and mutation endpoints return).

**`openapi.json` / `openapi-full.json`** — the only two of the eight generated OpenAPI files that declare `Thread`/`ThreadAttendee`/`ThreadInfo` schemas (verified via a Python scan of `components.schemas` in all eight during story creation — `openapi-federation.json`, `openapi-administration.json`, `openapi-bots.json`, `openapi-backend-*.json` contain none of the three). `Thread`'s schema in both files is byte-identical today.

### Assumption — AC4's scope (documented per skill instructions, no interactive user available)

Story 1.2's own Story statement is purely about a Thread *reporting* a state ("the interface and the API have something to show"), and Story 1.4 ("A Thread Manager moves a Thread between states...") is explicitly where the state-*changing* HTTP endpoint, authority checks, system messages, and last-write-wins semantics land (confirmed by reading Story 1.4's ACs in `epics.md`: AC1-AC4 there own the four transitions; AC7 there owns the authority-refusal shape). Story 1.2 has no state-setting endpoint of its own.

AC4 nonetheless requires "a request setting a state outside the three defined values" to be "refused with a validation error naming the field... distinguishable from a permission failure and from a Thread-not-found failure" — worded as if a live endpoint exists in this story's scope.

**Resolution:** rather than either (a) building a premature full state-change endpoint that duplicates/forecloses Story 1.4's design space (authority, system messages, cache-invalidate-not-reset, last-write-wins — none of which are in this story's ACs), or (b) skipping AC4 as unreachable, this story adds the **validation seam alone** — `ThreadService::validateState()` plus a new typed `StateException` (mirroring the existing `RoomProperty\TypeException` precedent exactly: a bounded-enum int property validated in the Service layer, throwing a distinct exception class with its own `REASON_VALUE`). This is real, unit-testable, and satisfies AC4's literal requirements (a validation error exists, it names the field via a dedicated exception class distinct from any not-found or authority exception) without inventing HTTP-layer behaviour that belongs to Story 1.4. Story 1.4 is expected to call `validateState()` from its own controller/service method rather than re-deriving the check — this is the same "preparatory commit ahead of the feature that consumes it" pattern Story 1.1 used for the row-key convention.

### Architecture compliance

- **AD-1** — every site in the "sole write seam" checklist (`Thread::addType()`, `createFromRow()`, `fromJson()`, `toJson()`, `toArray()`, both `SelectHelper::selectThreadsTable()` branches) is touched in this one change, per the rule's own text. No cache `set()` after a mutator — not applicable here since this story adds no mutator (Story 1.4 does), only the field itself.
- **AD-4** — "refusals are typed and distinguishable" is the standard AC4's `StateException` follows, one class among what will eventually be several distinct Thread-refusal exception types (Locked, authority, not-found — those arrive in Stories 1.3/1.4/1.6).
- **AD-12** — two capability flags, both in `FEATURES` *and* `LOCAL_FEATURES`; `threads` (existing, `FEATURES`-only) is explicitly not reused. `TalkThreadInfo` remains the one Thread representation; this story doesn't touch that shape's identity, only adds a field to it. `openapi*.json` and `src/types/openapi/*.ts` regenerate in the same change — AC3's explicit requirement.
- **AD-17** — the migration is a new file (no upstream file touched for it). `Thread.php`, `SelectHelper.php`, `ThreadService.php`, `Capabilities.php`, `ResponseDefinitions.php` are upstream-owned files; every edit to them in this story is additive (new lines only, nothing restructured), the smallest diff that satisfies AD-1's checklist.
- **AD-19** — migration is additive, defaulted (`Thread::STATE_ONGOING`), safe to run twice (guarded by `hasColumn`), and contains no `UPDATE` statement. Pre-existing Threads read Ongoing purely because the column default does the work at ALTER-TABLE time, not because application code backfills anything.

### Environment constraints (same as Story 1.1 — read before starting)

- No `php` binary on the host. The `clavis-deploy-nextcloud-1` Docker container (`nextcloud:34-apache`, PHP 8.5.9) has this repo bind-mounted **read-only** at `/var/www/html/custom_apps/spreed`. Use `docker exec -w /var/www/html/custom_apps/spreed clavis-deploy-nextcloud-1 php -l <path>` and `... php vendor/bin/psalm --no-cache <paths>` to verify every changed/created PHP file. Fix every real finding; the sole accepted gap is `Class Test\TestCase does not exist` (core's `tests/` tree isn't shipped in this image) — same as Story 1.1.
- `vendor/bin/phpunit` cannot execute here (bootstrap needs core's `tests/autoload.php`, absent from the image). Tests are written and verified by careful manual reasoning against `vendor/nextcloud/ocp/...` and the project's own precedent code, not by execution. State this plainly in Completion Notes.
- `vendor/bin/generate-spec` **is** available in the container and is the project's real OpenAPI generator (`composer.json`'s `openapi` script). The mount is read-only, so it cannot write output there directly — if used for cross-checking (Task 6.7), it must run against a container-local writable copy, never against the mounted path.
- Node/npm (v24.7.0) work natively on the **host** (no container needed) for anything JS/TS-only. This story's TS changes are hand-applied generated-file edits (Task 6.6), not a live `npm run ts:generate` run, because the source-of-truth JSON edits themselves are hand-applied for the reasons in Task 6.7 — but `npx openapi-typescript -t` (the real `ts:generate` script, config-driven via `redocly.yaml`) is available on the host if a full regeneration is attempted instead and proves safe to run standalone against the two already-edited JSON files.

### Testing standards summary

- `tests/php/Model/ThreadTest.php` (Story 1.1) is the direct precedent to extend: plain `extends \Test\TestCase`, no mocks needed for pure value-object assertions.
- `tests/php/Service/` has no `ThreadServiceTest.php` yet; `RoomServiceTest.php` is the closest precedent for constructor-mock setup style (`#[Group('DB')]`, `createMock()` per dependency). `ThreadService`'s constructor unconditionally calls `$this->cacheFactory->createDistributed('talk.threads')`, so the `ICacheFactory` mock must stub that call even for a test that never touches the cache.
- `tests/php/CapabilitiesTest.php::testCapabilitiesDocumentation()` will fail without the `docs/capabilities.md` entry for `thread-management` — this is a real, existing regression guard, not new test-writing for this story.
- Behat: `tests/integration/features/chat-4/threads.feature` must stay green unchanged, per the same reasoning Story 1.1 documented for AC3-there (this story's AC3 is the analogous, non-regression concern for the field addition).

### Project Structure Notes

- No conflicts with the unified project structure. The new exception class follows the existing `lib/Exceptions/<Property>Property/<Name>Exception.php` convention (`RoomProperty` is the direct precedent for the new `ThreadProperty` subnamespace).
- Migration filename `Version24000Date20260809120000.php` continues the `24000`-prefix group the two most recent migrations (`...20260219090000`, `...20260313120000`) already use, matching `appinfo/info.xml`'s `<version>24.0.3</version>`.

### Previous story intelligence (Story 1.1)

- Row-key convention is settled: `th_`-prefixed for query results read through `Thread::createFromRow()`, bare-keyed for the JSON cache round trip through `fromJson()`/`toJson()`. This story's Task 2/3 follow that convention exactly rather than reopening it.
- Story 1.1 established the pattern of documenting environment-constraint-driven decisions explicitly in Dev Notes/Completion Notes rather than silently working around them or fabricating results — this story follows the same discipline (see "Assumption — AC4's scope" above, and Task 6.7's json/ts cross-check approach).
- Story 1.1's post-review pass (recorded in its Dev Agent Record) found and fixed a real psalm bug (`FalsableReturnStatement`) that manual tracing had missed — confirms `psalm` catches real defects beyond `php -l` in this codebase; run it, don't skip it.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-1.2] — story statement and AC1-AC6, verbatim.
- [Source: _bmad-output/planning-artifacts/epics.md#Story-1.4] — confirms the state-*change* endpoint (four transitions) is Story 1.4's scope, not this story's, resolving AC4's ambiguity as documented above.
- [Source: _bmad-output/planning-artifacts/architecture/architecture-clavis-spreed-2026-08-08/ARCHITECTURE-SPINE.md#AD-1] — sole write seam, the field-addition checklist this story satisfies verbatim.
- [Source: .../ARCHITECTURE-SPINE.md#AD-4] — typed, distinguishable refusals.
- [Source: .../ARCHITECTURE-SPINE.md#AD-12] — two capability flags, additive API, one Thread representation.
- [Source: .../ARCHITECTURE-SPINE.md#AD-17] — fork hygiene, minimal upstream-file diffs.
- [Source: .../ARCHITECTURE-SPINE.md#AD-19] — additive/defaulted/no-backfill migrations.
- [Source: .../ARCHITECTURE-SPINE.md#Consistency-Conventions] — "Enumerations... stored as integers with named constants... as notification_level already is"; "ThreadService gets the PHPUnit test it does not have today."
- [Source: lib/Model/Thread.php] — current state, read in full.
- [Source: lib/Model/SelectHelper.php] — current state, read in full.
- [Source: lib/Service/ThreadService.php] — current state, read in full.
- [Source: lib/Controller/ThreadController.php] — `prepareListOfThreads()` as the single serialization choke point for AC3's four endpoints.
- [Source: lib/Capabilities.php:35-166] — `FEATURES`/`LOCAL_FEATURES` current contents.
- [Source: lib/ResponseDefinitions.php:724-753] — `TalkThread`/`TalkThreadAttendee`/`TalkThreadInfo` psalm-types, `notificationLevel: 0|1|2|3,` as the closed-enum precedent.
- [Source: lib/Exceptions/RoomProperty/TypeException.php] — typed-exception-with-REASON_VALUE precedent.
- [Source: lib/Service/RoomService.php:678-682] — `setType()`'s `in_array(..., true)` validation-in-Service-layer precedent.
- [Source: lib/Controller/RoomController.php:1769-1793] — Controller-side `catch (TypeException $e) { return new DataResponse(['error' => $e->getReason()], Http::STATUS_BAD_REQUEST); }` precedent Story 1.4 is expected to follow for `StateException`.
- [Source: lib/Migration/Version22000Date20250623142327.php] — original `talk_threads`/`talk_thread_attendees` table creation, `notification_level` column pattern.
- [Source: lib/Migration/Version24000Date20260313120000.php] — latest additive-column-with-guard migration pattern.
- [Source: tests/php/Model/ThreadTest.php] — Story 1.1's row-key regression test, the direct precedent this story extends.
- [Source: tests/php/Service/RoomServiceTest.php] — constructor-mock test setup precedent for the new `ThreadServiceTest.php`.
- [Source: tests/php/CapabilitiesTest.php:496-528] — `testCapabilitiesDocumentation()`, the existing regression guard requiring the `docs/capabilities.md` entry.
- [Source: tests/integration/features/bootstrap/FeatureContext.php:3378-3411] — confirms the Behat assertion for recent threads only checks a fixed field whitelist, unaffected by an additive `state` field.
- [Source: docs/capabilities.md:197-233] — version-heading format and `(local)` suffix convention.
- [Source: openapi.json, openapi-full.json components.schemas.Thread/ThreadAttendee/ThreadInfo] — exact JSON shape to extend, and confirmation these are the only two of the eight generated spec files containing these schemas.
- [Source: src/types/openapi/openapi.ts:3293-3335] — exact generated TypeScript shape to extend.
- [Source: redocly.yaml] — confirms `openapi.json`→`openapi.ts` and `openapi-full.json`→`openapi-full.ts` are the only mappings touching these schemas.

## Dev Agent Record

### Agent Model Used

Claude Sonnet 5 (claude-sonnet-5), via bmad-dev-story

### Debug Log References

- `docker exec -w /var/www/html/custom_apps/spreed clavis-deploy-nextcloud-1 php -l <file>` run on every created/modified PHP file individually — all clean (see Completion Notes for the full list).
- `docker exec ... php vendor/bin/psalm --no-cache <files>` run on all 9 created/modified PHP source+test files together. First run found 2 real errors, both in `lib/Model/Thread.php:126/129` (`MoreSpecificReturnType`/`LessSpecificReturnStatement`): the `@method int getState()` magic-getter annotation was too loose to satisfy `toArray()`'s declared `@return TalkThread` shape, which requires the literal union `state: 0|1|2`. Fixed by narrowing the docblock to `@method 0|1|2 getState()`. Re-ran psalm — clean. The only remaining psalm findings are `Class Test\TestCase does not exist` on `tests/php/Model/ThreadTest.php:25` and `tests/php/Service/ThreadServiceTest.php:29` — the same accepted environmental gap Story 1.1 documented (container image lacks Nextcloud core's `tests/` source tree; not a code defect).
- OpenAPI regeneration was verified against the project's real, configured generator rather than hand-typed and hoped correct: copied the (already-edited) repo into a container-writable directory (`/tmp/spreedgen`, never writing through the read-only bind mount) and ran `php vendor/bin/generate-spec` there — the exact command `composer.json`'s `openapi` script runs. Diffed all 8 generated `openapi*.json` files against the committed ones: only `openapi.json` and `openapi-full.json` differed, and the diff was byte-identical to the hand-applied edit already made on the host (`Thread` schema gains a `state` property, `type: integer, format: int64, enum: [0,1,2]`, added to `required`). The other 6 generated files (`openapi-administration.json`, `openapi-backend-recording.json`, `openapi-backend-signaling.json`, `openapi-backend-sipbridge.json`, `openapi-bots.json`, `openapi-federation.json`) showed zero diff, confirming none of them declare the `Thread`/`ThreadAttendee`/`ThreadInfo` schemas. Copied the container's regenerated `openapi.json`/`openapi-full.json` onto the host via `docker cp` (overwriting the hand-edited versions with the tool's actual byte-for-byte output).
- TypeScript regeneration used the real host toolchain directly (no container needed — Node/npm work natively): `npx openapi-typescript -t` (the exact `ts:generate` script), config-driven via `redocly.yaml`. Regenerated all 8 mapped `.ts` files; only `src/types/openapi/openapi.ts` and `src/types/openapi/openapi-full.ts` changed, each gaining the corresponding `state: 0 | 1 | 2;` member with matching JSDoc. Confirmed via `git diff --stat` that no other generated `.ts` file was touched.
- `python3 -c "import json; json.load(...)"` on both regenerated `openapi.json` and `openapi-full.json` confirms valid JSON after the file-copy step.
- `grep -rn "createFromRow\|::fromRow(\|selectThreadsTable" lib/` re-run after all edits: still exactly two `Thread::createFromRow()` call sites (`ThreadService.php`, `ScheduledMessageService.php`), zero `Thread::fromRow(` calls, `ThreadAttendee::createFromRow()` untouched, `SelectHelper::selectThreadsTable()` definition plus its two `aliasAll: true` callers unchanged in shape — no new divergent hydration path introduced.
- PHPUnit itself could not be executed in this sandbox — same confirmed, real environment limitation Story 1.1 documented (the container's `nextcloud:34-apache` image ships without Nextcloud core's `tests/` directory, so `tests/php/bootstrap.php`'s `require_once .../tests/autoload.php` has nothing to require). New/changed tests were verified by careful manual reasoning against `vendor/nextcloud/ocp/OCP/AppFramework/Db/Entity.php`'s actual `setter()`/`fromRow()` type-casting logic and against the exact PHP source they exercise, not by execution.

### Completion Notes List

- **AC1/AC2 (migration, defaults):** `lib/Migration/Version24000Date20260809120000.php` adds `talk_threads.state` as `Types::INTEGER`, `notnull: false`, `default: Thread::STATE_ONGOING`, guarded by `!$table->hasColumn('state')` for re-run safety. No `UPDATE` statement anywhere — every pre-existing Thread reads Ongoing purely because the column default does the work at ALTER-TABLE time (the same reasoning AD-7 documents for `talk_thread_attendees.subscribed`'s default). `Thread`'s own `protected int $state = self::STATE_ONGOING;` property default independently guarantees a freshly-constructed Thread (as `ThreadService::createThread()` builds it) reports Ongoing without any explicit `setState()` call — locked in by `ThreadTest::testNewThreadDefaultsToOngoingState()`.
- **AC3 (four endpoints, generated files):** `Thread::toArray()` is the single serialization choke point all four endpoints (`getRecentActiveThreads`, `getSubscribedThreads`, `getThread`, `renameThread`) share via `ThreadController::prepareListOfThreads()` — adding `state` there was sufficient, no controller code changed. `openapi.json`, `openapi-full.json`, `src/types/openapi/openapi.ts`, `src/types/openapi/openapi-full.ts` all regenerated and verified against the project's real tools (see Debug Log) — not hand-typed guesses.
- **AC4 (validation refusal) — documented assumption, no interactive user available:** Story 1.2's own scope is Thread state *reporting*, not state *changing* (confirmed by reading Story 1.4's ACs in epics.md, which own the actual close/lock/reopen endpoint, authority checks and system messages). AC4 nonetheless requires a refusal for an out-of-range state value to exist and be distinguishable from permission/not-found failures. Resolved by adding the validation seam alone: `ThreadService::validateState(int $state): void`, throwing the new `lib/Exceptions/ThreadProperty/StateException.php` (mirroring the existing `RoomProperty\TypeException`/`RoomService::setType()` precedent exactly — bounded-enum-int validated in the Service layer, typed exception with its own `REASON_VALUE`), rather than building a premature full state-change HTTP endpoint that would duplicate or foreclose Story 1.4's design space. This is documented in the story's Dev Notes ("Assumption — AC4's scope") as an explicit engineering judgment call. Fully unit-tested in `ThreadServiceTest.php`.
- **AC5 (field added everywhere AD-1 requires, cache round trip, joined query):** `Thread::addType()`, `createFromRow()`, `fromJson()`, `toJson()`, `toArray()`, and both branches of `SelectHelper::selectThreadsTable()` all updated in this one change. `createFromRow()` reads `th_state` with no defensive fallback (the row shape is produced entirely within this same change). `fromJson()` **does** use a defensive `$row['state'] ?? self::STATE_ONGOING` fallback — deliberately asymmetric with `createFromRow()` — because it reads the distributed cache (900s TTL), so a cache entry written by pre-upgrade code (no `state` key) can still be read for up to 15 minutes post-deploy; documented in a code comment at the call site. `ThreadService::getRecentByActor()`'s direct-query path and `ScheduledMessageMapper::findByRoomAndActor()`'s joined-read path both already route through the same `SelectHelper::selectThreadsTable(..., aliasAll: true)` + `Thread::createFromRow()` pair per Story 1.1's reconciliation, so both automatically carry `state` once those two shared methods were updated — no changes needed to either call site itself.
- **AC6 (capability flags):** `'thread-management'` added to both `Capabilities::FEATURES` and `Capabilities::LOCAL_FEATURES` (the existing `'threads'` flag stays `FEATURES`-only, per the architecture spine's explicit note that it's the `LOCAL_FEATURES` channel that lets a federated client learn a feature is unavailable there). Added the required `docs/capabilities.md` entry (`## 24.0.3` section) — without it, `tests/php/CapabilitiesTest.php::testCapabilitiesDocumentation()` (an existing, unmodified regression guard) would fail, since it asserts every `FEATURES`/`LOCAL_FEATURES` entry is documented there.
- **Execution constraint (same as Story 1.1):** this sandbox has no host `php` binary and cannot run PHPUnit (bootstrap needs Nextcloud core's `tests/` tree, absent from the container image). Every PHP change was verified with `php -l` (all files clean) and `psalm --no-cache` (all files clean except the one accepted `Test\TestCase` gap) via the `clavis-deploy-nextcloud-1` container, and every new test was verified by manual reasoning against the actual vendored `Entity`/`QBMapper` source rather than by execution. **Tests were written but not run.** Unlike Story 1.1, however, the OpenAPI/TypeScript regeneration for this story genuinely *was* executed with the project's real tools (`generate-spec` in the container, `openapi-typescript` on the host) — that part of AC3 has execution-based verification, not just manual reasoning.
- **No HALT condition was triggered.** The one interpretive decision (AC4's scope, given no interactive user was available) is documented above and in Dev Notes, with the reasoning that led to it.

### File List

**Created:**
- `lib/Migration/Version24000Date20260809120000.php` — additive `talk_threads.state` column (AC1, AC2).
- `lib/Exceptions/ThreadProperty/StateException.php` — typed out-of-range-state exception (AC4).
- `tests/php/Service/ThreadServiceTest.php` — new, `ThreadService::validateState()` coverage (AC4).

**Modified:**
- `lib/Model/Thread.php` — `STATE_ONGOING`/`STATE_CLOSED`/`STATE_LOCKED` constants, `state` property + `addType()`, `createFromRow()`, `fromJson()`, `toJson()`, `toArray()` all carry the new field (AC1, AC5).
- `lib/Model/SelectHelper.php` — `selectThreadsTable()`, both `aliasAll` branches, select `state`/`th_state` (AC5).
- `lib/Service/ThreadService.php` — new `validateState()` method + `StateException` import (AC4).
- `lib/Capabilities.php` — `'thread-management'` added to `FEATURES` and `LOCAL_FEATURES` (AC6).
- `docs/capabilities.md` — new `## 24.0.3` section documenting `thread-management` (AC6, keeps `CapabilitiesTest::testCapabilitiesDocumentation()` green).
- `lib/ResponseDefinitions.php` — `TalkThread` psalm-type gains `state: 0|1|2,` (AC3).
- `openapi.json`, `openapi-full.json` — regenerated via the project's real `generate-spec` tool (AC3).
- `src/types/openapi/openapi.ts`, `src/types/openapi/openapi-full.ts` — regenerated via the project's real `openapi-typescript` tool (AC3).
- `tests/php/Model/ThreadTest.php` — extended: `th_state` in both existing row-key tests, plus new tests for default-Ongoing, JSON round trip, missing-key cache fallback, and `toArray()` inclusion.

## Change Log

- 2026-08-09 — Story implemented end-to-end (Tasks 1-8). AC1/AC2: additive, defaulted, re-runnable migration; no backfill. AC3: `Thread::toArray()` (the single serialization seam for all four listed endpoints) carries state; `openapi.json`/`openapi-full.json`/`src/types/openapi/openapi.ts`/`openapi-full.ts` regenerated and verified against the project's actual `generate-spec`/`openapi-typescript` tools (not hand-typed). AC4: documented scope decision — added `ThreadService::validateState()` + `StateException` as the validation seam, since the actual state-change endpoint belongs to a later story; fully unit-tested. AC5: field added to every site AD-1 requires (`addType`, `createFromRow`, `fromJson`, `toJson`, `toArray`, both `SelectHelper::selectThreadsTable()` branches), with an intentional asymmetry — `fromJson()` defends against a still-warm pre-upgrade cache entry missing the key, `createFromRow()` does not need to. AC6: `thread-management` capability flag added to both `FEATURES` and `LOCAL_FEATURES`, plus the `docs/capabilities.md` entry required by an existing test. `psalm` caught one real type-precision defect (`Thread::getState()`'s magic-getter annotation too loose for `toArray()`'s declared return shape) — fixed by narrowing `@method int getState()` to `@method 0|1|2 getState()`; re-verified clean. PHPUnit still cannot execute in this environment (same confirmed limitation as Story 1.1); tests are written and manually verified against real vendored source, not executed. Status set to `review`.
