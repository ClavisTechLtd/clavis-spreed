# Epic 4 Context: Thread-Aware Notifications

<!-- Generated from planning artifacts. Regenerate with compile-epic-context if planning docs change. -->

## Goal

A notification tells the recipient which Thread it came from, so it can be triaged without being opened. Followers of a Thread learn when it is Closed, Locked or reopened — and why, when a reason was given — rather than discovering it by trying to post and being refused. Push payloads carry the same routing identifiers the in-app notification already carries, so a client can route into the right Thread without a second network call that can fail. Scope honesty matters here: only the naming and lifecycle stories change what a user sees on a client this work touches. The push payload work removes a round trip and a fallback failure mode and unblocks the later mobile epic; on its own it changes nothing visible, and treating it as "push click opens the thread is fixed" is a misread.

## Stories

- Story 4.1: Thread notifications name the Thread
- Story 4.2: Thread lifecycle changes notify a Thread's followers
- Story 4.3: Push payloads carry the same identifiers as in-app notifications, and fit

## Requirements & Constraints

- Every notification subject the server can emit for in-thread activity must carry the Thread Title. The authoritative enumeration is the condition in the notifier that routes subjects to chat-message parsing — nine subjects — not any prose list, which undercounts. Coverage is **one test per subject**, not one test for the family.
- Notifications for activity outside any Thread are unchanged and gain no thread text.
- Participants subscribed to a Thread are notified on lock, close and reopen, naming the Thread, who acted, and the lock reason where one was supplied. Muted-Thread participants and non-subscribers are not notified. A Closed Thread revived by someone posting produces only the ordinary reply notification — no state-change notification, because no state system message is emitted.
- No participant should learn a Thread is Locked only by attempting to post and failing.
- Push payloads must carry both the message identifier and the Thread identifier for in-thread activity, and the message identifier for activity outside a Thread — in the same composed form the in-app notification uses, so clients parse one shape. In-app identifiers must not change.
- Push payload size must be **measured against the platform notifications app's actual per-device encryption limit**, never inferred from the existing preview-truncation constant. When title and preview cannot both fit, the Thread Title is preserved and the preview is shortened, because the title is what makes the notification triageable. Truncation falls on character boundaries valid for Vietnamese, never mid-character.
- Localisation: all new notification text is translatable; Thread Titles and lock reasons are user content and are never translated.
- Verification limit: the push proxy's APNs/FCM hop is unimplemented and blocked on external credentials, and its not-implemented sender fails loudly rather than pretending. Acceptance for payload work is at **payload construction only** — no on-device verification may be claimed or assumed, and downstream planning must not assume a device test exists.

## Technical Decisions

- **Sensitivity (AD-20).** Thread Titles and lock reasons are user-authored content with message-grade sensitivity. Notifications withhold them wherever the shipped sensitive-conversation setting already withholds the message preview — **reuse that mechanism, never parallel it**. Nothing may move a Thread Title or lock reason into any part of a push payload outside the envelope the customer's server encrypts for the device; the proxy must remain unable to read what it relays. Routing identifiers are explicitly *not* content: they name a destination without disclosing what is in it, so identifiers do travel in a sensitive Conversation's payload while titles and reasons do not.
- **Composed identifier discipline (AD-12).** The notification object identifier is variable-arity and absence-significant. A trailing position may be appended, never reordered or shortened. A position is either present with a real value or **absent entirely** — never padded, never a sentinel, and `0` is never valid at any position. Shipped mobile clients parse it positionally and are not updated by this epic; a client receiving the shorter value must not misread it as carrying a thread identifier. The existing integration assertion on the composed value is the regression guard and must keep passing.
- **Read parameters, not rendered text (AD-14).** The Thread Title is already available in message parameter data on the notification path — read it from there rather than re-deriving it, and introduce no second lookup. The lock reason likewise rides the locking system message as parameter data, never concatenated into the rendered string; the notification builder reads that parameter and never re-parses rendered message text.
- **Reuse the existing recipient computation.** Lifecycle notifications use the established per-thread attendee lookup for notification recipients rather than inventing a recipient set, and leave the existing subscription logic unchanged.
- **Known adjacent defect, out of scope.** The signalling thread-info payload does not match the canonical Thread representation. Do not fix it here, but expect it to confuse anyone tracing thread context through notifications and signalling.

## Cross-Story Dependencies

- **Depends on Epic 1.** Story 4.2 needs Epic 1's lifecycle verbs and system messages and reads the optional lock reason introduced there; without those transitions there is nothing to notify about.
- **Inherits Epic 1's navigation guard.** Following a notification into a Thread is pinned under test in Epic 1 Story 1.9, deliberately ahead of both Epic 2's addressing rework and Story 4.3's payload change. This epic inherits that guard rather than establishing it — do not restate or duplicate it, and do not land the payload change assuming navigation is untested.
- **Internal ordering.** Story 4.1 establishes how the Thread Title reaches the notification builder and how sensitive-conversation withholding is applied; Story 4.2 reuses both for lifecycle notifications and extends withholding to the lock reason. Story 4.3 is independent of 4.2 but must not disturb the in-app identifiers 4.1 relies on.
- **Enables the later mobile epic.** Story 4.3 is groundwork: the mobile epic adopts the identifiers it puts in the payload and fixes the client-side fallback path that produces the reported "lands in main chat" symptom.
