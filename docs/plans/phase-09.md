# Phase 9 Implementation Plan — Assets, Inventory, Library

Source of truth: `/home/user/opes-school-soft/docs/specs/06-assets-stores.md` (v2.0), bound by `docs/specs/00-core.md`. Patterns copied from Phase 6 (Fees): Actions as cross-module doors, `DB::table` cross-module reads, all ledger writes via `App\Modules\Accounting\Actions\PostFromEvent::handle(string $event, array $payload, string $date, Actor $actor, ?string $reference)`, Pest + real MySQL `RefreshDatabase`, PHPStan level 8 zero-suppression, Navigation placeholder-flip.

## 0. Preconditions and dependency warning

- **Phase 5 (procurement) is NOT built.** The spec makes Phase 9 depend on it (`Supplier`, `SupplierInvoice`, `PurchaseOrder`, goods receipt). Only `tax_codes` exists (`2026_08_08_240012`). Resolution: all `supplier_id` / `supplier_invoice_id` / `purchase_order_id` columns ship as **unconstrained nullable BIGINT** (no FK) with a `// FK added in Phase 5` comment; acquisition/receipt Actions accept a manual `document_ref` path. Do not build the `03`-owned purchase entry — Phase 9 posts only the entries this spec owns (`asset.acquired` capitalisation leg, stock-entry leg, etc. remain, keyed to manual documents until Phase 5).
- **NEEDS VERIFICATION register (§11)** is load-bearing: V1–V17 columns ship NULL, seeders insert nothing for them, Actions refuse with a message naming the missing configuration. Never seed a guessed account. Verified codes usable without a flag: 812/822/816/826, 485, 481, 601/602/604, 6031/6032/6033, 31/32/33, 701, 401, 4111, 2442, class-14.
- New posting events (spec §12) must be registered wherever `PostingRule.event` is enumerated (check `app/Modules/Accounting` posting-rule validation and the `2026_08_08_230012_create_posting_rules_table.php` enum/validation path; a follow-up migration may be needed if the event list is a DB ENUM).

## 1. Migrations (pre-assigned filenames, `database/migrations/`)

Numbering continues the phase-block convention (`24xxxx` = Phase 6 → `25xxxx` = Phase 9). All money BIGINT SIGNED, quantities DECIMAL(14,3), identifier columns `utf8mb4_0900_as_cs`, dual `fiscal_year_id`+`academic_year_id`, RESTRICT everywhere with financial history.

**F1 — Assets register**
- `2026_08_09_250001_create_asset_categories_table.php` — §2.1, CHECKs A1/A2/A4
- `2026_08_09_250002_create_assets_table.php` — §2.2, CHECKs A6/A8; generated/unique per spec
- `2026_08_09_250003_create_asset_custody_movements_table.php` — §2.3 append-only + BEFORE UPDATE/DELETE reject triggers
- `2026_08_09_250004_create_asset_maintenance_requests_table.php` — §2.4
- `2026_08_09_250005_create_asset_construction_costs_table.php` — §3, append-only trigger

**F2 — Depreciation, disposal, subsidies**
- `2026_08_09_250006_create_depreciation_runs_table.php` — §4.1, `UNIQUE(fiscal_year_id, period_month)`
- `2026_08_09_250007_create_depreciation_schedules_table.php` — §4.2, both UNIQUEs + both CHECKs
- `2026_08_09_250008_create_asset_disposals_table.php` — §6.1, `gain_or_loss` GENERATED STORED, UNIQUE(asset_id)
- `2026_08_09_250009_create_investment_subsidies_tables.php` — §6.3 (+ `investment_subsidy_releases`, UNIQUE(subsidy, asset, run))
- `2026_08_09_250010_create_asset_impairments_and_revaluations_tables.php` — §6.5/§6.6 (`revaluation_campaigns`, `asset_revaluations`)

**F3 — Inventory**
- `2026_08_09_250011_create_item_categories_and_units_tables.php` — §7.2 + `units_of_measure`
- `2026_08_09_250012_create_items_and_store_locations_tables.php` — §7.3/§7.4
- `2026_08_09_250013_create_stock_balances_table.php` — §7.5, composite PK, CHECKs I6–I9 incl. `(quantity_on_hand = 0) = (value_on_hand = 0)`
- `2026_08_09_250014_create_stock_movements_table.php` — §7.6, CHECK I10, append-only triggers (I11), `reversal_of_movement_id` UNIQUE
- `2026_08_09_250015_create_stock_reservations_and_requisitions_tables.php` — §7.7/§7.8 (`store_requisitions` + lines)
- `2026_08_09_250016_create_stock_issues_and_transfers_tables.php` — §7.8/§7.9 (headers + lines)
- `2026_08_09_250017_create_stock_takes_tables.php` — §7.10 (`stock_takes`, `stock_take_lines`, UNIQUE(stock_take_id, item_id), generated variance)

**F4 — Library**
- `2026_08_09_250018_create_books_and_book_categories_tables.php` — §10.1 + `shelf_locations`
- `2026_08_09_250019_create_book_copies_and_acquisitions_tables.php` — §10.2/§10.8
- `2026_08_09_250020_create_library_members_tables.php` — §10.3 + `membership_classes`, CHECK on member identity
- `2026_08_09_250021_create_library_issues_tables.php` — §10.4, `open_copy_key` GENERATED + `uq_open_issue`, `library_renewals`, `library_reservations`
- `2026_08_09_250022_create_library_fines_table.php` — §10.5
- `2026_08_09_250023_create_library_visits_table.php` — §10.9

**F5 — wiring**
- `2026_08_09_250024_add_phase9_school_profile_settings.php` — `library_capitalisation_policy` (default `expensed`), fiscal-divergence disposition, stock-take variance threshold
- `2026_08_09_250025_register_phase9_posting_events.php` — only if `posting_rules.event` is DB-constrained; otherwise seed PostingRules for the verified-account events (`asset.disposed`, `inventory.received/issued/stocktake.variance/sale`, `library.fine.levied`) and leave gated ones unseeded

## 2. Models

One Eloquent model per table above, in `app/Modules/{Assets,Inventory,Library}/Models/`. No cross-module model imports (ModuleBoundaryTest): `chart_of_accounts`, `students`, `staff`, `enrollments`, `invoices` reached via `DB::table` inside Actions only. Append-only models get no `update()` paths; casts: money `int`, quantities `string` (DECIMAL) with a small `Quantity` value helper in `Domain/` if convenient.

## 3. Domain (pure, no Eloquent)

- `Assets/Domain/DepreciationCalculator.php` — §4.3 entitlement/catch-up formula, all four prorata conventions (§5.2), declining-balance replay, `min()` cap. Pure function of scalars; the property tests target this class.
- `Assets/Domain/SubsidyReleaseCalculator.php` — §6.4 mirrored entitlement formula.
- `Inventory/Domain/WeightedAverageCost.php` — §7.1 `issue_cost` arithmetic incl. empty-bin exact rule.
- `Library/Domain/FineCalculator.php` — §10.5 idempotent entitlement accrual, grace days, replacement-cost cap, closed-day exclusion.

## 4. Actions (all take `idempotency_key`; posting via PostFromEvent only)

**Assets (F1):** `CreateAssetCategory` (A3 class-prefix validation, A5 freeze), `RegisterAsset`/`CapitaliseAsset` (residual A7 snapshot, event `asset.acquired`), `CommissionAsset` (§3, `asset.commissioned`), `SplitIntoComponents` (A11 `Money::allocate`), `RecordCustodyMovement`, `RecordConstructionCost`, `CreateMaintenanceRequest`/`CloseMaintenanceRequest` (explicit expense-vs-capitalise choice).

**Assets (F2):** `RunDepreciation` (draft→calculated, FOR UPDATE on run row, exception report, V1 gate refusal), `ApproveDepreciationRun` (maker≠checker), `PostDepreciationRun` (one JE, `asset.depreciated`; subsidy releases in same run via `asset.subsidy.released`, skipped-with-exception when 845 unconfigured), `DisposeAsset` (§4.5 depreciate-to-date + gross two-leg entry, component cascade, `asset.disposed`), `RegisterInvestmentSubsidy`, `ClawBackSubsidy`, `ImpairAsset` / `RevalueAssets` (gated-off, config-refusal only), `ChangeDepreciationEstimate` (§5.5, reason + AuditLog).

**Inventory (F3):** `ReceiveStock` (avg moves; equipment→`CapitaliseAsset` handoff §8.6; `inventory.received`), `IssueStock` (header-level JE, `inventory.issued`, I6 rejection under FOR UPDATE), `TransferStock` (ordered two-row locking, posts only on stock-account difference), `AdjustStock`, `ReserveStock`/`ReleaseReservation`, `CreateStoreRequisition`/`ApproveStoreRequisition`, `StartStockTake` (freeze + counting block flag), `RecordStockTakeCounts`, `ApproveStockTake` (segregation), `PostStockTakeVariance` (`inventory.stocktake.variance`), `SellMerchandise` (§8.5: revenue leg gated on 571x/443x verification for cash; credit path calls the **Fees invoicing Action** — cross-module door — producing an Invoice, plus cost-of-sales leg `inventory.sale`), `ReverseStockMovement` (compensating movement, I11).

**Library (F4):** `RegisterBook`/`AddBookCopies`, `RecordBookAcquisition` (`library.acquisition.recorded`; capitalised policy hard-gated V17), `EnrollLibraryMember` (student via enrollment `DB::table` read; invariant `student_id = enrollment.student_id`), `IssueBook` (copy row lock → member row lock fixed order; limit/fine/reference checks; DB `uq_open_issue` as last line of defence), `ReturnBook` (reservation promotion + Communication outbox), `RenewIssue`, `AccrueOverdueFines` (nightly, idempotent), `LevyFine` → for students calls **Fees' invoicing Action** against an `own_revenue` FeeItem (§10.7 single debt stream; `library.fine.levied`), for staff queues a payroll-deduction stub row (posts nothing until Phase 11), `WaiveFine` (approver ≠ levier, `library.fine.waived`), `MarkIssueLost` (`library.book.lost`), `RecordLibraryVisit`.

Scheduled jobs (register in console kernel/schedule): nightly `open→overdue` promotion + `AccrueOverdueFines`; reservation expiry; stock-reservation expiry.

## 5. Permissions and seeder

Add to `app/Modules/Identity/Domain/Permission.php` (two-segment values, matching house style — a test enforces it):
`AssetView = 'asset.view'`, `AssetManage = 'asset.manage'`, `AssetDepreciate = 'asset.depreciate'`, `AssetDispose = 'asset.dispose'`, `InventoryView = 'inventory.view'`, `InventoryManage = 'inventory.manage'`, `InventoryPost = 'inventory.post'` (issues/adjustments/stock-takes), `LibraryView = 'library.view'`, `LibraryManage = 'library.manage'`, `LibraryCirculate = 'library.circulate'`, `LibraryFineWaive = 'library.waive_fine'`. Map into `database/seeders/RolePermissionSeeder.php` (bursar/accountant get asset+inventory; librarian role if roles are data — follow existing role mapping pattern).

## 6. Livewire screens, routes, navigation (F5)

Screens (pixel-faithful to `frontend images/` `Inventory management.png`, `libray management.png`; asset register has no mockup — reuse Fees list chrome):
- `Inventory/Livewire/Index.php` — §9 contract: 5 KPI aggregate query, filters, `(item, location)` grid, donut priority ladder, recent movements, low-stock rail. Server-paginated.
- `Inventory/Livewire/StockOperations.php` (adjustment/transfer/issue modals or sub-screens), `Inventory/Livewire/StockTakes.php`.
- `Library/Livewire/Index.php` — §10.9 tabs (Book List/Issued/Returned/Overdue/Members), KPIs with stated populations, right rail. `Library/Livewire/Circulation.php` — keyboard-first scan flow (Alpine holds scan-pair state).
- `Assets/Livewire/Register.php` (asset list + KPIs), `Assets/Livewire/DepreciationRuns.php` (run/approve/post with exception report), `Assets/Livewire/AssetShow.php` (schedule, custody, disposal).

Routes in `routes/web.php` (after the finance block, same style):
```
/assets            can:asset.view      assets.register
/assets/depreciation can:asset.depreciate assets.depreciation
/inventory         can:inventory.view  inventory.index
/inventory/stock-takes can:inventory.post
/library           can:library.view    library.index
/library/circulation can:library.circulate
```
`app/Modules/Identity/Support/Navigation.php`: flip `library` and `inventory` to `built => true` with their permissions; keys leave `placeholderRoutes()` automatically (it filters on `built`). No `assets` sidebar key exists — either hang the asset register under `inventory` (secondary nav/tab) or add a nav item; decide against `docs/specs/09-ui.md` and keep `tests/Feature/Ui/ShellTest.php` green.

## 7. Test list (Pest, per-agent DBs)

- **F1** (`opeschool_test_f1`): `tests/Feature/Assets/AssetCategoryTest` (A1–A5), `AssetRegistrationTest` (A6–A8, residual snapshot, capitalisation entry to 481), `ComponentTest` (A10 cycle, A11 conservation), `ConstructionTest` (A14, commissioning transfer), `CustodyMaintenanceTest`.
- **F2** (`f2`): `DepreciationRunTest` (acceptance 1, 3, 12: idempotency, catch-up, config-gate refusal), `DepreciationPropertyTest` (acceptance 2: Σ = cost−residual over randomized lives/costs; declining-balance replay), `DisposalTest` (acceptance 4: gross legs, no gain_or_loss line; mid-period depreciate-to-date; component cascade; fully-depreciated zero-line omission), `SubsidyTest` (acceptance 5 neutrality, partial funding, clawback, 845-unconfigured skip), `EstimateChangeTest` (negative charge).
- **F3** (`f3`): `StockValuationTest` (§8.1–8.3 golden figures incl. 603x sign, empty-bin acceptance 8), `StockConcurrencyTest` (acceptance 7 last-unit race, acceptance 9 transfer deadlock ordering), `StockLedgerTieTest` (acceptance 6, reduced N for CI), `StockTakeTest` (§8.4 variance, freeze/block, segregation), `MerchandiseSaleTest` (§8.5 both legs, credit-sale→Fees invoice), `EquipmentReceiptTest` (§8.6 threshold behaviours), `MovementImmutabilityTest` (I11 triggers, reversal).
- **F4** (`f4`): `LibraryCatalogTest`, `MembershipTest` (keying invariant, CHECK), `CirculationTest` (acceptance 10 double-issue via `uq_open_issue`, limits, reference-only, renewals/reservations), `FineTest` (acceptance 11 idempotent accrual, cap, grace, waiver segregation, student fine→Fees invoice single-debt-stream, staff route snapshot), `LostBookTest`.
- **F5** (`f5`): `tests/Feature/Ui/AssetsScreensTest`, `InventoryScreensTest`, `LibraryScreensTest` (KPI queries, permission gates), `ShellTest` stays green after nav flip, permission-seeding test.
- Architecture: extend `tests/Architecture/ModuleBoundaryTest.php` coverage to the three new modules; add the §7.1 assertion that no Action reads `items.weighted_avg_cost`.

All helpers `function_exists`-guarded with globally unique names (e.g. `phase9AssetFixture()`, `phase9StockItem()`); `Carbon::parse` not `Carbon::create`; `(int)` cast on SUM.

## 8. Parallel agent scopes (disjoint; worktrees; exact-path `git add`)

| Agent | Test DB | Scope | Migrations |
|---|---|---|---|
| F1 | opeschool_test_f1 | Assets register: categories, assets, custody, maintenance, construction, Capitalise/Commission/Split Actions + tests | 250001–250005 |
| F2 | opeschool_test_f2 | Depreciation engine (Domain calculators), runs/schedules, disposal, subsidies, impairment/revaluation gates + tests | 250006–250010 |
| F3 | opeschool_test_f3 | Inventory: all tables, WAC domain, all stock Actions, merchandise sale + tests | 250011–250017 |
| F4 | opeschool_test_f4 | Library: all tables, circulation, fines (incl. Fees-invoice integration), jobs + tests | 250018–250023 |
| F5 | opeschool_test_f5 | Permissions enum + seeder, SchoolProfile settings, posting-event registration/PostingRule seeds, all Livewire screens, routes, Navigation flip, UI tests | 250024–250025 |

Sequencing: F1 and F3/F4 can start immediately (F3's `asset_category_id` on items is a nullable ID, no FK needed cross-agent, or F3 depends only on F1's 250001 filename which is pre-assigned). F2 depends on F1's schema (merge F1 first or F2 builds against F1's committed migrations). F5 goes last for wiring but can build screens against pre-agreed model/Action signatures early. Contract freeze up front: table names, Action class names + `handle()` signatures, event names (§12), permission values — all fixed by this plan so agents never touch the same file. Only shared-file edits (`routes/web.php`, `Navigation.php`, `Permission.php`, `RolePermissionSeeder.php`) belong exclusively to F5.

Definition of done per agent: own suite green on own DB after `migrate:fresh`, PHPStan 0 on touched paths. Integration: SOLO full suite on `opeschool_test`, PHPStan repo-wide, `composer deploy`, live verify at opeschool.test.

### Critical Files for Implementation
- /home/user/opes-school-soft/docs/specs/06-assets-stores.md
- /home/user/opes-school-soft/app/Modules/Accounting/Actions/PostFromEvent.php
- /home/user/opes-school-soft/app/Modules/Identity/Support/Navigation.php
- /home/user/opes-school-soft/app/Modules/Identity/Domain/Permission.php
- /home/user/opes-school-soft/routes/web.php