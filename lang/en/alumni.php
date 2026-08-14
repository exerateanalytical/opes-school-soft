<?php

declare(strict_types=1);

// The Alumni module's screen strings (gap #3, 2026-08-12 gap analysis).
// Keep lang/fr/alumni.php structurally identical.

return [
    'title' => 'Alumni',
    'breadcrumb_dashboard' => 'Dashboard',
    'detail_title' => 'Alumnus File',

    'kpi_total' => 'Total Alumni',
    'kpi_cohort' => "This Year's Cohort",
    'kpi_engagements' => 'Engagements This Year',
    'kpi_reachable' => 'Reachable',

    'filter_year' => 'Graduation year',
    'filter_occupation' => 'Occupation',
    'filter_search' => 'Search',
    'filter_search_placeholder' => 'Search name or matricule...',
    'all_years' => 'All years',

    'empty' => 'No alumni match these filters yet. Graduates appear here once they are converted from the student roll.',

    'col_name' => 'Name',
    'col_matricule' => 'Matricule',
    'col_year' => 'Year',
    'col_final_class' => 'Final Class',
    'col_occupation' => 'Occupation',
    'col_contact' => 'Contact',
    'col_engagements' => 'Engagements',
    'col_status' => 'Status',
    'col_actions' => 'Actions',
    'view' => 'View',

    'status_deceased' => 'Deceased',
    'status_reachable' => 'Reachable',
    'status_unreachable' => 'No contact',

    'convert_action' => 'Convert graduates',
    'convert_hide' => 'Hide panel',
    'convert_title' => 'Convert Graduates to Alumni',
    'convert_intro' => 'Graduated students without an alumnus record yet. Conversion freezes the graduation year and the final class name as they stand today.',
    'convert_empty' => 'Every graduated student already has an alumnus record.',
    'convert_selected_button' => 'Convert selected',
    'convert_none_selected' => 'Select at least one graduate to convert.',
    'converted_count' => '{1} :count graduate converted to an alumnus.|[2,*] :count graduates converted to alumni.',

    'profile' => 'Profile',
    'graduation' => 'Graduation',
    'graduation_year' => 'Graduation year',
    'final_class' => 'Final class',
    'academic_year' => 'Academic year',
    'occupation' => 'Occupation',
    'organisation' => 'Organisation',
    'email' => 'Email',
    'phone' => 'Phone',
    'notes' => 'Notes',
    'none' => '—',

    'engagement_timeline' => 'Engagement Timeline',
    'engagement_empty' => 'No engagement recorded yet. Donations, visits, talks and mentorships appear here as they are logged.',
    'engagement_type' => [
        'donation' => 'Donation',
        'visit' => 'Visit',
        'talk' => 'Talk',
        'mentorship' => 'Mentorship',
        'other' => 'Other',
    ],

    'record_engagement' => 'Record Engagement',
    'form_type' => 'Type',
    'form_date' => 'Date',
    'form_note' => 'Note',
    'engagement_recorded' => 'Engagement recorded.',

    'update_contact' => 'Update contact',
    'form_occupation' => 'Current occupation',
    'form_organisation' => 'Current organisation',
    'form_email' => 'Contact email',
    'form_phone' => 'Contact phone',
    'form_notes' => 'Notes',
    'contact_updated' => 'Contact details updated.',

    'mark_deceased' => 'Mark deceased',
    'confirm_deceased' => 'Mark this alumnus as deceased? This is one-way and cannot be undone from any screen.',
    'marked_deceased' => 'The alumnus has been marked deceased.',

    'save' => 'Save',
    'cancel' => 'Cancel',
    'back_to_list' => 'Back to alumni',
];
