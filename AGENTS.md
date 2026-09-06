# AGENTS.md

Flarum extension (`zephyrisle/flarum-zai-bot`): an AI bot that auto-replies to forum posts and private messages. PHP backend in `src/`, TypeScript frontend in `js/src/`. Comments throughout the codebase are written in **Chinese** — match that style when adding comments.

## Commands

- **Test (PHP)**: `composer test` (= `phpunit`). All tests are unit tests against an **in-memory SQLite** DB (schema created in `tests/bootstrap.php`, not migrations) — no DB server needed. `phpunit.xml.dist` sets `failOnWarning`/`failOnRisky`.
- **Run a single test file**: `vendor/bin/phpunit tests/Unit/AIServiceTest.php`
- **Frontend build**: `cd js && npm install && npm run build` — outputs `js/dist/forum.js` and `js/dist/admin.js`, which `extend.php` references. `npm` must run in `js/`, not the repo root. `dev`/`watch` also exist.
- Keep the compiled `js/dist/*.js` in sync — `extend.php` loads them directly.
- `composer.lock` and `vendor/` are gitignored. `flarum/core` is `^2.0@dev` (pre-2.0-stable), so `composer install --prefer-source` isn't special; just install normally.

## Frontend

- `js/tsconfig.json` maps `flarum/*` to `../vendor/flarum/core/js/dist-typings/*` — TypeScript typings only resolve after `composer install` populates `vendor/`.
- Admin settings live in `js/src/admin/extend.ts`; admin can be fully tested only inside a real Flarum install (it needs the running forum), so PHP unit tests are the primary verification path.

## Architecture / wiring

- Extensions are loaded via two mechanisms, both critical:
  - **`extend.php`** registers events, routes (the `/zai-bot/*` API controllers from `src/Api/Controller/`), models, locales, and settings. Settings keys use the `flarum-zai-bot.` prefix.
  - **Event listeners must be registered as plain class-name strings** (e.g. `->listen(Event::class, Listener::class)`), **not** `[Class::class, 'method']` arrays — Flarum calls `handle()`, and the array form is not a valid callable for non-static methods (startup `TypeError`). See the comment in `extend.php`.
- **Optional integrations** (fof/upload, ramon/stickers, flarum/likes, flarum/messages, etc.) are all loaded via `Extend\Conditional` and guarded with `class_exists()` at runtime — there are **no hard dependencies**. When touching integration code, check `class_exists()` guards are correct; a previous commit fixed systemic class_exists mistakes that silently disabled features.
- Replies are generated asynchronously via queue jobs (`src/Job/GenerateReplyForPost.php`, `src/Job/GenerateReplyForMessage.php`), dispatched from listeners. A running queue worker (`php flarum queue:work`) is required at runtime.
- Tools implement `src/Service/Tool/ToolInterface` and are built per-reply in `src/Job/Concerns/BuildsBotTools.php`.
- Models (`src/Model/`) map to `bot_memories`, `bot_context_events`, `bot_affinities`, `bot_user_portraits`, `bot_relations`, `bot_expressions` tables (see `migrations/`).
- `src/Console/` exists but is empty — no console commands.

## Testing gotchas

- Message-job tests need `flarum/messages` installed as a dev dependency (it is). Stubs for `ramon/stickers` and `fof/upload` are loaded in `tests/bootstrap.php` only when the real extensions aren't present — keep them in sync with whatever the tests touch.
- `BotAffinity` uses a `ResetsBotAffinities` trait in tests; model-backed tests rely on the exact schema in `tests/bootstrap.php` — if you change a model, update the bootstrap schema too.
- Settings and Guzzle HTTP calls are mocked with Mockery (see `AIServiceTest` for the canonical pattern); there is no real network access in tests.

## Deploy / runtime notes

- Memory system uses **pgvector** (`bot_memories.embedding` is `vector(1024)`) — only meaningful on a PostgreSQL DB with the extension; embedding dimension must match the configured embedding model.
- Migration `2026_08_30_...fix_bot_memories_created_at_type.php` repairs a botched `created_at` column type and is PostgreSQL-aware — keep new migrations DB-specific where needed.
- No CI workflow or linter config exists; `composer test` (`phpunit`) is the only automated check.
