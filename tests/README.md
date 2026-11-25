# Tests

Engagement has three separate suites, modeled on the Autofill plugin's setup:

| Command | What it runs | Needs |
| --- | --- | --- |
| `composer test:unit` | PHPUnit in `tests/unit/`, no Craft app or database | PHP 8.2+, `composer install` |
| `composer test:integration` | Codeception in `tests/integration/` inside a disposable Craft site on DDEV | Docker + DDEV |
| `npm run test:browser` | Playwright in `tests/browser/` against a freshly seeded Craft site | Docker + DDEV, Node, host PHP with `pdo_mysql`, `gd`, `intl` |

None of this ships to customers: `tests/`, `testbench/`, `.ddev/`, `phpunit.xml.dist`, `playwright.config.ts` and
`package.json` are excluded through `.gitattributes` and Composer's `archive.exclude`. Nothing under `src/` references test code.

## Layout

```text
tests/
  unit/                     PHPUnit, no Craft boot (SecurityHelper, query helpers, Settings validation)
  integration/              Codeception + Craft module (field values, render sanitization, migration, aggregates)
  browser/                  Playwright specs, global setup, shared helpers
  fixtures/Support/         TestProject helper used by integration tests
  environment/testbench/    Tracked recipe for the disposable Craft site (config, seed module, templates)
testbench/                  Generated from the recipe by testbench/bin/materialize (ignored except bin/ scripts)
```

## Devbox (Docker only)

No host PHP, Composer, or DDEV installation is needed. From the repository root:

```bash
tests/docker/run start
tests/docker/run root composer install --no-interaction
tests/docker/run root composer test:unit
tests/docker/run integration
npm ci
npx playwright install chromium
ENGAGEMENT_TEST_RUNTIME=docker npm run test:browser
```

The recipe builds PHP 8.2 with Craft's extensions and uses a dedicated MySQL 8 container.
Only the test web server is published, on loopback port 8098; the database has no host port.
It retains the same database identity checks and suite lock as DDEV. Run suites sequentially.
`tests/docker/run stop` removes the disposable containers. Rebuild after Dockerfile changes with
`docker compose -f tests/docker/compose.yaml build`. In a restricted agent sandbox, use a writable
npm cache (`npm ci --cache /tmp/engagement-npm-cache`) and browser cache
(`PLAYWRIGHT_BROWSERS_PATH=/tmp/engagement-browsers npx playwright install chromium`); use the
same browser-cache variable when running the suite. If Buildx cannot write its activity file,
`DOCKER_BUILDKIT=0 tests/docker/run start` uses Docker's classic builder.

## First-time setup

DDEV's config is local and ignored. Create it once from the repository root:

```bash
ddev config --project-name=craft-engagement-testbench --project-type=php \
  --docroot=testbench/web --php-version=8.2 --database=mysql:8.0 \
  --composer-root=testbench \
  --web-environment-add="CRAFT_ENVIRONMENT=dev,CRAFT_DB_SERVER=db,CRAFT_DB_DATABASE=db,CRAFT_DB_USER=db,CRAFT_DB_PASSWORD=db,CRAFT_TEST_DB_DATABASE=db_test,ENGAGEMENT_TEST_DB_IDENTITY=craft-engagement-testbench:db_test,CRAFT_SECURITY_KEY=engagement-testbench-not-for-production,PRIMARY_SITE_URL=https://craft-engagement-testbench.ddev.site"
```

Then:

```bash
composer install
npm install
npx playwright install chromium   # only if the pinned Playwright's Chromium isn't cached yet
```

The credentials and security key above are disposable local values.

## Database safety

Automated suites only ever touch `db_test` in the `craft-engagement-testbench` DDEV project. Each reset checks an
identity marker table (`engagement_test_database_marker`) first and refuses unmarked or differently marked databases;
the manual site's `db` database is never reset. If `db_test` already exists without a marker, confirm it is disposable and run:

```bash
testbench/bin/mark-test-database
```

Integration and browser runs share `db_test`, so they take an exclusive lock at `testbench/.test-run.lock`. After an
interrupted run, check the lock's `owner` file and confirm that process is gone before removing a stale lock.

## Integration suite

`composer test:integration` materializes `testbench/`, starts DDEV, installs the site's dependencies, recreates `db_test`
and runs Codeception inside the web container. Craft's test module installs Craft and Engagement into `db_test` and wraps
each test in a transaction.

## Browser suite

`npm run test:browser` does everything from scratch on each run (see `tests/browser/global-setup.ts`):

1. Materializes `testbench/` and starts DDEV.
2. Recreates the marked `db_test`.
3. Installs Craft + Engagement into it with host PHP, using a random password generated for that run.
4. Runs `craft engagement-testbench/seed/browser`, which creates the Rating / Likes / Favorites fields, sample entries
   (published, disabled, draft, editor-supplied malicious SVG, and a page without the fields), and `member`, `moderator`
   and `deleter` users. The fixture JSON goes to a temp file that's removed afterwards.
5. Serves `testbench/web` with PHP's built-in server on `http://127.0.0.1:8098` and stops it when the suite ends.

Specs:

- `widgets.spec.ts`: each widget renders in both `normal` and `deferred` (`?mode=deferred`) modes, clicks send the exact
  payload the controllers expect (`elementId`, `fieldId`, `siteId`, plus `rating` / `value`) with a CSRF header, responses
  update the UI, and state survives a reload.
- `security.spec.ts`: endpoint validation (disabled / draft elements, field not in layout, bad site, bad values, missing
  CSRF, members-only favorites) and that editor-supplied SVG, colors and heading text can't execute.
- `moderation.spec.ts`: front-end members can't reach moderation actions, moderators without the delete permission can
  view but not delete, and moderators with it can.

Options: `ENGAGEMENT_BROWSER_PORT` (default `8098`), `ENGAGEMENT_BROWSER_DB_PORT` (default: discovered from `ddev describe`).
Use `npm run test:browser:headed` to watch the run. Screenshots and traces for failures land in `tests/browser/artifacts/`.

The testbench front-end template is `tests/environment/testbench/templates/samples/_entry.twig`.

`ddev stop` stops the containers when you're done.
