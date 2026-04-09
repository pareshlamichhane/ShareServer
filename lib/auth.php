<?php

function load_access_tokens() {
    return load_json(access_tokens_file(), []);
}

function save_access_tokens($tokens) {
    save_json(access_tokens_file(), $tokens);
}

function token_session_usage_file() {
    return data_dir() . '/token_session_usage.json';
}

function load_token_session_usage() {
    return load_json(token_session_usage_file(), []);
}

function save_token_session_usage($data) {
    save_json(token_session_usage_file(), $data);
}

function increment_token_session_usage($token, $sessionId) {
    if ($token === '' || $sessionId === '') return;
    $data = load_token_session_usage();
    if (!isset($data[$token])) $data[$token] = [];
    $data[$token][$sessionId] = (int)($data[$token][$sessionId] ?? 0) + 1;
    save_token_session_usage($data);
}

function get_token_session_usage($token) {
    $data = load_token_session_usage();
    return isset($data[$token]) && is_array($data[$token]) ? $data[$token] : [];
}

function app_base_url() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $dir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    if ($dir === '') $dir = '';

    return $scheme . '://' . $host . $dir . '/';
}

function token_share_url($token, $baseUrl = null) {
    $base = $baseUrl ? rtrim((string)$baseUrl, '/') . '/' : app_base_url();
    return $base . '?token=' . rawurlencode($token);
}

function token_is_expired($row) {
    if (empty($row['expires_at'])) return false;
    $ts = strtotime((string)$row['expires_at']);
    if (!$ts) return false;
    return $ts < time();
}

function bootstrap_token_login() {
    if (!empty($_SESSION['access_ok'])) {
        return;
    }

    if (!empty($_GET['token'])) {
        $token = trim((string)$_GET['token']);
        $tokens = load_access_tokens();

        if (isset($tokens[$token]) && !empty($tokens[$token]['enabled']) && !token_is_expired($tokens[$token])) {
            $_SESSION['access_ok'] = true;
            $_SESSION['access_token'] = $token;
            $_SESSION['access_role'] = $tokens[$token]['role'] ?? cfg('default_access_role');
            $_SESSION['access_name'] = $tokens[$token]['label'] ?? ('Guest @ ' . get_client_ip());

            $sessionIds = isset($tokens[$token]['session_ids']) && is_array($tokens[$token]['session_ids'])
                ? array_values(array_filter($tokens[$token]['session_ids']))
                : [default_share_session_id()];

            $_SESSION['allowed_session_ids'] = $sessionIds;

            $defaultSessionId = $tokens[$token]['default_session_id'] ?? '';
            if ($defaultSessionId === '' || !in_array($defaultSessionId, $sessionIds, true)) {
                $defaultSessionId = $sessionIds[0];
            }
            $_SESSION['current_session_id'] = $defaultSessionId;

            $tokens[$token]['last_used_at'] = now_text();
            $tokens[$token]['last_used_ip'] = get_client_ip();
            $tokens[$token]['use_count'] = (int)($tokens[$token]['use_count'] ?? 0) + 1;
            save_access_tokens($tokens);

            increment_token_session_usage($token, $defaultSessionId);

            $clean = strtok($_SERVER['REQUEST_URI'], '?');
            redirect_to($clean ?: 'index.php');
        }
    }

    if (!empty($_GET['admin_key']) && hash_equals((string)cfg('admin_bootstrap_key'), (string)$_GET['admin_key'])) {
        $_SESSION['access_ok'] = true;
        $_SESSION['access_role'] = 'admin';
        $_SESSION['access_name'] = 'Admin';
        $_SESSION['is_admin'] = true;
        $_SESSION['allowed_session_ids'] = array_map(function ($s) { return $s['id']; }, list_share_sessions());
        $_SESSION['current_session_id'] = default_share_session_id();

        $clean = strtok($_SERVER['REQUEST_URI'], '?');
        redirect_to($clean ?: 'index.php');
    }
}

function is_logged_in() {
    return !empty($_SESSION['access_ok']);
}

function current_role() {
    if (!empty($_SESSION['is_admin'])) return 'admin';
    return $_SESSION['access_role'] ?? 'viewer';
}

function can_edit() {
    return current_role() === 'editor' || current_role() === 'admin';
}

function is_admin() {
    return current_role() === 'admin';
}

function require_login_json() {
    if (!is_logged_in()) {
        json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
    }
}

function require_editor_json() {
    require_login_json();
    if (!can_edit()) {
        json_response(['ok' => false, 'error' => 'Editor access required'], 403);
    }
}

function require_admin_json() {
    require_login_json();
    if (!is_admin()) {
        json_response(['ok' => false, 'error' => 'Admin only'], 403);
    }
}

function current_user_label() {
    return $_SESSION['access_name'] ?? ('Guest @ ' . get_client_ip());
}

function create_access_token($label, $role = 'viewer', $expiresAt = '', $sessionIds = [], $defaultSessionId = '') {
    $tokens = load_access_tokens();
    $token = random_token((int)cfg('token_length'));

    $sessionIds = array_values(array_unique(array_filter(array_map('trim', (array)$sessionIds))));
    if (!$sessionIds) {
        $sessionIds = [default_share_session_id()];
    }

    if ($defaultSessionId === '' || !in_array($defaultSessionId, $sessionIds, true)) {
        $defaultSessionId = $sessionIds[0];
    }

    $tokens[$token] = [
        'label' => mb_substr(trim((string)$label), 0, 80),
        'role' => in_array($role, ['viewer', 'editor', 'admin'], true) ? $role : 'viewer',
        'enabled' => true,
        'created_at' => now_text(),
        'last_used_at' => '',
        'last_used_ip' => '',
        'expires_at' => trim((string)$expiresAt),
        'use_count' => 0,
        'session_ids' => $sessionIds,
        'default_session_id' => $defaultSessionId,
    ];

    save_access_tokens($tokens);
    add_activity('token', 'Created access token', ['label' => $label, 'role' => $role]);

    return $token;
}

function disable_access_token($token) {
    $tokens = load_access_tokens();
    if (!isset($tokens[$token])) return false;
    $tokens[$token]['enabled'] = false;
    save_access_tokens($tokens);
    add_activity('token', 'Disabled access token', ['token' => $token]);
    return true;
}

function cleanup_expired_tokens() {
    $tokens = load_access_tokens();
    $count = 0;

    foreach ($tokens as $token => $row) {
        if (token_is_expired($row) && !empty($row['enabled'])) {
            $tokens[$token]['enabled'] = false;
            $count++;
        }
    }

    if ($count) save_access_tokens($tokens);
    return $count;
}