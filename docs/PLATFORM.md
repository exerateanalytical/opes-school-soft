# OPES — Platform Overview

A school-management platform built for Cameroon, covering the full
administrative life of a school: students, academics, attendance, fees,
accounting, payroll, procurement, welfare, and a guardian-facing portal.
Single install per school (no shared multi-tenant database) — "SaaS" here
means hosted-and-managed-for-you, not shared infrastructure.

This document is a map, not an audit. For current build status of any
specific feature, see `docs/superpowers/PROGRESS.md`; that file changes
often and is the source of truth for "what's done right now."

---

## 1. What it is built on

| Layer | Choice |
|---|---|
| Backend | Laravel 11, PHP 8.3 |
| Frontend | Livewire 3/4 + Blade, Tailwind CSS |
| Database | MySQL 8.4 (MariaDB explicitly unsupported — the platform needs `utf8mb4_0900_*` collations, which are MySQL-only) |
| Charts | Hand-rolled inline SVG, no JS charting library by design — one visual language across the app, no build-step dependency |
| Testing | Pest, feature-first (most modules have little to no dedicated unit-level testing — see §5) |
| Local dev | Laragon only |

**Two numeric rules that hold everywhere, no exceptions:**
- **Money** is `BIGINT SIGNED`, whole FCFA, always through `App\Support\Money\Money`. Never `float`, never `DECIMAL`.
- **Rates** are integer basis points through `App\Support\Rate\Rate` (100 000 bp = 100%), parsed from strings — a statutory rate must reproduce exactly, forever.

## 2. How the code is organized

Everything domain-specific lives under `app/Modules/<Name>/`, each with its
own `Actions/`, `Models/`, and `Livewire/`. Two rules keep the modules from
turning into one big tangle:

- **Cross-module reads go through `DB::table()` only.** Importing another
  module's Eloquent Model fails `tests/Architecture/ModuleBoundaryTest.php`
  on purpose — a module's internal shape is its own business.
- **One Action, one operation.** Actions are typically `final readonly class`
  with a single `handle()`, gated with `Gate::authorize()` on a real
  permission string. This is the whole write/read surface — Livewire
  components compose Actions, they don't contain business logic themselves.

Every screen is reached two ways at once, on purpose: a route
(`routes/web.php`, permission-gated) and a sidebar entry
(`app/Modules/Identity/Support/Navigation.php`, same permission). A route
with no nav entry is a screen nobody can find; a nav entry with no route
500s. Both directions are checked by `tests/Feature/Shell/ReachabilityTest.php`.

**Every user-facing string exists in English and French, always together.**
`lang/en/opes.php` and `lang/fr/opes.php` are kept in exact key-for-key sync
— this is enforced, not a convention someone might forget.

## 3. The modules

Grouped by what they're for, not alphabetically.

**People**
`Identity` (auth, roles, permissions, audit log) · `Students` · `Guardians`
(+ the guardian-facing portal at `/portal`) · `HR` · `Staff` self-service.

**Academics**
`Academics` (classes, subjects, curriculum wiring) · `Assessment` (marks,
exams, report cards) · `Curriculum` · `Attendance` · `Admissions`.

**Money**
`Fees` (invoicing, cashier, aged receivables) · `Accounting` (the ledger —
chart of accounts, journal entries, trial balance, statutory books,
year-end close, budgets, expenses, reconciliation) · `Tax` (VAT,
withholding, DSF, fiscal identity) · `Payroll`.

**Operations**
`Procurement` (suppliers, orders, invoices, payments) · `Inventory` ·
`Assets` (registry, maintenance, depreciation) · `Library` · `Welfare`
(hostel, transport, insurance, discipline, medical, visitors) ·
`Activities` · `Alumni`.

**Platform**
`Operations` (the role dashboards, go-live readiness, backups) ·
`Reporting` (the reports hub, document rendering, bulk print) ·
`Communication` (in-app messaging) · `Notifications` (in-app + web push) ·
`SchoolProfile` (branding, settings, licensing) · `Forms`.

## 4. Two things unique to this platform's domain

**SYSCOHADA/OHADA accounting, not generic double-entry.** The ledger follows
the West/Central African accounting standard: a numbered chart of accounts
by class (1–9), statutory books required by AUDCIF (livre-journal, grand
livre, balance générale — a 4th, livre d'inventaire, is not yet built), and
a DSF (Déclaration Statistique et Fiscale) tax filing with government-defined
line codes. Where a real government number is required (a DGI line code, a
CNPS rate) and none has been supplied, the platform reports the gap
honestly rather than inventing one — this is a standing project rule, not
an oversight.

**Every generated document is reproducible, byte-for-byte, forever.** A
report card, a receipt, a payslip — once issued, a reprint must produce the
*original* hash and the *original* signature, even if the signature image
on file has since been replaced. A preview burns no serial number and logs
no print. Deleting a frozen source file raises an error rather than
silently printing a certificate with a missing signature. This
reproducibility guarantee is deliberately stronger than most systems bother
with, because these are legal documents a school hands to a parent or a
ministry.

## 5. Known shape of the codebase, for whoever works in it next

- **Coverage is uneven.** Accounting, Assessment, Operations, Students, and
  Reporting are well-tested. Guardians, Fees, HR, Tax, and Welfare are
  comparatively thin relative to their size. Communication, Forms, and
  Notifications have almost none. Only 4 of 26 modules have any dedicated
  unit-level tests — everything else is verified exclusively through
  feature/HTTP tests.
- **The demo dataset is thin on purpose in some places, empty by accident
  in others.** Budgets, expenses, and tax declarations have working CRUD
  screens with real write paths — they're empty because nobody has used
  them yet, not because they're broken. Refunds and write-offs are
  genuinely unbuilt: no table, no Action, no screen.
- **A few features are hard-disabled deliberately**, not left half-done:
  asset impairment, asset revaluation, and the payroll e-DIPE export all
  refuse on every call, each pending verification of a real government
  account code or file format before they're allowed to run.

## 6. Running it locally

Laragon only — see `docs/DEVELOPMENT.md` for the full toolchain paths and
the numeric/testing conventions in detail. In short:

```bash
"C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" artisan serve
```

The demo-login panel (enabled only outside production) signs in as any of
20 real roles with one click — each is a genuine Spatie role, so what it
can see is decided by the same permission checks a real account would hit,
not a bypass.
