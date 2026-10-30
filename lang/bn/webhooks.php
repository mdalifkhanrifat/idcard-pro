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
    ],
];
