<?php

// Event keys hold dots: read `events` whole (__('webhooks.events')), never one by path.
return [
    'groups' => [
        'cardholder' => 'কার্ডধারী',
        'card' => 'কার্ড',
        'appointment' => 'অ্যাপয়েন্টমেন্ট',
    ],
    'events' => [
        'cardholder.created' => 'কার্ডধারী যোগ',
        'cardholder.updated' => 'কার্ডধারী বদল',
        'cardholder.deleted' => 'কার্ডধারী মোছা',
        'card.issued' => 'কার্ড ইস্যু',
        'card.printed' => 'কার্ড ছাপা',
        'card.updated' => 'কার্ড বদল',
        'card.revoked' => 'কার্ড বাতিল',
        'appointment.booked' => 'অ্যাপয়েন্টমেন্ট বুক',
        'appointment.confirmed' => 'অ্যাপয়েন্টমেন্ট নিশ্চিত',
        'appointment.moved' => 'অ্যাপয়েন্টমেন্ট সরানো',
        'appointment.cancelled' => 'অ্যাপয়েন্টমেন্ট বাতিল',
        'appointment.status_changed' => 'অ্যাপয়েন্টমেন্টের অবস্থা বদল',
    ],
    'too_many' => 'একটা প্রতিষ্ঠানে সর্বোচ্চ :maxটা webhook রাখা যায়।',
    'address' => [
        'invalid' => 'এটা ওয়েব ঠিকানা নয় (যেমন https://hr.example.com/hooks)।',
        'credentials' => 'ঠিকানায় নাম বা পাসওয়ার্ড দেবেন না; প্রতিটা পাঠানো সই করা থাকে।',
        'https' => 'https ব্যবহার করুন, যাতে পাঠানো তথ্য পথে পড়া না যায়।',
        'inner' => 'এই ঠিকানা ব্যক্তিগত নেটওয়ার্কের ভেতরে যায়। শুধু পাবলিক ঠিকানা চলবে।',
        'only_hosts' => 'এই ইনস্টলেশন শুধু নিজের পরীক্ষার গ্রাহকে পাঠায়।',
    ],
    'failures' => [
        'refused' => 'কিছু পাঠানো হয়নি: এই ঠিকানা ব্যবহার করা যায় না।',
        'unresolved' => 'এই ঠিকানা খুঁজে পাওয়া যায়নি।',
        'timeout' => 'ঠিকানা সময়মতো উত্তর দেয়নি।',
        'unreachable' => 'ঠিকানায় সংযোগ করা যায়নি।',
        'certificate' => 'ঠিকানার নিরাপত্তা সনদে ভরসা করা যায়নি।',
        'redirected' => 'ঠিকানা অন্য জায়গায় যেতে বলেছে। তা অনুসরণ করা হয় না: শেষের ঠিকানাটাই সেভ করুন।',
        'rejected' => 'ঠিকানা ভুল জানিয়ে উত্তর দিয়েছে।',
    ],
];
