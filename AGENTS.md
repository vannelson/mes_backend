# Repository Guidelines

## Project Structure & Module Organization
- Backend lives in `app/` (Laravel 12) with HTTP, console, and model code; configuration sits in `config/`.
- Routes: `routes/web.php` for web UI (Inertia/Vue) and `routes/api.php` for API endpoints; keep feature-specific route groups close together.
- Frontend: `resources/js` (Vue 3 + TypeScript via Vite) and `resources/css`; Blade templates in `resources/views`.
- Data: migrations and seeders in `database/migrations` and `database/seeders`; SQLite stub created by setup scripts.
- Public assets build to `public/`; long-lived storage and cache outputs stay under `storage/`.
- Tests live in `tests/Feature` and `tests/Unit`; extend the shared `tests/TestCase.php`.

## Build, Test, and Development Commands
- `composer setup`: install PHP deps, create `.env` if missing, generate app key, run migrations, install Node deps, and build assets.
- `composer dev`: run Laravel server, queue listener, pail logs, and Vite dev server concurrently for local work.
- `composer dev:ssr`: same as above but also starts Inertia SSR after building.
- `npm run dev`: Vite dev server only (useful when API already running).
- `npm run build` / `npm run build:ssr`: production asset bundles (client and optional SSR).
- `npm run lint`: ESLint with Vue/TS config; `npm run format` or `npm run format:check` for Prettier.
- `composer test`: clears config cache then runs the PHP test suite (`php artisan test`).

## Coding Style & Naming Conventions
- Default indentation is 4 spaces; YAML uses 2 spaces; LF line endings per `.editorconfig`.
- PHP follows Laravel/PSR-12 defaults; favor explicit types and early returns.
- Vue/TS: single quotes, semicolons on, 80-char print width, 4-space tabs; organize imports and Tailwind classes via Prettier plugins.
- Component files in `resources/js` should use PascalCase; disable multi-word name rule is intentional, but prefer descriptive names.
- Keep front-end utilities and UI primitives in `resources/js/components` or `resources/js/lib` to avoid scattering shared logic.

## Testing Guidelines
- Add Feature tests for HTTP/API flows and Unit tests for pure logic; place fixtures close to the test file.
- Name tests `*Test.php` and mirror the namespace of the subject under test.
- Before pushing, run `composer test`; for front-end behavior, add assertions through Inertia responses or PHP-side validation where applicable.
- Seed test data with factories instead of raw inserts; prefer request/response assertions over DOM snapshots.

## Commit & Pull Request Guidelines
- Use concise, present-tense messages (e.g., `Add work order filters`); keep related changes in one commit when feasible.
- PRs should describe intent, key changes, and migration/ops impact; link issues or task IDs.
- Include testing notes (commands run), screenshots of UI changes (if applicable), and call out new env vars or queues/jobs added.

## Security & Configuration Tips
- Keep secrets in `.env`; never commit it. After cloning, run `php artisan key:generate` if the key is missing.
- Run `php artisan migrate --force` for deploys; ensure queues (`php artisan queue:listen`) run wherever async jobs are expected.
- Clear caches (`php artisan config:clear`) when toggling environment-sensitive settings; avoid editing files in `vendor/` or `public/` directly.
