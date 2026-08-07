# 10 — The Printable-Document Suite

**Version:** 2.0
**Status:** Draft for review
**Owns:** every document the product renders to paper or PDF — catalogue, data contracts, series, signatures, snapshot policy, bulk printing, reprints, QR verification.
**Binding upstream:** `00-core.md` (naming §5, sequences §12, **document integrity §13**, audit & print logging §14, build order §15). Where this document and `00-core.md` appear to disagree, `00-core.md` wins.

Cross-references: `01-assessment.md` (report cards, snapshots, `ReportCardConfig` versioning), `04-fees.md` (receipts, invoices, statements), `05-hr-payroll.md` (payslips, certificat de travail), `06-assets-stores.md` (library card, stock reports), `07-students.md` (admission, enrollment, attendance), `08-operations.md` (PDF engine, performance budget), `09-ui.md` (Bulk Prints screen chrome, Reports catalogue).

---

## 1. Why this document exists

v1 named **eight** printable documents. The sources define **36–50**:

| Source | Count |
|---|---|
| `ChatGPT Image Jul 13, 2026, 08_14_44 PM.png` | templates **1–18** |
| `ChatGPT Image Jul 13, 2026, 08_14_40 PM.png` | templates **19–36** |
| `complete product overview.png` | 68-item roadmap; §3 "Printable Reports & Documents (32)", §4 "Certificates & ID Cards (6)", and §B "Additional Printable Outputs" (17 further outputs) |
| .NET reference `Opes.SchoolErp.Infrastructure\Reporting\` | **37 document renderers** (`*Document.cs` + `*Service.cs` pairs) |

v1's coverage was therefore ~16–22%. This document enumerates **58 documents** (§5–§16), of which **41 are v1** and **17 are later**.

The reference renderer inventory, verified by listing the directory (never modified):

`AdmissionForm · BehaviourReport · BookRequest · ClassList · ClassRegister · CompletionCertificate · ConductCertificate · ExcursionPermission · FeeInvoice · FeeStatement · GatePass · HomeworkLog · HostelOccupancyReport · LeaveApplication · LeavingCertificate · LibraryCard · LostFoundLog · MaintenanceRequest · MedicalIntakeForm · MonthlyAttendanceSummary · ParentMeetingForm · Payslip · Receipt · ReportCard · SeatingPlan · SportsParticipation · StaffAttendanceSheet · StatementOfResults · StudentIdCard · StudentInfoSheet · TabularReport · Testimonial · TimetablePrint · Transcript · TransferCertificate · TransportRequest · VisitorPass`

Shared reference infrastructure worth porting as concepts, not code: `Letterhead`, `DocumentFooter`, `DocumentQr`, `Code39`, `CardStyling`, `CertificateStyling`, `HeritageArt`, `DocumentStrings` (i18n), `FileNameSanitizer`, `GeneratedDocumentPath`, `BulkPrintService`, `TabularReportDocument` (the generic list renderer behind most register/log outputs).

---

## 2. Document-integrity policy — restated and binding

**`00-core.md` §13 is binding on every document in this suite.** It is restated here in full operative form because this is the document an implementer has open when they are actually laying out a certificate.

### 2.1 Permitted

The **bilingual state letterhead** is permitted and is *standard* on real Cameroonian school documents:

```
RÉPUBLIQUE DU CAMEROUN            REPUBLIC OF CAMEROON
Paix – Travail – Patrie           Peace – Work – Fatherland
MINISTÈRE DES ENSEIGNEMENTS       MINISTRY OF SECONDARY
SECONDAIRES                       EDUCATION
DÉLÉGATION RÉGIONALE DU …         REGIONAL DELEGATION FOR …
DÉLÉGATION DÉPARTEMENTALE DU …    DIVISIONAL DELEGATION FOR …
```

Rendered by a single reusable `state_header` block, configured in `SchoolProfile` (`state_header_enabled`, `ministry`, `regional_delegation`, `divisional_delegation`, per-`SchoolSection` override because a bilingual school's MINEDUB nursery and MINESEC secondary sit under different ministries). It is text only — **no seal, no coat of arms, no emblem**.

### 2.2 Forbidden — permanently, on every document in this suite

1. A **Ministry seal or stamp**, national coat of arms, or any State emblem on a school-issued document.
2. Signature blocks for the **Minister**, the **GCE Board Chairman**, or the **Director of the Office of the Baccalauréat**.
3. **Certifying award of a national credential the school did not award** — GCE O/A Level, Baccalauréat, BEPC, Probatoire, CAP, BAC Technique. The school may state that a candidate *was presented* for an examination, and may print its own internal results; it may never certify the national result.
4. National credential **serial numbers, centre numbers, index numbers**.
5. **Security-features legends** — "hologram", "UV feature", "microtext", "printed on special security paper".

Building any of these makes the product a credential-forgery tool and exposes the vendor and its customer schools to criminal liability. The .NET reference reached and documented the same conclusion.

### 2.3 Enforcement, not exhortation

- `SchoolProfile` has **no field** capable of holding a ministry seal image. The branding uploader accepts `crest`, `logo`, `principal_signature`, `registrar_signature`, `school_stamp` only, and rejects any other slot name.
- The certificate template registry declares an allow-list of signature roles: `principal | vice_principal | registrar | class_master | bursar | accountant | librarian | store_keeper | discipline_master | nurse | guardian | student | staff | security`. **Any other role fails template validation at save.** `minister`, `gce_board_chairman`, `directeur_bac` are explicitly denied-listed with a message quoting §13.2.
- A Pest test sweeps every rendered golden file for the forbidden strings (`GCE`, `Advanced Level`, `Baccalauréat` *as an awarded credential*, `hologram`, `centre number`, `index number`) outside the whitelisted "presented for examination" phrasing, and fails the build on a hit.
- Archived-as-unbuildable mockups (6) per `00-core.md` §13.3 are **not** in this catalogue and must not be reintroduced: `Transcript.png`, `08_12_56`, `08_14_05`, `08_14_00`, `08_14_21` (Minister-signed GCE diploma), `08_14_25` (ministry-sealed GCE transcript).

---

## 3. ⚠ MOCKUP DEVIATION CLAUSE — READ BEFORE IMPLEMENTING ANY CERTIFICATE

> **Three mockups for documents this product *does* deliver currently violate §2.2. An implementer who follows the mockup pixel-for-pixel will build what the integrity policy forbids.** The mockup is the UI source of truth for *layout*; it is **not** authoritative on *content* where content conflicts with §13. In every conflict, §13 wins and the mockup is wrong.

| # | Mockup | What it does wrong | Required change | Catalogue doc |
|---|---|---|---|---|
| D1 | `statement of results.png` | Reports **GCE A' Level** grades; carries a ministry examinations-office seal | Report the **school's own internal results** for the school's own assessment periods. **School stamp only.** Header reads "Statement of Internal Results / Relevé de notes internes". Any external-exam column is deleted, not relabelled | §6.4 `STMT-RES` |
| D2 | `certificate of completeion.png` | States "EXAMINATION TYPE: GCE Advanced Level" | Certify **completion of the school's programme of studies** — "has completed the *n*-year programme of study at *School* for the academic years X–Y". No examination type field exists on the entity | §6.6 `CERT-COMP` |
| D3 | `Student ID V1.png` / `student ID V2.png` | National coat of arms and ministry seal | **School crest and school letterhead only.** Remove both emblems from front and back | §12.1 `ID-STU` |

**Additional defect in D3 that must be resolved, not copied.** The V1 mockup prints the *same* admission number three ways — `HA/2021/00045`, `HA-2021-00045`, `HA202100045` — and prints the class as "Form 5 (Upper Sixth)", which conflates two different `ClassLevel`s.

Resolution, binding:
- **One canonical admission-number format**, `{school_code}/{admission_year}/{serial:5}` → `HA/2021/00045`, rendered identically in every human-readable position.
- The **barcode symbology payload** is the *unpunctuated* form `HA202100045` (Code 39 has no `/`); the **human-readable line printed beneath the barcode is the canonical punctuated form**. They are the same value under a documented, tested transform `AdmissionNumber::barcodePayload()` / `::fromBarcodePayload()`, round-trip property-tested.
- The class field renders exactly one `ClassGroup` — `ClassGroup.name` with `ClassLevel.name` — resolved from the current `Enrollment`. There is no free-text class field and no parenthetical alias.

---

## 4. The document platform

### 4.1 `DocumentTemplate` (registry)

Every document in §5–§16 is a registered template, not an ad-hoc Blade file.

```
DocumentTemplate
├─ code                    VARCHAR(32)  utf8mb4_0900_as_cs   e.g. 'CERT-COMP'
├─ name, name_fr
├─ module                  enum, = the owning module in 00-core §6.3
├─ paper_size              enum(A4|A5|A3|CR80|LETTER)
├─ orientation             enum(portrait|landscape)
├─ duplex                  enum(none|double_sided)
├─ series_code             VARCHAR(16) NULL   → DocumentSeries.code
├─ is_snapshot_backed      BOOLEAN
├─ snapshot_source         VARCHAR(64) NULL   e.g. 'ReportCardSnapshot'
├─ carries_qr              BOOLEAN
├─ carries_barcode         BOOLEAN
├─ state_header            enum(none|optional|default_on)
├─ signature_roles         JSON  (ordered; validated against the §2.3 allow-list)
├─ min_phase               enum(v1|later)
├─ blade_view, version, is_active
└─ UNIQUE(code)
```

`version` is an integer bumped on any layout change. **Snapshot-backed documents store the `document_template_id` *and* `version` used at issue**, so a reprint years later reproduces the issued artefact — layout, labels and branding included, not only the numbers.

### 4.2 Snapshot-backed vs live

| | **Snapshot-backed** | **Live** |
|---|---|---|
| Definition | The document asserts a fact **as at a moment**, and a later reprint must be byte-identical to the original issue | The document is a **working view of current state**; reprinting after data changes is expected and correct |
| Mechanism | Renders **only** from an immutable snapshot row (+ pinned config version). **It never re-queries live tables and never recomputes.** | Renders from live queries with the render timestamp printed |
| Test | Issue → mutate every underlying record → re-render → assert **byte-identical** PDF and matching `content_hash` | Render twice across a data change → assert the printed `Generated: <datetime>` differs and content reflects current state |
| Examples | Report Card, Statement of Results, Transcript, all Certificates, Receipt, Invoice, Credit Note, Payslip, Student ID | Class List, Class Register, Attendance Sheet, Seating Plan, Timetable prints, all blank forms, all logs |

Every **live** document prints `Généré le / Generated on: {datetime} par {user}` in the footer. Every **snapshot-backed** document prints the issue date, the series number, and the snapshot generation/version. A live document that is mistaken for evidence is a control failure; the footer is what prevents it.

**Blank-form documents** (Admission Form, Consent Form, Answer Sheet, Excursion Permission…) are a sub-class of live: they render school branding and static field labels with optionally pre-filled known data. They are never snapshot-backed and never carry a document series number *when blank*; they acquire one only when the corresponding record exists (e.g. a completed `LeaveApplication`).

### 4.3 `DocumentSeries` and numbering

Per `00-core.md` §12, all document series are **gaps-permitted** (atomicity only — unlike `JournalEntry.piece_no`, which is gapless). Allocation is from a row-locked `Sequence` row, **never `max()+1`**.

```
DocumentSeries
├─ code                VARCHAR(16)  e.g. 'COM', 'TRANS', 'RCPT'
├─ format              VARCHAR(64)  e.g. '{school}/{year}/{code}/{serial:6}'
├─ scope               enum(global|academic_year|fiscal_year|section)
├─ reset_policy        enum(never|per_academic_year|per_fiscal_year)
├─ next_value, padding, is_active
└─ UNIQUE(code)
```

**Uniqueness scope is stated explicitly per series** (the v1 defect). The rendered string is `UNIQUE` globally in `IssuedDocument.serial`; the *counter* resets per the scope below.

| Series | Format example | Counter scope | Applies to |
|---|---|---|---|
| `ADM` | `HA/2026/ADM/000123` | academic_year | Admission Form / Admission Register entry |
| `RCPT` | `HA/2026/RCPT/000123` | fiscal_year | Fee Receipt |
| `INV` | `HA/2026/INV/000123` | fiscal_year | Fee Invoice |
| `CN` | `HA/2026/CN/000123` | fiscal_year | Credit Note |
| `REF` | `HA/2026/REF/000123` | fiscal_year | Refund Receipt |
| `BUL` | `HA/2026/BUL/T2/000123` | academic_year | Report Card / Bulletin |
| `RES` | `HA/2026/RES/000123` | academic_year | Statement of Results |
| `TRANS` | `HA/2026/TRANS/000123` | global | Academic Transcript |
| `COM` | `HA/2026/COM/000123` | global | Certificate of Completion |
| `TC` | `HA/2026/TC/000123` | global | Transfer Certificate |
| `LC` | `HA/2026/LC/000123` | global | School Leaving Certificate |
| `CHAR` | `HA/2026/CHAR/000123` | global | Character / Conduct Certificate, Testimonial |
| `BON` | `HA/2026/BON/000123` | academic_year | Bonafide Student Certificate |
| `ACH` | `HA/2026/ACH/000123` | academic_year | Certificate of Achievement |
| `CARD` | `HA/2026/CARD/00123` | academic_year | Student ID card number |
| `LIBC` | `LIBC/00123` | global | Library card number |
| `VIS` | `VIS/2026-04-12/0042` | day | Visitor pass number |
| `GP` | `GP/2026/000123` | academic_year | Gate pass |
| `PAY` | `HA/2026-04/PAY/00123` | payroll_month | Payslip |
| `DISC` | `HA/2026/DISC/000123` | academic_year | Disciplinary Action Form |
| `MNT` | `MNT/2026/000123` | fiscal_year | Maintenance Request |

`{school}` is `SchoolProfile.short_code` (collation `utf8mb4_0900_as_cs`). `{year}` is the **academic** year start for academic scopes, the **fiscal** year for fiscal scopes; the format string declares which, and a template using `{year}` with `scope = global` fails validation.

### 4.4 `IssuedDocument` and `DocumentPrintLog`

```
IssuedDocument                     -- one row per document ISSUED (snapshot-backed only)
├─ document_template_id, template_version
├─ series_code, serial UNIQUE
├─ subject_type, subject_id        -- Student, Enrollment, Payment, PayrollItem…
├─ snapshot_type, snapshot_id      -- the immutable payload it renders from
├─ language                        enum(en|fr)
├─ content_hash                    CHAR(64)  SHA-256 of the rendered PDF bytes
├─ qr_token                        TEXT NULL (§17)
├─ issued_by (RESTRICT), issued_at, issued_by_name_at_time
├─ status                          enum(valid|revoked|superseded)
├─ revoked_by/at/reason, superseded_by_document_id
└─ UNIQUE(document_template_id, subject_type, subject_id, snapshot_id)
```

`DocumentPrintLog` (defined in `00-core.md` §14) records **every render**, issued or live:

```
DocumentPrintLog
├─ document_template_id, template_version
├─ issued_document_id NULL         -- null for live documents
├─ subject_type, subject_id, subject_label_at_time
├─ snapshot_version NULL
├─ is_duplicate BOOLEAN            -- true ⇒ DUPLICATA watermark
├─ copy_no, language, paper_size
├─ bulk_print_job_id NULL
├─ printed_by (RESTRICT), actor_name_at_time, printed_at, ip
└─ INDEX(subject_type, subject_id, printed_at), INDEX(bulk_print_job_id)
```

Never cascade-deleted (`00-core.md` §10.5). Retention follows the audit-log policy; for accounting-bearing documents (receipts, invoices, payslips) the **10-year AUDCIF retention** in `02-accounting.md` applies and hard delete is refused by the global observer.

### 4.5 Reprints and `DUPLICATA`

**The first successful render of a snapshot-backed document is the original. Every subsequent render is a duplicate.** Two identical originals for one payment is a control failure.

- `is_duplicate = (count of prior successful prints of this IssuedDocument > 0)`. It is derived from `DocumentPrintLog` inside the render transaction, not passed in by the caller.
- Duplicates render a diagonal `DUPLICATA` watermark (FR and EN forms both render `DUPLICATA`; the reference uses the same word) at ~12% opacity, plus a footer line `Duplicata n° {copy_no} — imprimé le {date} par {user}`.
- **A failed render consumes no series number and writes no print log row** — the series allocation, `IssuedDocument` insert and the print-log row are one transaction, committed only after the PDF bytes exist and hash successfully.
- Reprinting is permission-gated (`documents.reprint`) separately from `documents.print`. Reprinting a **receipt** additionally requires the bursar/accountant role, mirroring the payment-void segregation in `04-fees.md`.
- `content_hash` is recomputed on every reprint of a snapshot-backed document and **compared** to the stored hash. A mismatch is a hard failure (`DocumentReproducibilityViolation`), logged and surfaced, never silently printed — it means either the template version pin or the snapshot has been violated.

### 4.6 Bilingual rendering

**Every document renders in the school's — or the `SchoolSection`'s — document language, independent of the operator's UI language.** An Anglophone secondary section and a Francophone nursery in one school print in different languages from the same operator session.

Resolution order for the rendering language: explicit request parameter → `SchoolSection.document_language` → `SchoolProfile.default_document_language`. `SchoolProfile.bilingual_documents = true` renders both languages side-by-side where the layout permits (letterhead, certificate body, receipt "amount in words") and stacked otherwise.

- All strings live in per-document translation files; **no literal in a Blade template** (arch test).
- Amounts render per `00-core.md` §7.5: FR `1 234 500 FCFA`, EN `1,234,500 FCFA`. Dates FR `12/04/2026`, EN `12/04/2026` with the month name spelled in the target language on certificates.
- **Amount in words** is required on Receipt, Invoice, Credit Note, Refund and Payslip. Two independent implementations (`fr` with the Cameroonian *quatre-vingts/quatre-vingt-dix* forms, `en` short-scale), each with a golden table covering 0, 1, 21, 71, 80, 81, 100, 200, 1 000, 80 000, 1 000 000 and every value in the fee-fixture set.
- **Golden-file rendering tests per document per language** — 58 documents × 2 languages. The golden asset is the extracted text layer plus a structural digest (block order and positions), not the raw PDF bytes, so a font-hinting change does not break the suite while a moved signature block does.

### 4.7 Shared blocks

| Block | Contents | Notes |
|---|---|---|
| `state_header` | §2.1 bilingual state text | Text only. Toggleable per template and per section |
| `school_header` | Crest, school name (EN/FR), motto, address, phone, email, website, **NIU, RCCM, ministry accreditation number** | The fiscal identity fields are **mandatory** on Invoice, Receipt, Credit Note and Refund per `03-tax-procurement.md`; a school without a NIU cannot issue a legally sufficient receipt, and the render **blocks with a setup prompt** rather than printing a deficient document |
| `subject_identity` | Photo, name, matricule, class group, section, academic year | Student documents |
| `signature_block` | Role label (bilingual), name, ruled line, date, optional stamp image | Roles validated against the §2.3 allow-list |
| `document_footer` | Series number, issue date, page *n* of *m*, template code+version, `Generated on` (live only), QR (where declared) | Every page |
| `qr_block` | §17 signed token + "Scan to verify / Scanner pour vérifier" | Never encodes student data |
| `watermark` | `DUPLICATA`, or `SPÉCIMEN / SPECIMEN` for template previews and demo licences | Demo-licence renders are **always** watermarked |

### 4.8 Rendering pipeline

`RenderDocumentAction` (Reporting module) is the only path to a PDF. It: authorizes → resolves template + version → resolves language → loads snapshot **or** runs the live query → allocates the series number (snapshot-backed, inside the transaction) → renders → hashes → writes `IssuedDocument` (if applicable) and `DocumentPrintLog` → returns bytes. It accepts an `idempotency_key` per `00-core.md` §6.2.7, so a double-clicked *Print Receipt* issues one receipt.

Per `00-core.md` §6.2.5 it exposes a **batch signature** `forSubjects(array $ids)`; the bulk printer calls the batch form, never the single form in a loop. Storage path from `GeneratedDocumentPath` semantics: `storage/documents/{yyyy}/{module}/{template}/{serial|uuid}.pdf`, filename sanitised (student names contain `/` and accents).

PDF engine decision is a `00-core.md` §16 blocking gate (#11) owned by `08-operations.md`; this document specifies **what** is rendered, not with which library. Constraints it must satisfy: embedded fonts with full French diacritic coverage, Code 39 and QR as vector, CR80 exact physical sizing, and the §18 batch budget.

---

## 5. Catalogue — how to read §6–§16

Documents are organised by **owning module** (`00-core.md` §6.3). Every entry gives: code · name EN/FR · purpose · paper · series · signatures · snapshot/live · v1-or-later · source (M = mockup number, R = reference renderer, RM = roadmap) · data contract.

"Data contract" lists the entities and fields read. A snapshot-backed document's contract describes what is **captured into the snapshot at issue**, not what is queried at print.

---

## 6. Assessment module — academic results and certificates

### 6.1 `RPT-CARD` — Report Card / Bulletin de notes

| | |
|---|---|
| Purpose | The core deliverable. Per-period academic record for one enrollment |
| Paper | A4 portrait (A3 landscape variant for wide per-sequence francophone bulletins) |
| Series | `BUL`, scope academic_year |
| Signatures | `class_master`, `principal`; optional `vice_principal`, `guardian` acknowledgement line |
| Backing | **Snapshot** — `ReportCardSnapshot` + pinned `ReportCardConfig` **version** |
| Phase | **v1** |
| Source | M12, R `ReportCardDocument`, RM "Student Report Card (Premium)" |

**Data contract (captured into the snapshot at publication).** School identity and branding as at issue; `state_header` fields; student identity (name, matricule, photo, DOB, gender, repeat flag); `ClassGroup`, `ClassLevel`, `Stream`, `SchoolSection`, `AcademicYear`, `AssessmentPeriod`; per-subject rows — `SubjectAllocation` (subject name EN/FR, coefficient, `subject_allocation_id`), each configured `marks_columns` entry (including child-period columns `Séq 1 | Séq 2`), normalized subject average, `M×Coef`, subject rank, class min/max (`cote [min–max]`), appréciation, teacher name and visa; **totals row** (Σ Coef, Σ M×Coef); derived moyenne, `mention` (band label), `conseil_award` (stored, never derived); rank with printed denominator (`Rang : 5ᵉ / 62`) and `NC` where non-classé; class statistics with the stated stdev basis; `ConductAssessment` (conduite, travail, assiduité, discipline, tenue); absence hours **justifiées / non justifiées**, retards, consignes, exclusions; `ReportCardRemark` rows (subject / class_master / principal); competency blocks for MINEDUB frameworks (A / ECA / NA); fee-balance block where enabled; version and issue date.

**Invariants.** Renders **only** from the snapshot — never recomputes an average. A Σcoefficient = 0 student prints "non évalué / not assessed", not 0. An amendment produces a **new snapshot generation** (see `01-assessment.md` C10) and a new `IssuedDocument` with `superseded_by_document_id` set on the prior one; the reissued card prints its generation number and issue date so two cards in a parent's hands are distinguishable.

Nursery variant (`RPT-CARD-F`, MINEDUB maternelle Family F): five domains, competency scale, **no marks, no coefficients, no rank**; same code, different `ReportCardConfig` version.

### 6.2 `MARK-SHEET` — Mark Sheet / Feuille de notes

A4 portrait/landscape · no series · `teacher` signature + date · **live** · **v1** · M11, R via `TabularReportDocument`.
Per-student assessment breakdown for one `ClassGroup` × `Subject` × `AssessmentPeriod`: columns Assessment / Total Marks / Score / % per the mockup, plus mark state (`scored | absent_justified | absent_unjustified | exempt | pending`) and workflow state (`draft | submitted | validated`). Reads `Mark`, `SubjectAllocation`, `Enrollment`. **Prints the workflow state in the header** — an unvalidated mark sheet must not look like a validated one.

### 6.3 `BROADSHEET` — Class Broadsheet / Tableau récapitulatif

A3 landscape · no series · `class_master`, `principal` · **snapshot** (same `ReportCardSnapshot` set as §6.1 when the period is published; live before publication, watermarked `PROVISOIRE / PROVISIONAL`) · **v1** · RM, `09-ui.md` §8.
All students × all subjects for a period, with per-student Σcoef, moyenne, rank, and per-subject class statistics. The single highest-value sheet for a conseil de classe.

### 6.4 `STMT-RES` — Statement of Results / Relevé de notes internes

| | |
|---|---|
| Purpose | A formal, sealed statement of the **school's own internal results** for a student across one or more assessment periods, on the school's authority |
| Paper | A4 portrait |
| Series | `RES`, scope academic_year |
| Signatures | `registrar`, `principal` + **school stamp only** |
| Backing | **Snapshot** |
| Phase | **v1** |
| Source | RM "Statement of Results", R `StatementOfResultsDocument`, mockup `statement of results.png` — **⚠ deviation D1, §3** |

> **⚠ Deviation D1.** The mockup reports **GCE A' Level** grades under a ministry examinations-office seal. **Both are forbidden (§2.2).** This document reports the school's internal grades for the school's own `AssessmentPeriod`s, under the school's stamp. There is no "examination type" field, no centre number, no index number, and no ministry seal slot in the entity or the template.

**Data contract.** Student identity; `AcademicYear` and the `AssessmentPeriod`s covered; per-subject grade points where the framework defines a GPA model (`01-assessment.md`), otherwise per-subject average /20 with coefficient; overall average; `GradeBand.purpose = internal` band labels; subjects offered and result state; issue date, series number, QR. Explicit footer disclaimer, both languages: *"This statement reports results of internal assessments conducted by {School}. It is not a national examination result and does not certify any national credential."*

### 6.5 `TRANSCRIPT` — Academic Transcript / Relevé de scolarité

A4 portrait, multi-page · series `TRANS` (global) · `registrar`, `principal` + school stamp · **snapshot** · **v1** · R `TranscriptDocument`, RM "Academic Transcript". *(v1's delivered list omitted any transcript entirely; the reference implements one.)*

**School-issued, multi-year.** Data contract: student identity and full enrollment history (`Enrollment` + `EnrollmentSegment`, per `07-students.md` C2, so a mid-year transfer shows both class groups with dates); per academic year, per subject: coefficient, annual average, grade band label, credits/grade points if configured; annual average per the **same annual-average service** the report card and promotion engine use (`01-assessment.md` H); promotion decision per year; conduct summary; dates of entry and exit; the §6.4 disclaimer. Carries QR. **Transcript Envelope** (RM) is a companion A4 fold-over addressing sheet, live, no series — §16.3.

### 6.6 `CERT-COMP` — Certificate of Completion / Attestation de fin d'études

| | |
|---|---|
| Purpose | Certifies that the student **completed the school's programme of studies** |
| Paper | A4 landscape, certificate styling |
| Series | `COM` |
| Signatures | `principal` + school stamp; optional `registrar` |
| Backing | **Snapshot** |
| Phase | **v1** |
| Source | R `CompletionCertificateDocument`, RM, mockup `certificate of completeion.png` — **⚠ deviation D2, §3** |

> **⚠ Deviation D2.** The mockup states `EXAMINATION TYPE: GCE Advanced Level`. **Forbidden (§2.2.3.)** The certificate certifies completion of studies at the school. The entity has **no** `examination_type` field — it cannot be added.

Data contract: student identity; `SchoolSection`, final `ClassLevel`; programme name; academic years covered (from enrollment history); date of completion; series number; QR. Body text, both languages, fixed: *"…has completed the {programme} programme of studies at {School} for the academic years {from}–{to}."*

### 6.7 `CERT-ACH` — Certificate of Achievement / Certificat de mérite

A4 landscape, certificate styling · series `ACH` · `principal` · **snapshot** · **v1** · M26.
Awards recognition for a named achievement. Data contract: student identity, achievement title and description (free text, authored and approved), the `ConseilDecision` or award record it derives from where applicable, date, class group. **Must not** be auto-generated from a grade band — awards are conseil-voted (`01-assessment.md` C6).

### 6.8 `EXAM-PAPER` — Examination Paper / Épreuve

A4 portrait · no series · no signature block (an `exams_officer` approval line optional) · **live** · **v1** · M9, R (paper header).
Renders the exam header block (subject, class, time allowed, total marks, instructions, Section A/B structure) from `Exam` + `MarkScheme` (`01-assessment.md`). The product renders the **header and structure**; question content is authored text. Whether a question bank is in scope is decided in `01-assessment.md` — this document renders whatever that decides.

### 6.9 `CLASS-TEST` — Class Test / Devoir

A4 portrait · no series · none · **live** · **v1** · M8. Lighter-weight sibling of §6.8: subject, class, date, time allowed, instructions, ruled answer area.

### 6.10 `ANS-SHEET` — Answer Sheet / Feuille de réponses

A4 portrait · no series · none · **blank/live** · **v1** · M10. Pre-printed with student identity fields, subject, class, date and Section A/B numbered answer lines. Optionally pre-filled per student for an exam sitting, in which case it is generated from the seating plan roster.

### 6.11 `SEATING-PLAN` — Examination Seating Plan / Plan de salle

A3 landscape · no series · `exams_officer`, invigilator · **live** · **v1** · R `SeatingPlanDocument`, RM.
Data contract: `Exam`, `Room` (rows × columns from `Room.capacity` and layout), seat assignments per candidate, invigilator assignment. Prints both a grid view and an alphabetical candidate→seat list, because invigilators use the latter at the door.

---

## 7. Students module — admission, identity, records

### 7.1 `ADM-FORM` — Admission Form / Fiche d'admission
A4 portrait · series `ADM` (on submission; blank copies unnumbered) · `guardian`, `registrar` · **blank/live** · **v1** · M1, R `AdmissionFormDocument`.
Sections A Student Information (full name, DOB, gender, class applying for, previous school) and B Parent/Guardian Information (father, mother, occupation, phone, email) exactly per the mockup, extended with matricule (if allocated), nationality, place of birth, and the `Admissions` application reference. Renders blank for counter use, or pre-filled from an `AdmissionApplication`.

### 7.2 `STU-INFO` — Student Information Sheet / Fiche de renseignements
A4 portrait · no series · `guardian` verification signature · **live** · **v1** · M2, R `StudentInfoSheetDocument`, RM (a Bulk Prints type).
Personal information (name, DOB, place of birth, gender, nationality, religion, blood group, residential address, phone) + medical information (allergies, medical condition, emergency contact). **Encrypted fields** (genotype, blood group, religion, national ID) per `00-core.md` §9.5 are decrypted only inside the render and never written to a log or export; printing this document is itself an audited event with the field list recorded.

### 7.3 `CONSENT` — Parent/Guardian Consent Form / Formulaire de consentement
A4 portrait · no series · `guardian` · **blank** · **v1** · M3. Generic activity/rules consent. Where a signed copy is returned it is stored as a `StudentDocument` scan, not re-rendered.

### 7.4 `ENROL-AGR` — Enrollment Agreement / Contrat d'inscription
A4 portrait · no series (references the `Enrollment`) · `guardian`, `principal` · **snapshot** (terms text is versioned; the signed version must reproduce) · **v1** · M4.
Data contract: student, guardian, academic year, class group, the fee schedule applicable (`FeeStructure` summary), payment terms, and the versioned terms-and-conditions text. Because it states fee terms, it is snapshot-backed and pinned to the terms version.

### 7.5 `CODE-CONDUCT` — Student Code of Conduct / Règlement intérieur
A4 portrait · no series · `student`, `guardian` · **snapshot** (versioned rules text) · **v1** · M5. Numbered rules from a versioned settings-registry text block, bilingual.

### 7.6 `TRANSFER-CERT` — Transfer Certificate / Certificat de transfert
A4 portrait, certificate styling · series `TC` · `principal` + school stamp · **snapshot** · **v1** · M17, R `TransferCertificateDocument`.
Data contract: student identity, class group and level at departure, date of admission, date of departure, reason, conduct summary, **`financial_clearance` flag** from the `WithdrawalSettlement` Action in `04-fees.md` — **issue is blocked when clearance is false unless overridden by the principal with a recorded reason**, and the override is printed nowhere but logged everywhere.

### 7.7 `LEAVING-CERT` — School Leaving Certificate / Certificat de fin de scolarité
A4 portrait, certificate styling · series `LC` · `principal` + stamp · **snapshot** · **v1** · R `LeavingCertificateDocument`, RM.
Distinct from §7.6: transfer implies moving to a named school mid-programme; leaving records the end of the student's time at the school. Same clearance gate.

### 7.8 `CHAR-CERT` — Character Certificate / Certificat de bonne conduite
A4 portrait, certificate styling · series `CHAR` · `principal`, `discipline_master` + stamp · **snapshot** · **v1** · M18, R `ConductCertificateDocument`.
States the student is known to be of good character and conduct. **Blocked when open `DisciplineCase` sanctions above a configured severity exist for the student**, requiring an explicit principal override with reason. Certifying good conduct over an open exclusion is exactly the failure that destroys a school's credibility.

### 7.9 `TESTIMONIAL` — Testimonial / Attestation de scolarité et de conduite
A4 portrait · series `CHAR` · `principal` · **snapshot** · **v1** · R `TestimonialDocument`, RM.
Narrative reference combining attendance, conduct and academic standing. Authored text with structured facts appended.

### 7.10 `BONAFIDE` — Bonafide Student Certificate / Attestation d'inscription
A4 portrait · series `BON` · `registrar` + stamp · **snapshot** · **v1** · RM.
Short attestation that X is a registered student of class Y for academic year Z. The most frequently requested document in a Cameroonian school office (banks, embassies, transport discounts). Must be issuable in one click from the student profile.

### 7.11 `ATTEND-CERT` — Attestation of Attendance / Attestation de présence
A4 portrait · series `BON` · `registrar` · **snapshot** · **v1** · `00-core.md` §13.4.
Certifies attendance over a stated date range with the computed attendance rate. Requires the `AttendanceRegister` denominator from `07-students.md` C5 — **it refuses to render for a period with zero registers taken** rather than printing 100%.

### 7.12 `ADM-REGISTER` — Student Admission Register / Registre des inscriptions
A3 landscape, multi-page · no series (the register *is* the series record) · `registrar`, `principal` · **live**, but **archived as a signed PDF per academic year at rollover** · **later** · RM, `TabularReportDocument`.
Bound-register style listing every admission in the year: serial, admission number, matricule, name, DOB, guardian, date of admission, class admitted into, previous school, date and reason of leaving. The archived year-end copy is snapshot-backed and immutable, mirroring the `StatutoryBook` pattern in `02-accounting.md`.

### 7.13 `DISC-ACTION` — Disciplinary Action Form / Fiche de sanction disciplinaire
A4 portrait · series `DISC` · `discipline_master`, `principal`, `guardian` acknowledgement · **snapshot** · **v1** · M31.
Data contract: `DisciplineCase` keyed on **student and enrollment** (`07-students.md` C3) — student, class, date and time of incident, nature of offence, action taken, reported by, sanction ladder position from cross-year history.

### 7.14 `BEHAV-REPORT` — Behaviour Report / Rapport de comportement
A4 portrait · no series · `staff`, `guardian` · **live** · **v1** · M22, R `BehaviourReportDocument`.
Positive **or** negative (the mockup's checkbox); description, action taken, reported by, date, guardian signature line. Feeds and reads the same `DisciplineCase`/behaviour log; positive entries are first-class, not an afterthought.

---

## 8. Attendance module

### 8.1 `ATT-SHEET` — Attendance Sheet / Feuille de présence
A4 portrait · no series · `teacher` · **live/blank** · **v1** · M6, R via `TabularReportDocument`.
S/N · Student Name · Present · Absent · Remark, with class and date in the header, pre-filled with the class roster in enrollment order. Blank-column form for manual marking, or filled from a taken `AttendanceRegister`.

### 8.2 `CLASS-REGISTER` — Class Register / Registre d'appel
A3 landscape, multi-page · no series · `class_master`, `principal` · **live**, archived signed per term · **v1** · R `ClassRegisterDocument`, RM.
Students × school days matrix for a month or term, with per-student totals (present, absent justified, absent unjustified, late) and per-day totals. Reads `AttendanceRegister` headers + exception rows; **days with no register print a distinct "register not taken" glyph, never a blank that reads as present** — the C5 defect made visible on paper.

### 8.3 `ATT-MONTHLY` — Monthly Attendance Summary / Récapitulatif mensuel de présence
A4 landscape · no series · `class_master`, `principal` · **live** · **v1** · R `MonthlyAttendanceSummaryDocument`.
Per class group per month: registers taken, sessions expected, per-student attendance rate, hours absent justified/unjustified (for MINESEC per-lesson attendance), retards. Denominator = registers taken; the sheet prints both the register-taken count and the expected count from the school calendar so a gap is visible.

### 8.4 `STAFF-ATT-SHEET` — Staff Attendance Sheet / Feuille de présence du personnel
A4 portrait · no series · `prepared_by` (front desk / HR) · **live/blank** · **v1** · M36, R `StaffAttendanceSheetDocument`.
S/N · Staff Name · Sign In · Sign Out · Signature, dated. Feeds `days_worked` for payroll (`05-hr-payroll.md`), so the printed sheet and the captured record must reconcile; the document prints the source (`captured` vs `blank`).

---

## 9. Academics module — timetable and class documents

### 9.1 `CLASS-LIST` — Class List / Liste de classe
A4 portrait · no series · `class_master` · **live** · **v1** · R `ClassListDocument`, RM, and a Reports-catalogue entry.
Roster for a `ClassGroup`: S/N, matricule, name, gender, DOB, guardian and phone, plus optional columns (house, stream, boarder/day, transport route). Parameterised by academic year, term and class group; respects `EnrollmentSegment` dates so a mid-term list is correct as at a stated date (`as_of` parameter, printed).

### 9.2 `TT-CLASS` — Class Timetable / Emploi du temps (classe)
A4 landscape · no series · `principal` · **live** · **v1** · R `TimetablePrintDocument`, RM.
Weekly grid: days × named `TimetableSlot`s including Break and Lunch rows; per cell subject + teacher + room; effective-from/to dates printed; colour legend. Renders in monochrome-safe patterns as well as colour, because school printers are usually black-and-white.

### 9.3 `TT-TEACHER` — Teacher Timetable / Emploi du temps (enseignant)
A4 landscape · no series · `principal` · **live** · **v1** · RM. Same grid keyed on staff member; includes free periods and total weekly teaching hours (which feeds hourly payroll, `05-hr-payroll.md` C6).

### 9.4 `TT-STUDENT` — Student Timetable / Emploi du temps (élève)
A4 portrait · no series · none · **live** · **v1** · RM. Class timetable filtered to the student's elective basket.

### 9.5 `TT-EXAM` — Examination Timetable / Calendrier des examens
A4 landscape · no series · `exams_officer`, `principal` · **live** · **v1** · RM. Date × session × class group × subject × room × invigilator.

### 9.6 `LESSON-PLAN` — Daily Lesson Plan / Fiche de préparation
A4 portrait · no series · `teacher`, `hod` (as `vice_principal`) · **live/blank** · **v1** · M7, R `HomeworkLog` sibling.
Teacher, subject, date, learning objectives, materials, teaching activities, evaluation. Blank form plus a filled variant from a stored lesson-plan record.

### 9.7 `HW-LOG` — Homework Log Sheet / Cahier de textes
A4 portrait · no series · `guardian` · **live** · **v1** · M24, R `HomeworkLogDocument`.
Week of · Subject · Assignment · Date Given · Due Date · Completed checkbox, per student or per class.

### 9.8 `PROJ-COVER` — Project Cover Sheet / Page de garde de projet
A4 portrait · no series · none · **blank** · **v1** · M25. Project title, subject, student name, class, teacher, date submitted.

### 9.9 `PARENT-MEETING` — Parent Meeting Form / Fiche d'entretien avec les parents
A4 portrait · no series · `guardian`, `staff` · **live** · **v1** · M23, R `ParentMeetingFormDocument`.
Backs `GuardianMeeting` (`07-students.md` H): date, student, class, admission no., meeting with, agenda/discussion, decisions, follow-up, both signatures.

---

## 10. Fees module — money documents

All four money documents render the **fiscal identity block** (NIU, RCCM, régime, accreditation number) per `03-tax-procurement.md`, and **refuse to render** if `SchoolProfile` fiscal identity is incomplete.

### 10.1 `FEE-RECEIPT` — Fee Receipt / Reçu de paiement
| | |
|---|---|
| Paper | A5 portrait default; A4 and 80 mm thermal variants |
| Series | `RCPT`, scope fiscal_year, gaps permitted |
| Signatures | `bursar` ("Received by") + school stamp |
| Backing | **Snapshot** — pinned to the `Payment` and its allocations as at issue |
| Phase | **v1** |
| Source | M14, R `ReceiptDocument`, RM "Receipt Printing" |

Data contract: receipt no, date, received from (`Payment.payer_name`, which is frequently **not** the registered guardian), student name + matricule + class, the sum of (amount in figures **and words**), for (period/fee items), payment method and reference, allocation breakdown by invoice line, running balance after payment, received by, stamp.
**Invariants.** Rendered **only after the DB commit returns** (`00-core.md` §3) — never optimistically. A **voided** payment's receipt is marked `ANNULÉ / VOID` on every subsequent render and the `IssuedDocument.status` becomes `revoked`. Template options per the mockup: Official / **Duplicate with `DUPLICATA` watermark**, paper size, number of copies, show school stamp, preview.

### 10.2 `FEE-INVOICE` — Fee Invoice / Facture de frais
A4 portrait · series `INV`, fiscal_year · `bursar`/`accountant` · **snapshot** · **v1** · M15, R `FeeInvoiceDocument`.
Invoice no, date, to (guardian), student, class, term, line items (description, `service_period_start/end` for revenue cut-off per `04-fees.md` C4, tax code and amount where taxable per the 2022 Finance Law, amount), total due, due date, instalment plan schedule, amount in words, "Thank you for your prompt payment." Agent-collected items (APEE, exam registration — `04-fees.md` C5) are presented in a **separately subtotalled block** labelled *"Sommes encaissées pour le compte de tiers / Amounts collected on behalf of third parties"*, because the school is not the principal for them.

### 10.3 `FEE-STATEMENT` — Student Account Statement / Relevé de compte élève
A4 portrait, multi-page · no series (parameterised, reproducible via `as_of`) · `accountant` · **live with a printed `as_of` date** · **v1** · M16, R `FeeStatementDocument`, RM, Bulk Prints type.
Date · Description · Debit (XAF) · Credit (XAF) · Balance, with **balance brought forward**, per `04-fees.md`. Aging by **instalment due date** with the axis stated on the page. Voided payments and bounced cheques appear as reversing lines, never as deletions.

### 10.4 `CREDIT-NOTE` — Credit Note / Facture d'avoir
A4 portrait · series `CN` · `accountant`, `principal` (approval) · **snapshot** · **v1** · `04-fees.md` C7.
Mirrors the invoice, references the original invoice number and lines, states the reason type, and posts to **4198**.

### 10.5 `REFUND-RECEIPT` — Refund Receipt / Reçu de remboursement
A5/A4 portrait · series `REF` · `bursar`, `approved_by`, recipient signature · **snapshot** · **v1** · `04-fees.md` C6.
Refund reference, original payment/invoice link, reason, method, treasury account, amount in figures and words, recipient identity and ID number, approver.

### 10.6 `THIRD-PARTY-STMT` — Third-Party Funds Statement / État des fonds de tiers
A4 landscape · no series · `accountant`, `principal` · **live** · **later** · `04-fees.md` C5.
Per `ThirdPartyFund` (APEE, exam bodies): collected, remitted, held, with the class-47 liability balance. The document the school shows the APEE committee.

---

## 11. Payroll & HR module

### 11.1 `PAYSLIP` — Payslip / Bulletin de paie
| | |
|---|---|
| Paper | A4 portrait (two per sheet variant) |
| Series | `PAY`, scope payroll_month |
| Signatures | `payroll_officer` or `principal`; employee acknowledgement line |
| Backing | **Snapshot** — `PayrollItemSnapshot` is **authoritative**; the payslip never recomputes |
| Phase | **v1** |
| Source | M-adjacent, R `PayslipDocument`, RM |

Mandatory legal content, enforced by golden-file test per language (`05-hr-payroll.md`): employer name, address, **CNPS employer number, NIU**; employee name and **CNPS number**; job title, category and classification; period; **days or hours worked**; base rate; each gross element; **each deduction with its base and its rate**; employer contributions; net (figures and words); payment date and method; leave balance. Missing fields are an on-the-spot labour-inspection finding.

### 11.2 `PAY-ADVICE` — Payment Advice / Avis de paiement
A4 landscape · no series · `payroll_officer`, `accountant` · **snapshot** (from the approved run) · **v1** · `05-hr-payroll.md` screens.
Per-run disbursement listing: staff, bank/mobile-money destination, net amount, total. Companion to the machine-readable disbursement file.

### 11.3 `LEAVE-APP` — Leave Application / Demande de congé
A4 portrait · no series (references `StaffLeave`/`StudentLeave`) · applicant, `hr_officer`, `principal` · **live** · **v1** · M13, R `LeaveApplicationDocument`.
The mockup is the **student** form (Student Name, Class, Reason for Leave, From/To, Parent/Guardian Signature). The reference renderer serves **staff** leave. Both variants ship under one template code with a `subject_type` discriminator, because the fields differ (staff adds leave type, balance before/after, cover arrangements).

### 11.4 `CERT-TRAVAIL` — Certificat de travail / Certificate of Employment
A4 portrait · series `CHAR` (staff sub-scope) · `principal`, `hr_officer` + stamp · **snapshot** · **later** · `05-hr-payroll.md`.
**Mandatory on departure under Cameroonian labour law.** Contents: employee identity, position(s) held, dates of service, nature of the contract. Deliberately contains no appraisal language.

### 11.5 `SOLDE-COMPTE` — Reçu pour solde de tout compte / Final Settlement Receipt
A4 portrait · no series · employee, `hr_officer`, `principal` · **snapshot** (from `TerminationSettlement`) · **later** · `05-hr-payroll.md`.
Itemises indemnité de licenciement, indemnité compensatrice de préavis, leave compensation, deductions and net, with the statutory acknowledgement wording.

### 11.6 `EMP-REGISTER` — Registre d'employeur / Employer Register
A3 landscape, multi-page · no series · `principal` · **snapshot as at a stated date** · **later** · `05-hr-payroll.md`.
Must be produced on demand to a labour inspector **"as at" a date**, which is why the payroll snapshot in `05-hr-payroll.md` C7 is a precondition for this document existing at all.

---

## 12. Identity documents and passes

### 12.1 `ID-STU` — Student ID Card / Carte d'élève

| | |
|---|---|
| Paper | **CR80** (85.60 × 53.98 mm), landscape, **double-sided**, 3 mm bleed, 300 dpi minimum |
| Series | `CARD` (card number), scope academic_year |
| Signatures | `registrar` ("Academic Registrar") — printed signature image |
| Backing | **Snapshot** |
| Phase | **v1** |
| Source | M27, R `StudentIdCardDocument`, RM (portrait and landscape variants) — **⚠ deviation D3, §3** |

> **⚠ Deviation D3.** `Student ID V1.png` / `student ID V2.png` carry the **national coat of arms and a ministry seal**. Both are forbidden (§2.2.1). The card carries the **school crest and school letterhead only**. The branding uploader has no slot capable of holding a State emblem.

**Front:** school crest + school name band · student photo (portrait, 3:4, minimum 300×400 px, validated at upload) · **student name** · card number · class group · **admission number in the canonical format `HA/2021/00045`** · date of birth · blood group *(opt-in per `SchoolProfile.id_card_show_blood_group`; it is encrypted health data under `00-core.md` §9.5 and printing it is a deliberate, audited choice)* · QR (§17).

**Back:** student ID/matricule · class group · academic session · curriculum/section · date issued · **Valid Until** (default `AcademicYear.ends_on`, overridable) · **Terms & Conditions 1–5** (versioned text from the settings registry, bilingual) · **Code 39 barcode with the human-readable number beneath** · Academic Registrar signature.

**Payloads.**
- **Barcode (Code 39):** the unpunctuated admission number `HA202100045`, uppercase alphanumeric only, with checksum, wide-to-narrow ratio 3:1, minimum module width validated against the card width so it is scannable at CR80 scale. Human-readable line beneath prints the canonical `HA/2021/00045` (§3, D3 resolution).
- **QR:** the §17 signed verification token. **Never the student's name, DOB, guardian phone, or any personal datum** — a dropped ID card must not leak a child's data.

`ID-STAFF` — Staff ID Card is the same template family keyed on `StaffMember`, series `CARD`, **v1**.

### 12.2 `LIB-CARD` — Library Card / Carte de bibliothèque
CR80 landscape, single or double sided · series `LIBC` · `librarian` · **snapshot** · **v1** · M28, R `LibraryCardDocument`.
Name, class, card number, barcode (member number), borrowing limit and loan period, library rules summary on the reverse. Member covers students **and** staff (`06-assets-stores.md`).

### 12.3 `VISITOR-PASS` — Visitor Pass / Badge visiteur
A6 portrait or CR80 · series `VIS`, scope **day** · `authorized_by` (front desk) · **snapshot** · **v1** · M29, R `VisitorPassDocument`.
Visitor name, purpose, date, time in, time out, person visited, authorized by, **PASS NO.** in a prominent band per the mockup. Reads the Welfare module's visitor log.

### 12.4 `GATE-PASS` — Gate Pass / Autorisation de sortie
A5 portrait · series `GP` · `authorized_by`, `gate_security` · **snapshot** · **v1** · M30, R `GatePassDocument`.
Student name, class, reason, date, time out, authorized by, gate security signature. Safeguarding-critical: issuing requires a permission and the guardian-notification setting determines whether a message is queued to the guardian (`Communication` module; queued to the outbox on LAN, never a blocking error).

---

## 13. Welfare module — transport, hostel, medical, activities

### 13.1 `MED-FORM` — Medical Form / Fiche médicale
A4 portrait · no series · `guardian`, `nurse` · **live** · **v1** · M19, R `MedicalIntakeFormDocument`.
Section A student information; Section B medical (**blood group, genotype**, allergies, chronic illness, medication, doctor's name and phone). All of Section B is encrypted at rest (`00-core.md` §9.5 — genotype is sickle-cell status, health data about a child). Printing is audited with the decrypted field list recorded; the document is excluded from bulk printing and from any export.

### 13.2 `TRANSPORT-REQ` — Transport Request Form / Demande de transport scolaire
A4 portrait · no series · `guardian`, `transport_officer` · **live** · **v1** · M20, R `TransportRequestDocument`.
Student, class, admission no., route/stop requested, pick-up address, guardian name and phone, signature. On approval creates a `TransportAllocation` keyed on **enrollment** (`07-students.md` C3) under the both-directions capacity constraint of `00-core.md` §10.2.

### 13.3 `HOSTEL-OCC` — Hostel Occupancy Report / État d'occupation de l'internat
A4 landscape · no series · `hostel_warden`, `principal` · **live** · **v1** · R `HostelOccupancyReportDocument`.
Block → room → bed → occupant with vacancy counts and occupancy rate; reads the one-active-per-bed constraint so a double-booked bed is impossible to print.

### 13.4 `EXCURSION` — Excursion Permission Form / Autorisation de sortie pédagogique
A4 portrait · no series · `guardian` · **live** · **v1** · M32, R `ExcursionPermissionDocument`.
Excursion to, date, student, class, permission wording, guardian name, phone, signature, date. Emergency contact block.

### 13.5 `SPORTS-PART` — Sports Participation Form / Fiche de participation sportive
A4 portrait · no series · `guardian` · **live** · **v1** · M33, R `SportsParticipationDocument`.
Student, class, age, sport(s), emergency contact (name, phone), guardian permission and signature. Medical-clearance flag sourced from the medical record (boolean only — never the underlying condition).

---

## 14. Library, inventory and facilities

### 14.1 `BOOK-REQ` — Book Request Form / Demande d'ouvrage
A4 portrait · no series · requester, `librarian` · **live** · **v1** · M21, R `BookRequestDocument`.
Student/staff, class, admission no., title, author, reason for request, requested by, signature. Feeds library acquisitions.

### 14.2 `LIB-ISSUE-SLIP` — Loan / Return Slip · A6 · no series · `librarian` · **live** · **later** · `06-assets-stores.md`. Copy barcode, member, issue date, due date, fine accrued on return.

### 14.3 `LIB-OVERDUE` — Overdue Books Report · A4 landscape · no series · `librarian` · **live** · **v1** · `06-assets-stores.md` tabs. Member, copy, due date, days overdue, fine accrued, and the debt stream it belongs to (student receivable on 4111 vs staff payroll deduction).

### 14.4 `LOST-FOUND` — Lost and Found Log / Registre des objets trouvés
A4 portrait · no series · `store_keeper` · **live** · **v1** · M34, R `LostFoundLogDocument`.
Date Found · Item Description · Found By · Claimed (Y/N) · Claimed By.

### 14.5 `MAINT-REQ` — Maintenance Request Form / Demande de maintenance
A4 portrait · series `MNT` · `requested_by`, `authorized_by` · **live** · **v1** · M35, R `MaintenanceRequestDocument`.
Requested by, date, location, issue description, **priority** (Low/Medium/High), **status** (Pending/In Progress/Completed), authorized signature, and the linked `Asset` where the request concerns a registered asset (`06-assets-stores.md`).

### 14.6 `STOCK-REPORT` — Inventory Stock Report · A4 landscape · no series · `store_keeper`, `accountant` · **live** · **v1** · Reports catalogue. Item, location, on-hand, reserved, reorder level, weighted average unit cost, total value — tying to the class-3 stock accounts.

### 14.7 `STOCK-ISSUE-NOTE` — Stock Issue / Bon de sortie · A5 · no series · `store_keeper`, recipient · **snapshot** · **later**. The paper trail behind the `stock.issued` posting.

---

## 15. Accounting, tax and procurement documents

These are **owned by** `02-accounting.md` and `03-tax-procurement.md`; this section registers them as templates so they share the platform (series, print log, bilingual rendering, duplicate watermarking) and are counted in the suite.

| Code | Document | Paper | Series | Backing | Phase | Owner doc |
|---|---|---|---|---|---|---|
| `JRNL-BOOK` | Livre-journal | A4 landscape | — | **snapshot**, signed paginated immutable PDF | v1 | 02 §C5 |
| `GL-BOOK` | Grand livre | A4 landscape | — | snapshot | v1 | 02 |
| `TB-BOOK` | Balance générale | A4 landscape | — | snapshot | v1 | 02 |
| `INV-BOOK` | Livre d'inventaire (Bilan, Compte de résultat, Tableau des flux, inventaire) | A4 | — | snapshot | v1 | 02 §C5 |
| `BANK-RECON` | État de rapprochement bancaire | A4 | — | snapshot per period | v1 | 02 |
| `PO` | Purchase Order / Bon de commande | A4 | `PO` | snapshot | v1 | 03 |
| `GRN` | Goods Received Note / Bon de réception | A4 | `GRN` | snapshot | v1 | 03 |
| `WHT-CERT` | Attestation de retenue à la source | A4 | `WHT` | snapshot | v1 | 03 |
| `TAX-DECL` | Déclaration fiscale / DSF cover | A4 | — | snapshot | v1 | 03 |
| `CNPS-DIPE` | DIPE C04 printable | A4 | — | snapshot | v1 | 05 |

**`JRNL-BOOK`, `GL-BOOK`, `TB-BOOK`, `INV-BOOK`** carry the `books_cote_paraphe_reference`, `paraphe_authority` and `paraphe_date` from `SchoolProfile` in their footer, are paginated continuously, and are **never** re-renderable with different content — that is the whole point of a statutory book.

---

## 16. Institutional and system documents

### 16.1 `CERT-VERIFY` — Certificate Verification Page / Page de vérification
Screen + printable A4 · no series · none · **live** · **later** · RM. §17.

### 16.2 `GRAD-PROG` — Graduation Programme / Programme de la cérémonie de fin d'année
A5 booklet (A4 folded) · no series · none · **live** · **later** · RM.
Order of ceremony, class lists of graduands, award recipients (from `ConseilDecision` awards — never derived from grade bands), staff list. Purely celebratory; carries no certification language.

### 16.3 `TRANS-ENVELOPE` — Transcript Envelope / Enveloppe de relevé
A4 fold or C4 label · no series · `registrar` sealing signature · **live** · **later** · RM.
Addressee, student, transcript serial number, sealed-envelope wording. It references the `TRANSCRIPT` serial so an envelope and its contents are traceable to each other.

### 16.4 `TABULAR` — Generic Tabular Report / État tabulaire
A4/A3, portrait or landscape · no series · configurable · **live** · **v1** · R `TabularReportDocument`.
**Not a document, a renderer.** The engine behind the Reports & Analytics catalogue (`09-ui.md` §8): title, parameters echoed in the header, columns, grouping, subtotals, totals, page *n* of *m*, `Generated on … by …`. Every report in that catalogue that is not individually listed above renders through `TABULAR`, which is why the catalogue can grow without new templates.

---

## 17. QR verification

Every certificate, transcript and ID in the mockups says "Scan to verify", and the roadmap lists a **Certificate Verification Page**. v1 had a report-card QR *block* with no endpoint, no token model and no public page — and **a LAN deployment has no public endpoint at all**.

Per `00-core.md` §13.5 the payload is a **self-contained signed token, verifiable offline**.

### 17.1 Token format

```
OPES1.<base64url(payload)>.<base64url(signature)>
```

`payload` is a compact CBOR/JSON map:

| Field | Meaning |
|---|---|
| `i` | instance UUID (which school issued it) |
| `t` | document template code (`CERT-COMP`) |
| `s` | document serial (`HA/2026/COM/000123`) |
| `h` | first 16 bytes of `IssuedDocument.content_hash` |
| `d` | issue date |
| `k` | signing key id |

`signature` is **ECDSA P-256 / SHA-256** over the payload — the same primitive family the reference already uses for licensing (`00-core.md` §17.1), so there is one crypto stack, not two. The key pair is generated per instance at setup; the **public key is printed on the recovery sheet** and published in the About window, so a verifier can validate offline with nothing but the school's public key.

**What the token contains: no student name, no matricule, no marks, no dates of birth.** A holder of the QR learns only that a document with a given serial and content hash was issued by a given instance on a given date.

### 17.2 Verification surfaces

| Deployment | Surface |
|---|---|
| **LAN** | An **in-app verification screen**: paste or scan the token, the app validates the signature against its own public key and looks the serial up locally, showing `VALID / REVOKED / SUPERSEDED / NOT FOUND` plus template, issue date and issuer. No internet involved |
| **VPS (optional)** | A public, unauthenticated `GET /verify/{token}` page rendering the **same four-state result and nothing else** |

**Rate limiting** on the public page: 10 requests/minute/IP and 100/hour/IP, plus a global cap; failures return the same generic response as a not-found so the endpoint cannot be used to enumerate serials. The page is `noindex`, has no student data, no search, and no listing.

**Revocation.** Setting `IssuedDocument.status = revoked` (with reason and actor) makes the token verify as `REVOKED` immediately on LAN and on the next VPS sync. A superseded report card (amendment) verifies as `SUPERSEDED` and names the superseding serial — which is precisely how a parent holding an old card learns it was reissued.

---

## 18. Bulk Prints

The reference ships `BulkPrintService`; the mockups and roadmap imply the screen; **v1 had zero mentions of bulk anything.**

### 18.1 Scope and screen

Selection: **document type** (`ID Cards | Report Cards | Student Info Sheets | Fee Statements`, extensible via `DocumentTemplate.bulk_printable`) × **class group** × **academic year** × **term / assessment period**.

Actions: **Print All · Print Unprinted · Print Selected**, with **Select All / Select None**. The roster grid shows per student: name, matricule, eligibility (e.g. "no published snapshot"), **Last Printed**, **Printed By**, and copy count — all read from `DocumentPrintLog`.

"Print Unprinted" is defined precisely: students in the selection **with no successful `DocumentPrintLog` row for this template, this subject and this snapshot version**. A reissued (superseded) report card therefore counts as unprinted again, which is the behaviour the office expects.

### 18.2 `BulkPrintJob`

```
BulkPrintJob
├─ document_template_id, template_version
├─ academic_year_id, class_group_id NULL, assessment_period_id NULL
├─ mode                 enum(all|unprinted|selected)
├─ subject_ids          JSON (mode=selected)
├─ language, paper_size, copies, collate, duplex
├─ status               enum(queued|running|completed|failed|partial)
├─ total, succeeded, failed
├─ output_path          -- one merged PDF, plus per-subject files where requested
├─ requested_by (RESTRICT), requested_at, started_at, finished_at
└─ INDEX(status, requested_at)
```

**Semantics.**
- Runs as a queued job (database driver by default, `00-core.md` §4) with progress reported to the screen; a school printing 1 200 report cards must not hold an HTTP request open.
- **Per-subject transactional**: each document is its own transaction — series allocation, `IssuedDocument`, `DocumentPrintLog`. A single failure marks that subject failed and the job `partial`; it never rolls back the successful ones and never burns the whole batch.
- **Resumable**: re-running a `partial` job in `unprinted` mode picks up exactly the failures.
- Every produced document is logged with `bulk_print_job_id`, so "who printed the whole class's cards on 12 April" is one query.
- Duplicate rules (§4.5) apply per subject: a bulk reprint watermarks `DUPLICATA` for students already printed, in the same batch as clean originals for those not.

### 18.3 Performance budget

Respect the batch budget in `08-operations.md`. The working target for the reference hardware (4-core, 8 GB, SSD): **a 62-student class of report cards in ≤ 60 s, and a 1 200-student full-school run in ≤ 20 min**, memory-flat (documents are streamed and written per subject, never accumulated in memory). The PDF-engine decision (`00-core.md` §16 gate 11) is benchmarked against exactly this, using the 1 200-student reference fixture. `Model::preventLazyLoading()` and the batch Action signature make an N+1 in the bulk printer a test failure rather than a support ticket.

---

## 19. Permissions

| Permission | Grants |
|---|---|
| `documents.print` | Render a document the actor may already view |
| `documents.reprint` | Render a duplicate of an already-issued document |
| `documents.reprint.financial` | Duplicate of a receipt / invoice / credit note / refund / payslip (bursar, accountant, payroll officer only) |
| `documents.bulk_print` | Queue a `BulkPrintJob` |
| `documents.revoke` | Set `IssuedDocument.status = revoked` |
| `documents.template.manage` | Edit templates, versions, series formats, branding |
| `documents.override_gate` | Issue a Transfer/Leaving/Character certificate despite a clearance or discipline block (principal), always with a recorded reason |

The **deny-by-default route enumeration suite** (`00-core.md` §9.2) covers every document route: a guardian may render only documents for their own children, only within their `valid_from`/`valid_to` window, and only in the scopes their flags allow (`07-students.md`). A guardian can never render a Medical Form, a Payslip, a statutory book, or another family's anything.

---

## 20. Summary

### 20.1 Totals

| | Count |
|---|---|
| Documents catalogued | **58** |
| **v1** | **41** |
| **Later** | **17** |
| Snapshot-backed | 30 |
| Live / blank-form | 28 |
| Carrying a document series | 27 |
| Carrying QR verification | 11 |
| Carrying a barcode | 3 (`ID-STU`, `ID-STAFF`, `LIB-CARD`) |
| v1 (2026-08-07 design) coverage | 8 of 58 — **14%** |

### 20.2 Source provenance

| Provenance | Documents |
|---|---|
| **Mockup + reference renderer** (strongest) | Admission Form · Student Info Sheet · Attendance Sheet · Report Card · Mark Sheet · Leave Application · Fee Receipt · Fee Invoice · Fee Statement · Transfer Certificate · Character Certificate · Medical Form · Transport Request · Book Request · Behaviour Report · Parent Meeting Form · Homework Log · Student ID · Library Card · Visitor Pass · Gate Pass · Excursion Permission · Sports Participation · Lost & Found Log · Maintenance Request · Staff Attendance Sheet · Certificate of Completion · Statement of Results (24 + 3 with deviations) |
| **Mockup only** | Consent Form · Enrollment Agreement · Code of Conduct · Daily Lesson Plan · Class Test · Examination Paper · Answer Sheet · Project Cover Sheet · Certificate of Achievement · Disciplinary Action Form |
| **Reference renderer only** | Class List · Class Register · Seating Plan · Timetable prints · Payslip · Hostel Occupancy Report · Leaving Certificate · Monthly Attendance Summary · Testimonial · Academic Transcript · Tabular Report engine |
| **Roadmap only** | Graduation Programme · Bonafide Student Certificate · Certificate Verification Page · Student Admission Register · Transcript Envelope · Teacher/Student/Exam Timetables · Broadsheet |
| **Regulatory requirement, no mockup or renderer** | Credit Note · Refund Receipt · Third-Party Funds Statement · Attestation of Attendance · Certificat de travail · Solde de tout compte · Registre d'employeur · the four statutory books · Attestation de retenue à la source · Purchase Order · GRN |

### 20.3 The three violations, once more

`statement of results.png`, `certificate of completeion.png`, and `Student ID V1/V2.png` **must not be implemented as drawn**. §3 states the required change for each. An implementation that ships any of the three as drawn is a release blocker, not a cosmetic defect.

### 20.4 Open items

- **NEEDS VERIFICATION** — whether Cameroonian practice expects a specific statutory wording on the *certificat de travail* and the *reçu pour solde de tout compte*; the model texts are not seeded.
- **NEEDS VERIFICATION** — whether the customer school's transfer certificate must be countersigned by the divisional delegation (some schools' practice); the template supports an additional signature block but no such block is enabled by default.
- **Blocked on `00-core.md` §16 gate 11** — the PDF engine, which determines CR80 physical-sizing fidelity and the §18.3 budget.
- **Blocked on gates 1–3** — real MINESEC/MINEDUB report-card specimens, before `RPT-CARD` layouts are frozen.
