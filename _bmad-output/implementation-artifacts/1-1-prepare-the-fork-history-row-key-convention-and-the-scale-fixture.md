---
baseline_commit: 6819859953c32f32ccaecf13f4b11f1ad382db00
---

# Story 1.1: Prepare the fork — history, row-key convention, and the scale fixture

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a Clavis engineer,
I want full git history, one row-key convention for hydrating a Thread, and a seeded Conversation at the design scale with its response time already recorded,
So that the fork can rebase onto upstream releases, a new Thread field cannot load on one query path while silently failing on another, and the criteria this work is judged by can actually fail.

## Acceptance Criteria

**AC1**
**Given** the working clone is depth-1 and `git rev-list --count HEAD` returns 1
**When** `git fetch --unshallow` completes
**Then** the full upstream history is present and `.git/shallow` is gone
**And** a rebase onto an upstream `stable34` reference completes without a history-related error.

**AC2**
**Given** `Thread::createFromRow()` reads a bare/`t_`-style row while the aliased branch of `SelectHelper::selectThreadsTable()` emits `th_*` keys
**When** the two are reconciled onto a single prefix
**Then** both the direct-query path and the joined-read path hydrate a `Thread` from the same row-key convention
**And** no new column, API field or response shape is introduced by the change.

**AC3**
**Given** the reconciliation is a behaviour-preserving refactor
**When** it lands as its own commit ahead of any new Thread field
**Then** `tests/integration/features/chat-4/threads.feature` passes unchanged
**And** it passes on all four CI database engines including Oracle.

**AC4**
**Given** no scale fixture exists
**When** the seeding tool is run
**Then** a Conversation contains at least 1000 Threads and 200 participants with activity dates spread across a year
**And** the tool is re-runnable and produces a deterministic dataset that later epics reuse rather than rebuild.

**AC5**
**Given** the seeded Conversation and the current nested threads list with no change yet applied to it
**When** first-page response time is sampled
**Then** median and 95th-percentile figures are recorded in a committed artefact
**And** the sample is taken before any story in this epic alters that list, its row, or its query — a baseline captured after Story 1.8 adds a state field is already polluted. *(AC-2)*

**AC6**
**Given** the seeded Conversation immediately after seeding
**When** `talk_thread_attendees` rows are counted and compared against participants × Threads
**Then** the count is recorded as the pre-read baseline that AC-5 measures growth against.

## Tasks / Subtasks

- [x] **Task 1 — Restore full git history (AC1)**
  - [x] 1.1 Confirm current state: `git rev-list --count HEAD` (expect `1`), `.git/shallow` present. `origin` (`ClavisTechLtd/clavis-spreed`) and `upstream` (`nextcloud/spreed`) remotes already exist — verified with `git remote -v`. Confirmed: count was `1`, `.git/shallow` existed, both remotes present.
  - [x] 1.2 Run `git fetch --unshallow` (network access to `origin` was verified reachable during story creation via `git ls-remote --heads origin`). If this environment cannot reach the remote when dev-story runs, document that explicitly as a blocker for this subtask only — do not let it block Tasks 2–5, which are independent. **Executed successfully** (~3m24s, `git fetch --unshallow origin`).
  - [x] 1.3 Verify: `.git/shallow` no longer exists; `git rev-list --count HEAD` returns a large number (thousands); `git log --oneline -5` still shows the same HEAD commit `6819859`. **Verified**: `.git/shallow` gone, `git rev-list --count HEAD` = 27226, HEAD still `6819859953c32f32ccaecf13f4b11f1ad382db00`.
  - [x] 1.4 Verify a rebase is possible without a history-related error: `git fetch upstream stable34` then `git merge-base HEAD upstream/stable34` should now resolve to a real common ancestor (not fail with "fatal: no merge base"). Do **not** actually perform a destructive rebase of the working branch — a dry-run merge-base / `git rebase --dry-run`-equivalent check (e.g. `git merge-base` succeeding, or `git rebase upstream/stable34` followed immediately by `git rebase --abort` if it starts cleanly) is sufficient evidence and avoids rewriting `stable34` history as a side effect of story verification. **Verified**: `git fetch upstream stable34` succeeded; `git merge-base HEAD upstream/stable34` resolved to `6819859953c32f32ccaecf13f4b11f1ad382db00` (HEAD itself), and `git merge-base --is-ancestor HEAD upstream/stable34` confirms HEAD is a clean ancestor of upstream's current `stable34` — the strongest possible evidence a rebase/merge onto that reference would proceed without a history-related error, since there is nothing to replay yet.
  - [x] 1.5 This is a repository-state change, not a source file edit — nothing to add to the File List for this task beyond a note that `.git/shallow` was removed.

- [x] **Task 2 — Reconcile the Thread row-key convention (AC2, AC3)**
  - [x] 2.1 Read `lib/Model/Thread.php`, `lib/Model/SelectHelper.php`, `lib/Service/ThreadService.php`, `lib/Model/ScheduledMessageMapper.php`, `lib/Service/ScheduledMessageService.php` in full before changing anything (see "Row-key convention — exact findings" in Dev Notes; this is not optional, the fix only makes sense once the mismatch below is understood).
  - [x] 2.2 Standardise on the `th_`-prefixed convention (the shape `SelectHelper::selectThreadsTable(..., aliasAll: true)` already produces, since that is the only branch with a live caller today). Changed `Thread::createFromRow()` in `lib/Model/Thread.php` to read `$row['th_id']`, `$row['th_room_id']`, `$row['th_last_message_id']`, `$row['th_num_replies']`, `$row['th_last_activity']`, `$row['th_name']` instead of the previous `t_id` + bare-field mix.
  - [x] 2.3 Updated `ThreadService::getRecentByActor()` (`lib/Service/ThreadService.php`) to stop hand-rolling `->select('a.*', 't.last_message_id', ...)->selectAlias('t.id', 't_id')` and instead call `(new SelectHelper())->selectThreadsTable($query, 't', aliasAll: true)` after `->select('a.*')`, keeping the existing `join('a', 'talk_threads', 't', ...)` alias. Followed the existing `new SelectHelper()` inline-instantiation convention used everywhere else in the codebase.
  - [x] 2.4 Reconciled `ScheduledMessageService::getMessages()` onto the same explicit hydration method as the direct-query path: stopped stripping the `th_` prefix when building the `$thread` sub-array, changed the null/sentinel check to `$thread['th_id'] ?? null`, and switched the hydration call from the inherited generic `Thread::fromRow($thread)` (valid — `Thread extends Entity`, and `OCP\AppFramework\Db\Entity::fromRow()` is a real snake_case-to-camelCase hydrator that worked correctly with the old bare-keyed array) to `Thread::createFromRow($thread)`. **Correction to this story's original Dev Notes draft:** the original draft claimed `Thread::fromRow()` was an undefined method causing a live crash — that claim was wrong (verified by reading `vendor/nextcloud/ocp/OCP/AppFramework/Db/Entity.php`: `fromRow()`, `columnToProperty()` and `setter()` are all concretely defined there and Thread inherits them; the old code path was not broken). The real, still-valid reason for this change is reconciliation, not a bug fix: both paths now hydrate `Thread` through the exact same explicit method and row-key convention instead of two different (if both previously working) Entity-hydration mechanisms — which is what AC2 asks for and what AD-1's "checklist spanning two conventions cannot be verified by reading the diff" warns against.
  - [x] 2.5 Confirmed `ThreadAttendee::createFromRow()` and its bare `a.*`/`room_id`/`thread_id`/etc. reads are untouched — not edited, verified via the post-change `grep` sweep in 6.1.
  - [x] 2.6 Left `SelectHelper::selectThreadsTable()`'s `aliasAll: false` (default) branch as-is — zero callers in the repository (confirmed via `grep -rn selectThreadsTable lib/` both before and after this task's edits), so out of scope for this behaviour-preserving reconciliation; documented as a deliberate decision for a future field-adding story to revisit per AD-1.
  - [x] 2.7 Added `tests/php/Model/ThreadTest.php` (new file, modelled on the existing `tests/php/Model/AttendeeMapperTest.php`/`tests/php/Chat/AutoComplete/SorterTest.php` pattern of `extends \Test\TestCase`) with two pure-unit tests locking in `Thread::createFromRow()`'s `th_`-prefixed contract: one hydrates a full row (including an unrelated joined-table column, asserting it's ignored) and asserts every getter; the other asserts the exact six-key set `SelectHelper::selectThreadsTable(..., aliasAll: true)` always produces is exactly what `createFromRow()` reads. Manually traced both tests line-by-line against `Entity::setter()`/`columnToProperty()`'s actual type-casting logic (see Dev Notes → Environment constraints for why this could not be executed) — both are expected to pass.
  - [x] 2.8 Traced (cannot execute — see Environment constraints) that `tests/integration/features/chat-4/threads.feature`'s 12 scenarios are unaffected (none of them exercise `ScheduledMessageService::getMessages()` or `ThreadService::getRecentByActor()`'s row shape directly in a way this change alters), and that `tests/integration/features/chat-4/scheduled-messages.feature`'s "Schedule a thread reply", "Schedule a quoted thread reply" and its delete-variant scenarios — the ones that exercise the joined-read path with a real (non-`-1`) `threadId` — continue to produce the same `threadTitle`/`threadId` response fields as before, since the reconciled code produces an equivalent `Thread` object from the same underlying row data.

- [x] **Task 3 — Build the seeded scale fixture tool (AC4)**
  - [x] 3.1 Added `lib/Command/Developer/SeedThreadsFixture.php` (`OCA\Talk\Command\Developer\SeedThreadsFixture`), registered as occ command `talk:developer:seed-threads`, modelled directly on `lib/Command/Developer/AgeChatMessages.php`: extends `OC\Core\Command\Base`, `isEnabled()` gated on `$this->config->getSystemValue('debug', false) === true`.
  - [x] 3.2 Registered in `appinfo/info.xml` under the existing `<commands>` block, in the `Developer` group between `AgeChatMessages` and `UpdateDocs`.
  - [x] 3.3 Implementation replicates the **exact production orchestration** found in `ChatController::sendMessage()` (`lib/Controller/ChatController.php:412-431`) rather than guessing: `ChatManager::sendMessage(..., threadId: Thread::THREAD_CREATE, threadTitle: $title)` to create the root message, then `ThreadService::createThread($room, (int)$comment->getId(), $title)` to create the `talk_threads` row, then `ThreadService::setNotificationLevel(...)` to subscribe the poster — the same three calls the real HTTP endpoint makes. Participants: `--participants` synthetic Nextcloud user accounts (`talk-fixture-user-0001..NNNN`) created via `IUserManager::createUser()` (precedent: `MatterbridgeManager.php:255`) and added via `ParticipantService::addUsers()`. Activity back-dated via `sendMessage()`'s existing `\DateTime $creationDateTime` parameter — no raw-SQL post-write patch needed, unlike `AgeChatMessages`'s approach for `comments.creation_timestamp`.
  - [x] 3.4 Deterministic + re-runnable, decided as follows (documented, not left implicit): activity spread uses **pure index arithmetic** (`intdiv($i * $totalSecondsInYear, $targetThreads)` offset from one year ago), not a PRNG — this guarantees byte-identical determinism without needing to manage a seed. Re-runnability: `--token <existing-room-token>` tops up participants (skips ones that already exist, checked via `ParticipantService::getParticipantsForRoom()`) and always adds `--threads` *additional* new Threads on top of whatever the room already has (no thread-count-detection query exists on `ThreadService` today, and adding one is out of this story's scope — see Dev Notes). Omitting `--token` always creates a fresh Conversation. This satisfies AC4's "re-runnable and produces a deterministic dataset" without requiring full idempotency (which the AC does not ask for).
  - [x] 3.5 Confirmed: this command is a **developer/test-fixture tool**, not user-facing product code — it lands in `lib/Command/Developer/`, gated by `debug`, matching `AgeChatMessages`'s precedent exactly, so it never ships active in a production Nextcloud instance.

- [x] **Task 4 — Capture the pre-work response-time baseline (AC5)**
  - [x] 4.1 Documented the measurement procedure (using the Task 3 fixture, sample first-page response time of the current, unmodified nested threads list endpoint, 20-50 samples, median + p95) in the new artefact — see 4.2.
  - [x] 4.2 Created `docs/dev/thread-directory-baseline.md` (no existing perf/benchmark artefact location existed in this repo — verified no `docs/perf/`, no `tests/perf/`) with full methodology, a reproduction procedure referencing `talk:developer:seed-threads`, and a results table.
  - [x] 4.3 Because PHP cannot be executed in this sandbox (see Dev Notes → Environment constraints), the actual measurement could not be run here. `docs/dev/thread-directory-baseline.md`'s results table is explicitly marked "Status: pending a real run" with every numeric cell as `_pending_` and a clear instruction to fill it in before Epic 2 begins — no numbers were fabricated.

- [x] **Task 5 — Record the pre-read baseline (AC6)**
  - [x] 5.1 Documented the exact method (`SELECT COUNT(*) FROM oc_talk_thread_attendees WHERE room_id = <seeded room id>` immediately after seeding, compared against participants × Threads) in `docs/dev/thread-directory-baseline.md` step 4, including the AD-6 expectation (near-zero) as context, not as a substitute for the real count.
  - [x] 5.2 Recorded alongside the AC5 baseline in the same file/table (`talk_thread_attendees` row count row + participants × Threads row), so both are committed together and cross-referenced.
  - [x] 5.3 Same execution constraint as Task 4: the actual number requires running the seeding tool against a live database, which this sandbox cannot do. Marked `_pending_` in the same results table, with the same "before Epic 2 begins" instruction — not fabricated.

- [x] **Task 6 — Regression pass and housekeeping**
  - [x] 6.1 Re-ran `grep -rn "createFromRow|::fromRow(|selectThreadsTable" lib/` after all Task 2 edits. Confirmed: exactly two `Thread::createFromRow()` call sites (`ScheduledMessageService.php:171`, `ThreadService.php:175`), zero remaining `Thread::fromRow(` calls anywhere, `ThreadAttendee::createFromRow()` untouched, `SelectHelper::selectThreadsTable()` definition and its one `aliasAll: true` caller (`ScheduledMessageMapper.php:51`) unchanged.
  - [x] 6.2 Confirmed via `git status`: only `appinfo/info.xml`, `lib/Model/Thread.php`, `lib/Service/ScheduledMessageService.php`, `lib/Service/ThreadService.php` modified, plus new untracked `lib/Command/Developer/SeedThreadsFixture.php`, `tests/php/Model/ThreadTest.php`, `docs/dev/`. `.gitignore` (modified) and `.bmad-loop/` (untracked) pre-date this story's work (present in the working tree before dev-story started) and were not touched.
  - [x] 6.3 Dev Agent Record, File List, Change Log updated below; Status flipped to `review` in this workflow's final step.

## Dev Notes

### Row-key convention — exact findings (read this before touching code)

**Correction to an earlier draft of this section:** an earlier pass of this story claimed `Thread::fromRow()` was an undefined method causing a live crash. That was **wrong** and has been corrected below after reading `vendor/nextcloud/ocp/OCP/AppFramework/Db/Entity.php` — `Thread extends Entity`, and `Entity::fromRow(array $row): static` is a real, concretely-defined generic hydrator (snake_case-to-camelCase via `columnToProperty()`, with correct BIGINT/DATETIME type casting in `setter()`). `Thread::fromRow($row)` was valid, inherited, and worked correctly with a bare-keyed row. The actual finding is a genuine convention split — two *different, both-working* hydration mechanisms for the same entity — not a crash:

1. **`Thread::createFromRow()`** (`lib/Model/Thread.php`, before this story's change) read `$row['t_id']` for the id, and *bare* `room_id`, `last_message_id`, `num_replies`, `last_activity`, `name` for everything else — a hand-written, Thread-specific hydrator.
2. **`ThreadService::getRecentByActor()`** (`lib/Service/ThreadService.php`, the "direct-query path") hand-built a query aliasing `talk_threads` as `t`, selecting `a.*` plus bare `t.last_message_id`/`num_replies`/`last_activity`/`name`, and `t.id AS t_id`. This was the **only** caller of `Thread::createFromRow()`, and its row shape matched convention (1) exactly — this path always worked correctly.
3. **`SelectHelper::selectThreadsTable($query, $alias = 'th', $aliasAll = false)`** (`lib/Model/SelectHelper.php:53-77`) has two branches. Only `aliasAll: true` has a live caller (`ScheduledMessageMapper::findByRoomAndActor()`, `lib/Model/ScheduledMessageMapper.php:51`) — the "joined-read path." That branch aliases **every** column with a hardcoded `th_` prefix regardless of the `$alias` parameter passed (the `$alias` parameter only controls the *source* table alias in the SQL, e.g. `th.room_id`; the *output* column keys are always `th_room_id`, `th_last_message_id`, `th_num_replies`, `th_last_activity`, `th_name`, `th_id`). The `aliasAll: false` branch (bare fields + `th_id` only) has **zero callers** anywhere in `lib/` — verified by grep — it is dead code today.
4. **`ScheduledMessageService::getMessages()`** (`lib/Service/ScheduledMessageService.php`, before this story's change) consumed the joined-read path's row by stripping the `th_` prefix off each field to build a `$thread` sub-array with bare keys, then called the **inherited, generic `Thread::fromRow($thread)`** — which, per the correction above, worked, via `Entity`'s reflection-ish `columnToProperty()`/`setter()` machinery rather than Thread's own explicit `createFromRow()`.

**The actual problem this story fixes is divergence, not breakage.** Two different mechanisms hydrated the same `Thread` entity from two different row-key conventions (Thread's own `createFromRow()` expecting `t_id`+bare, versus the generic inherited `fromRow()` fed a manually bare-keyed array) that both happened to work today, purely because PHP property-name casing and column-name-to-property conversion lined up by coincidence on both sides. That is exactly the fragility AD-1 names: "a checklist spanning two conventions cannot be verified by reading the diff." A future field added to `createFromRow()` but forgotten in the manual prefix-stripping loop (or vice versa) would silently diverge with no compile-time signal.

**The fix (Task 2) reconciles both paths onto the same explicit method and the same `th_`-prefixed convention** (the one branch of `SelectHelper::selectThreadsTable()` that is actually used), because:
- It lets `ThreadService::getRecentByActor()` stop duplicating `SelectHelper`'s column list by hand (the exact kind of drift AD-1 exists to prevent — "Every new Thread field is added, in the same change, to ... `SelectHelper::selectThreadsTable()`" only holds if call sites actually go through it).
- It moves `ScheduledMessageService::getMessages()` off the generic inherited `Entity::fromRow()` mechanism and onto `Thread`'s own explicit, purpose-built `createFromRow()` — the same method the direct-query path uses — so both paths are locked to one hydrator instead of two.
- `ThreadAttendee::createFromRow()` and the attendee half of the row are completely unaffected — only the `Thread` hydration changes.

No new column, API field, or response shape is introduced anywhere in this fix — `Thread::toArray()`/`toJson()` (the two methods that actually produce API-visible output) are untouched, and the resulting `Thread` object's field values are identical to what the pre-existing (working) code produced. AC2's "no new column, API field or response shape" constraint is satisfied by construction.

### Environment constraints (read before starting)

- **No PHP interpreter is installed in this sandbox** (`php` resolves to "command not found"). `vendor/autoload.php` exists but nothing can execute against it. This means **no PHPUnit, no Behat, no `php -l` syntax check** can be run here for Tasks 2–5's PHP changes. Verify correctness by careful manual tracing (as done in "Row-key convention — exact findings" above) and by matching existing, working call-site patterns exactly (indentation, typing, `IQueryBuilder::PARAM_*` usage, etc.) rather than by execution. State this plainly in Completion Notes — do not claim tests were run if they were not.
- `tests/php/phpunit.xml`'s `bootstrap`/`source` paths (`../../../spreed/appinfo`, `../../../spreed/lib`) assume this app is checked out as `apps/spreed` inside a full Nextcloud server tree. No such server tree is present alongside this checkout, so even with PHP installed, `composer test:unit` would not run as-is from this location — another reason execution-based verification is out of reach here.
- **Node/npm (v24.7.0), python3, sqlite3, and git are available.** This story touches no frontend code, so Node tooling is not directly relevant to it, but is available if any incidental JS/TS check is ever needed.
- **Network access to `origin` (`git@github.com:ClavisTechLtd/clavis-spreed.git`) works** — verified via `git ls-remote --heads origin` during story creation. `upstream` (`https://github.com/nextcloud/spreed.git`) is also configured as a remote. AC1 is expected to be genuinely executable, unlike the PHP-side verification.
- Given these constraints, the realistic, honest deliverable for Tasks 4 and 5 in this environment is the **tooling plus a documented, pending measurement** — not fabricated numbers. Say so explicitly rather than inventing plausible-looking median/p95 figures.

### Architecture compliance

- **AD-1** (`ThreadService` is the sole write seam; row-key reconciliation is explicitly called out as a preparatory commit in AD-1's own text) — this story *is* that preparatory commit. It must land, per AC3, "as its own commit ahead of any new Thread field," i.e. before Story 1.2 adds the `state` column.
- **AD-17** (fork hygiene) — `git fetch --unshallow` is AD-17's explicit prerequisite-of-the-first-commit. New behaviour (the seeding command) lands in a **new file** (`lib/Command/Developer/SeedThreadsFixture.php`), not an edit to an upstream-owned file. The row-key fix touches upstream-owned files (`Thread.php`, `SelectHelper.php`, `ThreadService.php`, `ScheduledMessageService.php`) — per AD-17, keep each edit the smallest diff that works and do not add unrelated cleanup.
- **AD-19** (migrations additive/no backfill) — not directly exercised by this story (no schema change here), but the seeded fixture (Task 3) must not assume any backfilled state, since none exists.
- **Operational envelope** (architecture spine, "Nothing here is deployed by Clavis" section) — explicitly states the seeded fixture "is an engineering deliverable of this work, not a test convenience" and that AC5's baseline "must be captured on it before the first Directory change ships." Treat Tasks 3-5 as first-class deliverables, not throwaway scripts.
- **Consistency Conventions table** — "Naming — code: New classes carry the `Thread` prefix... New columns are snake_case on the table, camelCase on the entity" — not directly applicable to this story (no new class/column), but the new `SeedThreadsFixture` command should still follow the `Command\Developer` namespace and `talk:developer:*` naming convention already established.

### File structure / where things land

- `lib/Model/Thread.php` — `createFromRow()` fix (UPDATE).
- `lib/Service/ThreadService.php` — `getRecentByActor()` fix (UPDATE).
- `lib/Service/ScheduledMessageService.php` — `getMessages()` fix (UPDATE).
- `lib/Model/SelectHelper.php` — read only; no change planned (see Task 2.6) unless implementation reveals otherwise.
- `lib/Command/Developer/SeedThreadsFixture.php` — new occ command (NEW), modelled on `lib/Command/Developer/AgeChatMessages.php`.
- `appinfo/info.xml` — register the new command in the existing `<commands>` block (UPDATE).
- `tests/php/Model/ThreadTest.php` — new PHPUnit coverage for the row-key fix (NEW).
- `docs/dev/thread-directory-baseline.md` (or equivalent path chosen at implementation time) — AC5/AC6 committed artefact (NEW).

### Testing standards summary

- Behat scenarios live under `tests/integration/features/chat-4/`; `threads.feature` (12 scenarios) and `scheduled-messages.feature` are the ones this story's Task 2 must not regress. New columns/fields are not introduced here, so no step-definition changes are expected.
- `tests/php/Service/ThreadServiceTest.php` and `ThreadControllerTest.php` do not exist yet (per epics.md's Epic 1 implementation notes) — this story is not required to create them (no new externally-observable `ThreadService` behaviour is added), but `tests/php/Model/ThreadTest.php` should be added per Task 2.7 to lock in the row-key convention this story establishes, following `tests/php/Model/AttendeeMapperTest.php`'s structure as the nearest existing precedent.
- CI runs four database engines (MySQL/MariaDB, PostgreSQL, SQLite, Oracle — `phpunit-oci.yml`, `integration-oci.yml`, etc., all present under `.github/workflows/`). AC3's "passes on all four" cannot be verified locally in this sandbox; it is a CI-verification item once the change is pushed.

### Project Structure Notes

- No conflicts with the unified project structure. All touched files already exist in their conventional locations; the one new command follows the existing `lib/Command/Developer/` precedent exactly.
- No prior story exists in this epic (this is Story 1.1, the first), so there is no previous-story intelligence or git-commit-pattern history to carry forward.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-1.1] — story statement and AC1-AC6, verbatim.
- [Source: _bmad-output/planning-artifacts/epics.md#Additional-Requirements] — prerequisites section naming the row-key convention, unshallow fetch, and the scale fixture as engineering deliverables (AD-1, AD-17, AD-9).
- [Source: _bmad-output/planning-artifacts/architecture/architecture-clavis-spreed-2026-08-08/ARCHITECTURE-SPINE.md#AD-1] — the row-key reconciliation rule, verbatim, including the `t_`/`th_` mismatch description.
- [Source: _bmad-output/planning-artifacts/architecture/architecture-clavis-spreed-2026-08-08/ARCHITECTURE-SPINE.md#AD-17] — fork hygiene, `git fetch --unshallow` prerequisite.
- [Source: _bmad-output/planning-artifacts/architecture/architecture-clavis-spreed-2026-08-08/ARCHITECTURE-SPINE.md#Operational-envelope] — seeded fixture as engineering deliverable, AC5's baseline timing requirement.
- [Source: lib/Model/Thread.php] — current `createFromRow()` implementation.
- [Source: lib/Model/SelectHelper.php] — `selectThreadsTable()`, both branches.
- [Source: lib/Service/ThreadService.php:143-177] — `getRecentByActor()`, the direct-query path.
- [Source: lib/Model/ScheduledMessageMapper.php:47-64] — `findByRoomAndActor()`, the joined-read path.
- [Source: lib/Service/ScheduledMessageService.php:130-174] — `getMessages()`, consumer of the joined-read path, reconciled from the inherited generic `Thread::fromRow()` onto `Thread::createFromRow()`.
- [Source: vendor/nextcloud/ocp/OCP/AppFramework/Db/Entity.php] — `fromRow()`, `columnToProperty()`, `setter()`: confirms `Entity::fromRow()` is a real, working, inherited method, correcting this story's earlier draft.
- [Source: lib/Command/Developer/AgeChatMessages.php] — precedent for the new seeding occ command.
- [Source: appinfo/info.xml:105-107] — `<commands>` registration block precedent.
- [Source: lib/Chat/ChatManager.php:383-398] — `sendMessage()` signature, including the `\DateTime $creationDateTime` parameter usable for back-dating seeded activity.
- [Source: tests/integration/features/chat-4/threads.feature] — 12 existing scenarios, must remain green.
- [Source: tests/integration/features/chat-4/scheduled-messages.feature:177-249] — the three scenarios that exercise the joined-read path with a real thread id.

## Dev Agent Record

### Agent Model Used

Claude Sonnet 5 (claude-sonnet-5), via bmad-dev-story

### Debug Log References

- `git fetch --unshallow origin` run in background (~3m24s); completed successfully. `git rev-list --count HEAD` went from `1` to `27226`; `.git/shallow` removed; HEAD unchanged at `6819859953c32f32ccaecf13f4b11f1ad382db00`.
- `git fetch upstream stable34` + `git merge-base HEAD upstream/stable34` → resolved to HEAD itself; `git merge-base --is-ancestor HEAD upstream/stable34` confirmed true. No destructive rebase performed (by design, per Task 1.4).
- Self-correction during implementation: read `vendor/nextcloud/ocp/OCP/AppFramework/Db/Entity.php` and discovered `Entity::fromRow()`, `columnToProperty()` and `setter()` are all real, concretely-defined, inherited methods. This invalidated an earlier draft claim (written during story creation, before this file was read) that `Thread::fromRow()` was an undefined-method crash bug. Corrected the story's Dev Notes, task descriptions, and References before finishing Task 2 — see "Row-key convention — exact findings" in Dev Notes for the corrected account. The code change itself (reconciling onto `Thread::createFromRow()`) remained valid and was completed as planned; only its stated rationale changed from "fixes a crash" to "removes a two-mechanism convention split."
- Confirmed via `sed -n '395,435p' lib/Controller/ChatController.php` the exact production orchestration for creating a Thread (`ChatManager::sendMessage()` with `threadId: Thread::THREAD_CREATE` followed by `ThreadService::createThread()` with the resulting comment id) and replicated it in the new seeding command rather than inventing a different approach.
- Confirmed no PHP interpreter, MySQL/PostgreSQL client, or full Nextcloud server tree is available in this sandbox (`php: command not found`; only `node`, `npm`, `python3`, `sqlite3`, `git` present). All PHP-side work (Tasks 2 and 3) was verified by manual line-by-line tracing against the actually-vendored `OCP` source, not by execution.
- **Post-review verification (orchestrator pass, outside the dev-story subagent):** the host has no `php` binary, but a live `nextcloud:34-apache` container (`clavis-deploy-nextcloud-1`) has this repo bind-mounted read-only at `/var/www/html/custom_apps/spreed` and has PHP 8.5.9 available. Ran `php -l` on all 5 changed/created PHP files (all clean) and the project's actual configured static analyzer, `vendor/bin/psalm --no-cache` (per `composer.json`'s `psalm` script), against the same 5 files. Full PHPUnit execution remains blocked in this environment — the image ships without Nextcloud core's `tests/` directory, so the app's `tests/php/bootstrap.php` cannot `require_once .../tests/autoload.php` (confirmed: that path does not exist in the container). Psalm caught one real bug that php -l and manual tracing both missed: `SeedThreadsFixture::ensureUser()` was typed to return `IUser` but could return `IUserManager::createUser()`'s `IUser|false` on failure (`FalsableReturnStatement`). Fixed by throwing `RuntimeException` on `false` instead of returning it; re-ran `php -l` and `psalm` — both clean. Psalm's only remaining flag (`ThreadTest.php:24`, `Class Test\TestCase does not exist`) is the same environmental gap as the PHPUnit issue (core's `Test\TestCase` isn't shipped in this image) and is not a code defect.

### Completion Notes List

- **AC1 (git history):** Fully executed and verified, not just documented — `git fetch --unshallow` actually ran successfully in this sandbox (network access to `origin` worked), and the rebase-feasibility check (`git merge-base`) actually ran and returned a positive result. This is the one AC in this story with genuine execution-based verification.
- **AC2/AC3 (row-key convention):** Reconciled `Thread::createFromRow()`, `ThreadService::getRecentByActor()`, and `ScheduledMessageService::getMessages()` onto one `th_`-prefixed convention, all routed through `SelectHelper::selectThreadsTable(..., aliasAll: true)` on the query side and `Thread::createFromRow()` on the hydration side. No new column, API field, or response shape introduced. **Correction logged:** an earlier draft of this story incorrectly described this as fixing a crash bug (`Thread::fromRow()` "undefined method"); it is not — `Entity::fromRow()` is a real inherited method and the pre-existing code worked. The reconciliation is still fully justified by AC2's literal text ("hydrate a Thread from the same row-key convention") and by AD-1's "checklist spanning two conventions cannot be verified by reading the diff" — it just isn't a bug fix. Added `tests/php/Model/ThreadTest.php` as a regression guard.
- **Execution constraint (applies to AC2/AC3, and to Tasks 3-5):** this sandbox has no PHP interpreter and no full Nextcloud server tree, so none of the PHP-side changes could be executed — no PHPUnit, no Behat, no `php -l`. Every change was verified by careful manual reasoning against the actual dependency source (`vendor/nextcloud/ocp/...`) and by matching existing, working call-site patterns exactly. This is stated explicitly, not glossed over: **tests were written but not run.** CI (which runs all four database engines) is the first point at which AC3's "passes on all four CI database engines including Oracle" can actually be confirmed.
- **AC4 (seeded fixture):** Implemented `talk:developer:seed-threads` as a new gated developer occ command, replicating the real `ChatController::sendMessage()` thread-creation orchestration exactly rather than writing rows directly. Deterministic via pure index arithmetic (no PRNG). Re-runnable via `--token` to top up an existing fixture, or omit it to create a fresh one each time — not made fully idempotent, since AC4 does not require idempotency and building thread-count-detection into `ThreadService` was judged out of scope for this story.
- **AC5/AC6 (baselines):** Created `docs/dev/thread-directory-baseline.md` with full methodology and a results table. The actual median/p95 response-time figures and the `talk_thread_attendees` pre-read count are marked **pending a real run** — they were not fabricated, because they cannot be produced without executing the seeding tool against a live database, which this sandbox cannot do. This is the one place in this story where a task's literal numeric deliverable is incomplete; the tooling and documentation deliverables are complete.
- **No HALT condition was triggered.** All ambiguities encountered (exact baseline artefact location, PRNG-vs-arithmetic determinism, idempotency-vs-additive re-runnability, whether to route the seeding tool through the full production write path or raw SQL) were resolved by direct engineering judgment grounded in reading the existing codebase's own precedents, and are documented at the point of each decision above and in Dev Notes.

### File List

**Modified:**
- `lib/Model/Thread.php` — `createFromRow()` reconciled to `th_`-prefixed row-key convention.
- `lib/Service/ThreadService.php` — `getRecentByActor()` now selects Thread columns via `SelectHelper::selectThreadsTable(..., aliasAll: true)` instead of a hand-rolled column list; added `SelectHelper` import.
- `lib/Service/ScheduledMessageService.php` — `getMessages()` stops stripping the `th_` prefix and calls `Thread::createFromRow()` instead of the inherited generic `Thread::fromRow()`.
- `appinfo/info.xml` — registered the new `talk:developer:seed-threads` command.

**Created:**
- `lib/Command/Developer/SeedThreadsFixture.php` — new occ command building the NFR-1 scale fixture (AC4).
- `tests/php/Model/ThreadTest.php` — PHPUnit regression coverage for `Thread::createFromRow()`'s row-key convention (AC2/AC3).
- `docs/dev/thread-directory-baseline.md` — AC5/AC6 committed artefact (methodology + results table, numbers pending a real run).

**Repository state (not a working-tree file change):**
- `.git/shallow` removed via `git fetch --unshallow` (AC1); `git rev-list --count HEAD` now 27226.

## Change Log

- 2026-08-09 — Story implemented end-to-end (Tasks 1-6). AC1 fully executed and verified (unshallow fetch + rebase-feasibility check). AC2/AC3 row-key convention reconciled across `Thread.php`, `ThreadService.php`, `ScheduledMessageService.php`, with a mid-implementation self-correction to the story's Dev Notes (the original "undefined method crash" framing for `Thread::fromRow()` was factually wrong — `Entity::fromRow()` is a real inherited method — corrected to the accurate "two working conventions reconciled to one" framing). AC4 seeding tool (`talk:developer:seed-threads`) added. AC5/AC6 baseline artefact created with methodology documented and numeric results explicitly marked pending (no PHP/DB available in this sandbox to produce real numbers). Status set to `review`.
- 2026-08-09 — Post-review verification pass using the live `clavis-deploy-nextcloud-1` container's PHP (host has none): `php -l` on all changed files (clean), `psalm --no-cache` on all changed files (project's real configured tool). Found and fixed a real bug: `SeedThreadsFixture::ensureUser()` could return `false` while typed to return `IUser`; now throws `RuntimeException` instead. Re-verified clean after fix. PHPUnit still cannot run in this environment (container lacks Nextcloud core's `tests/` source tree needed by the bootstrap).
