<?php

function cfg($key) {
    global $config;
    return isset($config[$key]) ? $config[$key] : null;
}

function h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function json_response($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, DATA_JSON_FLAGS);
    exit;
}

function redirect_to($url) {
    header('Location: ' . $url);
    exit;
}

function app_root() { return dirname(__DIR__); }
function data_dir() { return app_root() . '/data'; }
function uploads_dir() { return data_dir() . '/uploads'; }
function thumbs_dir() { return data_dir() . '/thumbs'; }
function access_tokens_file() { return data_dir() . '/access_tokens.json'; }
function versions_file() { return data_dir() . '/versions.json'; }
function notes_file() { return data_dir() . '/notes.json'; }
function files_meta_file() { return data_dir() . '/files_meta.json'; }
function files_cache_file() { return data_dir() . '/files_cache.json'; }
function activity_file() { return data_dir() . '/activity.json'; }

function ensure_app_storage() {
    if (!is_dir(data_dir())) mkdir(data_dir(), 0777, true);
    if (!is_dir(uploads_dir())) mkdir(uploads_dir(), 0777, true);
    if (!is_dir(thumbs_dir())) mkdir(thumbs_dir(), 0777, true);

    if (!file_exists(access_tokens_file())) save_json(access_tokens_file(), []);
    if (!file_exists(versions_file())) save_json(versions_file(), [
        'notes' => time(),
        'files' => time(),
        'activity' => time(),
    ]);
    if (!file_exists(notes_file())) {
        save_json(notes_file(), [
            'tabs' => [[
                'id' => 'tab_' . time(),
                'title' => 'Shared Note',
                'content' => '',
                'updated_at' => now_text(),
            ]],
        ]);
    }
    if (!file_exists(files_meta_file())) save_json(files_meta_file(), []);
    if (!file_exists(files_cache_file())) save_json(files_cache_file(), []);
    if (!file_exists(activity_file())) save_json(activity_file(), []);
}

function load_json($file, $fallback = []) {
    $raw = @file_get_contents($file);
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : $fallback;
}

function save_json($file, $data) {
    file_put_contents($file, json_encode($data, DATA_JSON_FLAGS), LOCK_EX);
}

function now_text() { return date('Y-m-d H:i:s'); }

function random_token($length = 32) {
    return bin2hex(random_bytes((int)ceil($length / 2)));
}

function safe_filename($name) {
    $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name);
    $name = trim((string)$name);
    return $name !== '' ? $name : 'file';
}

function storage_filename($originalName) {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $rand = bin2hex(random_bytes(8));
    $base = $rand . '_' . time();
    return $ext ? ($base . '.' . preg_replace('/[^A-Za-z0-9]/', '', $ext)) : $base;
}

function format_bytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $value = max(0, (float)$bytes);
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }
    return round($value, 2) . ' ' . $units[$i];
}

function detect_mime($path) {
    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($path);
        if ($mime) return $mime;
    }
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = @$finfo->file($path);
        if ($mime) return $mime;
    }
    return 'application/octet-stream';
}

function starts_with($haystack, $needle) {
    return substr($haystack, 0, strlen($needle)) === $needle;
}

function get_file_category($displayName, $mime) {
    $ext = strtolower(pathinfo($displayName, PATHINFO_EXTENSION));
    $images = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
    $videos = ['mp4', 'webm', 'mov', 'm4v'];
    $audio = ['mp3', 'wav', 'ogg', 'm4a', 'aac'];

    if (in_array($ext, $images, true) || starts_with($mime, 'image/')) return 'image';
    if (in_array($ext, $videos, true) || starts_with($mime, 'video/')) return 'video';
    if (in_array($ext, $audio, true) || starts_with($mime, 'audio/')) return 'audio';
    if ($ext === 'pdf' || $mime === 'application/pdf') return 'pdf';
    return 'other';
}

function get_client_ip() {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $parts = explode(',', (string)$_SERVER[$key]);
            return trim($parts[0]);
        }
    }
    return 'unknown-ip';
}

function add_activity($type, $text, $extra = []) {
    $items = load_json(activity_file(), []);
    array_unshift($items, [
        'type' => $type,
        'text' => $text,
        'time' => now_text(),
        'extra' => $extra,
    ]);
    $items = array_slice($items, 0, 200);
    save_json(activity_file(), $items);
    bump_version('activity');
}

function bump_version($key) {
    $versions = load_json(versions_file(), []);
    $versions[$key] = time();
    save_json(versions_file(), $versions);
}
