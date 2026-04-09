<?php
require __DIR__ . '/bootstrap.php';

// 1. Check Auth - If it's an <img> tag, we just exit silently on fail
if (!is_logged_in()) {
    http_response_code(403);
    exit; 
}

// 2. Clean the input - basename() prevents directory traversal attacks
$fileId = isset($_GET['file']) ? basename((string)$_GET['file']) : '';

if ($fileId === '') {
    http_response_code(400);
    exit;
}

// 3. Use your working function
// If this function sends headers and reads the file, you are good to go.
send_thumb_file($fileId);
exit;