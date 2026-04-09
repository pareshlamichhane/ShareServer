<?php
require __DIR__ . '/bootstrap.php';

if (!is_admin()) {
    http_response_code(403);
    exit('Forbidden');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Admin Panel - <?= h(cfg('site_name')) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="app-shell">
    <header class="topbar">
        <div>
            <h1>Secret Admin Panel</h1>
            <div class="topbar-sub">Sessions, links, branding, and danger controls</div>
        </div>
        <div class="top-actions">
            <a class="ghost-btn" href="audit.php">Audit Log</a>
            <a class="ghost-btn cyan" href="index.php">Back</a>
        </div>
    </header>

    <section class="card">
        <div class="admin-tabs">
            <button class="ghost-btn admin-tab-btn active" type="button" data-admin-tab="sessionsTab">Sessions</button>
            <button class="ghost-btn admin-tab-btn" type="button" data-admin-tab="linksTab">Share Links</button>
            <button class="ghost-btn admin-tab-btn danger" type="button" data-admin-tab="dangerTab">Danger Zone</button>
        </div>
    </section>

    <section id="sessionsTab" class="card admin-tab-panel">
        <div class="section-head">
            <div>
                <h2>Sessions</h2>
                <p class="muted">Compact cards. Expand only when needed.</p>
            </div>
            <button id="refreshSessionsBtn" class="ghost-btn" type="button">Refresh</button>
        </div>

        <details class="card-inline">
            <summary><strong>Create Session</strong></summary>
            <div class="details-body">
                <div class="files-toolbar">
                    <input id="newSessionName" type="text" placeholder="Session name">
                    <input id="newSessionDescription" type="text" placeholder="Description">
                    <input id="newSessionBrandTitle" type="text" placeholder="Brand title">
                    <input id="newSessionBrandColor" type="text" placeholder="#2563eb">
                    <input id="newSessionAccentColor" type="text" placeholder="#0f172a">
                    <button id="createSessionBtn" class="primary-btn" type="button">Create Session</button>
                </div>
            </div>
        </details>

        <div id="sessionsList" class="file-list"></div>
    </section>

    <section id="linksTab" class="card admin-tab-panel hidden">
        <div class="section-head">
            <div>
                <h2>Share Base</h2>
                <p class="muted">Current app URL or your custom public/LAN base</p>
            </div>
        </div>

        <div class="files-toolbar">
            <select id="baseModeSelect">
                <option value="current">Use current app URL</option>
                <option value="custom">Use custom domain / IP</option>
            </select>
            <input id="customBaseInput" type="text" placeholder="Example: http://192.168.1.50/share or https://files.example.com">
            <button id="saveBaseConfigBtn" class="primary-btn" type="button">Save Base</button>
            <button id="resetBaseConfigBtn" class="ghost-btn" type="button">Reset</button>
        </div>

        <div id="basePreviewBox" class="file-card"></div>

        <details class="card-inline" open>
            <summary><strong>Create Share Link</strong></summary>
            <div class="details-body">
                <div class="files-toolbar">
                    <input id="adminTokenLabel" type="text" placeholder="Label">
                    <select id="adminTokenRole">
                        <option value="viewer">Viewer</option>
                        <option value="editor">Editor</option>
                        <option value="admin">Admin</option>
                    </select>
                    <input id="adminTokenExpiry" type="datetime-local">
                    <button id="adminCreateTokenBtn" class="primary-btn" type="button">Create Link</button>
                </div>

                <div class="file-card">
                    <div class="label">Allowed Sessions</div>
                    <div id="tokenSessionChecks" class="tag-filter-bar"></div>
                    <div class="label" style="margin-top:10px;">Default Session</div>
                    <select id="tokenDefaultSessionSelect"></select>
                </div>

                <div id="adminCreateResult" class="file-card hidden"></div>
            </div>
        </details>

        <div class="section-head" style="margin-top:16px;">
            <div>
                <h2>Existing Share Links</h2>
                <p class="muted">Advanced details hidden by default</p>
            </div>
            <button id="adminRefreshBtn" class="ghost-btn" type="button">Refresh</button>
        </div>

        <div id="adminTokensList" class="file-list"></div>
    </section>

    <section id="dangerTab" class="card admin-tab-panel hidden">
        <div class="section-head">
            <div>
                <h2>Danger Zone</h2>
                <p class="muted">Destructive actions only</p>
            </div>
        </div>

        <div class="file-card">
            <div><strong>Disable Expired Links</strong></div>
            <div class="file-top-meta">This disables all links already past their expiry time.</div>
            <div style="margin-top:10px;">
                <button id="cleanupExpiredBtn" class="ghost-btn danger" type="button">Disable Expired</button>
            </div>
        </div>
    </section>
</div>

<div id="adminQrModal" class="modal hidden">
    <div class="modal-backdrop" data-close-admin-qr="1"></div>
    <div class="modal-card">
        <div class="modal-head">
            <div>
                <h3>Share QR Code</h3>
                <div id="adminQrUrlText" class="muted small"></div>
            </div>
            <button id="closeAdminQrBtn" class="ghost-btn" type="button">Close</button>
        </div>
        <div class="modal-body">
            <div class="modal-preview" id="adminQrPreview"></div>
        </div>
        <div class="modal-actions">
            <button id="adminQrCopyBtn" class="ghost-btn" type="button">Copy Link</button>
        </div>
    </div>
</div>

<script src="assets/vendor/qrcode.min.js"></script>
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

  function apiGet(action, params) {
    var url = 'api/index.php?action=' + encodeURIComponent(action);
    Object.keys(params || {}).forEach(function (key) {
      url += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
    });
    return fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }

  function apiPost(action, data) {
    return fetch('api/index.php?action=' + encodeURIComponent(action), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: encode(data || {})
    }).then(function (r) { return r.json(); });
  }

  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () {
        alert('Link copied');
      }).catch(function () {
        prompt('Copy this link:', text);
      });
    } else {
      prompt('Copy this link:', text);
    }
  }

  function normalizeBaseUrl(url) {
    var trimmed = String(url || '').trim();
    if (!trimmed) return '';
    return trimmed.replace(/\/+$/, '') + '/';
  }

  function getSavedBaseConfig() {
    try {
      return JSON.parse(localStorage.getItem('sharehub_admin_base') || '{}');
    } catch (e) {
      return {};
    }
  }

  function saveBaseConfig(mode, customBase) {
    localStorage.setItem('sharehub_admin_base', JSON.stringify({
      mode: mode,
      customBase: customBase
    }));
  }

  function getEffectiveBaseUrl() {
    var cfg = getSavedBaseConfig();
    if (cfg.mode === 'custom' && cfg.customBase) {
      return normalizeBaseUrl(cfg.customBase);
    }
    return normalizeBaseUrl(window.location.origin + window.location.pathname.replace(/[^\/]+$/, ''));
  }

  function renderBasePreview() {
    var cfg = getSavedBaseConfig();
    var modeEl = document.getElementById('baseModeSelect');
    var customEl = document.getElementById('customBaseInput');
    var previewEl = document.getElementById('basePreviewBox');

    if (!modeEl || !customEl || !previewEl) return;

    modeEl.value = cfg.mode || 'current';
    customEl.value = cfg.customBase || '';

    previewEl.innerHTML =
      '<div><strong>Effective share base:</strong></div>' +
      '<div class="file-top-meta" style="word-break:break-all;margin-top:6px;">' + esc(getEffectiveBaseUrl()) + '</div>';
  }

  function buildTokenUrl(token) {
    return getEffectiveBaseUrl() + '?token=' + encodeURIComponent(token);
  }

  function showQr(url) {
    document.getElementById('adminQrUrlText').textContent = url;
    var box = document.getElementById('adminQrPreview');
    box.innerHTML = '';

    if (typeof QRCode === 'undefined') {
      box.innerHTML = '<div class="muted">Offline QR library not found. Add assets/vendor/qrcode.min.js</div>';
    } else {
      new QRCode(box, {
        text: url,
        width: 280,
        height: 280
      });
    }

    document.getElementById('adminQrCopyBtn').setAttribute('data-url', url);
    document.getElementById('adminQrModal').classList.remove('hidden');
  }

  function closeQr() {
    document.getElementById('adminQrModal').classList.add('hidden');
    document.getElementById('adminQrPreview').innerHTML = '';
  }

  function switchAdminTab(id) {
    Array.prototype.slice.call(document.querySelectorAll('.admin-tab-panel')).forEach(function (panel) {
      panel.classList.add('hidden');
    });
    Array.prototype.slice.call(document.querySelectorAll('.admin-tab-btn')).forEach(function (btn) {
      btn.classList.remove('active');
    });

    var panel = document.getElementById(id);
    if (panel) panel.classList.remove('hidden');

    var btn = document.querySelector('[data-admin-tab="' + id + '"]');
    if (btn) btn.classList.add('active');
  }

  function loadSessions() {
    apiGet('admin_sessions').then(function (res) {
      if (!res.ok) return;

      var items = res.items || [];
      var box = document.getElementById('sessionsList');
      var checks = document.getElementById('tokenSessionChecks');
      var select = document.getElementById('tokenDefaultSessionSelect');

      checks.innerHTML = items.map(function (s, i) {
        return '<label class="tag-chip">' +
          '<input type="checkbox" class="token-session-check" value="' + esc(s.id) + '" ' + (i === 0 ? 'checked' : '') + '> ' +
          esc(s.name) +
        '</label>';
      }).join('');

      select.innerHTML = items.map(function (s) {
        return '<option value="' + esc(s.id) + '">' + esc(s.name) + '</option>';
      }).join('');

      box.innerHTML = items.map(function (s) {
        return '<details class="file-card compact-details">' +
          '<summary class="compact-summary">' +
            '<div><strong>' + esc(s.name) + '</strong></div>' +
            '<div class="file-top-meta">' + esc(s.description || '') + '</div>' +
          '</summary>' +
          '<div class="details-body">' +
            '<input class="modal-input session-name" data-id="' + esc(s.id) + '" value="' + esc(s.name) + '">' +
            '<input class="modal-input session-brand-title" data-id="' + esc(s.id) + '" value="' + esc(s.brand_title || '') + '" style="margin-top:8px;">' +
            '<input class="modal-input session-description" data-id="' + esc(s.id) + '" value="' + esc(s.description || '') + '" style="margin-top:8px;">' +
            '<div class="row-wrap" style="margin-top:8px;">' +
              '<input class="modal-input session-brand-color" data-id="' + esc(s.id) + '" value="' + esc(s.brand_color || '#2563eb') + '">' +
              '<input class="modal-input session-accent-color" data-id="' + esc(s.id) + '" value="' + esc(s.accent_color || '#0f172a') + '">' +
            '</div>' +
            '<label class="file-top-meta" style="display:block;margin-top:8px;">' +
              '<input type="checkbox" class="session-archive" data-id="' + esc(s.id) + '"' + (s.archive_mode ? ' checked' : '') + '> Archive mode' +
            '</label>' +
            '<div class="file-top-meta" style="margin-top:8px;">ID: ' + esc(s.id) + '</div>' +
            '<div class="file-actions" style="margin-top:10px;">' +
              '<button class="ghost-btn" type="button" data-save-session="' + esc(s.id) + '">Save</button>' +
              '<button class="ghost-btn" type="button" data-clone-session="' + esc(s.id) + '">Clone</button>' +
              '<button class="ghost-btn danger" type="button" data-delete-session="' + esc(s.id) + '">Delete</button>' +
            '</div>' +
          '</div>' +
        '</details>';
      }).join('');

      Array.prototype.slice.call(document.querySelectorAll('[data-save-session]')).forEach(function (btn) {
        btn.addEventListener('click', function () {
          var id = this.getAttribute('data-save-session');
          apiPost('admin_update_session', {
            id: id,
            name: document.querySelector('.session-name[data-id="' + id + '"]').value,
            description: document.querySelector('.session-description[data-id="' + id + '"]').value,
            brand_title: document.querySelector('.session-brand-title[data-id="' + id + '"]').value,
            brand_color: document.querySelector('.session-brand-color[data-id="' + id + '"]').value,
            accent_color: document.querySelector('.session-accent-color[data-id="' + id + '"]').value,
            archive_mode: document.querySelector('.session-archive[data-id="' + id + '"]').checked ? '1' : ''
          }).then(function (r) {
            if (r.ok) alert('Session saved');
            else alert(r.error || 'Could not save session');
          });
        });
      });

      Array.prototype.slice.call(document.querySelectorAll('[data-clone-session]')).forEach(function (btn) {
        btn.addEventListener('click', function () {
          var id = this.getAttribute('data-clone-session');
          var newName = prompt('New cloned session name:', 'Cloned Session');
          if (newName === null) return;
          apiPost('admin_clone_session', {
            source_id: id,
            new_name: newName
          }).then(function (r) {
            if (r.ok) loadSessions();
            else alert(r.error || 'Could not clone session');
          });
        });
      });

      Array.prototype.slice.call(document.querySelectorAll('[data-delete-session]')).forEach(function (btn) {
        btn.addEventListener('click', function () {
          var id = this.getAttribute('data-delete-session');

          apiGet('admin_delete_session_check', { session_id: id }).then(function (impact) {
            if (!impact.ok) {
              alert(impact.error || 'Could not inspect delete impact');
              return;
            }

            var message =
              'Delete session: ' + (impact.session.name || 'Session') + '\n\n' +
              'Notes: ' + (impact.note_tab_count || 0) + '\n' +
              'Files: ' + (impact.file_count || 0) + '\n' +
              'Trash items: ' + (impact.trash_count || 0) + '\n' +
              'Affected tokens: ' + (impact.affected_token_count || 0) + '\n' +
              'Tokens disabled: ' + (impact.tokens_disabled_count || 0) + '\n' +
              'Tokens reassigned: ' + (impact.tokens_reassigned_count || 0) + '\n\n' +
              'Type DELETE to confirm.';

            var answer = prompt(message, '');
            if (answer !== 'DELETE') return;

            apiPost('admin_delete_session', {
              session_id: id,
              confirmed: '1'
            }).then(function (r) {
              if (r.ok) {
                loadSessions();
                loadTokens();
                alert('Session deleted');
              } else {
                alert(r.error || 'Could not delete session');
              }
            });
          });
        });
      });
    });
  }

  function getSelectedSessionIds() {
    return Array.prototype.slice.call(document.querySelectorAll('.token-session-check:checked')).map(function (el) {
      return el.value;
    });
  }

  function loadTokens() {
    apiGet('admin_tokens').then(function (res) {
      if (!res.ok) return;
      var items = res.items || [];
      var box = document.getElementById('adminTokensList');

      if (!items.length) {
        box.innerHTML = '<div class="file-card"><div class="muted">No share links yet.</div></div>';
        return;
      }

      box.innerHTML = items.map(function (item) {
        var usageHtml = Object.keys(item.usage_by_session || {}).map(function (sid) {
          return '<span class="inline-tag">' + esc(sid) + ': ' + esc(String(item.usage_by_session[sid])) + '</span>';
        }).join('');

        var url = buildTokenUrl(item.token);

        return '<details class="file-card compact-details">' +
          '<summary class="compact-summary">' +
            '<div><strong>' + esc(item.label || 'Share Link') + '</strong></div>' +
            '<div class="file-top-meta">' +
              'Role: ' + esc(item.role || 'viewer') + ' • ' +
              '<span class="' + (item.enabled ? 'status-ok' : 'status-off') + '">' +
                (item.enabled ? 'Enabled' : 'Disabled') +
              '</span>' +
              (item.expired ? ' • <span class="status-off">Expired</span>' : '') +
            '</div>' +
          '</summary>' +
          '<div class="details-body">' +
            '<div class="file-top-meta">Sessions: ' + esc((item.session_ids || []).join(', ')) + '</div>' +
            '<div class="file-top-meta">Default session: ' + esc(item.default_session_id || '-') + '</div>' +
            '<div class="file-top-meta">Uses: ' + esc(String(item.use_count || 0)) + '</div>' +
            '<div class="file-tags" style="margin-top:8px;">' + usageHtml + '</div>' +
            '<div class="file-top-meta" style="margin-top:8px;">Created: ' + esc(item.created_at || '') + '</div>' +
            '<div class="file-top-meta">Expires: ' + esc(item.expires_at || '-') + '</div>' +
            '<div class="file-top-meta">Last used: ' + esc(item.last_used_at || '-') + ' • IP: ' + esc(item.last_used_ip || '-') + '</div>' +
            '<div class="file-top-meta" style="word-break:break-all;margin-top:8px;">' + esc(url) + '</div>' +
            '<div class="file-actions" style="margin-top:10px;">' +
              '<button class="ghost-btn" type="button" data-copy-url="' + esc(url) + '">Copy Link</button>' +
              '<button class="ghost-btn" type="button" data-show-qr="' + esc(url) + '">QR Code</button>' +
              (item.enabled
                ? '<button class="ghost-btn danger" type="button" data-disable-token="' + esc(item.token) + '">Disable</button>'
                : '<button class="ghost-btn" type="button" disabled>Disabled</button>') +
            '</div>' +
          '</div>' +
        '</details>';
      }).join('');

      Array.prototype.slice.call(document.querySelectorAll('[data-copy-url]')).forEach(function (btn) {
        btn.addEventListener('click', function () {
          copyText(this.getAttribute('data-copy-url'));
        });
      });

      Array.prototype.slice.call(document.querySelectorAll('[data-show-qr]')).forEach(function (btn) {
        btn.addEventListener('click', function () {
          showQr(this.getAttribute('data-show-qr'));
        });
      });

      Array.prototype.slice.call(document.querySelectorAll('[data-disable-token]')).forEach(function (btn) {
        btn.addEventListener('click', function () {
          if (!confirm('Disable this share link?')) return;

          var self = this;
          var token = self.getAttribute('data-disable-token');
          if (!token) {
            alert('Missing token');
            return;
          }

          self.disabled = true;
          self.textContent = 'Disabling...';

          apiPost('admin_disable_token', { token: token }).then(function (r) {
            if (r.ok) {
              loadTokens();
            } else {
              self.disabled = false;
              self.textContent = 'Disable';
              alert(r.error || 'Could not disable token');
            }
          }).catch(function () {
            self.disabled = false;
            self.textContent = 'Disable';
            alert('Could not disable token');
          });
        });
      });
    });
  }
  document.addEventListener('DOMContentLoaded', function () {
    renderBasePreview();
    loadSessions();
    loadTokens();

    Array.prototype.slice.call(document.querySelectorAll('.admin-tab-btn')).forEach(function (btn) {
      btn.addEventListener('click', function () {
        switchAdminTab(this.getAttribute('data-admin-tab'));
      });
    });

    document.getElementById('saveBaseConfigBtn').addEventListener('click', function () {
      saveBaseConfig(
        document.getElementById('baseModeSelect').value,
        document.getElementById('customBaseInput').value
      );
      renderBasePreview();
      loadTokens();
    });

    document.getElementById('resetBaseConfigBtn').addEventListener('click', function () {
      saveBaseConfig('current', '');
      renderBasePreview();
      loadTokens();
    });

    document.getElementById('createSessionBtn').addEventListener('click', function () {
      apiPost('admin_create_session', {
        name: document.getElementById('newSessionName').value,
        description: document.getElementById('newSessionDescription').value,
        brand_title: document.getElementById('newSessionBrandTitle').value,
        brand_color: document.getElementById('newSessionBrandColor').value,
        accent_color: document.getElementById('newSessionAccentColor').value
      }).then(function (res) {
        if (res.ok) {
          document.getElementById('newSessionName').value = '';
          document.getElementById('newSessionDescription').value = '';
          document.getElementById('newSessionBrandTitle').value = '';
          loadSessions();
        } else {
          alert(res.error || 'Could not create session');
        }
      });
    });

    document.getElementById('refreshSessionsBtn').addEventListener('click', loadSessions);
    document.getElementById('adminRefreshBtn').addEventListener('click', loadTokens);

    document.getElementById('adminCreateTokenBtn').addEventListener('click', function () {
      var expiryInput = document.getElementById('adminTokenExpiry').value;
      var expiryValue = expiryInput ? expiryInput.replace('T', ' ') + ':00' : '';

      apiPost('admin_create_token', {
        label: document.getElementById('adminTokenLabel').value,
        role: document.getElementById('adminTokenRole').value,
        expires_at: expiryValue,
        session_ids: getSelectedSessionIds().join(','),
        default_session_id: document.getElementById('tokenDefaultSessionSelect').value
      }).then(function (res) {
        var box = document.getElementById('adminCreateResult');
        if (res.ok) {
          var url = buildTokenUrl(res.token);
          box.classList.remove('hidden');
          box.innerHTML =
            '<div><strong>New link created</strong></div>' +
            '<div class="file-top-meta" style="word-break:break-all;margin-top:6px;">' + esc(url) + '</div>' +
            '<div class="file-actions" style="margin-top:10px;">' +
              '<button class="ghost-btn" id="newCopyLinkBtn" type="button">Copy Link</button>' +
              '<button class="ghost-btn" id="newQrBtn" type="button">QR Code</button>' +
            '</div>';
          document.getElementById('newCopyLinkBtn').addEventListener('click', function () { copyText(url); });
          document.getElementById('newQrBtn').addEventListener('click', function () { showQr(url); });
          loadTokens();
        } else {
          alert(res.error || 'Could not create link');
        }
      });
    });

    document.getElementById('cleanupExpiredBtn').addEventListener('click', function () {
      apiPost('admin_cleanup_expired', {}).then(function (res) {
        if (res.ok) {
          alert('Disabled expired links: ' + res.count);
          loadTokens();
        }
      });
    });

    document.getElementById('closeAdminQrBtn').addEventListener('click', closeQr);
    document.getElementById('adminQrCopyBtn').addEventListener('click', function () {
      copyText(this.getAttribute('data-url') || '');
    });

    Array.prototype.slice.call(document.querySelectorAll('[data-close-admin-qr]')).forEach(function (el) {
      el.addEventListener('click', closeQr);
    });
  });
})();
</script>
</body>
</html>