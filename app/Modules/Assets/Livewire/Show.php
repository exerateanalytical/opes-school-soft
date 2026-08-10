<?php

declare(strict_types=1);

namespace App\Modules\Assets\Livewire;

use App\Modules\Assets\Actions\CloseMaintenanceRequest;
use App\Modules\Assets\Actions\CommissionAsset;
use App\Modules\Assets\Actions\DisposeAsset;
use App\Modules\Assets\Domain\AssetPermission;
use App\Modules\Assets\Domain\DisposalSettlement;
use App\Modules\Assets\Domain\DisposalType;
use App\Modules\Assets\Domain\MaintenanceResolution;
use App\Modules\Assets\Models\Asset;
use App\Modules\Reporting\Support\PdfExport;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response;

/**
 * Asset detail page at /assets/{asset}, gated `asset.view`, modeled on
 * Students\Livewire\Students\Show: header summary, sectioned detail
 * (acquisition, depreciation history, maintenance history, custody
 * movements), row actions reusing the SAME Actions wired on Index.php
 * (Commission, Dispose, Close maintenance request), plus a printable
 * asset-card preview.
 *
 * Same-module DB::table reads only (ModuleBoundaryTest); the Asset model
 * itself is the one Eloquent read (own module, so allowed), everything else
 * is a bounded query builder read - no unbounded collection reaches the view
 * (00-core 6.2 rule 8).
 */
#[Layout('layouts.app')]
final class Show extends Component
{
    /** Cap per history list. */
    private const int HISTORY_LIMIT = 100;

    public Asset $asset;

    // ── Dispose asset form ──────────────────────────────────────────────
    public bool $showDisposeForm = false;

    public string $disposeType = 'scrap';

    public string $disposeDate = '';

    public string $disposeProceeds = '';

    public string $disposeBuyerPartnerId = '';

    public string $disposeSettlement = '';

    public string $disposeReason = '';

    // ── Close maintenance request form (toggled per row) ────────────────
    public bool $showCloseMaintenanceForm = false;

    public ?int $closeMaintenanceRequestId = null;

    public string $closeMaintenanceResolution = 'expense';

    public string $closeMaintenanceJustification = '';

    public string $closeMaintenanceActualCost = '';

    public string $closeMaintenanceCapitaliseAs = 'increase_cost';

    public function mount(Asset $asset): void
    {
        Gate::authorize(AssetPermission::VIEW);

        $this->asset = $asset;
    }

    private function actor(): \App\Support\Audit\Actor
    {
        /** @var \App\Modules\Identity\Models\User $user */
        $user = auth()->user();

        return $user->toAuditActor();
    }

    // ── Commission asset (row action) ───────────────────────────────────

    public function commissionAsset(CommissionAsset $commissionAsset): void
    {
        Gate::authorize(AssetPermission::MANAGE);

        try {
            $commissionAsset->handle((int) $this->asset->getKey(), Carbon::now()->format('Y-m-d'), $this->actor());
        } catch (ValidationException|DomainException $e) {
            $this->addError('commissionAsset', $e->getMessage());

            return;
        }

        $this->asset->refresh();
        session()->flash('status', 'Asset commissioned into service.');
    }

    // ── Dispose asset ────────────────────────────────────────────────────

    public function toggleDisposeForm(): void
    {
        Gate::authorize(AssetPermission::DISPOSE);

        $this->showDisposeForm = ! $this->showDisposeForm;

        if ($this->showDisposeForm && $this->disposeDate === '') {
            $this->disposeDate = Carbon::now()->format('Y-m-d');
        }
    }

    public function saveDisposeAsset(DisposeAsset $disposeAsset): void
    {
        Gate::authorize(AssetPermission::DISPOSE);

        try {
            $disposeAsset->handle((int) $this->asset->getKey(), [
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

        $this->asset->refresh();
        $this->reset([
            'showDisposeForm', 'disposeType', 'disposeDate',
            'disposeProceeds', 'disposeBuyerPartnerId', 'disposeSettlement', 'disposeReason',
        ]);
        session()->flash('status', 'Asset disposed.');
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
        session()->flash('status', 'Maintenance request closed.');
    }

    // ── Export ────────────────────────────────────────────────────────────

    public function exportAssetCardPdf(): Response
    {
        Gate::authorize(AssetPermission::VIEW);

        return PdfExport::download(
            'Asset Card — '.$this->asset->tag_number,
            ['Field', 'Value'],
            $this->assetCardRows(),
            'asset-card-'.$this->asset->tag_number.'.pdf',
        );
    }

    /**
     * @return iterable<int, list<mixed>>
     */
    private function assetCardRows(): iterable
    {
        yield ['Tag Number', $this->asset->tag_number];
        yield ['Name', $this->asset->name];
        yield ['Category', $this->categoryName()];
        yield ['Acquisition Date', $this->asset->acquisition_date];
        yield ['Acquisition Cost', (string) $this->asset->acquisition_cost];
        yield ['Status', ucfirst(str_replace('_', ' ', $this->asset->status->value))];
    }

    // ── Read model ────────────────────────────────────────────────────────

    private function categoryName(): string
    {
        $name = DB::table('asset_categories')->where('id', $this->asset->asset_category_id)->value('name');

        return is_string($name) ? $name : '—';
    }

    private function supplierName(): ?string
    {
        if ($this->asset->supplier_id === null) {
            return null;
        }

        $name = DB::table('suppliers')->where('id', $this->asset->supplier_id)->value('name');

        return is_string($name) ? $name : null;
    }

    /**
     * Latest net book value from the accounting-basis schedule, mirroring
     * Index.php's selectSub.
     */
    private function latestNetBookValue(): ?int
    {
        $value = DB::table('depreciation_schedules')
            ->where('asset_id', $this->asset->getKey())
            ->where('basis', 'accounting')
            ->orderByDesc('fiscal_year_id')
            ->orderByDesc('period_month')
            ->value('net_book_value');

        return $value !== null ? (int) $value : null;
    }

    /**
     * @return \Illuminate\Support\Collection<int, \stdClass>
     */
    private function depreciationHistory(): \Illuminate\Support\Collection
    {
        return DB::table('depreciation_schedules as ds')
            ->join('fiscal_years as fy', 'fy.id', '=', 'ds.fiscal_year_id')
            ->where('ds.asset_id', $this->asset->getKey())
            ->where('ds.basis', 'accounting')
            ->orderByDesc('ds.fiscal_year_id')
            ->orderByDesc('ds.period_month')
            ->limit(self::HISTORY_LIMIT)
            ->select([
                'ds.id', 'fy.code as fiscal_year_code', 'ds.period_month',
                'ds.charge', 'ds.closing_accumulated', 'ds.net_book_value',
            ])
            ->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, \stdClass>
     */
    private function maintenanceHistory(): \Illuminate\Support\Collection
    {
        return DB::table('asset_maintenance_requests')
            ->where('asset_id', $this->asset->getKey())
            ->orderByDesc('reported_at')
            ->limit(self::HISTORY_LIMIT)
            ->select(['id', 'title', 'priority', 'status', 'reported_at', 'estimated_cost', 'actual_cost', 'resolution'])
            ->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, \stdClass>
     */
    private function custodyMovements(): \Illuminate\Support\Collection
    {
        return DB::table('asset_custody_movements as m')
            ->leftJoin('staff_members as fs', 'fs.id', '=', 'm.from_staff_id')
            ->leftJoin('staff_members as ts', 'ts.id', '=', 'm.to_staff_id')
            ->where('m.asset_id', $this->asset->getKey())
            ->orderByDesc('m.moved_on')
            ->orderByDesc('m.id')
            ->limit(self::HISTORY_LIMIT)
            ->select([
                'm.id', 'm.moved_on', 'm.reason', 'm.document_ref', 'm.acknowledged_at',
                'fs.first_name as from_first_name', 'fs.last_name as from_last_name',
                'ts.first_name as to_first_name', 'ts.last_name as to_last_name',
            ])
            ->get();
    }

    public function canManage(): bool
    {
        return Gate::allows(AssetPermission::MANAGE);
    }

    public function canDispose(): bool
    {
        return Gate::allows(AssetPermission::DISPOSE);
    }

    public function render(): mixed
    {
        return view('livewire.assets.show', [
            'categoryName' => $this->categoryName(),
            'supplierName' => $this->supplierName(),
            'netBookValue' => $this->latestNetBookValue(),
            'depreciationHistory' => $this->depreciationHistory(),
            'maintenanceHistory' => $this->maintenanceHistory(),
            'custodyMovements' => $this->custodyMovements(),
            'canManage' => $this->canManage(),
            'canDispose' => $this->canDispose(),
            'disposalTypeOptions' => DisposalType::cases(),
            'disposalSettlementOptions' => DisposalSettlement::cases(),
            'maintenanceResolutionOptions' => MaintenanceResolution::cases(),
        ]);
    }
}
