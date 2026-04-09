<?php

define('DATA_JSON_FLAGS', JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

return [
    'site_name' => 'Local Share Hub',
    'timezone' => 'Asia/Kathmandu',
    'page_size' => 24,
    'max_file_size' => 100 * 1024 * 1024,
    'poll_interval_ms' => 3000,
    'admin_secret_slug' => 'admin',
    'admin_bootstrap_key' => 'admin',
    'token_length' => 32,
    'default_access_role' => 'viewer',
];
