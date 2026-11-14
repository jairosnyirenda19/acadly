# 07 — Engineering rules

Short, numbered, enforceable. Each rule names the check that guards it. A rule with no automated check is marked **(review)** and is a candidate for automation.

## A. Structure

| # | Rule | Guard |
|---|---|---|
| A1 | Dependencies point one way: Modules → Core → Kernel | Deptrac + Pest architecture tests |
| A2 | Kernel names no module, no domain noun, no education level | Architecture test (forbidden namespaces and words under `kernel/`) |
| A3 | Another module's code is reachable only through its `Contracts/` | Deptrac |
| A4 | Module dependencies are declared in the manifest and form an acyclic graph | `acadly:lint` |
| A5 | No Eloquent relation crosses a module boundary | Architecture test |
| A6 | Every module has the standard folder shape | `acadly:lint` |
| A7 | `app.backend/app/` holds bootstrap only. No business logic | Architecture test |

## B. Writing behaviour

| # | Rule | Guard |
|---|---|---|
| B1 | Actions are the only writers. Controllers, jobs, listeners and commands call Actions | Architecture test (models' write methods only callable from `Application/Actions` and the journey engine) |
| B2 | One Action, one use case, one transaction | (review) |
| B3 | No business logic in model events or observers | Architecture test (no `booted()` hooks beyond id and scope) |
| B4 | Controllers are thin: validate, call, return a Resource | PHPStan rule (controller method length / dependencies), (review) |
| B5 | Typed data objects cross layers. No raw arrays below the controller | PHPStan max level |
| B6 | External I/O (mail, SMS, HTTP, webhooks) runs after commit, on the queue | Architecture test + journey conformance suite |
| B7 | Every retried operation is idempotent and keyed | Conformance suite, (review) |
| B8 | Time comes from the injected `Clock`. No `now()` in domain code | Architecture test |

## C. Blueprints

| # | Rule | Guard |
|---|---|---|
| C1 | No code branches on education level or profile key. Use a binding | Architecture test (`ProfileKey` usable only under `app.backend/blueprints/`) |
| C2 | A multi-state process with rules is a Journey. Its state changes only through `JourneyEngine::perform()` | Database trigger on state columns + architecture test |
| C3 | Gates are pure and return a reason and a remedy | Conformance suite |
| C4 | Effects are idempotent and declare their boundary | Conformance suite |
| C5 | Released blueprint versions never change | `blueprints.lock` hash check in CI |
| C6 | Nothing declared stays unexecuted | `blueprint:lint` |
| C7 | Outcomes are named. No boolean approve/reject | `blueprint:lint` (minimum naming), (review) |
| C8 | No bare-string ids for states, steps, gates, effects, permissions or topics | PHPStan + lint |

## D. Tenancy and access

| # | Rule | Guard |
|---|---|---|
| D1 | Every tenant-owned table is created with `tenantOwned()` and has an RLS policy | `acadly:lint` (schema inspection) |
| D2 | Foreign keys between tenant-owned tables include `tenant_id` | `acadly:lint` |
| D3 | Scope is resolved on the server. A client-sent id is only a selector | Isolation test suite |
| D4 | Every route declares its permission and module guard, or is explicitly marked public | Route linter |
| D5 | Every route has a generated cross-tenant and cross-scope test | CI fails on an uncovered route |
| D6 | Permissions are enum cases from a manifest. No string literals | Lint |
| D7 | Entitlement and permission are separate checks. Both must pass | `Access::check()` is the only gate; architecture test forbids `Gate::` / `->can()` elsewhere |
| D8 | Guardian access exists only through an approved relationship row | Feature tests (PRD scenario C) |
| D9 | Support access needs a live grant and is always audited | Feature tests |

## E. Data

| # | Rule | Guard |
|---|---|---|
| E1 | Primary keys are UUIDv7 | `acadly:lint` |
| E2 | Record-plane tables are append-only | Database trigger via `appendOnly()` |
| E3 | Corrections are new rows that reference the original | (review) + attendance tests |
| E4 | Summaries are rebuildable from records | A rebuild command exists and is tested per summary |
| E5 | JSONB is for variable configuration and snapshots only | (review) |
| E6 | Sensitive changes write an audit event in the same transaction | Feature tests per Action marked `#[Audited]` |
| E7 | No personal data in logs, error reports or SMS beyond the minimum | Log scrubber + template linter |
| E8 | Every migration is forward-only in production and states its retention class | Migration linter |

## F. Notifications

| # | Rule | Guard |
|---|---|---|
| F1 | A topic is one class. Call sites never name recipients or channels | Architecture test (only `Notifier::send(Topic)` is public) |
| F2 | Code catalogue and database catalogue must match before a release is ready | Readiness probe hash check |
| F3 | Wording uses only tokens the topic declares. SMS/push wording exists and fits its budget | `catalogue:lint` |
| F6 | Staff are told by subscription, never by a role or permission query | Architecture test on audience resolvers |
| F7 | The actor is never told about their own action | Dispatcher test |
| F4 | Notification failure never fails the business transaction | Conformance suite |
| F5 | Paid channels are metered | Feature tests |

## S. Search and audit

| # | Rule | Guard |
|---|---|---|
| S1 | Every module ships a search source, or is on the exception list with a reason | Search sources test |
| S2 | A source the reader may not use is absent, never empty. No counts of hidden things | Generated search harness |
| S3 | A source matches only fields its list shows this reader. Hidden fields typed exactly find nothing | Generated search harness |
| S4 | A hit is a `SearchHit` built by hand. Never a serialised model | PHPStan + architecture test |
| S5 | The typed text is written to no log, audit line or analytics | Architecture test on the search controller |
| S6 | Opening a place to a kind of reader changes the search walk test on purpose | Search walk test |
| S7 | Every Action declares its audit action or `#[NotAudited('why')]` | Architecture test |
| S8 | A state change and its audit line are written in one transaction | Conformance test |
| S9 | Audit lines are never updated or deleted | Database trigger and role privileges; `audit:verify` in CI on the test database |

## P. Disclosure and wording

| # | Rule | Guard |
|---|---|---|
| P1 | The public health endpoint returns `{"status":"ok"}` and nothing else | Feature test (exact JSON) |
| P2 | No response discloses the framework, language or server: no default pages, no `X-Powered-By`, every error is JSON | Feature test; web server configuration for the `Server` header |
| P3 | No screen shows release identifiers, module lists, file paths or developer commands to an end user | (review) |
| P4 | API documentation is not served outside local development | Scramble access gate; deployment check |
| P5 | Serious matters use professional terms; no conversational phrasing | (review) against `PRODUCT.md` |

## G. API and frontend

| # | Rule | Guard |
|---|---|---|
| G1 | `contracts/openapi.json` is generated and committed. Drift fails CI | CI job |
| G2 | The frontend calls the API only through the generated client | ESLint |
| G3 | The UI checks permissions, modules and bindings — never role names, never education levels, never state literals | ESLint custom rules |
| G4 | A frontend module never imports another module's internals | `eslint-plugin-boundaries` |
| G5 | No user-facing string literal in components. Use i18n and vocabulary | ESLint |
| G6 | Components use semantic tokens. No raw hex colours | Stylelint / ESLint |
| G7 | Accessibility checks pass on critical routes | Playwright + axe |

## H. Process

| # | Rule |
|---|---|
| H1 | A feature is done only when it meets the PRD definition of done (§22.2): rules in the owning domain, server-side authorisation, scope tests, validation, audit, tests, docs, accessibility, failure paths |
| H2 | An architectural choice is written in `DECISIONS.md` in the same pull request |
| H3 | A new sensitive field or access path needs a privacy note in the pull request |
| H4 | A new dependency needs a line in `DECISIONS.md`: why, licence, maintenance state |
| H5 | If a rule here blocks good work, change the rule in a pull request. Do not work around it |

## Quality gates in CI

```
api:   pint --test · phpstan (max) · deptrac · pest (unit, feature, architecture, isolation, conformance)
       acadly:lint · blueprint:lint · catalogue:lint · openapi drift check
web:   tsc --noEmit (strict) · eslint · vitest · bundle budget · playwright (critical journeys)
```

## The one command

`php artisan acadly:lint` runs every structural linter (modules, schema, routes, blueprints, catalogue). It must be green before a pull request is opened.

## Named anti-patterns (say the name in review)

| Name | Looks like |
|---|---|
| **Level Conditional** | `if ($division->isSecondary())`, `match ($profile)` — a binding that was not declared |
| **Boolean Outcome** | `approve(bool $ok)` — outcomes have names and different consequences |
| **Persistence Trigger** | Domain work inside `saved`/`created` |
| **Shared Status Enum** | One status enum reused by different journeys |
| **Permission That Is A Gate** | "Fee unpaid" or "period closed" modelled as a permission |
| **Reach-Around** | Importing another module's model instead of its Contract |
| **Menu Security** | Hiding a button and calling it access control |
| **Call-Site Audience** | Passing recipients into a notification call |
| **Presentation Ladder** | A hardcoded progress list in the UI |
| **Client-Trusted Scope** | Using a tenant or institution id from the request without a membership check |
| **Mutable History** | Updating an attendance event or a published result in place |
| **JSON Drawer** | Core relationships hidden in a JSONB column |
