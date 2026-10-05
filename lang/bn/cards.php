<?php

return [
    'number_format' => [
        'seq_required' => 'ফরম্যাটে ঠিক একটি {SEQ:n} থাকতে হবে, n ১ থেকে ১০-এর মধ্যে।',
        'unknown_token' => 'শুধু {CODE}, {YYYY}, {YY}, {MM} আর {SEQ:n} ব্যবহার করুন।',
        'invalid_characters' => 'শুধু অক্ষর, সংখ্যা, টোকেন আর / - _ . ব্যবহার করুন।',
    ],
    'designer' => [
        'back_side_disabled' => 'এই টেমপ্লেট এক পাশের। পেছনের নকশা করতে "দুই পাশ" চালু করুন।',
        'single_background' => 'প্রতি পাশে একটাই ব্যাকগ্রাউন্ড রাখা যায়।',
        'field_required' => 'কোন তথ্য দেখাবে তা বাছাই করুন।',
        'field_unknown' => 'এই ঘরটা এই টেমপ্লেটে ব্যবহার করা যায় না।',
        'content_required' => 'যে লেখা দেখাবে তা লিখুন।',
        'qr_line_invalid' => 'QR-এর প্রতিটা লাইনে একটা ঘর বা কাস্টম লেখা থাকতে হবে।',
        'outside_card' => 'উপাদানটা কার্ডের ভেতরে থাকতে হবে (:width × :height mm)।',
        'tilted_outside_card' => 'এভাবে হেলালে উপাদানটা কার্ডের কিনারার বাইরে যায়। ভেতরে সরান, কোণ কমান, অথবা কিনারায় কাটা চলবে চালু করুন।',
        'image_missing' => 'ছবিটা পাওয়া যায়নি। আবার আপলোড করুন।',
        'image_or_library' => 'আপলোড করা ছবি বা লাইব্রেরির ছবি, যেকোনো একটা দিন।',
    ],
    // Printed values, in the organization's language (CardDataResolver)
    'values' => [
        'gender' => [
            'male' => 'পুরুষ',
            'female' => 'নারী',
            'other' => 'অন্যান্য',
        ],
        'marital_status' => [
            'single' => 'অবিবাহিত',
            'married' => 'বিবাহিত',
            'divorced' => 'তালাকপ্রাপ্ত',
            'widowed' => 'বিধবা/বিপত্নীক',
        ],
        'employment_type' => [
            'staff' => 'কর্মী',
            'contractor' => 'ঠিকাদার',
            'visitor' => 'অতিথি',
            'student' => 'ছাত্র/ছাত্রী',
            'other' => 'অন্যান্য',
        ],
        'relation' => [
            'father' => 'পিতা',
            'mother' => 'মাতা',
            'father_in_law' => 'শ্বশুর',
            'mother_in_law' => 'শাশুড়ি',
            'spouse' => 'স্বামী/স্ত্রী',
            'son' => 'পুত্র',
            'daughter' => 'কন্যা',
            'sibling' => 'ভাই/বোন',
            'other' => 'অন্যান্য',
        ],
        'yes' => 'হ্যাঁ',
        'no' => 'না',
    ],
];
