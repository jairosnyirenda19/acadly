# 06 — Frontend

React, TypeScript, no admin framework. The design is ours to shape. The structure below keeps that freedom from turning into chaos.

## 1. Workspace

```
web/                                pnpm workspace
├── apps/
│   ├── console/                    Staff: admins, officers, teachers, librarians. Desktop-first, works on tablet
│   ├── portal/                     Guardians and learners. Mobile-first, small bundle, PWA
│   └── platform/                   IQ-X operators: tenants, plans, support grants, health
└── packages/
    ├── api-client/                 GENERATED from contracts/openapi.json. Never edited by hand
    ├── kernel/                     Auth, context, capabilities, vocabulary, journey UI, query setup, i18n
    ├── ui/                         The design system: tokens, primitives, patterns
    └── config/                     Shared tsconfig, ESLint, Tailwind preset
```

Three apps, not one. The portal must stay light for low-end phones and weak networks; the console can be richer. They share the kernel, the UI system and the API client, so they stay coherent.

## 2. Libraries

| Concern | Choice |
|---|---|
| Build | Vite |
| Routing | TanStack Router (typed routes and search params, route-level code splitting) |
| Server state | TanStack Query. The only cache for API data |
| Client state | React state and context first. A small store (Zustand) only for true cross-screen UI state |
| API | `openapi-typescript` types + `openapi-fetch` client, generated into `packages/api-client` |
| Forms | React Hook Form + Zod. Server validation errors are mapped onto fields from the problem+json `errors` |
| Styling | Tailwind CSS with design tokens as CSS variables |
| Primitives | Radix UI (unstyled, accessible). Wrapped once in `packages/ui`. Apps never import Radix directly |
| i18n | i18next. `en` first, `ny` ready. No user-facing string literal in a component |
| Offline | Workbox service worker in the portal and in the console's attendance capture. IndexedDB queue for attendance events (P1) |
| Reader | PDF.js and an EPUB engine behind a `ReaderAdapter` interface in the library module (engine chosen by spike, PRD §7.7) |
| Tests | Vitest + Testing Library; Playwright for the critical journeys |

Pin exact versions at scaffold time.

## 3. Module mirror

Each app mirrors the backend module keys. A screen belongs to exactly one module.

```
apps/console/src/
├── app/                      Shell: providers, layout, root routes, error boundaries
└── modules/
    ├── attendance/
    │   ├── routes/           Route components (thin)
    │   ├── components/       Module-private components
    │   ├── api/              Query and mutation hooks built on api-client
    │   ├── model/            Types, zod schemas, pure helpers
    │   └── index.ts          The module's public surface: its route tree and nav contribution
    ├── admissions/ …
    └── library/ …
```

- A module imports from `packages/*` and from its own folder. **A module never imports another module's internals.** Enforced by `eslint-plugin-boundaries`.
- A module's routes are lazy-loaded. If the module is not active for the user's division, its code is never downloaded and its routes do not exist.

## 4. The app is built from the context payload

On sign-in the SPA calls `GET /api/v1/me/context` and gets: memberships, the selected institution, its divisions and campuses, active modules, vocabulary, permissions per scope and navigation (03 §5.8). The kernel package turns that into hooks:

```tsx
const { institution, division, campus, switchCampus } = useScope();
const can = useCan();                       // can('attendance.capture', { classId })
const t   = useVocabulary();                // t('learner') → "Student" | "Pupil" | "Child"
const on  = useModule('library');           // boolean
```

```tsx
<Can permission="result.publish" scope={{ divisionId }}>
  <PublishResultsButton />
</Can>
```

**UI rules that mirror the backend laws**

1. The UI never checks a role name. It checks a permission.
2. The UI never checks an education level or a profile name. It checks a module, a binding value sent by the API, or the vocabulary.
3. The UI never compares a journey state literal to decide what to show. It renders `available_actions` from the API.
4. Hiding is for comfort. The API decides. Every 403 and every `step_not_admissible` is handled gracefully.

## 5. Journey UI is generic

`packages/kernel/journey` ships components that work for any journey subject:

| Component | Renders |
|---|---|
| `<JourneyActions resource={…} />` | A button per available step. A blocked step is shown disabled with its `blocked_reason` and `remedy` |
| `<JourneyLadder resource={…} />` | The progress ladder from the API's `ladder` |
| `<JourneyHistory resource={…} />` | Step completions: who, when, outcome |
| `useJourneyStep()` | The mutation: sends `Idempotency-Key`, handles problem+json, invalidates the right queries |

A module adds only what is special: the input form for a step outcome (for example, the rejection reason).

## 6. Design system

**The visual system is fixed in `DESIGN.md` at the project root** ("The Register Book"), approved by the owner (D-022). Product context is in `PRODUCT.md`. `DESIGN.md` wins over anything in this section where the two differ; this section describes only how the system is built in code.

The brand comes from the Acadly IQ-X mark: deep navy and teal, with a red pen and marigold as working colours.

```
tokens (CSS variables)   →   primitives (Button, Field, Dialog…)   →   patterns (DataTable, PageHeader, FormSection, EmptyState, RegisterGrid)
```

- **Tokens:** the names and values in the `DESIGN.md` frontmatter, exported as CSS variables from `packages/ui`. Radius is 0 and there are no shadow tokens. Light theme first; tokens are structured so a dark theme is a variable swap.
- **Semantic tokens over raw colours.** Components use `--color-surface`, `--color-text-muted`, `--color-accent`, never a hex value.
- **Accessibility is part of "done":** keyboard paths, visible focus, labelled fields, contrast AA, status never shown by colour alone (PRD §13.5).
- **Density modes:** comfortable (portal, touch) and compact (console tables).
- **Speed for the daily task.** The class register is the most-used screen in the product. It gets its own pattern (`RegisterGrid`): one tap per learner, bulk "all present", works offline, visible sync state.
- Apps do not write one-off styled components for things that are patterns. If a pattern is missing, add it to `packages/ui`.

## 7. Data fetching rules

- All API calls go through generated types. A hand-written `fetch` to the API fails lint.
- Query keys are built by a factory per module and always include the institution id, so switching institution can never show the previous one's data.
- Mutations invalidate by key; no manual cache surgery unless measured.
- Lists use cursor pagination and server-side filters. No "load everything then filter".
- Errors: one error boundary per route, one toast channel, field errors on fields.

## 8. Performance budget (portal)

- First load usable on a low-end Android phone on a slow connection: route-level splitting, no heavy charting or reader code until needed.
- The reader engines load only inside the reader route.
- Images are sized and lazy. Fonts are self-hosted and subset.
- Budgets are checked in CI (bundle size limit per app entry).
