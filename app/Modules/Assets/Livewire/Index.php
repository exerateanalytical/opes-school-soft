<?php

declare(strict_types=1);

namespace App\Modules\Assets\Livewire;

use App\Modules\Assets\Actions\ApproveDepreciationRun;
use App\Modules\Assets\Actions\CloseMaintenanceRequest;
use App\Modules\Assets\Actions\CommissionAsset;
use App\Modules\Assets\Actions\CreateAssetCategory;
use App\Modules\Assets\Actions\CreateMaintenanceRequest;
use App\Modules\Assets\Actions\DisposeAsset;
use App\Modules\Assets\Actions\PostDepreciationRun;
use App\Modules\Assets\Actions\PrintAssetLabel;
use App\Modules\Assets\Actions\RegisterAsset;
use App\Modules\Assets\Actions\RunDepreciation;
use App\Modules\Assets\Domain\AcquisitionType;
use App\Modules\Assets\Domain\AssetPermission;
use App\Modules\Assets\Domain\AssetStatus;
use App\Modules\Assets\Domain\DepreciationMethod;
use App\Modules\Assets\Domain\DepreciationRunStatus;
use App\Modules\Assets\Domain\DisposalSettlement;
use App\Modules\Assets\Domain\DisposalType;
use App\Modules\Assets\Domain\MaintenancePriority;
use App\Modules\Assets\Domain\MaintenanceResolution;
use App\Modules\Assets\Domain\MaintenanceStatus;
use App\Modules\Assets\Domain\ProrataConvention;
use DomainException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response;

/**
 * Asset register at /assets, gated `asset.view`: a read-only Index modeled
 * on Welfare\Transport\Index.php - KPI strip, filter bar with #[Url]
 * properties, tabbed table (Assets / Maintenance / Depreciation Runs).
 *
 * Same-module DB::table reads only (ModuleBoundaryTest); no Models from
 * other modules are queried directly. One paginated query per render plus
 * dataset-wide KPI aggregates; no unbounded collection reaches the view.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    /** Which table is showing: assets | maintenance | depreciation. */
    #[Url]
    public string $tab = 'assets';

    #[Url]
    public string $category = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $search = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    // ── Register asset form ─────────────────────────────────────────────
    public bool $showAssetForm = false;

    public string $assetFormTagNumber = '';

    public string $assetFormName = '';

    public string $assetFormCategoryId = '';

    public string $assetFormSerialNumber = '';

    public string $assetFormAcquisitionType = 'purchase';

    public string $assetFormAcquisitionDate = '';

    public string $assetFormAcquisitionCost = '';

    public string $assetFormNotes = '';

    // ── New maintenance request form ────────────────────────────────────
    public bool $showMaintenanceForm = false;

    public string $maintenanceFormAssetId = '';

    public string $maintenanceFormTitle = '';

    public string $maintenanceFormPriority = 'medium';

    public string $maintenanceFormDescription = '';

    // ── Dispose asset form (toggled per row) ────────────────────────────
    public bool $showDisposeForm = false;

    public ?int $disposeAssetId = null;

    public string $disposeAssetLabel = '';

    public string $disposeType = 'scrap';

    public string $disposeDate = '';

    public string $disposeProceeds = '';

    public string $disposeBuyerPartnerId = '';

    public string $disposeSettlement = '';

    public string $disposeReason = '';

    // ── Run depreciation form ───────────────────────────────────────────
    public bool $showRunDepreciationForm = false;

    public string $runFiscalYearId = '';

    public string $runPeriodMonth = '';

    // ── Close maintenance request form (toggled per row) ────────────────
    public bool $showCloseMaintenanceForm = false;

    public ?int $closeMaintenanceRequestId = null;

    public string $closeMaintenanceResolution = 'expense';

    public string $closeMaintenanceJustification = '';

    public string $closeMaintenanceActualCost = '';

    public string $closeMaintenanceCapitaliseAs = 'increase_cost';

    // ── Create asset category form ──────────────────────────────────────
    public bool $showCategoryForm = false;

    public string $categoryFormCode = '';

    public string $categoryFormName = '';

    public string $categoryFormNameFr = '';

    public string $categoryFormDepreciationMethod = 'none';

    public string $categoryFormUsefulLifeMonths = '';

    public string $categoryFormDecliningRateBp = '';

    public string $categoryFormProrataConvention = '';

    public string $categoryFormAssetAccountId = '';

    public string $categoryFormAccumulatedDepreciationAccountId = '';

    public string $categoryFormDisposalNbvAccountId = '';

    public string $categoryFormDisposalProceedsAccountId = '';

    /**
     * The assets ticked for a bulk label sheet. Kept as a plain list on the
     * component rather than a "select all matching filter" flag: a stock-take
     * operator needs to see exactly which stickers they are about to print,
     * and "all 4 200 assets" is not a print job anyone meant to start.
     *
     * NOT a list: Livewire's checkbox binding removes entries in place, so an
     * operator who ticks three boxes and unticks the middle one hands this
     * property back with a hole at index 1. printLabelSheet re-indexes before
     * it hands the ids on.
     *
     * @var array<int, int>
     */
    public array $selectedAssetIds = [];

    public function mount(): void
    {
        Gate::authorize(AssetPermission::VIEW);
    }

    /**
     * Stream the A4 stock-take label sheet for the ticked assets. Goes
     * through PrintAssetLabel -> RenderDocument, so the sheet is print-logged
     * like every other PDF.
     */
    public function printLabelSheet(PrintAssetLabel $printAssetLabel): Response
    {
        Gate::authorize(AssetPermission::VIEW);

        try {
            $document = $printAssetLabel->sheet(array_values(array_map('intval', $this->selectedAssetIds)));
        } catch (DomainException $e) {
            $this->addError('selectedAssetIds', $e->getMessage());

            return response('', 204);
        }

        return response()->streamDownload(
            static function () use ($document): void {
                echo $document->bytes;
            },
            'asset-labels-'.now()->format('Ymd-His').'.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }

    public function selectTab(string $tab): void
    {
        $this->tab = in_array($tab, ['assets', 'maintenance', 'depreciation'], true)
            ? $tab
            : 'assets';
        $this->status = '';
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['category', 'status', 'search']);
        $this->resetPage();
    }

    public function updatedCategory(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    private function resetPage(): void
    {
        $this->page = 1;
    }

    public function toggleAssetForm(): void
    {
        Gate::authorize(AssetPermission::MANAGE);

        $this->showAssetForm = ! $this->showAssetForm;

        if ($this->showAssetForm && $this->assetFormAcquisitionDate === '') {
            $this->assetFormAcquisitionDate = Carbon::now()->format('Y-m-d');
        }
    }

    public function toggleMaintenanceForm(): void
    {
        Gate::authorize(AssetPermission::MANAGE);

        $this->showMaintenanceForm = ! $this->showMaintenanceForm;
    }

    public function saveAsset(RegisterAsset $registerAsset): void
    {
        Gate::authorize(AssetPermission::MANAGE);

        $fiscalYearId = DB::table('fiscal_years')->where('status', 'open')->value('id');
        $academicYearId = DB::table('academic_years')->where('is_current', true)->value('id');

        if ($fiscalYearId === null || $academicYearId === null) {
            $this->addError('assetFormName', 'No open fiscal year or current academic year is configured.');

            return;
        }

        try {
            $registerAsset->handle([
                'tag_number' => $this->assetFormTagNumber,
                'name' => $this->assetFormName,
                'asset_category_id' => (int) $this->assetFormCategoryId,
                'serial_number' => $this->assetFormSerialNumber === '' ? null : $this->assetFormSerialNumber,
                'acquisition_type' => $this->assetFormAcquisitionType,
                'acquisition_date' => $this->assetFormAcquisitionDate,
                'acquisition_cost' => $this->assetFormAcquisitionCost === '' ? 0 : (int) $this->assetFormAcquisitionCost,
                'notes' => $this->assetFormNotes === '' ? null : $this->assetFormNotes,
                'fiscal_year_id' => $fiscalYearId,
                'academic_year_id' => $academicYearId,
            ], $this->actor());
        } catch (ValidationException $e) {
            $this->addError('assetFormName', $e->getMessage());

            return;
        } catch (DomainException $e) {
            $this->addError('assetFormCategoryId', $e->getMessage());

            return;
        }

        $this->reset([
            'showAssetForm', 'assetFormTagNumber', 'assetFormName', 'assetFormCategoryId',
            'assetFormSerialNumber', 'assetFormAcquisitionType', 'assetFormAcquisitionDate',
            'assetFormAcquisitionCost', 'assetFormNotes',
        ]);
        $this->tab = 'assets';
        $this->resetPage();
        session()->flash('status', 'Asset registered in the register.');
    }

    public function saveMaintenanceRequest(CreateMaintenanceRequest $createMaintenanceRequest): void
    {
        Gate::authorize(AssetPermission::MANAGE);

        try {
            $createMaintenanceRequest->handle([
                'asset_id' => $this->maintenanceFormAssetId === '' ? null : (int) $this->maintenanceFormAssetId,
                'title' => $this->maintenanceFormTitle,
                'description' => $this->maintenanceFormDescription === '' ? null : $this->maintenanceFormDescription,
                'priority' => $this->maintenanceFormPriority,
            ], $this->actor());
        } catch (ValidationException $e) {
            $this->addError('maintenanceFormTitle', $e->getMessage());

            return;
        } catch (DomainException $e) {
            $this->addError('maintenanceFormAssetId', $e->getMessage());

            return;
        }

        $this->reset([
            'showMaintenanceForm', 'maintenanceFormAssetId', 'maintenanceFormTitle',
            'maintenanceFormPriority', 'maintenanceFormDescription',
        ]);
        $this->tab = 'maintenance';
        $this->resetPage();
        session()->flash('status', 'Maintenance request opened.');
    }

    // ── Commission asset (row action) ───────────────────────────────────

    public function commissionAsset(int $assetId, CommissionAsset $commissionAsset): void
    {
        Gate::authorize(AssetPermission::MANAGE);

        try {
            $commissionAsset->handle($assetId, Carbon::now()->format('Y-m-d'), $this->actor());
        } catch (ValidationException|DomainException $e) {
            $this->addError('commissionAsset', $e->getMessage());

            return;
        }

        session()->flash('status', 'Asset commissioned into service.');
    }

    // ── Dispose asset ────────────────────────────────────────────────────

    public function toggleDisposeForm(?int $assetId = null, string $assetLabel = ''): void
    {
        Gate::authorize(AssetPermission::DISPOSE);

        $this->showDisposeForm = ! $this->showDisposeForm || $this->disposeAssetId !== $assetId;
        $this->disposeAssetId = $this->showDisposeForm ? $assetId : null;
        $this->disposeAssetLabel = $this->showDisposeForm ? $assetLabel : '';

        if ($this->showDisposeForm && $this->disposeDate === '') {
            $this->disposeDate = Carbon::now()->format('Y-m-d');
        }
    }

    public function saveDisposeAsset(DisposeAsset $disposeAsset): void
    {
        Gate::authorize(AssetPermission::DISPOSE);

        if ($this->disposeAssetId === null) {
            $this->addError('disposeReason', 'No asset selected for disposal.');

            return;
        }

        try {
            $disposeAsset->handle($this->disposeAssetId, [
                'disposal_type' => $this->disposeType,
                'disposal_date' => $this->disposeDate,
                'proceeds_amount' => $this->disposeProceeds === '' ? 0 : (int) $this->disposeProceeds,
                'buyer_partner_id' => $this->disposeBuyerPartnerId === '' ? null : (int) $this->disposeBuyerPartnerId,
                'settlement' => $this->disposeSettlement === '' ? null : $this->disposeSettlement,
                'reason' => $this->disposeReason,
            ], $this->actor());
        } catch (ValidationException $e) {
            $this->addError('disposeReason', $e->getMessage());

            return;
        } catch (DomainException $e) {
            $this->addError('disposeReason', $e->getMessage());

            return;
        }

        $this->reset([
            'showDisposeForm', 'disposeAssetId', 'disposeAssetLabel', 'disposeType', 'disposeDate',
            'disposeProceeds', 'disposeBuyerPartnerId', 'disposeSettlement', 'disposeReason',
        ]);
        $this->tab = 'assets';
        $this->resetPage();
        session()->flash('status', 'Asset disposed.');
    }

    // ── Run depreciation ─────────────────────────────────────────────────

    public function toggleRunDepreciationForm(): void
    {
        Gate::authorize(AssetPermission::DEPRECIATE);

        $this->showRunDepreciationForm = ! $this->showRunDepreciationForm;
    }

    public function saveRunDepreciation(RunDepreciation $runDepreciation): void
    {
        Gate::authorize(AssetPermission::DEPRECIATE);

        $fiscalYearId = $this->runFiscalYearId === '' ? null : (int) $this->runFiscalYearId;

        if ($fiscalYearId === null) {
            $this->addError('runFiscalYearId', 'A fiscal year is required.');

            return;
        }

        try {
            $runDepreciation->handle($fiscalYearId, (int) $this->runPeriodMonth, $this->actor());
        } catch (ValidationException $e) {
            $this->addError('runPeriodMonth', $e->getMessage());

            return;
        } catch (DomainException $e) {
            $this->addError('runPeriodMonth', $e->getMessage());

            return;
        }

        $this->reset(['showRunDepreciationForm', 'runFiscalYearId', 'runPeriodMonth']);
        $this->tab = 'depreciation';
        $this->resetPage();
        session()->flash('status', 'Depreciation run calculated.');
    }

    // ── Approve / post depreciation run (row actions) ────────────────────

    public function approveDepreciationRun(int $runId, ApproveDepreciationRun $approveDepreciationRun): void
    {
        Gate::authorize(AssetPermission::DEPRECIATE);

        try {
            $approveDepreciationRun->handle($runId, $this->actor());
        } catch (DomainException $e) {
            $this->addError('depreciationRun', $e->getMessage());

            return;
        }

        session()->flash('status', 'Depreciation run approved.');
    }

    public function postDepreciationRun(int $runId, PostDepreciationRun $postDepreciationRun): void
    {
        Gate::authorize(AssetPermission::DEPRECIATE);

        try {
            $postDepreciationRun->handle($runId, $this->actor());
        } catch (DomainException $e) {
            $this->addError('depreciationRun', $e->getMessage());

            return;
        }

        session()->flash('status', 'Depreciation run posted.');
    }

    // ── Close maintenance request ────────────────────────────────────────

    public function toggleCloseMaintenanceForm(?int $requestId = null): void
    {
        Gate::authorize(AssetPermission::MANAGE);

        $this->showCloseMaintenanceForm = ! $this->showCloseMaintenanceForm || $this->closeMaintenanceRequestId !== $requestId;
        $this->closeMaintenanceRequestId = $this->showCloseMaintenanceForm ? $requestId : null;
    }

    public function saveCloseMaintenanceRequest(CloseMaintenanceRequest $closeMaintenanceRequest): void
    {
        Gate::authorize(AssetPermission::MANAGE);

        if ($this->closeMaintenanceRequestId === null) {
            $this->addError('closeMaintenanceJustification', 'No maintenance request selected.');

            return;
        }

        $resolution = MaintenanceResolution::from($this->closeMaintenanceResolution);

        try {
            $closeMaintenanceRequest->handle(
                $this->closeMaintenanceRequestId,
                $resolution,
                $this->closeMaintenanceJustification,
                $this->actor(),
                [
                    'actual_cost' => $this->closeMaintenanceActualCost === '' ? null : (int) $this->closeMaintenanceActualCost,
                    'capitalise_as' => $this->closeMaintenanceCapitaliseAs,
                ],
            );
        } catch (ValidationException $e) {
            $this->addError('closeMaintenanceJustification', $e->getMessage());

            return;
        } catch (DomainException $e) {
            $this->addError('closeMaintenanceJustification', $e->getMessage());

            return;
        }

        $this->reset([
            'showCloseMaintenanceForm', 'closeMaintenanceRequestId', 'closeMaintenanceResolution',
            'closeMaintenanceJustification', 'closeMaintenanceActualCost', 'closeMaintenanceCapitaliseAs',
        ]);
        $this->tab = 'maintenance';
        $this->resetPage();
        session()->flash('status', 'Maintenance request closed.');
    }

    // ── Create asset category ────────────────────────────────────────────

    public function toggleCategoryForm(): void
    {
        Gate::authorize(AssetPermission::MANAGE);

        $this->showCategoryForm = ! $this->showCategoryForm;
    }

    public function saveCategory(CreateAssetCategory $createAssetCategory): void
    {
        Gate::authorize(AssetPermission::MANAGE);

        try {
            $createAssetCategory->handle(null, [
                'code' => $this->categoryFormCode,
                'name' => $this->categoryFormName,
                'name_fr' => $this->categoryFormNameFr,
                'depreciation_method' => $this->categoryFormDepreciationMethod,
                'useful_life_months' => $this->categoryFormUsefulLifeMonths === '' ? null : (int) $this->categoryFormUsefulLifeMonths,
                'declining_rate_bp' => $this->categoryFormDecliningRateBp === '' ? null : (int) $this->categoryFormDecliningRateBp,
                'prorata_convention' => $this->categoryFormProrataConvention === '' ? null : $this->categoryFormProrataConvention,
                'asset_account_id' => (int) $this->categoryFormAssetAccountId,
                'accumulated_depreciation_account_id' => (int) $this->categoryFormAccumulatedDepreciationAccountId,
                'disposal_nbv_account_id' => (int) $this->categoryFormDisposalNbvAccountId,
                'disposal_proceeds_account_id' => (int) $this->categoryFormDisposalProceedsAccountId,
            ], $this->actor());
        } catch (ValidationException $e) {
            $this->addError('categoryFormCode', $e->getMessage());

            return;
        } catch (DomainException $e) {
            $this->addError('categoryFormCode', $e->getMessage());

            return;
        }

        $this->reset([
            'showCategoryForm', 'categoryFormCode', 'categoryFormName', 'categoryFormNameFr',
            'categoryFormDepreciationMethod', 'categoryFormUsefulLifeMonths', 'categoryFormDecliningRateBp',
            'categoryFormProrataConvention', 'categoryFormAssetAccountId', 'categoryFormAccumulatedDepreciationAccountId',
            'categoryFormDisposalNbvAccountId', 'categoryFormDisposalProceedsAccountId',
        ]);
        session()->flash('status', 'Asset category created.');
    }

    private function actor(): \App\Support\Audit\Actor
    {
        /** @var \App\Modules\Identity\Models\User $user */
        $user = auth()->user();

        return $user->toAuditActor();
    }

    /**
     * @return list<array{id: int, tag_number: string, name: string}>
     */
    private function assetOptions(): array
    {
        $options = [];

        foreach (DB::table('assets')->orderBy('tag_number')->get(['id', 'tag_number', 'name']) as $row) {
            /** @var object{id: int|string, tag_number: string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'tag_number' => $row->tag_number, 'name' => $row->name];
        }

        return $options;
    }

    /**
     * @return list<array{id: int, code: string, name: string}>
     */
    private function accountOptions(): array
    {
        $options = [];

        foreach (DB::table('chart_of_accounts')->orderBy('code')->get(['id', 'code', 'name']) as $row) {
            /** @var object{id: int|string, code: string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'code' => $row->code, 'name' => $row->name];
        }

        return $options;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function fiscalYearOptions(): array
    {
        $options = [];

        foreach (DB::table('fiscal_years')->where('status', 'open')->orderByDesc('id')->get(['id', 'code']) as $row) {
            /** @var object{id: int|string, code: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => $row->code];
        }

        return $options;
    }

    /**
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function rows(): LengthAwarePaginator
    {
        return match ($this->tab) {
            'maintenance' => $this->maintenanceRows(),
            'depreciation' => $this->depreciationRows(),
            default => $this->assetRows(),
        };
    }

    /**
     * The asset register: tag, name, category, status, acquisition cost
     * and the latest accounting-basis net book value.
     *
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function assetRows(): LengthAwarePaginator
    {
        return DB::table('assets as a')
            ->join('asset_categories as c', 'c.id', '=', 'a.asset_category_id')
            ->when($this->category !== '', fn ($q) => $q->where('a.asset_category_id', (int) $this->category))
            ->when($this->status !== '', fn ($q) => $q->where('a.status', $this->status))
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('a.tag_number', 'like', '%'.$this->search.'%')
                        ->orWhere('a.name', 'like', '%'.$this->search.'%')
                        ->orWhere('a.serial_number', 'like', '%'.$this->search.'%');
                });
            })
            ->orderBy('a.tag_number')
            ->select([
                'a.id', 'a.tag_number', 'a.name', 'a.status', 'a.acquisition_cost',
                'c.name as category_name',
            ])
            ->selectSub(
                DB::table('depreciation_schedules')
                    ->whereColumn('asset_id', 'a.id')
                    ->where('basis', 'accounting')
                    ->orderByDesc('fiscal_year_id')
                    ->orderByDesc('period_month')
                    ->limit(1)
                    ->select('net_book_value'),
                'net_book_value'
            )
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * Open maintenance requests (not done/cancelled), with the asset tag
     * and reporting details.
     *
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function maintenanceRows(): LengthAwarePaginator
    {
        return DB::table('asset_maintenance_requests as m')
            ->leftJoin('assets as a', 'a.id', '=', 'm.asset_id')
            ->when($this->status !== '', fn ($q) => $q->where('m.status', $this->status))
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('m.title', 'like', '%'.$this->search.'%')
                        ->orWhere('a.tag_number', 'like', '%'.$this->search.'%');
                });
            })
            ->orderByDesc('m.reported_at')
            ->select([
                'm.id', 'm.title', 'm.priority', 'm.status', 'm.reported_at',
                'm.estimated_cost', 'a.tag_number', 'a.name as asset_name',
            ])
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * Recent depreciation runs with their status and totals.
     *
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function depreciationRows(): LengthAwarePaginator
    {
        return DB::table('depreciation_runs as r')
            ->join('fiscal_years as fy', 'fy.id', '=', 'r.fiscal_year_id')
            ->when($this->status !== '', fn ($q) => $q->where('r.status', $this->status))
            ->orderByDesc('r.fiscal_year_id')
            ->orderByDesc('r.period_month')
            ->select([
                'r.id', 'r.period_month', 'r.status', 'r.run_at', 'r.approved_at',
                // fiscal_years carries `code` (e.g. "2026"), never a `name`
                // column - selecting fy.name made this whole tab a SQL error.
                'r.assets_processed', 'r.total_charge', 'fy.code as fiscal_year_name',
            ])
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * Dataset-wide KPI cards: total assets, net book value total, assets
     * under maintenance, depreciation runs pending approval.
     *
     * @return array{total_assets: int, net_book_value: int, under_maintenance: int, runs_pending: int}
     */
    private function kpis(): array
    {
        $latestNbvPerAsset = DB::table('depreciation_schedules as ds')
            ->select('ds.asset_id', 'ds.net_book_value')
            ->whereRaw('ds.id = (
                select ds2.id from depreciation_schedules ds2
                where ds2.asset_id = ds.asset_id and ds2.basis = "accounting"
                order by ds2.fiscal_year_id desc, ds2.period_month desc
                limit 1
            )')
            ->where('ds.basis', 'accounting');

        $netBookValue = (int) DB::query()
            ->fromSub($latestNbvPerAsset, 'nbv')
            ->sum('net_book_value');

        return [
            'total_assets' => (int) DB::table('assets')->count(),
            'net_book_value' => $netBookValue,
            'under_maintenance' => (int) DB::table('assets')
                ->where('status', AssetStatus::UnderMaintenance->value)
                ->count(),
            'runs_pending' => (int) DB::table('depreciation_runs')
                ->where('status', DepreciationRunStatus::Calculated->value)
                ->count(),
        ];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function categoryOptions(): array
    {
        $options = [];

        foreach (DB::table('asset_categories')->orderBy('name')->get(['id', 'name']) as $row) {
            /** @var object{id: int|string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => $row->name];
        }

        return $options;
    }

    /**
     * Per-tab status filter choices (the WORD carries the meaning, 09-ui 10).
     *
     * @return list<array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        return match ($this->tab) {
            'maintenance' => array_map(
                fn (MaintenanceStatus $case) => ['value' => $case->value, 'label' => ucfirst(str_replace('_', ' ', $case->value))],
                MaintenanceStatus::cases()
            ),
            'depreciation' => array_map(
                fn (DepreciationRunStatus $case) => ['value' => $case->value, 'label' => ucfirst(str_replace('_', ' ', $case->value))],
                DepreciationRunStatus::cases()
            ),
            default => array_map(
                fn (AssetStatus $case) => ['value' => $case->value, 'label' => ucfirst(str_replace('_', ' ', $case->value))],
                AssetStatus::cases()
            ),
        };
    }

    public function render(): mixed
    {
        $tabCounts = [
            'assets' => (int) DB::table('assets')->count(),
            'maintenance' => (int) DB::table('asset_maintenance_requests')
                ->whereNotIn('status', [MaintenanceStatus::Done->value, MaintenanceStatus::Cancelled->value])
                ->count(),
            'depreciation' => (int) DB::table('depreciation_runs')->count(),
        ];

        return view('livewire.assets.index', [
            'rows' => $this->rows(),
            'kpis' => $this->kpis(),
            'tabCounts' => $tabCounts,
            'categoryOptions' => $this->categoryOptions(),
            'statusOptions' => $this->statusOptions(),
            'assetOptions' => $this->assetOptions(),
            'accountOptions' => $this->accountOptions(),
            'fiscalYearOptions' => $this->fiscalYearOptions(),
            'acquisitionTypeOptions' => AcquisitionType::cases(),
            'maintenancePriorityOptions' => MaintenancePriority::cases(),
            'disposalTypeOptions' => DisposalType::cases(),
            'disposalSettlementOptions' => DisposalSettlement::cases(),
            'depreciationMethodOptions' => DepreciationMethod::cases(),
            'prorataConventionOptions' => ProrataConvention::cases(),
            'maintenanceResolutionOptions' => MaintenanceResolution::cases(),
        ]);
    }
}
