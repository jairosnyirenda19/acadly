# Acadly IQ-X
## Product Requirements Document (PRD)

**Document status:** Draft for product and engineering baseline  
**Version:** 1.0  
**Date:** 9 November 2026  
**Product owner:** IQ-X Technologies  
**Product:** Acadly IQ-X — Education Management and Learning Platform  
**Document purpose:** Define the MVP scope, users, workflows, permissions, functional and non-functional requirements, acceptance criteria, domain boundaries, and digital library direction.

---

## 1. Executive summary

Acadly IQ-X is a configurable, multi-institution education management platform for Early Childhood Development (ECD), primary and secondary schools, colleges, and other learning institutions. It provides a shared operational foundation for managing learners, guardians, staff, academic structures, attendance, communication, assessments, and institutional records.

The product is designed as a commercial platform that institutions can subscribe to and configure. It is **not** initially a national education registry and does not depend on integrations with Malawi's National Registration Bureau (NRB), Unified Beneficiary Registry (UBR), or a national education identifier.

Acadly will use its own persistent learner identifier and institution-scoped identifiers. Future integrations may be added through controlled APIs where an institution has a lawful basis and the necessary authorisation.

The first release should prove a complete, valuable institutional workflow rather than attempt to digitise every school process. The MVP will focus on:

1. Institution and campus setup.
2. Academic years, terms, classes, streams and subjects.
3. Admissions and learner registration.
4. Guardian and family relationships.
5. Staff accounts and role-based access.
6. Attendance capture, review and reporting.
7. Basic assessments, results and report cards.
8. Guardian-facing access to relevant learner information.
9. Notifications through an extensible provider layer.
10. A controlled digital library catalogue and browser reading experience for PDF and EPUB resources, subject to content rights.

Fees, payments, advanced timetable generation, transport, hostel management, advanced analytics, biometrics, predictive risk scoring, and complex national integrations are not MVP requirements.

### 1.1 Product promise

**Manage education operations. Connect institutions and families. Make learning resources accessible.**

### 1.2 Product principles

- **Institution-first:** each institution controls its own operational records and policies.
- **One platform, configurable workflows:** ECD, schools and colleges share a core, while features are enabled by institution type and subscription.
- **Privacy and safeguarding by design:** collect only what is needed, restrict access, and audit sensitive actions.
- **Reliable in imperfect connectivity:** attendance capture and selected workflows must tolerate temporary network loss.
- **Clear domain ownership:** Acadly owns institutional and academic administration; Stride IQ-X remains the specialist LMS.
- **Open integration boundaries:** use documented APIs and events rather than tightly coupling modules.
- **Human accountability:** automated indicators may assist staff later, but decisions affecting learners remain subject to human review.
- **Commercial sustainability:** subscriptions and optional services must cover hosting, support, messaging and onboarding costs.

---

## 2. Product scope

### 2.1 Product domains

| Domain | Responsibility | MVP |
|---|---|---:|
| Institution management | Tenant, institution, campus and configuration | Yes |
| Identity and access | Users, roles, permissions, authentication | Yes |
| Academic structure | Academic years, terms, grades, classes, streams, subjects | Yes |
| Admissions | Applications and admission decisions | Yes |
| Learner records | Learner profile, enrolment and status | Yes |
| Guardian relationships | Guardians, authorised relationships and access | Yes |
| Staff records | Staff profiles and institutional assignments | Yes |
| Attendance | Sessions, attendance events, corrections and reports | Yes |
| Assessment | Assessment definitions, marks and results | Basic |
| Communication | Announcements and notifications | Basic |
| Parent/guardian portal | View authorised learner information | Yes |
| Digital library | Catalogue, access rules, PDF/EPUB reader, progress | Basic |
| Stride LMS integration | Identity/course linking and learning progress | Later |
| Fees and payments | Billing, balances, receipts and reconciliation | Later |
| Timetabling | Periods, rooms and timetable generation | Later |
| Transport | Routes, vehicles, boarding and drop-off | Later |
| Hostel | Accommodation and occupancy | Later |
| Advanced analytics | Cross-period insights and configurable dashboards | Later |
| National integrations | NRB, UBR or national learner registry | Out of initial scope |

### 2.2 MVP exclusions

The following are explicitly excluded from the first production MVP:

- National learner identity or national enrolment reconciliation.
- Direct NRB or UBR integration.
- Facial recognition, fingerprint attendance or other biometric identification.
- Continuous learner or guardian GPS tracking.
- Automated dropout prediction or opaque learner-risk labels.
- Full accounting or ERP functionality.
- Payroll and statutory payroll calculations.
- Complex fee invoicing, payment gateway settlement and accounting reconciliation.
- Automated timetable optimisation.
- Transport fleet management and live vehicle tracking.
- Hostel management.
- Native mobile applications, unless a pilot proves a specific capability cannot be delivered reliably through responsive web/PWA.
- Full LMS authoring, quizzes, assignment grading and course delivery inside Acadly.
- Unrestricted downloading or redistribution of copyrighted library content.
- Automated OCR-to-EPUB conversion as a standard ingestion path.
- Digital rights management implementation from scratch.

MVP scope may be adjusted after institution discovery and pilot validation. Changes must be recorded in the product backlog and approved by the product owner.

---

## 3. Target institutions and configuration

Acadly should support different institution types without creating separate products or codebases.

### 3.1 Institution profiles

**ECD**
- Children/learners, guardians, rooms or groups, caregivers/teachers.
- Daily attendance and collection/release workflows.
- Simple progress notes and communication.
- Age-appropriate resource collections.

**Primary and secondary schools**
- Grades/forms, streams, subjects, teachers and class teachers.
- Term-based assessments, marks, reports and attendance.
- Guardian portal and announcements.
- Optional NFC attendance and child release workflows.

**Colleges and tertiary institutions**
- Departments, programmes, academic levels, semesters and course registration.
- Course/session attendance and student academic records.
- Student portal and later LMS integration.
- The MVP should not attempt to deliver a full university student information system.

### 3.2 Configuration rules

- Institution type determines the default terminology and available setup templates.
- Institution administrators can configure terminology where safe (for example, Grade, Form, Class or Level).
- Features are enabled by subscription entitlement and institution configuration.
- A feature being hidden in the interface is not a security control; backend authorisation must also enforce entitlements and permissions.
- Institution-specific configuration must not fork the shared application logic.

---

## 4. Tenancy and organisational model

### 4.1 Core hierarchy

Recommended conceptual hierarchy:

```text
Acadly Platform
└── Tenant (customer organisation / subscription boundary)
    ├── Institution A
    │   ├── Campus 1
    │   └── Campus 2
    └── Institution B
        └── Campus 1
```

A tenant may operate one or more institutions. An institution may operate one or more campuses. Some customers may have one tenant and one institution; the model must not assume that this is always the case.

### 4.2 Tenancy requirements

- Every tenant-owned record must be associated with a tenant.
- Institution-owned records must also identify their institution.
- Campus-specific records must identify a campus where applicable.
- A user may have memberships in more than one institution or tenant, but access must be explicit and scoped.
- Tenant context must be established server-side from an authenticated membership or trusted platform administration context.
- Client-supplied tenant IDs must never be trusted as the sole authorisation check.
- Cross-tenant queries and mutations must be prevented by application policies and automated tests.
- PostgreSQL row-level security may be used as an additional isolation layer, but it does not replace application authorisation.
- Platform support access must be time-limited, justified, logged and revocable.

### 4.3 Tenant lifecycle

MVP states:
- `pending_setup`
- `active`
- `suspended`
- `closed`

Suspended tenants may be restricted from normal operations while retaining access to agreed export and account-resolution processes. Closure and deletion must follow retention, contractual and legal requirements; do not hard-delete records indiscriminately.

---

## 5. Users, personas and roles

A person may hold more than one role. Role assignment is scoped to a tenant, institution or campus. A role is not a global property of a person.

### 5.1 User personas

| Persona | Primary need |
|---|---|
| Platform administrator | Operate the SaaS platform and support customers |
| Tenant owner | Manage subscription and tenant-wide settings |
| Institution administrator | Configure and manage an institution |
| Campus administrator | Manage assigned campus operations |
| Admissions officer | Process applications and enrolments |
| Registrar / academic officer | Maintain academic structures and learner records |
| Headteacher / principal | Oversee school operations and approve selected actions |
| Teacher / lecturer | View assigned classes and record attendance/results |
| Attendance officer | Capture and review attendance |
| Librarian / resource manager | Catalogue resources and manage access |
| Guardian / parent | View only linked learners and permitted information |
| Learner / student | View own profile, attendance, results and resources |
| Auditor / read-only officer | Review permitted records and audit history |
| IQ-X support operator | Provide controlled technical support |

### 5.2 MVP roles

| Role | Scope | Main capabilities |
|---|---|---|
| `platform_admin` | Platform | Platform operations, tenant provisioning, support access |
| `tenant_owner` | Tenant | Tenant settings, institution creation, subscription visibility, tenant administrators |
| `institution_admin` | Institution | Institution setup, users, academic structures, policies |
| `campus_admin` | Campus | Campus operations and campus-scoped records |
| `admissions_officer` | Institution/campus | Applications and admission decisions |
| `academic_officer` | Institution | Learner enrolment, class allocation, academic records |
| `teacher` | Assigned classes/courses | Assigned learners, attendance, assessment entry |
| `attendance_officer` | Assigned campus/classes | Attendance capture and review |
| `librarian` | Institution/campus | Library catalogue, resource access and circulation configuration |
| `guardian` | Linked learners | Read permitted information and receive notifications |
| `learner` | Own account | Read own permitted information and access resources |
| `auditor` | Assigned scope | Read-only access and audit reports |
| `support_operator` | Time-limited support scope | Approved troubleshooting only |

Do not create a single unrestricted `staff` role. Staff access must be tied to duties and scope.

### 5.3 Role and permission design

Use role-based access control (RBAC) with scope-aware policies and, where needed, record-level checks.

Permissions should be expressed as actions on resources, for example:

- `learner.view`
- `learner.create`
- `learner.update`
- `learner.archive`
- `guardian.view`
- `guardian.link`
- `admission.review`
- `enrolment.create`
- `class.assign`
- `attendance.capture`
- `attendance.view`
- `attendance.correct`
- `assessment.create`
- `assessment.mark`
- `result.publish`
- `library.resource.create`
- `library.resource.publish`
- `library.access.manage`
- `library.read`
- `audit.view`
- `user.invite`
- `role.assign`
- `institution.configure`

### 5.4 Permission rules

1. **Deny by default.** A user receives no access until a valid membership, role and scope permit it.
2. **Least privilege.** Assign only the permissions needed for the user's duties.
3. **Scope is mandatory.** A teacher assigned to Class A must not automatically access Class B.
4. **Guardians are relationship-scoped.** A guardian can access only learners linked to that guardian through an approved relationship.
5. **Learners are self-scoped.** A learner account can access only their own permitted records.
6. **Sensitive actions require elevated permissions.** Publishing results, correcting attendance, changing guardian links and granting library access must be separately authorised.
7. **Role assignment is controlled.** Only authorised administrators can assign roles within their scope; no user may grant permissions they do not possess.
8. **Platform support is exceptional.** Support access must be approved, time-limited and audited.
9. **Read-only means read-only.** Auditors cannot mutate data or trigger workflows that change state.
10. **Entitlements are separate from permissions.** A user must pass both authorisation and subscription-feature checks.

### 5.5 Permission matrix (MVP baseline)

Legend: **F** = full within scope, **C** = create/update within assigned scope, **R** = read, **A** = approve/publish, **—** = no access. This is a starting matrix; enforce individual permissions in code.

| Capability | Platform admin | Tenant owner | Institution admin | Admissions | Academic officer | Teacher | Attendance officer | Librarian | Guardian | Learner | Auditor |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Provision tenant | F | — | — | — | — | — | — | — | — | — | — |
| Manage tenant settings | R/support | F | — | — | — | — | — | — | — | — | R |
| Configure institution | support | R | F | — | C | — | — | C (library only) | — | — | R |
| Manage users/roles | support | tenant scope | F | — | limited | — | — | — | — | — | R |
| Process admissions | — | — | F | C/A | R | — | — | — | — | — | R |
| Manage learner enrolment | — | — | F | C (admission) | C | assigned read | — | — | linked read | own read | R |
| Manage academic structure | — | — | F | — | C | assigned read | assigned read | — | — | — | R |
| Capture attendance | — | — | F | — | C | assigned C | C | — | linked read | own read | R |
| Correct attendance | — | — | A | — | A/C | request only | request only | — | — | — | R |
| Enter marks | — | — | A | — | C/A | assigned C | — | — | published read | own read | R |
| Publish results | — | — | A | — | A (if delegated) | — | — | — | linked read | own read | R |
| Manage library catalogue | — | — | F | — | — | — | — | C | — | — | R |
| Read library resources | — | tenant policy | institution policy | institution policy | institution policy | institution policy | institution policy | R | policy-based | policy-based | R (metadata only by default) |
| View audit logs | platform | tenant scope | institution scope | limited | limited | own actions | attendance scope | library scope | — | — | assigned scope |

### 5.6 Guardian relationship model

Guardian access must not be inferred from shared surnames, addresses or contact details.

A guardian relationship must include:
- learner
- guardian user or verified contact identity
- relationship type (parent, guardian, caregiver, other)
- verification status
- access status
- permitted actions or information scope
- effective dates where applicable
- who created and verified the relationship
- audit history

Where a learner has multiple guardians, each relationship is managed independently. Institutions must be able to suspend or review access in safeguarding or custody-related situations. The platform should not attempt to adjudicate family disputes.

---

## 6. Core functional requirements

Priority definitions:
- **P0:** required for MVP launch.
- **P1:** valuable early enhancement; may be included if pilot needs justify it.
- **P2:** post-MVP.

### 6.1 Institution and campus management

**FR-INS-001 (P0):** The system shall allow an authorised tenant owner to create an institution under their tenant.

**FR-INS-002 (P0):** The system shall support one or more campuses per institution.

**FR-INS-003 (P0):** The system shall store institution name, type, contact details, timezone, locale, academic terminology and status.

**FR-INS-004 (P0):** The system shall support institution-level configuration for academic periods, attendance rules and enabled modules.

**FR-INS-005 (P0):** Institution configuration changes shall be audited.

**Acceptance criteria**
- A tenant owner can create an institution and assign an institution administrator.
- An institution administrator can add a campus without creating a new tenant.
- A user from Institution A cannot view or modify Institution B's records unless explicitly assigned.
- Changes to critical settings record actor, timestamp, old value and new value where appropriate.

### 6.2 Academic structure

**FR-ACA-001 (P0):** Institution administrators shall create academic years and terms/semesters.

**FR-ACA-002 (P0):** The system shall support configurable grade/form/level structures.

**FR-ACA-003 (P0):** The system shall support classes, streams or cohorts.

**FR-ACA-004 (P0):** The system shall support subjects/courses and teacher assignments.

**FR-ACA-005 (P0):** The system shall prevent conflicting active enrolments in the same academic context unless the institution explicitly permits them.

**FR-ACA-006 (P1):** The system may support departments and programmes for college configurations.

**Acceptance criteria**
- An administrator can configure an academic year, term and class structure.
- A class belongs to the correct institution and academic year.
- A teacher sees only classes/courses assigned to them.
- Closing an academic period does not delete historical attendance or results.

### 6.3 Admissions

**FR-ADM-001 (P0):** An authorised admissions officer shall create and manage an application.

**FR-ADM-002 (P0):** An application shall capture only institution-defined and necessary applicant data.

**FR-ADM-003 (P0):** The system shall support application statuses: `draft`, `submitted`, `under_review`, `accepted`, `waitlisted`, `rejected`, `withdrawn`.

**FR-ADM-004 (P0):** An authorised officer shall record an admission decision with actor, date and optional reason.

**FR-ADM-005 (P0):** An accepted application shall be convertible to a learner record and enrolment without retyping the same data.

**FR-ADM-006 (P1):** External application forms and document uploads may be enabled after the internal workflow is stable.

**Acceptance criteria**
- An application can be created, reviewed and accepted/rejected by an authorised officer.
- An unauthorised teacher cannot make an admission decision.
- Converting an accepted application creates one learner record and the appropriate enrolment.
- Retrying the conversion does not create duplicate learners or enrolments.
- Application changes and decisions are auditable.

### 6.4 Learner information system

**FR-LRN-001 (P0):** The system shall generate a stable, unique Acadly learner identifier.

**FR-LRN-002 (P0):** Each institution may assign its own admission number or student number.

**FR-LRN-003 (P0):** Learner records shall support profile, enrolment, status and institution-defined non-sensitive fields.

**FR-LRN-004 (P0):** Learner status shall include at least `applicant`, `active`, `transferred`, `completed`, `withdrawn`, `suspended` and `archived`, with institution policy governing transitions.

**FR-LRN-005 (P0):** A learner may have historical enrolments across academic years and institutions, subject to permissions and data-sharing rules.

**FR-LRN-006 (P0):** The system shall preserve historical academic records when a learner changes class or status.

**FR-LRN-007 (P0):** Duplicate detection shall use configurable matching signals and human review; it shall not automatically merge records based only on name similarity.

**Acceptance criteria**
- A learner receives a stable identifier that does not change when class, campus or institution assignment changes.
- Institution-specific student numbers can coexist with the Acadly identifier.
- A learner's prior-year enrolment and results remain available to authorised users.
- A suspected duplicate is flagged for review and is not automatically merged.
- Deactivation does not erase legally or operationally required history.

### 6.5 Guardian and family management

**FR-GUA-001 (P0):** An authorised officer shall link one or more guardians to a learner.

**FR-GUA-002 (P0):** The system shall record relationship verification and access status.

**FR-GUA-003 (P0):** A guardian account shall see only learners with an active, approved relationship.

**FR-GUA-004 (P0):** An institution administrator shall be able to suspend a guardian relationship with a reason.

**FR-GUA-005 (P1):** Guardian self-service relationship requests may be added with institution verification before access is granted.

**Acceptance criteria**
- A guardian linked to Learner A cannot retrieve Learner B by changing a URL or API identifier.
- Revoking a relationship removes access on the next authorised request and invalidates relevant cached access.
- Relationship creation, verification, suspension and restoration are audited.
- The system supports multiple guardians for one learner.

### 6.6 Staff and assignments

**FR-STF-001 (P0):** Institution administrators shall invite or create staff accounts.

**FR-STF-002 (P0):** Staff shall be assigned one or more roles and explicit institutional scopes.

**FR-STF-003 (P0):** Teachers shall be assigned to classes/courses and, where relevant, subjects.

**FR-STF-004 (P0):** Staff accounts shall be deactivated without deleting records of their previous actions.

**Acceptance criteria**
- An invited staff member must complete account activation before accessing the institution.
- A teacher sees assigned classes only.
- Deactivated staff cannot authenticate or perform new actions.
- Historical actions remain attributed to the original staff identity.

### 6.7 Attendance (EduTrack)

**FR-ATT-001 (P0):** The system shall support attendance sessions linked to a class/course and date or scheduled period.

**FR-ATT-002 (P0):** An authorised user shall record learner attendance as `present`, `absent`, `late` or `excused`.

**FR-ATT-003 (P0):** The system shall retain the original attendance event and record corrections as separate audited actions.

**FR-ATT-004 (P0):** The system shall prevent duplicate attendance events for the same learner/session unless an authorised correction workflow is used.

**FR-ATT-005 (P0):** Users shall be able to review attendance by learner, class, date range and status.

**FR-ATT-006 (P0):** Attendance capture shall support manual entry and a simple mobile-friendly interface.

**FR-ATT-007 (P1):** QR or NFC capture may be enabled after manual attendance is stable.

**FR-ATT-008 (P1):** Offline attendance capture shall queue encrypted events locally and synchronise when connectivity returns.

**FR-ATT-009 (P1):** A sync operation shall be idempotent and preserve capture time separately from server receipt time.

**FR-ATT-010 (P1):** College self-check-in may use a session-specific, short-lived QR token validated against course registration and time window.

**Attendance event model**
- immutable event ID
- tenant, institution and campus
- learner ID
- attendance session ID
- status
- capture method
- captured by user/device
- client capture timestamp
- server received timestamp
- device identifier (pseudonymous where possible)
- sync status
- correction/reversal reference where applicable

**Acceptance criteria**
- A teacher can record attendance for an assigned class.
- A teacher cannot record attendance for an unassigned class.
- Duplicate submissions do not create duplicate events.
- A correction records who made it, when and why; the original value remains available in audit history.
- Attendance reports reconcile with recorded events and approved corrections.
- If offline capture is enabled, a queued event synchronises once and only once after reconnection.
- Server time and client capture time are not conflated.

### 6.8 Assessments and results

**FR-ASM-001 (P0):** An authorised academic officer shall define assessment categories and grading scales.

**FR-ASM-002 (P0):** Teachers shall enter marks only for assigned classes/subjects and authorised assessment periods.

**FR-ASM-003 (P0):** The system shall validate marks against configured maximum values and grading rules.

**FR-ASM-004 (P0):** Results shall have draft and published states.

**FR-ASM-005 (P0):** Only an authorised role shall publish results.

**FR-ASM-006 (P0):** Published results shall be visible only to authorised staff, the relevant learner and approved guardians.

**FR-ASM-007 (P1):** Configurable report card templates may be introduced after core result integrity is proven.

**Acceptance criteria**
- A teacher can enter marks for an assigned subject and class.
- Marks outside configured bounds are rejected with a clear validation message.
- Draft marks are not visible in the guardian portal.
- Publishing results records the publisher and timestamp.
- Changes after publication require a controlled correction/republication action and audit record.

### 6.9 Communication and notifications

**FR-COM-001 (P0):** The system shall support institution announcements targeted by campus, class, group or role.

**FR-COM-002 (P0):** The system shall support in-app notifications.

**FR-COM-003 (P0):** The system shall provide an abstraction for email and SMS providers.

**FR-COM-004 (P0):** Notification delivery attempts and provider responses shall be logged.

**FR-COM-005 (P0):** Notification failures shall not roll back the underlying academic transaction.

**FR-COM-006 (P1):** Push and WhatsApp channels may be added through provider adapters.

**Acceptance criteria**
- An authorised administrator can publish an announcement to a defined audience.
- A guardian receives only notifications relating to linked learners or audiences they are authorised to receive.
- A failed SMS does not undo an attendance record or result publication.
- The system records queued, sent, failed and delivered states where provider callbacks support them.
- Institutions can see message usage and avoid unbounded/unpriced messaging.

### 6.10 Guardian and learner portal

**FR-PORT-001 (P0):** Guardians shall view permitted learner profiles and current enrolment information.

**FR-PORT-002 (P0):** Guardians shall view published attendance summaries.

**FR-PORT-003 (P0):** Guardians shall view published results only.

**FR-PORT-004 (P0):** Guardians shall view institution announcements targeted to them.

**FR-PORT-005 (P0):** Learners with accounts shall view their own permitted profile, attendance, published results and accessible library resources.

**FR-PORT-006 (P0):** The portal shall be responsive and usable on common mobile browsers.

**Acceptance criteria**
- A guardian can switch between multiple linked learners.
- A guardian cannot access an unlinked learner.
- Draft results and internal staff notes are not exposed.
- A learner cannot access another learner's records.
- Portal pages remain usable on a small mobile viewport.

---

## 7. Digital library requirements (EduLibrary)

### 7.1 Purpose

EduLibrary is an institution-managed digital resource catalogue and reading service. It allows institutions to organise and provide access to approved digital books, learning materials, reference documents and reading lists.

The library is not simply a file upload folder. It must support resource metadata, classification, access policies, reader compatibility, learner progress and a consistent reading experience.

### 7.2 MVP objectives

- Allow authorised librarians to create and manage a resource catalogue.
- Support PDF and EPUB resources.
- Provide a browser-based reading experience.
- Allow institutions to control who can access each resource.
- Save reading position for each user and resource.
- Provide bookmarks and basic reading history.
- Support curated collections and class reading lists.
- Store files privately and issue authorised, short-lived access.
- Record access events for operational and rights-management purposes.

### 7.3 Resource types

MVP:
- PDF documents
- EPUB 2/3 files where supported by the chosen reader
- External resource links with title, description, provider and access policy

Later:
- Audio books
- HTML learning packages
- Image-based learning resources
- Licensed streaming content
- OPDS catalogue integration, where relevant

### 7.4 Metadata

A library resource should support:
- title
- subtitle
- author(s)
- publisher
- publication date/year
- ISBN or other external identifier (optional)
- language
- description/summary
- cover image
- subject/category
- grade/level suitability
- resource format
- file version
- file size
- accessibility notes
- source/provider
- rights holder
- licence type and licence evidence/reference
- permitted audience
- availability start/end
- download permission
- status (`draft`, `published`, `withdrawn`, `archived`)
- created by and last updated by

Do not require every metadata field for every resource. Validate fields based on resource type and institutional policy.

### 7.5 Library functional requirements

**FR-LIB-001 (P0):** A librarian shall create, edit and archive catalogue records.

**FR-LIB-002 (P0):** A librarian shall upload PDF and EPUB files to private object storage.

**FR-LIB-003 (P0):** The system shall validate file extension, MIME type, file size and file integrity; extension alone is insufficient.

**FR-LIB-004 (P0):** The system shall keep uploaded files private by default.

**FR-LIB-005 (P0):** A librarian shall publish or withdraw a resource.

**FR-LIB-006 (P0):** A librarian shall classify resources by category, subject, grade/level and collection.

**FR-LIB-007 (P0):** An authorised user shall search and browse resources they are allowed to discover.

**FR-LIB-008 (P0):** The system shall enforce resource access rules at the server/API and file-delivery layers.

**FR-LIB-009 (P0):** A learner or guardian shall open an accessible resource in the browser reader.

**FR-LIB-010 (P0):** The reader shall remember the user's last reading position per resource.

**FR-LIB-011 (P0):** The reader shall support PDF page navigation and zoom.

**FR-LIB-012 (P0):** The reader shall support EPUB table of contents and reflowable text presentation.

**FR-LIB-013 (P0):** The reader shall support bookmarks.

**FR-LIB-014 (P0):** The system shall record resource access and reading progress events.

**FR-LIB-015 (P0):** A librarian shall assign a resource or collection to a class/group or audience.

**FR-LIB-016 (P1):** EPUB reader preferences shall include font size, font family where supported, line spacing, margins and light/dark/sepia themes.

**FR-LIB-017 (P1):** The reader shall support text search within the resource where technically available.

**FR-LIB-018 (P1):** The reader shall support highlights and private notes anchored to a stable content location.

**FR-LIB-019 (P1):** The platform may support offline reading for selected resources after a licensing and device-security review.

**FR-LIB-020 (P2):** The platform may support text-to-speech, reading goals, audio books, comprehension activities and reading analytics.

### 7.6 PDF versus EPUB reader behaviour

**EPUB**
- EPUB is the preferred format for a Kindle-like reflowable reading experience.
- Users should be able to change font size, line spacing, margins and theme.
- The reader should provide a table of contents and remember the last reading location.
- Highlights, bookmarks and notes should be anchored using a stable EPUB location representation, such as an EPUB CFI or a Readium locator, depending on the selected engine.

**PDF**
- PDF is a fixed-layout format. The reader should preserve the original page design.
- Users should be able to zoom, navigate pages, search selectable text, bookmark pages and annotate where supported.
- Do not promise reliable conversion of every PDF into reflowable text. Reflow can damage tables, equations, diagrams, columns and page references.
- Scanned PDFs may require OCR before text search is possible. OCR should be treated as a separate processing step, with confidence and quality review.
- For PDF annotations, use page index plus coordinates/selection context, and retain enough metadata to relocate the annotation.

**Important product decision:** Acadly should not attempt to make every PDF behave exactly like a Kindle EPUB. It should provide a polished reader for each format, using reflow for EPUB and faithful page rendering for PDF.

### 7.7 Recommended reader technology

The recommendation is to evaluate the following options through a short technical spike rather than build a document rendering engine from scratch.

| Technology | Best fit | Strengths | Considerations |
|---|---|---|---|
| Readium Web | EPUB-first web reader | Modern Readium architecture; reader toolkit; publication model and navigation concepts | Web project is evolving; confirm supported formats and integration maturity for the selected release |
| PDF.js | PDF rendering | Mozilla-backed browser PDF rendering; established viewer and APIs | UI integration and access control remain Acadly responsibilities |
| epub.js | Lightweight EPUB rendering | JavaScript EPUB rendering and navigation | Validate EPUB compatibility, accessibility, annotation and maintenance needs |
| Readium Mobile | Future native mobile reader | Dedicated mobile reading toolkits | Separate mobile integration; not required for web MVP |

**Preferred direction**
1. Run a proof of concept using **Readium Web for EPUB** and **PDF.js for PDF**.
2. Compare the Readium Web integration with epub.js for implementation complexity, rendering quality, accessibility, browser support and annotation capabilities.
3. Keep the Acadly library domain independent of the reader engine. Store progress and annotations behind an adapter so the reader can be replaced without redesigning the domain model.
4. Pin library versions and maintain a regression test corpus of representative PDFs and EPUBs.

Official references:
- Readium Web: https://readium.org/web/
- Readium Web repository: https://github.com/readium/web
- Readium Web / EDRLab overview: https://www.edrlab.org/software/readium-web/
- Readium Web Publication Manifest: https://readium.org/webpub-manifest/
- PDF.js: https://mozilla.github.io/pdf.js/
- PDF.js getting started: https://mozilla.github.io/pdf.js/getting_started/
- PDF.js API: https://mozilla.github.io/pdf.js/api/
- epub.js: https://github.com/futurepress/epub.js
- Readium mobile toolkit documentation: https://readium.org/swift-toolkit/latest/documentation/readium/getting-started/

### 7.8 Digital library access and copyright

- Institutions must confirm that they own, have licensed, or are otherwise legally permitted to distribute each digital resource.
- Purchasing a physical book does not automatically grant the right to scan and distribute a digital copy.
- Support public-domain resources, openly licensed educational resources, institution-authored materials and properly licensed digital content first.
- Store licence metadata and evidence/reference for each resource.
- Access policy must be enforced even if a user obtains a previously issued file URL; use short-lived signed URLs or an authorised streaming endpoint.
- Do not describe signed URLs as DRM. A signed URL limits access duration but cannot prevent a determined authorised user from copying content.
- Downloading should be disabled by default for restricted resources.
- Offline access requires a separate decision about encryption, expiry, device binding, revocation and licence terms.
- Do not build proprietary DRM in the MVP. If commercial publishers require DRM, evaluate established licensing ecosystems and their costs, integration requirements and device support.

### 7.9 Library acceptance criteria

- A librarian can create a resource record and upload a valid PDF or EPUB.
- Invalid, oversized or mismatched files are rejected safely.
- Uploaded resources are not publicly accessible through a predictable storage URL.
- A learner without access cannot open the resource even if they know its identifier.
- A learner with access can open a PDF and navigate/zoom pages.
- A learner with access can open an EPUB, navigate its table of contents and change reading preferences where supported.
- Reading position is saved and restored for the same user and resource.
- One learner cannot see another learner's bookmarks, highlights or notes.
- Withdrawing a resource prevents new access and follows the institution's defined behaviour for active reading sessions.
- Resource access is logged without storing unnecessary reading content or sensitive personal data.
- The catalogue clearly distinguishes downloadable from reader-only resources.

---

## 8. SafeRelease — child collection and release

SafeRelease is a safeguarding-oriented workflow and should not be reduced to a QR code scan.

**MVP status:** P1, subject to pilot institution demand and safeguarding review. It is not a prerequisite for the first core academic release.

### 8.1 Workflow

1. Institution configures authorised collection policy.
2. Guardian submits a request to add an authorised collector.
3. Institution verifies and approves the collector.
4. Guardian or collector announces arrival.
5. Staff view the pending collection request and learner details.
6. Staff verify the collector's identity and authorisation using the institution's procedure.
7. Staff explicitly confirm handover.
8. The system records the handover and sends a notification.

### 8.2 Safety requirements

- A QR code is an aid to finding a collection record, never automatic permission to release a child.
- Staff must verify the person and authority before handover.
- Temporary authorisations must have expiry dates and clear learner scope.
- Manual fallback must exist for device/network failure.
- Emergency collection and disputed attempts must be recorded with a reason.
- The system must not expose children's records to other guardians or unauthorised collectors.

### 8.3 Acceptance criteria

- A collector cannot collect a learner unless an active approved authorisation exists.
- Expired or revoked authorisations are rejected.
- Staff must explicitly confirm release after verification.
- Every completed handover records learner, collector, staff member, timestamp and method.
- Failed or disputed collection attempts can be recorded without falsely marking the learner as released.

---

## 9. Core workflows

### 9.1 Institution onboarding

```text
Tenant owner creates tenant
    -> Creates institution
        -> Adds campus(es)
            -> Invites institution administrator
                -> Configures academic year and terms
                    -> Creates grades/classes/subjects
                        -> Invites staff
                            -> Institution is ready for admissions
```

**Completion condition:** institution has an active academic period, basic academic structure and at least one authorised administrator.

### 9.2 Admissions to enrolment

```text
Admissions officer creates application
    -> Completes required applicant information
        -> Submits for review
            -> Admissions officer reviews
                -> Accept / Waitlist / Reject
                    -> If accepted: convert to learner
                        -> Create academic enrolment
                            -> Link guardian(s)
                                -> Assign class
                                    -> Learner becomes active
```

**Rules**
- Conversion must be transactional and idempotent.
- An application cannot be converted twice into duplicate learner records.
- Rejected or withdrawn applications do not create active enrolments.
- Admission decisions are auditable.

### 9.3 Attendance capture and correction

```text
Teacher opens assigned class/session
    -> System loads enrolled learners
        -> Teacher records status
            -> System validates permissions and duplicates
                -> Attendance events are stored
                    -> Summary is updated
                        -> Guardian notification may be queued

If correction is needed:
    -> Authorised user submits correction + reason
        -> System checks permission
            -> Correction event is recorded
                -> Summary reflects approved state
                    -> Audit history retains original event
```

### 9.4 Assessment and result publication

```text
Academic officer creates assessment
    -> Assigns class/subject/period and grading rules
        -> Teacher enters marks
            -> System validates marks
                -> Marks remain draft
                    -> Authorised reviewer checks
                        -> Results are published
                            -> Learner/guardian portal displays published result
```

### 9.5 Library publication and reading

```text
Librarian creates resource metadata
    -> Uploads PDF/EPUB
        -> System validates file and stores privately
            -> Librarian assigns category and access policy
                -> Resource is published
                    -> Learner discovers resource
                        -> Access policy is checked
                            -> Reader receives authorised resource access
                                -> Position/bookmarks/progress are saved
```

### 9.6 Guardian access

```text
Institution creates guardian relationship
    -> Verifies relationship
        -> Guardian account is activated
            -> Guardian signs in
                -> System loads approved learner links
                    -> Guardian selects learner
                        -> Portal returns only permitted information
```

---

## 10. Domain boundaries and bounded contexts

Acadly should be implemented as a modular monolith initially. Modules may share one deployable application and database, but each domain owns its business rules, services and data lifecycle.

### 10.1 Bounded contexts

| Context | Owns | Must not own |
|---|---|---|
| Platform & tenancy | Tenant lifecycle, subscriptions, platform configuration | Learner academic decisions |
| Identity & access | Authentication, memberships, roles, permissions, sessions | Institution-specific academic records |
| Institution | Institution/campus profile and settings | Tenant subscription billing rules |
| Academic structure | Years, terms, grades, classes, subjects, programmes | Attendance event history |
| Admissions | Applications, review and admission decisions | Long-term academic results |
| Learner records | Learner identity, profile, status and enrolments | LMS lesson content |
| Guardian relationships | Guardian links, verification and access scope | General identity provider credentials |
| Staff | Staff profiles and assignments | Authentication secrets |
| Attendance | Sessions, attendance events, corrections and summaries | Fee balances or payroll |
| Assessment | Assessments, grading scales, marks and published results | LMS course content and quiz engine |
| Communication | Templates, audience resolution, delivery jobs and provider adapters | Core academic state |
| Digital library | Resource catalogue, files, licences, access, reading progress and annotations | LMS assignment grading |
| Integration | External identifiers, API clients, webhooks and sync logs | Source-of-truth business decisions |
| Analytics | Read models, reports and derived metrics | Authoritative mutation of operational records |

### 10.2 Stride IQ-X boundary

Stride IQ-X remains a separate Learning Management System.

**Acadly owns**
- Institution and campus.
- User identity and institutional membership.
- Learner and staff records.
- Academic enrolment and course registration.
- Authoritative institutional course/class assignment.
- Official academic result publication and transcript records.

**Stride owns**
- Course learning content.
- Modules, lessons and learning activities.
- Assignments and quizzes.
- Learning attempts and detailed learning progress.
- LMS-specific grading workflows.

**Integration responsibilities**
- Acadly provisions or links identity and institutional membership.
- Acadly provides course/learner enrolment information to Stride through an agreed integration contract.
- Stride returns agreed completion and grade information.
- Acadly decides whether returned grades become official academic results.
- All sync operations are authenticated, idempotent, logged and retryable.
- The integration must not create two competing sources of truth for the same field.

Potential standards to evaluate later:
- OpenID Connect for single sign-on.
- LTI 1.3 where tool interoperability is needed.
- OneRoster for roster exchange where its supported data model fits.
- REST APIs and signed webhooks for product-specific operations.

Do not implement all standards merely because they exist. Choose based on partner interoperability requirements.

### 10.3 External integrations

Future integrations may include:
- SMS provider(s)
- Email delivery provider
- Payment gateway(s)
- Stride IQ-X
- Accounting software
- Government registries where authorised
- External content/catalogue providers

Each integration must have:
- documented data contract
- authentication and authorisation
- explicit source-of-truth rules
- idempotency strategy
- retries and dead-letter handling
- audit/sync logs
- versioning and deprecation policy

### 10.4 Domain events

Examples:
- `InstitutionCreated`
- `AcademicYearOpened`
- `ApplicationSubmitted`
- `AdmissionDecisionRecorded`
- `LearnerEnrolled`
- `GuardianRelationshipApproved`
- `AttendanceRecorded`
- `AttendanceCorrected`
- `AssessmentPublished`
- `ResultsPublished`
- `LibraryResourcePublished`
- `LibraryAccessRevoked`

Events should be published after the source transaction commits. Consumers must be idempotent. Do not use events as a substitute for transactional validation inside the owning domain.

---

## 11. Data model (conceptual)

This is a conceptual starting point, not a final database migration specification.

### 11.1 Platform and tenancy
- `tenants`
- `tenant_subscriptions`
- `institutions`
- `campuses`
- `institution_settings`
- `feature_entitlements`

### 11.2 Identity and access
- `users`
- `user_profiles`
- `tenant_memberships`
- `institution_memberships`
- `campus_memberships`
- `roles`
- `permissions`
- `role_permissions`
- `model_role_assignments` or equivalent scoped role assignments
- `user_sessions`
- `audit_logs`

### 11.3 Academic structure
- `academic_years`
- `academic_periods`
- `grade_levels`
- `programmes`
- `departments`
- `classes`
- `streams`
- `subjects`
- `class_subjects`
- `staff_assignments`

### 11.4 Admissions and learner records
- `admission_applications`
- `admission_documents`
- `admission_decisions`
- `learners`
- `learner_identifiers`
- `enrolments`
- `enrolment_history`
- `learner_status_history`

### 11.5 Guardian and staff
- `guardians` (or user-linked guardian profiles)
- `learner_guardian_relationships`
- `guardian_verifications`
- `staff_profiles`
- `staff_assignments`

### 11.6 Attendance
- `attendance_sessions`
- `attendance_events`
- `attendance_corrections`
- `attendance_sync_batches`
- `attendance_devices` (only if device registration is required)

Prefer an append-only event/correction history. A current attendance summary may be materialised for reporting, but it must be rebuildable from authoritative events and approved corrections.

### 11.7 Assessments
- `assessment_categories`
- `grading_scales`
- `assessments`
- `assessment_components`
- `marks`
- `result_publications`
- `report_card_snapshots`

### 11.8 Communication
- `announcements`
- `announcement_audiences`
- `notification_templates`
- `notification_jobs`
- `notification_deliveries`
- `communication_preferences`

### 11.9 Digital library
- `library_resources`
- `library_resource_files`
- `library_categories`
- `library_collections`
- `library_collection_items`
- `library_access_rules`
- `library_assignments`
- `library_access_events`
- `reading_progress`
- `reading_bookmarks`
- `reading_highlights`
- `reading_notes`
- `resource_licences`

### 11.10 Integration
- `external_identities`
- `integration_connections`
- `api_clients`
- `webhook_endpoints`
- `webhook_deliveries`
- `integration_sync_runs`
- `integration_sync_errors`

### 11.11 Data modelling principles

- Use stable internal IDs (UUID/ULID are both viable; choose one consistently).
- Keep human-readable admission numbers separate from internal primary keys.
- Use explicit foreign keys and database constraints.
- Add tenant/institution scope columns to tenant-owned records.
- Index common tenant + institution + period + learner query paths.
- Use soft deletion only where it has a clear business purpose; retain lifecycle status and audit history for important records.
- Avoid large, unvalidated JSON blobs for core academic relationships.
- Use JSON for genuinely variable configuration or provider payload snapshots, not to replace relational modelling.
- Define retention and archival policies before production data migration.

---

## 12. API and application architecture requirements

### 12.1 Architectural approach

Recommended MVP architecture: **modular monolith**.

Suggested infrastructure:
- Laravel application/API.
- PostgreSQL as primary relational database.
- Redis for cache, queues and rate-limiting support.
- Laravel queue workers for asynchronous work.
- S3-compatible private object storage for documents and library files.
- Nginx reverse proxy.
- Docker-based deployment pipeline.
- Centralised logs, metrics and error reporting.
- Reverb or another WebSocket service only where real-time updates materially improve workflows.

The frontend decision remains open:
- React or Vue 3 may power the main user-facing application.
- Filament may be used for internal administrative workflows where it improves delivery speed.
- Filament is an admin-panel framework, not a direct like-for-like alternative to React.
- A mixed approach is acceptable if design consistency, authentication, routing and authorisation remain coherent.

### 12.2 API requirements

- Version public and integration APIs (for example `/api/v1`).
- Use consistent validation and error response formats.
- Enforce authentication, scope and permissions on every protected endpoint.
- Use pagination for collection endpoints.
- Use idempotency keys for operations that may be retried.
- Rate-limit authentication, sensitive and public endpoints.
- Avoid exposing internal database IDs or unrestricted model serialization.
- Use signed webhooks with replay protection.
- Document APIs using OpenAPI where appropriate.

### 12.3 Background jobs

Use asynchronous jobs for:
- SMS/email delivery
- report generation
- bulk imports
- file scanning and processing
- EPUB/PDF metadata extraction
- thumbnails and cover generation
- webhook delivery
- external integration sync

Jobs must be retryable, idempotent where possible and observable. Failed jobs must be visible to authorised operators.

---

## 13. Non-functional requirements

### 13.1 Security

- Enforce HTTPS.
- Hash passwords using a modern password hashing algorithm supported by the framework.
- Support MFA for platform and institution administrators; make MFA policy configurable for staff.
- Use secure, HttpOnly and SameSite cookies where cookie-based sessions are used.
- Protect against CSRF, XSS, SQL injection, SSRF and insecure direct object references.
- Validate file uploads and scan uploaded files where practical.
- Keep object storage private and use short-lived authorised access.
- Encrypt sensitive data in transit and at rest using managed infrastructure controls.
- Store secrets outside source control.
- Apply least-privilege service credentials.
- Log privileged actions and sensitive record access where justified.
- Do not log passwords, tokens, full identity documents or unnecessary sensitive content.
- Perform dependency and container vulnerability scanning.
- Maintain backup and restore procedures with tested recovery.

### 13.2 Privacy and child safeguarding

- Collect the minimum information required for institutional operations.
- Document the purpose and lawful basis for processing personal data.
- Provide clear privacy notices and role-specific access.
- Establish retention, archival and deletion policies.
- Restrict guardian access through verified relationships.
- Protect child collection/release workflows.
- Avoid continuous tracking and unnecessary biometric data.
- Ensure that staff can correct inaccurate records through an auditable process.
- Provide a process for access requests, correction requests and account closure.
- Review local data protection, child safeguarding and education-sector requirements before launch.

### 13.3 Availability and resilience

Initial target:
- 99.5% monthly application availability, excluding announced maintenance and agreed third-party outages.
- Automated daily backups at minimum.
- More frequent database backups/PITR where infrastructure supports it.
- Documented recovery procedures.
- Proposed initial RPO: 24 hours maximum; target lower where point-in-time recovery is enabled.
- Proposed initial RTO: 8 hours maximum for a pilot deployment.

Targets must be confirmed against hosting budget and customer contracts.

### 13.4 Performance

Initial targets under an agreed pilot load:
- 95th percentile for common authenticated read requests: under 800 ms, excluding large file transfer and third-party latency.
- 95th percentile for normal write requests: under 1.2 seconds, excluding asynchronous delivery.
- First useful page render should be optimised for low-to-mid-range mobile devices and variable connectivity.
- Large reports and bulk operations should run asynchronously.
- Library files should support efficient streaming/range requests where the chosen reader requires them.

Performance targets must be validated with realistic data volumes and representative devices.

### 13.5 Accessibility and usability

- Responsive layouts for desktop, tablet and mobile.
- Keyboard-accessible controls.
- Semantic form labels and meaningful validation messages.
- Adequate colour contrast.
- Avoid communicating status by colour alone.
- Reader controls must be discoverable and usable on touch devices.
- Design for users with varying digital literacy.
- Support English initially; design for future localisation, including Chichewa.

### 13.6 Observability

- Structured application logs with request/correlation IDs.
- Error tracking with tenant context but without leaking sensitive data.
- Queue depth and failed-job monitoring.
- Database health and storage usage monitoring.
- Notification provider failure monitoring.
- Audit logs separated from ordinary debug logs.
- Operational dashboards for platform support.

---

## 14. Reporting requirements (MVP)

Institution-level reports:
- Learner list by class, grade, status and campus.
- Admissions pipeline and admission outcomes.
- Attendance register by class/date.
- Attendance summary by learner and period.
- Absence/late summary.
- Assessment marks by class/subject.
- Published results summary.
- Library catalogue and resource access summary.

Report controls:
- All reports must respect the viewer's scope.
- Exports must be permission-controlled and logged.
- Sensitive reports should not be publicly shareable by default.
- Exported files should include generation time and institution context.
- Reports must clearly distinguish draft from published information.

Advanced dashboards and predictive analytics are post-MVP.

---

## 15. Import, migration and data quality

MVP should support controlled CSV import for learners and selected reference data, after validation.

Requirements:
- Downloadable templates with field definitions.
- Preview and validation before import.
- Row-level error reporting.
- Duplicate detection and human review.
- Import batch ID and audit record.
- Ability to reject the entire batch or import valid rows according to an explicit user choice.
- No silent data truncation or coercion.
- Import users must have a specific permission.
- Sensitive import files must be deleted or retained according to a defined policy.

Do not build a universal migration engine in the MVP. Start with agreed templates for pilot institutions.

---

## 16. Acceptance criteria and release gates

### 16.1 MVP end-to-end acceptance scenarios

**Scenario A — Institution setup**
- Given a tenant owner has an active tenant,
- when they create an institution, campus and institution administrator,
- then the institution administrator can configure the academic year and class structure,
- and users outside the tenant cannot access the new institution.

**Scenario B — Admission and enrolment**
- Given an admissions officer has permission,
- when they create and accept an application,
- then the system creates one learner record and one appropriate enrolment,
- and the operation is auditable and safe to retry.

**Scenario C — Guardian access**
- Given a guardian has an approved relationship with a learner,
- when the guardian signs in,
- then they can view only the linked learner's permitted information,
- and cannot access another learner by changing a URL or API request.

**Scenario D — Attendance**
- Given a teacher is assigned to a class,
- when they record attendance for an active session,
- then the system stores valid attendance events and updates the summary,
- and a duplicate submission does not create duplicate attendance.

**Scenario E — Attendance correction**
- Given an attendance event is incorrect,
- when an authorised officer submits a correction and reason,
- then the current summary reflects the correction,
- and the original event remains visible in audit history.

**Scenario F — Assessment publication**
- Given a teacher has entered valid marks,
- when an authorised reviewer publishes the results,
- then only published results become visible to learners and guardians,
- and the publication action is recorded.

**Scenario G — Digital library**
- Given a librarian has uploaded and published a licensed PDF or EPUB,
- when an authorised learner opens it,
- then the appropriate reader loads the resource and saves progress,
- and an unauthorised learner is denied access.

**Scenario H — Tenant isolation**
- Given users from two separate tenants,
- when either user queries or mutates tenant-owned records,
- then the system returns only records within their authorised scope,
- and automated tests cover both read and write paths.

### 16.2 Security release gate

Before pilot:
- Cross-tenant access tests pass.
- Guardian-to-learner access tests pass.
- Role and scope tests pass for all critical operations.
- File access tests confirm private storage and authorisation.
- Authentication and password reset flows are tested.
- Privileged actions are audited.
- Backups and restoration are tested.
- No unresolved critical/high security issue remains without documented risk acceptance.

### 16.3 Operational release gate

- Monitoring and error reporting enabled.
- Queue workers supervised.
- Database backup schedule active.
- Restore procedure documented and tested.
- Support escalation process documented.
- Institution onboarding checklist ready.
- Data import template and validation process ready.
- Privacy notice and terms reviewed.
- Pilot support contact and incident process defined.

---

## 17. MVP roadmap

### Phase 0 — Discovery and technical validation
- Interview ECD, primary, secondary and college institutions.
- Map current admissions, attendance, assessment and guardian communication processes.
- Validate willingness to pay and operational constraints.
- Confirm data protection and safeguarding requirements.
- Prototype core workflows.
- Spike PDF.js + Readium Web + epub.js.
- Confirm hosting, storage and messaging costs.
- Select 2–3 pilot institutions with different profiles.

**Exit:** approved MVP scope, pilot commitments, validated workflow assumptions and architecture decision record.

### Phase 1 — Core institutional foundation
- Tenancy and institution setup.
- Identity, memberships and roles.
- Academic years, terms, grades, classes and subjects.
- Admissions and learner registration.
- Guardian relationships.
- Staff profiles and assignments.
- Audit logging.

**Exit:** institution can onboard learners and staff securely.

### Phase 2 — Daily school operations
- Attendance sessions and manual capture.
- Attendance review and correction.
- Basic assessments and result publication.
- Guardian portal.
- Announcements and in-app notifications.
- Email/SMS provider adapter.

**Exit:** pilot institution can run a meaningful academic period through Acadly.

### Phase 3 — Digital library
- Catalogue and metadata.
- Private file storage.
- PDF.js integration.
- EPUB reader technical selection and integration.
- Access policies and collections.
- Reading position and bookmarks.
- Rights metadata and access logs.

**Exit:** institution can publish and safely provide approved digital resources.

### Phase 4 — Pilot hardening and selected enhancements
- Offline attendance if validated by pilot.
- NFC/QR attendance if justified.
- SafeRelease after safeguarding review.
- CSV imports and exports.
- Performance and security hardening.
- Pilot feedback and pricing validation.

### Phase 5 — Expansion
- Fees and payment integrations.
- College academic workflows.
- Stride IQ-X integration.
- Timetabling.
- Transport and physical library circulation.
- Advanced analytics and reporting.

---

## 18. Success metrics

MVP product metrics:
- Time required to configure a new institution.
- Percentage of active learners with complete minimum required records.
- Percentage of attendance sessions recorded through Acadly.
- Attendance correction rate and unresolved error rate.
- Guardian account activation and monthly active usage.
- Notification delivery success rate by channel.
- Number of support tickets per institution.
- Time to resolve critical support issues.
- Library resource opens and repeat reading sessions.
- Reading progress restoration success rate.
- Institution renewal intent and willingness to pay.

Do not use raw login count as the only measure of product success. Measure whether institutions complete their real workflows reliably.

---

## 19. Risks and mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Scope expansion across all education levels | Delayed launch and poor quality | One shared core; narrow MVP workflows; phase college features |
| Weak institution adoption | Commercial failure | Pilot discovery, observe real workflows, validate willingness to pay |
| Poor connectivity | Incomplete attendance and frustration | Mobile-first design; offline capture as a validated enhancement |
| Child data exposure | Serious safeguarding and legal harm | Relationship-scoped access, least privilege, audit, privacy review |
| Incorrect guardian links | Unauthorised disclosure | Verification, controlled changes, access revocation and audit |
| Attendance manipulation | Loss of trust | Immutable events, correction workflow, audit and session validation |
| Copyright infringement | Legal and commercial exposure | Rights metadata, licence verification, restricted access and takedown process |
| Reader compatibility issues | Poor library experience | Test representative PDF/EPUB corpus and use established engines |
| Messaging costs | Unprofitable subscriptions | Provider abstraction, usage metering and transparent limits |
| Tenant data leakage | Critical security failure | Centralised tenant scoping, policies, automated isolation tests and optional RLS |
| Over-engineered microservices | Operational complexity | Modular monolith until scale or team boundaries justify separation |
| Conflicting Acadly/Stride records | Data inconsistency | Explicit source-of-truth contracts and idempotent integration |

---

## 20. Open decisions

The following decisions should be made through discovery or technical spikes; they are intentionally not silently assumed in this PRD.

1. React or Vue 3 for the primary user-facing application.
2. Whether Filament is used for internal administration, institution administration, or neither.
3. Whether the first pilot requires a PWA and which offline capabilities are essential.
4. Whether NFC attendance is a launch requirement or Phase 4.
5. Whether SafeRelease is required for the first ECD/primary pilot.
6. Which SMS and email providers are commercially viable for Malawi and target markets.
7. Which EPUB engine is selected after testing Readium Web and epub.js.
8. Whether PDF annotation is MVP or P1.
9. Whether digital library access is included in the base subscription or an optional module.
10. Whether institutions need a dedicated database/deployment option at launch or only later.
11. Exact retention periods and legal requirements by data category.
12. Pilot institution selection, success thresholds and onboarding costs.

Each decision should be captured in an Architecture Decision Record (ADR) or product decision log.

---

## 21. Recommended MVP vertical slice

Build and demonstrate one complete workflow before expanding breadth:

1. Create an institution and academic year.
2. Create a class.
3. Register a learner and guardian.
4. Approve the guardian relationship.
5. Assign the learner to the class.
6. Record attendance.
7. Display attendance to the guardian.
8. Publish a simple assessment result.
9. Display the published result to the guardian.
10. Publish one approved library resource.
11. Let the learner open it and resume reading.
12. Demonstrate that an unrelated guardian or learner cannot access those records or files.

This vertical slice tests the product's core value and the most important security boundaries.

---

## 22. Engineering delivery guidance

### 22.1 Suggested implementation order

1. Define domain vocabulary and tenancy model.
2. Implement authentication and scoped memberships.
3. Implement permission policies and automated isolation tests.
4. Implement institution and academic structure.
5. Implement learner, guardian and admissions workflows.
6. Implement attendance event model and correction history.
7. Implement assessment and result publication.
8. Implement guardian portal.
9. Implement notification abstraction.
10. Implement library catalogue, private storage and reader adapter.
11. Add import/export and operational tooling.
12. Harden, pilot and iterate.

### 22.2 Definition of done

A feature is not complete merely because its happy-path UI works. It is done when:
- Business rules are implemented in the owning domain.
- Server-side authorisation is enforced.
- Tenant and institution scope is tested.
- Validation and error states are handled.
- Audit requirements are met.
- Relevant automated tests pass.
- API and user-facing behaviour are documented.
- Accessibility and responsive behaviour are checked.
- Logging and operational failure paths are considered.
- Data migration and rollback implications are understood.

### 22.3 Test strategy

- Unit tests for domain rules and state transitions.
- Feature/API tests for permissions and workflows.
- Cross-tenant and cross-institution isolation tests.
- Browser end-to-end tests for critical journeys.
- File upload and private storage access tests.
- Reader compatibility tests using a curated corpus of PDF and EPUB files.
- Offline/synchronisation tests if offline attendance is enabled.
- Load tests using realistic learner, attendance and report volumes.
- Backup restoration and incident-response exercises.

---

## 23. Product governance

- The product owner approves scope and priority changes.
- Each bounded context has a named engineering owner, even within a small team.
- Security/privacy review is mandatory for new sensitive data fields and access workflows.
- New integrations require a source-of-truth and failure-handling specification.
- New reports must define their audience and data scope.
- New library content workflows must define rights and access controls.
- Pilot feedback is recorded, prioritised and reviewed at agreed intervals.
- Product decisions and architecture decisions are documented separately.

---

## 24. Glossary

| Term | Definition |
|---|---|
| Tenant | Customer organisation and subscription boundary |
| Institution | School, college or other learning organisation |
| Campus | Physical or administrative branch of an institution |
| Learner | Child, pupil, student or other enrolled participant |
| Guardian | Parent or other institution-approved person linked to a learner |
| Enrolment | Learner's formal placement in an academic context |
| Academic period | Term, semester or equivalent period |
| Attendance session | A class/course meeting or attendance-taking event |
| Published result | Result approved for learner/guardian visibility |
| Resource | A digital book, document or external learning resource |
| Reader | Browser interface used to consume PDF/EPUB resources |
| Bounded context | Domain boundary that owns a specific set of business rules and data |
| Stride IQ-X | Separate LMS integrated with Acadly through defined contracts |

---

## 25. Final product recommendation

Build Acadly as a secure, configurable education operations platform with a deliberately narrow first release. Prove institution setup, admissions, learner and guardian records, attendance, results and parent access before attempting to deliver every feature schools might eventually request.

Treat EduLibrary as a first-class domain, but implement it in phases: catalogue, rights and private storage first; reliable PDF and EPUB reading second; annotations, offline access and advanced reading features later. Use established reader technology and keep the library's business model independent of the rendering engine.

Keep Stride IQ-X independent as the LMS. Keep national identity and registry integrations outside the initial commercial product. The strength of Acadly should come from excellent everyday workflows, trustworthy data, clear permissions, reliable operation and a genuinely useful experience for institutions, families and learners.

---

## Appendix A — Official digital reader references

- Readium Web: https://readium.org/web/
- Readium Web source repository: https://github.com/readium/web
- EDRLab Readium Web overview: https://www.edrlab.org/software/readium-web/
- Readium Web Publication Manifest: https://readium.org/webpub-manifest/
- Mozilla PDF.js: https://mozilla.github.io/pdf.js/
- PDF.js getting started: https://mozilla.github.io/pdf.js/getting_started/
- PDF.js API: https://mozilla.github.io/pdf.js/api/
- epub.js: https://github.com/futurepress/epub.js
- Readium Swift Toolkit documentation: https://readium.org/swift-toolkit/latest/documentation/readium/getting-started/

**Implementation note:** Confirm current project status, supported formats, licence terms, release compatibility and maintenance activity before selecting or pinning a reader dependency. The links above are starting points for technical evaluation, not a substitute for a proof of concept.
