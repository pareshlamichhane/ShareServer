<?php
require __DIR__ . '/bootstrap.php';

if (!is_logged_in()) {
    http_response_code(403);
    exit('Forbidden');
}

$currentSession = current_session_info();
$isArchived = current_session_is_archived();
$canWrite = session_can_write();
$isAdminUser = is_admin();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Trash - <?= h(cfg('site_name')) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="assets/style.css">
    <style>
      :root{
        --primary: <?= h(current_session_brand_color()) ?>;
        --panel-2: <?= h(current_session_accent_color()) ?>;
      }
    </style>
</head>
<body>
<div class="app-shell">
    <header class="topbar">
        <div>
            <h1>Trash</h1>
            <div class="topbar-sub">
                <span><?= h($currentSession['name'] ?? 'Session') ?></span>
                <?php if ($isArchived): ?>
                    <span class="dot"></span>
                    <span>Archive Mode</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="top-actions">
          <a class="ghost-btn danger" href="logout.php">Logout</a>
            <a class="ghost-btn info" href="session_stats.php">Session Stats</a>
            <a class="ghost-btn cyan" href="index.php">Back</a>
        </div>
    </header>

    <section class="card">
        <div class="section-head">
            <div>
                <h2>Trash Items</h2>
                <p class="muted">
                    Editors can restore only. Permanent delete is admin-only.
                </p>
            </div>
            <div class="row-wrap">
                <?php if ($canWrite): ?>
                    <button id="restoreAllBtn" class="ghost-btn" type="button">Restore All</button>
                <?php endif; ?>

                <?php if ($isAdminUser): ?>
                    <button id="emptyTrashBtn" class="ghost-btn danger" type="button">Permanent Delete All</button>
                <?php endif; ?>
            </div>
        </div>

        <div id="trashList" class="file-list"></div>
    </section>
</div>

<div id="permDeleteModal" class="modal hidden">
    <div class="modal-backdrop" data-close-perm-modal="1"></div>
    <div class="modal-card">
        <div class="modal-head">
            <div>
                <h3>Permanent Delete</h3>
                <div class="muted small">Admin-only action</div>
            </div>
            <button id="closePermDeleteModalBtn" class="ghost-btn" type="button">Close</button>
        </div>

        <div class="modal-body">
            <div id="permDeleteSummary" class="file-card"></div>
            <label class="file-top-meta" style="display:block;">
                <input id="permDeleteAcknowledge" type="checkbox">
                I understand this permanent delete may affect multiple sessions if the same file blob is shared.
            </label>
        </div>

        <div class="modal-actions">
            <button id="confirmPermanentDeleteBtn" class="ghost-btn danger" type="button">Delete Permanently</button>
        </div>
    </div>
</div>

<script>
(function () {
  'use strict';

  var pendingPermanentDeleteFileId = '';

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

  function apiGet(action, params) {
    var url = 'api/index.php?action=' + encodeURIComponent(action);
    Object.keys(params || {}).forEach(function (key) {
      url += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
    });

    return fetch(url, {
      credentials: 'same-origin'
    }).then(function (r) {
      return r.json();
    });
  }

  function apiPost(action, data) {
    return fetch('api/index.php?action=' + encodeURIComponent(action), {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
      },
      body: encode(data || {})
    }).then(function (r) {
      return r.json();
    });
  }

  function loadTrash() {
    apiGet('trash_list', {}).then(function (res) {
      if (!res.ok) return;

      var box = document.getElementById('trashList');
      var items = res.items || [];

      if (!items.length) {
        box.innerHTML = '<div class="file-card"><div class="muted">Trash is empty.</div></div>';
        return;
      }

      box.innerHTML = items.map(function (file) {
        return '' +
          '<div class="file-card">' +
            '<div class="file-top">' +
              '<div>' +
                '<h3>' + esc(file.name) + '</h3>' +
                '<div class="file-top-meta">' + esc(file.size_text || '') + ' • Deleted: ' + esc(file.deleted_at || '') + '</div>' +
                '<div class="file-top-meta">' + esc(file.description || '') + '</div>' +
              '</div>' +
              '<div class="file-actions">' +
                <?php if ($canWrite): ?>
                '<button class="ghost-btn" type="button" data-restore="' + esc(file.storage_name) + '">Restore</button>' +
                <?php endif; ?>
                <?php if ($isAdminUser): ?>
                '<button class="ghost-btn danger" type="button" data-check-perm-delete="' + esc(file.storage_name) + '">Delete Permanently</button>' +
                <?php endif; ?>
              '</div>' +
            '</div>' +
          '</div>';
      }).join('');

      Array.prototype.slice.call(document.querySelectorAll('[data-restore]')).forEach(function (btn) {
        btn.addEventListener('click', function () {
          apiPost('trash_restore', {
            file: this.getAttribute('data-restore')
          }).then(function (r) {
            if (r.ok) loadTrash();
            else alert(r.error || 'Could not restore');
          });
        });
      });

      Array.prototype.slice.call(document.querySelectorAll('[data-check-perm-delete]')).forEach(function (btn) {
        btn.addEventListener('click', function () {
          openPermanentDeleteModal(this.getAttribute('data-check-perm-delete'));
        });
      });
    });
  }

  function openPermanentDeleteModal(fileId) {
    apiGet('trash_permanent_delete_check', { file: fileId }).then(function (res) {
      if (!res.ok) {
        alert(res.error || 'Could not inspect delete impact');
        return;
      }

      pendingPermanentDeleteFileId = fileId;
      document.getElementById('permDeleteAcknowledge').checked = false;

      var names = res.sessions_using_blob || [];
      var sessionHtml = names.length
        ? names.map(function (s) { return '<span class="inline-tag">' + esc(s) + '</span>'; }).join('')
        : '<span class="muted">No session usage info found.</span>';

      document.getElementById('permDeleteSummary').innerHTML =
        '<div><strong>' + esc(res.file_name || 'File') + '</strong></div>' +
        '<div class="file-top-meta" style="margin-top:8px;">Referenced sessions: ' + esc(String(res.referenced_session_count || 0)) + '</div>' +
        '<div class="file-tags" style="margin-top:8px;">' + sessionHtml + '</div>' +
        '<div class="file-top-meta" style="margin-top:8px;">' +
          (res.referenced_session_count > 1
            ? 'This file blob is shared by multiple sessions. Permanent delete will remove the blob for all sessions still pointing to it.'
            : 'This file blob appears to belong to only one session reference.')
        + '</div>';

      document.getElementById('permDeleteModal').classList.remove('hidden');
    });
  }

  function closePermanentDeleteModal() {
    document.getElementById('permDeleteModal').classList.add('hidden');
    pendingPermanentDeleteFileId = '';
    document.getElementById('permDeleteAcknowledge').checked = false;
  }

  function confirmPermanentDelete() {
    if (!pendingPermanentDeleteFileId) return;

    if (!document.getElementById('permDeleteAcknowledge').checked) {
      alert('Please acknowledge before permanent delete.');
      return;
    }

    apiPost('trash_admin_delete_permanent', {
      file: pendingPermanentDeleteFileId,
      acknowledge_multi_session: '1'
    }).then(function (res) {
      if (res.ok) {
        closePermanentDeleteModal();
        loadTrash();
      } else {
        alert(res.error || 'Could not permanently delete');
      }
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    loadTrash();

    var restoreAllBtn = document.getElementById('restoreAllBtn');
    if (restoreAllBtn) {
      restoreAllBtn.addEventListener('click', function () {
        apiPost('trash_restore_all', {}).then(function (r) {
          if (r.ok) {
            alert('Restored: ' + r.count);
            loadTrash();
          } else {
            alert(r.error || 'Could not restore all');
          }
        });
      });
    }

    var emptyTrashBtn = document.getElementById('emptyTrashBtn');
    if (emptyTrashBtn) {
      emptyTrashBtn.addEventListener('click', function () {
        if (!confirm('Permanently delete every file in this trash?')) return;

        apiPost('trash_admin_delete_all_permanent', {
          acknowledge_multi_session: '1'
        }).then(function (r) {
          if (r.ok) {
            alert('Deleted permanently: ' + r.count);
            loadTrash();
          } else {
            alert(r.error || 'Could not delete all permanently');
          }
        });
      });
    }

    document.getElementById('closePermDeleteModalBtn').addEventListener('click', closePermanentDeleteModal);
    document.getElementById('confirmPermanentDeleteBtn').addEventListener('click', confirmPermanentDelete);

    Array.prototype.slice.call(document.querySelectorAll('[data-close-perm-modal]')).forEach(function (el) {
      el.addEventListener('click', closePermanentDeleteModal);
    });
  });
})();
</script>
</body>
</html>