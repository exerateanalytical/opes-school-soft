<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Portal;

use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * `/portal/help` - Help & Support (`mobile/help-support.png`).
 *
 * The answers are written against what this product ACTUALLY does, which
 * matters more here than on any other screen. The commonest support call this
 * portal will generate is "why can't I see my child's fees", and the true
 * answer is that the school controls it per guardian, per child. A help page
 * that said "try refreshing" would send that parent round in circles and the
 * office would take the call anyway.
 */
#[Layout('layouts.portal')]
final class HelpSupport extends Component
{
    /** Which question is expanded. Null collapses all. */
    public ?int $open = 0;

    public function toggle(int $index): void
    {
        $this->open = $this->open === $index ? null : $index;
    }

    public function render(): mixed
    {
        return view('livewire.guardians.portal.help-support', [
            'faqs' => [
                ['q' => __('opes.guardian_portal.faq_scope_q'), 'a' => __('opes.guardian_portal.faq_scope_a')],
                ['q' => __('opes.guardian_portal.faq_payments_q'), 'a' => __('opes.guardian_portal.faq_payments_a')],
                ['q' => __('opes.guardian_portal.faq_download_q'), 'a' => __('opes.guardian_portal.faq_download_a')],
                ['q' => __('opes.guardian_portal.faq_pay_online_q'), 'a' => __('opes.guardian_portal.faq_pay_online_a')],
                ['q' => __('opes.guardian_portal.faq_phone_q'), 'a' => __('opes.guardian_portal.faq_phone_a')],
            ],
        ]);
    }
}
