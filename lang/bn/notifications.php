<?php

return [

    'channels' => [
        'mail' => 'ইমেইল',
        'sms' => 'SMS',
    ],

    // বার্তা যেসব ভাষায় লেখা যায় (config notifications.languages)
    'languages' => [
        'bn' => 'বাংলা',
        'en' => 'ইংরেজি (English)',
    ],

    // SMS কোন ভাষায় লেখা হবে (config notifications.sms_languages)
    'sms_languages' => [
        'all' => 'সব ভাষা, ক্রমে',
        'first' => 'শুধু প্রথম ভাষা (ছোট, কম SMS ভাগ)',
    ],

    // বার্তার লেখায় যেসব মান বসানো যায়: {holder.name} ইত্যাদি
    'placeholders' => [
        'organization.name' => 'প্রতিষ্ঠানের নাম',
        'holder.name' => 'কার্ডধারকের নাম',
        'holder.employee_no' => 'কর্মী নম্বর',
        'card.number' => 'কার্ড নম্বর',
        'card.template' => 'কার্ড টেমপ্লেট',
        'card.issue_date' => 'ইস্যুর তারিখ',
        'card.expiry_date' => 'মেয়াদ শেষের তারিখ',
        'card.days_left' => 'মেয়াদ শেষ হতে বাকি দিন',
        'card.validity' => 'মেয়াদ (পুরো বাক্য; মেয়াদহীন কার্ডেও ঠিক)',
        'appointment.number' => 'অ্যাপয়েন্টমেন্টের নম্বর',
        'appointment.service' => 'কাজের ধরন',
        'appointment.place' => 'জায়গা',
        'appointment.address' => 'জায়গার ঠিকানা',
        'appointment.date' => 'অ্যাপয়েন্টমেন্টের তারিখ',
        'appointment.time' => 'অ্যাপয়েন্টমেন্টের সময় (শুধু দিন বুক হলে ফাঁকা)',
        'appointment.when' => 'তারিখ ও সময় (শুধু দিন বুক হলে শুধু দিন)',
        'appointment.status' => 'অ্যাপয়েন্টমেন্টের অবস্থা',
        'appointment.cancel_reason' => 'বাতিলের কারণ (পুরো বাক্য; কারণ না দিলে ফাঁকা)',
    ],

    // লেখার preview-এর বানানো তথ্য (কখনো আসল কারও নয়)
    'samples' => [
        'holder_name' => 'রহিম উদ্দিন',
        'employee_no' => 'EMP-1024',
        'template' => 'কর্মী কার্ড',
        'place' => 'প্রধান কার্যালয়',
        'address' => '১২ স্টেশন রোড',
        'cancel_reason' => 'সেদিন অফিস বন্ধ',
    ],

    // অ্যাপয়েন্টমেন্টের যে মান বাক্য হিসেবে লেখা
    'appointment' => [
        'when' => ':date, :time',
        'reason' => 'কারণ: :reason।',
        // In an appointment's e-mail (5.7b): under its QR code, and its calendar file's words
        'qr' => 'কাউন্টারে এই QR কোডটি (:number) দেখান।',
        'calendar_summary' => 'অ্যাপয়েন্টমেন্ট: :service — :organization',
        'calendar_description' => 'অ্যাপয়েন্টমেন্ট নম্বর :number, :person-এর জন্য। কাউন্টারে দেখান।',
        'calendar_cancelled' => 'অ্যাপয়েন্টমেন্ট নম্বর :number, :person-এর জন্য — বাতিল হয়েছে।',
    ],

    'validity' => [
        'until' => 'কার্ডটি :date পর্যন্ত বৈধ।',
        'none' => 'কার্ডটির মেয়াদ শেষের কোনো তারিখ নেই।',
    ],

    'events' => [
        'card_ready' => [
            'name' => 'কার্ড তৈরি',
            'description' => 'কার্ড ইস্যু হয়েছে, নেওয়া যাবে।',
            'subject' => 'আপনার আইডি কার্ড {card.number} তৈরি হয়েছে',
            'body' => "প্রিয় {holder.name},\n\n{organization.name}-এর আপনার আইডি কার্ড {card.number} তৈরি হয়েছে। {card.validity}\n\nধন্যবাদ।",
            // এক ভাগের SMS (বাংলা = Unicode, ৭০ অক্ষর); মেয়াদ আর বাকিটা ইমেইলে
            'sms' => '{organization.name}: আপনার কার্ড {card.number} তৈরি, সংগ্রহ করুন।',
        ],
        'card_expiring' => [
            'name' => 'কার্ডের মেয়াদ শেষ হচ্ছে',
            'description' => 'কার্ডের মেয়াদ শেষ হয়ে আসছে: নবায়নের সময়।',
            'subject' => 'আপনার আইডি কার্ড {card.number}-এর মেয়াদ {card.expiry_date}-এ শেষ হবে',
            'body' => "প্রিয় {holder.name},\n\n{organization.name}-এর আপনার আইডি কার্ড {card.number}-এর মেয়াদ {card.expiry_date}-এ শেষ হবে (আজ থেকে {card.days_left} দিন পর)। সময়মতো নবায়নের ব্যবস্থা করুন।\n\nধন্যবাদ।",
            'sms' => '{organization.name}: কার্ডের মেয়াদ {card.expiry_date}-এ শেষ, নবায়ন করুন।',
        ],
        'appointment_booked' => [
            'name' => 'অ্যাপয়েন্টমেন্ট বুক হয়েছে',
            'description' => 'অ্যাপয়েন্টমেন্ট বুক হয়েছে (কর্মী, পাবলিক লিংক বা অন্য সিস্টেম থেকে)।',
            'subject' => 'আপনার অ্যাপয়েন্টমেন্ট {appointment.number}: {appointment.when}',
            'body' => "প্রিয় {holder.name},\n\n{appointment.place}-এ {appointment.service}-এর জন্য আপনার অ্যাপয়েন্টমেন্ট {appointment.when}-এ বুক হয়েছে ({appointment.status})। নম্বর {appointment.number}; সাথে রাখুন।\n\n{organization.name}",
            // এক ভাগের SMS (বাংলা = Unicode, ৭০ অক্ষর); জায়গা আর বাকিটা ইমেইলে
            'sms' => '{organization.name}: বুকিং {appointment.number}, {appointment.when}।',
        ],
        'appointment_confirmed' => [
            'name' => 'অ্যাপয়েন্টমেন্ট নিশ্চিত হয়েছে',
            'description' => 'অনুমোদনের অপেক্ষায় থাকা অ্যাপয়েন্টমেন্ট নিশ্চিত হয়েছে, বা বাতিলটা ফেরানো হয়েছে।',
            'subject' => 'আপনার অ্যাপয়েন্টমেন্ট {appointment.number} নিশ্চিত হয়েছে',
            'body' => "প্রিয় {holder.name},\n\n{appointment.place}-এ {appointment.when}-এর {appointment.service}-এর জন্য আপনার অ্যাপয়েন্টমেন্ট {appointment.number} নিশ্চিত হয়েছে। নম্বরটি সাথে রাখুন।\n\n{organization.name}",
            'sms' => '{organization.name}: বুকিং {appointment.number}, {appointment.when} নিশ্চিত।',
        ],
        'appointment_changed' => [
            'name' => 'অ্যাপয়েন্টমেন্ট সরানো হয়েছে',
            'description' => 'অ্যাপয়েন্টমেন্ট অন্য সময়, দিন বা জায়গায় সরানো হয়েছে।',
            'subject' => 'আপনার অ্যাপয়েন্টমেন্ট {appointment.number} এখন {appointment.when}',
            'body' => "প্রিয় {holder.name},\n\n{appointment.service}-এর জন্য আপনার অ্যাপয়েন্টমেন্ট {appointment.number} সরানো হয়েছে। এখন {appointment.when}, {appointment.place}-এ।\n\n{organization.name}",
            'sms' => '{organization.name}: বুকিং {appointment.number} এখন {appointment.when}।',
        ],
        'appointment_cancelled' => [
            'name' => 'অ্যাপয়েন্টমেন্ট বাতিল',
            'description' => 'অ্যাপয়েন্টমেন্ট বাতিল হয়েছে, বা অনুমোদনের অপেক্ষায় থাকা অবস্থায় না-মঞ্জুর হয়েছে।',
            'subject' => 'আপনার অ্যাপয়েন্টমেন্ট {appointment.number} বাতিল হয়েছে',
            'body' => "প্রিয় {holder.name},\n\n{appointment.when}-এর {appointment.service}-এর জন্য আপনার অ্যাপয়েন্টমেন্ট {appointment.number} বাতিল হয়েছে। {appointment.cancel_reason}\n\nঅন্য সময়ে আবার বুক করতে পারেন।\n\n{organization.name}",
            'sms' => '{organization.name}: বুকিং {appointment.number} বাতিল হয়েছে।',
        ],
        'appointment_reminder' => [
            'name' => 'অ্যাপয়েন্টমেন্টের রিমাইন্ডার',
            'description' => 'অ্যাপয়েন্টমেন্ট কাছে এসেছে (প্রতিষ্ঠানের রিমাইন্ডারের ঘণ্টা আগে)।',
            'subject' => 'মনে করিয়ে দিচ্ছি: আপনার অ্যাপয়েন্টমেন্ট {appointment.when}',
            'body' => "প্রিয় {holder.name},\n\nমনে করিয়ে দিচ্ছি: {appointment.when}, {appointment.place}-এ {appointment.service}-এর জন্য আপনার অ্যাপয়েন্টমেন্ট {appointment.number}। নম্বরটি সাথে রাখুন।\n\n{organization.name}",
            'sms' => '{organization.name}: স্মরণ, বুকিং {appointment.number}, {appointment.when}।',
        ],
    ],

    // হাতে পাঠানো পরীক্ষার বার্তা (রেজিস্ট্রির ঘটনা নয়)
    // পাবলিক বুকিং লিংকে পরিচয় প্রমাণের এককালীন কোড (৫.৬)
    'booking_code' => [
        'name' => 'বুকিংয়ের কোড',
        'subject' => '{organization.name}: আপনার বুকিংয়ের কোড {code}',
        'body' => "{organization.name}-এ অ্যাপয়েন্টমেন্ট বুক করার জন্য আপনার কোড {code}।\n\nকোডটি {minutes} মিনিট কাজ করবে। কাউকে জানাবেন না; আপনি না চেয়ে থাকলে এই বার্তা উপেক্ষা করুন।",
        'sms' => '{organization.name}: বুকিংয়ের কোড {code} ({minutes} মিনিট)। কাউকে জানাবেন না।',
    ],

    'test' => [
        'name' => 'পরীক্ষার বার্তা',
        'subject' => '{organization.name}: পরীক্ষার বার্তা',
        'body' => "এটি {organization.name}-এর একটি পরীক্ষার বার্তা।\n\nবার্তাটি আপনার কাছে পৌঁছেছে, তাই এই চ্যানেলে বার্তা ঠিকঠাক যাচ্ছে। উত্তর দেওয়ার দরকার নেই।",
        'sms' => '{organization.name}: এটি একটি পরীক্ষার বার্তা। এই চ্যানেলে বার্তা ঠিকঠাক যাচ্ছে।',
        'address_needed' => 'পরীক্ষার বার্তা কোন ঠিকানায় যাবে লিখুন; আপনার নিজের প্রোফাইলে এই চ্যানেলের ঠিকানা নেই।',
    ],

    // লেখা ঠিকানা তার ধরনের মতো নয় (config notifications.addresses, ধাঁচ দেখে যাচাই)
    'addresses' => [
        'phone' => 'ফোন নম্বর লিখুন: ৬ থেকে ১৫টা অঙ্ক, চাইলে শুরুতে +।',
    ],

    // পাঠানো বার্তার লগ: কোনটা কেমন গেল, আর না গেলে কেন
    'statuses' => [
        'queued' => 'অপেক্ষায়',
        'sent' => 'পাঠানো হয়েছে',
        'failed' => 'ব্যর্থ',
        'skipped' => 'পাঠানো হয়নি',
    ],
    'reasons' => [
        'no_address' => 'ঠিকানা সংরক্ষিত নেই',
        'turned_off' => 'যাওয়ার আগে বন্ধ করা হয়েছে',
    ],

    // হাতে আবার পাঠানো: কেন যাবে না
    'resend' => [
        'not_failed' => 'শুধু ব্যর্থ বা না-যাওয়া বার্তা আবার পাঠানো যায়।',
        'code' => 'বুকিংয়ের কোড আবার পাঠানো হয় না: ব্যক্তি বুকিংয়ের পাতা থেকে নতুন কোড চাইবেন।',
        'already' => 'এই বার্তা আগেই আবার পাঠানো হয়েছে; দরকার হলে নতুনটা আবার পাঠান।',
        'unknown_channel' => 'এই চ্যানেল আর চালু নেই।',
        'no_recipient' => 'যাঁকে পাঠানো হয়েছিল, তাঁর রেকর্ড আর নেই।',
        'no_address' => 'এই চ্যানেলের জন্য এখনো কোনো ঠিকানা সংরক্ষিত নেই। আগে রেকর্ডে ঠিকানা যোগ করুন।',
    ],

    'settings' => [
        'unknown_placeholders' => 'এই লেখায় :names আছে, যা এই বার্তায় বসানো যায় না। তালিকার মান থেকে বেছে নিন।',
        'channel_needed' => 'বার্তা পাঠাতে অন্তত একটা চ্যানেল বেছে নিন।',
    ],

];
