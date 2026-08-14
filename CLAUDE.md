# clavis-spreed — Clavis fork of Nextcloud Talk

Team-wide process rules live in https://github.com/ClavisTechLtd/clavis-handbook —
read `HOW-WE-WORK.md` there first. Do not duplicate handbook rules here.

## What this repo is

A fork of `nextcloud/spreed` (Talk), carried for thread management and
thread-aware notifications. Granted as an ADR 0002 exception in
[`docs/adr/0001-fork-nextcloud-talk-for-thread-management.md`](docs/adr/0001-fork-nextcloud-talk-for-thread-management.md)
— read it before adding anything outside that scope.

## Branches

- `main` — mirror of upstream `main`. No Clavis commits, ever.
- `stable34` — the integration branch. Clavis work sits here, rebased onto
  `upstream/stable34`.
- Feature branches per handbook §3 (`<tool>/<issue#>-slug`), PR into `stable34`.

## Core paths (ADR 0002)

This repo **is** an upstream core, so the usual "these directories are core" list
does not apply: every file here is upstream's until a Clavis commit touches it,
and the fork's diff against `upstream/stable34` *is* the core diff.

The rule that replaces it: **a PR may not grow the drift surface outside thread
management.** Check what a change adds before opening it:

```sh
git fetch upstream stable34
git diff --stat upstream/stable34 stable34
```

The grant's baseline is recorded in ADR 0001 (37 upstream files outside tests,
generated OpenAPI types and translations). A PR that widens that into a new
feature area needs its own ADR.

Prefer, in order: contribute the hook upstream · add a new file rather than edit
an upstream one · edit an upstream file and say why in the commit body.

## High-risk areas (HOW-WE-WORK §5.2 — label PRs `risk:high`)

- `lib/Notification/Notifier.php`, `lib/Chat/Notifier.php` — the notification
  subject and rich parameters are a contract with `clavis-talk-android` and
  `clavis-talk-ios`. A cosmetic-looking change can drop information on mobile.
- `lib/Migration/`, `lib/Model/*Mapper.php` — DB schema.
- `appinfo/info.xml` — the app version drives migrations and the `?v=`
  cache-buster on the customer's next deploy.

## Verify before PR

Tests run inside the Nextcloud container against a bind-mounted checkout. The
suite needs the server's test harness, which the production image does not ship:

```sh
# once per container: fetch tests/autoload.php + tests/lib/TestCase.php from
# nextcloud/server at the image's version into /var/www/html/tests/
docker exec -u www-data <nc-container> sh -c \
  'cd /var/www/html/custom_apps/spreed && ./vendor/bin/phpunit -c tests/php/phpunit.xml'
```

- `./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff <paths>`
- `./vendor/bin/psalm` on changed `lib/` files
- `npm run test` for `src/` changes

Note: php-cs-fixer 3.88 refuses PHP 8.5 without
`PHP_CS_FIXER_IGNORE_ENV=1`; CI runs it on a supported PHP.

Entity subclasses (`Thread`, `Attendee`, …) get their getters from
`OCP\AppFramework\Db\Entity::__call`. PHPUnit cannot stub them — build real
entities in tests instead of `createMock()`.
