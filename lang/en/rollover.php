<?php

declare(strict_types=1);

// Labels for the year-rollover wizard's domain enums
// (docs/specs/08-operations.md §6.2). Step keys are the RolloverStep ordinals.
return [
    'step' => [
        '0' => 'Pre-flight checks',
        '1' => 'Create the new year',
        '2' => 'Class levels & groups',
        '3' => 'Subject allocations & coefficients',
        '4' => 'Assessment periods',
        '5' => 'Fee structures & instalment plans',
        '6' => 'Promote students',
        '7' => 'Carry balances forward',
        '8' => 'Graduates & leavers',
        '9' => 'Teacher reassignment',
        '10' => 'Flip the active year',
    ],
    'run_status' => [
        'running' => 'Running',
        'completed' => 'Completed',
        'undone' => 'Undone',
        'failed' => 'Failed',
    ],
];
