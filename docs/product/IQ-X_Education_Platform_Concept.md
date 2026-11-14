# IQ-X Education Platform

## Commercial Product Concept, Product Strategy and Technical Architecture

**Working title:** EduCore IQ-X (provisional; not approved or final)\
**Document type:** Product concept and solution architecture\
**Status:** Discovery / concept definition\
**Version:** 1.0\
**Prepared for:** IQ-X Technologies\
**Date:** 9 November 2026

------------------------------------------------------------------------

## 1. Executive Summary

EduCore IQ-X is a proposed commercial, multi-institution education
management and learning platform for schools, colleges and other
education providers. It is intended to bring together the operational
and academic functions institutions need to manage learners, engage
parents and guardians, support teachers, and deliver learning.

The product should be designed as **one configurable platform**, not
three unrelated products for ECD, K-12 and tertiary education. The
underlying platform, identity model, security, tenancy, communication
services and reporting foundations can be shared, while institution type
and subscription configuration determine which workflows and modules are
enabled.

The product has three central pillars:

1.  **Manage** -- admissions, learner records, attendance, academic
    administration, fees and institutional operations.
2.  **Engage** -- parent, guardian, student and staff portals;
    communications; notifications; and safeguarding workflows.
3.  **Learn** -- learning resources, online courses, assignments and
    learning analytics through integration with the independent **Stride
    IQ-X LMS**.

A key point of differentiation should be excellent execution rather than
simply a long list of modules. The strongest proposed differentiators
are:

-   Reliable attendance capture, including NFC student cards and offline
    operation.
-   Parent arrival/departure alerts and a controlled child
    pickup/release workflow.
-   A high-quality parent and student experience.
-   A digital library and reading experience, including a Kindle-like
    reader for digital books.
-   Integration with Stride IQ-X for colleges, professional training and
    blended learning.
-   A clean integration and API strategy for institutions that already
    use other systems.

The first commercial target should be private primary and secondary
schools, with ECD as a closely related segment. Colleges and tertiary
institutions should follow once the core student lifecycle and academic
records are stable. The product should not attempt to become a full
accounting ERP or a national education identity infrastructure in its
first releases.

This is a product concept, not a final requirements specification.
Product naming, stack selection, pricing, exact workflows and
implementation scope remain decisions to validate with pilot
institutions.

------------------------------------------------------------------------

## 2. Product Vision and Positioning

### 2.1 Vision

Provide education institutions with a dependable, accessible and
integrated digital platform that helps them manage every learner's
institutional journey, communicate with families, and support effective
learning.

### 2.2 Product promise

**One platform. Every learner. Every opportunity.**

This is a working positioning statement and should be tested with
prospective customers.

### 2.3 The problem to solve

Many institutions operate across disconnected processes and tools:

-   Admissions and learner information may be stored in spreadsheets or
    paper files.
-   Attendance may be recorded manually, with delayed or inconsistent
    reporting.
-   Parents may receive information through informal messaging groups or
    paper notices.
-   Fee balances, payments and receipts may be difficult to reconcile
    with learner records.
-   Results, report cards and academic histories may be scattered across
    files.
-   Digital learning platforms may not share identity or course
    information with school administration.
-   Child collection and early departure procedures may rely heavily on
    paper registers and staff memory.
-   Digital learning resources may be distributed without a central
    catalogue, reading history or access controls.

The platform should reduce this fragmentation without forcing every
institution into an unnecessarily complicated enterprise system.

### 2.4 Target customers

**Initial market** - Private ECD centres. - Private primary schools. -
Private secondary schools. - School groups operating multiple campuses.

**Expansion market** - Colleges and technical institutions. -
Universities and other tertiary providers. - Professional training
organisations. - Distance-learning and blended-learning providers.

**Potential later customers** - Education NGOs and training
programmes. - Corporate academies. - Education networks and school
associations.

### 2.5 Product principles

1.  **One platform, configurable experiences.** Avoid separate codebases
    for ECD, schools and colleges.
2.  **Mobile-first, not mobile-only.** Parents and teachers need simple
    mobile experiences, while administrators need capable desktop
    workflows.
3.  **Offline-aware.** Attendance and selected field workflows must
    remain usable during connectivity interruptions.
4.  **Safety before convenience.** Child release must involve authorised
    adults and staff verification.
5.  **Interoperability.** Use standards and documented APIs where
    possible.
6.  **Privacy by design.** Collect only data required for a defined
    educational or operational purpose.
7.  **Modular commercial packaging.** Institutions should pay for the
    capabilities they need.
8.  **Operational simplicity.** Avoid turning a school management
    platform into an accounting or enterprise resource planning system.
9.  **Reliable records before artificial intelligence.** Build trusted
    data first; add predictive insights only when there is sufficient
    quality and governance.
10. **Local realities matter.** Design for variable connectivity, shared
    devices, SMS costs, different levels of digital literacy and local
    payment practices.

------------------------------------------------------------------------

## 3. Market and Competitive Landscape

The Malawi market is not empty. Existing products already advertise
student management, attendance, fee management, results, portals and
parent communication. IQ-X should therefore avoid positioning itself as
the first or only school management system.

Examples identified during initial market research include:

  -----------------------------------------------------------------------
  Product                 Market / positioning    Publicly advertised
                                                  capabilities
  ----------------------- ----------------------- -----------------------
  EasySchool by Horizon   Malawi school           Admissions, exams,
  Labs Malawi             management              payroll, bank payments,
                                                  parent communication,
                                                  portals and Google
                                                  Classroom integration

  Mulowe SMS              Malawi / African school Student lifecycle,
                          management              staff, exams, fees,
                                                  attendance, analytics,
                                                  library and
                                                  guardian/student
                                                  portals

  SSIMS                   Malawi secondary school Grades, report cards,
                          management              fees, attendance and
                                                  role-based access

  OurSchool               Malawi school           Admissions, attendance,
                          management              grading, reports,
                                                  library, hostel and
                                                  fees

  OpenEduCat              International education Admissions, student
                          ERP                     information,
                                                  attendance, fees,
                                                  library, portals and
                                                  integrations

  Fedena                  International school    School administration,
                          ERP                     student records,
                                                  attendance and related
                                                  modules

  Alma SIS                International student   Student information,
                          information system      attendance, family
                                                  portals and analytics

  Classter                International           Student information,
                          SIS/LMS/ERP             learning management and
                                                  institutional
                                                  administration
  -----------------------------------------------------------------------

The capabilities and prices described by vendors are public marketing
claims, not independent product audits. Features, service quality,
support arrangements and actual customer adoption should be validated
directly before using this research for a formal competitive assessment.

### 3.1 Initial competitive lesson

Core administration alone is not a sufficient differentiator. A credible
product needs to compete on:

-   Ease of use and speed of common tasks.
-   Reliable attendance, including offline operation.
-   Parent communication that is useful and timely.
-   Safe, auditable learner release and pickup.
-   Quality of the student and guardian experience.
-   Useful digital learning and reading facilities.
-   Support, onboarding and dependable local implementation.
-   Integrations that prevent institutions from being locked into a
    single system.

### 3.2 Proposed differentiation

IQ-X should aim to be particularly strong in the intersection of:

1.  **Student administration**
2.  **Attendance intelligence and parent engagement**
3.  **SafeRelease child pickup management**
4.  **Digital library and learning experience**
5.  **Stride LMS integration**
6.  **Open APIs and interoperability**

The product should not try to out-feature every competitor at launch. It
should deliver a smaller set of workflows exceptionally well.

------------------------------------------------------------------------

## 4. Product Structure and Editions

### 4.1 One configurable platform

The recommended architecture is a unified platform with
institution-specific experiences.

A school, ECD centre and college share foundational concepts such as: -
Institutions and campuses. - People and identity. - Learner records. -
Guardians and emergency contacts where applicable. - Academic periods. -
Enrolment and status history. - Attendance events. - Communication
preferences. - Documents and audit trails.

They do not necessarily share every workflow. For example: - An ECD
centre needs child collection authorisations and daily handover
procedures. - A secondary school needs subjects, class registers,
examinations and report cards. - A college needs programmes, semesters,
course registration, credit structures and transcripts.

These should be implemented as configurable domain capabilities, not as
unrelated products.

### 4.2 Suggested editions

**EduCore ECD** - Child and guardian records. - Admissions and
enrolment. - Class/group allocation. - Daily attendance. - Parent
notifications. - SafeRelease pickup workflows. - Basic billing and
receipts. - Notices and daily communication.

**EduCore K-12** - Student information and admissions. - Classes,
subjects, teachers and timetables. - Attendance and absence
management. - Assessments, grading and report cards. - Fees and payment
records. - Parent and student portals. - NFC attendance options. -
SafeRelease where relevant. - Homework and learning resources.

**EduCore Institution** - Programmes, departments and faculties. -
Academic years, semesters and terms. - Course catalogue and
registration. - Lecturer and class management. - Session attendance and
student self-check-in. - Examinations, results and transcripts. -
Student self-service. - Stride LMS integration. - APIs, integrations and
advanced reporting.

These are commercial editions or feature profiles, not separate
codebases. An institution may enable additional modules as it grows.

------------------------------------------------------------------------

## 5. Functional Modules

### 5.1 Institution and campus management

Capabilities: - Institution profile and branding. - Multiple campuses or
branches. - Academic years, terms and semesters. - Departments,
faculties, sections and houses. - Classes, streams, cohorts and
groups. - Rooms, venues and facilities. - Configurable institution
calendar. - Institution-specific grading and attendance rules. - User
roles, permissions and approval structures.

### 5.2 Admissions and enrolment --- EduApply

Capabilities: - Online application forms. - Application status
tracking. - Supporting document uploads. - Admission decisions and
approval workflow. - Waiting lists. - Offer and acceptance management. -
Conversion from applicant to enrolled learner. - Re-enrolment and
progression. - Transfer-in and transfer-out workflows. - Admission
reporting.

Avoid duplicating learner profiles when a returning learner applies for
a new academic period. The application record and the persistent learner
identity should be distinct.

### 5.3 Student information system

A central learner profile should include: - Unique platform learner
identifier. - Institution-specific admission or registration number. -
Names and relevant demographic information. - Date of birth where
required. - Current and historical enrolments. - Guardian
relationships. - Emergency contacts. - Medical or welfare information
only where justified and access-restricted. - Academic history. -
Attendance history. - Documents and certificates. - Status history:
applicant, active, suspended, transferred, completed, withdrawn, etc.

The platform should maintain a clear distinction between a person's
identity and their enrolment at a particular institution. A learner may
have multiple enrolments over time without creating a new person record
each time.

### 5.4 Guardian and family relationships

Capabilities: - Multiple guardians per learner. - One guardian
associated with multiple learners. - Primary contact designation. -
Emergency contact designation. - Communication preferences. - Collection
permissions. - Temporary or time-bound authorisations. - Relationship
and authority documentation where required. - Access restrictions for
sensitive family circumstances.

Do not assume that every person listed as a guardian has identical legal
authority or collection rights. Institutions need controlled permissions
and an auditable process for changes.

### 5.5 Attendance --- EduTrack

Attendance should be treated as a major product capability rather than a
simple `present/absent` field.

#### Supported capture methods

-   Manual class register.
-   NFC card tap.
-   QR code scan.
-   Staff mobile attendance.
-   Student self-check-in for appropriate tertiary sessions.
-   Future integrations with dedicated readers or approved biometric
    devices.

The institution should choose which methods are enabled for each campus,
learner group or attendance context.

#### NFC student cards

A typical workflow: 1. Each learner receives a card containing a unique,
non-sensitive identifier or secure token. 2. A staff member uses an
Android NFC-capable phone or tablet as a reader. 3. The learner taps the
card. 4. The system validates the token and determines the relevant
learner and attendance context. 5. An attendance event is recorded with
the reader, location, time and capture method. 6. The dashboard updates.
7. A parent/guardian notification is sent according to the institution's
rules.

Start with compatible Android phones or tablets before investing in
custom reader hardware. Test actual NFC device compatibility and card
types before committing to procurement.

**Security:** Do not place a learner's full name, date of birth or other
personal details directly on the NFC tag. Use a random identifier or
cryptographically protected token, and provide a process for revoking
lost cards.

#### Offline-first attendance

Connectivity interruptions should not prevent attendance capture.

Recommended behaviour: - Reader devices maintain a locally encrypted
queue. - Each event receives a unique event identifier. - Events are
timestamped and associated with the device and capture context. - When
connectivity returns, events synchronise with the server. - The server
detects duplicate submissions idempotently. - Conflicts and suspicious
timestamps are flagged for review. - Staff can see whether a device has
unsynchronised events.

The system should distinguish the time an event was captured from the
time it reached the server.

#### Attendance event model

Avoid relying exclusively on a mutable table such as:

`student_id | date | present`

Instead, retain attendance events such as: - Check-in. - Check-out. -
Late arrival. - Absence recorded. - Manual attendance entry. -
Correction requested. - Correction approved. - Attendance session
closed.

Corrections should be separate, auditable actions rather than silently
overwriting the original event. This creates a more reliable history and
helps resolve disputes.

#### College self-check-in

A college session may follow a different workflow: 1. A lecturer opens
an attendance session for a course, venue and time. 2. The platform
generates a rotating, short-lived QR code. 3. Enrolled students scan the
code through the student app. 4. The server validates enrolment, session
status, token expiry and duplicate attempts. 5. The system records the
check-in and any supporting signals.

GPS/geofencing can be used as supporting evidence, but it should not be
treated as definitive proof of attendance. Indoor GPS can be inaccurate
and location can be spoofed. Use short-lived session tokens, server-side
time validation, enrolment checks and lecturer oversight. NFC cards may
be preferable where the institution already uses campus cards.

#### Attendance reporting

-   Daily attendance by class or campus.
-   Late arrivals and early departures.
-   Absence trends.
-   Attendance by learner and date range.
-   Attendance by subject/session for colleges.
-   Unusual patterns requiring staff review.
-   Parent notification delivery status.
-   Device synchronisation status.

### 5.6 SafeRelease --- child pickup and release management

SafeRelease is a proposed signature feature for ECD and younger learners
in particular.

The system must distinguish: - Arrival at the institution. - Departure
from the institution. - Release to an authorised collector.

These are different events and should not be collapsed into a single
attendance status.

#### Authorised collectors

A guardian can register or request approval for one or more authorised
collectors. Each record may include: - Collector's name. - Relationship
to the child. - Contact details. - Relevant identification details where
justified. - Approval status. - Validity period. - Any restrictions. -
Supporting documentation where required.

The institution must approve authorisations according to its policy.
Temporary authorisations should expire automatically.

#### Pickup workflow

1.  A guardian or authorised collector announces arrival using the app,
    web portal, QR credential or a staff-assisted process.
2.  A pickup request enters a queue.
3.  Staff see the learner, class/group and authorised collector details.
4.  Staff verify the collector's identity and authority.
5.  Staff retrieve or call the learner.
6.  Staff confirm the handover.
7.  The platform records the release event.
8.  The guardian receives a confirmation notification.

**Critical safeguarding rule:** scanning a QR code must never
automatically release a young child. QR is an identification and
workflow aid. Staff must verify the person and explicitly approve the
handover.

#### Additional SafeRelease capabilities

-   Emergency pickup authorisation.
-   Early departure requests.
-   Uncollected learner alerts.
-   Failed or disputed pickup attempts.
-   Manual fallback for families without smartphones.
-   Release history.
-   Staff accountability.
-   Bus boarding and drop-off extensions.
-   Parent arrival queue and estimated queue status where useful.

### 5.7 Academic management

For ECD and K-12: - Subjects and learning areas. - Class and subject
allocation. - Teacher assignment. - Assessment types. - Marks entry. -
Grade scales and grading rules. - Report card generation. - Academic
progression. - Promotion, repetition and transfer. - Examination
schedules. - Result approval and publication. - Historical results.

For colleges: - Programmes and qualifications. - Departments and
faculties. - Course catalogue. - Credit or unit structures. -
Prerequisites. - Semester registration. - Course registration. -
Lecturer allocation. - Assessment components. - Examination records. -
Grade calculation. - Academic standing and progression. - Transcripts
and completion records.

Institution-specific grading policies must be configurable. Do not
hard-code one grading model for all institutions.

### 5.8 Fees and payments --- EduFinance

Capabilities: - Fee structures by class, programme, term or semester. -
Learner invoices and statements. - Payment recording. - Payment
allocations. - Receipts. - Discounts and scholarships. - Approved
waivers. - Outstanding balance reports. - Payment reminders. -
Integration with supported payment providers where commercially
viable. - Reconciliation exports.

Keep EduFinance focused on education billing and receivables. It should
not attempt to replace a full accounting system. Provide exports and
integration points for accounting platforms.

Do not include unlimited SMS or payment-provider fees in a subscription
without modelling the underlying cost. Price variable services
transparently.

### 5.9 Parent and guardian portal

The parent portal is a core product capability, not an optional
afterthought.

A guardian should be able to: - View multiple linked learners. - See
attendance and arrival/departure events. - Receive important
notifications. - View report cards and academic progress. - View fee
statements, invoices and receipts. - Receive school notices and event
information. - Submit absence or leave requests. - Communicate with
authorised school staff. - Manage pickup authorisations. - Submit pickup
requests where enabled. - View timetables, examinations and homework. -
Access learning resources and digital books where permitted. - Update
selected contact information subject to approval.

Notification channels may include: - In-app notifications. - Push
notifications. - SMS. - Email. - WhatsApp, subject to provider
availability, consent, policy and cost.

Critical safeguarding notifications should have an appropriate fallback
channel. Routine notices should allow sensible preferences so that
families are not overwhelmed.

### 5.10 Student portal

**K-12 student experience** - Timetable. - Homework and assignments. -
Results and report cards. - Attendance summary. - Notices. - Learning
resources. - Digital library. - Stride learning activities where
enabled.

**College student experience** - Registration and enrolment. - Course
timetable. - Course materials. - Assignment submission. - Examination
timetable. - Results and transcript. - Fee statement. - Academic
document requests. - Digital student ID. - Library access. - Stride LMS
access.

### 5.11 Teacher and staff portal

Capabilities: - Class and subject registers. - Attendance capture. -
Marks entry. - Assignment and homework publishing. - Learner progress
views. - Class notices. - Timetable. - Leave or substitution information
where enabled. - Parent communication within institution policy. - Staff
task and approval queues.

Keep the teacher workflow fast. A teacher should not need to navigate
complex administrative screens to mark a class register or enter
results.

### 5.12 Digital Library and Learning Resources --- EduLibrary

The platform should include an online resource library for textbooks,
supplementary books, notes, past papers, reference material and
institution-approved learning content.

This should be more than a file upload page. It should become a
structured digital library with a comfortable reading experience.

#### Resource catalogue

Each resource may have: - Title and subtitle. - Author or creator. -
Publisher. - ISBN or other identifier where available. - Description. -
Cover image. - Subject and topic. - Grade, class, programme or course
relevance. - Language. - Publication date. - Format: PDF, EPUB, audio or
other supported format. - Copyright/licensing status. - Access
classification. - Institution or course ownership. - Availability
dates. - Version and replacement history.

#### Reading experience: Kindle-like interface

Yes, it is technically possible to provide a reading experience that
feels much closer to Kindle than to opening an ordinary PDF in a
browser. The important distinction is between **reflowable EPUB books**
and **fixed-layout PDF documents**.

**EPUB** - Text can reflow to fit the screen. - Readers can change font
family, font size, line spacing, margins and background theme. - It is
the better format for a Kindle-like reading experience. - It supports
more natural chapter navigation and reading positions.

**PDF** - Preserves the original page layout. - Useful for scanned
books, textbooks with diagrams, worksheets and official documents. - Can
support zoom, page navigation, search, bookmarks and annotations. - It
cannot always reflow cleanly into a phone-sized reading column without
changing the document's layout or converting it.

The platform should support both, but should prefer EPUB for books where
a reflowable source is available. It should not promise that every PDF
can be converted perfectly into a Kindle-style layout. OCR and
conversion may introduce errors, particularly with scanned books,
tables, equations and diagrams.

#### Proposed reader features

**MVP reader** - Browser-based reading. - Full-screen or
distraction-free mode. - Table of contents and chapter navigation for
EPUB. - Page navigation and zoom for PDF. - Remember last reading
position. - Continue reading. - Basic search where supported. -
Responsive desktop, tablet and mobile layout.

**Enhanced reader** - Font and text-size controls for EPUB. - Light,
sepia and dark themes. - Adjustable line spacing and margins. -
Bookmarks. - Highlights. - Personal notes. - Reading progress and
completion status. - Reading history. - Personal bookshelf and saved
resources. - Keyboard navigation. - Text selection and copy controls
according to content permissions. - Offline access for approved
resources, where technically and contractually permitted.

**Future enhancements** - Read-aloud / text-to-speech where supported. -
Audiobooks. - Reading goals and reading streaks. - Teacher-assigned
reading lists. - Class reading assignments. - Comprehension questions
and quizzes. - Shared annotations or teacher notes. - Reading analytics
for educators, designed carefully to avoid turning reading into
intrusive surveillance.

#### Technical options for the reader

**EPUB options** - **Readium Web:** an open-source toolkit for building
web readers. It supports EPUB through a TypeScript toolkit and has a
complementary publication-serving component. Thorium Web is a reference
reader built on this ecosystem. This is a strong standards-oriented
option to evaluate. - **epub.js:** a JavaScript library for rendering
EPUB content in the browser. Its API includes annotation support such as
highlights and underlines. It may be useful where a more custom React
reader UI is desired.

**PDF options** - **Mozilla PDF.js:** a web standards-based PDF
rendering platform. Its viewer can be used as a starting point for a
custom PDF reading interface. PDF.js provides rendering, text and
annotation-related layers; application-level bookmarks, reading progress
and personal notes can be stored by EduCore. - A commercial PDF SDK
could be evaluated later if advanced annotation, collaboration or form
workflows become essential. This is not necessary for the first release.

The reader should be implemented as a dedicated front-end experience,
not as a large collection of custom PDF manipulation code inside the
core student management application.

#### Suggested reader architecture

1.  EduLibrary manages catalogue metadata, access rights, resource
    versions and lending/availability rules where applicable.
2.  Original files are stored in private object storage, such as
    S3-compatible storage.
3.  The application issues short-lived, authorised access URLs or
    streams content through a controlled endpoint.
4.  The reader selects the appropriate engine based on format: EPUB
    reader or PDF.js.
5.  Reading progress, bookmarks, highlights and notes are stored as
    user-specific records in the platform.
6.  The platform synchronises progress across devices when the user is
    online.
7.  Institution policies control whether a resource can be downloaded,
    printed, shared or accessed after a course ends.

#### Suggested data concepts

-   `library_resources`
-   `library_resource_files`
-   `library_categories`
-   `library_collections`
-   `library_access_rules`
-   `library_loans` (only if lending is part of scope)
-   `reading_progress`
-   `reading_bookmarks`
-   `reading_highlights`
-   `reading_notes`
-   `reading_assignments`
-   `resource_access_events`

A reading position should be format-aware. EPUB positions can use a
publication-specific locator such as a CFI or Readium locator. PDF
positions can use page number and, where useful, a more precise
location. Avoid a single generic page number field for all formats.

#### Copyright and licensing

The institution must have the right to distribute each digital resource
to the intended users. A platform feature that technically permits
uploading a book does not grant permission to distribute it.

Recommended controls: - Record the source and licence of every
resource. - Distinguish public-domain, open-licensed, institution-owned
and commercially licensed material. - Apply access restrictions based on
licence terms. - Provide takedown and rights-holder contact
procedures. - Do not assume that a purchased print textbook can
automatically be scanned and distributed digitally. - Do not promise DRM
circumvention or unrestricted downloads.

For the first release, support institution-owned documents, open
educational resources and content for which the institution has explicit
digital distribution rights.

### 5.13 Learning Management System --- Stride IQ-X integration

Stride IQ-X should remain an independent LMS product. EduCore should
manage the institution and learner lifecycle; Stride should manage
structured learning delivery.

**EduCore responsibilities** - Institution and user identity. - Learner
enrolment. - Programme and course registration. - Class or cohort
membership. - Academic records. - Fee and administrative information. -
Institutional reporting.

**Stride responsibilities** - Course content and learning paths. -
Lessons and learning activities. - Assignments and submissions. -
Quizzes and assessments where appropriate. - Learning progress. - Course
completion. - Learning analytics.

#### Example integration flow

1.  A learner is admitted and enrolled in EduCore.
2.  EduCore provisions or links the learner's Stride account.
3.  Course registration in EduCore creates or updates the relevant
    Stride course membership.
4.  The learner accesses Stride through single sign-on.
5.  Stride records learning activity, assignment submissions and
    outcomes.
6.  Approved results or completion information synchronise back to
    EduCore.
7.  EduCore combines institutional academic information with learning
    activity for authorised reporting.

#### Standards to evaluate

-   **OpenID Connect (OIDC):** identity and single sign-on.
-   **LTI 1.3 / LTI Advantage:** secure integration between learning
    tools and platforms.
-   **OneRoster:** exchange of roster, course, class and gradebook
    information where supported and suitable.
-   **REST APIs and webhooks:** application-specific integration where a
    standard does not cover the required workflow.

Do not build a proprietary integration protocol if a mature standard
already solves the problem. The exact standard support and
implementation responsibilities must be verified for the chosen Stride
architecture.

### 5.14 Timetabling and scheduling --- EduPlan

-   Class and subject timetables.
-   Lecturer schedules.
-   Room and venue allocation.
-   Examination timetables.
-   Substitution and rescheduling.
-   Academic calendar.
-   Event scheduling.
-   Conflict detection.

### 5.15 Transport --- EduRoute

Potential later module: - Vehicles and driver records. - Routes and
stops. - Learner route allocation. - Boarding and drop-off events. - Bus
manifests. - Guardian notifications. - Vehicle and route reporting.

Transport should be added after the core attendance and release model is
stable.

### 5.16 Library circulation

If EduLibrary expands beyond digital resources: - Physical book
catalogue. - Barcode or RFID inventory. - Borrowing and returns. -
Reservations. - Overdue notices. - Fines where institution policy
permits. - Stock and circulation reports.

Digital library and physical library circulation can share catalogue
concepts, but should not be forced into one workflow.

### 5.17 Analytics and institutional insights --- EduInsights

Initial dashboards: - Enrolment by year, campus, class and programme. -
Attendance and lateness trends. - Learner progression. - Assessment and
grade distributions. - Fee collection and outstanding balances. - Parent
portal engagement. - Library usage. - Stride participation and course
completion.

Future indicators may identify learners who need additional support.
These should be explainable indicators that assist educators, not opaque
automated decisions. Avoid labelling a learner as a dropout or failure
based solely on a predictive score.

### 5.18 Staff, assets and other future modules

Potential later capabilities: - Staff profiles and role assignments. -
Staff attendance and leave. - Basic asset register. - Hostel/residence
management. - Student welfare case management. - Alumni records. -
Certificates and document requests. - Institutional accreditation
evidence.

These should be considered only after validating demand and the core
product's commercial traction.

------------------------------------------------------------------------

## 6. Portals and User Experiences

The platform should provide role-appropriate interfaces rather than
expose one administrative interface to everyone.

### 6.1 Platform administrator

-   Tenant and institution provisioning.
-   Subscription and module management.
-   Platform health and usage.
-   Support access with explicit audit trail.
-   Integration and provider configuration.
-   Global configuration and feature flags.

### 6.2 Institution administrator

-   Institution configuration.
-   User and role management.
-   Admissions and enrolment.
-   Academic setup.
-   Attendance oversight.
-   Finance administration.
-   Reports and exports.
-   Parent and student communication.
-   Approval queues.

### 6.3 Teacher / lecturer

-   Personal timetable.
-   Class or session register.
-   Attendance.
-   Marks and assessment entry.
-   Homework and learning activities.
-   Learner progress.
-   Approved parent communication.

### 6.4 Parent / guardian

-   Linked learners.
-   Attendance and alerts.
-   Results and reports.
-   Fee information.
-   Notices and communication.
-   Pickup authorisations.
-   Pickup requests and release confirmations.
-   Learning resources.

### 6.5 Student

-   Academic information.
-   Timetable and course registration.
-   Attendance.
-   Assignments and learning resources.
-   Digital library.
-   Stride access.
-   Fee statements and student services where enabled.

### 6.6 Mobile applications

A Flutter application is a reasonable option for a shared Android/iOS
parent and student application, with role-specific experiences. A
separate staff/reader app should be considered only if field workflows
justify it.

A progressive web application may be sufficient for the first release,
especially for administrative users. NFC support and offline
requirements should be validated against target devices and browser
capabilities before choosing a PWA-only approach.

------------------------------------------------------------------------

## 7. Technical Architecture

### 7.1 Proposed technology choices

The final stack is not yet decided. The two main front-end directions
should remain open:

  -----------------------------------------------------------------------
  Layer                   Option A                Option B / alternatives
  ----------------------- ----------------------- -----------------------
  Backend                 Laravel                 FastAPI for selected
                                                  services only if
                                                  justified

  Administrative web UI   React                   Vue 3

  Laravel-native admin    Filament v4             Custom React or Vue
                                                  application

  Web application         React + Inertia or      Vue 3 + Inertia or
  approach                API-driven React        API-driven Vue

  Mobile                  Flutter                 PWA for selected
                                                  workflows

  Database                PostgreSQL              ---

  Cache / queues          Redis                   ---

  Realtime                Laravel Reverb /        Push notifications for
                          WebSockets              mobile

  Object storage          S3-compatible storage   Cloudflare R2 or
                                                  equivalent

  Analytics               Metabase initially      Apache Superset

  Deployment              Docker, Nginx, Ubuntu   Managed deployment
                                                  where commercially
                                                  justified

  LMS                     Stride IQ-X             External LMS
                                                  integration where
                                                  needed
  -----------------------------------------------------------------------

**React versus Filament decision:** Filament is not a direct equivalent
to React. Filament is a Laravel admin-panel framework, while React is a
front-end library. They can coexist: for example, Filament for internal
administration and React for parent/student portals. Alternatively, a
custom React application can handle the whole web experience. Keep the
choice open until UX complexity, development speed, team capability and
long-term maintenance are assessed.

### 7.2 Recommended high-level architecture

A modular monolith is the recommended starting point.

-   One Laravel application with clear domain boundaries.
-   PostgreSQL as the primary relational database.
-   Redis for cache, queues and transient state.
-   Background workers for notifications, imports, reports and
    integrations.
-   Object storage for documents, books and media.
-   Reverb or another WebSocket layer for live dashboards and pickup
    queues.
-   Separate Stride LMS integration boundary.
-   Flutter or responsive web applications for mobile use.

Do not begin with microservices. A modular monolith is easier to
develop, deploy, test and operate for an early-stage product. Domain
boundaries can later be extracted if scale or team structure justifies
it.

### 7.3 Multi-tenancy

Recommended initial model: shared application and shared database, with
tenant ownership explicitly represented on institution-owned records.

Key considerations: - Every tenant-owned record must be scoped to an
institution or tenant. - Tenant context must be established and
validated server-side. - Do not trust a client-submitted
`institution_id` as proof of access. - Apply tenant-aware policies and
query scopes consistently. - PostgreSQL Row-Level Security may provide
an additional isolation layer, but should not replace application
authorisation. - Platform support access must be controlled and
audited. - Larger institutions may later be offered dedicated databases
or deployments.

Use a deliberate tenant model. If school groups operate multiple
campuses, distinguish the organisation/tenant from individual
institutions and campuses.

### 7.4 Domain boundaries

Suggested domains: - Identity and access. - Tenancy and institution
configuration. - Admissions. - Student information. - Guardian
relationships. - Academic structure. - Attendance. - SafeRelease. - Fees
and payments. - Communication. - Library and reading. - Learning /
Stride integration. - Reporting and analytics. - Integrations and API. -
Audit and compliance.

Each domain should own its business rules and expose well-defined
application services or events. Avoid building one enormous set of
controllers and models with implicit dependencies.

### 7.5 API-first integration

Provide a versioned API for: - Mobile applications. - Stride
integration. - Payment providers. - SMS and communication providers. -
External school systems. - Future government or education ecosystem
integrations, where authorised.

Use webhooks for events such as: - Learner admitted. - Enrolment
activated. - Attendance recorded. - Learner released. - Fee payment
received. - Course registration changed. - Result published. - Library
resource assigned.

Webhook delivery should include signatures, retries, idempotency and
delivery logs.

### 7.6 Identity and access control

-   Role-based access control with institution-aware permissions.
-   Separate platform-level and institution-level roles.
-   Least-privilege defaults.
-   Multi-factor authentication for privileged users.
-   Secure password reset and session management.
-   Optional SSO for larger institutions.
-   Auditable support access.
-   Explicit guardian-to-learner relationship permissions.
-   Time-bound access for temporary staff or collectors.

### 7.7 Data and audit model

Important records should retain: - Who performed the action. - What
changed. - When it happened. - Which institution and context were
involved. - Source device or integration where relevant. - Approval or
correction history.

Sensitive actions such as editing learner identity, changing guardian
authority, modifying attendance and confirming child release should be
auditable.

### 7.8 Notifications and messaging

Use a provider abstraction so that the product is not tied to one SMS or
messaging company.

Support: - In-app notifications. - Email. - SMS. - Push notifications. -
WhatsApp integration where feasible.

Track delivery status, retries and provider errors. Critical
notifications should have defined fallback behaviour. Pricing should
account for per-message costs.

------------------------------------------------------------------------

## 8. Data Model --- Initial Conceptual Entities

This is a starting point for domain modelling, not a final database
schema.

### 8.1 Platform and tenancy

-   `tenants`
-   `institutions`
-   `campuses`
-   `institution_settings`
-   `subscriptions`
-   `subscription_modules`
-   `users`
-   `roles`
-   `permissions`
-   `user_institution_memberships`

### 8.2 People and enrolment

-   `people`
-   `students`
-   `guardians`
-   `student_guardians`
-   `emergency_contacts`
-   `admission_applications`
-   `enrolments`
-   `student_identifiers`
-   `student_documents`
-   `student_status_history`

### 8.3 Academic structure

-   `academic_years`
-   `academic_periods`
-   `departments`
-   `programmes`
-   `courses`
-   `subjects`
-   `classes`
-   `class_groups`
-   `course_offerings`
-   `student_course_registrations`
-   `teacher_assignments`
-   `timetables`
-   `assessments`
-   `assessment_results`
-   `grade_scales`
-   `report_cards`

### 8.4 Attendance

-   `attendance_sessions`
-   `attendance_events`
-   `attendance_corrections`
-   `attendance_devices`
-   `attendance_credentials`
-   `attendance_sync_batches`
-   `attendance_rules`

### 8.5 SafeRelease

-   `authorised_collectors`
-   `student_collector_authorisations`
-   `pickup_requests`
-   `pickup_queue_events`
-   `student_release_events`
-   `release_verifications`
-   `temporary_pickup_authorisations`

### 8.6 Finance

-   `fee_structures`
-   `fee_items`
-   `student_invoices`
-   `invoice_lines`
-   `payments`
-   `payment_allocations`
-   `receipts`
-   `scholarships`
-   `discounts`
-   `fee_adjustments`

### 8.7 Communication

-   `notifications`
-   `notification_templates`
-   `notification_deliveries`
-   `communication_preferences`
-   `announcements`
-   `message_threads`

### 8.8 Digital library

-   `library_resources`
-   `library_resource_files`
-   `library_categories`
-   `library_collections`
-   `library_access_rules`
-   `library_loans`
-   `reading_progress`
-   `reading_bookmarks`
-   `reading_highlights`
-   `reading_notes`
-   `reading_assignments`
-   `resource_access_events`

### 8.9 Integrations and audit

-   `integration_connections`
-   `integration_events`
-   `webhook_endpoints`
-   `webhook_deliveries`
-   `audit_logs`
-   `data_export_jobs`

Use stable internal identifiers such as UUIDs or ULIDs where
appropriate. Human-facing admission numbers and student registration
numbers should remain separate from internal primary keys.

------------------------------------------------------------------------

## 9. Privacy, Safeguarding and Security

The platform will process information about children and young people.
Privacy and safeguarding cannot be deferred until after launch.

### 9.1 Data minimisation

-   Collect only what is necessary for defined institutional purposes.
-   Do not collect continuous location data for ordinary attendance.
-   Do not collect facial templates for the MVP.
-   Do not collect broad household socioeconomic data without a clear
    lawful basis and purpose.
-   Avoid exposing sensitive information in notifications visible on
    shared phones.

### 9.2 Child safety

-   Verify authorised collectors.
-   Require staff confirmation for release.
-   Retain an auditable release history.
-   Support emergency and temporary authorisation workflows.
-   Provide manual fallback procedures.
-   Restrict who can view child and family details.
-   Define procedures for disputed or failed collection attempts.

### 9.3 Security controls

-   Encryption in transit.
-   Encryption at rest for sensitive storage.
-   Secure object storage and time-limited access URLs.
-   Strong access control.
-   Multi-factor authentication for privileged users.
-   Audit logs.
-   Backup and recovery procedures.
-   Secure development and dependency management.
-   Rate limiting and abuse monitoring.
-   Device revocation for lost attendance readers.
-   Secure card token revocation and replacement.

### 9.4 Governance

Before production deployment, define: - Data controller and processor
responsibilities. - Legal basis and consent requirements where
applicable. - Retention schedules. - Data subject access and correction
procedures. - Breach response procedures. - Vendor and subprocessor
arrangements. - Cross-border hosting considerations. - Child-specific
safeguards. - Copyright and licensing controls for digital resources.

The legal and regulatory position should be reviewed with qualified
local advice before onboarding real learners.

------------------------------------------------------------------------

## 10. Commercial Model and Packaging

### 10.1 Proposed packages

**Essential** - Small ECD centres and schools. - Admissions and learner
records. - Classes and basic academic setup. - Attendance. - Basic
assessments and reports. - Guardian portal. - Basic notifications.

**Professional** - Primary and secondary schools. - Everything in
Essential. - NFC attendance options. - SafeRelease. - Fees and
receipts. - Parent and teacher mobile experience. - Timetabling. -
Advanced reports. - Multiple campuses where applicable. - More
communication automation.

**Institution** - Colleges and larger education providers. - Programme
and semester management. - Course registration. - Advanced academic
records. - Student self-service. - Stride integration. - API access and
integrations. - Advanced analytics. - Enhanced support and deployment
options.

### 10.2 Pricing structure

Evaluate a combination of: - Annual or term-based subscription. -
Enrolled learner bands. - One-time onboarding and implementation. -
Optional modules. - NFC cards and reader hardware charged separately. -
SMS, WhatsApp and other consumption-based services billed
transparently. - Premium integrations and dedicated support. - Optional
dedicated hosting for larger institutions.

Do not charge parents simply to view their child's basic records. Parent
engagement is part of the value institutions purchase.

### 10.3 Implementation and onboarding

A commercial package should include a defined onboarding process: 1.
Institution discovery and configuration. 2. Data template preparation.
3. Data cleansing and import. 4. User and role setup. 5. Staff training.
6. Parent/guardian onboarding support. 7. Pilot period. 8. Go-live and
support handover.

Implementation effort should be priced realistically. Data migration and
staff training can consume more time than software configuration.

### 10.4 Pricing research caution

Initial Malawi vendor websites advertise a range of price structures,
including annual institution packages, per-student/per-term pricing and
monthly tiers. These public figures are useful as market signals, not as
a final IQ-X price benchmark. Confirm current quotations, inclusions,
support and taxes directly before setting prices.

------------------------------------------------------------------------

## 11. Delivery Roadmap

The roadmap is deliberately staged. It should be adjusted after
discovery interviews and pilot feedback.

### Release 0 --- Discovery and validation

-   Interview ECD, primary, secondary and college administrators.
-   Observe admissions, attendance, fee and report-card workflows.
-   Identify current tools and data quality.
-   Validate willingness to pay.
-   Test NFC hardware and card options.
-   Validate parent notification needs and SMS economics.
-   Confirm legal, privacy and safeguarding requirements.
-   Choose initial pilot institutions.
-   Decide React/Vue/Filament composition and mobile strategy.
-   Confirm whether the first release is school-focused or includes a
    narrow college pilot.

**Exit criteria:** validated problem statements, prioritised workflows,
pilot commitments and a product scope that can be delivered.

### Release 1 --- Core school management

-   Multi-tenancy and institution setup.
-   Academic years and terms.
-   Admissions.
-   Learner profiles.
-   Guardian relationships.
-   Classes, subjects and teacher assignments.
-   Manual and QR attendance.
-   Basic assessments and grading.
-   Report cards.
-   Essential reporting.
-   Audit and role-based access.

**Goal:** replace core spreadsheets and paper-based administrative
processes for pilot schools.

### Release 2 --- Parent engagement and attendance differentiation

-   Parent portal.
-   Student portal foundations.
-   Notification service.
-   NFC attendance.
-   Offline attendance capture and synchronisation.
-   SafeRelease.
-   Fees, invoices and receipts.
-   Teacher mobile workflows.
-   Operational dashboards.

**Goal:** deliver the product's strongest everyday value to schools and
families.

### Release 3 --- Digital learning and library

-   EduLibrary catalogue.
-   Private document storage and access control.
-   PDF reader.
-   EPUB reader.
-   Reading progress and bookmarks.
-   Basic highlights and notes where supported.
-   Teacher-assigned reading lists.
-   Stride SSO and initial course/roster integration.

**Goal:** connect administration with learning resources and structured
online learning.

### Release 4 --- College and tertiary workflows

-   Programmes and departments.
-   Semesters.
-   Course catalogue and registration.
-   Lecturer allocation.
-   Session attendance and QR self-check-in.
-   Examination management.
-   Transcripts.
-   Stride course and grade synchronisation.
-   College student self-service.

**Goal:** extend the platform into tertiary education without
compromising school workflows.

### Release 5 --- Intelligence and ecosystem

-   Advanced institutional analytics.
-   Explainable early-support indicators.
-   Public API and webhooks.
-   Third-party SIS/LMS integrations.
-   Transport and physical library options.
-   Advanced institutional group reporting.
-   Additional deployment and support tiers.

**Goal:** become an extensible education platform rather than a closed
school application.

------------------------------------------------------------------------

## 12. Pilot Strategy and Success Measures

### 12.1 Pilot selection

Start with a small number of institutions that are willing to provide
feedback and participate in workflow validation. A useful pilot mix may
include: - One ECD centre to validate guardian and SafeRelease
workflows. - One primary or secondary school to validate the core school
model. - A college only after the core model is stable, unless a strong
early partner is available.

Avoid onboarding too many institutions before support, data migration
and training processes are repeatable.

### 12.2 Pilot success measures

**Operational** - Time required to register a learner. - Time required
to complete a class register. - Attendance capture success rate. -
Offline event synchronisation success. - Reduction in manual duplicate
data entry. - Report-card preparation time.

**Parent engagement** - Guardian account activation. - Notification
delivery success. - Parent portal usage. - Time between attendance event
and notification. - Pickup workflow completion and exception rates.

**Academic** - Completeness of assessment records. - Time to publish
results. - Accuracy of grade calculations. - Learner progression data
completeness.

**Commercial** - Onboarding cost per institution. - Support effort per
institution. - Renewal intent. - Paid conversion. - Cost of messaging
and storage per learner. - Module adoption.

### 12.3 Feedback loop

Run structured reviews with administrators, teachers, parents and
students. Observe real usage rather than relying only on feature
requests. Prioritise friction in high-frequency workflows before adding
more modules.

------------------------------------------------------------------------

## 13. Key Product Decisions Still Open

These decisions should remain explicitly open rather than being assumed:

1.  Final product name and brand architecture.
2.  React versus Vue for the main web application.
3.  Whether Filament is used for internal administration, all admin
    workflows, or not at all.
4.  PWA versus Flutter for initial parent and staff experiences.
5.  First commercial segment: ECD, K-12 or a carefully scoped
    combination.
6.  Whether NFC is included in the first paid release or offered as an
    add-on.
7.  Whether SafeRelease is a launch differentiator or a second-release
    capability.
8.  Whether EduLibrary begins with PDFs only or launches with both PDF
    and EPUB.
9.  Whether the first reader is built with PDF.js plus an EPUB toolkit
    or adopts a broader Readium-based approach.
10. Exact Stride integration standards and data ownership.
11. Hosting model and dedicated deployment thresholds.
12. Pricing and onboarding structure.
13. Data retention, privacy and safeguarding policies.

------------------------------------------------------------------------

## 14. Risks and Mitigations

  -----------------------------------------------------------------------
  Risk                    Potential impact        Mitigation
  ----------------------- ----------------------- -----------------------
  Overly broad first      Delayed launch and poor Focus on core workflows
  release                 quality                 and pilot feedback

  Weak adoption by        Incomplete or           Make frequent workflows
  teachers                unreliable data         fast and provide
                                                  training

  Poor connectivity       Lost or delayed         Offline queue,
                          attendance              idempotent sync and
                                                  device status

  NFC hardware            Deployment friction     Test devices/cards
  incompatibility                                 early; retain QR/manual
                                                  fallback

  Unsafe child release    Serious safeguarding    Staff verification,
  workflow                risk                    explicit approval and
                                                  audit history

  Incorrect guardian      Unauthorised access or  Institution-approved
  permissions             collection              relationship and
                                                  authorisation workflows

  SMS costs               Unprofitable            Usage limits,
                          subscriptions           transparent pricing and
                                                  provider abstraction

  Poor data migration     Loss of trust           Data templates,
                                                  validation and
                                                  controlled import

  Tenant data leakage     Severe security impact  Server-side tenant
                                                  scoping, policies,
                                                  tests and optional RLS

  Copyright infringement  Legal and reputational  Rights metadata,
                          exposure                licence checks and
                                                  takedown process

  PDF conversion errors   Poor reading experience Preserve original PDF;
                                                  prefer EPUB source
                                                  where available

  LMS duplication         Confusing product       Keep Stride independent
                          boundaries              and define clear
                                                  ownership

  AI bias or false alerts Harmful learner         Explainable indicators,
                          decisions               human review and
                                                  delayed introduction

  Competitor price        Reduced margins         Differentiate on
  pressure                                        experience, support,
                                                  safety and reliability
  -----------------------------------------------------------------------

------------------------------------------------------------------------

## 15. Important Strategic Boundaries

### 15.1 Commercial platform versus national education infrastructure

This commercial product is distinct from the longer-term idea of a
national education management and intelligence infrastructure.

The commercial product should not initially depend on: - National birth
registration integration. - Household or socioeconomic databases. - A
government-wide persistent education identifier. - National
population-to-enrolment reconciliation. - Government-wide dropout
prediction.

Those ideas may become relevant through formally authorised partnerships
and standards in the future, but they are not prerequisites for a
commercially viable institution-level product.

The commercial platform should use its own stable learner identity and
support institution-specific identifiers. It should provide APIs and
data export so that future authorised integrations remain possible.

### 15.2 Avoid AI-first development

The first priority is accurate, timely and governed operational data.
Predictive features should be introduced only after: - Sufficient
historical data exists. - Data quality is understood. - The institution
has a real intervention process. - Indicators can be explained. - Human
review remains central. - Privacy and fairness risks are assessed.

### 15.3 Do not overbuild finance

Education billing and payment records are essential. Full general
ledger, payroll, procurement and accounting functionality are not
required to prove the product's value. Integrate with accounting systems
where necessary.

### 15.4 Do not force NFC

NFC is a useful option, not a universal requirement. Manual attendance
and QR workflows should remain available. Some institutions may have
budget, device or operational constraints.

### 15.5 Do not turn location into surveillance

GPS should only be used where it has a defined, proportionate purpose.
Avoid continuous tracking of learners or parents. For session check-in,
location is supporting evidence rather than definitive proof.

------------------------------------------------------------------------

## 16. Recommended Immediate Next Steps

1.  **Conduct discovery interviews.** Speak to administrators, teachers,
    parents and college registrars.
2.  **Choose the first pilot segment.** Private primary/secondary
    schools are the recommended starting point, with ECD as a focused
    adjacent pilot.
3.  **Map five high-value workflows.** Admissions, learner registration,
    attendance, parent notification and report-card publication.
4.  **Prototype EduTrack and SafeRelease.** These are potential
    differentiators and deserve early validation.
5.  **Run a hardware proof of concept.** Test NFC cards and Android
    reader devices under realistic school conditions.
6.  **Prototype the digital reader.** Test one EPUB and one PDF in a
    React interface; evaluate Readium Web, epub.js and PDF.js before
    committing.
7.  **Define the Stride boundary.** Document which system owns identity,
    enrolment, course content, learning activity and grades.
8.  **Make the front-end decision deliberately.** Compare React, Vue and
    Filament based on the actual workflows and development trade-offs.
9.  **Create the product requirements document.** Convert validated
    workflows into user stories, permissions, acceptance criteria and
    release scope.
10. **Build a narrow pilot, not the whole vision.** Measure adoption and
    support effort before expanding.

### Recommended first vertical slice

A practical first end-to-end demonstration should allow an institution
to: 1. Create an academic year and class. 2. Register a learner and
guardian. 3. Record attendance manually or by QR. 4. Notify the
guardian. 5. Show the event in the parent portal. 6. Record an
authorised pickup request. 7. Require staff verification and confirm
release. 8. View the full audit history.

This vertical slice proves the platform's core identity, attendance,
communication, safeguarding and audit foundations.

------------------------------------------------------------------------

## 17. Reference Products and Technical Resources

The following links are starting points for further product and
technical evaluation. They are not endorsements and should be reviewed
for current features, licensing and maturity before implementation.

### Malawi and regional market references

-   [EasySchool --- Horizon Labs
    Malawi](https://horizonlabsmw.com/easyschool/)
-   [Mulowe SMS](https://mulowe.com/)
-   [SSIMS](https://ssimsapp.com/)
-   [OurSchool Malawi](https://www.ourschool.mw/pricing.php)

### International education platforms

-   [OpenEduCat](https://www.openeducat.org/)
-   [Fedena](https://fedena.com/)
-   [Alma SIS](https://www.getalma.com/)
-   [Classter](https://www.classter.com/)

### Attendance and student release references

-   [Dismissal.ai](https://dismissal.ai/)
-   [Talliez](https://talliez.com/)

These products can be reviewed for workflow inspiration. IQ-X should
independently validate which features are relevant to the local market.

### Digital reading and document technology

-   [Readium Web](https://readium.org/web/) --- open-source web reader
    toolkit focused on EPUB and web publications.
-   [Readium Web project](https://github.com/readium/web) --- source
    code and project status.
-   [Thorium Reader](https://www.edrlab.org/software/thorium-reader/)
    --- open-source reading application in the Readium ecosystem.
-   [Readium Playground](https://playground.readium.org/) --- reference
    implementation and demonstration.
-   [epub.js](https://github.com/futurepress/epub.js) --- JavaScript
    EPUB rendering library; its API documents highlights and
    annotations.
-   [Mozilla PDF.js](https://mozilla.github.io/pdf.js/) ---
    browser-based PDF rendering.
-   [PDF.js Getting
    Started](https://mozilla.github.io/pdf.js/getting_started/) ---
    architecture and viewer guidance.

### Learning interoperability

-   [1EdTech LTI standards](https://www.1edtech.org/standards/lti)
-   [1EdTech OneRoster](https://www.1edtech.org/standards/oneroster)
-   [OpenID Connect](https://openid.net/developers/how-connect-works/)

### LMS reference

-   [Moodle](https://moodle.org/) --- useful reference for LMS
    capabilities and mobile/offline learning patterns.

------------------------------------------------------------------------

## 18. Final Recommendation

Build EduCore IQ-X as a **unified, modular, multi-tenant education
platform**, with configurable experiences for ECD, K-12 and tertiary
institutions.

Start with private primary and secondary schools, and validate ECD
workflows through a focused pilot. Build the student information
foundation, admissions, attendance, guardian relationships and core
academic records first. Then add the two strongest differentiators:
**EduTrack attendance** and **SafeRelease**.

Treat EduLibrary as a real learning product capability, not merely
document storage. Support PDF for fixed-layout material and EPUB for a
genuine reflowable, Kindle-like reading experience. Keep reading
progress, notes and bookmarks in the platform so learners can continue
across devices.

Keep Stride IQ-X independent and integrate it through clear identity,
roster and learning-result contracts. Keep the technology decision open:
Laravel is a sound backend foundation, while React, Vue and Filament
should be selected based on the final user experience and delivery
strategy.

Most importantly, do not build the entire vision before proving demand.
Deliver a focused pilot, measure whether institutions and families
actually use it, and let evidence guide the next releases.

**The opportunity is not to build another school database. It is to
build an education platform that makes institutional operations more
reliable, family engagement more meaningful, child handovers safer, and
learning resources easier to access.**
