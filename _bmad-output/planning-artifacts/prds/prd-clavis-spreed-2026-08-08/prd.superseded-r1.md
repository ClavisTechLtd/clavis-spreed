---
title: Clavis Talk — Thread Management and Navigation
status: draft
created: 2026-08-08
updated: 2026-08-08
---

# PRD: Clavis Talk — Thread Management and Navigation

*Working title — confirm.*

## 0. Document Purpose

This PRD is for the Clavis engineering team building thread management into Clavis Talk, and for the downstream BMad workflows that turn it into architecture and stories. It originates from GitHub issue `ClavisTechLtd/clavis-deploy#22`, which lists ten gaps in the thread feature; every one of those ten is traced to a requirement here, and §14 maps them explicitly so nothing is silently dropped.

Vocabulary is fixed by §3 Glossary — downstream documents must use those terms verbatim. Features are grouped in §4 with functional requirements nested and numbered globally (FR-1 … FR-39) so epics and stories can reference them even if features get reorganised. Where a decision was inferred rather than confirmed with the requester, it carries an inline `[ASSUMPTION]` tag and is indexed in §16.

Clavis Talk is a fork of Nextcloud Talk (`clavis-spreed`, currently tracking upstream `stable34` / Talk 24.0.3). Threads already exist upstream: creation, replies, renaming, per-thread notification levels, a recent-threads list and a followed-threads list are all shipped. This PRD does not re-specify those. It specifies what upstream does not have and what the issue asks for. The distinction matters for effort: roughly a third of the ten items are extensions of working machinery, and one is a server-side defect rather than a missing feature.

Implementation-level material — schema shapes, migration ordering, cache invalidation points, exact call sites, alternatives considered — lives in `addendum.md` beside this file, for the architecture workflow. This document stays at the level of capability and behaviour.

## 1. Vision

A Clavis Talk room used seriously for work accumulates threads the way a shared inbox accumulates mail. That is a sign of health, not a problem — until there is no way to tell a live discussion from one that ended three weeks ago. Today every thread a room has ever produced sits in one undifferentiated list, reachable only by opening the right-hand sidebar and drilling into "Shared items", sorted by nothing but recency. A room with two hundred threads is a room where nobody uses threads, because finding the one you want costs more than starting a new one. The feature defeats itself at exactly the scale it was built for.

This work gives threads a lifecycle and a front door. A thread can be marked done, so the list shows what is actually live. A thread can be locked, so a decision that has been made stops being re-litigated. A thread can be pinned, so the room's standing references stay at the top. A thread can be labelled, so a room that runs several kinds of work can separate them. And threads get a real destination in the interface — their own place at the same level as Shared items, searchable by title, showing where you have unread replies — instead of being a sub-page of a sub-page.

The payoff is that threads become the default way a busy room organises itself rather than a feature people abandon. Concretely: a moderator can leave a room tidy in a minute instead of not bothering; a member returning after leave can find the three threads that moved without reading everything; and someone who gets a notification about a thread reply lands in that thread, with the right message in view, instead of being dropped into the room's main chat to hunt for what changed.

## 2. Target User

Clavis Talk is sold to organisations who self-host it as part of a Clavis-branded Nextcloud deployment. The users below are inside those customer organisations, not Clavis staff. `[ASSUMPTION: personas are inferred from the issue text ("groups with very many threads"), the existence of per-customer deployments in the Clavis repo set, and the issue author's framing. No customer interviews or support tickets were supplied. Confirm or replace before the epics workflow consumes §2.3.]`

### 2.1 Jobs To Be Done

- **Keep a busy room legible.** When I open a room I want to see what is live right now, not an archaeological record. Functional, and the primary driver of the issue.
- **Signal that something is settled.** When a discussion reaches a decision I want that visible to everyone, so it stops being reopened by someone who did not read to the end. Social as much as functional.
- **Stop a conversation that has gone wrong.** Off-topic drift, a heated exchange, a thread that duplicates another one — I need to end it, not just ask people to stop. Functional with an authority dimension.
- **Find a thread I remember by name.** I know there was a thread about the invoice format. I do not know when, and I am not going to scroll. Functional.
- **Know where I owe a reply.** Across a room with many threads, I want to see which ones moved since I last looked. Functional, and the difference between threads being useful and being noise.
- **Keep the room's standing references reachable.** Some threads are permanent — the on-call rota, the release checklist. They should not sink. Functional.
- **Separate kinds of work inside one room.** This room runs customer escalations and internal planning. I want to look at one without the other. Functional, organisational.
- **Get pulled back to the right place.** When something notifies me, I want to arrive where it happened. Contextual — and today the single most jarring failure, because notifications about thread replies land in the room's main chat.

### 2.2 Non-Users (v1)

- **Federated participants.** Users joining a room from another Nextcloud server. Upstream Talk does not yet support threads over federation at all — `ChatController` carries explicit `FIXME support threads in federation` markers on the two message-fetch paths. Thread state, pinning, labels and unread counts are therefore not specified across federation boundaries in v1, and the interface must degrade rather than mislead.
- **Mobile app users, for notification behaviour specifically.** The Clavis Talk Android and iOS clients are not modified by this PRD. They will receive the corrected notification payload (FR-31 … FR-35) and can adopt it in a follow-up epic; until they do, the improved deep-linking is visible on web and desktop only. Everything else in this PRD is server plus web, so mobile users do get lifecycle, labels and pinning as soon as the server ships — they just cannot act on them from the app until the app is updated.
- **Guest and public-link participants, as thread managers.** Guests may read and, where the room permits, reply. They do not get lifecycle, pinning or labelling authority. `[ASSUMPTION: guests are excluded from all thread management. The issue says "people who opened the thread or room moderators", and a guest session is too weak an identity to hold that authority across reconnects.]`
- **Bots, as thread managers.** Bots may create threads and post into them, as they already do. They do not change thread state. Deferred, not refused — see §13.

### 2.3 Key User Journeys

- **UJ-1. Hạnh closes out the week in a room with 180 threads.**
  Hạnh moderates the room her team uses for customer escalations; it has run for a year and has more threads than anyone has counted. Already signed in on the web client. She opens the room and clicks **Threads** in the top bar — its own destination now, not a link inside Shared items. The list opens filtered to Ongoing, newest first, and eleven threads show an unread badge. She works down it: four are finished, and for each she opens the row's menu and picks **Close**. They leave the list as she goes. One is a duplicate of another thread that is still live, so she **Locks** it after posting a line pointing at the other. The count of Ongoing threads drops from 27 to 22. **Climax:** the list now shows only what is actually live, and she can see it in one screen without scrolling. **Resolution:** she leaves the room; Monday's opener sees a list that means something. **Edge case:** one of the threads she closed gets a reply on Saturday from a colleague who had more to say — the thread returns to Ongoing on its own and reappears in the list, so nothing is lost by closing something prematurely.

- **UJ-2. Dũng ends his own thread when the decision lands.**
  Dũng opened a thread three days ago to settle which date format the export uses. Six people replied; the answer is ISO-8601. He is a regular member, not a moderator. He opens the thread, uses the thread header menu, and picks **Close**. He is allowed to, because he started it. The thread header now shows a Closed marker, and a system message records that he closed it, so nobody has to ask whether the decision is final. **Climax:** the state is visible to everyone who opens the thread, without reading forty replies to find out it ended. **Resolution:** the thread drops out of the room's default Ongoing list but stays fully readable and, if someone genuinely has more, postable.

- **UJ-3. Linh comes back from two weeks' leave and finds what moved.**
  Linh returns to a room she has been away from. She opens **Threads** and sees, at the top, three pinned threads that are the room's standing references, then the Ongoing threads with unread badges showing how many replies she has not read. She types "invoice" into the thread search box; two threads match by title, one of them Closed. She opens the Ongoing one and lands on her first unread reply rather than at the top of a long thread. **Climax:** eleven minutes in, she knows what she missed and where she owes an answer. **Resolution:** the badges she has cleared are gone; the ones she has not are still there tomorrow. **Edge case:** her search term matches nothing, and the empty state offers to search message bodies instead of titles — handing her to the existing message search rather than dead-ending.

- **UJ-4. Minh gets a notification and arrives where it happened.**
  Minh has the desktop client open in the background. Someone replies in a thread he follows. The notification he receives names the thread — "Reply in *Invoice format for Giza*" — rather than only the room. He clicks it and lands in that thread, scrolled to the new message. **Climax:** no hunting; the click puts him at the reply. **Resolution:** he answers in two lines and closes the window. **Edge case:** the thread was Locked between the notification being sent and his click — he arrives in the thread, sees the Locked state and the reason, and the composer is disabled with an explanation rather than silently failing when he tries to send.

- **UJ-5. Hạnh separates two kinds of work in one room.**
  The escalation room also carries internal planning, and the mix makes both harder to read. Hạnh labels the customer threads `escalation` in red and the planning ones `planning` in blue, choosing the colour the first time she uses each label. Afterwards, anyone in the room typing `escalation` gets the same red — the colour belongs to the label within the room, not to whoever typed it. She then filters the thread list to `escalation` and gets the eight she cares about. **Climax:** one room reads as two, without splitting it into two rooms and losing the shared membership. **Resolution:** other members see the same labels and can filter the same way. **Edge case:** she mistypes `escalaton`, sees it is a new label rather than an existing one because the input distinguishes them, and corrects it before saving.

## 3. Glossary

Downstream workflows and readers must use these terms exactly. Introducing a synonym anywhere is a discipline violation.

- **Thread** — A branch of conversation inside one Conversation, rooted at a single Thread Root Message, carrying a Thread Title, a Thread State, zero or more Thread Labels, and an optional pin. Belongs to exactly one Conversation. Already exists upstream; this PRD extends it.
- **Thread Root Message** — The chat message a Thread grew from. Its message identifier *is* the Thread's identifier; a Thread cannot exist without it. One-to-one with Thread.
- **Thread Title** — The human-readable name of a Thread, set at creation and editable afterwards. Already exists upstream.
- **Thread State** — Exactly one of **Ongoing**, **Closed**, or **Locked**, defined in §4.1. Every Thread has one; the default is Ongoing. New in this PRD.
- **Ongoing** — The Thread State of a live Thread. Anyone who may post in the Conversation may post in it. Appears in the Thread Directory by default.
- **Closed** — The Thread State of a Thread whose discussion has concluded. Advisory only: posting is still permitted, and posting returns the Thread to Ongoing. Hidden from the Thread Directory's default filter.
- **Locked** — The Thread State of a Thread that has been shut. All writes are refused. Only a Thread Manager may return it to Ongoing.
- **Thread Manager** — For a given Thread, the author of its Thread Root Message, or any moderator of the Conversation. The only actors who may change Thread State, pin, or set Thread Labels. Mirrors the authority rule upstream already applies to renaming.
- **Thread Directory** — The interface surface listing the Threads of one Conversation, with filtering, sorting, search and unread indication. A destination at the same level as Shared items, not nested inside it. New in this PRD; replaces the current nested list.
- **Followed Thread List** — The existing cross-Conversation list of Threads the current user is subscribed to, shown in the left sidebar. Already exists upstream; this PRD extends what each row displays.
- **Thread Label** — A free-text tag applied to a Thread, carrying a colour. The colour is bound to the label text within one Conversation, so identical text renders identically for every member of that Conversation. A Thread may carry several; a label may be on many Threads. New in this PRD.
- **Pinned Thread** — A Thread a Thread Manager has raised to the top of the Thread Directory, above all unpinned Threads regardless of state or activity. New in this PRD.
- **Thread Unread Count** — The number of messages in one Thread the current user has not read. Independent per Thread: clearing it in one Thread does not clear it in another, and reading the Conversation's main chat does not clear it at all. New in this PRD.
- **Thread Read Marker** — The record of how far one participant has read in one Thread. Exists only for Threads that participant has read; where absent, their Conversation read marker stands in. New in this PRD.
- **Conversation** — What Nextcloud Talk calls a room. Contains Threads, has moderators, has participants. Pre-existing; named here only to fix the word.
- **Thread Subscription** — The existing relationship recording that a user follows a Thread and at what notification level. Created implicitly on reply. Already exists upstream; unchanged by this PRD except where §4.7 reads from it.

## 4. Features

### 4.1 Thread Lifecycle

**Description:** Every Thread has exactly one Thread State. New Threads are Ongoing. A Thread Manager moves a Thread between states, and the interface shows the current state wherever a Thread appears — in the Thread Directory, in the thread header, and on the Thread Root Message in the main chat.

The two non-default states do different jobs and must not be conflated. **Closed** is a statement about the discussion: it is finished. It is advisory, and deliberately cheap to get wrong — anybody who may post in the Conversation may still post in a Closed Thread, and doing so returns it to Ongoing automatically. That property is what makes closing a habit rather than a decision: a moderator tidying a room does not have to be sure, because a premature close costs one message to undo and undoes itself. **Locked** is a statement about permission: this Thread is shut. Writes are refused, and only a Thread Manager can reopen it. Realises UJ-1, UJ-2.

State changes are recorded as system messages in the Thread, the way renaming already is, so the Thread carries its own history and a reader can see who closed or locked it and when. `[ASSUMPTION: state changes warrant system messages. Upstream emits them for thread creation and renaming, so this follows the established pattern, but it does add noise to short threads.]`

**Functional Requirements:**

#### FR-1: Thread State exists and defaults to Ongoing

Every Thread has a Thread State of Ongoing, Closed, or Locked. Realises UJ-1.

**Consequences (testable):**
- A newly created Thread reports state Ongoing.
- Every Thread that existed before this feature shipped reports state Ongoing after migration, with no interaction required.
- The Thread representation returned by the API includes the state on every endpoint that returns a Thread.
- A request to set a state outside the three defined values is refused with a validation error naming the field.

#### FR-2: A Thread Manager can close a Thread

A Thread Manager can set an Ongoing Thread to Closed. Realises UJ-1, UJ-2.

**Consequences (testable):**
- The Thread reports state Closed immediately after the request succeeds.
- A system message is added to the Thread recording who closed it.
- A participant who is neither the Thread Root Message author nor a Conversation moderator is refused with a permission error.
- Closing a Thread that is already Closed succeeds without duplicating the system message.

#### FR-3: Posting into a Closed Thread returns it to Ongoing

Any participant who may post in the Conversation can post into a Closed Thread, and doing so sets the Thread back to Ongoing. Realises UJ-1 edge case.

**Consequences (testable):**
- A non-manager participant posting a reply into a Closed Thread succeeds.
- The Thread reports state Ongoing after that reply.
- The Thread reappears in the Thread Directory's default filter without the user refreshing.
- Reopening by reply produces no system message about the state change — the reply itself is the record. `[ASSUMPTION: silent reopen. A system message on every reopen would be noise given that reopening is meant to be frictionless.]`
- A participant with permission to post but no Thread Manager authority can reopen a Closed Thread this way, and cannot reopen a Locked one. This asymmetry matches Discord's documented rule and is deliberate, not an oversight — see addendum §10.

#### FR-4: A Thread Manager can lock a Thread

A Thread Manager can set a Thread to Locked from either Ongoing or Closed. Realises UJ-1.

**Consequences (testable):**
- The Thread reports state Locked immediately after the request succeeds.
- A system message is added recording who locked it.
- A non-manager is refused with a permission error.
- Locking is reachable from Closed without passing through Ongoing.

#### FR-5: A Locked Thread refuses every write

While a Thread is Locked, all attempts to add content to it are refused, from every entry point. Realises UJ-4 edge case.

**Consequences (testable):**
- Posting a message into a Locked Thread is refused with an error identifying the Thread as locked, distinguishable by the client from a generic permission failure.
- Sharing a file, posting a rich object, and creating a poll into a Locked Thread are each refused the same way.
- A message scheduled into a Thread before it was Locked does not post when its time arrives; it is marked failed with a reason the sender can see.
- A bot posting into a Locked Thread is refused.
- Editing or deleting a message that already exists in a Locked Thread is refused. `[ASSUMPTION: Locked freezes existing content too, not just new content. "Locked" reading as "this cannot change" is the less surprising interpretation, and it closes the hole where a participant edits their message to say something new.]`
- Reacting to a message in a Locked Thread is refused. `[ASSUMPTION: reactions count as writes. Weaker than the others — reactions are low-harm and blocking them may read as petty. Flagged in §13.]`
- The web composer is disabled with a visible explanation when the open Thread is Locked, so the refusal is not discovered by failing to send.

#### FR-6: A Thread Manager can reopen a Locked Thread

A Thread Manager can set a Locked Thread back to Ongoing. Realises UJ-1.

**Consequences (testable):**
- The Thread reports state Ongoing after the request succeeds and accepts writes again.
- A system message is added recording who reopened it.
- A non-manager is refused, including a non-manager who posts a message — a Locked Thread does not reopen by reply the way a Closed one does.

#### FR-7: Thread State is visible everywhere a Thread appears

The current Thread State is shown on every surface that represents a Thread. Realises UJ-2.

**Consequences (testable):**
- The Thread Directory row shows state for Closed and Locked Threads; Ongoing is shown as the absence of a marker rather than a badge, so the common case stays quiet.
- The thread header shows state while the Thread is open.
- The Thread Root Message in the main chat shows state alongside the existing thread title and reply count.
- A row in the Followed Thread List shows state.
- The Closed indicator conveys that the Thread can still be posted in and that posting reopens it, rather than reading as a shut Thread. This is the mitigation for "Closed" meaning the opposite in issue trackers — see the note below.

**Notes:** Comparable-product research (addendum §10) found that **Closed** carries three incompatible meanings: Discord's "Close Thread" is exactly this PRD's Closed — hidden, revived by posting — while GitHub, Jira and Viva Engage users read "closed" as *finished* or *replies blocked*. The requester has confirmed **Ongoing / Closed / Locked** as the interface wording, matching the Discord reference the issue names. The cost is that a participant arriving from an issue tracker will assume a Closed Thread cannot be posted in, so FR-7 carries a consequence requiring the indicator to say otherwise. **Locked** and **label** were confirmed safe by the same research; **tag** was ruled out because Microsoft Teams tags are @-mentionable groups of people, so the interface must never use "tag" for a Thread Label.

### 4.2 Thread Management Authority

**Description:** Thread State, pinning and labelling are restricted to Thread Managers: the author of the Thread Root Message, or any moderator of the Conversation. This matches the rule upstream already applies to renaming a Thread, so the model is consistent rather than novel, and a member who starts a Thread retains authority over their own without needing moderator rights. Realises UJ-2.

**Functional Requirements:**

#### FR-8: Thread management is restricted to Thread Managers

Only a Thread Manager may change Thread State, pin or unpin a Thread, or add or remove Thread Labels. Realises UJ-2.

**Consequences (testable):**
- The Thread Root Message author succeeds at each of those operations on their own Thread without being a moderator.
- A Conversation moderator succeeds on any Thread in that Conversation.
- A participant who is neither is refused, with the same error shape for all three operation families.
- A guest session is refused regardless of who created the Thread.
- Authority is evaluated per request; a member demoted from moderator loses it immediately on the next request rather than at session end.

#### FR-9: The interface offers only what the actor may do

Management controls appear only for actors who may use them. Realises UJ-2.

**Consequences (testable):**
- A non-manager viewing a Thread sees no state, pin or label controls — not disabled ones.
- A member sees controls on Threads they started and not on others', within the same list.
- A moderator sees controls on every Thread in the Conversation.

#### FR-10: Existing renaming authority is unchanged

Renaming a Thread continues to follow the same Thread Manager rule it follows today.

**Consequences (testable):**
- Renaming behaviour and permissions are identical before and after this work, verified by the existing integration coverage.

### 4.3 Thread Directory

**Description:** The Thread Directory is where a Conversation's Threads live. Today the equivalent list is reachable only by opening the right sidebar, going to Shared items, and clicking through a three-item preview — the issue's fourth item is precisely that this is the wrong place. The Directory becomes a destination at the same level as Shared items, with its own entry point in the Conversation's top bar and, on narrow screens, in the same navigation the other Conversation surfaces use.

It lists Threads with Pinned Threads first, then the rest by most recent activity. It filters by Thread State — defaulting to Ongoing, because the whole point is to show what is live — and by Thread Label. It searches by Thread Title. Each row carries the title, the state, the labels, the reply count, the last activity, and the Thread Unread Count. The three-item preview in Shared items is removed rather than left as a second path to the same thing. Realises UJ-1, UJ-3, UJ-5.

**Functional Requirements:**

#### FR-11: The Thread Directory is a first-class destination

A participant can reach the Thread Directory for the current Conversation directly, without passing through Shared items. Realises UJ-1, UJ-3.

**Consequences (testable):**
- A control in the Conversation's top bar opens the Thread Directory in one click from anywhere in the Conversation.
- The Directory is reachable on narrow and mobile-width viewports through the same navigation as the Conversation's other surfaces.
- The thread list no longer appears inside Shared items, and Shared items contains no thread preview.
- Opening the Directory does not change which Thread, if any, the user has open.

#### FR-12: The Directory lists Threads with pinned first, then by activity

The Directory orders Pinned Threads above all others, and orders within each group by most recent activity. Realises UJ-3.

**Consequences (testable):**
- Every Pinned Thread sorts above every unpinned Thread, whatever their activity or state.
- Within each group, the Thread with the most recent activity is first.
- A reply arriving in a listed Thread moves it to the top of its group without the user refreshing.

#### FR-13: The Directory filters by Thread State

A participant can filter the Directory by Thread State, and the default filter shows only Ongoing Threads. Realises UJ-1.

**Consequences (testable):**
- Opening the Directory fresh shows Ongoing Threads and no others.
- Closed and Locked Threads are reachable by changing the filter, and the filter makes clear that Threads are being hidden rather than absent.
- The chosen filter persists while the user stays in the Conversation and resets to Ongoing when they return to it later. `[ASSUMPTION: filter resets rather than persisting across visits. A sticky filter that hides live threads is a support call.]`

#### FR-14: The Directory filters by Thread Label

A participant can filter the Directory to Threads carrying a chosen Thread Label. Realises UJ-5.

**Consequences (testable):**
- Selecting a label narrows the list to Threads carrying it.
- Label and state filters combine.
- Only labels in use in this Conversation are offered.
- Clearing the label filter restores the previous list without reloading the Directory.

#### FR-15: Each Directory row carries enough to decide without opening

Each row shows Thread Title, Thread State, Thread Labels, reply count, last activity, and Thread Unread Count. Realises UJ-3.

**Consequences (testable):**
- Every listed field is present for a Thread that has a value for it.
- A Thread with no unread messages shows no unread indicator at all.
- A long Thread Title truncates without pushing the state, labels or unread indicator out of view.
- A Thread with many labels shows a bounded number and indicates that more exist.

#### FR-16: The Directory loads incrementally

The Directory loads a bounded first page and fetches more as the participant goes down the list. Realises UJ-1.

**Consequences (testable):**
- The first page returns without the participant waiting on the full Thread count for the Conversation.
- Reaching the end of the loaded list fetches the next page and appends it in order.
- A Conversation whose Threads all fit in one page shows no pagination affordance.
- Filtering and searching apply across all Threads in the Conversation, not only those already loaded.

### 4.4 Thread Search

**Description:** Searching the Thread Directory finds Threads by Thread Title. It answers "I remember there was a thread about X" — a different question from "find the message where someone said X", which Talk's existing message search already answers. The two are kept distinct and cross-referenced: when a title search finds nothing, the empty state offers the message search rather than dead-ending. Realises UJ-3.

**Functional Requirements:**

#### FR-17: A participant can search Threads by title

A participant can search the Threads of the current Conversation by Thread Title. Realises UJ-3.

**Consequences (testable):**
- A search matching part of a title returns that Thread.
- Matching ignores case and matches on any part of the title, not only its start.
- Matching is diacritic-insensitive for Vietnamese input, so `dinh dang` finds a Thread titled `Định dạng`. `[ASSUMPTION: diacritic-insensitive matching is required. The customer base is Vietnamese-speaking and typing without diacritics is normal; an exact-match search would fail most real queries.]`
- Search covers all Threads in the Conversation regardless of Thread State, and results show state so a match in a Locked Thread is not mistaken for a live one.
- Results appear as the participant types, without a submit action.

#### FR-18: Empty results hand off to message search

When a title search returns nothing, the participant is offered the existing message search for the same term. Realises UJ-3 edge case.

**Consequences (testable):**
- An empty result shows the term searched and a control that runs the message search with it.
- Taking that control opens the existing message search surface with the term applied, and does not lose the participant's place in the Conversation.

#### FR-19: The Followed Thread List is searchable by title

A participant can search the cross-Conversation Followed Thread List by Thread Title.

**Consequences (testable):**
- A search term narrows the followed list to Threads whose titles match, across every Conversation.
- Each result identifies which Conversation the Thread belongs to.
- Matching follows the same rules as FR-17.

### 4.5 Pinned Threads

**Description:** A Thread Manager can pin a Thread so it stays at the top of the Thread Directory regardless of activity or state. Pinning is for a Conversation's standing references — the rota, the checklist, the escalation procedure — which by their nature stop generating replies and would otherwise sink out of sight. Pinning is per Conversation and visible to everyone in it, not a private bookmark. Realises UJ-3.

**Functional Requirements:**

#### FR-20: A Thread Manager can pin and unpin a Thread

A Thread Manager can pin a Thread and can unpin it. Realises UJ-3.

**Consequences (testable):**
- A pinned Thread reports as pinned and appears above unpinned Threads for every participant in the Conversation, not only the actor.
- Unpinning returns it to activity ordering.
- A non-manager is refused.
- Pinning is independent of Thread State: a Closed or Locked Thread can be pinned and stays pinned.

#### FR-21: The number of Pinned Threads per Conversation is bounded

A Conversation may have at most a fixed small number of Pinned Threads. Realises UJ-3.

**Consequences (testable):**
- Pinning beyond the limit is refused with an error naming the limit, not a generic failure.
- The limit is stated in the interface before the participant hits it.
- The limit is a server-side constant, identical for every Conversation. `[ASSUMPTION: a hard bound around ten. Unbounded pinning recreates the problem the Directory exists to solve — a long list sorted by nothing.]`

### 4.6 Thread Labels

**Description:** A Thread Label is free text with a colour, applied by a Thread Manager. Free text keeps it frictionless: no moderator has to define a taxonomy before anyone can use one. The colour is what stops it becoming the mess free text usually becomes — colour is bound to the label text within the Conversation, so the first person to use `escalation` in a room picks its colour and everyone in that room sees `escalation` in that colour thereafter. Labels are shared, not personal, and they are the axis the Directory filters on. Realises UJ-5.

The recognised failure mode is near-duplicate labels — `escalation` and `escalations` and `Escalation` living side by side. The mitigation is in input, not enforcement: when a participant types a label the interface offers the Conversation's existing labels first and makes visually clear when what they have typed is new. `[ASSUMPTION: suggestion, not validation. Blocking similar-looking labels would be wrong as often as right, and the requester chose free text over a moderated set knowing the tradeoff.]`

**Functional Requirements:**

#### FR-22: A Thread Manager can label a Thread

A Thread Manager can add a Thread Label to a Thread and remove one from it. Realises UJ-5.

**Consequences (testable):**
- An added label is visible to every participant in the Conversation.
- Removing a label from one Thread does not affect other Threads carrying the same label.
- A non-manager is refused.
- Labels are visible to every participant regardless of authority — only editing is restricted.
- A Thread may carry up to five Thread Labels; a sixth is refused with an error naming the limit. `[ASSUMPTION: five per Thread, taken from Discord's forum-tag cap rather than invented. Above that a row's labels stop being scannable, which is the point of them.]`
- A Conversation may accumulate up to twenty distinct Thread Labels; creating a twenty-first is refused with an error that says so and points at the existing labels. `[ASSUMPTION: twenty per Conversation, also Discord's cap. It is the bound SM-C2 watches, made enforceable rather than merely observed.]`

#### FR-23: A label's colour is fixed within a Conversation

Within one Conversation, the same label text always renders in the same colour. Realises UJ-5.

**Consequences (testable):**
- The first use of a label text in a Conversation sets its colour; the actor chooses from a defined palette.
- Later uses of the same text in that Conversation take the established colour, and the participant applying it is not asked for one.
- Label text is compared case-insensitively for colour binding, so `Escalation` and `escalation` are one label, not two. `[ASSUMPTION: case-insensitive identity. Two labels differing only in case are always an accident.]`
- The same label text in a different Conversation is independent and may have a different colour.
- Colours come from a fixed palette chosen for contrast in both light and dark themes; arbitrary colour input is not accepted.

#### FR-24: A Thread Manager can change a label's colour for the Conversation

A Thread Manager can change the colour bound to a label text in the Conversation. Realises UJ-5.

**Consequences (testable):**
- Changing the colour updates every Thread carrying that label in that Conversation.
- A non-manager is refused.
- The change is visible to other participants without them reloading. `[ASSUMPTION: live propagation. If it proves costly, a reload-to-see-it fallback is acceptable and should be raised in architecture rather than silently adopted.]`

#### FR-25: Label input surfaces existing labels before creating new ones

When applying a label, the participant is offered the Conversation's existing labels and shown clearly when their input would create a new one. Realises UJ-5 edge case.

**Consequences (testable):**
- Typing shows matching existing labels in the Conversation, with their colours.
- Input matching no existing label is presented as a new label, visually distinct from picking an existing one, before it is saved.
- Selecting an existing label applies it without asking for a colour.
- Leading and trailing whitespace is stripped, and empty or whitespace-only input is refused.

### 4.7 Thread Unread State

**Description:** A row in the Thread Directory shows how many messages in that Thread the participant has not read, and that state is **independent per Thread**. Reading one Thread clears that Thread and nothing else. This is the behaviour the issue asks for, and it is worth stating why it needs saying: the obvious cheap implementation derives unread from the single Conversation-level read marker every participant already has, and that marker cannot express "read thread A, not thread B". Deriving from it would mean reading one Thread clears the badges on all of them, which is the same uselessness the feature exists to fix. So each Thread carries its own Thread Read Marker for each participant who has read it.

The symmetric trap is the main chat. If reading the Conversation's main chat advances something that thread unread derives from, then one visit to the main chat wipes every Thread badge — the same defect, arriving from the other direction. So reading the main chat must not clear Thread unread either. That splits Conversation-level unread into two things that were previously one: how many messages are unread in the main chat, and whether any Thread has unread messages. FR-30 covers the second, and the three unused columns upstream left on the participant table are exactly the shape it needs.

Storage is the reason upstream removed per-Thread markers, and it is addressed by materialising a Thread Read Marker only for Threads a participant has actually read. A Thread a participant has never opened has no row and falls back to their Conversation read marker, which is what keeps a new member from walking into a room with five hundred old Threads and five hundred unread badges. Rows therefore track engagement, not the product of participants and Threads. `[ASSUMPTION: lazy materialisation with fallback to the Conversation marker. It is the mechanism that makes per-Thread markers affordable, and the joining-member case above is the behaviour it must produce; architecture may reach the same behaviour another way.]` Realises UJ-3.

**Functional Requirements:**

#### FR-26: Each Thread reports a Thread Unread Count for the current participant

Every Thread returned to a participant carries the number of messages in it that participant has not read. Realises UJ-3.

**Consequences (testable):**
- A Thread the participant has fully read reports zero.
- A Thread with three messages the participant has not seen reports three.
- The count excludes the participant's own messages. `[ASSUMPTION: own messages never count as unread, matching Conversation-level behaviour.]`
- The count excludes system messages, so a rename or a state change does not read as an unread reply.
- Two participants who have read different amounts of the same Thread get different counts for it.
- A participant who has never opened a Thread gets a count derived from their Conversation read marker, so a Thread whose activity predates their joining the Conversation reports zero rather than its full length.

#### FR-27: Thread unread state is independent per Thread

Clearing unread state in one Thread does not clear it in any other Thread, and reading the Conversation's main chat does not clear it in any Thread. Realises UJ-3.

**Consequences (testable):**
- With unread messages in Threads A and B, reading A leaves B's count unchanged.
- With unread messages in Thread A, reading the Conversation's main chat to its end leaves A's count unchanged.
- With unread messages in Thread A, reading A does not change the Conversation's main-chat unread count.
- A Thread's count returns to zero only when that participant has read that Thread to its end.
- A participant who leaves and rejoins the Conversation does not inherit stale per-Thread read state from before they left.

#### FR-28: The Directory and the Followed Thread List show unread state

Thread Unread Count is displayed in both list surfaces. Realises UJ-3.

**Consequences (testable):**
- A Thread with unread messages shows a count in the Thread Directory.
- The same Thread shows a count in the Followed Thread List.
- A Thread with none shows no indicator.
- Counts update as messages arrive while the list is open, and clear as the participant reads, without a reload.
- A count above a display bound renders as a capped indicator rather than widening the row.
- A Thread containing an unread mention of the participant is distinguished from one containing only unread replies.

#### FR-29: Opening a Thread lands the participant on their first unread message and marks it read

Opening a Thread with unread messages positions the view at the first one, and reading it clears that Thread's unread state. Realises UJ-3.

**Consequences (testable):**
- A Thread with unread messages opens scrolled to the first unread message, with a visible boundary marking where unread begins.
- A Thread with none opens at its most recent message.
- The boundary remains visible while the participant reads and does not jump as new messages arrive.
- Reading to the end of the Thread returns its count to zero, and the Directory reflects that without a reload.
- Leaving a Thread part-read preserves the position: reopening it returns to the same first-unread message, not to the end.
- A participant can mark a Thread read without opening it, from the Directory. `[ASSUMPTION: a mark-as-read affordance in the list is expected. Without it, tidying a room means opening every Thread, which defeats the Directory.]`

#### FR-30: The Conversation reports whether it has unread Threads

A Conversation indicates whether it contains any Thread with unread messages for the current participant, separately from its main-chat unread count. Realises UJ-1.

**Consequences (testable):**
- The Conversation reports a thread-unread indication when at least one of its Threads has unread messages, and none when no Thread does.
- The indication distinguishes unread replies in Threads from unread mentions in Threads.
- A Conversation whose main chat is fully read but which has an unread Thread still reports the thread-unread indication.
- A Conversation with unread main-chat messages and no unread Threads reports no thread-unread indication.
- The Conversation list surfaces it in a way that does not duplicate or contradict the existing Conversation-level unread badge.

### 4.8 Thread-Aware Notifications

**Description:** Two separate problems, both in the notification the server produces. First, a notification about a thread reply does not say which Thread — the reader sees a room and a message and has to infer. Second, and this is a defect rather than a gap: the server already attaches the thread identifier to notifications, but the code that does so sits inside a branch skipped when the payload being built is a push payload. So web notifications carry the thread and push notifications do not, which is exactly why clicking a push notification lands in the main chat. Compounding it, the same code overwrites the message identifier with the thread identifier, so even where the thread is known the specific message is lost.

Fixing the server is in scope here. Adopting the fixed payload in the Android and iOS clients is a follow-up epic — see §11 — because the Clavis push proxy cannot help: it relays payloads it cannot read, encrypted for the device by the customer's own server, so nothing about this can be solved in the proxy. Realises UJ-4.

**Functional Requirements:**

#### FR-31: Thread notifications name the Thread

A notification generated by activity in a Thread identifies the Thread by its Thread Title. Realises UJ-4.

**Consequences (testable):**
- A reply notification includes the Thread Title in the text the recipient reads.
- A mention notification in a Thread includes it.
- A notification for activity outside any Thread is unchanged and gains no thread text.
- A Thread Title too long for the available space is truncated with an indication, and the truncation does not push out the message preview entirely.
- In a Conversation the recipient has marked sensitive, the Thread Title is withheld along with the other content already withheld.

#### FR-32: Push notifications carry the Thread identifier

The identifier of the Thread is present in push payloads as well as in-app notifications. Realises UJ-4.

**Consequences (testable):**
- A push payload generated for a thread reply contains the Thread identifier.
- A push payload for activity outside a Thread contains none.
- The corresponding in-app notification continues to carry it, unchanged.
- A payload for a sensitive Conversation carries the Thread identifier but no Thread Title, so a client can still route without leaking content. `[ASSUMPTION: routing information is not content. If the requester disagrees, sensitive Conversations lose thread routing entirely — flagged in §13.]`

#### FR-33: Notification payloads keep both Thread and message identifiers

A notification about a message in a Thread carries the Thread identifier and the message identifier. Realises UJ-4.

**Consequences (testable):**
- Both identifiers are present and independently readable in a thread-reply notification.
- Neither overwrites the other.
- A notification about a message outside a Thread carries the message identifier as it does today.
- Existing clients reading only the message identifier continue to work. `[ASSUMPTION: this must be backward compatible, because the shipped Android and iOS clients read the current shape and will not be updated in this epic.]`

#### FR-34: Following a notification lands the recipient in the Thread

Acting on a notification about a Thread opens that Thread with the relevant message in view. Realises UJ-4.

**Consequences (testable):**
- Following the notification on web opens the Conversation with the Thread open and the message in view.
- Following one about a message outside a Thread opens the Conversation's main chat as it does today.
- Following one about a Locked Thread opens the Thread and shows its state with the composer disabled, rather than failing.
- Following one for a Thread since deleted lands in the Conversation's main chat with an explanation, not an error page.

#### FR-35: Push notification text stays within its size budget

Adding the Thread Title does not push a push payload past what the transport accepts.

**Consequences (testable):**
- A notification for a Thread with a maximum-length title and a maximum-length message preview is delivered.
- When both cannot fit, the Thread Title is preserved and the message preview is shortened, because the title is what makes the notification actionable. `[ASSUMPTION: title wins over preview when space is short.]`
- Truncation is applied on character boundaries valid for Vietnamese text, never mid-character.

### 4.9 Thread Navigation Entry Points

**Description:** The issue's fourth item asks not only for a dedicated thread page but for thread entry points "in other places, so navigation makes sense". Today the only way in is the sidebar path. This adds the entry points that make the Directory reachable from where participants already are, and makes the existing ones consistent. Realises UJ-1, UJ-3.

**Functional Requirements:**

#### FR-36: The Conversation top bar opens the Thread Directory

A control in the Conversation's top bar opens the Thread Directory. Realises UJ-1.

**Consequences (testable):**
- The control is present for every Conversation whose server supports threads, and absent otherwise.
- It indicates when the Conversation has Threads with unread messages.
- It is reachable by keyboard and announced by screen readers with its purpose and unread state.

#### FR-37: A Thread's own header links to the Directory

While a Thread is open, the participant can reach the Thread Directory for its Conversation from the thread header. Realises UJ-3.

**Consequences (testable):**
- The thread header offers a route to the Directory alongside the existing route back to the main chat.
- Taking it leaves the Thread open behind the Directory, so dismissing the Directory returns the participant to where they were.

#### FR-38: The Thread Root Message links to the Directory

The Thread Root Message in the main chat offers a route to the Thread Directory as well as into the Thread. Realises UJ-1.

**Consequences (testable):**
- The Thread Root Message's existing route into the Thread is unchanged.
- A distinct route opens the Directory filtered to nothing, positioned on this Thread's row.

#### FR-39: Thread destinations are linkable and restorable

A Thread and the Thread Directory each have an address a participant can copy, share and return to. Realises UJ-4.

**Consequences (testable):**
- Copying the link to a message in a Thread produces a link that opens that Thread at that message — the behaviour that exists today, preserved.
- The Thread Directory has an address that reopens it on the same Conversation.
- Reloading the page with a Thread open restores that Thread, not the main chat.
- Browser back and forward move between the main chat, a Thread, and the Directory in the order visited.

## 5. Cross-Cutting NFRs

- **Scale is the point.** Every list surface must stay usable in a Conversation with at least a thousand Threads and two hundred participants. That is the condition the issue describes, so it is the condition the requirements are written against, not a stretch target.
- **No unbounded work per request.** Returning a page of Threads must not cost work proportional to the Conversation's total Thread count. Thread Unread Count is the most likely place for a per-Thread query to creep in, since the straightforward implementation counts messages once per row.
- **Per-participant thread state must not grow without bound.** Thread Read Markers are stored per participant per Thread and are the one genuinely new source of row growth in this work. Growth must track Threads a participant has read rather than Threads that exist, and rows must be reclaimed when a participant leaves a Conversation.
- **Cached Thread data must not go stale.** Thread data is served from a distributed cache with a fifteen-minute lifetime and negative caching for misses. Every operation in this PRD that changes a Thread must invalidate it, or a state change will appear to succeed and then revert for other participants for up to fifteen minutes. This is the single highest-risk correctness surface in the work.
- **Migration must be safe on live customer data.** Customers self-host and upgrade in place. Schema changes must apply to a Conversation with a large Thread history without a maintenance window, and must be safe to run twice.
- **The interface must survive missing capability.** A client may talk to a server that predates this work. Every new surface must be gated on the server declaring support, and must degrade to current behaviour rather than erroring.
- **Accessibility.** New controls and list surfaces meet the same bar as the rest of Talk: keyboard reachable, screen-reader announced, and state conveyed by more than colour — which matters specifically for Thread Labels, where colour is the point. A label's text always accompanies its colour.
- **Theme.** Label palette and state indicators must be legible in both light and dark themes at normal and high contrast.
- **Localisation.** All new user-visible text is translatable. Thread Titles and Thread Labels are user content and are never translated. Vietnamese is the primary customer language and must be correct in search matching, truncation and sorting.
- **Real-time consistency.** State, pin and label changes must reach other participants viewing the same Conversation without a reload, over the signalling path Talk already uses for thread events.

## 6. Constraints and Guardrails

### 6.1 Upstream Divergence

`clavis-spreed` is a fork that must keep taking upstream Nextcloud Talk releases. Every change here is a future merge conflict. Two guardrails follow. First, extend upstream structures rather than replacing them — adding to the existing thread tables and endpoints costs less at merge time than a parallel implementation alongside them. Second, where upstream has deliberately not done something, record why we are doing it anyway. The live example is the per-Thread read marker: upstream added those columns and removed them three weeks later, and this PRD reinstates them because the alternative does not deliver what the issue asks for (§4.7). That is a knowing, permanent divergence on a table upstream may still change, and it is the largest single merge liability in this work.

The repository is currently a shallow clone with a single commit of history, which makes upstream rebasing impractical. Restoring full history is a prerequisite for the work, not part of it.

### 6.2 Data and Privacy

Thread Titles and Thread Labels are user-authored content in customer conversations and carry the same sensitivity as messages. Two consequences. Notifications must respect the existing sensitive-Conversation setting, withholding Thread Titles wherever message previews are already withheld. And the Clavis push proxy must remain unable to read what it carries: nothing in this PRD may move Thread Titles into any part of a push payload the proxy can inspect. The proxy relays material encrypted by the customer's server for the device, and that property is a stated commitment to customers, not an implementation detail.

### 6.3 Cost of the Chosen Unread Model

Per-Thread unread independence is required (§4.7), and it is not free. Three costs are accepted deliberately.

**Storage and write volume.** A Thread Read Marker is one row per participant per Thread they read, updated every time they read further. In an active Conversation that is the highest-write-rate table this feature touches. The mitigation is that rows exist only for Threads a participant has actually read, and are reclaimed when a participant leaves — but a heavily used Conversation will still accumulate them, and capacity planning must assume it.

**Divergence from upstream.** These are the columns upstream created and dropped. Reinstating them means our fork carries a shape upstream rejected, on a table upstream continues to change. See §6.1.

**A split notion of "read".** Conversation unread stops being one number. Reading the main chat no longer implies the Conversation is read, because a Thread may still hold unread messages, and reading a Thread no longer contributes to main-chat unread. Every surface that today shows one unread state must be checked against the two it now has, and the two must never contradict each other — FR-30's consequences exist to pin that down.

## 7. API Surface and Compatibility

This is a public OCS API consumed by three shipped clients that this epic does not modify. That constrains change shape.

- **Additive only.** New fields on the Thread representation, new endpoints for the new operations. No field removed, renamed, or given a new meaning. FR-33 exists because the current code violates this by overwriting one identifier with another.
- **Capability-gated.** New behaviour is announced through a capability the clients already know how to read, so a new client against an old server and an old client against a new server both behave predictably.
- **Documented in the generated specification.** Thread endpoints are currently undocumented outside the generated OpenAPI specification; new endpoints must appear there, and the regenerated specification is part of the work rather than a follow-up.
- **Errors are distinguishable.** A write refused because a Thread is Locked must be distinguishable from one refused for lack of permission and from one refused because the Thread does not exist. FR-5 depends on this: the composer cannot explain a refusal it cannot identify.
- **Federation degrades, never lies.** Thread state, pinning, labels and unread counts are not specified across federation. A federated Conversation must present threads as it does today rather than showing controls that silently do nothing.

## 8. Non-Goals

- **Not a task tracker.** Thread State has three values about the state of a conversation. It is not assignment, due dates, priority, or status workflow. A Closed Thread means the talking stopped, not that work finished.
- **Not a moderated taxonomy.** Thread Labels are free text by explicit choice, which makes this product an outlier: of the four comparable tools researched, only Discord has per-thread labels and they are predefined per channel with no free-text path at all (addendum §10). There is no label administration surface, no per-Conversation approved set, no renaming a label across Threads, no label hierarchy.
- **Not private organisation.** Pins and labels are shared within the Conversation. Personal bookmarks, personal tags and per-participant ordering are not in this product.
- **Not automatic lifecycle.** No auto-close after inactivity, no auto-archive, no scheduled cleanup. Every state change is a person deciding. `[NOTE FOR PM]` Auto-close on inactivity is the most likely follow-up request once moderators feel the manual cost in a room with a thousand Threads.
- **Not federation.** See §2.2 and §7.
- **Not mobile clients.** See §11. The server work lands; app adoption is a separate epic.
- **Not a thread permission system.** Thread Managers are derived from existing authority — Thread Root Message authorship and Conversation moderation. There is no per-Thread participant list, no per-Thread role, no delegating management of one Thread to a specific person.
- **Not threads-of-threads.** A Thread has one level. Replying inside a Thread does not create a sub-Thread.

## 9. MVP Scope

### 9.1 In Scope

- Thread State — Ongoing, Closed, Locked — with soft close, hard lock, and write refusal at every entry point into a Thread (FR-1 … FR-7).
- Thread Manager authority for state, pinning and labelling, mirroring existing renaming authority (FR-8 … FR-10).
- Thread Directory as a first-class destination, replacing the list nested in Shared items: filtering by state and label, pinned-first ordering, incremental loading, and rows that carry enough to decide without opening (FR-11 … FR-16).
- Thread search by title, diacritic-insensitive, with handoff to the existing message search on empty results; and title search over the Followed Thread List (FR-17 … FR-19).
- Pinned Threads, bounded per Conversation (FR-20, FR-21).
- Thread Labels — free text, colour bound to label text within a Conversation, existing-label suggestion on input (FR-22 … FR-25).
- Per-Thread unread state: independent per Thread, unaffected by reading the main chat or other Threads, shown in both list surfaces, with first-unread positioning on open, mark-as-read from the list, and a Conversation-level indication that some Thread is unread (FR-26 … FR-30).
- Server-side notification correctness: Thread Title in notification text, Thread identifier in push payloads, both identifiers preserved, working deep links on web, within the push size budget (FR-31 … FR-35).
- Thread Directory entry points from the top bar, the thread header and the Thread Root Message, with linkable and restorable addresses (FR-36 … FR-39).
- Capability declaration, regenerated API specification, and integration coverage extending the existing thread test suite.

### 9.2 Out of Scope for MVP

- **Android and iOS client adoption of the corrected notification payload** — separate epic. The server change ships first because the clients cannot adopt what is not sent, and the push delivery path itself is independently blocked (§11).
- **Threads over federation** — upstream does not support thread message fetching over federation; we are not building that first.
- **Automatic lifecycle transitions** — deferred to v2. `[NOTE FOR PM]` Load-bearing: a moderator facing a thousand stale Threads will ask for this within a release of shipping the manual version.
- **Bulk operations on Threads** — closing or labelling many at once. Deferred to v2, and likely requested alongside auto-close for the same reason.
- **Label administration** — renaming a label across Threads, merging near-duplicates, deleting one everywhere. Deferred to v2; FR-25's suggestion behaviour is the v1 mitigation for the mess this would clean up.
- **Bots changing Thread State** — deferred. Plausible for an escalation workflow, and cheap once the endpoints exist, but no requester has asked.
- **Reactions in Locked Threads** — FR-5 refuses them on an assumption that may not survive review. §13.
- **Personal pins, personal labels, personal ordering** — non-goal, not deferral. §8.

## 10. Success Metrics

Measurement is constrained: Clavis Talk is self-hosted and Clavis does not collect product telemetry from customer servers. Primary metrics are therefore measured on a consenting reference deployment through direct queries, and corroborated qualitatively with the customer who raised the issue. `[ASSUMPTION: a reference deployment is available and a customer will give feedback. If neither holds, these metrics are aspirational and should be replaced with acceptance criteria before the epics workflow runs.]`

**Primary**

- **SM-1**: Live-thread density in the reference deployment's largest Conversation — the share of Threads shown by the Directory's default filter, out of all Threads. Target: below 30% within a month of adoption, from 100% today. Validates FR-2, FR-13.
- **SM-2**: Thread creation rate in Conversations with more than fifty Threads, comparing the month before and the month after. Target: no decline, and ideally growth — the thesis is that people abandon threads at scale because they are unnavigable, so navigability should show up as continued use. Validates FR-11, FR-17.
- **SM-3**: Requester confirmation. The person who filed the issue, asked one month after adoption whether the room that prompted it is now navigable, answers yes without qualification. Validates the whole document; the only metric that catches getting every requirement right and the product wrong.

**Secondary**

- **SM-4**: Share of Conversations with more than fifty Threads that use at least one Thread Label. Target: above half within two months. Below that, labels were the wrong answer to organising a mixed-purpose room. Validates FR-22, FR-14.
- **SM-5**: Median position, in the Directory's default order, of the Thread a participant opens. Target: within the first ten rows. High values mean the ordering is wrong even though the list is short. Validates FR-12, FR-20.
- **SM-6**: Share of Thread Directory sessions that use search. Reported, not targeted: heavy use means titles are how people navigate and search deserves more investment; near-zero use with high SM-5 means the list is doing the work by itself. Validates FR-17.

**Counter-metrics (do not optimise)**

- **SM-C1**: Locked Thread count. Should stay low in absolute terms. Locking is for genuine problems; a Conversation with many Locked Threads is one where moderation replaced conversation. Counterbalances SM-1 — closing threads is the goal, locking them is not.
- **SM-C2**: Distinct Thread Labels per Conversation. Should stay well under twenty. A room with sixty labels has a taxonomy nobody agreed to and free text failed. Counterbalances SM-4 — label adoption is good, label proliferation is the failure mode.
- **SM-C3**: Median Thread Directory first-page response time under the reference deployment's largest Conversation. Must not regress as fields are added to rows. Counterbalances SM-5 and FR-15 — a richer row that loads slowly is worse than a plain one that does not.
- **SM-C4**: Thread Read Marker row count in the reference deployment, and its growth rate, against the count of participants times Threads. Reported and watched, not targeted: the point is to learn whether lazy materialisation actually bounds growth the way §6.3 assumes, before a customer discovers it does not. Counterbalances FR-26 and FR-27 — per-Thread independence is the goal, unbounded per-participant state is the cost that upstream refused to pay.

## 11. Dependencies and the Mobile Follow-Up

**Upstream Nextcloud Talk.** Every change lands on a fork that must keep merging upstream releases. Upstream may itself add thread lifecycle at any time, and if it does, our version becomes the conflict. Worth a check of upstream's roadmap before the architecture workflow commits to a shape.

**Nextcloud notifications app.** Push delivery goes through the platform notifications app, which encrypts each payload for the target device. FR-32 and FR-35 depend on what that app will carry and on the size it can encrypt. This is a platform component, not ours, and the size budget is a real constraint rather than a cautious one.

**Clavis push proxy.** Not a dependency for correctness and cannot help: it relays payloads it cannot read. It is, however, a dependency for *verification* — its delivery path to Apple and Google is unimplemented and blocked on an Apple push key and a Firebase project that Clavis does not yet have. So FR-32 and FR-35 can be verified at the point of payload construction but not end-to-end onto a device until that unblocks. Downstream planning must not assume a device test is available.

**Mobile clients — the follow-up epic.** Android and iOS must read the Thread identifier from the payload and route into the Thread, and display Thread State, labels, pins and unread counts. That epic depends on this one shipping first — the clients cannot adopt a payload that is not sent — and on the push delivery path for its own verification. Sequencing it after this work is a consequence of both, not a preference.

## 12. Risks and Mitigations

- **Cache staleness makes state changes look broken.** Thread data is cached for fifteen minutes with negative caching. A missed invalidation on any new mutation means a participant closes a Thread, sees it close, and watches it reappear for colleagues. High likelihood on first implementation, high visibility, and the kind of bug that gets reported as "the feature doesn't work". Mitigation: treat invalidation as part of every mutation's acceptance criteria, and cover it with a multi-actor integration test rather than a unit test that cannot see the cache.
- **Locked leaks through an unguarded entry point.** Content reaches a Thread through at least six paths — direct message, bot message, file share, rich object, poll, and the scheduled-message job that fires later without a live request. A guard on the obvious one is not a guard. Mitigation: FR-5 enumerates them as separate testable consequences so a missed path fails a test, not a customer.
- **Unread counts turn into a per-Thread query.** The straightforward implementation counts messages once per row. Under the scale this feature exists for, that is the Directory being slow at exactly the moment it matters. Mitigation: SM-C3 makes it measurable, and the NFR forbids per-request work proportional to Thread count.
- **Thread Read Marker rows grow faster than expected.** One row per participant per Thread read, written on every read, on a table in an active Conversation's hot path. This is the cost that plausibly drove upstream to drop these columns, and lazy materialisation bounds it by engagement rather than eliminating it. Mitigation: SM-C4 watches the growth against real usage rather than an estimate, rows are reclaimed on leaving a Conversation, and capacity for the reference deployment is measured before a customer meets it.
- **The two unread states contradict each other.** With main-chat unread and thread-unread now separate, it is possible to ship a Conversation that shows no unread badge while holding an unread Thread, or the reverse. That reads as a bug regardless of which state is technically right. Mitigation: FR-30's consequences specify both directions explicitly, and the Conversation list is checked against them rather than against main-chat behaviour alone.
- **Label proliferation reproduces the original problem.** Free text plus no administration is how a room ends up with sixty labels, and a sixty-label filter is the unnavigable list again. Mitigation: FR-25's suggestion behaviour, SM-C2 as an early warning, and label administration named as the v2 answer rather than discovered as one.
- **Upstream merge cost compounds.** Touching thread tables, thread endpoints, the chat send path, the notification builder and the signalling payload is a broad footprint on a fork. Mitigation: additive-only discipline in §7, and full git history restored before the first change so rebasing is possible at all.
- **State naming confuses users coming from other tools.** Closed and Locked do not mean the same things in Discord, Slack, Teams and Zulip. Choosing the wrong pair means every new user learns the semantics by being surprised. Mitigation: comparable-product research, pending at the time of writing, feeding a naming decision before the interface strings are written.
- **A migration stalls on a large customer table.** Schema changes apply in place on customer servers with long Conversation histories and no maintenance window. Mitigation: the NFR requires it, and it needs a rehearsal against realistic data volume, not a fresh install.

## 13. Open Questions

1. **What advances the Conversation read marker once Threads have their own?** FR-27 requires that reading a Thread not affect main-chat unread and that reading the main chat not affect Thread unread. That leaves an unspecified case: a Thread a participant has never opened falls back to their Conversation marker, so advancing that marker by reading the main chat would silently mark such Threads read. Whether to accept that, or to freeze a fallback point when the marker advances, is a behavioural choice with real implementation cost. Addendum §4.4 sets out three options and argues for the third — a separate fallback baseline advanced only by an explicit "mark all threads read". **Deferred to the architecture workflow by the requester. Affects FR-26 and FR-27; must be settled there, not discovered in code.**
2. **Do reactions count as writes in a Locked Thread?** FR-5 says yes on an assumption. Reactions are low-harm and blocking them may read as excessive. **Owner: requester. Non-blocking; a change here is a small change.**
3. **Should a Thread Manager be able to state a reason when locking?** UJ-4's edge case has the participant seeing "the Locked state and the reason", which no requirement provides. A free-text reason on the locking system message would cover it cheaply. **Owner: requester.**
4. **Should bots be able to change Thread State?** Excluded in v1 without being asked about. An escalation workflow that closes its own Thread on resolution is a plausible customer request. **Owner: requester. Non-blocking.**
5. **Is a reference deployment available for §10?** All primary metrics assume one, plus a customer willing to answer SM-3. If not, §10 needs replacing with acceptance criteria. **Owner: Clavis. Resolve before the epics workflow.**
6. **Has upstream announced thread lifecycle work?** If it has, the architecture should shape toward whatever upstream is doing rather than against it. **Owner: engineering. Cheap to check, expensive to skip.**
7. **What is the actual scale to design for?** §5 asserts a thousand Threads and two hundred participants, inferred from "groups with very many threads". Real numbers from the room that prompted the issue would let the NFRs be tested rather than guessed. **Owner: requester.**
8. **Does the notifications app size budget accommodate a Thread Title?** FR-35 assumes the title fits if the message preview yields. Needs measuring against the platform's encryption limit before FR-31 is committed. **Owner: engineering. Blocks FR-31 and FR-35.**

## 14. Traceability — Issue #22 to Requirements

| # | Issue item | Requirements | Note |
|---|---|---|---|
| 1 | Thread classification: Ongoing / Closed / Locked | FR-1, FR-2, FR-4, FR-6, FR-7 | Semantics of Closed vs Locked defined in §4.1 |
| 2 | Locked blocks posting; reopen via Ongoing | FR-5, FR-6 | Six entry points enumerated in FR-5 |
| 3 | Only thread opener or room moderator may modify | FR-8, FR-9, FR-10 | Mirrors existing renaming authority |
| 4 | Dedicated thread page level with Shared items; entry points elsewhere | FR-11 … FR-16, FR-36 … FR-39 | Nested list removed, not left as a second path |
| 5 | Pin / unpin threads | FR-20, FR-21 | Bounded per Conversation |
| 6 | Tags / labels for threads | FR-22 … FR-25 | Free text with Conversation-scoped colour |
| 7 | Search to navigate to a thread | FR-17, FR-18, FR-19 | Title search; hands off to existing message search |
| 8 | Push notification click opens the thread | FR-32, FR-33, FR-34 | Server-side defect, not a client gap — §4.8 |
| 9 | Unread message count in the thread list | FR-26 … FR-30 | Independent per Thread; costs accepted in §6.3 |
| 10 | Thread title in thread-related notifications | FR-31, FR-35 | Size budget is a real constraint — open question 8 |

## 15. Companion Documents

- `addendum.md` — implementation-level material for the architecture workflow: existing thread machinery and where it is, the notification defect in detail, schema and migration considerations, cache invalidation points, the six write paths Locked must cover, rejected alternatives and their reasons, two unrelated upstream defects found while mapping, and a cited comparison of how Discord, Slack, Teams and Zulip model thread lifecycle, labels and unread state (§10) — the evidence behind this document's naming and bounds.
- `.memlog.md` — decision and audit trail for this PRD run.

## 16. Assumptions Index

Every `[ASSUMPTION]` in the document, surfaced for confirmation:

1. **§2** — Personas in §2.1 and §2.3 are inferred from the issue text and repository context. No customer interviews, support tickets or usage data were supplied. Highest-leverage item in this list: the journeys drive the whole document.
2. **§2.2** — Guests are excluded from all thread management.
3. **§4.1** — State changes warrant system messages, following the pattern established for creation and renaming.
4. **FR-3** — Reopening a Closed Thread by posting produces no system message.
5. **FR-5** — Locked freezes existing content: editing and deleting messages already in the Thread are refused.
6. **FR-5** — Reactions count as writes and are refused. Weakest assumption in the document; open question 2.
7. **FR-13** — The Directory's state filter resets to Ongoing between visits rather than persisting.
8. **FR-17** — Title search must be diacritic-insensitive for Vietnamese.
9. **FR-21** — Pinned Threads are bounded at a fixed server-side constant around ten.
10. **FR-22** — Label bounds of five per Thread and twenty per Conversation, taken from Discord's forum-tag caps rather than invented.
11. **§4.6** — Near-duplicate labels are mitigated by input suggestion, not by validation.
12. **FR-23** — Label identity is case-insensitive within a Conversation.
13. **FR-24** — A label colour change propagates to other participants live; a reload-to-see-it fallback is acceptable if it proves costly.
14. **§4.7** — Thread Read Markers are materialised lazily, only for Threads a participant reads, with fallback to the Conversation marker where absent. This is what makes per-Thread markers affordable; architecture may reach the same behaviour another way. Open question 1.
15. **FR-26** — A participant's own messages never count as unread.
16. **FR-29** — A mark-as-read affordance in the Directory is expected, so tidying a room does not require opening every Thread.
17. **FR-32** — Routing information is not content, so a Thread identifier may travel in a sensitive Conversation's payload where the Thread Title may not.
18. **FR-33** — Payload changes must be backward compatible for the unmodified shipped clients.
19. **FR-35** — When space is short, the Thread Title is preserved and the message preview is shortened.
20. **§10** — A consenting reference deployment exists and the requesting customer will answer SM-3.
