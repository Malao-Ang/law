<?php

return [
    'timezone' => 'Asia/Bangkok',

    /** Days before a deadline when email is sent. Exact match, once per deadline. */
    'reminder_days' => [30, 14, 7],

    'published_status' => 'ประกาศแล้ว',

    /** Statuses that no longer need a deadline reminder or an overdue count. */
    'closed_statuses' => ['ประกาศแล้ว', 'ยกเลิก'],

    'statuses' => [
        ['key' => 'drafting', 'label' => 'อยู่ระหว่างการยกร่าง'],
        ['key' => 'submitted', 'label' => 'เสนอเพื่อพิจารณา'],
        ['key' => 'approved', 'label' => 'อนุมัติแล้ว/รอประกาศ'],
        ['key' => 'published', 'label' => 'ประกาศแล้ว'],
        ['key' => 'cancelled', 'label' => 'ยกเลิก'],
    ],
];
