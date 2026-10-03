<?php

return [
    'lookup_parent_mismatch' => 'নির্বাচিত আইটেমটি বেছে নেওয়া :parent-এর অধীনে নয়।',
    'fields' => [
        'blood_group_id' => 'রক্তের গ্রুপ',
        'religion_id' => 'ধর্ম',
        'region_id' => 'অঞ্চল',
        'sub_region_id' => 'এলাকা',
        'department_id' => 'বিভাগ',
        'unit_id' => 'ইউনিট',
        'rank_id' => 'র‍্যাংক',
        'designation_id' => 'পদবি',
        'branch_id' => 'শাখা',
        'trade_id' => 'ট্রেড',
        'appointment_id' => 'নিয়োগ',
    ],
    'import' => [
        'parent_required' => 'এটি খুঁজতে আগে একটি :parent দিন।',
        'unknown_lookup' => '“:value” :list তালিকায় নেই।',
        'duplicate_in_file' => 'কর্মী নম্বর :value ফাইলে একাধিকবার আছে।',
        'name_column_missing' => 'ফাইলে "Name" বা "Full name" কলাম থাকতে হবে।',
        'too_many_rows' => 'ফাইলে :max-এর বেশি সারি আছে। ছোট ছোট ফাইলে ভাগ করুন।',
        'unreadable' => 'ফাইলটি পড়া যায়নি। CSV বা XLSX ফাইল দিন।',
        'empty' => 'ফাইলে কোনো হেডার সারি নেই।',
        'row_failed' => 'এই সারিটি সেভ করা যায়নি।',
        'already_started' => 'এই ইমপোর্ট আগেই শুরু হয়েছে।',
        'nothing_to_import' => 'ইমপোর্ট করার মতো কোনো সঠিক সারি নেই।',
        'running' => 'ইমপোর্ট চলছে, এখন মোছা যাবে না।',
    ],
];
