<?php

function share_sessions_file() {
    return data_dir() . '/share_sessions.json';
}

function ensure_sessions_storage() {
    if (!file_exists(share_sessions_file())) {
        save_json(share_sessions_file(), [[
            'id' => 'session_main',
            'name' => 'Main Session',
            'description' => 'Default shared space',
            'brand_title' => 'Main Session',
            'brand_color' => '#2563eb',
            'accent_color' => '#0f172a',
            'archive_mode' => false,
            'created_at' => now_text(),
        ]]);
    }

    foreach (list_share_sessions() as $session) {
        ensure_session_storage($session['id']);
    }
}

function list_share_sessions() {
    $items = load_json(share_sessions_file(), []);
    return is_array($items) ? $items : [];
}

function get_share_session($id) {
    foreach (list_share_sessions() as $session) {
        if (($session['id'] ?? '') === $id) return $session;
    }
    return null;
}

function default_share_session_id() {
    $sessions = list_share_sessions();
    return $sessions[0]['id'] ?? 'session_main';
}

function create_share_session($name, $description = '', $brandTitle = '', $brandColor = '#2563eb', $accentColor = '#0f172a') {
    $sessions = list_share_sessions();
    $id = 'session_' . bin2hex(random_bytes(4));

    $sessions[] = [
        'id' => $id,
        'name' => mb_substr(trim((string)$name) ?: 'Untitled Session', 0, 80),
        'description' => mb_substr(trim((string)$description), 0, 300),
        'brand_title' => mb_substr(trim((string)$brandTitle) ?: trim((string)$name), 0, 80),
        'brand_color' => trim((string)$brandColor) ?: '#2563eb',
        'accent_color' => trim((string)$accentColor) ?: '#0f172a',
        'archive_mode' => false,
        'created_at' => now_text(),
    ];

    save_json(share_sessions_file(), $sessions);
    ensure_session_storage($id);
    add_activity('session', 'Created share session', ['session_id' => $id]);

    return $id;
}

function update_share_session($id, $payload) {
    $sessions = list_share_sessions();
    $updated = false;

    foreach ($sessions as &$session) {
        if (($session['id'] ?? '') !== $id) continue;

        $session['name'] = mb_substr(trim((string)($payload['name'] ?? $session['name'] ?? 'Untitled Session')), 0, 80);
        $session['description'] = mb_substr(trim((string)($payload['description'] ?? $session['description'] ?? '')), 0, 300);
        $session['brand_title'] = mb_substr(trim((string)($payload['brand_title'] ?? $session['brand_title'] ?? $session['name'])), 0, 80);
        $session['brand_color'] = trim((string)($payload['brand_color'] ?? $session['brand_color'] ?? '#2563eb')) ?: '#2563eb';
        $session['accent_color'] = trim((string)($payload['accent_color'] ?? $session['accent_color'] ?? '#0f172a')) ?: '#0f172a';
        $session['archive_mode'] = !empty($payload['archive_mode']);
        $updated = true;
        break;
    }

    if (!$updated) return false;

    save_json(share_sessions_file(), $sessions);
    add_activity('session', 'Updated share session', ['session_id' => $id]);
    return true;
}

function session_data_dir($sessionId = null) {
    $id = $sessionId ?: current_session_id();
    return data_dir() . '/sessions/' . basename($id);
}

function session_notes_file($sessionId = null) {
    return session_data_dir($sessionId) . '/notes.json';
}

function session_files_index_file($sessionId = null) {
    return session_data_dir($sessionId) . '/files_index.json';
}

function session_files_cache_file($sessionId = null) {
    return session_data_dir($sessionId) . '/files_cache.json';
}

function session_trash_meta_file($sessionId = null) {
    return session_data_dir($sessionId) . '/trash_meta.json';
}

function ensure_session_storage($sessionId = null) {
    $dir = session_data_dir($sessionId);
    if (!is_dir($dir)) mkdir($dir, 0777, true);

    if (!file_exists(session_notes_file($sessionId))) {
        save_json(session_notes_file($sessionId), [
            'tabs' => [[
                'id' => 'tab_' . time(),
                'title' => 'Shared Note',
                'content' => '',
                'updated_at' => now_text(),
                'pinned' => false,
                'sort_order' => 1,
            ]]
        ]);
    }

    if (!file_exists(session_files_index_file($sessionId))) save_json(session_files_index_file($sessionId), []);
    if (!file_exists(session_files_cache_file($sessionId))) save_json(session_files_cache_file($sessionId), []);
    if (!file_exists(session_trash_meta_file($sessionId))) save_json(session_trash_meta_file($sessionId), []);
}

function current_session_notes_file() {
    ensure_session_storage();
    return session_notes_file();
}

function current_session_files_index_file() {
    ensure_session_storage();
    return session_files_index_file();
}

function current_session_files_cache_file() {
    ensure_session_storage();
    return session_files_cache_file();
}

function current_session_trash_meta_file() {
    ensure_session_storage();
    return session_trash_meta_file();
}

function allowed_session_ids_for_current_user() {
    if (is_admin()) {
        return array_map(function ($s) { return $s['id']; }, list_share_sessions());
    }

    $ids = $_SESSION['allowed_session_ids'] ?? [];
    if (!is_array($ids) || !$ids) return [default_share_session_id()];
    return array_values(array_unique(array_filter($ids)));
}

function bootstrap_session_context() {
    if (!is_logged_in()) return;

    $allowed = allowed_session_ids_for_current_user();
    if (!$allowed) {
        $allowed = [default_share_session_id()];
        $_SESSION['allowed_session_ids'] = $allowed;
    }

    $current = $_SESSION['current_session_id'] ?? '';
    if ($current === '' || !in_array($current, $allowed, true)) {
        $_SESSION['current_session_id'] = $allowed[0];
    }

    ensure_session_storage($_SESSION['current_session_id']);
}

function current_session_id() {
    return $_SESSION['current_session_id'] ?? default_share_session_id();
}

function current_session_info() {
    return get_share_session(current_session_id());
}

function current_session_is_archived() {
    $session = current_session_info();
    return !empty($session['archive_mode']);
}

function current_session_brand_title() {
    $session = current_session_info();
    return $session['brand_title'] ?? ($session['name'] ?? 'Shared Session');
}

function current_session_brand_color() {
    $session = current_session_info();
    return $session['brand_color'] ?? '#2563eb';
}

function current_session_accent_color() {
    $session = current_session_info();
    return $session['accent_color'] ?? '#0f172a';
}

function session_can_write() {
    if (!can_edit()) return false;
    if (is_admin()) return true;
    return !current_session_is_archived();
}

function switch_current_session($sessionId) {
    $allowed = allowed_session_ids_for_current_user();
    if (!in_array($sessionId, $allowed, true)) return false;

    $_SESSION['current_session_id'] = $sessionId;
    ensure_session_storage($sessionId);

    if (!empty($_SESSION['access_token']) && function_exists('increment_token_session_usage')) {
        increment_token_session_usage((string)$_SESSION['access_token'], $sessionId);
    }

    return true;
}

function clone_share_session($sourceId, $newName) {
    $source = get_share_session($sourceId);
    if (!$source) return false;

    $newId = create_share_session(
        $newName ?: (($source['name'] ?? 'Session') . ' Copy'),
        $source['description'] ?? '',
        $source['brand_title'] ?? ($source['name'] ?? 'Session'),
        $source['brand_color'] ?? '#2563eb',
        $source['accent_color'] ?? '#0f172a'
    );

    update_share_session($newId, [
        'archive_mode' => !empty($source['archive_mode']),
    ]);

    $sourceNotes = load_json(session_notes_file($sourceId), ['tabs' => []]);
    save_json(session_notes_file($newId), $sourceNotes);

    $sourceFiles = load_json(session_files_index_file($sourceId), []);
    $clonedFiles = [];
    foreach ($sourceFiles as $fileId => $row) {
        $newFileId = 'file_' . bin2hex(random_bytes(4));
        $clonedFiles[$newFileId] = [
            'blob_name' => $row['blob_name'] ?? '',
            'display_name' => $row['display_name'] ?? 'File',
            'title' => $row['title'] ?? '',
            'description' => $row['description'] ?? '',
            'tags' => isset($row['tags']) && is_array($row['tags']) ? array_values($row['tags']) : [],
            'uploader' => $row['uploader'] ?? 'Unknown',
            'uploaded_at' => now_text(),
            'download_count' => 0,
        ];
    }
    save_json(session_files_index_file($newId), $clonedFiles);
    save_json(session_trash_meta_file($newId), []);
    save_json(session_files_cache_file($newId), []);
    if (function_exists('rebuild_files_cache_for_session')) {
        rebuild_files_cache_for_session($newId);
    }

    add_activity('session', 'Cloned share session', ['source_id' => $sourceId, 'new_id' => $newId]);
    return $newId;
}

function token_references_session($sessionId) {
    $tokens = load_access_tokens();
    foreach ($tokens as $token => $row) {
        $ids = isset($row['session_ids']) && is_array($row['session_ids']) ? $row['session_ids'] : [];
        if (in_array($sessionId, $ids, true)) return true;
    }
    return false;
}

function delete_share_session($sessionId) {
    $all = list_share_sessions();
    if (count($all) <= 1) {
        return ['ok' => false, 'error' => 'At least one session must remain'];
    }
    if (!get_share_session($sessionId)) {
        return ['ok' => false, 'error' => 'Session not found'];
    }
    if (token_references_session($sessionId)) {
        return ['ok' => false, 'error' => 'Remove this session from tokens first'];
    }

    $fileIndex = load_json(session_files_index_file($sessionId), []);
    $trashIndex = load_json(session_trash_meta_file($sessionId), []);

    foreach ($trashIndex as $fileId => $row) {
        $blobName = $row['file_ref']['blob_name'] ?? '';
        if ($blobName !== '' && function_exists('blob_is_referenced_anywhere_except_session')) {
            if (!blob_is_referenced_anywhere_except_session($blobName, $sessionId)) {
                $path = file_blob_path($blobName);
                if (is_file($path)) @unlink($path);
                $thumb = build_thumb_path($blobName);
                if (is_file($thumb)) @unlink($thumb);
            }
        }
    }

    $sessions = [];
    foreach ($all as $row) {
        if (($row['id'] ?? '') !== $sessionId) $sessions[] = $row;
    }
    save_json(share_sessions_file(), $sessions);

    $dir = session_data_dir($sessionId);
    if (is_dir($dir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) {
            if ($fileinfo->isDir()) @rmdir($fileinfo->getRealPath());
            else @unlink($fileinfo->getRealPath());
        }
        @rmdir($dir);
    }

    if (current_session_id() === $sessionId) {
        $_SESSION['current_session_id'] = default_share_session_id();
    }

    add_activity('session', 'Deleted share session', ['session_id' => $sessionId]);
    return ['ok' => true];
}

function get_session_stats($sessionId) {
    $session = get_share_session($sessionId);
    if (!$session) return null;

    $index = load_json(session_files_index_file($sessionId), []);
    $trash = load_json(session_trash_meta_file($sessionId), []);
    $notes = load_json(session_notes_file($sessionId), ['tabs' => []]);

    $fileCount = count($index);
    $trashCount = count($trash);
    $noteCount = count($notes['tabs'] ?? []);
    $totalDownloads = 0;
    $totalBytes = 0;

    foreach ($index as $fileId => $row) {
        $totalDownloads += (int)($row['download_count'] ?? 0);
        $blobName = $row['blob_name'] ?? '';
        $path = file_blob_path($blobName);
        if (is_file($path)) $totalBytes += (int)filesize($path);
    }

    return [
        'session_id' => $sessionId,
        'name' => $session['name'] ?? 'Session',
        'brand_title' => $session['brand_title'] ?? '',
        'archive_mode' => !empty($session['archive_mode']),
        'file_count' => $fileCount,
        'trash_count' => $trashCount,
        'note_count' => $noteCount,
        'total_downloads' => $totalDownloads,
        'total_size_bytes' => $totalBytes,
        'total_size_text' => format_bytes($totalBytes),
    ];
}

function list_all_session_stats() {
    $rows = [];
    foreach (list_share_sessions() as $session) {
        $stat = get_session_stats($session['id']);
        if ($stat) $rows[] = $stat;
    }
    return $rows;
function delete_share_session($sessionId) {
    $sessionId = trim((string)$sessionId);
    if ($sessionId === '') {
        return ['ok' => false, 'error' => 'Session id is required'];
    }

    $sessions = list_share_sessions();
    if (count($sessions) <= 1) {
        return ['ok' => false, 'error' => 'You must keep at least one session'];
    }

    $target = get_share_session($sessionId);
    if (!$target) {
        return ['ok' => false, 'error' => 'Session not found'];
    }

    $defaultId = default_share_session_id();
    if ($sessionId === $defaultId) {
        return ['ok' => false, 'error' => 'Cannot delete the default session'];
    }

    $sessionDir = session_data_dir($sessionId);
    if (is_dir($sessionDir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sessionDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }

        @rmdir($sessionDir);
    }

    $remaining = array_values(array_filter($sessions, function ($s) use ($sessionId) {
        return ($s['id'] ?? '') !== $sessionId;
    }));
    save_json(share_sessions_file(), $remaining);

    $tokens = load_access_tokens();
    foreach ($tokens as $token => $row) {
        $ids = isset($row['session_ids']) && is_array($row['session_ids']) ? array_values($row['session_ids']) : [];
        $ids = array_values(array_filter($ids, function ($id) use ($sessionId) {
            return $id !== $sessionId;
        }));

        if (!$ids) {
            $tokens[$token]['enabled'] = false;
            $tokens[$token]['session_ids'] = [];
            $tokens[$token]['default_session_id'] = '';
            continue;
        }

        $tokens[$token]['session_ids'] = $ids;
        if (($tokens[$token]['default_session_id'] ?? '') === $sessionId) {
            $tokens[$token]['default_session_id'] = $ids[0];
        }
    }
    save_access_tokens($tokens);

    add_activity('session', 'Deleted share session', ['session_id' => $sessionId]);

    return ['ok' => true];
}
function get_delete_session_impact($sessionId) {
    $sessionId = trim((string)$sessionId);
    $session = get_share_session($sessionId);
    if (!$session) {
        return ['ok' => false, 'error' => 'Session not found'];
    }

    $sessions = list_share_sessions();
    if (count($sessions) <= 1) {
        return ['ok' => false, 'error' => 'You must keep at least one session'];
    }

    if ($sessionId === default_share_session_id()) {
        return ['ok' => false, 'error' => 'Cannot delete the default session'];
    }

    $notes = load_json(session_notes_file($sessionId), ['tabs' => []]);
    $files = load_json(session_files_index_file($sessionId), []);
    $trash = load_json(session_trash_meta_file($sessionId), []);
    $tokens = load_access_tokens();

    $affectedTokens = [];
    $tokensDisabled = 0;
    $tokensReassigned = 0;

    foreach ($tokens as $token => $row) {
        $ids = isset($row['session_ids']) && is_array($row['session_ids']) ? array_values($row['session_ids']) : [];
        if (!in_array($sessionId, $ids, true)) continue;

        $remaining = array_values(array_filter($ids, function ($id) use ($sessionId) {
            return $id !== $sessionId;
        }));

        $affectedTokens[] = [
            'label' => $row['label'] ?? $token,
            'token' => $token,
            'remaining_session_count' => count($remaining),
            'will_disable' => count($remaining) === 0,
            'will_reassign_default' => (($row['default_session_id'] ?? '') === $sessionId) && count($remaining) > 0,
        ];

        if (count($remaining) === 0) $tokensDisabled++;
        elseif (($row['default_session_id'] ?? '') === $sessionId) $tokensReassigned++;
    }

    return [
        'ok' => true,
        'session' => $session,
        'note_tab_count' => count($notes['tabs'] ?? []),
        'file_count' => count($files),
        'trash_count' => count($trash),
        'affected_tokens' => $affectedTokens,
        'affected_token_count' => count($affectedTokens),
        'tokens_disabled_count' => $tokensDisabled,
        'tokens_reassigned_count' => $tokensReassigned,
    ];
}

function delete_share_session($sessionId, $confirmed = false) {
    $impact = get_delete_session_impact($sessionId);
    if (empty($impact['ok'])) {
        return $impact;
    }

    if (!$confirmed) {
        return ['ok' => false, 'error' => 'Confirmation required'];
    }

    $sessions = list_share_sessions();
    $sessionDir = session_data_dir($sessionId);

    if (is_dir($sessionDir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sessionDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }

        @rmdir($sessionDir);
    }

    $remaining = array_values(array_filter($sessions, function ($s) use ($sessionId) {
        return ($s['id'] ?? '') !== $sessionId;
    }));
    save_json(share_sessions_file(), $remaining);

    $tokens = load_access_tokens();
    foreach ($tokens as $token => $row) {
        $ids = isset($row['session_ids']) && is_array($row['session_ids']) ? array_values($row['session_ids']) : [];
        $ids = array_values(array_filter($ids, function ($id) use ($sessionId) {
            return $id !== $sessionId;
        }));

        if (!$ids) {
            $tokens[$token]['enabled'] = false;
            $tokens[$token]['session_ids'] = [];
            $tokens[$token]['default_session_id'] = '';
            continue;
        }

        $tokens[$token]['session_ids'] = $ids;
        if (($tokens[$token]['default_session_id'] ?? '') === $sessionId) {
            $tokens[$token]['default_session_id'] = $ids[0];
        }
    }
    save_access_tokens($tokens);

    add_activity('session', 'Deleted share session', ['session_id' => $sessionId]);

    return ['ok' => true];
}
}