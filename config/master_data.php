<?php

/*
|--------------------------------------------------------------------------
| Master data (lookup list) registry
|--------------------------------------------------------------------------
|
| Every reference list used across the application. Each tenant keeps its
| own items per type. Adding a new list only requires a new entry here.
|
| - parent:   type whose items act as parent (e.g. sub_region -> region)
| - has_code: whether items carry a short code (e.g. district code)
| - defaults: items created for every new tenant
|
| Legacy mapping: department = Base/Unit, unit = Unit, rank = Rank,
| designation = Designation, branch = Branch, trade = Trade,
| appointment = Appointment, region = District, sub_region = Upazila/Thana.
|
*/

return [

    'types' => [
        'department' => ['parent' => null, 'has_code' => true],
        'unit' => ['parent' => 'department', 'has_code' => true],
        'rank' => ['parent' => null, 'has_code' => true],
        'designation' => ['parent' => null, 'has_code' => true],
        'branch' => ['parent' => null, 'has_code' => true],
        'trade' => ['parent' => null, 'has_code' => true],
        'appointment' => ['parent' => null, 'has_code' => false],
        'authority_type' => ['parent' => null, 'has_code' => true],
        'blood_group' => [
            'parent' => null,
            'has_code' => false,
            'defaults' => ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'],
        ],
        'religion' => ['parent' => null, 'has_code' => false],
        'region' => ['parent' => null, 'has_code' => true],
        'sub_region' => ['parent' => 'region', 'has_code' => true],
    ],

];
