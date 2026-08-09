<?php

declare(strict_types=1);

return [
    'title' => 'Attendance Management',
    'take_title' => 'Take Attendance',
    'coverage_title' => 'Register Coverage',

    'breadcrumb_dashboard' => 'Dashboard',
    'breadcrumb_attendance' => 'Attendance',

    'status' => [
        'present' => 'Present',
        'absent' => 'Absent',
        'late' => 'Late',
        'excused' => 'Excused',
        'sick' => 'Sick',
        'suspended' => 'Suspended',
    ],

    'register_status' => [
        'open' => 'Open',
        'submitted' => 'Submitted',
        'amended' => 'Amended',
    ],

    'session' => [
        'morning' => 'Morning',
        'afternoon' => 'Afternoon',
        'full_day' => 'Full day',
    ],

    'mode' => [
        'daily' => 'Daily',
        'per_lesson' => 'Per lesson',
    ],

    'justification' => [
        'medical' => 'Medical',
        'family' => 'Family',
        'administrative' => 'Administrative',
        'transport' => 'Transport',
        'other' => 'Other',
    ],

    'filter_class' => 'Class',
    'filter_date' => 'Date',
    'filter_session' => 'Session',
    'filter_slot' => 'Lesson slot',
    'filter_period' => 'Period',
    'select_class' => 'Select a class…',
    'select_slot' => 'Select a lesson slot…',

    'pick_class' => 'Select a class group to take attendance.',
    'empty_roster' => 'No students are enrolled in this class group on the selected date.',
    'no_year' => 'No current academic year is configured.',
    'no_periods' => 'No assessment periods are defined for the current academic year.',
    'no_class_groups' => 'No class groups exist for the current academic year.',
    'no_calendar' => 'The school calendar has not been seeded for this month.',

    'taking_for' => 'Taking Attendance — :class',
    'date_label' => 'Date',
    'total_students' => 'Total Students',
    'suspended_note' => 'Recorded as suspended',
    'minutes' => 'min',

    'mark_all_present' => 'Mark All Present',
    'clear_all' => 'Clear All',
    'save' => 'Save Attendance',
    'saved' => 'Attendance saved.',
    'save_amendment' => 'Save Amendment',
    'amended' => 'Register amended.',
    'amend_reason_placeholder' => 'Reason for amendment…',
    'amend_reason_required' => 'An amendment must say why the submitted register was wrong.',
    'already_taken' => 'This register has already been :status. Changes require an amendment with a reason.',

    'summary_title' => 'Attendance Summary',
    'no_register_yet' => 'No register has been taken for this class, date and session.',
    'quick_actions' => 'Quick Actions',

    'kpi_total' => 'Total Students',
    'kpi_present_today' => 'Present Today',
    'kpi_absent_today' => 'Absent Today',
    'kpi_late_today' => 'Late Today',
    'kpi_month_rate' => 'Rate This Month',

    'todays_registers' => "Today's Registers",
    'no_registers_today' => 'No register has been taken today.',
    'no_registers_yet' => 'No register has been taken this month.',
    'overview_title' => 'Attendance Overview',
    'legend_not_present' => 'Absent / excused / suspended',
    'calendar_title' => 'Class Calendar',
    'legend_school_days' => 'School Days',
    'legend_holidays' => 'Holidays',
    'legend_events' => 'Events',

    'dow_mo' => 'Mo',
    'dow_tu' => 'Tu',
    'dow_we' => 'We',
    'dow_th' => 'Th',
    'dow_fr' => 'Fr',
    'dow_sa' => 'Sa',
    'dow_su' => 'Su',

    'col_admission_no' => 'Admission No.',
    'col_full_name' => 'Full Name',
    'col_status' => 'Status',
    'col_class' => 'Class',
    'col_session' => 'Session',
    'col_taken_by' => 'Taken By',
    'col_mode' => 'Mode',
    'col_teaching_days' => 'Teaching Days',
    'col_days_taken' => 'Days Taken',
    'col_coverage' => 'Coverage',
    'expected' => 'Expected',

    'coverage_explainer' => 'Registers taken ÷ teaching days per class group, :from to :to. A class with no registers has NO attendance rate — the gap surfaces here, not as a silent 100%.',
    'coverage_ok' => 'On track',
    'coverage_partial' => 'Partial',
    'coverage_poor' => 'Not covered',
    'coverage_no_calendar' => 'No calendar',
];
