<?php

return [
    'default' => [
        'enabled'  => true,
        'start'    => env('PAUSE_WINDOW_START', '01:00'),
        'end'      => env('PAUSE_WINDOW_END', '08:00'),
        'timezone' => env('PAUSE_WINDOW_TZ', env('APP_TIMEZONE', 'Asia/Kolkata')),
    ],
];
