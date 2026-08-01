# Musicball repository guidance

## Project overview

- Musicball is a live PHP/MySQL music competition app with a PWA front end.
- There is no framework build step or formal automated test suite in this repository.
- Production uses the `main` branch, `musicball` database, and `musicball.net`.
- Development uses the `future-app` branch, `musicball_future` database, and `mb-future.musicball.net`.
- The usual Windows local copies live under `C:\laragon\www\musicball` and `C:\laragon\www\musicball-future`.
- Treat the checked-out branch and its existing configuration as the source of truth. Do not silently switch branches, databases, or environments.

## Working agreement

- Inspect the relevant PHP, JavaScript, CSS, shared helpers, and database access before editing.
- Make the smallest cohesive change that solves the request. Reuse established helpers and UI patterns instead of creating a parallel system.
- Preserve unrelated user changes. The working tree may already be dirty; never discard or rewrite changes outside the requested scope.
- Do not commit, push, merge, open a pull request, deploy, tag a release, or refresh a database unless the user explicitly asks.
- Explain any database migration, new dependency, public behavior change, or deployment requirement before treating the work as complete.
- Do not edit generated/debug artifacts such as `input.txt`, `output_errors.txt`, or `output_partial.txt`.

## Secrets and environment safety

- Never read back, print, copy, or commit credentials, tokens, cookies, session data, or private connection details.
- Files under `config/connections/`, plus `config/local_env.php`, `config/spotify_config.php`, `config/discord_sso_secrets.php`, `.env`, and `_sessions/`, are environment-specific and ignored by Git. Do not modify them unless the user specifically requests a local configuration change.
- Do not add secrets to fixtures, logs, screenshots, patches, SQL, or documentation.
- Never run writes against the production `musicball` database as part of development or verification.
- `refresh_musicball_future` is a destructive server-side data refresh. Do not run it unless the user explicitly asks to refresh the dev database and confirms the target.

## Database conventions

- Use the shared PDO connection from `config.php`; do not introduce a second connection path.
- Use prepared statements for user- or request-controlled values.
- Wrap related multi-table writes in a transaction and roll back on failure.
- Preserve the QA table proxy behavior in `config.php`: ordinary `ML_*` queries may be rewritten to `QA_ML_*` in QA mode.
- Never assume production and future schemas are identical. Check existing schema helpers and call out any required migration.
- Avoid destructive delete-and-recreate patterns when stable IDs or foreign keys are involved. Prefer targeted updates or upserts.
- Do not modify production data, clone data, or execute broad SQL merely to test a code change.

## PHP and request handling

- Follow the repository's existing procedural PHP style and helper organization.
- Keep authentication and admin authorization checks on server-side entry points.
- Validate and normalize request parameters before database use.
- Escape user-controlled HTML output with `htmlspecialchars` using the surrounding file's established pattern.
- Preserve redirect-and-exit behavior after successful POST requests to avoid duplicate submissions.
- Do not expose raw exception messages, SQL, paths, or secrets to players.

## UI and PWA conventions

- Reuse the main gameplay visual language from shared styles and existing pages, especially `vote.php`, before adding one-off components.
- Every UI change must work in both light and dark mode.
- Check narrow phone widths as well as desktop. Do not shrink important touch targets to solve toolbar or layout pressure.
- Keep interactive controls keyboard accessible and maintain visible focus, disabled, hover, and selected states.
- When changing shared assets, CSS, or JavaScript, inspect `service-worker.js` and the asset URL/cache-busting behavior. Update the cache version only when needed to ensure clients receive changed static assets.
- Preserve PWA/offline behavior; do not add external runtime dependencies casually.

## Verification

Run checks appropriate to every changed file:

```bash
# Each changed PHP file
php -l path/to/file.php

# Each changed JavaScript file
node --check path/to/file.js

# Patch hygiene
git diff --check
git status --short
```

- If PHP or Node is unavailable, say which checks could not be run.
- For database-writing changes, trace success, validation failure, and exception/rollback paths without writing to production.
- For UI changes, verify or explicitly report the relevant desktop/mobile and light/dark states.
- Review the final diff for accidental environment files, secrets, debug output, and unrelated formatting churn.

## Handoff

- Lead with what changed and the user-visible result.
- List the files changed and checks run.
- State clearly whether a schema change, cache refresh, manual test, or deployment step remains.
- Never claim a browser, database, or production test was performed unless it actually was.
