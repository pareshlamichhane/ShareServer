<?php
require __DIR__ . '/../bootstrap.php';

$action = isset($_GET['action']) ? (string)$_GET['action'] : '';

if ($action === 'switch_session') {
    require_login_json();
    $sessionId = (string)($_POST['session_id'] ?? '');
    if (!switch_current_session($sessionId)) {
        json_response(['ok' => false, 'error' => 'Session not allowed'], 403);
    }
    json_response(['ok' => true]);
}

if ($action === 'session_info') {
    require_login_json();
    $allowed = allowed_session_ids_for_current_user();
    $sessions = array_values(array_filter(list_share_sessions(), function ($s) use ($allowed) {
        return in_array($s['id'], $allowed, true);
    }));

    json_response([
        'ok' => true,
        'current_session_id' => current_session_id(),
        'current_session' => current_session_info(),
        'sessions' => $sessions,
    ]);
}

if ($action === 'admin_sessions') {
    require_admin_json();
    json_response(['ok' => true, 'items' => list_share_sessions()]);
}

if ($action === 'admin_session_stats') {
    require_admin_json();
    json_response(['ok' => true, 'items' => list_all_session_stats()]);
}

if ($action === 'admin_create_session') {
    require_admin_json();
    $id = create_share_session(
        (string)($_POST['name'] ?? ''),
        (string)($_POST['description'] ?? ''),
        (string)($_POST['brand_title'] ?? ''),
        (string)($_POST['brand_color'] ?? '#2563eb'),
        (string)($_POST['accent_color'] ?? '#0f172a')
    );
    json_response(['ok' => true, 'id' => $id]);
}

if ($action === 'admin_update_session') {
    require_admin_json();
    $id = (string)($_POST['id'] ?? '');
    $ok = update_share_session($id, [
        'name' => (string)($_POST['name'] ?? ''),
        'description' => (string)($_POST['description'] ?? ''),
        'brand_title' => (string)($_POST['brand_title'] ?? ''),
        'brand_color' => (string)($_POST['brand_color'] ?? ''),
        'accent_color' => (string)($_POST['accent_color'] ?? ''),
        'archive_mode' => !empty($_POST['archive_mode']),
    ]);
    json_response(['ok' => $ok]);
}

if ($action === 'admin_clone_session') {
    require_admin_json();
    $sourceId = (string)($_POST['source_id'] ?? '');
    $newName = (string)($_POST['new_name'] ?? '');
    $id = clone_share_session($sourceId, $newName);
    if (!$id) json_response(['ok' => false, 'error' => 'Could not clone session'], 400);
    json_response(['ok' => true, 'id' => $id]);
}

if ($action === 'admin_delete_session') {
    require_admin_json();
    $sessionId = (string)($_POST['session_id'] ?? '');
    json_response(delete_share_session($sessionId));
}

if ($action === 'poll') {
    require_login_json();
    json_response([
        'ok' => true,
        'versions' => load_json(versions_file(), []),
    ]);
}

if ($action === 'files_list') {
    require_login_json();

    $search = (string)($_GET['search'] ?? '');
    $type = (string)($_GET['type'] ?? 'all');
    $sort = (string)($_GET['sort'] ?? 'newest');
    $tag = (string)($_GET['tag'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $pageSize = max(1, min(100, (int)($_GET['page_size'] ?? cfg('page_size'))));

    $payload = filter_sort_paginate_files($search, $type, $sort, $page, $pageSize, $tag);
    $allItems = load_files_cache();

    json_response([
        'ok' => true,
        'items' => $payload['items'],
        'page' => $payload['page'],
        'pages' => $payload['pages'],
        'total' => $payload['total'],
        'recent' => array_slice($allItems, 0, 8),
        'tags' => all_tags_from_cache(),
    ]);
}

if ($action === 'upload') {
    require_editor_json();
    if (!session_can_write()) json_response(['ok' => false, 'error' => 'This session is archived'], 403);

    if (empty($_FILES['shared_file'])) {
        json_response(['ok' => false, 'error' => 'No file selected'], 400);
    }

    try {
        $uploaded = save_uploaded_files($_FILES['shared_file']);
        json_response([
            'ok' => true,
            'uploaded' => $uploaded,
            'uploaded_count' => count($uploaded),
        ]);
    } catch (Exception $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 400);
    }
}

if ($action === 'file_update_meta') {
    require_editor_json();
    if (!session_can_write()) json_response(['ok' => false, 'error' => 'This session is archived'], 403);

    $fileId = (string)($_POST['file'] ?? '');
    $title = (string)($_POST['title'] ?? '');
    $description = (string)($_POST['description'] ?? '');
    $tags = (string)($_POST['tags'] ?? '');

    if (!update_file_metadata($fileId, $title, $description, $tags)) {
        json_response(['ok' => false, 'error' => 'Could not update metadata'], 400);
    }

    json_response(['ok' => true]);
}

if ($action === 'files_bulk_trash') {
    require_editor_json();
    if (!session_can_write()) json_response(['ok' => false, 'error' => 'This session is archived'], 403);

    $files = (string)($_POST['files'] ?? '');
    $fileIds = array_values(array_filter(array_map('trim', explode(',', $files))));
    $count = bulk_move_to_trash($fileIds);

    json_response(['ok' => true, 'count' => $count]);
}

if ($action === 'files_bulk_copy_session') {
    require_editor_json();
    if (!session_can_write()) json_response(['ok' => false, 'error' => 'This session is archived'], 403);

    $files = (string)($_POST['files'] ?? '');
    $targetSessionId = (string)($_POST['target_session_id'] ?? '');
    $fileIds = array_values(array_filter(array_map('trim', explode(',', $files))));
    $result = copy_or_move_files_to_session($fileIds, $targetSessionId, false);
    json_response($result);
}

if ($action === 'files_bulk_move_session') {
    require_editor_json();
    if (!session_can_write()) json_response(['ok' => false, 'error' => 'This session is archived'], 403);

    $files = (string)($_POST['files'] ?? '');
    $targetSessionId = (string)($_POST['target_session_id'] ?? '');
    $fileIds = array_values(array_filter(array_map('trim', explode(',', $files))));
    $result = copy_or_move_files_to_session($fileIds, $targetSessionId, true);
    json_response($result);
}

if ($action === 'trash_list') {
    require_login_json();
    json_response(['ok' => true, 'items' => list_trash_items()]);
}

if ($action === 'trash_delete') {
    require_login_json();

    if (!is_admin()) {
        json_response(['ok' => false, 'error' => 'Only admins can permanently delete'], 403);
    }

    $file = (string)($_POST['file'] ?? '');
    $ack = !empty($_POST['ack_multi_session']);
    json_response(delete_file_permanently_from_trash($file, $ack));
}

if ($action === 'trash_delete_warning') {
    require_login_json();
    if (!is_admin()) {
        json_response(['ok' => false, 'error' => 'Only admins can permanently delete'], 403);
    }

    $file = (string)($_GET['file'] ?? '');
    $info = get_permanent_delete_warning($file);
    if ($info === null) json_response(['ok' => false, 'error' => 'File not found'], 404);
    json_response(['ok' => true] + $info);
}

if ($action === 'trash_restore') {
    require_editor_json();
    if (!session_can_write()) json_response(['ok' => false, 'error' => 'This session is archived'], 403);

    $file = (string)($_POST['file'] ?? '');
    if (!restore_file_from_trash($file)) {
        json_response(['ok' => false, 'error' => 'Could not restore file'], 400);
    }
    json_response(['ok' => true]);
}

if ($action === 'trash_restore_all') {
    require_editor_json();
    if (!session_can_write()) json_response(['ok' => false, 'error' => 'This session is archived'], 403);

    $items = list_trash_items();
    $count = 0;
    foreach ($items as $item) {
        if (restore_file_from_trash($item['storage_name'])) $count++;
    }
    json_response(['ok' => true, 'count' => $count]);
}

if ($action === 'trash_empty_all') {
    require_login_json();
    if (!is_admin()) {
        json_response(['ok' => false, 'error' => 'Only admins can permanently delete'], 403);
    }

    $items = list_trash_items();
    $count = 0;
    foreach ($items as $item) {
        $res = delete_file_permanently_from_trash($item['storage_name'], true);
        if (!empty($res['ok'])) $count++;
    }
    json_response(['ok' => true, 'count' => $count]);
}

if ($action === 'notes_list') {
    require_login_json();
    json_response(['ok' => true, 'tabs' => list_note_tabs()]);
}

if ($action === 'note_save') {
    require_editor_json();
    if (!session_can_write()) json_response(['ok' => false, 'error' => 'This session is archived'], 403);

    $id = (string)($_POST['id'] ?? '');
    $title = (string)($_POST['title'] ?? '');
    $content = (string)($_POST['content'] ?? '');

    if (!update_note_tab($id, $title, $content)) {
        json_response(['ok' => false, 'error' => 'Tab not found'], 404);
    }

    json_response(['ok' => true]);
}

if ($action === 'note_rename') {
    require_editor_json();
    if (!session_can_write()) json_response(['ok' => false, 'error' => 'This session is archived'], 403);

    $id = (string)($_POST['id'] ?? '');
    $title = (string)($_POST['title'] ?? '');

    if (!rename_note_tab_only($id, $title)) {
        json_response(['ok' => false, 'error' => 'Could not rename tab'], 400);
    }

    json_response(['ok' => true]);
}

if ($action === 'note_create') {
    require_editor_json();
    if (!session_can_write()) json_response(['ok' => false, 'error' => 'This session is archived'], 403);

    create_note_tab((string)($_POST['title'] ?? 'New Tab'));
    json_response(['ok' => true]);
}

if ($action === 'note_delete') {
    require_editor_json();
    if (!session_can_write()) json_response(['ok' => false, 'error' => 'This session is archived'], 403);

    $id = (string)($_POST['id'] ?? '');
    if (!delete_note_tab($id)) {
        json_response(['ok' => false, 'error' => 'Could not delete tab'], 400);
    }
    json_response(['ok' => true]);
}

if ($action === 'note_pin') {
    require_editor_json();
    if (!session_can_write()) json_response(['ok' => false, 'error' => 'This session is archived'], 403);

    $id = (string)($_POST['id'] ?? '');
    if (!toggle_note_pin($id)) {
        json_response(['ok' => false, 'error' => 'Could not pin tab'], 400);
    }
    json_response(['ok' => true]);
}

if ($action === 'note_reorder') {
    require_editor_json();
    if (!session_can_write()) json_response(['ok' => false, 'error' => 'This session is archived'], 403);

    $ids = (string)($_POST['ids'] ?? '');
    $orderedIds = array_values(array_filter(array_map('trim', explode(',', $ids))));
    reorder_note_tabs($orderedIds);
    json_response(['ok' => true]);
}

if ($action === 'note_export') {
    require_login_json();

    $id = (string)($_GET['id'] ?? '');
    $tab = get_note_tab($id);
    if (!$tab) {
        http_response_code(404);
        exit('Tab not found');
    }

    $filename = safe_filename($tab['title']) . '.txt';
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $tab['content'];
    exit;
}

if ($action === 'admin_create_token') {
    require_admin_json();

    $label = (string)($_POST['label'] ?? 'Share Link');
    $role = (string)($_POST['role'] ?? 'viewer');
    $expiresAt = (string)($_POST['expires_at'] ?? '');
    $sessionIds = isset($_POST['session_ids'])
        ? array_values(array_filter(array_map('trim', explode(',', (string)$_POST['session_ids']))))
        : [];
    $defaultSessionId = (string)($_POST['default_session_id'] ?? '');

    $token = create_access_token($label, $role, $expiresAt, $sessionIds, $defaultSessionId);

    json_response([
        'ok' => true,
        'token' => $token,
        'url' => token_share_url($token),
    ]);
}

if ($action === 'admin_disable_token') {
    require_admin_json();
    $token = (string)($_POST['token'] ?? '');
    if (!disable_access_token($token)) {
        json_response(['ok' => false, 'error' => 'Token not found'], 404);
    }
    json_response(['ok' => true]);
}

if ($action === 'admin_cleanup_expired') {
    require_admin_json();
    $count = cleanup_expired_tokens();
    json_response(['ok' => true, 'count' => $count]);
}

if ($action === 'admin_tokens') {
    require_admin_json();
    $tokens = load_access_tokens();
    $items = [];

    foreach ($tokens as $token => $row) {
        $items[] = [
            'token' => $token,
            'label' => $row['label'] ?? '',
            'role' => $row['role'] ?? 'viewer',
            'enabled' => !empty($row['enabled']),
            'created_at' => $row['created_at'] ?? '',
            'last_used_at' => $row['last_used_at'] ?? '',
            'last_used_ip' => $row['last_used_ip'] ?? '',
            'expires_at' => $row['expires_at'] ?? '',
            'expired' => token_is_expired($row),
            'use_count' => (int)($row['use_count'] ?? 0),
            'session_ids' => isset($row['session_ids']) ? array_values($row['session_ids']) : [],
            'default_session_id' => $row['default_session_id'] ?? '',
            'usage_by_session' => get_token_session_usage($token),
            'url' => token_share_url($token),
        ];
    }

    usort($items, function ($a, $b) {
        return strcmp($b['created_at'], $a['created_at']);
    });

    json_response(['ok' => true, 'items' => $items]);
}

if ($action === 'batch_download') {
    require_login_json();
    $files = $_GET['files'] ?? [];
    if (!is_array($files)) $files = [];
    send_batch_zip($files);
}
if ($action === 'trash_permanent_delete_check') {
    require_admin_json();

    $fileId = (string)($_GET['file'] ?? '');
    $check = get_trash_file_usage_check($fileId);

    if (!$check['ok']) {
        json_response($check, 400);
    }

    json_response($check);
}

if ($action === 'trash_admin_delete_permanent') {
    require_admin_json();

    $fileId = (string)($_POST['file'] ?? '');
    $ack = !empty($_POST['acknowledge_multi_session']);

    $result = admin_delete_file_permanently_from_trash($fileId, $ack);
    if (!$result['ok']) {
        json_response($result, 400);
    }

    json_response($result);
}

if ($action === 'trash_admin_delete_all_permanent') {
    require_admin_json();

    $ack = !empty($_POST['acknowledge_multi_session']);
    $items = list_trash_items();
    $count = 0;

    foreach ($items as $item) {
        $result = admin_delete_file_permanently_from_trash($item['storage_name'], $ack);
        if (!empty($result['ok'])) {
            $count++;
        }
    }

    json_response(['ok' => true, 'count' => $count]);
}

if ($action === 'session_stats') {
    require_login_json();

    $cache = load_files_cache();
    $notes = list_note_tabs();

    $totalSize = 0;
    $totalDownloads = 0;
    $tagCounts = [];

    foreach ($cache as $file) {
        $totalSize += (int)($file['size'] ?? 0);
        $totalDownloads += (int)($file['download_count'] ?? 0);

        foreach (($file['tags'] ?? []) as $tag) {
            $tag = trim((string)$tag);
            if ($tag === '') continue;
            $tagCounts[$tag] = (int)($tagCounts[$tag] ?? 0) + 1;
        }
    }

    usort($cache, function ($a, $b) {
        return (int)($b['download_count'] ?? 0) <=> (int)($a['download_count'] ?? 0);
    });

    $topFiles = array_slice(array_map(function ($file) {
        return [
            'name' => $file['name'] ?? 'File',
            'download_count' => (int)($file['download_count'] ?? 0),
            'size_text' => $file['size_text'] ?? '0 B',
        ];
    }, $cache), 0, 10);

    $topTags = [];
    foreach ($tagCounts as $name => $count) {
        $topTags[] = ['name' => $name, 'count' => $count];
    }
    usort($topTags, function ($a, $b) {
        return $b['count'] <=> $a['count'];
    });
    $topTags = array_slice($topTags, 0, 10);

    json_response([
        'ok' => true,
        'overview' => [
            'total_files' => count($cache),
            'total_note_tabs' => count($notes),
            'total_downloads' => $totalDownloads,
            'total_size_text' => format_bytes($totalSize),
            'archive_mode' => current_session_is_archived(),
        ],
        'top_files' => $topFiles,
        'top_tags' => $topTags,
    ]);
}

if ($action === 'admin_delete_session') {
    require_admin_json();

    $sessionId = (string)($_POST['session_id'] ?? '');
    $result = delete_share_session($sessionId);

    if (!$result['ok']) {
        json_response($result, 400);
    }

    json_response($result);
}
if ($action === 'trash_permanent_delete_check') {
    require_admin_json();

    $fileId = (string)($_GET['file'] ?? '');
    $check = get_trash_file_usage_check($fileId);

    if (!$check['ok']) {
        json_response($check, 400);
    }

    json_response($check);
}

if ($action === 'trash_admin_delete_permanent') {
    require_admin_json();

    $fileId = (string)($_POST['file'] ?? '');
    $ack = !empty($_POST['acknowledge_multi_session']);

    $result = admin_delete_file_permanently_from_trash($fileId, $ack);
    if (!$result['ok']) {
        json_response($result, 400);
    }

    json_response($result);
}

if ($action === 'trash_admin_delete_all_permanent') {
    require_admin_json();

    $ack = !empty($_POST['acknowledge_multi_session']);
    $items = list_trash_items();
    $count = 0;

    foreach ($items as $item) {
        $result = admin_delete_file_permanently_from_trash($item['storage_name'], $ack);
        if (!empty($result['ok'])) {
            $count++;
        }
    }

    json_response(['ok' => true, 'count' => $count]);
}

if ($action === 'session_stats') {
    require_login_json();

    $cache = load_files_cache();
    $notes = list_note_tabs();

    $totalSize = 0;
    $totalDownloads = 0;
    $tagCounts = [];

    foreach ($cache as $file) {
        $totalSize += (int)($file['size'] ?? 0);
        $totalDownloads += (int)($file['download_count'] ?? 0);

        foreach (($file['tags'] ?? []) as $tag) {
            $tag = trim((string)$tag);
            if ($tag === '') continue;
            $tagCounts[$tag] = (int)($tagCounts[$tag] ?? 0) + 1;
        }
    }

    usort($cache, function ($a, $b) {
        return (int)($b['download_count'] ?? 0) <=> (int)($a['download_count'] ?? 0);
    });

    $topFiles = array_slice(array_map(function ($file) {
        return [
            'name' => $file['name'] ?? 'File',
            'download_count' => (int)($file['download_count'] ?? 0),
            'size_text' => $file['size_text'] ?? '0 B',
        ];
    }, $cache), 0, 10);

    $topTags = [];
    foreach ($tagCounts as $name => $count) {
        $topTags[] = ['name' => $name, 'count' => $count];
    }
    usort($topTags, function ($a, $b) {
        return $b['count'] <=> $a['count'];
    });
    $topTags = array_slice($topTags, 0, 10);

    json_response([
        'ok' => true,
        'overview' => [
            'total_files' => count($cache),
            'total_note_tabs' => count($notes),
            'total_downloads' => $totalDownloads,
            'total_size_text' => format_bytes($totalSize),
            'archive_mode' => current_session_is_archived(),
        ],
        'top_files' => $topFiles,
        'top_tags' => $topTags,
    ]);
}

if ($action === 'admin_delete_session_check') {
    require_admin_json();

    $sessionId = (string)($_GET['session_id'] ?? '');
    $result = get_delete_session_impact($sessionId);
    if (!$result['ok']) {
        json_response($result, 400);
    }

    json_response($result);
}

if ($action === 'admin_delete_session') {
    require_admin_json();

    $sessionId = (string)($_POST['session_id'] ?? '');
    $confirmed = !empty($_POST['confirmed']);
    $result = delete_share_session($sessionId, $confirmed);

    if (!$result['ok']) {
        json_response($result, 400);
    }

    json_response($result);
}
json_response(['ok' => false, 'error' => 'Unknown action'], 404);