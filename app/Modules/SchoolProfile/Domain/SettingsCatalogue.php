<?php

declare(strict_types=1);

namespace App\Modules\SchoolProfile\Domain;

/**
 * The /settings hub's card list: WHICH settings screens exist, what each one
 * is for, and which permission opens it.
 *
 * Pure metadata by design (DomainPurityTest forbids DB access here): the hub
 * component computes the per-card "current state" summaries itself, because
 * those are reads across five modules and belong in the component, not in a
 * value list every test would then have to boot a database for.
 *
 * `permission` is the SAME string the route's `can:` middleware carries, so
 * a card can never offer a link its holder would be refused at - the nav
 * contract in 00-core 6.2.
 */
final class SettingsCatalogue
{
    /**
     * @return list<array{key: string, route: string, permission: string, icon: string, title_key: string, description_key: string}>
     */
    public static function cards(): array
    {
        return [
            [
                'key' => 'school_identity',
                'route' => 'settings.school-identity',
                'permission' => 'setting.edit',
                'icon' => 'system_documentation',
                'title_key' => 'opes.settings_hub.school_identity_title',
                'description_key' => 'opes.settings_hub.school_identity_description',
            ],
            [
                'key' => 'branding',
                'route' => 'settings.branding',
                'permission' => 'setting.edit',
                'icon' => 'branding',
                'title_key' => 'opes.settings_hub.branding_title',
                'description_key' => 'opes.settings_hub.branding_description',
            ],
            [
                'key' => 'fiscal',
                'route' => 'tax.fiscal-identity',
                'permission' => 'ledger.configure',
                'icon' => 'fiscal_identity',
                'title_key' => 'opes.settings_hub.fiscal_title',
                'description_key' => 'opes.settings_hub.fiscal_description',
            ],
            [
                'key' => 'tax',
                'route' => 'tax.settings',
                'permission' => 'ledger.configure',
                'icon' => 'finance',
                'title_key' => 'opes.settings_hub.tax_title',
                'description_key' => 'opes.settings_hub.tax_description',
            ],
            [
                'key' => 'licence',
                'route' => 'settings.licence',
                'permission' => 'licence.manage',
                'icon' => 'licence',
                'title_key' => 'opes.settings_hub.licence_title',
                'description_key' => 'opes.settings_hub.licence_description',
            ],
            [
                'key' => 'academic',
                'route' => 'academics.settings',
                'permission' => 'academics.manage',
                'icon' => 'academics',
                'title_key' => 'opes.settings_hub.academic_title',
                'description_key' => 'opes.settings_hub.academic_description',
            ],
            [
                'key' => 'go_live',
                'route' => 'operations.setup',
                'permission' => 'setting.view',
                'icon' => 'setup',
                'title_key' => 'opes.settings_hub.go_live_title',
                'description_key' => 'opes.settings_hub.go_live_description',
            ],
            [
                'key' => 'advanced',
                'route' => 'settings.advanced',
                'permission' => 'setting.view',
                'icon' => 'settings',
                'title_key' => 'opes.settings_hub.advanced_title',
                'description_key' => 'opes.settings_hub.advanced_description',
            ],
        ];
    }
}
