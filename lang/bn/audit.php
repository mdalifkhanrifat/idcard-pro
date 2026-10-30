<?php

return [
    // যে মান audit কখনো দেখায় না (config audit.hidden)
    'hidden' => '(লুকানো)',

    // রেকর্ডের ঘরের audit-এর নিজের নাম, log-এর নাম ধরে; '*' যেকোনো log-এর জন্য। এখানে না থাকলে নাম আসে রিপোর্ট,
    // কার্ডধারীর তালিকা বা validation থেকে (App\Services\Audit\AuditChangeDescriber)।
    'fields' => [
        '*' => [
            'name' => 'নাম',
            'name_local' => 'নাম (স্থানীয় ভাষায়)',
            'is_active' => 'চালু',
            'phone' => 'ফোন',
            'locale' => 'ভাষা',
            'title' => 'শিরোনাম',
            'original_name' => 'ফাইলের নাম',
            'replaced_at' => 'বদলানোর সময়',
            'card_type_id' => 'কার্ডের ধরন',
            'password' => 'পাসওয়ার্ড',
            'authority_type_id' => 'কর্তৃপক্ষের ধরন',
        ],
        'user' => [
            'kind' => 'অ্যাকাউন্টের ধরন',
            'description' => 'বিবরণ',
            'username' => 'ইউজারনেম',
            'roles' => 'রোল',
        ],
        'api_tokens' => [
            'owner' => 'অ্যাকাউন্ট',
            'name' => 'API token-এর নাম',
            'expires_at' => 'শেষ দিন',
        ],
        'roles' => [
            'name' => 'রোলের নাম',
            'permissions' => 'পারমিশন',
        ],
        'tenant' => [
            'slug' => 'সংক্ষিপ্ত নাম (লিংকে)',
            'legal_name' => 'আইনি নাম',
            'timezone' => 'সময়-অঞ্চল',
            'primary_color' => 'প্রধান রং',
            'logo_path' => 'লোগো',
        ],
        'appointments' => [
            'number' => 'বুকিং নম্বর',
            'starts_at' => 'শুরুর সময়',
            'ends_at' => 'শেষের সময়',
            'cancel_reason' => 'বাতিলের কারণ',
        ],
        'biometrics' => [
            'modality' => 'ধরন',
            'position' => 'অবস্থান',
            'unavailable_reason' => 'না নেওয়ার কারণ',
            'driver' => 'নেওয়ার উপায়',
            'quality' => 'মান',
            'low_quality' => 'কম মান',
        ],
        'cards' => [
            'number' => 'কার্ড নম্বর',
            'rf_card_no' => 'RF কার্ড নম্বর',
            'card_template_id' => 'টেমপ্লেট',
            'template_version' => 'টেমপ্লেটের সংস্করণ',
            'issue_date' => 'ইস্যুর তারিখ',
            'expiry_date' => 'মেয়াদ শেষের তারিখ',
            'status_note' => 'অবস্থার নোট',
        ],
        'card_templates' => [
            'orientation' => 'দিক',
            'width' => 'প্রস্থ',
            'height' => 'উচ্চতা',
            'size_unit' => 'মাপের একক',
            'validity_months' => 'মেয়াদ (মাস)',
            'version' => 'সংস্করণ',
        ],
        'cardholder_files' => [
            'collection' => 'ফাইলের ধরন',
        ],
        'custom_fields' => [
            'entity' => 'কিসের ঘর',
            'key' => 'চাবি (key)',
            'label' => 'লেবেল',
            'type' => 'ধরন',
            'is_required' => 'আবশ্যক',
        ],
        'family_members' => [
            'relation' => 'সম্পর্ক',
            'card_no' => 'কার্ড নম্বর',
        ],
        'master_data' => [
            'type' => 'তালিকা',
        ],
        'qualifications' => [
            'kind' => 'ধরন',
            'institution' => 'প্রতিষ্ঠান',
            'year' => 'বছর',
            'result' => 'ফলাফল',
        ],
        'signatories' => [
            'signature_path' => 'স্বাক্ষরের ছবি',
            'effective_from' => 'যেদিন থেকে কার্যকর',
            'effective_to' => 'যেদিন পর্যন্ত কার্যকর',
        ],
        'fonts' => [
            'family' => 'ফন্টের পরিবার',
            'style' => 'ধরন (স্টাইল)',
        ],
        'images' => [
            'path' => 'ছবির ফাইল',
        ],
    ],
    // যেসব মানের নাম অ্যাপ শুধু পাতায় দেয় (config audit.values)
    'values' => [
        'locale' => ['en' => 'ইংরেজি', 'bn' => 'বাংলা'],
        'user_kind' => ['person' => 'ব্যক্তি', 'integration' => 'ইন্টিগ্রেশন'],
        'permission_module' => ['users' => 'ইউজার', 'roles' => 'রোল', 'audit' => 'অডিট লগ', 'settings' => 'প্রতিষ্ঠানের সেটিংস', 'master_data' => 'মাস্টার ডেটা', 'cardholders' => 'কার্ডধারী', 'signatories' => 'সইকারী', 'templates' => 'কার্ড টেমপ্লেট', 'fonts' => 'ফন্ট', 'images' => 'ছবির লাইব্রেরি', 'cards' => 'ইস্যু হওয়া কার্ড', 'biometrics' => 'বায়োমেট্রিক', 'reports' => 'রিপোর্ট', 'notifications' => 'নোটিফিকেশন', 'appointments' => 'অ্যাপয়েন্টমেন্ট', 'integrations' => 'ইন্টিগ্রেশন'],
        'permission_ability' => ['view' => 'দেখা', 'create' => 'তৈরি', 'update' => 'সম্পাদনা', 'delete' => 'মোছা', 'import' => 'ইমপোর্ট', 'issue' => 'ইস্যু', 'print' => 'প্রিন্ট', 'revoke' => 'বাতিল', 'enroll' => 'নেওয়া', 'override_requirements' => 'শর্ত পার করা', 'export' => 'রপ্তানি', 'send' => 'পাঠানো', 'book' => 'বুক করা', 'manage' => 'পরিচালনা'],
        'tenant_status' => ['active' => 'সক্রিয়', 'suspended' => 'স্থগিত'],
        'custom_field_type' => ['text' => 'টেক্সট', 'textarea' => 'বড় টেক্সট', 'number' => 'সংখ্যা', 'date' => 'তারিখ', 'select' => 'ড্রপডাউন', 'checkbox' => 'হ্যাঁ / না'],
        'custom_field_entity' => ['cardholder' => 'কার্ডধারী', 'family_member' => 'পরিবারের সদস্য'],
        'capture_status' => ['captured' => 'নেওয়া হয়েছে', 'unavailable' => 'নেওয়া যায় না'],
        'biometric_driver' => ['upload' => 'ফাইল আপলোড', 'simulator' => 'সিমুলেটর', 'webcam' => 'ওয়েবক্যাম', 'secugen' => 'SecuGen', 'bridge' => 'ব্রিজ এজেন্ট'],
        'biometric_mode' => ['off' => 'বন্ধ', 'optional' => 'ঐচ্ছিক', 'required' => 'বাধ্যতামূলক'],
        'file_collection' => ['photo' => 'ছবি', 'signature' => 'সই', 'document' => 'নথি'],
        'number_on_reissue' => ['keep' => 'বর্তমান নম্বর রাখো', 'new' => 'সবসময় নতুন নম্বর'],
        'card_detail' => ['photo' => 'ছবি', 'template' => 'টেমপ্লেট'],
    ],

    // যে রেকর্ডের দিকে id দেখায় সেটা আর নেই
    'deleted_record' => '#:id (মোছা)',
];
