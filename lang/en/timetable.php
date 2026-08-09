<?php

declare(strict_types=1);

// Timetable Management (09-ui §8.6) + school calendar vocabulary. Its own
// file (like rollover.php) so Phase 8 agents never edit opes.php
// concurrently; LocalisationTest's structural-parity guarantee is mirrored in
// TimetableTest for this pair.
return [
    'title' => 'Timetable Management',
    'breadcrumb_dashboard' => 'Dashboard',
    'breadcrumb_academics' => 'Academics',
    'breadcrumb_timetable' => 'Timetable Management',

    'tab_class' => 'Class',
    'tab_teacher' => 'Teacher',
    'tab_room' => 'Room',
    'tab_exam' => 'Exam',

    'year_label' => 'Academic Year',
    'class_label' => 'Class',
    'teacher_label' => 'Teacher',
    'teacher_placeholder' => 'Select a teacher…',
    'room_label' => 'Room',
    'room_placeholder' => 'Select a room…',
    'room_none' => 'No room',

    'assign_subject' => 'Assign Subject',
    'generate' => 'Generate Timetable',
    'generate_unavailable' => 'Automatic timetable generation is not available in this version. Assign subjects to periods manually.',
    'dismiss' => 'Dismiss',

    'field_day' => 'Day',
    'field_period' => 'Period',
    'field_subject' => 'Subject',
    'field_teacher' => 'Teacher',
    'field_room' => 'Room',
    'save' => 'Save',
    'cancel' => 'Cancel',
    'assigned' => 'The slot has been assigned.',
    'removed' => 'The slot has been removed.',
    'remove_slot' => 'Remove this slot',

    'grid_heading' => 'Weekly Timetable',
    'column_time' => 'Time',
    'no_year' => 'No current academic year is set. Timetables are per-year — set the current year in Academic Settings first.',
    'no_classes' => 'No class groups exist for the current academic year yet.',
    'no_periods' => 'This section has no bell schedule yet. Define its periods (Add New Period / Set Time Breaks) before assigning subjects.',
    'pick_teacher' => 'Select a teacher to see their weekly load.',
    'pick_room' => 'Select a room to see its weekly occupancy.',

    'details_heading' => 'Class Details',
    'details_class' => 'Class',
    'details_students' => 'Students',
    'details_mode' => 'Attendance mode',

    'legend_heading' => 'Subjects',
    'legend_empty' => 'No subjects assigned yet.',

    'exam_heading' => 'Exam sittings — current year',
    'exam_empty' => 'No exam sittings are scheduled for the current academic year.',
    'exam_date' => 'Date',
    'exam_time' => 'Time',
    'exam_class' => 'Class',
    'exam_subject' => 'Subject',
    'exam_room' => 'Room',
    'exam_status' => 'Status',

    'day' => [
        'monday' => 'Monday',
        'tuesday' => 'Tuesday',
        'wednesday' => 'Wednesday',
        'thursday' => 'Thursday',
        'friday' => 'Friday',
        'saturday' => 'Saturday',
    ],

    'day_type' => [
        'teaching' => 'Teaching day',
        'weekend' => 'Weekend',
        'public_holiday' => 'Public holiday',
        'school_holiday' => 'School holiday',
        'exam' => 'Exam day',
        'staff_day' => 'Staff day',
        'closure' => 'Closure',
    ],

    'attendance_mode' => [
        'daily' => 'Daily register',
        'per_lesson' => 'Per-lesson register',
    ],
];
