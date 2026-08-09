<?php

declare(strict_types=1);

return [
    'breadcrumb_dashboard' => 'Dashboard',
    'breadcrumb_discipline' => 'Discipline',
    'title' => 'Discipline Management',
    'tabs_label' => 'Case status',
    'tab_all' => 'All',

    'kpi_total' => 'Total Cases',
    'kpi_open' => 'Open Cases',
    'kpi_investigating' => 'Under Investigation',
    'kpi_positive' => 'Positive Entries',
    'kpi_unacknowledged' => 'Awaiting Guardian Signature',

    'open_case' => 'Open Case',
    'save_case' => 'Save Case',
    'view_case' => 'View case',
    'case_title' => 'Discipline Case',
    'positive_entry_title' => 'Positive Behaviour Entry',
    'case_ref' => 'Case #:id',
    'incident_title' => 'Incident',

    'search_label' => 'Search cases',
    'search_placeholder' => 'Search student name or matricule…',
    'student_search_placeholder' => 'Type a name, matricule or admission no…',
    'all_categories' => 'All categories',
    'select_category' => 'Select a category…',
    'empty' => 'No discipline cases recorded.',

    'field_student' => 'Student',
    'field_category' => 'Offence category',
    'field_occurred_on' => 'Date of incident',
    'field_description' => 'Description',
    'field_visibility' => 'Guardian visibility',
    'field_is_positive' => 'This is a positive behaviour entry',
    'field_sanction_type' => 'Sanction',
    'field_starts_on' => 'Starts on',
    'field_ends_on' => 'Ends on',
    'field_notes' => 'Notes',
    'field_outcome' => 'Outcome',
    'field_resolution_note' => 'Resolution note',

    'col_date' => 'Date',
    'col_student' => 'Student',
    'col_class' => 'Class',
    'col_category' => 'Category',
    'col_kind' => 'Entry',
    'col_status' => 'Status',
    'severity' => 'Severity',
    'kind_positive' => 'Positive',
    'kind_incident' => 'Incident',

    'status' => [
        'open' => 'Open',
        'under_investigation' => 'Under investigation',
        'resolved' => 'Resolved',
        'dismissed' => 'Dismissed',
    ],

    'visibility' => [
        'internal' => 'Internal only',
        'guardian' => 'Visible to guardian',
    ],
    'visibility_hint' => 'Cases involving another named minor stay internal.',
    'visibility_guardian_note' => 'The guardian can see this case\'s narrative on the portal.',
    'visibility_internal_note' => 'Guardians see only the date, category and outcome — never the narrative.',

    'sanction' => [
        'warning' => 'Warning',
        'detention' => 'Detention',
        'consigne' => 'Consigne',
        'suspension' => 'Suspension',
        'exclusion' => 'Exclusion',
        'community_service' => 'Community service',
        'guardian_summons' => 'Guardian summons',
    ],

    'apply_sanction' => 'Apply Sanction',
    'save_sanction' => 'Save Sanction',
    'sanctions_title' => 'Sanctions',
    'no_sanctions' => 'No sanction has been applied to this case.',
    'unknown_sanction_type' => 'Unknown sanction type.',
    'sanction_not_on_case' => 'That sanction does not belong to this case.',
    'ladder_suggestion' => 'Sanction ladder suggests: :type.',
    'ladder_advisory' => 'Advisory only — the decision is yours.',

    'acknowledgement' => 'Guardian acknowledgement',
    'record_acknowledgement' => 'Record acknowledgement',
    'acknowledged_on' => 'Acknowledged :date',
    'unacknowledged' => 'Awaiting signature',

    'start_investigation' => 'Start Investigation',
    'close_case' => 'Close Case',
    'confirm_close' => 'Confirm',
    'dismiss_hint' => 'Dismissed cases never count against the student.',
    'lifecycle_title' => 'Lifecycle',
    'resolved_at' => 'Closed on',
    'reported_by' => 'Reported by',
];
