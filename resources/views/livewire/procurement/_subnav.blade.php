{{-- The procure-to-pay chain's sub-nav, mounted on every screen in the
     module: the sidebar's Procurement item lands on /procurement/suppliers,
     and before this nav existed the other eight screens in the chain had
     zero inbound links. One partial so the eight screens cannot drift. --}}
<x-module-subnav :items="[
    ['href' => '/procurement/suppliers', 'label' => __('opes.procurement.nav_suppliers'), 'permission' => 'procurement.view'],
    ['href' => '/procurement/requisitions', 'label' => __('opes.procurement.nav_requisitions'), 'permission' => 'procurement.view'],
    ['href' => '/procurement/orders', 'label' => __('opes.procurement.nav_orders'), 'permission' => 'procurement.view'],
    ['href' => '/procurement/receipts', 'label' => __('opes.procurement.nav_receipts'), 'permission' => 'procurement.view'],
    ['href' => '/procurement/invoices', 'label' => __('opes.procurement.nav_invoices'), 'permission' => 'procurement.invoice_view'],
    ['href' => '/procurement/payments', 'label' => __('opes.procurement.nav_payments'), 'permission' => 'procurement.payment_record'],
    ['href' => '/procurement/payables', 'label' => __('opes.procurement.nav_payables'), 'permission' => 'procurement.view'],
]"/>
