<?php
require __DIR__ . '/bootstrap.php';

if (!is_logged_in()) {
    http_response_code(403);
    exit('Forbidden');
}

$session = current_session_info();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Session Stats - <?= h(cfg('site_name')) ?></title>
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
            <h1>Session Stats</h1>
            <div class="topbar-sub">
                <span><?= h($session['name'] ?? 'Session') ?></span>
                <span class="dot"></span>
                <span><?= h($session['brand_title'] ?? ($session['name'] ?? 'Session')) ?></span>
            </div>
        </div>
        <div class="top-actions">
          <a class="ghost-btn danger" href="logout.php">Logout</a>
            <a class="ghost-btn cyan" href="index.php">Back</a>
        </div>
    </header>

    <section class="card">
        <div class="section-head">
            <div>
                <h2>Overview</h2>
                <p class="muted">Current session only</p>
            </div>
        </div>

        <div id="statsOverview" class="file-list"></div>
    </section>

    <section class="card">
        <div class="section-head">
            <div>
                <h2>Top Downloaded Files</h2>
                <p class="muted">Based on this session’s own download counts</p>
            </div>
        </div>

        <div id="statsFiles" class="file-list"></div>
    </section>

    <section class="card">
        <div class="section-head">
            <div>
                <h2>Top Tags</h2>
                <p class="muted">Current session tags only</p>
            </div>
        </div>

        <div id="statsTags" class="file-list"></div>
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

  function apiGet(action) {
    return fetch('api/index.php?action=' + encodeURIComponent(action), {
      credentials: 'same-origin'
    }).then(function (r) {
      return r.json();
    });
  }

  function renderOverview(stats) {
    var box = document.getElementById('statsOverview');
    box.innerHTML = '' +
      '<div class="file-card"><div><strong>Total Files</strong></div><div class="file-top-meta">' + esc(String(stats.total_files || 0)) + '</div></div>' +
      '<div class="file-card"><div><strong>Total Notes Tabs</strong></div><div class="file-top-meta">' + esc(String(stats.total_note_tabs || 0)) + '</div></div>' +
      '<div class="file-card"><div><strong>Total Downloads</strong></div><div class="file-top-meta">' + esc(String(stats.total_downloads || 0)) + '</div></div>' +
      '<div class="file-card"><div><strong>Total Size</strong></div><div class="file-top-meta">' + esc(String(stats.total_size_text || '0 B')) + '</div></div>' +
      '<div class="file-card"><div><strong>Archive Mode</strong></div><div class="file-top-meta">' + (stats.archive_mode ? 'Yes' : 'No') + '</div></div>';
  }

  function renderFiles(files) {
    var box = document.getElementById('statsFiles');
    if (!files.length) {
      box.innerHTML = '<div class="file-card"><div class="muted">No files yet.</div></div>';
      return;
    }

    box.innerHTML = files.map(function (file) {
      return '' +
        '<div class="file-card">' +
          '<div><strong>' + esc(file.name) + '</strong></div>' +
          '<div class="file-top-meta">Downloads: ' + esc(String(file.download_count || 0)) + '</div>' +
          '<div class="file-top-meta">Size: ' + esc(file.size_text || '') + '</div>' +
        '</div>';
    }).join('');
  }

  function renderTags(tags) {
    var box = document.getElementById('statsTags');
    if (!tags.length) {
      box.innerHTML = '<div class="file-card"><div class="muted">No tags yet.</div></div>';
      return;
    }

    box.innerHTML = tags.map(function (tag) {
      return '' +
        '<div class="file-card">' +
          '<div><strong>#' + esc(tag.name) + '</strong></div>' +
          '<div class="file-top-meta">Used in files: ' + esc(String(tag.count || 0)) + '</div>' +
        '</div>';
    }).join('');
  }

  document.addEventListener('DOMContentLoaded', function () {
    apiGet('session_stats').then(function (res) {
      if (!res.ok) {
        alert(res.error || 'Could not load stats');
        return;
      }

      renderOverview(res.overview || {});
      renderFiles(res.top_files || []);
      renderTags(res.top_tags || []);
    });
  });
})();
</script>
</body>
</html>