# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Stack

React 19 + TypeScript (strict), Vite, pnpm workspace with three apps (`console` for staff, `portal` for guardians and learners, `platform` for IQ-X operators). Tailwind CSS with design tokens as CSS variables. Radix UI primitives wrapped once in `app.frontend/packages/ui`. Laravel API behind `/api/v1`. No Filament, no Livewire, no Inertia. Details: `docs/architecture/06-frontend.md`.

## Users

**Primary: the teacher.** Marks the class register and enters marks every school day. Often on a phone, often standing in a classroom, often in a hurry, sometimes with a weak or absent connection. Digital skill varies widely. When two needs conflict, the teacher's daily task wins. If teachers do not use Acadly, the school has no data and nothing else in the product works.

**The school office.** Institution and campus administrators, admissions officers, registrars and academic officers, headteachers. Work at a desk for long stretches on a laptop or desktop. They configure the school, process admissions, move learners, approve and publish results. They are also the buyer.

**The guardian.** Checks a child's attendance, results and notices on a low-cost Android phone with expensive data. Did not choose the product. May have several children across nursery, primary and secondary in the same school. Often shares the phone with other family members.

**The learner.** Older learners and college students view their own record and read library resources.

**Others:** librarians, auditors (read-only), IQ-X platform operators.

## Product Purpose

Acadly IQ-X is an education management platform, deployed once per school, for Early Childhood Development centres, primary schools, secondary schools and colleges. It replaces paper registers, spreadsheets and informal messaging groups with one trusted record of each learner: admission, enrolment, guardians, attendance, assessments, results, notices and library reading.

Success is that an institution runs a real academic period through Acadly: registers are taken in it every day, results are published from it, and guardians see both without asking the school.

Product promise: **Manage education operations. Connect institutions and families. Make learning resources accessible.**

## Positioning

- **One learner, one record, one identifier** from nursery to the day they complete or leave, across every level and every campus of the school. Moving up a level, moving from primary to secondary, or moving campus never creates a new record and never loses history.
- **One platform that reshapes itself per level.** A nursery, a primary school, a secondary school and a college each get their own words, structure and modules from a Profile Blueprint. A school is never shown or sold a module it does not need.
- **History is never rewritten.** Attendance and published results are append-only. A correction is a new, attributed entry beside the original.
- **Built for imperfect connectivity and shared, low-cost phones**, not adapted to them afterwards.

## Operating Context

- Malawi first: private ECD centres, primary and secondary schools, and school groups with several campuses. Colleges follow.
- The academic calendar rules the rhythm: terms or semesters, daily registers, end-of-term results, year-end progression.
- Staff use the console on laptops in an office and on phones in classrooms. Guardians use the portal on small Android phones.
- SMS is the reliable way to reach a guardian and it costs money per message.
- Phones are often shared. A message or a lock-screen notification can be read by someone else.
- English first. Chichewa (`ny`) must be possible without redesign.
- The same person is called Child, Pupil or Student depending on the division. The interface takes these words from the division's vocabulary; they are never hardcoded.

## Capabilities and Constraints

MVP capabilities (PRD §1): institution, division and campus setup; academic years, terms, classes, streams and subjects; admissions; learner records; guardian relationships; staff and scoped roles; attendance capture, correction and reports; basic assessments, results and report cards; guardian and learner portal; announcements and notifications; a digital library with a browser reader for PDF and EPUB.

Constraints that shape every screen:

- **The interface is built from what the server allows.** It shows actions the API returns as available, with the reason when one is blocked. It never decides by role name, education level or status text.
- **Scope is always visible.** Staff work inside an institution, a division and a campus; they must always be able to see and switch which one.
- **Multi-step processes are journeys** with named outcomes, a visible ladder and a stated reason when a step cannot happen yet.
- **Draft is never shown as published.** Reports and results must make the difference obvious.
- **Status is never shown by colour alone.**
- **Offline attendance** is a planned capability; the register must show its sync state honestly.
- **No user-facing text is hardcoded.** All of it goes through translation and vocabulary.

Excluded from the MVP: fees and payments, timetable generation, transport, hostel, biometrics, location tracking, predictive risk labels, native mobile apps.

Undecided: EPUB reader engine; PWA offline scope for the first pilot; whether SafeRelease and NFC attendance are in the first release.

## Brand Commitments

- **Name:** Acadly IQ-X, a product of IQ-X Technologies. Module names in use: EduTrack (attendance), EduLibrary (digital library), SafeRelease (child collection).
- **Voice: calm and exact.** Like a trusted head teacher. Plain words, short sentences, no jokes, no hype, no exclamation marks. Warm, but serious about children's records. It says what happened and what to do next.
- **Register: professional on serious matters** (owner rule, 14 November 2026). Status, security, access, audit, results, attendance, safeguarding, errors and system messages use clean, professional terms. No conversational or playful phrasing ("The API says", "asks", "look here", "chatty" asides). Preferred: "Service status", "Operational", "Unavailable", "Awaiting decision", "Access refused", "Check again".
- **Disclose nothing about the build.** No screen, response, header or error message names the framework, the language, the server, internal file paths, module lists, release identifiers or developer commands. A public status says only whether the service is available.
- **Character: a balance of boldness and professionalism** (owner's words). Confident and distinct, never playful at the cost of trust.
- **Logo: draft.** The name and the navy-and-teal idea are kept. The mark (an "A" with a graduation cap and a rising path) may be redrawn to fit the interface. Source image supplied by the owner; not yet stored in the repository.
- **Safe on a shared phone.** SMS and push text never carries marks, health or custody detail. It points to the portal.

## Evidence on Hand

- `docs/product/Acadly_IQ-X_Product_Requirements_Document.md` — requirements, roles, permission matrix, acceptance criteria.
- `docs/product/IQ-X_Education_Platform_Concept.md` — concept, market, competitors named by the owner.
- `docs/architecture/` — the system design, including `06-frontend.md` and `09-learner-lifecycle.md`.
- The draft logo image (navy and teal).

There are no customers, pilots, testimonials, usage figures, screenshots or prices yet. Future work must not invent any.

## Product Principles

1. **The register comes first.** The teacher's daily task must be the fastest, calmest thing in the product. Every other screen is judged after it.
2. **Say what is true, including what is not finished.** Draft, unsynced, blocked and failed states are shown plainly, with the reason and the next step.
3. **One child, one story.** Any view of a learner reads as one continuous record, whatever level or campus they are in now.
4. **The school's words, not ours.** Vocabulary, structure and modules come from the division. The product never forces a college's language on a nursery.
5. **Careful with children's data.** Show the least that does the job, to the person entitled to see it, in a place that is safe to read it.

## Accessibility & Inclusion

- WCAG 2.2 AA as the floor: contrast, visible focus, full keyboard paths in the console, labelled fields, meaningful error messages.
- Touch targets sized for one-handed phone use in the register and the portal.
- Works on low-end Android devices and slow, costly connections: small first load, no heavy assets on the daily path.
- Designed for varying digital literacy: plain labels over icons alone, one clear primary action per screen.
- Status and state are carried by text or shape as well as colour.
- English and Chichewa; layouts must tolerate longer strings.
