<?php

return [
    'subdomain'  => env('AIMHARDER_SUBDOMAIN', 'hybridboxgrau'),
    'box_id'     => (int) env('AIMHARDER_BOX_ID', 8244),
    'run_at'     => env('AIMHARDER_RUN_AT', '06:00'),
    'timezone'   => env('AIMHARDER_TZ', 'Europe/Madrid'),
    'retries'    => (int) env('AIMHARDER_RETRIES', 3),
    'user_agent' => env('AIMHARDER_UA', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36'),
];
