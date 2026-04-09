<?php
require __DIR__ . '/bootstrap.php';

if (!is_logged_in()) {
    http_response_code(403);
    exit('Forbidden');
}

/*
|--------------------------------------------------------------------------
| Raw media file endpoint
|--------------------------------------------------------------------------
| Used by file cards, modal previews, gallery images, video/audio/pdf opens.
*/
if (isset($_GET['file']) && $_GET['file'] !== '') {
    $fileId = (string)$_GET['file'];
    send_media_file($fileId);
    exit;
}

$currentSession = current_session_info();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Shared Media - <?= h(cfg('site_name')) ?></title>
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
            <h1>Shared Media</h1>
            <div class="topbar-sub">
                <span><?= h(current_user_label()) ?></span>
                <span class="dot"></span>
                <span id="mediaCurrentSessionLabel"><?= h($currentSession['name'] ?? 'Current Session') ?></span>
            </div>
        </div>
        <div class="top-actions">
            <?php
            $allowedSessionIds = allowed_session_ids_for_current_user();
            $allowedSessions = array_values(array_filter(list_share_sessions(), function ($s) use ($allowedSessionIds) {
                return in_array($s['id'], $allowedSessionIds, true);
            }));
            ?>
            <select id="mediaSessionSwitcher">
                <?php foreach ($allowedSessions as $s): ?>
                    <option value="<?= h($s['id']) ?>" <?= current_session_id() === $s['id'] ? 'selected' : '' ?>>
                        <?= h($s['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <a class="ghost-btn danger" href="logout.php">Logout</a>
            <a class="ghost-btn cyan" href="index.php">Back</a>
        </div>
    </header>

    <section class="card">
        <div class="files-toolbar">
            <input id="mediaSearchInput" type="text" placeholder="Search media...">
            <select id="mediaTypeFilter">
                <option value="all">All media</option>
                <option value="image">Images</option>
                <option value="video">Videos</option>
                <option value="audio">Audio</option>
                <option value="pdf">PDF</option>
            </select>
            <select id="mediaSortSelect">
                <option value="newest">Newest</option>
                <option value="oldest">Oldest</option>
                <option value="largest">Largest</option>
                <option value="smallest">Smallest</option>
                <option value="az">A-Z</option>
                <option value="za">Z-A</option>
            </select>
            <button id="mediaClearFiltersBtn" class="ghost-btn" type="button">Clear Filters</button>
        </div>

        <div id="mediaTagFilterBar" class="tag-filter-bar"></div>
        <div id="mediaGallery" class="gallery-grid"></div>
    </section>
</div>

<script>
(function () {
  'use strict';

  function esc(str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
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
    var body = [];
    Object.keys(data || {}).forEach(function (key) {
      body.push(encodeURIComponent(key) + '=' + encodeURIComponent(data[key]));
    });

    return fetch('api/index.php?action=' + encodeURIComponent(action), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.join('&')
    }).then(function (r) {
      return r.json();
    });
  }

  var state = {
    search: '',
    type: 'all',
    sort: 'newest',
    tag: ''
  };

  function renderTags(tags) {
    var box = document.getElementById('mediaTagFilterBar');
    var chips = [
      '<button class="tag-chip ' + (state.tag === '' ? 'active' : '') + '" data-tag="" type="button">All Tags</button>'
    ];

    (tags || []).forEach(function (tag) {
      chips.push(
        '<button class="tag-chip ' + (state.tag === tag ? 'active' : '') + '" data-tag="' + esc(tag) + '" type="button">#' + esc(tag) + '</button>'
      );
    });

    box.innerHTML = chips.join('');

    Array.prototype.slice.call(box.querySelectorAll('[data-tag]')).forEach(function (btn) {
      btn.addEventListener('click', function () {
        state.tag = this.getAttribute('data-tag') || '';
        loadMedia();
      });
    });
  }

  function fileTagsHtml(tags) {
    if (!tags || !tags.length) return '';
    return '<div class="file-tags">' + tags.map(function (tag) {
      return '<span class="inline-tag">#' + esc(tag) + '</span>';
    }).join('') + '</div>';
  }

  function renderMedia(items) {
    var box = document.getElementById('mediaGallery');
    var previewable = (items || []).filter(function (f) {
      return ['image', 'video', 'audio', 'pdf'].indexOf(f.category) !== -1;
    });

    if (!previewable.length) {
      box.innerHTML = '<div class="file-card"><div class="muted">No media found in this session.</div></div>';
      return;
    }

    box.innerHTML = previewable.map(function (file) {
      var preview = '<div class="muted">Preview</div>';

      if (file.category === 'image') {
        if (file.thumb_url) {
          preview = '<img loading="lazy" src="' + esc(file.thumb_url) + '" alt="' + esc(file.name) + '">';
        } else {
          preview = '<img loading="lazy" src="' + esc(file.media_url) + '" alt="' + esc(file.name) + '">';
        }
      } else if (file.category === 'video') {
        preview = '<video src="' + esc(file.media_url) + '" preload="metadata" muted></video>';
      } else if (file.category === 'audio') {
        preview = '<div class="muted">Audio</div>';
      } else if (file.category === 'pdf') {
        preview = '<div class="muted">PDF</div>';
      }

      return '<div class="gallery-card">' +
        '<div class="thumb-frame"><a href="' + esc(file.media_url) + '" target="_blank">' + preview + '</a></div>' +
        '<div class="gallery-body">' +
          '<div class="gallery-title">' + esc(file.name) + '</div>' +
          '<div class="muted small">' + esc(file.size_text || '') + '</div>' +
          fileTagsHtml(file.tags || []) +
          '<div class="file-actions" style="margin-top:10px;">' +
            '<a class="primary-btn" href="' + esc(file.download_url) + '">Download</a>' +
            '<a class="ghost-btn" href="' + esc(file.media_url) + '" target="_blank">Open</a>' +
          '</div>' +
        '</div>' +
      '</div>';
    }).join('');
  }

  function loadMedia() {
    apiGet('files_list', {
      search: state.search,
      type: state.type,
      sort: state.sort,
      tag: state.tag,
      page: 1,
      page_size: 200
    }).then(function (res) {
      if (!res.ok) return;
      renderTags(res.tags || []);
      renderMedia(res.items || []);
    });
  }

  function switchSession(sessionId) {
    apiPost('switch_session', { session_id: sessionId }).then(function (res) {
      if (!res.ok) {
        alert(res.error || 'Could not switch session');
        return;
      }
      window.location.reload();
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var searchInput = document.getElementById('mediaSearchInput');
    var typeFilter = document.getElementById('mediaTypeFilter');
    var sortSelect = document.getElementById('mediaSortSelect');
    var clearBtn = document.getElementById('mediaClearFiltersBtn');
    var sessionSwitcher = document.getElementById('mediaSessionSwitcher');

    if (searchInput) {
      searchInput.addEventListener('input', function (e) {
        state.search = e.target.value || '';
        loadMedia();
      });
    }

    if (typeFilter) {
      typeFilter.addEventListener('change', function () {
        state.type = this.value;
        loadMedia();
      });
    }

    if (sortSelect) {
      sortSelect.addEventListener('change', function () {
        state.sort = this.value;
        loadMedia();
      });
    }

    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        state.search = '';
        state.type = 'all';
        state.sort = 'newest';
        state.tag = '';

        if (searchInput) searchInput.value = '';
        if (typeFilter) typeFilter.value = 'all';
        if (sortSelect) sortSelect.value = 'newest';

        loadMedia();
      });
    }

    if (sessionSwitcher) {
      sessionSwitcher.addEventListener('change', function () {
        switchSession(this.value);
      });
    }

    loadMedia();
  });
})();
</script>
</body>
</html>