---
title: Clavis Talk — Thread Management and Navigation
status: final
revision: r3
created: 2026-08-08
updated: 2026-08-08
---

# PRD: Clavis Talk — Thread Management and Navigation

*Working title — confirm.*

## 0. Document Purpose

This PRD is for the Clavis engineering team building thread management into Clavis Talk, and for the downstream BMad workflows that turn it into architecture and stories. It originates from GitHub issue `ClavisTechLtd/clavis-deploy#22`, which lists ten gaps in the thread feature; every one of those ten is traced to a requirement here, and §14 maps them explicitly, including the two places where what is specified is narrower than what was asked.

Vocabulary is fixed by §3 Glossary — downstream documents must use those terms verbatim. Features are grouped in §4 with functional requirements nested and numbered globally (FR-1 … FR-43) so epics and stories have stable references. Where a decision was inferred rather than confirmed, it carries an inline `[ASSUMPTION]` tag and is indexed in §16.

Clavis Talk is a fork of Nextcloud Talk (`clavis-spreed`, tracking upstream `stable34` / Talk 24.0.3). Threads already exist upstream: creation, replies, renaming, per-thread notification levels, a recent-threads list and a followed-threads list all ship. This PRD does not re-specify those. It specifies what upstream does not have, and it corrects one thing upstream does wrong. The distinction matters for effort — several of the ten items extend working machinery, and one has most of its value in a follow-up epic rather than here.

**Revision 3.** All ten open questions are now closed — three by the architecture workflow, seven by the requester — and §13 records each resolution rather than the question. Three of those resolutions changed this document: FR-43 is new (a free-text reason when locking), §10's success metrics are replaced by acceptance criteria because no reference deployment exists to measure against, and §5's scale figures are now a design target rather than an inference. Revision 2 follows a four-reviewer pass. Three of its claims about the codebase were wrong and have been corrected, one requirement was removed because its premise did not exist, and the count of code paths a Locked Thread must block went from six to nine. Where a reviewer's finding changed a requirement, the requirement says so.

Implementation-level material lives in `addendum.md` beside this file; §15 lists what it contains. This document stays at the level of capability and behaviour.

## 1. Vision

A Clavis Talk room used seriously for work accumulates threads the way a shared inbox accumulates mail. That is a sign of health, not a problem — until there is no way to tell a live discussion from one that ended three weeks ago. Today every thread a room has ever produced sits in one undifferentiated list, sorted by nothing but recency and reachable only by opening the right-hand sidebar, going to "Shared items", and clicking through a three-item preview. A room with two hundred threads is a room where nobody uses threads, because finding the one you want costs more than starting a new one. The feature defeats itself at exactly the scale it was built for.

This work gives threads a lifecycle and a front door. A thread can be marked done, so the list shows what is actually live. A thread can be locked, so a decision that has been made stops being re-litigated. A thread can be featured, so a room's standing references stay at the top. A thread can be tagged, so a room that runs several kinds of work can separate them. And threads get a real destination in the interface — their own place alongside the room's other surfaces, searchable by title, showing where you have unread replies — instead of being a sub-page of a sub-page.

The payoff is that threads become the default way a busy room organises itself rather than a feature people abandon. Concretely: a moderator can leave a room tidy in a few minutes instead of not bothering; a member returning after leave can find the three threads that moved without reading everything; and unread state finally means something per thread, so a badge points at where you actually owe a reply.

## 2. Target User

Clavis Talk is sold to organisations who self-host it as part of a Clavis-branded Nextcloud deployment. The users below are inside those customer organisations, not Clavis staff. `[ASSUMPTION: personas are inferred from the issue text ("groups with very many threads"), the existence of per-customer deployments in the Clavis repo set, and the issue author's framing. No customer interviews, support tickets or usage data were supplied. Confirm or replace before the epics workflow consumes §2.3 — this is the highest-leverage unconfirmed item in the document.]`

### 2.1 Jobs To Be Done

- **Keep a busy room legible.** When I open a room I want to see what is live right now, not an archaeological record. Functional, and the primary driver of the issue.
- **Signal that something is settled.** When a discussion reaches a decision I want that visible to everyone, so it stops being reopened by someone who did not read to the end. Social as much as functional.
- **Stop a conversation that has gone wrong.** Off-topic drift, a heated exchange, a thread that duplicates another one — I need to end it, not just ask people to stop. Functional with an authority dimension.
- **Find a thread I remember by name.** I know there was a thread about the invoice format. I do not know when, and I am not going to scroll. Functional.
- **Know where I owe a reply.** Across a room with many threads, I want to see which ones moved since I last looked, per thread and not in aggregate. Functional, and the difference between threads being useful and being noise.
- **Keep the room's standing references reachable.** Some threads are permanent — the on-call rota, the release checklist. They should not sink. Functional.
- **Separate kinds of work inside one room.** This room runs customer escalations and internal planning. I want to look at one without the other. Functional, organisational.

### 2.2 Non-Users (v1)

- **Federated participants.** Users joining a room from another Nextcloud server. Upstream Talk does not support threads over federation at all — `ChatController` carries explicit `FIXME support threads in federation` markers on both message-fetch paths. Thread state, featuring, tags and unread counts are not specified across federation boundaries in v1, and the interface must degrade rather than mislead.
- **Mobile app users, for notification behaviour specifically.** The Clavis Talk Android and iOS clients are not modified by this PRD. Everything except §4.8 is server plus web, so mobile users get lifecycle, tags, featuring and per-thread unread as soon as the server ships. The notification work here is groundwork for a follow-up epic — see §4.8 and §9.1, which are blunt about how little of it is user-visible now.
- **Guest and public-link participants, as thread managers.** Guests may read and, where the room permits, reply. They do not get lifecycle, featuring or tagging authority. `[ASSUMPTION: guests are excluded from all thread management. The issue says "people who opened the thread or room moderators", and a guest session is too weak an identity to hold that authority across reconnects.]`
- **Bots, as thread managers.** Bots may create threads and post into them, as they already do. They do not change Thread State. Deferred, not refused — see §13.

### 2.3 Key User Journeys

- **UJ-1. Hạnh closes out the week in a room with 180 threads.**
  Hạnh moderates the room her team uses for customer escalations; it has run for a year and has more threads than anyone has counted. Already signed in on the web client. She opens the room and clicks **Threads** in the top bar — its own destination now, not a link inside Shared items. The list opens filtered to Ongoing, newest first, and eleven threads show an unread badge. She works down it: four are finished, and for each she opens the row's menu and picks **Close**. They leave the list as she goes. One is a duplicate of another thread that is still live, so she **Locks** it after posting a line pointing at the other. The count of Ongoing threads drops from 27 to 22. **Climax:** the list now shows only what is live, and she can see it in one screen without scrolling. **Resolution:** she leaves the room; Monday's opener sees a list that means something. **Edge case:** one of the threads she closed gets a reply on Saturday from a colleague who had more to say — the thread returns to Ongoing on its own and reappears in the list, so nothing is lost by closing something prematurely.

- **UJ-2. Dũng ends his own thread when the decision lands.**
  Dũng opened a thread three days ago to settle which date format the export uses. Six people replied; the answer is ISO-8601. He is a regular member, not a moderator. He opens the thread, uses the thread header menu, and picks **Close**. He is allowed to, because he started it. The thread header now shows a Closed marker with a note that posting will reopen it, and a system message records that he closed it, so nobody has to ask whether the decision is final. **Climax:** the state is visible to everyone who opens the thread, without reading forty replies to find out it ended. **Resolution:** the thread drops out of the room's default Ongoing list but stays fully readable and postable. **Edge case:** he closed it too early and someone objects — he reopens it from the same menu without having to post a filler message to undo his own click.

- **UJ-3. Linh comes back from two weeks' leave and finds what moved.**
  Linh returns to a room she has been away from. She opens **Threads** and sees, at the top, three featured threads that are the room's standing references, then the Ongoing threads with unread badges showing how many replies she has not read. She types "invoice" into the thread search box; two threads match by title, one of them Closed. She opens the Ongoing one and lands on her first unread reply rather than at the top of a long thread. She reads it, answers, and the badge on that row clears — the badges on the other eight stay, because she has not read those. **Climax:** eleven minutes in, she knows what she missed and where she owes an answer. **Resolution:** what she has cleared is gone; what she has not is still there tomorrow, and reading the room's main chat later does not wipe it. **Edge case:** her search term matches nothing, and the empty state offers to search message bodies instead of titles — handing her to the existing message search rather than dead-ending.

- **UJ-4. Minh triages a notification without opening it.**
  Minh has the desktop client open in the background. Someone replies in a thread he follows. The notification names the thread — "Reply in *Invoice format for Giza*" — rather than only the room, so he can tell at a glance whether it is the thread he has been waiting on. It is, so he clicks it and lands in that thread, scrolled to the new message. **Climax:** he decided whether to interrupt himself by reading the notification, not by opening the app. **Resolution:** he answers in two lines and closes the window. **Edge case:** the thread was Locked between the notification being sent and his click — he arrives in the thread, sees the Locked state and who locked it, and the composer is disabled with an explanation rather than silently failing when he tries to send.

- **UJ-5. Hạnh separates two kinds of work in one room.**
  The escalation room also carries internal planning, and the mix makes both harder to read. Hạnh tags the customer threads `escalation` in red and the planning ones `planning` in blue, choosing the colour the first time she uses each tag. Afterwards, anyone in the room typing `escalation` gets the same red — the colour belongs to the tag within the room, not to whoever typed it. She then filters the thread list to `escalation` and gets the eight she cares about. **Climax:** one room reads as two, without splitting it into two rooms and losing the shared membership. **Resolution:** other members see the same tags and can filter the same way. **Edge case:** she mistypes `escalaton`, sees it presented as a new tag rather than an existing one, and corrects it before saving.

## 3. Glossary

Downstream workflows and readers must use these terms exactly. Introducing a synonym anywhere is a discipline violation. Two terms were chosen against the obvious word to avoid colliding with features Talk already ships — see the notes.

- **Thread** — A branch of conversation inside one Conversation, rooted at a single Thread Root Message, carrying a Thread Title, a Thread State, zero or more Thread Tags, and a featured flag. Belongs to exactly one Conversation. Already exists upstream; this PRD extends it.
- **Thread Root Message** — The chat message a Thread grew from. Its message identifier *is* the Thread's identifier; a Thread cannot exist without it. One-to-one with Thread.
- **Thread Title** — The human-readable name of a Thread, set at creation and editable afterwards. Already exists upstream.
- **Thread State** — Exactly one of **Ongoing**, **Closed**, or **Locked**, defined in §4.1. Every Thread has one; the default is Ongoing. New in this PRD.
- **Ongoing** — The Thread State of a live Thread. Anyone who may post in the Conversation may post in it. Appears in the Thread Directory by default.
- **Closed** — The Thread State of a Thread whose discussion has concluded. Advisory only: posting is still permitted, and posting returns the Thread to Ongoing. Hidden from the Thread Directory's default filter unless the Thread is Featured.
- **Locked** — The Thread State of a Thread that has been shut. All writes are refused. Only a Thread Manager may return it to Ongoing.
- **Thread Manager** — For a given Thread, the author of its Thread Root Message, or any moderator of the Conversation. The only actors who may change Thread State, feature a Thread, or set Thread Tags. Mirrors the authority rule upstream already applies to renaming.
- **Thread Directory** — The interface surface listing the Threads of one Conversation, with filtering, sorting, search and unread indication. A peer of the Conversation's other sidebar surfaces, not nested inside Shared items. New in this PRD; replaces the current nested list.
- **Followed Thread List** — The existing cross-Conversation list of Threads the current user is subscribed to, shown in the left sidebar. Already exists upstream; this PRD extends what each row displays.
- **Thread Tag** — A free-text tag applied to a Thread, carrying a colour. The colour is bound to the tag text within one Conversation, so identical text renders identically for every member of that Conversation. A Thread may carry several; a tag may be on many Threads. New in this PRD. *Named "tag" rather than "label" because Talk already ships a `conversation-tags` feature and one product should not have both words. Note the difference in kind: Talk's conversation tags are per-user and private, while Thread Tags are shared within the Conversation.*
- **Featured Thread** — A Thread a Thread Manager has raised to the top of the Thread Directory, above all unfeatured Threads regardless of state or activity, and shown regardless of the active state filter. New in this PRD. *Named "featured" rather than "pinned" because Talk already ships a `pinned-messages` feature and the two would be confused in the same room.*
- **Thread Unread Count** — The number of messages in one Thread the current user has not read. Independent per Thread: clearing it in one Thread does not clear it in another, and reading the Conversation's main chat does not clear it.
- **Thread Read Marker** — The record of how far one participant has read in one Thread. Exists only for Threads that participant has read; where absent, their Conversation read marker stands in. New in this PRD.
- **Conversation** — What Nextcloud Talk calls a room. Contains Threads, has moderators, has participants. Pre-existing; named here only to fix the word.
- **Thread Subscription** — The existing relationship recording that a user follows a Thread and at what notification level. Created implicitly on reply. Already exists upstream; unchanged by this PRD.

## 4. Features

### 4.1 Thread Lifecycle

**Description:** Every Thread has exactly one Thread State. New Threads are Ongoing. A Thread Manager moves a Thread between states, and the interface shows the current state wherever a Thread appears.

The two non-default states do different jobs and must not be conflated. **Closed** is a statement about the discussion: it is finished. It is advisory, and deliberately cheap to get wrong — anybody who may post in the Conversation may still post in a Closed Thread, and doing so returns it to Ongoing. That is what makes closing a habit rather than a decision: a moderator tidying a room does not have to be sure, because a premature close costs one message to undo and undoes itself. **Locked** is a statement about permission: this Thread is shut. Writes are refused, and only a Thread Manager can reopen it. Realises UJ-1, UJ-2.

State changes are recorded as system messages in the Thread, the way renaming already is, so the Thread carries its own history. `[ASSUMPTION: state changes warrant system messages, following the pattern upstream established for creation and renaming. It does add noise to short threads.]`

Locking alone carries an optional free-text reason (FR-43). Closing does not: a Closed Thread is advisory and undone by anyone posting, so there is nothing to explain, while a Locked Thread refuses writes and the participant who hits that refusal has no other way to learn why. This was open question 9 and the requester resolved it into v1.

**Functional Requirements:**

#### FR-1: Thread State exists and defaults to Ongoing

Every Thread has a Thread State of Ongoing, Closed, or Locked. Realises UJ-1.

**Consequences (testable):**
- A newly created Thread reports state Ongoing.
- Every Thread that existed before this feature shipped reports state Ongoing after migration, with no interaction required. No migration reclassifies existing Threads — see the limitation in §9.3.
- The Thread representation returned by the API includes the state on every endpoint that returns a Thread.
- A request to set a state outside the three defined values is refused with a validation error naming the field.

#### FR-2: A Thread Manager can close a Thread

A Thread Manager can set an Ongoing Thread to Closed. Realises UJ-1, UJ-2.

**Consequences (testable):**
- The Thread reports state Closed immediately after the request succeeds.
- A system message is added to the Thread recording who closed it.
- A participant who is neither the Thread Root Message author nor a Conversation moderator is refused with a permission error.
- Closing a Thread that is already Closed succeeds without duplicating the system message.
- When two Thread Managers change the state of the same Thread concurrently, the later request wins and the earlier actor's client is told the resulting state rather than continuing to display its own.

#### FR-3: Posting into a Closed Thread returns it to Ongoing

Any participant who may post in the Conversation can post into a Closed Thread, and doing so sets the Thread back to Ongoing. Realises UJ-1 edge case.

**Consequences (testable):**
- A non-manager participant posting a reply into a Closed Thread succeeds.
- The Thread reports state Ongoing after that reply.
- The Thread reappears in the Thread Directory's default filter without the participant refreshing.
- Reopening by reply produces no system message about the state change — the reply itself is the record. `[ASSUMPTION: silent reopen. A system message on every reopen would be noise given that reopening is meant to be frictionless.]`
- Every path that can add content to a Thread reopens it, not only a typed message: the nine paths enumerated in FR-5 behave identically here.
- A participant with permission to post but no Thread Manager authority can reopen a Closed Thread this way, and cannot reopen a Locked one. The asymmetry matches Discord's documented rule and is deliberate — addendum §10.2.

#### FR-4: A Thread Manager can lock a Thread

A Thread Manager can set a Thread to Locked from either Ongoing or Closed. Realises UJ-1.

**Consequences (testable):**
- The Thread reports state Locked immediately after the request succeeds.
- A system message is added recording who locked it.
- A non-manager is refused with a permission error.
- Locking is reachable from Closed without passing through Ongoing.
- Concurrent state changes resolve as in FR-2.

#### FR-5: A Locked Thread refuses every write

While a Thread is Locked, all attempts to add content to it are refused, from every entry point. Realises UJ-4 edge case.

Content reaches a Thread through **nine** distinct paths. They are listed separately because a guard on the obvious ones is not a guard, and because a code review of this PRD's first revision found three of them missing — one of which performs no thread validation at all today. Each is its own testable consequence so a missed path fails a test rather than a customer.

**Consequences (testable):**
- A typed message posted into a Locked Thread is refused with an error identifying the Thread as locked, distinguishable by the client from a generic permission failure and from a Thread-not-found failure.
- A message from a bot posting through the bot API is refused.
- A message from an in-process bot answering an invocation event is refused.
- A rich object shared into a Locked Thread is refused.
- A poll created in a Locked Thread is refused.
- A **file share** into a Locked Thread is refused. This is a separate path from rich objects, handled by the system-message listener that reads the thread identifier from share metadata, and it can create Threads as well as post into them.
- An **attachment uploaded** into a Locked Thread is refused. This path currently performs no thread validation whatsoever, so it needs validation added before it can be guarded.
- A message **scheduled** into a Thread that is Locked at scheduling time is refused.
- A message scheduled *before* the Thread was Locked does not post when its time arrives; the background job marks it failed with a reason the sender can see. This path has no live request and no acting participant.
- Every refusal fails visibly rather than silently redirecting the content to the Conversation's main chat.
- Editing or deleting a message that already exists in a Locked Thread is refused. `[ASSUMPTION: Locked freezes existing content too. "Locked" reading as "this cannot change" is the less surprising interpretation, and it closes the hole where a participant edits their own message to say something new.]`
- Reacting to a message in a Locked Thread is refused. **Confirmed by the requester; open question 2 is closed.** The assumption was carried on the belief that this was a cheap, isolated call. It is not isolated: reactions reach the comments store by the same route as the edits, deletes and pins this requirement already refuses, so all four ride one enforcement seam. Refusing reactions is therefore marginal work on a seam the requirement mandates anyway, and Locked means frozen without exception.
- System messages recording Thread State changes are still written to a Locked Thread, so FR-4 and FR-7 can record themselves. The exemption is specifically for those messages and must not be expressed as "system messages are exempt" — four of the nine paths above post their content *as* system messages, so that framing would leave four holes. See addendum §3.2.

#### FR-6: A Thread Manager can reopen a Closed Thread directly

A Thread Manager can set a Closed Thread back to Ongoing without posting a message. Realises UJ-2 edge case.

**Consequences (testable):**
- The Thread reports state Ongoing after the request succeeds and reappears in the default Directory filter.
- A system message is added recording who reopened it.
- The Thread Root Message author can do this to their own Thread without being a moderator.
- A non-manager is refused, and their route to reopening remains FR-3.

#### FR-7: A Thread Manager can reopen a Locked Thread

A Thread Manager can set a Locked Thread back to Ongoing. Realises UJ-1.

**Consequences (testable):**
- The Thread reports state Ongoing after the request succeeds and accepts writes again from all nine paths.
- A system message is added recording who reopened it.
- A non-manager is refused, including a non-manager who posts a message — a Locked Thread does not reopen by reply the way a Closed one does.

#### FR-8: Thread State is visible everywhere a Thread appears

The current Thread State is shown on every surface that represents a Thread. Realises UJ-2.

**Consequences (testable):**
- The Thread Directory row shows state for Closed and Locked Threads; Ongoing shows no badge, so the common case stays quiet.
- The thread header shows state while the Thread is open, and names who set it.
- The Thread Root Message in the main chat shows state alongside the existing thread title and reply count.
- A row in the Followed Thread List shows state.
- The Closed indicator conveys that the Thread can still be posted in and that posting reopens it, rather than reading as a shut Thread. This is the mitigation for "Closed" meaning the opposite in issue trackers — see the note below.

**Notes:** Comparable-product research (addendum §10) found that **Closed** carries three incompatible meanings: Discord's "Close Thread" is exactly this PRD's Closed — hidden, revived by posting — while GitHub, Jira and Viva Engage users read "closed" as *finished* or *replies blocked*. The requester has confirmed **Ongoing / Closed / Locked** as the interface wording, matching the Discord reference the issue names. The cost is that a participant arriving from an issue tracker will assume a Closed Thread cannot be posted in, which is why FR-8 carries the consequence above.

#### FR-43: A Thread Manager can state a reason when locking

A Thread Manager may attach a free-text reason when Locking a Thread, and that reason is shown wherever the Locked state is explained. Realises UJ-4 edge case. *Added after the first release of this document, resolving open question 9 in favour of v1.*

**Consequences (testable):**
- Locking with a reason succeeds, and locking without one succeeds — the reason is optional and its absence is not an error.
- The reason is carried on the locking system message, so it is part of the Thread's own history and needs no second lookup.
- The thread header, while the Thread is Locked, shows the reason alongside who locked it. Where no reason was given, the header shows only who locked it, with no empty affordance.
- The composer's refusal explanation includes the reason when one exists, so the participant who tries to post learns why in the place they hit the wall.
- The lifecycle notification of FR-34 carries the reason when one exists.
- The reason is user-authored content in a customer Conversation and is withheld exactly where a Thread Title is withheld — a sensitive Conversation's notification carries neither.
- Reopening the Thread and locking it again replaces the reason rather than accumulating reasons; the earlier one survives in the earlier system message.
- Whitespace-only input is treated as no reason. Length is bounded and the bound is stated before the participant hits it.

### 4.2 Thread Management Authority

**Description:** Thread State, featuring and tagging are restricted to Thread Managers: the author of the Thread Root Message, or any moderator of the Conversation. This matches the rule upstream already applies to renaming a Thread, so the model is consistent rather than novel. A member who starts a Thread keeps authority over it without needing moderator rights. The issue asks for exactly this, in these words. Realises UJ-2.

**Functional Requirements:**

#### FR-9: Thread management is restricted to Thread Managers

Only a Thread Manager may change Thread State, feature or unfeature a Thread, or add or remove Thread Tags. Realises UJ-2.

**Consequences (testable):**
- The Thread Root Message author succeeds at each of those operations on their own Thread without being a moderator.
- A Conversation moderator succeeds on any Thread in that Conversation.
- A participant who is neither is refused, with the same error shape for all three operation families.
- A guest session is refused regardless of who created the Thread.
- Authority is evaluated per request; a member demoted from moderator between opening a menu and using it is refused on the request, not at session end.

#### FR-10: The interface offers only what the actor may do

Management controls appear only for actors who may use them. Realises UJ-2.

**Consequences (testable):**
- A non-manager viewing a Thread sees no state, featuring or tag controls — not disabled ones.
- A member sees controls on Threads they started and not on others', within the same list.
- A moderator sees controls on every Thread in the Conversation.

#### FR-11: Existing renaming authority is unchanged

Renaming a Thread continues to follow the same Thread Manager rule it follows today.

**Consequences (testable):**
- Renaming behaviour and permissions are identical before and after this work, verified by the existing integration coverage.

### 4.3 Thread Directory

**Description:** The Thread Directory is where a Conversation's Threads live. The issue's fourth item is that today's equivalent list is in the wrong place — buried in Shared items behind a three-item preview, as §1 describes. The Directory becomes a peer of the Conversation's other sidebar surfaces, with its own entry point in the top bar and, on narrow screens, in the same navigation the other surfaces use.

It lists Featured Threads first, then the rest by most recent activity; filters by Thread State — defaulting to Ongoing, because the point is to show what is live — and by Thread Tag; and searches by Thread Title. Each row carries the title, state, tags, reply count, last activity and Thread Unread Count. The three-item preview in Shared items is removed rather than left as a second path. Realises UJ-1, UJ-3, UJ-5.

**Functional Requirements:**

#### FR-12: The Thread Directory is a first-class destination

A participant can reach the Thread Directory for the current Conversation directly, without passing through Shared items. Realises UJ-1, UJ-3.

**Consequences (testable):**
- A control in the Conversation's top bar opens the Thread Directory in one click from anywhere in the Conversation.
- The Directory is registered as a peer of the Conversation's other sidebar surfaces — reachable by the same navigation, appearing in the same set, and not rendered by replacing them. A participant can move between the Directory and any other surface without an intermediate step. `[ASSUMPTION: "peer" means a sibling of the existing sidebar tabs, since the issue says "ngang hàng với Shared Items" and Shared items is a tab. The current threads list is not a tab — it replaces the whole tab set — so this is a change in kind, not only in placement.]`
- The Directory is reachable on narrow and mobile-width viewports through the same navigation as the Conversation's other surfaces.
- The thread list no longer appears inside Shared items, and Shared items contains no thread preview.
- Opening the Directory does not change which Thread, if any, the participant has open.

#### FR-13: The Directory lists Featured Threads first, then by activity

The Directory orders Featured Threads above all others, and orders within each group by most recent activity. Realises UJ-3.

**Consequences (testable):**
- Every Featured Thread sorts above every unfeatured Thread, whatever their activity or state.
- Within each group, the Thread with the most recent activity is first.
- A reply arriving in a listed Thread moves it to the top of its group without the participant refreshing.

#### FR-14: The Directory filters by Thread State, and Featured Threads survive the filter

A participant can filter the Directory by Thread State; the default filter shows Ongoing Threads, and Featured Threads appear regardless of the filter. Realises UJ-1.

**Consequences (testable):**
- Opening the Directory fresh shows Ongoing Threads and every Featured Thread, and no others.
- A Featured Thread that is Closed or Locked appears under the default filter with its state shown, so featuring is never silently overridden by the filter.
- Closed and Locked Threads that are not Featured are reachable by changing the filter, and the filter makes clear that Threads are being hidden rather than absent.
- The chosen filter persists while the participant stays in the Conversation and resets to Ongoing when they return to it later. `[ASSUMPTION: filter resets rather than persisting across visits. A sticky filter that hides live threads is a support call.]`

#### FR-15: The Directory filters by Thread Tag

A participant can filter the Directory to Threads carrying a chosen Thread Tag. Realises UJ-5.

**Consequences (testable):**
- Selecting a tag narrows the list to Threads carrying it.
- Tag and state filters combine.
- Only tags in use in this Conversation are offered.
- Clearing the tag filter restores the previous list without reloading the Directory.

#### FR-16: Each Directory row carries enough to decide without opening

Each row shows Thread Title, Thread State, Thread Tags, reply count, last activity, and Thread Unread Count. Realises UJ-3.

**Consequences (testable):**
- Every listed field is present for a Thread that has a value for it.
- A Thread with no unread messages shows no unread indicator at all.
- A long Thread Title truncates without pushing the state, tags or unread indicator out of view.
- A Thread carrying the maximum five Thread Tags renders them without displacing the other fields.

#### FR-17: The Directory loads incrementally

The Directory loads a bounded first page and fetches more as the participant goes down the list. Realises UJ-1.

**Consequences (testable):**
- The first page returns without the participant waiting on the full Thread count for the Conversation.
- Reaching the end of the loaded list fetches the next page and appends it in order.
- A Conversation whose Threads all fit in one page shows no pagination affordance.
- Filtering and searching apply across all Threads in the Conversation, not only those already loaded.

### 4.4 Thread Search

**Description:** Searching the Thread Directory finds Threads by Thread Title. It answers "I remember there was a thread about X" — a different question from "find the message where someone said X", which Talk's existing message search already answers. The two stay distinct and cross-referenced, with the handoff always available rather than offered only on failure. Realises UJ-3.

**Functional Requirements:**

#### FR-18: A participant can search Threads by title

A participant can search the Threads of the current Conversation by Thread Title. Realises UJ-3.

**Consequences (testable):**
- A search matching part of a title returns that Thread.
- Matching ignores case and matches any part of the title, not only its start.
- Matching is diacritic-insensitive for Vietnamese input, so `dinh dang` finds a Thread titled `Định dạng`. `[ASSUMPTION: diacritic-insensitive matching is required. The customer base is Vietnamese-speaking and typing without diacritics is normal; an exact-match search would fail most real queries.]`
- Search covers all Threads in the Conversation regardless of Thread State, and results show state so a match in a Locked Thread is not mistaken for a live one.
- Results appear as the participant types, without a submit action.

#### FR-19: Title search always offers the message search alongside it

The participant can hand a search term to the existing message search at any point, not only when the title search returns nothing. Realises UJ-3 edge case.

**Consequences (testable):**
- A control to search message bodies for the same term is present whether the title search returned zero, one or many results, so a wrong-but-nonzero result set does not dead-end.
- Taking that control opens the existing message search surface with the term applied, and does not lose the participant's place in the Conversation.
- An empty title-search result states the term searched and makes the message-search control the prominent next step.

#### FR-20: The Followed Thread List is searchable by title

A participant can search the cross-Conversation Followed Thread List by Thread Title.

**Consequences (testable):**
- A search term narrows the followed list to Threads whose titles match, across every Conversation.
- Each result identifies which Conversation the Thread belongs to.
- Matching follows the same rules as FR-18.

### 4.5 Featured Threads

**Description:** A Thread Manager can feature a Thread so it stays at the top of the Thread Directory regardless of activity or state, and regardless of the active filter. Featuring is for a Conversation's standing references — the rota, the checklist, the escalation procedure — which by their nature stop generating replies and would otherwise sink out of sight. It is per Conversation and visible to everyone in it, not a private bookmark. Realises UJ-3.

**Functional Requirements:**

#### FR-21: A Thread Manager can feature and unfeature a Thread

A Thread Manager can feature a Thread and can unfeature it. Realises UJ-3.

**Consequences (testable):**
- A featured Thread reports as featured and appears above unfeatured Threads for every participant in the Conversation, not only the actor.
- Unfeaturing returns it to activity ordering and, if its state is Closed or Locked, to being hidden under the default filter.
- A non-manager is refused.
- Featuring is independent of Thread State: a Closed or Locked Thread can be featured and remains visible under the default filter per FR-14.
- Featuring a Thread never changes its Thread State.

#### FR-22: The number of Featured Threads per Conversation is bounded

A Conversation may have at most a fixed small number of Featured Threads. Realises UJ-3.

**Consequences (testable):**
- Featuring beyond the limit is refused with an error naming the limit, not a generic failure.
- The limit is stated in the interface before the participant hits it.
- The limit is a server-side constant, identical for every Conversation. `[ASSUMPTION: a hard bound of five. Unbounded featuring recreates the problem the Directory exists to solve, and a featured group large enough to need its own scrolling is not a featured group.]`

### 4.6 Thread Tags

**Description:** A Thread Tag is free text with a colour, applied by a Thread Manager. Free text keeps it frictionless: no moderator has to define a taxonomy before anyone can use one. Colour is what keeps free text from becoming the usual mess. The colour is bound to the tag text within the Conversation, so the first person to use `escalation` in a room picks its colour and everyone in that room sees `escalation` in that colour thereafter. Tags are shared, not personal. They are the axis the Directory filters on. Realises UJ-5.

The recognised failure mode is near-duplicate tags — `escalation` and `escalations` and `Escalation` side by side. The mitigation is in input, not enforcement: when a participant types a tag the interface offers the Conversation's existing tags first and marks clearly when the input would create a new one. `[ASSUMPTION: suggestion, not validation. Blocking similar-looking tags would be wrong as often as right, and the requester chose free text over a moderated set knowing the tradeoff.]`

**Functional Requirements:**

#### FR-23: A Thread Manager can tag a Thread

A Thread Manager can add a Thread Tag to a Thread and remove one from it. Realises UJ-5.

**Consequences (testable):**
- An added tag is visible to every participant in the Conversation.
- Removing a tag from one Thread does not affect other Threads carrying the same tag.
- A non-manager is refused.
- Tags are visible to every participant regardless of authority — only editing is restricted.
- A Thread may carry up to five Thread Tags; a sixth is refused with an error naming the limit. `[ASSUMPTION: five per Thread, matching Discord's forum-tag cap rather than invented.]`
- A Conversation may accumulate up to twenty distinct Thread Tags; creating a twenty-first is refused with an error that says so and points at the existing tags. `[ASSUMPTION: twenty per Conversation. This is both Discord's cap and the value Talk's own ConversationTagService already uses for tags per conversation, so it is the number this codebase has settled on.]`

#### FR-24: A tag's colour is fixed within a Conversation

Within one Conversation, the same tag text always renders in the same colour. Realises UJ-5.

**Consequences (testable):**
- The first use of a tag text in a Conversation sets its colour; the actor chooses from a defined palette.
- Later uses of the same text in that Conversation take the established colour, and the participant applying it is not asked for one.
- Tag text is compared case-insensitively for colour binding, so `Escalation` and `escalation` are one tag, not two. `[ASSUMPTION: case-insensitive identity. Two tags differing only in case are always an accident.]`
- The same tag text in a different Conversation is independent and may have a different colour.
- Colours come from a fixed palette chosen for contrast in both light and dark themes; arbitrary colour input is not accepted.

#### FR-25: A Thread Manager can change a tag's colour for the Conversation

A Thread Manager can change the colour bound to a tag text in the Conversation. Realises UJ-5.

**Consequences (testable):**
- Changing the colour updates every Thread carrying that tag in that Conversation.
- A non-manager is refused.
- The change is visible to other participants without them reloading. `[ASSUMPTION: live propagation. If it proves costly, a reload-to-see-it fallback is acceptable and should be raised in architecture rather than silently adopted.]`

#### FR-26: Tag input surfaces existing tags before creating new ones

When applying a tag, the participant is offered the Conversation's existing tags and shown clearly when their input would create a new one. Realises UJ-5 edge case.

**Consequences (testable):**
- Typing shows matching existing tags in the Conversation, with their colours.
- Input matching no existing tag is presented as a new tag, visually distinct from picking an existing one, before it is saved.
- Selecting an existing tag applies it without asking for a colour.
- Leading and trailing whitespace is stripped, and empty or whitespace-only input is refused.

### 4.7 Thread Unread State

**Description:** A row in the Thread Directory shows how many messages in that Thread the participant has not read, and that state is **independent per Thread**. Reading one Thread clears that Thread and nothing else. Reading the Conversation's main chat clears nothing in any Thread. Both halves are required: the cheap implementation derives unread from the single Conversation-level read marker, and that marker cannot express "read thread A, not thread B" in either direction. Each Thread therefore carries its own Thread Read Marker for each participant who has read it.

One consequence the issue does not mention has to be faced here, because a shipped API field depends on it. Today the Conversation's unread message count includes thread replies, and the Conversation's mention flag is computed *from* that count being non-zero. If reading a Thread must not change main-chat unread, then thread replies cannot keep contributing to it. So the count becomes main-chat-only and the mention indication is recomputed independently. That is a deliberate change to the meaning of a shipped field — the one exception to §7's additive-only rule, taken because the alternative is a Conversation badge that cannot be cleared without opening every Thread. FR-30 specifies it, §7 records the exception, and §12 carries the regression risk for the three unmodified clients.

Storage is why upstream removed per-Thread markers. This PRD answers it by materialising a Thread Read Marker only for Threads a participant has actually read. A Thread a participant has never opened has no row and falls back to their Conversation read marker, which keeps a new member from walking into a room with five hundred old Threads and five hundred unread badges. Rows track engagement, not the product of participants and Threads. The interaction between that fallback and an advancing Conversation marker is open question 1. `[ASSUMPTION: lazy materialisation with fallback. It is the mechanism that makes per-Thread markers affordable, and the joining-member case is the behaviour it must produce; architecture may reach the same behaviour another way.]` Realises UJ-3.

**Functional Requirements:**

#### FR-27: Each Thread reports a Thread Unread Count for the current participant

Every Thread returned to a participant carries the number of messages in it that participant has not read. Realises UJ-3.

**Consequences (testable):**
- A Thread the participant has fully read reports zero.
- A Thread with three messages the participant has not seen reports three.
- The count excludes the participant's own messages. `[ASSUMPTION: own messages never count as unread, matching Conversation-level behaviour.]`
- The count excludes system messages, so a rename or a state change does not read as an unread reply.
- Two participants who have read different amounts of the same Thread get different counts for it.
- A participant who has never opened a Thread gets a count derived from their Conversation read marker, so a Thread whose activity predates their joining the Conversation reports zero rather than its full length.

#### FR-28: Thread unread state is independent per Thread

Clearing unread state in one Thread does not clear it in any other Thread, and reading the Conversation's main chat does not clear it in any Thread the participant has read before. Realises UJ-3.

**Consequences (testable):**
- With unread messages in Threads A and B, reading A leaves B's count unchanged.
- With unread messages in Thread A that the participant has read before, reading the Conversation's main chat to its end leaves A's count unchanged.
- Reading Thread A does not change the Conversation's main-chat unread count.
- A Thread's count returns to zero only when that participant has read that Thread to its end, or has marked it read per FR-29.
- A participant who leaves and rejoins the Conversation does not inherit stale per-Thread read state from before they left.
- For a Thread the participant has never opened, behaviour follows open question 1 and must be stated explicitly by the architecture rather than emerging from the implementation.

#### FR-29: A participant can clear unread state without opening a Thread

A participant can mark one Thread read, and can mark every Thread in a Conversation read, from the Directory. Realises UJ-1, UJ-3.

**Consequences (testable):**
- Marking a Thread read from its Directory row returns its count to zero without opening it.
- A mark-all-threads-read action clears every Thread in the Conversation, including Threads hidden by the active filter, so a badge raised by a Closed Thread is always clearable.
- Marking read does not change Thread State and does not appear as activity to other participants.
- After a mark-all, the Conversation reports no thread-unread indication per FR-31.

#### FR-30: Conversation unread counts main-chat messages only

The Conversation's unread message count covers messages in the main chat and excludes messages inside Threads; the Conversation's mention indication no longer depends on that count being non-zero. Realises UJ-3.

**Consequences (testable):**
- A Conversation whose only unread messages are thread replies reports zero unread messages and reports the thread-unread indication of FR-31 instead.
- A participant mentioned only inside a Thread is still reported as mentioned, even though the Conversation's unread message count is zero. This is the specific regression the change would otherwise cause, because the mention indication is currently derived from the count.
- A participant mentioned in the main chat is reported as mentioned exactly as they are today.
- Reading a Thread does not decrease the Conversation's unread message count; reading the main chat does.
- The change is announced through its own capability, separate from the thread-management capability, so a client can tell which unread semantics the server uses.

#### FR-31: The Conversation reports whether it has unread Threads

A Conversation indicates whether it contains any Thread with unread messages for the current participant, separately from its main-chat unread count. Realises UJ-1.

**Consequences (testable):**
- The Conversation reports a thread-unread indication when at least one of its Threads has unread messages, and none when no Thread does.
- The indication distinguishes unread replies in Threads from unread mentions in Threads.
- A Conversation whose main chat is fully read but which has an unread Thread still reports the thread-unread indication.
- A Conversation with unread main-chat messages and no unread Threads reports no thread-unread indication.
- The indication is raised by Threads hidden under the default filter as well as visible ones, and FR-29's mark-all is the guaranteed way to clear it.
- The Conversation list surfaces it in a way that does not duplicate or contradict the main-chat unread badge.

#### FR-32: The Directory and the Followed Thread List show unread state

Thread Unread Count is displayed in both list surfaces. Realises UJ-3.

**Consequences (testable):**
- A Thread with unread messages shows a count in the Thread Directory.
- The same Thread shows a count in the Followed Thread List.
- A Thread with none shows no indicator.
- Counts update as messages arrive while the list is open, and clear as the participant reads, without a reload.
- A count above a display bound renders as a capped indicator rather than widening the row.
- A Thread containing an unread mention of the participant is distinguished from one containing only unread replies.
- Opening a Thread with unread messages positions the view at the first one, with a visible boundary marking where unread begins, and that boundary does not jump as new messages arrive.
- Leaving a Thread part-read preserves the position: reopening it returns to the same first-unread message, not to the end.

### 4.8 Thread-Aware Notifications

**Description:** One improvement worth making, and one defect worth fixing. They are separated because their value is very different, and the first revision of this PRD overstated both.

**The improvement.** A notification about activity in a Thread does not say which Thread — the reader sees a room and a message and has to infer. Naming the Thread makes a notification triageable without opening it, which is UJ-4's actual payoff. This is the part that lands for real users on real clients in this epic, and it is the only part.

**The defect.** The server attaches the thread identifier to a notification's object id, but that code sits inside a branch skipped when the payload being built is a push payload. Code review established the precise shape and corrected two claims this PRD made in its first revision. First, the two `setObject` calls **append**, producing `{token}/{messageId}/{threadId}` rather than one overwriting the other; the repository's own integration test asserts that value and the shipped Android client reads both positions. Second, the guard skips **both** calls, so a push payload's object id is the bare room token, carrying neither the message identifier nor the thread identifier. The requirement written to stop an overwrite has been removed because the overwrite does not exist; what remains is that push payloads should carry what in-app notifications already carry.

**What this delivers now: almost nothing, and that is worth saying plainly.** Web and desktop deep-linking into threads already works, because the link is set outside the push guard. Android already routes into threads on push: it fetches the notification over the API and reads the identifiers from *that* object id, which is not push-prepared. So the issue's complaint of landing in the main chat is a narrower failure in that client's fallback path when the fetch fails, not a missing server capability. Fixing the payload therefore removes a client's dependence on a second network call; it does not, by itself, change what any user sees. Its value is enabling the mobile epic in §11. §9.1 records this. Realises UJ-4.

**Functional Requirements:**

#### FR-33: Thread notifications name the Thread

Every notification generated by activity inside a Thread identifies the Thread by its Thread Title. Realises UJ-4.

**Consequences (testable):**
- Each of the nine notification subjects the server can emit for in-thread activity carries the Thread Title: a plain message to a follower, a reply, a direct mention, a group mention, a team mention, an all mention, a reaction, and a reminder. A test exists per subject, not one test for the family.
- A notification for activity outside any Thread is unchanged and gains no thread text.
- A Thread Title too long for the available space is truncated with an indication, and the truncation does not remove the message preview entirely.
- In a Conversation the recipient has marked sensitive, the Thread Title is withheld along with the other content already withheld.

#### FR-34: Thread lifecycle changes notify the Thread's followers

A participant following a Thread is notified when it is Closed, Locked or reopened. Realises UJ-2, UJ-4.

**Consequences (testable):**
- A participant subscribed to a Thread receives a notification when a Thread Manager Locks it, naming the Thread, who locked it, and the reason where FR-43 supplied one.
- The same holds for closing and reopening.
- The notification respects the participant's per-Thread notification level, so someone who muted the Thread is not notified.
- A participant who is not subscribed to the Thread is not notified.
- No participant learns a Thread is Locked only by attempting to post and failing.
- Reopening by reply (FR-3) generates the ordinary reply notification and no additional state notification.

#### FR-35: Push payloads carry the same identifiers as in-app notifications

A push payload about activity in a Thread carries both the message identifier and the Thread identifier, as the in-app notification already does. Realises UJ-4.

**Consequences (testable):**
- A push payload generated for in-thread activity contains both identifiers, in the same composed form the in-app notification uses, so a client parses one shape.
- A push payload for activity outside a Thread contains the message identifier, which it does not today.
- The in-app notification's identifiers are unchanged, verified by the existing integration assertion on that value.
- Existing clients that read positionally continue to work, and a client that never receives a thread identifier — because the activity was not in a Thread — must not misread the shorter value. `[ASSUMPTION: backward compatibility is mandatory, because the shipped Android and iOS clients parse this value by position and are not updated in this epic.]`
- A payload for a sensitive Conversation carries the identifiers but no Thread Title, so a client can route without leaking content. **Confirmed by the requester; open question 3 is closed.** A Thread identifier is routing information, not content: it names a destination without disclosing anything about what is in it, and withholding it would cost sensitive Conversations their thread routing entirely — the rooms where landing in the right place matters most.

#### FR-36: Following a notification lands the recipient in the Thread

Acting on a notification about a Thread opens that Thread with the relevant message in view. Realises UJ-4.

**Consequences (testable):**
- Following the notification on web opens the Conversation with the Thread open and the message in view — behaviour that already works, now covered by a test so the payload change cannot break it.
- Following one about a message outside a Thread opens the Conversation's main chat as it does today.
- Following one about a Locked Thread opens the Thread and shows its state with the composer disabled, rather than failing.
- Following one for a Thread since deleted lands in the Conversation's main chat with an explanation, not an error page.

#### FR-37: Push notification text stays within its size budget

Adding the Thread Title does not push a push payload past what the transport accepts.

**Consequences (testable):**
- A notification for a Thread with a maximum-length title and a maximum-length message preview is delivered.
- When both cannot fit, the Thread Title is preserved and the message preview is shortened, because the title is what makes the notification triageable. `[ASSUMPTION: title wins over preview when space is short.]`
- Truncation is applied on character boundaries valid for Vietnamese text, never mid-character.

### 4.9 Thread Navigation Entry Points

**Description:** The issue's fourth item asks not only for a dedicated thread page but for thread entry points "in other places, so navigation makes sense", without specifying which places. This adds the entry points that make the Directory reachable from where participants already are. Realises UJ-1, UJ-3.

**Functional Requirements:**

#### FR-38: The Conversation top bar opens the Thread Directory

A control in the Conversation's top bar opens the Thread Directory. Realises UJ-1.

**Consequences (testable):**
- The control is present for every Conversation whose server supports thread management, and absent otherwise.
- It indicates when the Conversation has Threads with unread messages.
- It is reachable by keyboard and announced by screen readers with its purpose and unread state.

#### FR-39: A Thread's own header links to the Directory

While a Thread is open, the participant can reach the Thread Directory for its Conversation from the thread header. Realises UJ-3.

**Consequences (testable):**
- The thread header offers a route to the Directory alongside the existing route back to the main chat.
- Taking it leaves the Thread open behind the Directory, so dismissing the Directory returns the participant to where they were.

#### FR-40: The Thread Root Message links to the Directory

The Thread Root Message in the main chat offers a route to the Thread Directory as well as into the Thread. Realises UJ-1.

**Consequences (testable):**
- The Thread Root Message's existing route into the Thread is unchanged.
- A distinct route opens the Directory positioned on this Thread's row.

`[ASSUMPTION: the three entry points above — top bar, thread header, Thread Root Message — are the PRD's choice, since the issue says "in other places" without naming them. The Conversation list in the left sidebar is deliberately excluded, because the existing Followed Thread List already serves cross-Conversation navigation there. Confirm with the requester, who may have had specific places in mind.]`

#### FR-41: Thread destinations are linkable and restorable

A Thread and the Thread Directory each have an address a participant can copy, share and return to. Realises UJ-4.

**Consequences (testable):**
- Copying the link to a message in a Thread produces a link that opens that Thread at that message — the behaviour that exists today, preserved.
- The Thread Directory has an address that reopens it on the same Conversation.
- Reloading the page with a Thread open restores that Thread, not the main chat.
- Browser back and forward move between the main chat, a Thread, and the Directory in the order visited.

#### FR-42: Thread routing works in every surface that renders a Conversation

Thread and Directory addresses resolve wherever Talk renders a Conversation, not only in the main application. Realises UJ-3.

**Consequences (testable):**
- A thread address opens the Thread when the Conversation is rendered inside the Files sidebar, which uses a separate router instance from the main application.
- A Conversation rendered in that surface either offers the Directory or omits its entry point cleanly, rather than offering a control that fails.

## 5. Cross-Cutting NFRs

- **Scale is the point.** Every list surface must stay usable in a Conversation with at least a thousand Threads and two hundred participants. That is the condition the issue describes, so the requirements are written against it rather than treating it as a stretch target. **The requester has adopted these figures as a deliberate design target rather than an observation; open question 6 is closed.** The room that prompted the issue holds roughly 180 Threads, so the target is a safety margin over the known case — and it costs nothing, because the mechanisms that satisfy it (keyset paging, a fixed number of queries per page) are no more expensive than the ones that would not.
- **No unbounded work per request.** Returning a page of Threads must not cost work proportional to the Conversation's total Thread count. Thread Unread Count is the most likely place for a per-Thread query to creep in, since the straightforward implementation counts messages once per row.
- **Per-participant thread state must not grow without bound.** Thread Read Markers are stored per participant per Thread and are the one genuinely new source of row growth. Growth must track Threads a participant has read rather than Threads that exist, and rows must be reclaimed when a participant leaves a Conversation.
- **Cached Thread data must not go stale.** Thread data is served from a distributed cache with a fifteen-minute lifetime and negative caching for misses. Every operation in this PRD that changes a Thread must invalidate it, or a state change will appear to succeed and then revert for other participants for up to fifteen minutes. This is the highest-risk correctness surface in the work.
- **Concurrent management resolves predictably.** Two Thread Managers acting on the same Thread must not produce a state that neither chose. The later write wins, and the earlier actor's client is corrected rather than left displaying a state the server does not hold. This applies to state, featuring and tags alike.
- **Migration must be safe on live customer data.** Customers self-host and upgrade in place. Schema changes must apply to a Conversation with a large Thread history without a maintenance window, and must be safe to run twice.
- **The interface must survive missing capability.** A client may talk to a server that predates this work, or — because of FR-30 — to one whose unread semantics differ from what it expects. Every new surface and every changed field must be gated on a declared capability and must degrade to current behaviour rather than erroring.
- **Accessibility.** New controls and list surfaces meet the same bar as the rest of Talk: keyboard reachable, screen-reader announced, and state conveyed by more than colour — which matters specifically for Thread Tags, where colour is the point. A tag's text always accompanies its colour.
- **Theme.** Tag palette and state indicators must be legible in both light and dark themes at normal and high contrast.
- **Localisation.** All new user-visible text is translatable. Thread Titles and Thread Tags are user content and are never translated. Vietnamese is the primary customer language and must be correct in search matching, truncation and sorting.
- **Real-time consistency.** State, featuring and tag changes must reach other participants viewing the same Conversation without a reload, over the signalling path Talk already uses for thread events.

## 6. Constraints and Guardrails

### 6.1 Upstream Divergence

`clavis-spreed` is a fork that must keep taking upstream Nextcloud Talk releases, so every change here is a future merge conflict. The requester has already established the constraint that makes this unavoidable: *"kiến trúc con talk & spreed này ko làm kiểu plugin first được mà phải chọc vào code"*. Talk's architecture offers no extension point for thread semantics, message-send interception or list surfaces, so the fork is modified directly.

Two guardrails follow. First, extend upstream structures rather than replacing them — adding to the existing thread tables and endpoints costs less at merge time than a parallel implementation. Second, where upstream has deliberately not done something, record why we are doing it anyway. The live example is the per-Thread read marker: upstream added those columns and removed them three weeks later, and this PRD reinstates them because the alternative does not deliver what the issue asks for (§4.7). That is a knowing, permanent divergence on a table upstream may still change, and together with FR-30's change to the Conversation unread count it is the largest merge liability in this work.

The repository is currently a shallow clone with a single commit of history, which makes upstream rebasing impractical. Restoring full history is a prerequisite for the work, not part of it.

### 6.2 Data and Privacy

Thread Titles and Thread Tags are user-authored content in customer conversations and carry the same sensitivity as messages. Two consequences. Notifications must respect the existing sensitive-Conversation setting, withholding Thread Titles wherever message previews are already withheld. And the Clavis push proxy must remain unable to read what it carries: nothing in this PRD may move Thread Titles into any part of a push payload the proxy can inspect. The proxy relays material encrypted by the customer's server for the device, and that property is a stated commitment to customers, not an implementation detail.

### 6.3 Cost of Per-Thread Unread

Per-Thread unread independence (§4.7) is not free. Four costs are accepted deliberately.

**Storage and write volume.** A Thread Read Marker is one row per participant per Thread they read, updated every time they read further. In an active Conversation that is the highest-write-rate table this feature touches. Rows exist only for Threads a participant has actually read and are reclaimed when a participant leaves — but a heavily used Conversation will still accumulate them, and capacity planning must assume it.

**A changed API field.** FR-30 redefines the Conversation unread count to exclude thread replies and decouples the mention indication from it. This is the one deliberate breach of §7's additive-only rule. Three shipped clients read that field; §12 carries the regression risk.

**Divergence from upstream.** Reinstating the columns upstream created and dropped means the fork carries a shape upstream rejected, on a table upstream continues to change (§6.1).

**A split notion of "read".** Conversation unread stops being one number. Reading the main chat no longer implies the Conversation is read, and reading a Thread no longer contributes to main-chat unread. Every surface that shows one unread state must be checked against the two it now has, and the two must never contradict each other — FR-31's consequences exist to pin that down.

## 7. API Surface and Compatibility

This is a public OCS API consumed by three shipped clients that this epic does not modify. That constrains change shape.

- **Additive only, with one recorded exception.** New fields on the Thread representation, new endpoints for the new operations; no field removed, renamed, or given a new meaning — **except** the Conversation unread count and the mention indication derived from it, which FR-30 deliberately redefines. That exception is taken with its cost named in §6.3 and its risk in §12; nothing else may follow its precedent.
- **Composed identifier values are append-only.** The notification object identifier is a composed, positionally-parsed value that shipped clients read by index. FR-35 may add a position; it may not reorder or remove one. This is the rule the first revision of this PRD wrongly believed the current code was breaking — the code appends correctly, and the rule exists so that stays true.
- **Capability-gated.** New behaviour is announced through capabilities the clients already know how to read. FR-30's change needs its own capability, separate from the thread-management capability, so a client can tell which unread semantics the server uses independently of whether thread management is present.
- **Documented in the generated specification.** Thread endpoints are currently undocumented outside the generated OpenAPI specification; new endpoints must appear there, and the regenerated specification is part of the work rather than a follow-up.
- **Errors are distinguishable.** A write refused because a Thread is Locked must be distinguishable from one refused for lack of permission and from one refused because the Thread does not exist. FR-5 depends on this: the composer cannot explain a refusal it cannot identify.
- **Federation degrades, never lies.** Thread state, featuring, tags and unread counts are not specified across federation. A federated Conversation must present threads as it does today rather than showing controls that silently do nothing.

## 8. Non-Goals

- **Not a task tracker.** Thread State's three values describe the state of a conversation. It is not assignment, due dates, priority, or status workflow. A Closed Thread means the talking stopped, not that work finished.
- **Not a moderated taxonomy.** Thread Tags are free text by explicit choice, which makes this product an outlier: of the four comparable tools researched, only Discord has per-thread tags and they are predefined per channel with no free-text path (addendum §10.5). There is no tag administration surface, no per-Conversation approved set, no renaming a tag across Threads, no tag hierarchy.
- **Not private organisation.** Featuring and tags are shared within the Conversation. Talk's existing conversation tags are per-user and private; Thread Tags deliberately are not. Personal bookmarks, personal tags and per-participant ordering are not in this product.
- **Not automatic lifecycle.** No auto-close after inactivity, no auto-archive, no scheduled cleanup. Every state change is a person deciding. `[NOTE FOR PM]` Auto-close on inactivity is the most likely follow-up request once moderators feel the manual cost in a room with a thousand Threads, and §9.3 makes it more likely still.
- **Not federation.** See §2.2 and §7.
- **Not mobile clients.** See §11.
- **Not a thread permission system.** Thread Managers are derived from existing authority. There is no per-Thread participant list, no per-Thread role, no delegating management of one Thread to a specific person.
- **Not threads-of-threads.** A Thread has one level. Replying inside a Thread does not create a sub-Thread.

## 9. MVP Scope

### 9.1 In Scope

- Thread State — Ongoing, Closed, Locked — with soft close, hard lock, an optional free-text reason on locking, manager reopen from either state, and write refusal across all nine paths into a Thread plus edits, deletes, pins and reactions (FR-1 … FR-8, FR-43).
- Thread Manager authority for state, featuring and tagging, mirroring existing renaming authority (FR-9 … FR-11).
- Thread Directory as a peer sidebar surface, replacing the list nested in Shared items: filtering by state and tag, Featured-first ordering, incremental loading, and rows that carry enough to decide without opening (FR-12 … FR-17).
- Thread search by title, diacritic-insensitive, with an always-available handoff to the existing message search; and title search over the Followed Thread List (FR-18 … FR-20).
- Featured Threads, bounded per Conversation (FR-21, FR-22).
- Thread Tags — free text, colour bound to tag text within a Conversation, existing-tag suggestion on input, bounded at five per Thread and twenty per Conversation (FR-23 … FR-26).
- Per-Thread unread state: independent per Thread, unaffected by reading the main chat or other Threads, with mark-one and mark-all, the redefinition of Conversation unread that independence requires, a Conversation-level thread-unread indication, and display in both list surfaces (FR-27 … FR-32).
- Notifications that name the Thread on all nine in-thread subjects, lifecycle notifications to followers, and push payloads carrying the identifiers in-app notifications already carry (FR-33 … FR-37).
- Thread Directory entry points from the top bar, the thread header and the Thread Root Message, with linkable, restorable addresses that work in every surface rendering a Conversation (FR-38 … FR-42).
- Capability declarations, regenerated API specification, and integration coverage extending the existing thread test suite.

**Honesty note on §4.8.** Only FR-33 and FR-34 produce a user-visible change on a client this epic touches. Web and desktop deep-linking into threads already works, and Android already reaches threads from a push by fetching the notification over the API — so FR-35 through FR-37 remove a network round trip and a fallback failure mode rather than adding a capability anyone can see. They are groundwork so the mobile epic can deliver the issue's item 8, and they cannot be verified end-to-end on a device until the push delivery path is unblocked (§11). Anyone reading §14 row 8 as "item 8 is done" is reading it wrong.

### 9.2 Out of Scope for MVP

- **Android and iOS client adoption of the corrected payload, and the Android push fallback fix** — separate epic. That fallback is where the issue's reported symptom actually originates; correcting it needs the client, not the server. See §11.
- **Threads over federation** — upstream does not support thread message fetching over federation; we are not building that first.
- **Automatic lifecycle transitions** — deferred to v2. Load-bearing given §9.3.
- **Bulk operations on Threads** — closing, tagging or featuring many at once. Deferred to v2. Considered for MVP as a mitigation for §9.3 and declined by the requester.
- **Migration-time reclassification of existing Threads** — declined by the requester; see §9.3.
- **Tag administration** — renaming a tag across Threads, merging near-duplicates, deleting one everywhere. Deferred to v2; FR-26's suggestion behaviour is the v1 mitigation. Talk's own conversation tags already ship rename and delete, so there is an in-product surface to copy when this is picked up.
- **Bots changing Thread State** — **decided, not deferred.** Put to the requester and excluded from v1. Bots create Threads and post into them as they do today. Cheap to add later, since the endpoints and the authority method will exist; what is missing is a decision about which bots would qualify, and nobody has asked for one.
- **Personal featuring, personal tags, personal ordering** — non-goal, not deferral. §8.

### 9.3 Known Limitation: the First Day After Upgrade

FR-1 migrates every existing Thread to Ongoing. In the room that prompted this issue, that means the new Directory opens showing all 180 Threads under its default filter — the same undifferentiated list, in a better place. Nothing in this MVP changes that on day one: closing is manual, bulk close is deferred, and no migration reclassifies anything.

Two mitigations were offered to the requester and both declined: a migration that closes Threads inactive beyond a threshold, and bulk close in MVP. The decision is to let rooms self-correct as moderators work through them. AC-1 demonstrates that the manual path is walkable at the target scale; whether rooms actually walk it can only be learned from a customer (AC-W2), and if they do not, §9.2's first deferrals become the next release.

This is recorded here rather than buried because it is the gap between what the requirements deliver and what the issue's framing complains about. All forty-two requirements can pass while a room still reads as cluttered on the day it upgrades.

## 10. Acceptance Criteria

This section replaced a set of success metrics. Those metrics were written against a consenting reference deployment and a customer willing to answer, and open question 4 asked whether either existed. **The answer is no.** Clavis Talk is self-hosted, Clavis collects no product telemetry from customer servers, and there is no deployment whose database can be queried a month after adoption. Every metric that depended on one — live-thread share, creation rate, list length, tag and featuring adoption, Locked share, tag-ceiling pressure — was unmeasurable as written, and keeping it would have been decoration.

What replaces it is measurable without a customer: criteria demonstrated in a **seeded environment** built to the §5 scale target, plus two things that can only be watched rather than measured. Not every requirement has an acceptance criterion beyond its own testable consequences; the criteria below test whether the *thesis* holds — that giving threads a lifecycle and a front door makes a room navigable enough that people keep using threads.

**Demonstrated in a seeded environment.** A Conversation seeded to the §5 target — a thousand Threads, two hundred participants, activity dates spread across a year — is the fixture. It is built once and kept, because AC-2 needs it twice.

- **AC-1: A cluttered room becomes a short list by working down it.** Starting from the seeded Conversation with every Thread Ongoing, a moderator closes Threads from the Directory rows without opening them, and the default-filter list shortens as they go. From the seeded state, reducing the default-filter list to twenty Threads or fewer takes only row-menu interactions — no navigation away from the Directory, no bulk tooling, no per-Thread page. The point being demonstrated is that the manual path §9.3 relies on is actually walkable at this scale; if it is not, §9.2's deferred bulk tooling is the answer and this is where that shows. Validates FR-2, FR-14, FR-16.
- **AC-2: The Directory is not slower than what it replaces.** Median and 95th-percentile first-page response times are captured on the **current nested list** against the seeded fixture **before the first Directory change ships**, and the Directory must not regress against that baseline once rows carry state, tags, reply count, last activity and unread count. Without the pre-work capture this criterion cannot fail, so capturing it is a task in the first Directory story, not a later measurement. Validates FR-16, FR-17, and the §5 no-unbounded-work NFR.
- **AC-3: A returning participant finds what moved.** In the seeded Conversation with unread messages distributed across many Threads, a participant reaches an unread Thread and answers in it using only the Directory: featured Threads at the top, unread counts on rows, title search to narrow, and landing on the first unread message rather than the end. Reading that Thread clears its badge and no other, and reading the main chat clears none of them. Validates FR-13, FR-18, FR-27, FR-28, FR-32.
- **AC-4: One room reads as two.** In the seeded Conversation, tagging a subset and filtering to that tag yields exactly that subset; the same tag text applied later by a different participant takes the established colour without asking; and both caps refuse with an error naming the limit rather than failing generically. Validates FR-15, FR-23, FR-24, FR-26.
- **AC-5: Per-participant thread state grows with engagement, not with the room.** In the seeded Conversation, Thread Read Marker rows are counted after seeding and again after a scripted read pass, and compared against participants × Threads. Growth must track Threads actually read. Rows are reclaimed when a participant leaves. This is the cost that plausibly drove upstream to create these columns and drop them three weeks later, so it is measured in a fixture before a customer meets it. Validates FR-27, FR-28, and the §5 growth NFR.
- **AC-6: The unread split does not contradict itself.** Across the seeded Conversation, the two unread states are exercised in both directions: a Conversation with no main-chat unread but an unread Thread, and one with main-chat unread and no unread Thread. Neither reads as a bug, a participant mentioned only inside a Thread is still reported as mentioned, and mark-all-threads-read clears the thread indication including Threads hidden by the active filter. Validates FR-29, FR-30, FR-31.

**Confirmed by demonstration, not by adoption.**

- **AC-7: The requester recognises their problem as solved.** The person who filed issue #22 is walked through AC-1 and AC-3 on the seeded fixture before release and answers, without qualification, that this is what they asked for. This is the only criterion that catches getting every requirement right and the product wrong. It is weaker than watching their real room for a month — that was SM-4 and it is not available — and the weakness is stated rather than papered over.

**Watched, not measured.** Two failure modes leave no trace in a fixture and no query anyone at Clavis can run. They are named so that the first report is recognised as the signal rather than treated as an isolated complaint.

- **AC-W1: Conversation unread badges after FR-30.** A redefined unread count misread by an unmodified Android, iOS or desktop client surfaces as "the badge is wrong". Support channels are the only detector. Any such report is treated as a regression in FR-30 until shown otherwise, not as a client bug.
- **AC-W2: Moderation replacing conversation.** A room where Locking has become routine rather than exceptional, or where the twenty-tag ceiling is being hit, means the product shaped behaviour the wrong way. Only a customer conversation reveals it. Counterbalances AC-1 and AC-4 — closing Threads is the goal, locking them is not, and tag adoption is good while tag exhaustion is not.

`[ASSUMPTION: a seeded fixture at the §5 scale target is representative enough to accept against. It is not a real room — real rooms have uneven Thread lengths, social structure and reply patterns a generator will not reproduce — so AC-1 and AC-3 demonstrate that the mechanism works, not that people will use it. Nothing available substitutes for that, and §9.3 remains the honest statement of what ships on day one.]`

## 11. Dependencies and the Mobile Follow-Up

**Upstream Nextcloud Talk.** Upstream may add thread lifecycle at any time, and if it does, our version becomes the conflict on a fork that must keep merging upstream releases (§6.1). Worth checking upstream's roadmap before architecture commits to a shape — open question 5.

**Nextcloud notifications app.** Push delivery goes through the platform notifications app, which encrypts each payload for the target device. FR-35 and FR-37 depend on what that app will carry and on the size it can encrypt. This is a platform component, not ours, and the size budget is a real constraint — open question 7.

**Clavis push proxy.** Not a dependency for correctness and cannot help: it relays payloads it cannot read. It is a dependency for *verification* — its delivery path to Apple and Google is unimplemented and blocked on an Apple push key and a Firebase project that Clavis does not have. FR-35 and FR-37 are verifiable at payload construction but not on a device. Downstream planning must not assume a device test exists.

**Mobile clients — the follow-up epic.** Two pieces of work, and code review clarified which one matters. Android already routes into a Thread from a push by fetching the notification over the API; the reported symptom of landing in the main chat comes from its **fallback path when that fetch fails**. So the epic is: fix that fallback, adopt the identifiers FR-35 puts directly in the payload so the fetch is no longer needed, and display Thread State, tags, featuring and per-Thread unread. **This is where the issue's item 8 actually gets delivered.** It depends on this epic shipping first, and on the push delivery path for its own verification.

## 12. Risks and Mitigations

- **FR-30 breaks unread badges on three unmodified clients.** Redefining the Conversation unread count changes what shipped Android, iOS and desktop clients display. The mention indication is currently *computed from* that count, so a careless change makes thread mentions stop registering as mentions everywhere. High likelihood of a visible regression, and the most dangerous single change in this work. Mitigation: FR-30's consequences specify the mention case explicitly, the change is capability-gated so clients can tell which semantics they are getting, and AC-W1 names the complaint pattern that would otherwise be the first anyone hears of it — with no reference deployment, support channels are the only detector.
- **Locked leaks through an unguarded path.** Content reaches a Thread through nine paths, including a background job with no live request, a file-share path that can also *create* Threads, and an attachment path that performs no thread validation at all today. The first revision of this PRD enumerated six and missed three — evidence that the enumeration is the control, not a formality. Mitigation: FR-5 lists all nine as separate testable consequences, and the system-message exemption is scoped narrowly because four of the nine post their content as system messages.
- **Cache staleness makes state changes look broken.** Thread data is cached for fifteen minutes with negative caching. A missed invalidation means a participant closes a Thread, sees it close, and watches it reappear for colleagues. High likelihood on first implementation, high visibility, reported as "the feature doesn't work". Mitigation: treat invalidation as part of every mutation's acceptance criteria, and cover it with a multi-actor integration test — a single-actor test cannot see the failure, because the actor reads their own fresh value.
- **Per-Thread unread counts turn into a per-Thread query.** The straightforward implementation counts messages once per row. At the scale this feature exists for, that is the Directory being slow at the moment it matters. Mitigation: AC-2 makes it measurable against a pre-work baseline captured on the seeded fixture, and the NFR forbids per-request work proportional to Thread count.
- **Thread Read Marker rows grow faster than expected.** One row per participant per Thread read, written on every read, on a hot path. This is the cost that plausibly drove upstream to drop these columns; lazy materialisation bounds it by engagement rather than eliminating it. Mitigation: AC-5 measures real growth in the seeded fixture, rows are reclaimed on leaving a Conversation, and capacity is measured before a customer meets it.
- **The two unread states contradict each other.** With main-chat unread and thread-unread separate, a Conversation can show no unread badge while holding an unread Thread, or the reverse. That reads as a bug regardless of which state is technically right. Mitigation: FR-31's consequences specify both directions, and FR-29's mark-all guarantees the thread indication is always clearable.
- **Day one disappoints (§9.3).** A customer upgrades, opens the new Directory, and sees the same 180 threads. The feature is judged before anyone has closed anything. Mitigation: none in this MVP by the requester's decision. AC-1 proves the manual path is walkable before release; after release AC-W2 is the only detector, and §9.2's deferred bulk tooling is the response.
- **Tag proliferation, or its ceiling.** Free text with no administration is how a room ends up with a tag list as unnavigable as the thread list was. The twenty-tag cap prevents the runaway case and creates a new one: rooms pressed against the ceiling with no way to merge or rename. Mitigation: FR-26's input suggestion, AC-W2 as the early warning, and tag administration named as the v2 answer in §9.2.
- **A composed identifier is misparsed by a client we do not control.** Shipped clients read the notification object identifier positionally. Adding a position to push payloads is safe; changing the order or the arity for any existing case is not. Mitigation: §7 states the append-only rule, and FR-35 requires the existing integration assertion on that value to keep passing.
- **Upstream merge cost compounds.** This touches thread tables, thread endpoints, the chat send path, the unread count, the notification builder and the signalling payload — a broad footprint on a fork, now including one changed field. Mitigation: additive-only discipline with the single recorded exception in §7, and full git history restored before the first change so rebasing is possible at all.
- **A migration stalls on a large customer table.** Schema changes apply in place on customer servers with long histories and no maintenance window. Mitigation: the NFR requires it, and it needs a rehearsal against realistic data volume, not a fresh install.

## 13. Open Questions — All Resolved

The ten open questions this document carried are closed. Three were settled by the architecture workflow, seven by the requester in a resolution session. They are kept here rather than deleted, because a downstream reader needs to know a decision was made and why, not merely that a question disappeared.

| # | Question | Resolved by | Resolution |
|---|---|---|---|
| 1 | What advances the Conversation read marker once Threads have their own? | Architecture — AD-6 | A nullable per-participant baseline on the attendee row, frozen lazily at one named seam on **any** advance, moved thereafter only by mark-all-threads-read. FR-27 and FR-28 hold as written. |
| 2 | Do reactions count as writes in a Locked Thread? | Requester | **Yes — refused.** The assumption survived, and on stronger grounds than it was made: reactions reach the comments store by the same route as the edits, deletes and pins FR-5 already refuses, so all four ride one enforcement seam. Locked means frozen without exception. |
| 3 | Is a Thread identifier content, for a sensitive Conversation? | Requester | **No — it is routing.** Payloads carry the identifiers and never the Thread Title. Withholding identifiers would cost sensitive Conversations their thread routing entirely. FR-35 holds as written. |
| 4 | Is a reference deployment available for the success metrics? | Clavis | **No**, and no customer is committed to answering. §10's metrics were unmeasurable as written and have been replaced by acceptance criteria demonstrated on a seeded fixture, plus two watch items. |
| 5 | Has upstream announced thread lifecycle work? | Architecture — AD-17 | Checked during the architecture run: nothing announced, so there is nothing to shape toward and AD-17 contains the divergence instead. Re-check before a major upstream release. |
| 6 | What is the actual scale to design for? | Requester | **A thousand Threads and two hundred participants, adopted as a design target** rather than an observation. The room that prompted the issue holds roughly 180 Threads, so this is a deliberate safety margin — and it costs nothing, because keyset paging is no more expensive than the offset paging that would not scale. |
| 7 | Does the notifications app size budget accommodate a Thread Title? | Requester | **Does not block.** FR-37 already states the policy — title preserved, preview shortened, truncation on boundaries valid for Vietnamese. The measurement becomes acceptance criteria inside the notification story rather than a gate before epics are cut. |
| 8 | What happens to a Thread when its Thread Root Message is deleted or expires? | Architecture — AD-5 | Author or moderator deletion tombstones the comment, so the Thread survives with its id, state, tags, featuring and replies. Message **expiry** hard-deletes, so a bounded background reaper removes the orphaned thread rows and invalidates their cache entries in the same pass. |
| 9 | Should a Thread Manager be able to state a reason when locking? | Requester | **Yes, in v1.** Specified as FR-43. Locking only — a Closed Thread is advisory and undone by anyone posting, so there is nothing to explain; a Locked Thread refuses writes and the participant who hits that refusal has no other way to learn why. |
| 10 | Should bots be able to change Thread State? | Requester | **No, and now by decision rather than omission.** Bots create Threads and post into them as they do today. Cheap to add later; what is missing is a rule for which bots would qualify, and nobody has asked for one. |

## 14. Traceability — Issue #22 to Requirements

| # | Issue item | Requirements | Note |
|---|---|---|---|
| 1 | Thread classification: Ongoing / Closed / Locked | FR-1, FR-2, FR-4, FR-6, FR-7, FR-8, FR-43 | Semantics of Closed vs Locked defined in §4.1; naming risk in FR-8's note. FR-43 (free-text lock reason) was open question 9 and is now v1 |
| 2 | Locked blocks posting; reopen via Ongoing | FR-5, FR-6, FR-7 | Nine entry points enumerated in FR-5 |
| 3 | Only thread opener or room moderator may modify | FR-9, FR-10, FR-11 | Mirrors existing renaming authority. "Modify" is read as state, featuring, tags and renaming; root-message deletion is open question 8 |
| 4 | Dedicated thread page level with Shared items; entry points elsewhere | FR-12 … FR-17, FR-38 … FR-42 | Nested list removed. "Peer" and the choice of entry points are both assumption-tagged |
| 5 | Pin / unpin threads | FR-21, FR-22 | Called **Featured** to avoid colliding with Talk's shipped pinned messages |
| 6 | Tags / labels for threads | FR-23 … FR-26 | Called **tags** to match Talk's existing vocabulary; free text with Conversation-scoped colour |
| 7 | Search to navigate to a thread | FR-18, FR-19, FR-20 | Title search only; message search already exists and the handoff is always available |
| 8 | Push notification click opens the thread | FR-35, FR-36 | **Not delivered by this epic.** The symptom originates in the Android client's push fallback, not the server. This epic makes the payload self-sufficient; the mobile epic delivers the fix — §9.1's honesty note and §11 |
| 9 | Unread message count in the thread list | FR-27 … FR-32 | Over-delivers: the issue asks for a count, this specifies per-Thread independence, which is what forced FR-30's API change |
| 10 | Thread title in thread-related notifications | FR-33, FR-34, FR-37 | All nine in-thread notification subjects, plus lifecycle notifications the issue did not ask for but "dễ nhận biết" implies, now carrying FR-43's lock reason |

## 15. Companion Documents

- `addendum.md` — implementation-level material for the architecture workflow: existing thread machinery and where it is, the notification behaviour in detail, the unread-count split, schema and migration considerations, cache invalidation points, the nine write paths Locked must cover, rejected alternatives, two unrelated upstream defects, and a cited comparison of how Discord, Slack, Teams and Zulip model thread lifecycle, tags and unread state (§10).
- `.memlog.md` — decision and audit trail for this PRD run, including two reversals and the corrected factual errors.
- `../../../specs/spec-clavis-spreed/SPEC.md` — the distilled kernel contract downstream BMad skills consume, with this PRD carried as an adopted companion. Its `traceability.md` holds the capability-to-requirement-to-architecture-decision join.
- `review-rubric.md`, `review-adversarial.md`, `review-code-claims.md`, `reconcile-issue-22.md` — the four-reviewer pass that produced this revision.
- `prd.superseded-r1.md` — the first revision, kept so the corrections above can be checked against what they replaced.

## 16. Assumptions Index

Every `[ASSUMPTION]` in the document, surfaced for confirmation. Items struck through were put to their owner and resolved; they are kept so a reader can see the resolution rather than an absence.

1. **§2** — Personas in §2.1 and §2.3 are inferred from the issue text and repository context. No customer interviews, tickets or usage data were supplied. Highest-leverage item in this list: the journeys drive the whole document.
2. **§2.2** — Guests are excluded from all thread management.
3. **§4.1** — State changes warrant system messages, following the pattern established for creation and renaming.
4. **FR-3** — Reopening a Closed Thread by posting produces no system message.
5. **FR-5** — Locked freezes existing content: editing and deleting messages already in the Thread are refused.
6. ~~**FR-5** — Reactions count as writes and are refused.~~ **Confirmed by the requester (open question 2). No longer an assumption.**
7. **FR-12** — "Peer of Shared items" means a sibling of the existing sidebar tabs. The current threads list replaces the whole tab set instead, so this is a change in kind.
8. **FR-14** — The Directory's state filter resets to Ongoing between visits rather than persisting.
9. **FR-18** — Title search must be diacritic-insensitive for Vietnamese.
10. **FR-22** — Featured Threads are bounded at five per Conversation.
11. **FR-23** — Five Thread Tags per Thread, matching Discord's forum-tag cap.
12. **FR-23** — Twenty distinct Thread Tags per Conversation, matching Discord's cap and the value Talk's own `ConversationTagService` already uses.
13. **§4.6** — Near-duplicate tags are mitigated by input suggestion, not by validation.
14. **FR-24** — Tag identity is case-insensitive within a Conversation.
15. **FR-25** — A tag colour change propagates to other participants live; a reload-to-see-it fallback is acceptable if it proves costly.
16. **§4.7** — Thread Read Markers are materialised lazily, only for Threads a participant reads, with fallback to the Conversation marker where absent. Open question 1.
17. **FR-27** — A participant's own messages never count as unread.
18. **FR-35** — Backward compatibility of the composed notification identifier is mandatory, because shipped clients parse it by position.
19. ~~**FR-35** — Routing information is not content, so identifiers may travel in a sensitive Conversation's payload where the Thread Title may not.~~ **Confirmed by the requester (open question 3). No longer an assumption.**
20. **FR-37** — When space is short, the Thread Title is preserved and the message preview is shortened.
21. **FR-40** — The three Directory entry points are the PRD's choice; the issue said "in other places" without naming them, and the Conversation list is deliberately excluded.
22. ~~**§10** — A consenting reference deployment exists and the requesting customer will answer SM-4.~~ **Refuted (open question 4): neither exists.** §10 was rewritten as acceptance criteria, which introduced the assumption below in its place.
23. **§10** — A seeded fixture at the §5 scale target is representative enough to accept against. It reproduces the mechanism, not the social behaviour of a real room, so AC-1 and AC-3 demonstrate that the feature works rather than that people will use it. The strongest remaining unconfirmed item now that the personas in item 1 are the only larger one.
