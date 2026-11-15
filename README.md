# Acadly IQ-X

Education management for nurseries, primary schools, secondary schools and colleges. A product of IQ-X Technologies.

Each school runs its own deployment: its own application, database and storage.

| Folder | What it is |
|---|---|
| `app.backend/` | Laravel API. A modular monolith: `kernel/`, `core/`, `modules/` |
| `app.frontend/` | React + TypeScript + Tailwind workspace: `apps/console`, `apps/portal`, `packages/ui`, `packages/api-client`, `packages/kernel` |
| `contracts/openapi.json` | The contract between the two. Generated from the API. Committed |
| `docs/architecture/` | The system design. Start at its `README.md` |
| `docs/product/` | The product requirements |
| `PRODUCT.md`, `DESIGN.md` | Who the product is for, and how it looks ("The Register Book") |
| `infra/` | Local services |

## Run it

You need Laravel Herd (PHP 8.4 and Composer), Node 24 and pnpm (through `corepack`).

```bash
cd app.backend && composer install
```

PostgreSQL holds two roles: `acadly_owner` runs migrations and `acadly_app` runs the application. Set their names and passwords in `app.backend/.env` (see `.env.example`), then create the tables:

```bash
cd app.backend && composer migrate
```

Bring permissions and the administrator role into line with the code. Run this after every deploy:

```bash
cd app.backend && php artisan acadly:sync
```

Run the background worker and the scheduler. Events, seals and other after-commit work need both:

```bash
cd app.backend && php artisan queue:work
```

```bash
cd app.backend && php artisan schedule:work
```

Install the licence issued by IQ-X. A development machine with no licence key runs with every module licensed:

```bash
cd app.backend && php artisan acadly:licence path/to/licence.json
```

Create the first administrator of a new deployment. It asks for a password and runs only once:

```bash
cd app.backend && php artisan acadly:administrator head@example.org --name="Full Name"
```

Laravel Herd serves the API at https://api.acadly-iq-x.test. Set it up once:

```bash
cd app.backend && herd link api.acadly-iq-x && herd secure api.acadly-iq-x
```

```bash
cd app.frontend && corepack pnpm install && corepack pnpm dev
```

Open http://localhost:5173. The console asks the API for its health and shows the answer.

## Check it

```bash
cd app.backend && composer lint && php artisan test
```

```bash
cd app.frontend && corepack pnpm check
```

After a deploy, `/api/v1/ready` answers `ready` only once `acadly:sync` has run for that release.

After a change to a journey declaration, compile it and commit the files in `app.backend/blueprints/`. `composer lint` fails while a compiled file is out of date:

```bash
cd app.backend && php artisan blueprint:compile
```

Release a version when it goes to schools. From then on it cannot change; a change is a new version:

```bash
cd app.backend && php artisan blueprint:release key@YYYY.N
```

After a change to an API route or response:

```bash
cd app.backend && composer contract
```

```bash
cd app.frontend && corepack pnpm client
```

Commit `contracts/openapi.json` and `app.frontend/packages/api-client/src/schema.d.ts`. CI fails if either is out of date.

## Where the project stands

Stage 0 of `docs/architecture/08-roadmap.md` is built: the walking skeleton and its checks. Stage 1 is the kernel.
