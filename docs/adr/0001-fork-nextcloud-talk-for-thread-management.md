<!--
  - SPDX-FileCopyrightText: 2026 Clavis Tech Ltd
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# 0001. Fork Nextcloud Talk for thread management

Date: 2026-08-13 · Status: **proposed** (Vuong Pham, architecture lane per HOW-WE-WORK §0)

ADR 0002 exception grant, recorded here rather than in the handbook per handbook
ADR 0007 ("exception records live in the owning repo"; "applies to every core
fork"). Fork base: `upstream/stable34` at `f39e8bc5a`, Talk 24.0.4.

Supersedes the `clavis_talk_threads` app in `clavis-core/services/nextcloud-talk-threads`
and the hot-patch script `clavis-deploy/ops/patch-talk-thread-notification.sh`.

## Context

`ClavisTechLtd/clavis-deploy#22` reports ten gaps in Talk 24's threads. Trọng
Tín runs many threads inside one conversation and cannot close a thread, pin it,
label it, count unread per thread, find one by name, or tell from a notification
which thread a message belongs to.

What this fork delivers today, against that list: **items 1, 2, 3, 8 and 10**,
plus part of item 4 (thread state is visible where a thread appears; the
first-class Thread Directory is Epic 2). Items **5 (pin), 6 (tag/label),
7 (search) and 9 (unread count)** are Epics 2 and 3, still in backlog. Item 8's
client-side half — the browser service worker that opens the thread rather than
the dashboard — ships separately in
`clavis-deploy/services/nextcloud-notifications-patch`.

One further story is deliberately not shipping: Story 4.3 would compose the
thread id into the push payload's `id` field, but that field is read as a bare
room token by every shipped client (`NCPushNotification.m:50`;
`NotificationWorker.kt` at nine `KEY_ROOM_TOKEN` sites), so landing it would
break push navigation on all of them. It stays blocked pending a client change
or a capability gate.

Two attempts preceded this ADR, and both are informative:

**The companion app.** `clavis_talk_threads` v1.0.0 keeps its own state
(`clavis_thread_state`, `clavis_thread_label`, `clavis_thread_read`) and reaches
Talk through a single adapter class, so Talk stays stock. It delivers items 1-6
and 8. Its own README records why that is not enough:

> No thread state can be shown *inside* Talk's own thread list: Talk 24 exposes
> exactly two frontend hooks (`registerMessageAction`,
> `registerParticipantSearchAction`) and neither reaches that list. That limit is
> the reason items 1, 4, 5 and 8 also belong upstream in `spreed`.

So the sanctioned NextCloud extension surface — an app — reaches the *data* but
not the *place the user looks*. A user reads their threads in Talk's thread list;
a second app page listing the same threads with different state is a second place
to look, not a fix.

The adapter also calls `OCA\Talk` internals (`Manager`, `ParticipantService`,
`ThreadService`, `ChatManager`) because `OCA\Talk\OCP` covers conversations only.
An app that couples to another app's internals carries the same upgrade risk as a
fork, without the fork's ability to see the change in a diff.

**The hot patch.** `ops/patch-talk-thread-notification.sh` rewrites
`Notifier.php` inside the running container to name the thread in notifications.
It is idempotent and marked (`CLAVIS-THREAD-TITLE`), and it is live on Trọng Tín
today. It also sits in the appstore-installed app directory, so a Talk update
through the UI silently reverts it, and the script's own header says so. This is
the failure mode handbook ADR 0008's corollary names: a change that exists only
on a box is not a deployment.

## Decision

**Talk ships as a Clavis fork, `ClavisTechLtd/clavis-spreed`.** This is an ADR
0002 exception, scoped as follows.

**Scope of the grant.** Thread management and thread-aware notifications. As of
this ADR the fork changes 119 files against `upstream/stable34`: 54 new, 65
modified. Excluding tests, generated OpenAPI types and translations, the drift
surface is **37 upstream files, +2386/−110** — 20 under `lib/`, 15 under `src/`,
plus `appinfo/info.xml` and `docs/capabilities.md`. Growing that surface for a
feature outside thread management needs its own ADR.

**Upstream sync procedure.**
- `origin/main` is a mirror of upstream `main` and carries no Clavis commits.
- Clavis work lives on `stable34`, rebased onto `upstream/stable34`.
- Rebase on every upstream Talk patch release, not on a calendar. The gate is
  that `git diff upstream/stable34 stable34` stays reviewable in one sitting; if
  it stops being reviewable, that is the signal to upstream features or drop
  them, not to skip the rebase.
- Talk's major version is pinned to the NextCloud major in
  `appinfo/info.xml`; a NextCloud major upgrade and a Talk major rebase are one
  piece of work, not two.

**Why not contribute upstream first.** Preferred by ADR 0002 and still the
intended endpoint for the parts that are general (thread lifecycle state,
notification thread context). It does not solve the customer's problem on the
timescale it exists on: upstream review plus a Talk release plus a NextCloud
upgrade is quarters, and Trọng Tín is running a hot patch now. Contributing
upstream and carrying the fork are not alternatives — carrying the fork is how we
keep shipping while the upstream conversation happens.

**Distribution.** Built into a versioned tarball on the build machine and
bind-mounted read-only over `custom_apps/spreed`, the same shape as
`clavis_cloud` and `auto_groups`. This does not conflict with handbook ADR 0008:
that ADR forbids bind-mounting files a container's *entrypoint* writes, and the
NextCloud image's entrypoint excludes `/var/www/html/custom_apps/` from its
rsync. The mount is read-only on purpose, so that an appstore update of Talk
fails loudly instead of silently reverting Clavis code — the exact defect the hot
patch has today.

**`clavis_talk_threads` is retired**, and this costs capability in the short
term. The two implementations overlap on thread state, which is the part that
must never run twice against one conversation — the companion app enforces
Archived through `ArchivedThreadGuard` on `BeforeChatMessageSentEvent` while the
fork enforces Locked inside `ChatManager`, so with both mounted a message can be
refused by two different rules with two different error shapes. So it is
unmounted from `clavis-core/docker-compose.yml` and disabled before the fork is
enabled.

The cost: pin, tag/label, search and per-thread unread (issue #22 items 5, 6, 7,
9) exist in the companion app and do not exist in the fork until Epics 2 and 3
land. Retiring it removes them from staging. This is acceptable only because the
app was **never deployed to a customer** — staging only, so no customer loses a
feature and no data migration is owed. If Epics 2 and 3 slip, the gap is a
backlog item, not a regression to a shipped promise.

## Consequences

**Buys.** Thread state is visible where users read threads. Notifications name
the thread on every surface, including Android and iOS, which read the same OCS
subject. The hot patch and its "re-run after every Talk update" footgun go away.
Changes arrive through the release path with a diff, a review and a rollback,
instead of by `docker cp`.

**Does not buy, yet.** Four of the ten reported gaps (pin, label, search,
per-thread unread) are backlog. `clavis-deploy#22` is not closed by this ADR and
should not be reported to the customer as closed.

**Costs.** Every Talk security release now needs a rebase before it reaches a
customer, and until it does, customers run a Talk we have to patch ourselves.
This is precisely the cost ADR 0002 exists to avoid, accepted here with the
scope and the sync procedure above as the containment. The fork is also large:
the build produces ~54 MB of assets that must be built, streamed and mounted,
where `auto_groups` is 604 KB.

**Risks and what to watch.**
- **Rebase debt is silent until it is expensive.** The tripwire is the
  reviewability of `git diff upstream/stable34 stable34`, and nothing enforces
  it automatically today.
- **The notification subject is a client contract.** `clavis-talk-android` and
  `clavis-talk-ios` parse the OCS subject and the rich parameters. A change here
  that looks cosmetic can drop information on mobile; iOS additionally composes
  its own title, so some strings cannot be fixed server-side at all.
- **Two apps claim the `spreed` app id** — the appstore copy stays in the
  `nextcloud_apps` volume underneath the bind mount. That is what makes rollback
  cheap (remove the mount) and is deliberate, but `occ app:list` shows only the
  mounted one, so the volume copy's version is not a reliable record of what is
  running.
- **This ADR constrains what may be forked, not what the features are.** New
  thread-management work inside the scope above is a normal PR.
