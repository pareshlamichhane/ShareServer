<?php
require __DIR__ . '/bootstrap.php';
require_login_json();
$file = isset($_GET['file']) ? (string)$_GET['file'] : '';
send_download_file($file);
