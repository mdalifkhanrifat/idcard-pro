<?php

/*
|--------------------------------------------------------------------------
| Custom field presets
|--------------------------------------------------------------------------
|
| Ready-made groups of custom fields an organization can add in one step.
| Fields whose key already exists are skipped, so applying twice is safe.
|
| Legacy mapping: student = student_information (class, section, session,
| registration).
|
*/

return [

    'student' => [
        'entity' => 'cardholder',
        'group' => 'Student information',
        'fields' => [
            ['key' => 'class', 'label' => 'Class', 'label_local' => 'শ্রেণি', 'type' => 'text'],
            ['key' => 'section', 'label' => 'Section', 'label_local' => 'শাখা', 'type' => 'text'],
            ['key' => 'session', 'label' => 'Session', 'label_local' => 'সেশন', 'type' => 'text'],
            ['key' => 'registration_no', 'label' => 'Registration no', 'label_local' => 'রেজিস্ট্রেশন নম্বর', 'type' => 'text'],
        ],
    ],

];
