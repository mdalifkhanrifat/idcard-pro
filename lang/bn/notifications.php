<?php

return [

    'channels' => [
        'mail' => 'ইমেইল',
        'sms' => 'এসএমএস',
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
    ],

    'events' => [
        'card_ready' => [
            'name' => 'কার্ড তৈরি',
            'description' => 'কার্ড ইস্যু হয়েছে, নেওয়া যাবে।',
            'subject' => 'আপনার আইডি কার্ড {card.number} তৈরি হয়েছে',
            'body' => "প্রিয় {holder.name},\n\n{organization.name}-এর আপনার আইডি কার্ড {card.number} তৈরি হয়েছে। কার্ডটি {card.expiry_date} পর্যন্ত বৈধ।\n\nধন্যবাদ।",
            'sms' => '{organization.name}: আপনার আইডি কার্ড {card.number} তৈরি হয়েছে, {card.expiry_date} পর্যন্ত বৈধ।',
        ],
        'card_expiring' => [
            'name' => 'কার্ডের মেয়াদ শেষ হচ্ছে',
            'description' => 'কার্ডের মেয়াদ শেষ হয়ে আসছে: নবায়নের সময়।',
            'subject' => 'আপনার আইডি কার্ড {card.number}-এর মেয়াদ {card.expiry_date}-এ শেষ হবে',
            'body' => "প্রিয় {holder.name},\n\n{organization.name}-এর আপনার আইডি কার্ড {card.number}-এর মেয়াদ {card.expiry_date}-এ শেষ হবে (আজ থেকে {card.days_left} দিন পর)। সময়মতো নবায়নের ব্যবস্থা করুন।\n\nধন্যবাদ।",
            'sms' => '{organization.name}: আপনার আইডি কার্ড {card.number}-এর মেয়াদ {card.expiry_date}-এ শেষ হবে। সময়মতো নবায়ন করুন।',
        ],
    ],

    'settings' => [
        'unknown_placeholders' => 'এই লেখায় :names আছে, যা এই বার্তায় বসানো যায় না। তালিকার মান থেকে বেছে নিন।',
        'channel_needed' => 'বার্তা পাঠাতে অন্তত একটা চ্যানেল বেছে নিন।',
    ],

];
