{{-- The ledger's sub-nav, mounted on every ledger screen: the sidebar's
     Ledger item lands on the chart of accounts, and before this nav existed
     the journal register was reachable only from its own create screen, the
     trial balance only from /finance/dashboard, and the year-end console -
     "without this the ledger could never enter a second fiscal year" - from
     nowhere at all. One partial so the screens cannot drift. --}}
<x-module-subnav :items="[
    ['href' => '/ledger/chart-of-accounts', 'label' => __('opes.ledger.nav_chart'), 'permission' => 'ledger.view'],
    ['href' => '/ledger/journal-entries', 'label' => __('opes.ledger.nav_journals'), 'permission' => 'ledger.view'],
    ['href' => '/ledger/trial-balance', 'label' => __('opes.ledger.nav_trial_balance'), 'permission' => 'ledger.view'],
    ['href' => '/accounting/year-end', 'label' => __('opes.ledger.nav_year_end'), 'permission' => 'ledger.view'],
]"/>
