<?php
require __DIR__ . '/bootstrap.php';

if (!is_logged_in()) {
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= h(cfg('site_name')) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="auth-body">
    <div class="auth-card">
        <h1><?= h(cfg('site_name')) ?></h1>
        <p>Open the unique share URL you were given.</p>
        <p class="muted">This app uses secure access links instead of a normal password screen.</p>
    </div>
</body>
</html>
<?php
    exit;
}

$sessionTitle = current_session_brand_title();
$brandColor = current_session_brand_color();
$accentColor = current_session_accent_color();
$sessionArchived = current_session_is_archived();
$sessionWritable = session_can_write();
$currentSession = current_session_info();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= h($sessionTitle) ?> - <?= h(cfg('site_name')) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="assets/style.css">
    <style>
      :root{
        --primary: <?= h($brandColor) ?>;
        --panel-2: <?= h($accentColor) ?>;
      }
    </style>
</head>
<body>
<div class="app-shell">
    <header class="topbar">
        <div>
            <h1><?= h($sessionTitle) ?></h1>
            <div class="topbar-sub">
                <span><?= h(current_user_label()) ?></span>
                <span class="dot"></span>
                <span id="currentSessionLabel"><?= h($currentSession['name'] ?? 'Session') ?></span>
                <?php if ($sessionArchived): ?>
                    <span class="dot"></span>
                    <span>Archive Mode</span>
                <?php endif; ?>
                <span class="dot"></span>
                <span id="liveStatus">Syncing…</span>
            </div>
        </div>

        <div class="top-actions">
            <?php
            $allowedSessionIds = allowed_session_ids_for_current_user();
            $allowedSessions = array_values(array_filter(list_share_sessions(), function ($s) use ($allowedSessionIds) {
                return in_array($s['id'], $allowedSessionIds, true);
            }));
            ?>
            <select id="sessionSwitcher">
                <?php foreach ($allowedSessions as $s): ?>
                    <option value="<?= h($s['id']) ?>" <?= current_session_id() === $s['id'] ? 'selected' : '' ?>>
                        <?= h($s['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php if (is_admin()): ?>
                <button id="renameSessionBtn" class="ghost-btn primary-soft" type="button">Edit Session</button>
            <?php endif; ?>

            <a class="ghost-btn cyan" href="media.php">Media</a>
            <a class="ghost-btn" href="trash.php">Trash</a>
            <a class="ghost-btn teal" href="session_stats.php">Stats</a>
            <a class="ghost-btn danger" href="logout.php">Logout</a>

<?php if (is_admin()): ?>
                <a class="ghost-btn info" href="<?= h(cfg('admin_secret_slug')) ?>.php">Admin</a>
            <?php endif; ?>
        </div>
    </header>

    <main class="main-layout">
        <section class="notes-panel card">
            <div class="section-head">
                <div>
                    <h2>Shared Text Tabs</h2>
                    <p class="muted">Current session only</p>
                </div>
                <div class="row-wrap">
                    <span id="noteSaveState" class="status-chip"><?= $sessionArchived ? 'Read Only' : 'Ready' ?></span>
                    <button id="exportNoteBtn" class="ghost-btn" type="button">Export TXT</button>
                    <?php if ($sessionWritable): ?>
                        <button id="newTabBtn" class="primary-btn" type="button">New Tab</button>
                    <?php endif; ?>
                </div>
            </div>

            <div id="noteTabsBar" class="tabs-bar"></div>

            <div class="note-editor-wrap">
                <input id="noteTitle" class="note-title" type="text" placeholder="Tab title" <?= $sessionWritable ? '' : 'readonly' ?>>
                <textarea id="noteContent" class="note-content" placeholder="Write shared text here..." <?= $sessionWritable ? '' : 'readonly' ?>></textarea>
            </div>

            <?php if ($sessionWritable): ?>
            <div class="note-actions">
                <button id="saveNoteBtn" class="primary-btn" type="button">Save</button>
                <button id="pinTabBtn" class="ghost-btn" type="button">Pin / Unpin</button>
                <button id="deleteTabBtn" class="ghost-btn danger" type="button">Delete Tab</button>
            </div>
            <?php endif; ?>
        </section>

        <section class="files-panel card">
            <div class="section-head">
                <div>
                    <h2>Files</h2>
                    <p class="muted">Only files from the current share session</p>
                </div>
                <div class="view-toggle">
                    <button id="listViewBtn" class="ghost-btn active" type="button">List</button>
                    <button id="galleryViewBtn" class="ghost-btn" type="button">Gallery</button>
                </div>
            </div>

            <?php if ($sessionWritable): ?>
            <form id="uploadForm" class="upload-box">
                <input id="sharedFile" name="shared_file[]" type="file" multiple class="hidden-input">

                <div id="dropZone" class="drop-zone">
                    <div class="drop-title">Drop file(s) here or click to choose</div>
                    <div class="drop-sub">You can select multiple files, reorder them, or paste images</div>
                </div>

                <div id="duplicateWarning" class="duplicate-warning hidden"></div>

                <div id="selectedFilesPanel" class="selected-files-panel hidden">
                    <div class="selected-files-head">
                        <strong>Selected files</strong>
                        <div class="selected-files-tools">
                            <button id="chooseMoreFilesBtn" class="ghost-btn" type="button">Add More</button>
                            <button id="clearSelectedFilesBtn" class="ghost-btn danger" type="button">Clear Selected</button>
                        </div>
                    </div>
                    <div id="selectedFileText" class="muted small">No files selected</div>
                    <div id="selectedFilesList" class="selected-files-list"></div>
                </div>

                <div class="upload-actions">
                    <button id="chooseFilesBtn" class="ghost-btn" type="button">Choose Files</button>
                    <button id="uploadBtn" class="primary-btn" type="submit">Upload Selected Files</button>
                </div>

                <div id="uploadProgressWrap" class="progress-wrap hidden">
                    <div class="progress"><div id="uploadProgressBar" class="progress-bar"></div></div>
                    <div id="uploadStatus" class="muted small">Waiting…</div>
                </div>
            </form>
            <?php endif; ?>

            <div class="files-toolbar">
                <input id="searchInput" type="text" placeholder="Search files...">
                <select id="typeFilter">
                    <option value="all">All types</option>
                    <option value="image">Images</option>
                    <option value="video">Videos</option>
                    <option value="audio">Audio</option>
                    <option value="pdf">PDF</option>
                    <option value="other">Other</option>
                </select>
                <select id="sortSelect">
                    <option value="newest">Newest</option>
                    <option value="oldest">Oldest</option>
                    <option value="largest">Largest</option>
                    <option value="smallest">Smallest</option>
                    <option value="az">A-Z</option>
                    <option value="za">Z-A</option>
                </select>
                <button id="clearFiltersBtn" class="ghost-btn" type="button">Clear Filters</button>
            </div>

            <div id="tagFilterBar" class="tag-filter-bar"></div>

            <div id="listView" class="file-list"></div>
            <div id="galleryView" class="gallery-grid hidden"></div>

            <div class="pager">
                <button id="prevPageBtn" class="ghost-btn" type="button">Prev</button>
                <span id="pageText" class="muted">Page 1 / 1</span>
                <button id="nextPageBtn" class="ghost-btn" type="button">Next</button>
            </div>
        </section>
    </main>

    <section class="card" style="margin-top:18px;">
        <div class="section-head">
            <div>
                <h2>Session Insights</h2>
                <p class="muted">Secondary information for this session</p>
            </div>
        </div>

        <div class="file-list" style="margin-bottom:14px;">
            <div class="file-card">
                <div><strong>Session Dashboard</strong></div>
                <div class="file-top-meta">Current session summary</div>
                <div id="sessionStatsBox" class="file-list" style="margin-top:10px;"></div>
            </div>
        </div>

        <div>
            <div class="section-head" style="margin-bottom:10px;">
                <div>
                    <h2 style="font-size:16px;">Recent Files</h2>
                    <p class="muted">Latest files in this session</p>
                </div>
            </div>
            <div id="recentFilesStrip" class="recent-files-strip"></div>
        </div>
    </section>
</div>

<div id="selectionBar" class="selection-bar hidden">
    <div class="selection-bar-left">
        <strong id="selectionBarText">No files selected</strong>
    </div>
    <div class="selection-bar-actions">
        <select id="bulkTargetSessionSelect"></select>
        <button id="selectVisibleBtn" class="ghost-btn" type="button">Select Visible</button>
        <button id="clearVisibleSelectionBtn" class="ghost-btn danger" type="button">Clear Selection</button>
        <?php if ($sessionWritable): ?>
            <button id="copySelectedExistingBtn" class="ghost-btn" type="button">Copy to Session</button>
            <button id="moveSelectedExistingBtn" class="ghost-btn" type="button">Move to Session</button>
            <button id="trashSelectedExistingBtn" class="ghost-btn danger" type="button">Move to Trash</button>
        <?php endif; ?>
        <button id="downloadSelectedExistingBtn" class="primary-btn" type="button">Download Selected</button>
    </div>
</div>

<div id="fileModal" class="modal hidden">
    <div class="modal-backdrop" data-close-modal="1"></div>
    <div class="modal-card">
        <div class="modal-head">
            <div>
                <h3 id="modalFileName">File</h3>
                <div id="modalFileMeta" class="muted small"></div>
            </div>
            <button id="closeFileModalBtn" class="ghost-btn" type="button">Close</button>
        </div>
        <div class="modal-body">
            <div id="modalFilePreview" class="modal-preview"></div>
            <div class="modal-details-grid">
                <div>
                    <div class="label">Title</div>
                    <input id="modalEditTitle" class="modal-input" type="text" <?= $sessionWritable ? '' : 'readonly' ?>>
                </div>
                <div>
                    <div class="label">Uploader</div>
                    <div id="modalFileUploader" class="value">-</div>
                </div>
                <div>
                    <div class="label">Description</div>
                    <textarea id="modalEditDescription" class="modal-textarea" <?= $sessionWritable ? '' : 'readonly' ?>></textarea>
                </div>
                <div>
                    <div class="label">Tags</div>
                    <input id="modalEditTags" class="modal-input" type="text" placeholder="tag1, tag2" <?= $sessionWritable ? '' : 'readonly' ?>>
                </div>
            </div>
        </div>
        <div class="modal-actions">
            <?php if ($sessionWritable): ?>
                <button id="modalSaveMetaBtn" class="ghost-btn" type="button">Save Metadata</button>
            <?php endif; ?>
            <button id="modalCopyLinkBtn" class="ghost-btn cyan" type="button">Copy Link</button>
            <a id="modalDownloadBtn" class="primary-btn" href="#">Download</a>
            <a id="modalOpenBtn" class="ghost-btn teal" href="#" target="_blank">Open</a>
        </div>
    </div>
</div>

<div id="editSessionModal" class="modal hidden">
    <div class="modal-backdrop" data-close-edit-session="1"></div>
    <div class="modal-card">
        <div class="modal-head">
            <div>
                <h3>Edit Current Session</h3>
                <div class="muted small"><?= h($currentSession['id'] ?? '') ?></div>
            </div>
            <button id="closeEditSessionBtn" class="ghost-btn" type="button">Close</button>
        </div>
        <div class="modal-body">
            <div class="modal-details-grid">
                <div>
                    <div class="label">Session name</div>
                    <input id="editSessionName" class="modal-input" type="text" value="<?= h($currentSession['name'] ?? '') ?>">
                </div>
                <div>
                    <div class="label">Brand title</div>
                    <input id="editSessionBrandTitle" class="modal-input" type="text" value="<?= h($currentSession['brand_title'] ?? '') ?>">
                </div>
                <div>
                    <div class="label">Description</div>
                    <textarea id="editSessionDescription" class="modal-textarea"><?= h($currentSession['description'] ?? '') ?></textarea>
                </div>
                <div>
                    <div class="label">Brand color</div>
                    <input id="editSessionBrandColor" class="modal-input" type="text" value="<?= h($currentSession['brand_color'] ?? '#2563eb') ?>">
                    <div class="label" style="margin-top:12px;">Accent color</div>
                    <input id="editSessionAccentColor" class="modal-input" type="text" value="<?= h($currentSession['accent_color'] ?? '#0f172a') ?>">
                    <?php if (is_admin()): ?>
                        <label class="file-top-meta" style="display:block;margin-top:12px;">
                            <input type="checkbox" id="editSessionArchiveMode" <?= !empty($currentSession['archive_mode']) ? 'checked' : '' ?>> Archive mode
                        </label>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="modal-actions">
            <button id="saveSessionBtn" class="primary-btn" type="button">Save Session</button>
        </div>
    </div>
</div>

<script>
window.APP_BOOT = {
    pageSize: <?= (int)cfg('page_size') ?>,
    pollIntervalMs: <?= (int)cfg('poll_interval_ms') ?>,
    canEdit: <?= $sessionWritable ? 'true' : 'false' ?>,
    isAdmin: <?= is_admin() ? 'true' : 'false' ?>
};
window.CURRENT_SESSION_ID = <?= json_encode($currentSession['id'] ?? '') ?>;
</script>
<script src="assets/app.js?v=10"></script>
<script>
(function () {
  function esc(str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function encode(data) {
    var parts = [];
    Object.keys(data).forEach(function (key) {
      parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(data[key]));
    });
    return parts.join('&');
  }

  function apiGet(action) {
    return fetch('api/index.php?action=' + encodeURIComponent(action), {
      credentials: 'same-origin'
    }).then(function (r) { return r.json(); });
  }

  function apiPost(action, data) {
    return fetch('api/index.php?action=' + encodeURIComponent(action), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: encode(data || {})
    }).then(function (r) { return r.json(); });
  }

  function selectedFileIdsFromDom() {
    return Array.prototype.slice.call(document.querySelectorAll('[data-file-check]:checked')).map(function (el) {
      return el.getAttribute('data-file-check');
    }).filter(Boolean);
  }

  function renderBulkTargetOptions() {
    apiGet('session_info').then(function (res) {
      if (!res.ok) return;
      var select = document.getElementById('bulkTargetSessionSelect');
      if (!select) return;

      var currentId = res.current_session_id || '';
      var sessions = (res.sessions || []).filter(function (s) { return s.id !== currentId; });

      select.innerHTML = sessions.map(function (s) {
        return '<option value="' + esc(s.id) + '">' + esc(s.name) + '</option>';
      }).join('');

      if (!sessions.length) {
        select.innerHTML = '<option value="">No other session</option>';
      }
    });
  }

  function loadStats() {
    apiGet('admin_session_stats').then(function (res) {
      if (!res.ok) return;
      var rows = res.items || [];
      var currentId = window.CURRENT_SESSION_ID || '';
      var row = rows.find(function (r) { return r.session_id === currentId; });
      var box = document.getElementById('sessionStatsBox');
      if (!box || !row) return;

      box.innerHTML =
        '<div class="file-card"><div><strong>Files:</strong> ' + esc(String(row.file_count || 0)) + '</div></div>' +
        '<div class="file-card"><div><strong>Trash:</strong> ' + esc(String(row.trash_count || 0)) + '</div></div>' +
        '<div class="file-card"><div><strong>Notes:</strong> ' + esc(String(row.note_count || 0)) + '</div></div>' +
        '<div class="file-card"><div><strong>Total size:</strong> ' + esc(row.total_size_text || '0 B') + '</div></div>' +
        '<div class="file-card"><div><strong>Total downloads:</strong> ' + esc(String(row.total_downloads || 0)) + '</div></div>' +
        '<div class="file-card"><div><strong>Archive mode:</strong> ' + (row.archive_mode ? 'Yes' : 'No') + '</div></div>';
    });
  }

  function openEditSession() {
    document.getElementById('editSessionModal').classList.remove('hidden');
  }

  function closeEditSession() {
    document.getElementById('editSessionModal').classList.add('hidden');
  }

  document.addEventListener('DOMContentLoaded', function () {
    renderBulkTargetOptions();
    loadStats();

    var copyBtn = document.getElementById('copySelectedExistingBtn');
    if (copyBtn) {
      copyBtn.addEventListener('click', function () {
        var ids = selectedFileIdsFromDom();
        var target = document.getElementById('bulkTargetSessionSelect').value;
        if (!ids.length) return alert('Select files first.');
        if (!target) return alert('Choose target session.');
        apiPost('files_bulk_copy_session', {
          files: ids.join(','),
          target_session_id: target
        }).then(function (res) {
          if (res.ok) alert('Copied: ' + res.count);
          else alert(res.error || 'Could not copy files');
        });
      });
    }

    var moveBtn = document.getElementById('moveSelectedExistingBtn');
    if (moveBtn) {
      moveBtn.addEventListener('click', function () {
        var ids = selectedFileIdsFromDom();
        var target = document.getElementById('bulkTargetSessionSelect').value;
        if (!ids.length) return alert('Select files first.');
        if (!target) return alert('Choose target session.');
        if (!confirm('Move selected files to the other session?')) return;
        apiPost('files_bulk_move_session', {
          files: ids.join(','),
          target_session_id: target
        }).then(function (res) {
          if (res.ok) window.location.reload();
          else alert(res.error || 'Could not move files');
        });
      });
    }

    var renameBtn = document.getElementById('renameSessionBtn');
    if (renameBtn) renameBtn.addEventListener('click', openEditSession);

    var closeBtn = document.getElementById('closeEditSessionBtn');
    if (closeBtn) closeBtn.addEventListener('click', closeEditSession);

    Array.prototype.slice.call(document.querySelectorAll('[data-close-edit-session]')).forEach(function (el) {
      el.addEventListener('click', closeEditSession);
    });

    var saveBtn = document.getElementById('saveSessionBtn');
    if (saveBtn) {
      saveBtn.addEventListener('click', function () {
        apiPost('admin_update_session', {
          id: window.CURRENT_SESSION_ID || '',
          name: document.getElementById('editSessionName').value,
          description: document.getElementById('editSessionDescription').value,
          brand_title: document.getElementById('editSessionBrandTitle').value,
          brand_color: document.getElementById('editSessionBrandColor').value,
          accent_color: document.getElementById('editSessionAccentColor').value,
          archive_mode: document.getElementById('editSessionArchiveMode') ? (document.getElementById('editSessionArchiveMode').checked ? '1' : '') : ''
        }).then(function (res) {
          if (res.ok) window.location.reload();
          else alert(res.error || 'Could not save session');
        });
      });
    }
  });
})();
</script>
</body>
</html>