<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Payroll\Domain\ComponentCalculation;
use App\Modules\Payroll\Domain\PayrollFormula;
use App\Modules\Payroll\Domain\PayrollPermission;
use App\Modules\Payroll\Domain\StatutoryRateCode;
use App\Modules\Payroll\Models\PayrollComponent;
use App\Modules\Payroll\Models\PayrollComponentTest;
use App\Support\Audit\Actor;
use App\Support\Expression\ExpressionException;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a payroll component (docs/specs/05-hr-payroll.md 5.2,
 * 5.4). The H4 line is held HERE:
 *
 *  - `formula_expression` is parsed against the whitelisted grammar AT SAVE
 *    TIME; an unknown identifier, a disallowed function or a boolean
 *    operator rejects the save with the offending position. Never eval().
 *  - Stored unit tests (named input vector -> expected integer) are saved
 *    with the component and RE-EXECUTED; a formula component cannot be
 *    ENABLED without at least one and cannot be saved while any fails.
 *
 * System components (5.2): never deleted, `calculation_order` never edited
 * - the order IS the arithmetic (CAC after IRPP is not a preference).
 * `depends_on` entries must exist and run strictly earlier (7.2 rule 2's
 * cheap half; the full Kahn's-DAG validation lives with the run engine).
 *
 * @phpstan-type StoredTest array{name: string, inputs: array<string, int>, expected: int}
 */
final class SavePayrollComponent
{
    /**
     * @param  array<string, mixed>  $attributes  column => value; `code` fixed at creation
     * @param  list<StoredTest>  $tests  replaces the stored tests when non-empty
     */
    public function handle(
        string $code,
        array $attributes,
        array $tests = [],
        ?Actor $actor = null,
    ): PayrollComponent {
        Gate::authorize(PayrollPermission::CONFIGURE);

        $actor ??= auth()->user()?->toAuditActor() ?? Actor::system();

        return DB::transaction(function () use ($code, $attributes, $tests, $actor): PayrollComponent {
            $component = PayrollComponent::query()
                ->where('code', $code)
                ->lockForUpdate()
                ->first();

            $before = $component?->only([
                'calculation', 'formula_expression', 'calculation_order', 'is_enabled',
            ]);

            if ($component === null) {
                $component = new PayrollComponent(['code' => $code, 'is_system' => false]);
            } elseif ($component->is_system) {
                // 5.2: the system set is the arithmetic. Its order is not
                // editable, its identity is not repurposable.
                foreach (['calculation_order', 'code', 'type', 'calculation', 'is_system'] as $frozen) {
                    if (! array_key_exists($frozen, $attributes)) {
                        continue;
                    }

                    $current = $component->getAttribute($frozen);
                    $current = $current instanceof \BackedEnum ? $current->value : $current;
                    $incoming = $attributes[$frozen];
                    $incoming = $incoming instanceof \BackedEnum ? $incoming->value : $incoming;

                    if ($incoming !== $current) {
                        throw new DomainException(
                            "System component {$code}: `{$frozen}` cannot be edited (05-hr-payroll 5.2)."
                        );
                    }
                }
            }

            unset($attributes['code'], $attributes['is_system']);
            $component->fill($attributes);

            $this->assertCalculationShape($component);
            $this->assertDependenciesRunEarlier($component);

            $formula = $this->parseFormula($component);

            if ($component->exists) {
                $component->version++;
            }

            $component->save();

            if ($tests !== []) {
                $this->replaceStoredTests($component, $tests);
            }

            $this->runStoredTests($component, $formula);

            app(WriteAuditEntry::class)->handle(
                action: $before === null ? AuditAction::Created : AuditAction::SettingChanged,
                module: 'Payroll',
                auditableType: PayrollComponent::class,
                auditableId: (int) $component->getKey(),
                before: $before,
                after: $component->only([
                    'code', 'calculation', 'formula_expression', 'calculation_order', 'is_enabled',
                ]),
                actor: $actor,
            );

            return $component;
        });
    }

    private function assertCalculationShape(PayrollComponent $component): void
    {
        if ($component->calculation === ComponentCalculation::Statutory) {
            if ($component->statutory_rate_code === null
                || StatutoryRateCode::tryFrom($component->statutory_rate_code) === null) {
                throw ValidationException::withMessages([
                    'statutory_rate_code' => 'A statutory component must name the statutory rate code it resolves.',
                ]);
            }
        }

        if ($component->calculation === ComponentCalculation::Formula && $component->formula_expression === null) {
            throw ValidationException::withMessages([
                'formula_expression' => 'A formula component must carry a formula.',
            ]);
        }
    }

    /**
     * 7.2 rule 2, the save-time half: every named dependency exists and has
     * a strictly LOWER calculation_order. (Cycle detection over the whole
     * graph - Kahn's algorithm - runs with the engine package; a same-or-
     * higher-order dependency is already structurally impossible to
     * evaluate, so it dies here.)
     */
    private function assertDependenciesRunEarlier(PayrollComponent $component): void
    {
        /** @var list<string> $dependsOn */
        $dependsOn = $component->depends_on ?? [];

        if ($dependsOn === []) {
            return;
        }

        $orders = PayrollComponent::query()
            ->whereIn('code', $dependsOn)
            ->pluck('calculation_order', 'code');

        foreach ($dependsOn as $dependency) {
            $order = $orders->get($dependency);

            if ($order === null) {
                throw ValidationException::withMessages([
                    'depends_on' => "Unknown component `{$dependency}` in depends_on.",
                ]);
            }

            if ((int) $order >= $component->calculation_order) {
                throw ValidationException::withMessages([
                    'depends_on' => "`{$component->code}` cannot depend on `{$dependency}`:"
                        .' a component may not depend on one with a higher or equal calculation order (05-hr-payroll 7.2).',
                ]);
            }
        }
    }

    /**
     * Parse-at-save (H4). Component codes are legal variables (5.4
     * `<component_code>`), so the current catalogue is handed to the
     * whitelist.
     */
    private function parseFormula(PayrollComponent $component): ?PayrollFormula
    {
        if ($component->calculation !== ComponentCalculation::Formula || $component->formula_expression === null) {
            return null;
        }

        /** @var list<string> $codes */
        $codes = PayrollComponent::query()->pluck('code')->all();

        try {
            return PayrollFormula::parse($component->formula_expression, $codes);
        } catch (ExpressionException $exception) {
            throw ValidationException::withMessages([
                'formula_expression' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  list<array{name: string, inputs: array<string, int>, expected: int}>  $tests
     */
    private function replaceStoredTests(PayrollComponent $component, array $tests): void
    {
        PayrollComponentTest::query()
            ->where('payroll_component_id', $component->getKey())
            ->delete();

        foreach ($tests as $test) {
            PayrollComponentTest::query()->create([
                'payroll_component_id' => $component->getKey(),
                'name' => $test['name'],
                'inputs' => $test['inputs'],
                'expected' => $test['expected'],
            ]);
        }
    }

    /**
     * 5.4: stored tests re-execute at save; a failure rejects the save, and
     * a formula component cannot be enabled with none stored.
     */
    private function runStoredTests(PayrollComponent $component, ?PayrollFormula $formula): void
    {
        if ($formula === null) {
            return;
        }

        /** @var list<PayrollComponentTest> $stored */
        $stored = PayrollComponentTest::query()
            ->where('payroll_component_id', $component->getKey())
            ->get()
            ->all();

        if ($stored === [] && $component->is_enabled) {
            throw ValidationException::withMessages([
                'tests' => "Formula component `{$component->code}` cannot be enabled without at least one stored unit test (05-hr-payroll 5.4).",
            ]);
        }

        foreach ($stored as $test) {
            try {
                $actual = $formula->evaluate($test->inputs);
            } catch (ExpressionException $exception) {
                throw ValidationException::withMessages([
                    'tests' => "Stored test `{$test->name}` failed to evaluate: {$exception->getMessage()}",
                ]);
            }

            if ($actual !== $test->expected) {
                throw ValidationException::withMessages([
                    'tests' => "Stored test `{$test->name}` expected {$test->expected}, got {$actual}.",
                ]);
            }
        }
    }
}
