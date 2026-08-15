# Broken flows and runtime bugs — audit, 2026-08-13

Scope: OPES School platform (Laravel 13 / Livewire 4), branch `main` at `c1ccba2`, audited
against the **dev database `opeschool`** (production-shaped data), not fixtures.

Audit only — no application code was changed.

## Summary

**Seven confirmed crashes**, all invisible to the existing 279-file test suite, and all of the
shape the brief names — reachable only with production-shaped data or on a click path the
tests never take:

- **Three procurement flows 500 on the first click** (Amend a purchase order; pick a PO on a
  goods receipt; pick an invoice for a credit note). One root cause: `whereKey()` called on a
  `DB::table` query builder, where Laravel's dynamic-`where` magic turns it into
  `where 'key' = ?`. No test references any of the three methods.
- **Three "Export PDF" buttons 500 for every record** (asset card, library card, purchase
  order) because the download filename interpolates identifiers that contain `/` by house
  convention — `AST/000001`, `LM/2026/00001`, `BC/2026/000001`. The codebase already owns a
  `DocumentFileName::sanitize()` for exactly this and `PdfExport` does not call it.
- **Report cards already issued become permanently unprintable** once the assessment period
  (or student) is renamed: the subject label is re-derived live into the hashed bytes, so the
  reprint's integrity check refuses forever. Reproduced on two real documents.

Plus one client-reachable 500 in the journal-entry form, and a latent second vector on the
report-card reprint that fires the first time anyone edits the school profile.

The enum-in-string sweep (task 1) found **no further instances** — see section B for why, and
for the list of places that would become instances after a routine refactor.

The test suite is **failing on `main`**: 2 architecture failures (module-boundary violations,
one introduced by a recent commit). The Feature suite could not be made to finish. See
section E.

## Method

Six independent sweeps, so a finding is only listed as "confirmed" if it was reproduced:

1. **Static enum-in-string hunt.** A scanner built the map of every enum-cast property
   (223 properties across 133 of the 272 model files, backed by 240 string/int enums) and cross-referenced
   every `.blade.php` and every file under `app/` for five hazardous contexts: string
   concatenation, array-offset use, `===`/`!==` against a string literal, string functions
   (`sprintf`/`str_replace`/`ucfirst`/…), and `in_array`. Every hit was then traced back to
   whether its variable is an Eloquent model or a `DB::table` row.
2. **Route sweep.** Booted the real app, authenticated as the demo administrator, and issued
   every GET route — first once each (123 routes), then **every detail route for every record**
   (386 requests: all 18 assets, 40 invoices, 40 receipts, 40 statements, 40 students,
   24 books, 20 library members, 37 users, all 4 discipline cases, all report cards, …).
3. **Guardian-portal sweep.** Same, authenticated as a real guardian principal with two
   children — 59 portal paths including invoices, receipts, threads, health and ID cards.
4. **Livewire driver.** Mounted all 150 components (145 mount; 5 need a route-model binding
   the harness can't build) and invoked **503 action calls** plus **340 `updated*` reactive
   handlers**, each inside a transaction that was rolled back. Destructive verbs
   (delete/void/approve/post/…) were excluded by name.
5. **Export/print sweep.** A separate pass over the 19 `export*` / `download*` / `*Pdf`
   actions that sweep 4 excluded for speed. Note: in this harness a *successful* PDF return
   surfaces as `InvalidArgumentException: Malformed UTF-8 characters` (the harness JSON-encodes
   the binary response body; real HTTP does not) — those were discarded as harness noise. The
   `HeaderUtils` failures in section A.1 are thrown *before* that, while building the response
   header, and are real.
6. **Delete/state sweep.** 147 `delete*` / `void*` / `archive*` / `cancel*` / `toggle*`
   invocations, transaction-rolled-back, with a post-run check that all watched table counts
   were unchanged.

Live browser confirmation on `http://localhost:8931` for the headline crash and for
empty-form validation.

---

## A. Confirmed by reproducing

| Flow / Area | Bug | Evidence | Severity | Suggested fix |
|---|---|---|---|---|
| **Procurement → Purchase Orders → Amend** | Clicking **Amend** on any purchase order 500s. `DB::table()` returns a *Query* Builder, which has no `whereKey()`; Laravel's dynamic `where{Column}` magic silently turns the call into `where 'key' = ?`. | `app/Modules/Procurement/Livewire/PurchaseOrders/Index.php:91` — `DB::table('purchase_orders')->whereKey($purchaseOrderId)`. Live: `/procurement/orders`, click Amend → `POST /livewire-409dbc42/update` → **500**. Error: `SQLSTATE[42S22]: Column not found: 1054 Unknown column 'key' in 'where clause' (SQL: select 'version' from 'purchase_orders' where 'key' = 1)`. Wired at `resources/views/livewire/procurement/purchase-orders/index.blade.php:108`. | **crash / blocker** | `->where('id', $purchaseOrderId)`. |
| **Procurement → Goods Receipts → select a purchase order** | Choosing a PO in the goods-receipt form 500s before the form can be filled — the receiving flow is unusable. Same `whereKey` cause. | `app/Modules/Procurement/Livewire/GoodsReceipts/Index.php:91` in `updatedFormPurchaseOrderId()`. Reproduced: `Livewire::test(GoodsReceipts\Index::class)->set('formPurchaseOrderId', 1)` → `Unknown column 'key' … (SQL: select 'supplier_id' from 'purchase_orders' where 'key' = 1)`. | **crash / blocker** | `->where('id', …)`. |
| **Procurement → Supplier Invoices → Credit Note → select invoice** | Choosing the invoice a credit note is raised against 500s. Same `whereKey` cause. | `app/Modules/Procurement/Livewire/SupplierInvoices/Index.php:148` in `updatedCreditNoteInvoiceId()`. Reproduced: `->set('creditNoteInvoiceId', 1)` → `Unknown column 'key' … (SQL: select 'supplier_id' from 'supplier_invoices' where 'key' = 1)`. | **crash / blocker** | `->where('id', …)`. |
| **Documents → report-card reprint** | A report card already issued becomes **permanently unprintable** as soon as the assessment period (or the student) is renamed. The reprint's byte-for-byte re-render no longer matches the hash recorded at issue, so `DocumentReproducibilityViolation` refuses it forever. | `GET /assessment/report-cards/1/2/print` and `/2/2/print` → **422**: *"Reprint of issued document SCH/2026/RPT/000001 produced content hash dc3c07179e42… where e3bd2c3a98e5… was recorded at issue."* `assessment_periods.id=2` was renamed at `2026-08-13 08:23:57`; documents 8 and 9 were issued `01:18`, documents 14 and 88 (period 1, never renamed) still reprint fine. Chain: `app/Modules/Assessment/Actions/PrintReportCard.php:80-95` derives `label()` live from `students` + `assessment_periods` → `app/Modules/Reporting/Actions/RenderDocument.php:817` renders it as `subject.label` → `resources/views/documents/assessment/report-card.blade.php:60` prints it (the snapshot payload has no `student` key, so the `?:` fallback always fires). | **crash / blocker** (permanent loss of a statutory reprint) | Freeze `subject_label` at issue alongside the payload and read it back on reprint, exactly as `payload_snapshot` already does — or drop `$subject['label']` from hashed output for snapshot-mapped templates. |
| **Documents → report-card reprint (second vector)** | Same permanent-refusal outcome triggered by editing the **school profile, branding or fiscal identity**: for a template registered in `SnapshotSourceMap`, `payload_snapshot` is deliberately `NULL`, and the report-card snapshot payload carries no `school` key, so the letterhead is re-derived **live** on every reprint. | `app/Modules/Reporting/Actions/RenderDocument.php:425` (`payload_snapshot => … ? null : …`) and `:574` `schoolChrome()` falling through to `captureSchoolChrome()`. Verified: `report_card_snapshots.payload` top-level keys are `rank, totals, mention, subjects, general_average` — no `school`. By contrast the invoice/receipt payloads *do* carry `school` (the receipt-pattern fix, `6f02f00`). | **blocker** (latent; fires on the first settings edit) | Capture the `school` chrome into the report-card snapshot at publication, or freeze the resolved chrome onto `issued_documents` at issue for mapped templates too. |
| **Assets → asset → Export Asset Card** | The download filename interpolates the asset tag raw. Every tag is `AST/000001`, so Symfony refuses to build the `Content-Disposition` header and the button 500s — **for all 18 assets, always**. | `app/Modules/Assets/Livewire/Show.php:211` — `'asset-card-'.$this->asset->tag_number.'.pdf'`. Reproduced: `InvalidArgumentException: The filename and the fallback cannot contain the "/" and "\" characters.` at `vendor/symfony/http-foundation/HeaderUtils.php:187`. Data: `assets.tag_number` = `AST/000001, AST/000002, …`. | **crash / blocker** | Route through the existing `App\Modules\Reporting\Domain\DocumentFileName::sanitize()`. |
| **Library → member → Export Library Card** | Same: `library_members.member_no` is `LM/2026/00001`. 500 for **all 20 members, always**. | `app/Modules/Library/Livewire/MemberShow.php:155` — `'library-card-'.$this->member->member_no.'.pdf'`. Same exception, reproduced. | **crash / blocker** | As above. |
| **Procurement → purchase order → Export PDF** | Same: `purchase_orders.po_no` is `BC/2026/000001`. 500 for **all 5 orders, always**. | `app/Modules/Procurement/Livewire/PurchaseOrders/Show.php:68` — `filename: 'purchase-order-'.$order->po_no.'.pdf'`. Same exception, reproduced. | **crash / blocker** | As above. |
| **Ledger → Journal Entries → new entry → pick account** | `pickAccount($index, $accountId)` writes `$this->lines[$index][...]` without checking the index exists. An out-of-range index creates a partial row with no `debit`/`credit` key, and the next render 500s. | `app/Modules/Accounting/Livewire/JournalEntries/Form.php:187` (unguarded write) → crash at `:361` `runningTotals()`: `ErrorException: Undefined array key "debit"`. Reproduced via `->call('pickAccount', 2, 2)` on a two-line form. Reachable in the UI: the blade wires both `pickAccount({{ $index }}, …)` (line 111) and `removeLine({{ $index }})` (line 151), so removing a line while a later line's picker is open lands here; also trivially reachable from the client. | **major** | Guard with `if (! isset($this->lines[$index])) return;` (and merge onto `blankLine()`). |
| **Accounting → Budgets → save a budget line** | `saveLine()` with no budget selected throws an unhandled `ModelNotFoundException` (500) instead of refusing with a validation message. | `Accounting\Livewire\Budgets\Index::saveLine()` → `Illuminate\Database\Eloquent\ModelNotFoundException: No query results for model [App\Modules\Accounting\Models\Budget]`. | **minor** (needs an unusual state to reach) | Validate `budgetId` before the action call, or catch and surface it. |
| **Payroll → payslip print** | Every one of the 18 payroll items is `cancelled`, so `/payroll/payslips/{id}/print` answers 422 for all of them — the Print button on the payroll run screen can never succeed against current data. | 18/18 requests → 422 *"Payroll item N is cancelled; a cancelled line has no payslip to issue."* | **minor** (probably a data state, not code) | Confirm the run/approve flow leaves non-cancelled items; consider hiding Print on cancelled lines rather than 422-ing. |
| **Documents → any print route** | `GET` print routes **mutate**: each first print issues an `IssuedDocument` and consumes a series serial. An 80-request read-only sweep took `issued_documents` from 12 → 86. | `/finance/invoices/{id}/print`, `/finance/payments/{id}/receipt`. | **minor** (correct by design, but a prefetching browser, a duplicated tab or a crawler burns statutory document numbers) | Consider issuing on an explicit POST, or keep the idempotency key mandatory on these controllers. |

### A.1 The unsanitised-filename family

`PdfExport::download()` (`app/Modules/Reporting/Support/PdfExport.php:22-37`) passes its
`$filename` straight to `Pdf::download()`, which builds a `Content-Disposition` header. The
codebase already owns the fix — `App\Modules\Reporting\Domain\DocumentFileName::sanitize()`,
whose docblock says in terms: *"the storage filename is derived from the serial … SANITISED,
because serials contain `/` by design ('HA/2026/RCPT/000123')"*. `PdfExport` does not call it.

Three call sites remembered to sanitise by hand (`Accounting/Livewire/Expenses/Show.php:67`,
`Fees/Livewire/CashDesk/Show.php:85`, `Accounting/Livewire/YearEnd/Console.php:247` all do
`str_replace('/', '-', …)`) — which is why `cash_desk_sessions.session_no` (`CDS/2026/000001`)
does *not* crash. The rest interpolate raw:

| Call site | Filename source | Today |
|---|---|---|
| `Assets/Livewire/Show.php:211` | `assets.tag_number` = `AST/000001` | **BROKEN** |
| `Library/Livewire/MemberShow.php:155` | `library_members.member_no` = `LM/2026/00001` | **BROKEN** |
| `Procurement/Livewire/PurchaseOrders/Show.php:68` | `purchase_orders.po_no` = `BC/2026/000001` | **BROKEN** |
| `Procurement/Livewire/SupplierInvoices/Show.php:68` | `supplier_invoices.internal_no` | latent (table empty — untestable) |
| `Inventory/Livewire/Show.php:114` | `items.item_code` = `ITM0001` | latent (no slash yet) |
| `Welfare/Livewire/Insurance/PolicyShow.php:173` | `insurance_policies.policy_no` = `POL-2026-001` | latent |
| `Welfare/Livewire/Transport/VehicleShow.php:200` | `vehicles.registration_no` = `CE-101-BC` | latent |
| `Welfare/Livewire/Hostel/RoomShow.php:181` | `hostels.code` + `hostel_rooms.name` | latent |
| `Payroll/Livewire/Show.php:263` | staff first/last name | latent (a name containing `/`) |

The single fix is to sanitise inside `PdfExport::download()`, which closes all nine at once.

---

## B. Enum-in-string sweep (task 1) — negative result, with the latent-risk list

**No further live instances of the discipline-case bug class exist.** This is a real result,
not a failure to look: the scanner found 28 concatenation hits, 117 array-offset hits and
200 string-comparison hits on enum-cast property *names*, and **every one was traced to a
`DB::table(...)` row (`stdClass`), where the Eloquent cast does not apply.**

The structural reason: this codebase renders its detail and list screens from raw
`DB::table` joins (an explicit module-boundary rule), so an enum-cast attribute almost never
reaches a Blade template. Only three screens hand a real Eloquent model to a view:

| Screen | Model | Verdict |
|---|---|---|
| `Welfare\Livewire\Discipline\CaseShow` | `DisciplineCase` (`with('category','sanctions')`) | The one that bit — fixed in `c1ccba2`. The other five call sites in that file already use `->value`. |
| `Tax\Livewire\Declarations\Show` | `TaxDeclaration` (`status` → `DeclarationStatus`) | **Safe** — the blade correctly uses `$declaration->status->value` (line 12) and `->isFileable()` / `->isFiled()` (lines 22, 27). |
| `Communication\Livewire\Outbox\Index` | `OutboxMessage` (`$selected`) | **Safe** — `strtoupper($selected->language)` at `outbox/index.blade.php:41`; `language` is not enum-cast on `OutboxMessage` (only `status` and `kind` are, and both are used via `->label()`). |

**Latent risk (currently safe, would crash the moment the backing query is refactored to
Eloquent).** These are the highest-density clusters — worth a comment or a `->value` today:

- `resources/views/livewire/welfare/insurance/policy-show.blade.php:44,46,85,193,254` —
  `__('…'.$policy->status)`, `.$policy->cover_type`, `.$claim->status`. `InsurancePolicy`,
  `InsuranceClaim` and `StudentInsurance` all enum-cast these.
- `resources/views/livewire/welfare/transport/vehicle-show.blade.php:43,219,378,420` —
  `.$vehicle->status`, `.$allocation->direction`, `.$log->type`.
- `resources/views/livewire/welfare/hostel/room-show.blade.php:32,82,216` —
  `.$room->gender`, `.$inspection->rating`.
- `resources/views/livewire/attendance/index.blade.php:114,122` — `.$row->session`, `.$row->status`.
- `resources/views/livewire/operations/rollover-wizard.blade.php:420` — `.$run->status`.
- `resources/views/livewire/procurement/payments/show.blade.php:37,38` and
  `supplier-invoices/show.blade.php:45,46` — `$payTone[$payment->status]`,
  `str_replace('_',' ', $payment->clearing_state)`, `$invoice->match_status`. **These are the
  most dangerous of the set**: `SupplierPayment` and `SupplierInvoice` enum-cast every one of
  those columns, the screens are backed by `DB::table` *today*, and `supplier_payments` /
  `supplier_invoices` are **empty**, so nothing would catch a regression here.

Confirmed **false positives** (checked, no cast on the model in question):
`Accounting/Livewire/JournalEntries/index.blade.php:104,154` (`JournalEntry` has no `status`
cast), `Tax/Actions/GenerateDsf.php:238` (`stdClass`), `Fees/Actions/StudentStatement.php:175`
(`stdClass`), `HR/Livewire/Reports/Index.php:382` (`stdClass`),
`Accounting/Actions/Books/BuildGrandLivre.php:98` (joined column on `JournalEntry`, cast lives
on `JournalEntryLine`), `Payroll/Actions/ExportDisbursementFile.php:88,91` and
`Procurement/Actions/ExportPaymentBatch.php:91,108,116` (`payment_method` is **not** cast on
`PayrollPayment` or `SupplierPayment`).

---

## C. Coverage gaps — where the next bug is hiding

These tables are **empty in the dev database**, so their detail screens and flows could not be
exercised by any sweep, and no fixture covers them either:

`expenses`, `supplier_invoices`, `supplier_payments`, `tax_declarations`,
`withholding_attestations`.

Unreachable as a result: `/accounting/expenses/{expense}`, `/procurement/invoices/{invoice}`,
`/procurement/payments/{payment}`, `/procurement/payments/{payment}/voucher`,
`/tax/declarations/{declaration}`, `/tax/withholding-attestations/{attestation}/print`.

**Three of the seven confirmed crashes are in Procurement — the most data-starved module.**

### The structural reason all seven survived

The 279-file suite is written at the **Action / domain layer**. The Livewire presentation
layer — reactive `updated*` handlers, row actions, and export buttons — is essentially
untested. `grep` over `tests/` finds **zero** references to:

- `startAmend`, `updatedFormPurchaseOrderId`, `updatedCreditNoteInvoiceId` (the three 500s),
  despite 20 procurement test files;
- any `export*Pdf` / `exportAssetCardPdf` / `exportLibraryCardPdf` action, despite 12 asset
  and 5 library test files;
- any asserted download filename (`asset-card-`, `library-card-`, `purchase-order-`);
- `PrintReportCard` or the `report-cards` print route.

Every crash in section A lives in exactly that untested band. Two changes would close it:

1. **Component tests for `updated*` handlers and export actions.** A single loop that mounts
   each component and calls each `updated*` handler and `export*` action would have caught
   six of the seven. (The harness used for this audit is a working proof; it found them in
   one pass.)
2. **Seed the empty modules and give fixtures production-shaped identifiers.** Fixtures that
   use `AST1` where production uses `AST/000001` cannot catch the filename family at all.

---

## D. What passed

Recorded so a future regression is visible:

- **123 admin GET routes**: 112 × 200, 3 × 302, 2 × 404 (ids absent), 2 × 422 (documented
  domain refusals), 1 × 403 (`/settings/licence` — `can:licence.manage`, correctly withheld
  from the demo administrator; no sidebar link points at it), 3 skipped (empty tables).
- **386 detail-route requests across every record** — **zero** 500s. Notably all 4 discipline
  cases now render (the `c1ccba2` fix holds against real data).
- **59 guardian-portal paths** as a real guardian with two children — **zero** non-200,
  including fee detail, receipts, health detail, ID card and message threads.
- **145 Livewire components mount**; **503 action invocations** and **340 `updated*`
  handler invocations** — only the failures in section A. Every `save`/`store`/`submit`
  action invoked with empty state raised `ValidationException` (a correct refusal), not a
  crash: **no CRUD form was found that crashes on an empty submit.** Spot-checked live:
  `/users/create` with an empty form returns *"The name field is required."*, *"The email
  field is required."*, *"The role field is required."*, *"The password field is required."*
- **147 delete / archive / void / cancel / deactivate / toggle invocations** across every
  component — **zero** failures. Run inside rolled-back transactions with a post-run integrity
  check confirming all 16 watched table counts were unchanged; filesystem-touching components
  (Documents, Branding, Import, Backups, Photo, BulkPrints) were excluded because a rollback
  cannot undo an `unlink`, so those delete paths remain unaudited.
- **Query-builder misuse scan**: the three `DB::table(...)->whereKey(...)` calls in section A
  are the *complete* set — no other Eloquent-only builder method (`whereHas`, `withCount`,
  `whereBelongsTo`, `withTrashed`, …) is called on a `DB::table` chain anywhere in `app/`.
- Note: student creation is not on `/students` by design (it runs through
  `/admissions/wizard` and `/students/import`); that is architecture, not a missing button.

**Discarded as harness noise, for the record.** 12 guardian/staff-portal actions reported
`InvalidArgumentException: Invalid Livewire snapshot structure` under `Livewire::test()`.
These are not defects: `Guardians\Livewire\Portal\SchoolIdCard::reveal()`, for instance, is
literally `$this->revealed = true;` and cannot throw. The harness cannot snapshot the nested
portal layout. Those screens are independently covered by the 59-path portal sweep, which
was clean.

---

## E. Test suite

### E.1 Unit + Architecture — **2 failures, both real** (`--testsuite=Unit,Architecture`)

```
261 tests, 259 passed, 2 failed, 3319 assertions, 71.8s
```

Both failures are in `tests/Architecture/ModuleBoundaryTest.php`, which forbids any module
from importing another module's `Models`. No database, no flakiness — deterministic, and
**failing on `main` right now**.

| Failing test | Violating code | Introduced |
|---|---|---|
| `HR does not reach into another module's Models` — *Expecting `App\Modules\HR` not to use `App\Modules\Identity\Models`* | `app/Modules/HR/Actions/GrantStaffPortalAccess.php:10` — `use App\Modules\Identity\Models\User;` | `09f9ee4 feat(hr): give admins a way to grant staff their own portal access` — one of the most recent commits |
| `Communication does not reach into another module's Models` — *Expecting `App\Modules\Communication` not to use `App\Modules\Identity\Models`* | `app/Modules/Communication/Livewire/Messages/Index.php:13`, `Outbox/Index.php:14`, `Templates/Index.php:12` | `31c0840` and `aebf5f2` (older), but only exposed once the rule was tightened |

The rule became absolute in `b671525 refactor: pass an Actor value object to the audit log,
not a User model`, which removed the one standing exception; the test file now says so in
terms ("No exceptions… the rule is absolute again"). Three sibling HR Actions
(`HireStaffMember.php:158`, `OpenStaffContract.php:254`, `SaveCostAllocation.php:152`) each
carry the comment *"No textual reference to the Identity User model crosses this module"* —
so the intended pattern is well established and `GrantStaffPortalAccess` simply missed it.

Fix: route the user lookup/creation through `Identity\Actions\CreateUser` (already imported
in that file) and a shared-kernel value object rather than typing against the `User` model;
for the three Communication screens, resolve names via `DB::table('users')` as the other
screens in that module already do.

### E.2 Feature suite — did not complete

`DB_DATABASE=opeschool_test_verify php artisan test`, started at the beginning of this audit
and run in the background throughout.

**The full run never completed and was killed after more than an hour** — it produced no
output at all, so there are no pass/fail counts for the Feature suite (258 of the 279 test
files). That is itself worth recording, because part of the cause looks like a defect in the
test setup rather than plain slowness:

- 245 of the 279 `*Test.php` files use `RefreshDatabase`, which is meant to run `migrate:fresh`
  **once per process** and then wrap each test in a transaction.
- MySQL's process list was sampled repeatedly during the run and showed **more than one full
  `migrate:fresh` cycle**: at one point connection `11877` was midway through applying
  migrations (`alter table document_print_logs add constraint fk_print_logs_template`), and
  roughly ten minutes later connection `11898` was executing a `drop table` of the entire
  schema, immediately followed by connection `11904` re-applying migrations from the start.
  A single `migrate:fresh` drops and then migrates; it does not migrate, then drop, then
  migrate again.
- Each cycle is expensive: the scratch schema held **284 tables** and there are **225
  migrations**, measured at roughly 0.5 migrations/second — about 7–8 minutes per cycle,
  before a single test body runs.

Worth investigating whether a test calls `migrate:fresh` explicitly (there is a comment
acknowledging the once-per-process contract at `tests/Feature/Assessment/PublicationTest.php:79`)
or whether a second connection/`RefreshDatabaseState` reset is defeating the cache. If each
such reset costs ~8 minutes, a handful of them turns a 15-minute suite into an unrunnable one —
which would explain why the presentation-layer gap in section C was never closed by anyone
simply running the tests.

**Caveat — this machine was heavily contended, so treat the timing (not the repeated-cycle
observation) as unreliable.** During the run, the same MySQL instance was also serving another
project's test suite (`meritra_laravel_test`, actively creating tables), and an unrelated
`composer update --with-all-dependencies` (PID 4516) was running, which can rewrite `vendor/`
mid-run. Re-run the suite on a quiet machine before treating any failure it eventually reports
as real. The repeated `migrate:fresh` evidence is independent of contention — the connection
IDs on `opeschool_test_verify` increase monotonically through migrate → drop-all → migrate —
but the wall-clock cost per cycle was certainly inflated by the competing load.
