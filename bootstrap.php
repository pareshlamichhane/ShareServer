<?php
session_start();

$config = require __DIR__ . '/config/app.php';
date_default_timezone_set($config['timezone']);

require __DIR__ . '/lib/common.php';
require __DIR__ . '/lib/sessions.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/notes.php';
require __DIR__ . '/lib/files.php';

ensure_app_storage();
ensure_sessions_storage();
bootstrap_token_login();
bootstrap_session_context();