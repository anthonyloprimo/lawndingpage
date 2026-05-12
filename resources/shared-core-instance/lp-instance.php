<?php
// Per-instance overrides for a shared-core deployment.
return [
    'instance_root_dir' => __DIR__,
    'instance_public_dir' => __DIR__ . '/public',
    'instance_admin_dir' => __DIR__ . '/public/admin',
    'instance_data_dir' => __DIR__ . '/public/res/data',
    'instance_img_dir' => __DIR__ . '/public/res/img',
    'instance_private_dir' => __DIR__ . '/data',
    'instance_runtime_admin_dir' => __DIR__ . '/data/admin',
    'instance_logs_dir' => __DIR__ . '/data/logs',
    'instance_state_dir' => __DIR__ . '/data/state',
    'instance_modules_dir' => __DIR__ . '/modules',
];
