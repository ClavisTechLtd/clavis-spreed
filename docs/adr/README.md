<!--
  - SPDX-FileCopyrightText: 2026 Clavis Tech Ltd
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Clavis ADRs for this fork

Decisions local to `clavis-spreed`. Cross-repo decisions and the team's rules
live in [`clavis-handbook`](https://github.com/ClavisTechLtd/clavis-handbook) —
read `HOW-WE-WORK.md` there first.

This directory, not `docs/decisions/`: handbook ADR 0007 puts ADR 0002 exception
records in the repo whose core they modify, in a directory **that repo owns**.
Upstream `nextcloud/spreed` has neither `docs/adr/` nor `docs/decisions/`, so
`docs/adr/` conflicts with nothing on rebase.

Rules (handbook `decisions/README.md`): append-only, numbers reserved up front in
the driving issue, template at `clavis-handbook/templates/adr.md`, status
`proposed` → `accepted` → `superseded`.

`docs/` is excluded from the `make appstore` build, so nothing here ships to a
customer.

## Index

| # | Title | Status |
|---|---|---|
| 0001 | [Fork Nextcloud Talk for thread management](0001-fork-nextcloud-talk-for-thread-management.md) | proposed |
