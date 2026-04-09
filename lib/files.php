<?php

function blobs_dir() {
    $dir = data_dir() . '/blobs';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    return $dir;
}

function thumbs_dir_global() {
    $dir = data_dir() . '/thumbs_global';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    return $dir;
}

function temp_zip_dir() {
    $dir = data_dir() . '/tmpzip';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    return $dir;
}

function load_session_files_index($sessionId = null) {
    return load_json(session_files_index_file($sessionId), []);
}

function save_session_files_index($sessionId, $index) {
    save_json(session_files_index_file($sessionId), $index);
}

function load_files_meta() {
    return load_session_files_index(current_session_id());
}

function save_files_meta($meta) {
    save_session_files_index(current_session_id(), $meta);
}

function load_files_cache() {
    return load_json(current_session_files_cache_file(), []);
}

function save_files_cache($cache) {
    save_json(current_session_files_cache_file(), $cache);
    bump_version('files');
}

function load_trash_meta() {
    return load_json(current_session_trash_meta_file(), []);
}

function save_trash_meta($meta) {
    save_json(current_session_trash_meta_file(), $meta);
    bump_version('files');
}

function file_blob_path($blobName) {
    return blobs_dir() . '/' . basename((string)$blobName);
}

function build_thumb_path($blobName) {
    return thumbs_dir_global() . '/' . basename((string)$blobName) . '.jpg';
}

function thumb_url($fileId) {
    return 'thumb.php?file=' . rawurlencode($fileId);
}

function download_url($fileId) {
    return 'download.php?file=' . rawurlencode($fileId);
}

function media_url($fileId) {
    return 'media.php?file=' . rawurlencode($fileId);
}

function get_file_ref($fileId, $sessionId = null) {
    $index = load_session_files_index($sessionId ?: current_session_id());
    return $index[$fileId] ?? null;
}

function save_session_files_cache($sessionId, $cache) {
    save_json(session_files_cache_file($sessionId), $cache);
}

function build_image_thumbnail($sourcePath, $destPath, $maxW = 360, $maxH = 220) {
    if (!function_exists('imagecreatefromjpeg') && !function_exists('imagecreatefrompng') && !function_exists('imagecreatefromwebp')) {
        return false;
    }

    $info = @getimagesize($sourcePath);
    if (!$info) return false;

    $mime = $info['mime'] ?? '';
    if ($mime === 'image/jpeg') $src = @imagecreatefromjpeg($sourcePath);
    elseif ($mime === 'image/png') $src = @imagecreatefrompng($sourcePath);
    elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) $src = @imagecreatefromwebp($sourcePath);
    elseif ($mime === 'image/gif') $src = @imagecreatefromgif($sourcePath);
    else return false;

    if (!$src) return false;

    $srcW = imagesx($src);
    $srcH = imagesy($src);
    if (!$srcW || !$srcH) {
        imagedestroy($src);
        return false;
    }

    $ratio = min($maxW / $srcW, $maxH / $srcH, 1);
    $newW = max(1, (int)floor($srcW * $ratio));
    $newH = max(1, (int)floor($srcH * $ratio));

    $dst = imagecreatetruecolor($newW, $newH);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 17, 24, 39));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);

    $ok = imagejpeg($dst, $destPath, 82);

    imagedestroy($src);
    imagedestroy($dst);

    return $ok;
}

function rebuild_files_cache_for_session($sessionId) {
    $index = load_session_files_index($sessionId);
    $items = [];

    foreach ($index as $fileId => $row) {
        $blobName = $row['blob_name'] ?? '';
        $path = file_blob_path($blobName);
        if (!is_file($path)) continue;

        $stat = @stat($path);
        $mime = detect_mime($path);
        $displayName = $row['display_name'] ?? 'File';
        $category = get_file_category($displayName, $mime);

        $thumbExists = false;
        if ($category === 'image') {
            $thumbPath = build_thumb_path($blobName);
            if (!is_file($thumbPath)) {
                build_image_thumbnail($path, $thumbPath);
            }
            $thumbExists = is_file($thumbPath);
        }

        $items[] = [
            'storage_name' => $fileId,
            'name' => $displayName,
            'title' => $row['title'] ?? '',
            'description' => $row['description'] ?? '',
            'tags' => isset($row['tags']) && is_array($row['tags']) ? array_values($row['tags']) : [],
            'uploader' => $row['uploader'] ?? 'Unknown',
            'uploaded_at' => $row['uploaded_at'] ?? now_text(),
            'download_count' => (int)($row['download_count'] ?? 0),
            'size' => (int)($stat['size'] ?? 0),
            'size_text' => format_bytes((int)($stat['size'] ?? 0)),
            'mtime' => (int)($stat['mtime'] ?? time()),
            'mtime_text' => date('Y-m-d H:i:s', (int)($stat['mtime'] ?? time())),
            'mime' => $mime,
            'category' => $category,
            'download_url' => download_url($fileId),
            'media_url' => media_url($fileId),
            'thumb_url' => $thumbExists ? thumb_url($fileId) : '',
            'has_thumb' => $thumbExists,
        ];
    }

    usort($items, function ($a, $b) {
        return $b['mtime'] <=> $a['mtime'];
    });

    save_session_files_cache($sessionId, $items);
    if ($sessionId === current_session_id()) bump_version('files');
}

function rebuild_files_cache() {
    rebuild_files_cache_for_session(current_session_id());
}

function all_tags_from_cache() {
    $items = load_files_cache();
    $tags = [];
    foreach ($items as $item) {
        foreach (($item['tags'] ?? []) as $tag) {
            $t = trim((string)$tag);
            if ($t !== '') $tags[$t] = true;
        }
    }
    $all = array_keys($tags);
    natcasesort($all);
    return array_values($all);
}

function filter_sort_paginate_files($search, $type, $sort, $page, $pageSize, $tag = '') {
    $items = load_files_cache();

    $search = mb_strtolower(trim((string)$search));
    $type = (string)$type;
    $sort = (string)$sort;
    $tag = trim((string)$tag);

    $items = array_values(array_filter($items, function ($item) use ($search, $type, $tag) {
        if ($type !== 'all' && $item['category'] !== $type) return false;
        if ($tag !== '') {
            $tags = isset($item['tags']) && is_array($item['tags']) ? $item['tags'] : [];
            if (!in_array($tag, $tags, true)) return false;
        }
        if ($search === '') return true;

        $haystack = mb_strtolower(implode(' ', [
            $item['name'],
            $item['title'],
            $item['description'],
            $item['uploader'],
            implode(' ', $item['tags']),
        ]));

        return strpos($haystack, $search) !== false;
    }));

    usort($items, function ($a, $b) use ($sort) {
        switch ($sort) {
            case 'oldest': return $a['mtime'] <=> $b['mtime'];
            case 'largest': return $b['size'] <=> $a['size'];
            case 'smallest': return $a['size'] <=> $b['size'];
            case 'az': return strcasecmp($a['name'], $b['name']);
            case 'za': return strcasecmp($b['name'], $a['name']);
            default: return $b['mtime'] <=> $a['mtime'];
        }
    });

    $total = count($items);
    $pages = max(1, (int)ceil($total / $pageSize));
    $page = max(1, min($page, $pages));
    $offset = ($page - 1) * $pageSize;

    return [
        'items' => array_slice($items, $offset, $pageSize),
        'page' => $page,
        'pages' => $pages,
        'total' => $total,
    ];
}

function save_uploaded_files($filesInput) {
    $uploaded = [];
    $index = load_files_meta();

    if (is_array($filesInput['name'])) {
        $count = count($filesInput['name']);
        for ($i = 0; $i < $count; $i++) {
            if ((int)$filesInput['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
            $single = [
                'name' => $filesInput['name'][$i],
                'tmp_name' => $filesInput['tmp_name'][$i],
                'error' => $filesInput['error'][$i],
                'size' => $filesInput['size'][$i],
            ];
            $uploaded[] = save_one_uploaded_file($single, $index);
        }
    } else {
        $uploaded[] = save_one_uploaded_file($filesInput, $index);
    }

    save_files_meta($index);
    rebuild_files_cache();
    add_activity('file', 'Uploaded file(s)', ['count' => count($uploaded), 'session_id' => current_session_id()]);
    return $uploaded;
}

function save_one_uploaded_file($file, &$indexStore) {
    if ((int)$file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Upload error');
    if ((int)$file['size'] > (int)cfg('max_file_size')) throw new RuntimeException('File too large');

    $original = (string)$file['name'];
    $blobName = storage_filename($original);
    $dest = file_blob_path($blobName);

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Could not save uploaded file');
    }

    $base = safe_filename(pathinfo($original, PATHINFO_FILENAME));
    $ext = pathinfo($original, PATHINFO_EXTENSION);
    $displayName = $base . ($ext ? '.' . safe_filename($ext) : '');

    $fileId = 'file_' . bin2hex(random_bytes(4));
    $indexStore[$fileId] = [
        'blob_name' => $blobName,
        'display_name' => $displayName,
        'title' => '',
        'description' => '',
        'tags' => [],
        'uploaded_at' => now_text(),
        'uploader' => current_user_label(),
        'download_count' => 0,
    ];

    return [
        'storage_name' => $fileId,
        'display_name' => $displayName,
    ];
}

function update_file_metadata($fileId, $title, $description, $tags) {
    $index = load_files_meta();
    if (!isset($index[$fileId])) return false;

    $cleanTags = array_values(array_filter(array_map(function ($t) {
        return mb_substr(trim((string)$t), 0, 30);
    }, is_array($tags) ? $tags : explode(',', (string)$tags))));

    $index[$fileId]['title'] = mb_substr(trim((string)$title), 0, 120);
    $index[$fileId]['description'] = mb_substr(trim((string)$description), 0, 2000);
    $index[$fileId]['tags'] = $cleanTags;

    save_files_meta($index);
    rebuild_files_cache();
    add_activity('file', 'Updated file metadata', ['file' => $fileId, 'session_id' => current_session_id()]);
    return true;
}

function increment_download_count($fileId) {
    $index = load_files_meta();
    if (!isset($index[$fileId])) return;
    $index[$fileId]['download_count'] = (int)($index[$fileId]['download_count'] ?? 0) + 1;
    save_files_meta($index);
    rebuild_files_cache();
}

function send_download_file($fileId) {
    $ref = get_file_ref($fileId);
    if (!$ref) {
        http_response_code(404);
        exit('File not found');
    }

    $path = file_blob_path($ref['blob_name'] ?? '');
    if (!is_file($path)) {
        http_response_code(404);
        exit('File not found');
    }

    $displayName = $ref['display_name'] ?? $fileId;
    increment_download_count($fileId);

    header('Content-Type: ' . detect_mime($path));
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $displayName) . '"; filename*=UTF-8\'\'' . rawurlencode($displayName));
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

function send_media_file($fileId) {
    $ref = get_file_ref($fileId);
    if (!$ref) {
        http_response_code(404);
        exit('File not found');
    }

    $path = file_blob_path($ref['blob_name'] ?? '');
    if (!is_file($path)) {
        http_response_code(404);
        exit('File not found');
    }

    header('Content-Type: ' . detect_mime($path));
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

function send_thumb_file($fileId) {
    $ref = get_file_ref($fileId);
    if (!$ref) {
        http_response_code(404);
        exit('File not found');
    }

    $blobName = $ref['blob_name'] ?? '';
    $path = file_blob_path($blobName);
    if (!is_file($path)) {
        http_response_code(404);
        exit('File not found');
    }

    $thumbPath = build_thumb_path($blobName);
    if (!is_file($thumbPath)) {
        build_image_thumbnail($path, $thumbPath);
    }
    if (!is_file($thumbPath)) {
        http_response_code(404);
        exit('Thumbnail not found');
    }

    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($thumbPath));
    readfile($thumbPath);
    exit;
}

function unique_zip_entry_name($desiredName, &$usedNames) {
    $desiredName = (string)$desiredName;
    $lower = mb_strtolower($desiredName);

    if (!isset($usedNames[$lower])) {
        $usedNames[$lower] = 1;
        return $desiredName;
    }

    $info = pathinfo($desiredName);
    $filename = $info['filename'] ?? $desiredName;
    $ext = isset($info['extension']) && $info['extension'] !== '' ? '.' . $info['extension'] : '';

    $n = $usedNames[$lower] + 1;
    do {
        $candidate = $filename . ' (' . $n . ')' . $ext;
        $candidateLower = mb_strtolower($candidate);
        $n++;
    } while (isset($usedNames[$candidateLower]));

    $usedNames[$candidateLower] = 1;
    $usedNames[$lower] = $n - 1;

    return $candidate;
}

function send_batch_zip($fileIds) {
    $valid = [];
    $index = load_files_meta();

    foreach ($fileIds as $fileId) {
        $row = $index[$fileId] ?? null;
        if (!$row) continue;
        $path = file_blob_path($row['blob_name'] ?? '');
        if (is_file($path)) {
            $valid[$fileId] = [
                'display_name' => $row['display_name'] ?? $fileId,
                'path' => $path,
            ];
        }
    }

    if (!$valid) {
        http_response_code(400);
        exit('No valid files selected.');
    }
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        exit('ZipArchive not enabled.');
    }

    $zipPath = temp_zip_dir() . '/batch_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.zip';
    $zip = new ZipArchive();
    $openResult = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($openResult !== true) {
        http_response_code(500);
        exit('Could not create zip archive.');
    }

    $usedNames = [];
    foreach ($valid as $fileId => $row) {
        $entryName = unique_zip_entry_name($row['display_name'], $usedNames);
        $zip->addFile($row['path'], $entryName);
    }

    if (!$zip->close()) {
        if (is_file($zipPath)) @unlink($zipPath);
        http_response_code(500);
        exit('Could not finalize zip archive.');
    }

    clearstatcache(true, $zipPath);
    $zipSize = filesize($zipPath);
    if ($zipSize === false || $zipSize <= 0) {
        @unlink($zipPath);
        http_response_code(500);
        exit('Zip file was created empty.');
    }

    foreach (array_keys($valid) as $fileId) {
        increment_download_count($fileId);
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="selected-files-' . date('Ymd-His') . '.zip"');
    header('Content-Length: ' . $zipSize);

    $fp = fopen($zipPath, 'rb');
    if ($fp) {
        while (!feof($fp)) echo fread($fp, 8192);
        fclose($fp);
    }

    @unlink($zipPath);
    exit;
}

function move_file_to_trash($fileId) {
    $index = load_files_meta();
    if (!isset($index[$fileId])) return false;

    $trash = load_trash_meta();
    $trash[$fileId] = [
        'file_ref' => $index[$fileId],
        'deleted_at' => now_text(),
    ];
    unset($index[$fileId]);

    save_files_meta($index);
    save_trash_meta($trash);
    rebuild_files_cache();

    add_activity('file', 'Moved file to trash', ['file' => $fileId, 'session_id' => current_session_id()]);
    return true;
}

function bulk_move_to_trash($fileIds) {
    $count = 0;
    foreach ($fileIds as $fileId) {
        if (move_file_to_trash($fileId)) $count++;
    }
    return $count;
}

function list_trash_items() {
    $items = [];
    $trash = load_trash_meta();

    foreach ($trash as $fileId => $row) {
        $ref = $row['file_ref'] ?? [];
        $blobName = $ref['blob_name'] ?? '';
        $path = file_blob_path($blobName);
        $size = is_file($path) ? filesize($path) : 0;

        $items[] = [
            'storage_name' => $fileId,
            'name' => $ref['display_name'] ?? $fileId,
            'title' => $ref['title'] ?? '',
            'description' => $ref['description'] ?? '',
            'tags' => isset($ref['tags']) && is_array($ref['tags']) ? $ref['tags'] : [],
            'deleted_at' => $row['deleted_at'] ?? '',
            'size_text' => format_bytes((int)$size),
            'blob_name' => $blobName,
            'shared_session_count' => $blobName !== '' ? count_sessions_using_blob($blobName) : 0,
            'shared_session_names' => $blobName !== '' ? list_session_names_using_blob($blobName) : [],
        ];
    }

    usort($items, function ($a, $b) {
        return strcmp($b['deleted_at'], $a['deleted_at']);
    });

    return $items;
}

function restore_file_from_trash($fileId) {
    $trash = load_trash_meta();
    if (!isset($trash[$fileId])) return false;

    $index = load_files_meta();
    $index[$fileId] = $trash[$fileId]['file_ref'];
    unset($trash[$fileId]);

    save_files_meta($index);
    save_trash_meta($trash);
    rebuild_files_cache();

    add_activity('file', 'Restored file from trash', ['file' => $fileId, 'session_id' => current_session_id()]);
    return true;
}

function blob_is_referenced_anywhere($blobName) {
    foreach (list_share_sessions() as $session) {
        $index = load_json(session_files_index_file($session['id']), []);
        foreach ($index as $row) {
            if (($row['blob_name'] ?? '') === $blobName) return true;
        }

        $trash = load_json(session_trash_meta_file($session['id']), []);
        foreach ($trash as $row) {
            $ref = $row['file_ref'] ?? [];
            if (($ref['blob_name'] ?? '') === $blobName) return true;
        }
    }
    return false;
}

function blob_is_referenced_anywhere_except_session($blobName, $excludedSessionId) {
    foreach (list_share_sessions() as $session) {
        if (($session['id'] ?? '') === $excludedSessionId) continue;

        $index = load_json(session_files_index_file($session['id']), []);
        foreach ($index as $row) {
            if (($row['blob_name'] ?? '') === $blobName) return true;
        }

        $trash = load_json(session_trash_meta_file($session['id']), []);
        foreach ($trash as $row) {
            $ref = $row['file_ref'] ?? [];
            if (($ref['blob_name'] ?? '') === $blobName) return true;
        }
    }
    return false;
}

function count_sessions_using_blob($blobName) {
    $count = 0;
    foreach (list_share_sessions() as $session) {
        $found = false;
        $index = load_json(session_files_index_file($session['id']), []);
        foreach ($index as $row) {
            if (($row['blob_name'] ?? '') === $blobName) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $trash = load_json(session_trash_meta_file($session['id']), []);
            foreach ($trash as $row) {
                $ref = $row['file_ref'] ?? [];
                if (($ref['blob_name'] ?? '') === $blobName) {
                    $found = true;
                    break;
                }
            }
        }
        if ($found) $count++;
    }
    return $count;
}

function list_session_names_using_blob($blobName) {
    $names = [];
    foreach (list_share_sessions() as $session) {
        $found = false;
        $index = load_json(session_files_index_file($session['id']), []);
        foreach ($index as $row) {
            if (($row['blob_name'] ?? '') === $blobName) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $trash = load_json(session_trash_meta_file($session['id']), []);
            foreach ($trash as $row) {
                $ref = $row['file_ref'] ?? [];
                if (($ref['blob_name'] ?? '') === $blobName) {
                    $found = true;
                    break;
                }
            }
        }
        if ($found) $names[] = $session['name'] ?? $session['id'];
    }
    return $names;
}

function get_permanent_delete_warning($fileId) {
    $trash = load_trash_meta();
    if (!isset($trash[$fileId])) return null;

    $blobName = $trash[$fileId]['file_ref']['blob_name'] ?? '';
    if ($blobName === '') return null;

    return [
        'shared_session_count' => count_sessions_using_blob($blobName),
        'shared_session_names' => list_session_names_using_blob($blobName),
    ];
}

function delete_file_permanently_from_trash($fileId, $acknowledgeMultiSession = false) {
    $trash = load_trash_meta();
    if (!isset($trash[$fileId])) return ['ok' => false, 'error' => 'File not found in trash'];

    $blobName = $trash[$fileId]['file_ref']['blob_name'] ?? '';
    $sharedCount = $blobName !== '' ? count_sessions_using_blob($blobName) : 0;

    if ($sharedCount > 1 && !$acknowledgeMultiSession) {
        return [
            'ok' => false,
            'error' => 'File blob is used by multiple sessions',
            'needs_acknowledgement' => true,
            'shared_session_count' => $sharedCount,
            'shared_session_names' => list_session_names_using_blob($blobName),
        ];
    }

    unset($trash[$fileId]);
    save_trash_meta($trash);

    if ($blobName !== '' && !blob_is_referenced_anywhere($blobName)) {
        $path = file_blob_path($blobName);
        if (is_file($path)) @unlink($path);

        $thumb = build_thumb_path($blobName);
        if (is_file($thumb)) @unlink($thumb);
    }

    add_activity('file', 'Deleted file permanently', ['file' => $fileId, 'session_id' => current_session_id()]);
    return ['ok' => true];
}

function copy_or_move_files_to_session($fileIds, $targetSessionId, $removeSource = false) {
    $allowed = allowed_session_ids_for_current_user();
    if (!in_array($targetSessionId, $allowed, true) && !is_admin()) {
        return ['ok' => false, 'error' => 'Target session not allowed'];
    }

    $sourceSessionId = current_session_id();
    if ($sourceSessionId === $targetSessionId && !$removeSource) {
        return ['ok' => false, 'error' => 'Source and target are the same'];
    }

    $sourceIndex = load_session_files_index($sourceSessionId);
    $targetIndex = load_session_files_index($targetSessionId);

    $count = 0;
    foreach ($fileIds as $fileId) {
        if (!isset($sourceIndex[$fileId])) continue;

        $sourceRef = $sourceIndex[$fileId];
        $newFileId = 'file_' . bin2hex(random_bytes(4));
        $targetIndex[$newFileId] = [
            'blob_name' => $sourceRef['blob_name'] ?? '',
            'display_name' => $sourceRef['display_name'] ?? 'File',
            'title' => $sourceRef['title'] ?? '',
            'description' => $sourceRef['description'] ?? '',
            'tags' => isset($sourceRef['tags']) && is_array($sourceRef['tags']) ? array_values($sourceRef['tags']) : [],
            'uploader' => $sourceRef['uploader'] ?? current_user_label(),
            'uploaded_at' => now_text(),
            'download_count' => 0,
        ];

        if ($removeSource) {
            unset($sourceIndex[$fileId]);
        }
        $count++;
    }

    save_session_files_index($targetSessionId, $targetIndex);
    rebuild_files_cache_for_session($targetSessionId);

    if ($removeSource) {
        save_session_files_index($sourceSessionId, $sourceIndex);
        rebuild_files_cache_for_session($sourceSessionId);
        add_activity('file', 'Moved files between sessions', ['count' => $count, 'from' => $sourceSessionId, 'to' => $targetSessionId]);
    } else {
        add_activity('file', 'Copied files between sessions', ['count' => $count, 'from' => $sourceSessionId, 'to' => $targetSessionId]);
    }

    return ['ok' => true, 'count' => $count];
function get_blob_reference_sessions($blobName) {
    $sessions = [];

    foreach (list_share_sessions() as $session) {
        $index = load_json(session_files_index_file($session['id']), []);
        foreach ($index as $row) {
            if (($row['blob_name'] ?? '') === $blobName) {
                $sessions[] = $session['name'] ?? $session['id'];
                break;
            }
        }

        $trash = load_json(session_trash_meta_file($session['id']), []);
        foreach ($trash as $row) {
            $ref = $row['file_ref'] ?? [];
            if (($ref['blob_name'] ?? '') === $blobName) {
                $sessions[] = $session['name'] ?? $session['id'];
                break;
            }
        }
    }

    return array_values(array_unique($sessions));
}

function get_trash_file_usage_check($fileId) {
    $trash = load_trash_meta();
    if (!isset($trash[$fileId])) {
        return ['ok' => false, 'error' => 'Trash item not found'];
    }

    $ref = $trash[$fileId]['file_ref'] ?? [];
    $blobName = $ref['blob_name'] ?? '';
    if ($blobName === '') {
        return ['ok' => false, 'error' => 'Blob info missing'];
    }

    $sessions = get_blob_reference_sessions($blobName);

    return [
        'ok' => true,
        'file_name' => $ref['display_name'] ?? $fileId,
        'blob_name' => $blobName,
        'referenced_session_count' => count($sessions),
        'sessions_using_blob' => $sessions,
    ];
}

function admin_delete_file_permanently_from_trash($fileId, $acknowledgeMultiSession) {
    $check = get_trash_file_usage_check($fileId);
    if (empty($check['ok'])) {
        return $check;
    }

    if (!$acknowledgeMultiSession) {
        return ['ok' => false, 'error' => 'Acknowledgement required'];
    }

    if (!delete_file_permanently_from_trash($fileId)) {
        return ['ok' => false, 'error' => 'Could not permanently delete file'];
    }

    return ['ok' => true];
}
function get_blob_reference_sessions($blobName) {
    $sessions = [];

    foreach (list_share_sessions() as $session) {
        $index = load_json(session_files_index_file($session['id']), []);
        foreach ($index as $row) {
            if (($row['blob_name'] ?? '') === $blobName) {
                $sessions[] = $session['name'] ?? $session['id'];
                break;
            }
        }

        $trash = load_json(session_trash_meta_file($session['id']), []);
        foreach ($trash as $row) {
            $ref = $row['file_ref'] ?? [];
            if (($ref['blob_name'] ?? '') === $blobName) {
                $sessions[] = $session['name'] ?? $session['id'];
                break;
            }
        }
    }

    return array_values(array_unique($sessions));
}

function get_trash_file_usage_check($fileId) {
    $trash = load_trash_meta();
    if (!isset($trash[$fileId])) {
        return ['ok' => false, 'error' => 'Trash item not found'];
    }

    $ref = $trash[$fileId]['file_ref'] ?? [];
    $blobName = $ref['blob_name'] ?? '';
    if ($blobName === '') {
        return ['ok' => false, 'error' => 'Blob info missing'];
    }

    $sessions = get_blob_reference_sessions($blobName);

    return [
        'ok' => true,
        'file_name' => $ref['display_name'] ?? $fileId,
        'blob_name' => $blobName,
        'referenced_session_count' => count($sessions),
        'sessions_using_blob' => $sessions,
    ];
}

function admin_delete_file_permanently_from_trash($fileId, $acknowledgeMultiSession) {
    $check = get_trash_file_usage_check($fileId);
    if (empty($check['ok'])) {
        return $check;
    }

    if (!$acknowledgeMultiSession) {
        return ['ok' => false, 'error' => 'Acknowledgement required'];
    }

    if (!delete_file_permanently_from_trash($fileId)) {
        return ['ok' => false, 'error' => 'Could not permanently delete file'];
    }

    return ['ok' => true];
}
    }