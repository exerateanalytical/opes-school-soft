<?php

declare(strict_types=1);

namespace App\Modules\Communication\Livewire;

use App\Modules\Communication\Actions\SendWhatsAppMessage;
use App\Modules\Communication\Models\WhatsAppDeliveryLog;
use App\Modules\Communication\Support\WhatsApp\WhatsAppConfig;
use App\Modules\Communication\Support\WhatsApp\WhatsAppNotConfiguredException;
use App\Modules\Identity\Domain\Permission;
use App\Modules\SchoolProfile\Actions\ReadSetting;
use App\Modules\SchoolProfile\Actions\WriteSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

/**
 * /settings/whatsapp - where a principal wires the school's own Meta account
 * up to the platform.
 *
 * Three jobs, in the order an operator meets them:
 *
 *  1. say plainly whether the channel is live or not, and if not, WHY - the
 *     one sentence WhatsAppConfig::missingReason() produces, so the screen
 *     and the exception in the log never word the same problem differently;
 *  2. take the credentials;
 *  3. show recent delivery attempts, because "did the parent get it?" is the
 *     question this screen actually gets opened for.
 *
 * The access token is never rendered back. It is write-only from the UI's
 * point of view: the field shows a masked placeholder and saving it blank
 * means "leave it alone", which is the only way to let somebody change the
 * phone number id without either retyping a 200-character token or having it
 * sitting in the page source of a screen that gets shown on a projector.
 */
#[Layout('layouts.app')]
final class WhatsAppSettings extends Component
{
    /** Blank means "unchanged" - see the class docblock. */
    public string $accessToken = '';

    public string $phoneNumberId = '';

    public string $businessAccountId = '';

    public string $defaultTemplate = '';

    public string $defaultTemplateLanguage = 'en';

    public bool $enabled = false;

    /** The number a test message goes to; never persisted. */
    public string $testRecipient = '';

    public bool $tokenIsSet = false;

    public function mount(ReadSetting $readSetting): void
    {
        // Same permission as the route's `can:setting.edit`, so nav, route
        // and component agree by construction. SettingEdit rather than a new
        // permission string: this IS a setting, and inventing
        // `whatsapp.configure` would create a permission no seeded role
        // grants - a screen nobody can reach.
        Gate::authorize(Permission::SettingEdit->value);

        $this->hydrate($readSetting);
    }

    private function hydrate(ReadSetting $readSetting): void
    {
        $this->enabled = (bool) $readSetting->handle(WhatsAppConfig::SETTING_ENABLED, false);
        $this->phoneNumberId = (string) $readSetting->handle(WhatsAppConfig::SETTING_PHONE_NUMBER_ID, '');
        $this->businessAccountId = (string) $readSetting->handle(WhatsAppConfig::SETTING_BUSINESS_ACCOUNT_ID, '');
        $this->defaultTemplate = (string) $readSetting->handle(WhatsAppConfig::SETTING_DEFAULT_TEMPLATE, '');
        $this->defaultTemplateLanguage = (string) $readSetting->handle(
            WhatsAppConfig::SETTING_DEFAULT_TEMPLATE_LANGUAGE,
            'en',
        ) ?: 'en';

        // Whether a token exists, never the token itself.
        $this->tokenIsSet = trim((string) $readSetting->handle(WhatsAppConfig::SETTING_ACCESS_TOKEN, '')) !== '';
        $this->accessToken = '';
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            // Mirrors the seeded validation_rule on each setting, so the
            // operator gets the message under the field instead of a raw
            // ValidationException out of WriteSetting.
            'accessToken' => ['nullable', 'string', 'max:500'],
            'phoneNumberId' => ['nullable', 'string', 'max:32', 'regex:/^[0-9]*$/'],
            'businessAccountId' => ['nullable', 'string', 'max:32', 'regex:/^[0-9]*$/'],
            'defaultTemplate' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9_]*$/'],
            'defaultTemplateLanguage' => ['nullable', 'string', 'max:16', 'regex:/^[a-zA-Z_]*$/'],
            'testRecipient' => ['nullable', 'string', 'max:24'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'phoneNumberId.regex' => (string) __('opes.whatsapp.error_numeric_id'),
            'businessAccountId.regex' => (string) __('opes.whatsapp.error_numeric_id'),
            'defaultTemplate.regex' => (string) __('opes.whatsapp.error_template_name'),
            'defaultTemplateLanguage.regex' => (string) __('opes.whatsapp.error_language'),
        ];
    }

    public function save(WriteSetting $writeSetting, ReadSetting $readSetting): void
    {
        Gate::authorize(Permission::SettingEdit->value);

        $this->resetErrorBag();
        $this->validate();

        // Turning the channel ON without the two values a send needs would
        // produce a screen claiming "connected" over a channel that refuses
        // every message. Caught here so the operator is told at the moment
        // they made the choice.
        $tokenAfterSave = trim($this->accessToken) !== '' || $this->tokenIsSet;

        if ($this->enabled && (! $tokenAfterSave || trim($this->phoneNumberId) === '')) {
            $this->addError('enabled', (string) __('opes.whatsapp.error_enable_incomplete'));

            return;
        }

        /** @var \App\Modules\Identity\Models\User $user */
        $user = auth()->user();
        $actor = $user->toAuditActor();

        try {
            // One operator intent, one transaction: the platform must never
            // be left enabled against half-written credentials.
            DB::transaction(function () use ($writeSetting, $actor): void {
                $writeSetting->handle(WhatsAppConfig::SETTING_PHONE_NUMBER_ID, trim($this->phoneNumberId), $actor);
                $writeSetting->handle(WhatsAppConfig::SETTING_BUSINESS_ACCOUNT_ID, trim($this->businessAccountId), $actor);
                $writeSetting->handle(WhatsAppConfig::SETTING_DEFAULT_TEMPLATE, trim($this->defaultTemplate), $actor);
                $writeSetting->handle(
                    WhatsAppConfig::SETTING_DEFAULT_TEMPLATE_LANGUAGE,
                    trim($this->defaultTemplateLanguage) ?: 'en',
                    $actor,
                );

                // Blank means unchanged, so an operator editing the phone
                // number id does not have to re-paste the token.
                if (trim($this->accessToken) !== '') {
                    $writeSetting->handle(WhatsAppConfig::SETTING_ACCESS_TOKEN, trim($this->accessToken), $actor);
                }

                $writeSetting->handle(WhatsAppConfig::SETTING_ENABLED, $this->enabled, $actor);
            });
        } catch (RuntimeException $e) {
            $this->addError('accessToken', $e->getMessage());

            return;
        }

        $this->hydrate($readSetting);
        $this->dispatch('settings-saved');
    }

    /**
     * Clears the stored token. Separate from save() because "revoke the key
     * that leaked" is an urgent, deliberate act and must not require reading
     * a form to work out which field to blank.
     */
    public function clearToken(WriteSetting $writeSetting, ReadSetting $readSetting): void
    {
        Gate::authorize(Permission::SettingEdit->value);

        /** @var \App\Modules\Identity\Models\User $user */
        $user = auth()->user();
        $actor = $user->toAuditActor();

        DB::transaction(function () use ($writeSetting, $actor): void {
            $writeSetting->handle(WhatsAppConfig::SETTING_ACCESS_TOKEN, '', $actor);
            // A channel with no token cannot send, so leaving it "on" would
            // misreport the instance as connected.
            $writeSetting->handle(WhatsAppConfig::SETTING_ENABLED, false, $actor);
        });

        $this->hydrate($readSetting);
        $this->dispatch('settings-saved');
    }

    /**
     * Sends one real message so the operator finds out here, on the screen
     * they are already looking at, rather than from a parent tomorrow.
     *
     * Deliberately NOT queued: the whole value is the answer coming back.
     */
    public function sendTest(SendWhatsAppMessage $send, WhatsAppConfig $config): void
    {
        Gate::authorize(Permission::SettingEdit->value);

        $this->resetErrorBag();

        if (trim($this->testRecipient) === '') {
            $this->addError('testRecipient', (string) __('opes.whatsapp.error_test_recipient'));

            return;
        }

        try {
            // Template if the school has one configured, because a brand-new
            // number is by definition outside the 24h service window and a
            // plain-text test would fail for policy reasons that have nothing
            // to do with whether the credentials work.
            $template = $config->defaultTemplate();

            $log = $template !== null
                ? $send->template(to: $this->testRecipient, templateName: $template)
                : $send->text(to: $this->testRecipient, body: (string) __('opes.whatsapp.test_body'));
        } catch (WhatsAppNotConfiguredException $e) {
            $this->addError('testRecipient', $e->getMessage());

            return;
        }

        if ($log->error_message !== null) {
            $this->addError('testRecipient', $log->error_message);

            return;
        }

        $this->dispatch('settings-saved');
    }

    /**
     * The most recent attempts, newest first.
     *
     * The guardian NAME is resolved with DB::table rather than Guardians'
     * model (00-core 6.2, enforced by ModuleBoundaryTest) - the log stores an
     * id and this screen needs a label.
     *
     * @return list<array{log: WhatsAppDeliveryLog, guardian: string|null}>
     */
    public function recentDeliveries(): array
    {
        /** @var \Illuminate\Support\Collection<int, WhatsAppDeliveryLog> $logs */
        $logs = WhatsAppDeliveryLog::query()
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        $guardianIds = $logs->pluck('guardian_id')->filter()->unique()->values()->all();

        $names = [];

        if ($guardianIds !== []) {
            // first_name/last_name, not a full_name column - guardians has
            // none, and inventing one in a query would just 500 this screen.
            foreach (DB::table('guardians')->whereIn('id', $guardianIds)->get(['id', 'first_name', 'last_name']) as $row) {
                $names[(int) $row->id] = trim($row->first_name.' '.$row->last_name);
            }
        }

        // Built with a foreach rather than ->map()->all(): the rows are a
        // LIST for the view to iterate, and a Collection cannot prove that
        // to the analyser.
        $rows = [];

        foreach ($logs as $log) {
            $rows[] = [
                'log' => $log,
                'guardian' => $log->guardian_id === null
                    ? null
                    : ($names[$log->guardian_id] ?? null),
            ];
        }

        return $rows;
    }

    public function render(): mixed
    {
        // Built fresh rather than injected into mount(), so the banner
        // reflects what was just saved instead of what was loaded.
        $config = app(WhatsAppConfig::class);

        return view('livewire.communication.whatsapp-settings', [
            'isConfigured' => $config->isConfigured(),
            'missingReason' => $config->missingReason(),
            'endpointVersion' => $config->apiVersion(),
            'deliveries' => $this->recentDeliveries(),
        ]);
    }
}
