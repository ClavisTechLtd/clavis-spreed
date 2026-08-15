---
title: Rubric-walker review — ARCHITECTURE-SPINE.md
reviewer: rubric walker (Reviewer Gate)
target: _bmad-output/planning-artifacts/architecture/architecture-clavis-spreed-2026-08-08/ARCHITECTURE-SPINE.md
spine_revision_reviewed: 389 lines, AD-1 … AD-20 (post parallel-reviewer fixes)
repo: clavis-spreed @ stable34, commit 6819859 (Talk 24.0.3)
date: 2026-08-08
verdict: revise — 3 high, 4 medium, 4 low
---

# Rubric-walker review — Clavis Talk Thread Management spine

Reviewed against the **current** spine (AD-1 … AD-20, 389 lines), after the parallel reviewer's
five corrections landed. Every code claim below was re-checked against `stable34` / `6819859`.

## What the parallel pass already fixed — confirmed closed

I had independently reached two of these; recording them as closed rather than re-reporting.

- **AD-7** — I had this as my critical finding. The rewrite is correct and goes further than my
  suggested fix: it names the asymmetry (`findAttendeesForNotification()` excludes
  `NOTIFY_DEFAULT`, `getRecentByActor()` excludes only `NOTIFY_NEVER`), requires an explicit
  subscription column rather than patching the predicate, sequences it ("in the same change that
  introduces read-created rows — not afterwards"), and cites `threads.feature:224` as the
  regression guard. **Closed.**
- **AD-14** — I had the registry undercount as high. Registry 5 now covers the inline early-return
  array in `notifySystemMessageSent()`. **Partially closed** — two of the three sites I found are
  still missing. See H2.
- **AD-2 narrowing**, **AD-10 dropping `normalizeTagName()`**, **AD-19/AD-6 lazy NULL baseline**,
  **Oracle in the Stack**, **AD-20** — all new to me, all verified correct:
  `ConversationTagService::normalizeTagName()` is `private` at `:190` and only trims and
  length-checks (`:191-195`); `editMessage` (`:729`), `deleteMessage` (`:652`), `pinMessage`
  (`:809`) and `ReactionManager::addReactionMessage` (`:81`) / `deleteReactionMessage` (`:124`)
  all reach `commentsManager->save()` outside both choke points; `.github/workflows/phpunit-oci.yml`
  and `integration-oci.yml` exist. AD-16's four memory-router entry points
  (`mainFilesSidebar.js`, `mainPublicShareSidebar.js`, `mainPublicShareAuthSidebar.js`,
  `mainFloatingCall.ts`) and the `SearchMessagesTab.vue:229-233` precedent both check out.
- **PRD open question 3** — now explicitly deferred with AD-20 settling titles. This was my M4.
  **Closed.**

---

## 1. Real divergence points — fixed, or missed?

**Verdict: two new misses, both introduced by the fixes.**

The spine now covers the seams it did not before. Two gaps remain, and both are places where a
correction moved a decision without giving it a home.

### Missed — AD-6's "first marker advance" names no seam, and there are eight candidates

AD-6 now reads: *"the first time a participant's conversation read marker would advance, the
**pre-advance** value is frozen into the baseline in that same row write."* There is no single
place where that happens. `talk_attendees.last_read_message` is advanced from eight sites with
three different meanings:

| Site | What it means | Should it freeze? |
| --- | --- | --- |
| `ChatController.php:948`, `:1911` | the participant read the main chat | **Yes** — this is the intended trigger |
| `ChatManager.php:474` (in `sendMessage`), `:269` | the participant **sent** a message — including a thread reply | **No.** Posting one thread reply would freeze the baseline permanently, with no relation to anything they read |
| `RoomFormatter.php:323` | `UNREAD_MIGRATION` legacy repair, fires on first room-list render | **No.** Freezes before the participant has read anything — reintroduces the day-one badge flood AD-19's redesign exists to prevent |
| `ParticipantService.php:529`, `:683`, `:1017` | join / add-participant; sets the marker to the room's last message | This is the join-time advance the **previous** AD-6 named explicitly and the rewrite dropped |
| `ParticipantService::updateUnreadInfoForProxyParticipant()` `:251-259` | federated proxy sync; sets the field directly, bypassing `updateLastReadMessage()` | No — but it will be missed by anyone who hooks only the named method |

Note that five of the eight go through `ParticipantService::updateLastReadMessage()` (`:244-249`)
and three do not, so even "hook the one service method" is not a complete answer.

**What goes wrong.** Two epics implementing AD-6 pick different sites and get different unread
behaviour for every Thread a participant has never opened — and one plausible pick
(`RoomFormatter.php:323`) reproduces the exact failure the NULL-baseline redesign was made to
avoid. This is load-bearing: it is FR-27's and FR-28's fallback semantics.

**Fix.** Name the seam and the exclusions: the baseline freezes only in the read-marker path
driven by an explicit read (`ChatController::receiveMessages` / `setReadMarker`), never on send,
never in the `UNREAD_MIGRATION` repair, and restore the join-time behaviour the earlier draft had
— on join the baseline is set alongside `last_read_message`, so a new member never inherits a
room's history as unread.

### Missed — accessibility, theming and the tag colour palette are still entirely silent

Unchanged from my first pass, and AD-20 does not touch it (AD-20 is about sensitivity, not
presentation). The current spine contains zero occurrences of *access*, *keyboard*, *screen
reader*, *contrast*, *theme* or *palette*. See H3.

## 3. Anything load-bearing parked under `Deferred`?

**Verdict: yes — one, and it is new.**

Nine of the ten deferrals are sound, and the two rewritten ones are better than before: Q2 now
carries the real cost ("answering yes means building a third enforcement seam") instead of the
PRD's "one-line change", and Q3 is explicitly parked with AD-20 covering the part that could be
settled.

The problem is what Q2's bullet now carries with it. AD-2 states that reactions, edits, deletes
and pins all bypass both choke points and need a third seam; the Deferred bullet then bundles
**edits, deletes and pins** into the *reactions* question. They are not the same question:

- Reactions in a Locked Thread are PRD **open question 2** — genuinely undecided, owner requester.
- Edit and delete refusal is FR-5's settled consequence — *"Editing or deleting a message that
  already exists in a Locked Thread is refused"* — backed by PRD assumption 5, **not** an open
  question, and inside MVP scope per §9.1.

So a requirement that the PRD decided is now reachable only through a deferral of a question the
PRD left open. If Q2 resolves "no, reactions are not writes", the third seam is never built and
edits and deletes silently go unguarded too — a Locked Thread that a participant can still rewrite
by editing their own message, which is the hole PRD assumption 5 was written to close. See M1.

## 6. PRD capability coverage, §4.1 – §4.9

**Verdict: eight sections covered; §4.6 still has a hole, §4.1 has the new one above.**

| PRD § | Governed by | Adequate? |
| --- | --- | --- |
| §4.1 Lifecycle (FR-1…8) | AD-1, AD-2, AD-4, AD-5, AD-14 | FR-5's edit/delete consequence is now unowned (M1); AD-14 still half-covers the relay (H2) |
| §4.2 Authority (FR-9…11) | AD-3 | Yes, subject to M3 |
| §4.3 Directory (FR-12…17) | AD-9, AD-12, AD-15, AD-16 | Yes — AD-16 is materially stronger now |
| §4.4 Search (FR-18…20) | AD-11, AD-12 | Yes; FR-20 (followed-list search) still rides only on the capability-map row — AD-12 names `threads/recent`, not `subscribed-threads` |
| §4.5 Featured (FR-21, 22) | AD-1, AD-12, AD-19 | Yes; FR-22's cap still has no home in the conventions (L4) |
| §4.6 Tags (FR-23…26) | AD-10, AD-11, AD-12 | **FR-25 unaddressed (M2); colour representation undecided (H3)** |
| §4.7 Unread (FR-27…32) | AD-6, AD-7, AD-8, AD-9, AD-19 | AD-7 now correct; AD-6's freeze seam unnamed (item 1) |
| §4.8 Notifications (FR-33…37) | AD-12, AD-14, AD-20, Q7 | Yes — AD-20 closes the gap I had here |
| §4.9 Navigation (FR-38…42) | AD-15, AD-16 | Yes |

**Open questions.** Q1 (AD-6) and Q8 (AD-5) resolved — both verified against code. Q7, Q2 and
now Q3 deferred with conditions. Q4, Q6 and Q10 acceptably absorbed. **Q9** (a free-text reason on
locking) is still not decided, deferred or open anywhere. **Q5** (upstream lifecycle announcement)
is still resolved only in `.memlog.md`; AD-17 now mentions a *different* upstream issue (threads
persisting after message deletion), which is useful but is not Q5. See L2.

## 7. Dimensions the altitude owns

**Verdict: one whole dimension still silent.**

Operational envelope: present and correct, unchanged. Stack: now stronger — Oracle is a real
fourth CI engine and the spine draws the right consequence for AD-11, AD-9 and AD-19.

Accessibility / theming / localisation: still absent. See H3.

---

# Findings

## HIGH

### H1 — AD-1 still mandates "re-set the cache, never remove", which loses writes under AD-13's last-write-wins *(unchanged from first pass, not addressed)*

`ThreadService::updateLastMessageInfoAfterReply` (`:256-268`) is the hot path — every reply. It
issues a raw increment (`num_replies = num_replies + 1`) and then `remove()`s the cache entry at
`:266`. AD-1's Rule and the Cache convention row both require it to instead **re-write** the entry
"from the fresh entity", which for an increment means a read-after-write.

Two writers interleaving as `UPDATE_A, UPDATE_B, SET_B, SET_A` leave the cache holding A's older
snapshot while the database holds B's, for the full **900s** TTL (`:51`, `:74`, `:126`), with no
version check. That is PRD §5's "highest-risk correctness surface" and directly contradicts FR-2's
*"the later request wins"*. `remove()` has no such race and is not vulnerable to negative caching:
`findByThreadId` (`:61-79`) writes the `''` sentinel only inside the `DoesNotExistException`
handler (`:76`), so an invalidated key falls through to the mapper. The negative-caching argument
in AD-1's *Prevents* justifies **invalidation**, which `remove()` already provides; it does not
justify re-set over remove.

**Fix.** Split the rule by mutator shape: a mutator may re-set only a value it authored in its own
write (`createThread`, `renameThread`, the new state/featuring mutators — all full-entity writes);
read-modify-write mutators `remove()`. Phrase it as *no mutator writes a cache value it did not
itself author*.

### H2 — AD-14 now names six registries; `lib/Signaling/Listener.php` still has two more, and they are the ones that carry the payload

The fix added the inline early-return array. Two sites in the same function remain unnamed, both
gated on `$messageType === 'thread_created' || $messageType === 'thread_renamed'`:

| Line | What it does | Effect of omitting a new verb |
| --- | --- | --- |
| `:558` | inline early-return allow-list | **Now covered by AD-14 registry 5** |
| `:81` | `SYSTEM_MESSAGE_TYPE_RELAY` | **Covered** |
| `:576-583` | loads `$thread` for those two verbs only | `$thread` is `null`, so `$message->toArray('json', $thread)` at `:596` carries no thread |
| `:621-635` | attaches `$data['chat']['comment']['threadInfo']`, then sends and returns | The relayed payload carries **no thread object at all** |

AD-13 says other participants converge *"through the relayed state-change system message of
AD-14"* and AD-15 says *"relayed state-change system messages update the store"* — that is the
spine's only convergence mechanism. A verb registered in all six named places still relays a bare
comment with no `threadInfo`, so `useChatExtrasStore` has nothing to write and the Directory,
thread header and Followed Thread List all keep the old state until reload. The message now gets
through; it just arrives empty.

**Fix.** Extend registry 5 to *"every branch in `notifySystemMessageSent` keyed on a thread verb —
the early-return array, `SYSTEM_MESSAGE_TYPE_RELAY`, the `$thread` load and the `threadInfo`
payload block"*, or lift the shared condition into one `SYSTEM_MESSAGE_TYPE_THREAD_STATE` constant
all four consult. Add the acceptance condition that a relayed lifecycle message carries a
populated `threadInfo`.

### H3 — Accessibility, theming and the tag colour palette remain undecided, and the colour part is a schema decision *(unchanged, not addressed)*

Zero occurrences of *access*, *keyboard*, *screen reader*, *contrast*, *theme*, *palette* in the
current spine. PRD §5 makes all three cross-cutting NFRs; FR-24 and FR-38 turn them into testable
consequences.

Two concrete consequences. First, `talk_thread_tags.colour` is an unqualified "colour" in the
Schema delta, while FR-24 requires *"a fixed palette chosen for contrast in both light and dark
themes"* and forbids arbitrary input — palette index versus validated string is a schema and
validation-seam decision two epics answer differently. Second, PRD §5's *"state conveyed by more
than colour ... a tag's text always accompanies its colour"* is an invariant across the four
surfaces that render state and tags (Directory row, thread header, Thread Root Message, Followed
Thread List — FR-8, FR-32), built by different epics with nothing holding them together.

**Fix.** One Consistency-Conventions row for the presentation envelope: tag colour is a fixed
server-owned palette with the constant and validation seam named; state and unread are conveyed by
text or icon as well as colour; new controls meet Talk's existing keyboard and screen-reader bar;
all new strings go through `t('spreed', …)` while Thread Titles and Tags are never translated
(AD-11 already covers search matching).

---

## MEDIUM

### M1 — FR-5's edit/delete refusal is bundled into open question 2 and thereby loses its owner *(new — created by the AD-2 fix)*

AD-2's narrowing is correct and well evidenced. But the Deferred bullet for Q2 now folds edits,
deletes and pins into the reactions question. Reactions are genuinely open (PRD Q2, owner
requester). Edit/delete refusal is **decided** — FR-5, *"Editing or deleting a message that already
exists in a Locked Thread is refused"*, PRD assumption 5, in MVP scope per §9.1.

If Q2 comes back "no", the third seam is never built and edits and deletes go unguarded with it —
a Locked Thread whose content a participant can still change by editing their own message, which
is the specific hole assumption 5 closes.

**Fix.** Separate them. Keep reactions in Deferred as Q2. Give the third seam its own AD or its own
in-scope line covering edit/delete/pin, with the seam named — the natural one is a shared guard
called from `ChatManager::editMessage` (`:693`), `deleteMessage` (`:626`) and `pinMessage`
(`:773`), leaving `ReactionManager` to be wired in only if Q2 resolves yes.

### M2 — FR-25 is in MVP scope but AD-10 and Deferred both push its operation to v2 *(unchanged, not addressed)*

PRD §9.1 puts FR-23…FR-26 in scope; §9.2 defers only rename, merge and delete across Threads.
FR-25 — *"A Thread Manager can change the colour bound to a tag text in the Conversation"* — is
none of those, yet AD-10 ends *"Its `updateTag`/`deleteTag` are the v2 administration surface, not
v1"* and Deferred repeats it. Nothing provides FR-25's endpoint, authority check or propagation,
and its third consequence (*"visible to other participants without them reloading"*) collides with
AD-14, whose relay set is defined as lifecycle transitions and is simultaneously AD-2's exemption
list — so putting a tag change on the relay list widens the Locked exemption, and leaving it off
leaves FR-25 with no mechanism.

**Fix.** Either state in AD-10 that colour change is the one `updateTag`-shaped operation that *is*
v1, naming its propagation mechanism and its relationship to AD-2's exemption list, or move FR-25
into Deferred as a recorded scope cut.

### M3 — AD-3 puts `isThreadManager` where it cannot reach the root comment *(unchanged, not addressed)*

AD-3 fixes the signature as `ThreadService::isThreadManager(Thread $thread, Participant $participant): bool`.
The check needs the root comment; upstream loads it with `$this->chatManager->getComment(…)`
(`ThreadController.php:216`). `ChatManager` already constructor-injects `ThreadService`
(`ChatManager.php:111-121`), so `ThreadService` cannot inject `ChatManager` without a container
cycle, and the spine's own dependency graph draws no `S3 → S2` edge. The signature has no comment
parameter, so callers cannot supply one.

The workable resolution is injecting `CommentsManager` into `ThreadService` and re-implementing
`getComment`'s object-type/object-id check (`ChatManager.php:951-957`) — including whether the
federated-conversation guard at `:947-949` is reproduced, which two epics can answer differently,
inside the one method AD-3 exists to keep singular.

**Fix.** Name the seam: `ThreadService` takes `CommentsManager` with the root-comment lookup in one
private helper beside `isThreadManager`, or widen the signature to accept an optional `?IComment`
with a single resolver.

### M4 — "Two capability flags" does not say which of the three capability arrays, and AD-18 has no other signal *(unchanged, not addressed)*

`lib/Capabilities.php` publishes three arrays: `FEATURES` (`:35`), `CONDITIONAL_FEATURES` (`:140`)
and `LOCAL_FEATURES` (`:148`), the last exposed as `features-local` at `:267`. `features-local` is
how a client learns a feature does not work over federation — `conversation-tags` is in both
`FEATURES` and `LOCAL_FEATURES`; `threads` is in `FEATURES` only.

AD-18 requires the client to **hide** thread affordances on a federated Conversation, and
`features-local` is its only mechanism. An epic following the nearest precedent (`threads`,
`FEATURES` only) advertises thread management as available on federated Conversations, and AD-18
becomes unimplementable as written.

**Fix.** AD-12 states both flags go in `FEATURES` **and** `LOCAL_FEATURES`; AD-18 names
`features-local` as the mechanism its hiding relies on.

---

## LOW

### L1 — AD-8's "semantics token" has an unspecified position, and one position disables six invalidation sites

The unread-count cache key is `$chat->getId() . '-' . $lastReadMessage` (`ChatManager.php:999`),
invalidated **by prefix** at `ChatManager.php:214`, `:318`, `:362`, `:482`, `:659` and
`ParticipantService.php:756`, all calling `clear($chat->getId() . '-')`. Appending the token keeps
all six working; prepending it breaks all six silently, and counts stay wrong for the 1800s TTL.
Say the token is appended, or that the `clear()` prefix is part of the key contract.

### L2 — PRD open questions 9 and 5 are still not visible in the spine

Q9 (a free-text reason on locking) is neither decided, deferred nor open. It is small — a parameter
on AD-14's constant, additive under AD-12 — but an epic reader cannot tell it was considered. Q5
was answered during the run (no upstream lifecycle work announced) and recorded only in
`.memlog.md`; AD-17 now cites a *different* upstream issue. One line each.

### L3 — FR-20 rides on the capability map rather than on an AD

AD-12 names `GET .../threads/recent` gaining parameters. FR-20 requires title search over the
**Followed Thread List**, which is `subscribed-threads` → `ThreadService::getRecentByActor()`
(`:143-176`) — a hand-built query, not the mapper. AD-11's normalised column serves it, but no rule
says the search happens in that query rather than in the client store, which is the discipline
AD-12 applies to the other list.

### L4 — FR-22's featured cap has no home in the conventions

The Dates-and-counts row pins the unread display cap as a shared constant but not the
Featured-per-Conversation bound, which FR-22 requires to be a server-side constant identical for
every Conversation, producing an error naming the limit. One clause in that row, alongside
AD-10's `MAX_TAG_IDS_PER_CONVERSATION`.

---

# Summary

The spine improved materially between passes. AD-7 went from the worst AD in the document to one
of the best; AD-2, AD-10, AD-16, AD-19 and the Stack are all more accurate than they were, and
AD-20 closes the notification-sensitivity gap I had open. Its ratification of the brownfield
codebase — verified again this pass across roughly thirty file:line claims — remains its strongest
property.

Three things still block a clean gate. **H1** is untouched and is the one rule in the document that
makes working code worse: AD-1 requires re-setting a cache entry where the existing code correctly
removes it, and under AD-13's last-write-wins that loses writes for fifteen minutes. **H2** is a
half-landed fix — the relayed message now gets through and arrives empty, because two of the three
thread-verb branches in `notifySystemMessageSent` are still unnamed. **H3** is a whole dimension
still silent, with a schema decision (tag colour) hanging off it.

Two findings are new and both are artefacts of the corrections: AD-6's lazy freeze moved the
decision into a seam that does not exist singly (eight writers, three meanings, one of which
recreates the day-one flood the change was made to prevent), and FR-5's settled edit/delete
refusal got bundled into the deferral of an open question, so a "no" on reactions silently drops a
requirement the PRD decided.
