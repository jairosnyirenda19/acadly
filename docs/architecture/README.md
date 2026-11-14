# Acadly IQ-X — Architecture

**Status:** Baseline v1.0 · 14 November 2026
**Scope:** The backbone. Structure, patterns and rules that every later feature must follow.
**Sources:** `docs/product/Acadly_IQ-X_Product_Requirements_Document.md`, `docs/product/IQ-X_Education_Platform_Concept.md`, and the Track Blueprint pattern from MCRS.

This folder is the law of the codebase. If code and these documents disagree, one of them is a bug. Fix it in the same pull request.

## Reading order

| # | Document | Answers |
|---|---|---|
| 01 | [Foundations](01-foundations.md) | Stack, repository layout, the module system, the module catalogue |
| 02 | [Blueprints](02-blueprints.md) | Profile Blueprints (what a school *is*) and Journey Blueprints (how a record *moves*) |
| 03 | [Deployment, campus and access](03-deployment-campus-access.md) | One deployment per school; Institution → Division / Campus; identity, roles, licence |
| 04 | [Notification catalogue](04-notification-catalogue.md) | Topics, audiences, channels, preferences, delivery ledger |
| 05 | [Data and API](05-data-and-api.md) | UUIDs, table rules, append-only records, events, API contract |
| 06 | [Frontend](06-frontend.md) | React workspace, design system, capability-driven UI |
| 07 | [Engineering rules](07-engineering-rules.md) | The numbered rules and the automated checks that enforce them |
| 08 | [Roadmap](08-roadmap.md) | Build order for the backbone |
| 09 | [Learner lifecycle](09-learner-lifecycle.md) | One learner, one ID: progression between levels and divisions, campus transfer |
| 10 | [Audit trail](10-audit-trail.md) | Who did what, who looked, old → new, hash chain, nightly seal, watchers |
| 11 | [Assessment](11-assessment.md) | Assessment kinds, the grading plan, continuous assessment or exam only, the computed term grade |
| 12 | [Search everything](12-search.md) | One search bar, one source per module, results that never leak what a reader cannot open |
| — | [Decisions](DECISIONS.md) | Decision log (D-numbers). Add to it; never rewrite history |

## The system on one page

```
┌──────────────────────────── app.frontend/ (React + TypeScript) ────────────────────────────┐
│  apps/console (staff)               apps/portal (guardian, learner)                │
│  packages/ui (design system) · packages/api-client (generated) · packages/kernel   │
└───────────────────────────────────────┬───────────────────────────────────────────┘
                                        │  /api/v1  (OpenAPI is the contract)
┌───────────────────────────────────────┴───────────────────────────────────────────┐
│ app.backend/ (Laravel, modular monolith)                                                    │
│                                                                                     │
│  MODULES   Admissions · Attendance · Assessment · Library · SafeRelease · …         │
│            optional, enabled per division, talk through Contracts + events          │
│  ─────────────────────────────────────────────────────────────────────────────     │
│  CORE      Institution · People · Academics · Enrolment · Guardians · Staff         │
│            always on                                                                │
│  ─────────────────────────────────────────────────────────────────────────────     │
│  KERNEL    Tenancy · Identity · Access · Blueprint · Notify · Audit · Events ·      │
│            Files · Support        (knows no module, knows no education level)       │
└───────────────────────────────────────┬───────────────────────────────────────────┘
                                        │
   One deployment per school: PostgreSQL (UUIDv7) · Redis · S3-compatible private storage
```

## The six ideas that carry the system

1. **Three layers, one direction.** Kernel ← Core ← Modules. A lower layer never imports a higher one.
2. **Modules are sealed.** A module exposes a `Contracts/` folder and domain events. Nothing else is importable.
3. **Profile Blueprints replace "school type" conditionals.** ECD, Primary, Secondary and Tertiary are versioned declarations. Code asks the blueprint *what applies*. Code never asks *which level is this*.
4. **Journey Blueprints replace status spaghetti.** Every multi-step process is a versioned declaration run by one engine that answers four questions: admissibility, authority, consequence, commitment.
5. **One deployment per school.** Each school has its own application, database and storage. Inside it, scope (division, campus, class) is resolved on the server, and the application role cannot change the schema or alter history.
6. **Everything downstream is generated.** Permissions, notification topics, navigation, API types, diagrams and conformance tests come from declarations. Hand-maintained copies are defects.
