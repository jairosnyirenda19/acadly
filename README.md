# Acadly IQ-X

Education management for nurseries, primary schools, secondary schools and colleges. A product of IQ-X Technologies.

| Folder | What it is |
|---|---|
| `api/` | Laravel API. A modular monolith: `kernel/`, `core/`, `modules/` |
| `web/` | React + TypeScript + Tailwind workspace: `apps/console`, `apps/portal`, `apps/platform`, `packages/ui`, `packages/api-client`, `packages/kernel` |
| `contracts/openapi.json` | The contract between the two. Generated from the API. Committed |
| `docs/architecture/` | The system design. Start at its `README.md` |
| `docs/product/` | The product requirements |
| `PRODUCT.md`, `DESIGN.md` | Who the product is for, and how it looks ("The Register Book") |
| `infra/` | Local services |

## Run it

You need PHP 8.4, Composer, Node 24 and pnpm (through `corepack`).

```bash
cd api && composer install && php artisan serve
```

```bash
cd web && corepack pnpm install && corepack pnpm dev
```

Open http://localhost:5173. The console asks the API for its health and shows the answer.

## Check it

```bash
cd api && composer lint && php artisan test
```

```bash
cd web && corepack pnpm check
```

After a change to an API route or response:

```bash
cd api && composer contract
```

```bash
cd web && corepack pnpm client
```

Commit `contracts/openapi.json` and `web/packages/api-client/src/schema.d.ts`. CI fails if either is out of date.

## Where the project stands

Stage 0 of `docs/architecture/08-roadmap.md` is built: the walking skeleton and its checks. Stage 1 is the kernel.
