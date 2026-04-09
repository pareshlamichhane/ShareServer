(function () {
  'use strict';

  var boot = window.APP_BOOT || {};
  var state = {
    page: 1,
    pages: 1,
    search: '',
    type: 'all',
    sort: 'newest',
    tag: '',
    view: 'list',
    noteTabs: [],
    activeTabId: null,
    selectedFiles: [],
    existingSelected: {},
    currentVisibleFiles: [],
    currentFilesById: {},
    recentFiles: [],
    allTags: [],
    currentModalFile: null,
    sessionList: [],
    currentSessionId: '',
    noteDirty: false,
    noteAutosaveTimer: null,
    lightboxImages: [],
    lightboxIndex: 0,
    versions: {
      notes: 0,
      files: 0,
      activity: 0
    }
  };

  function $(sel) { return document.querySelector(sel); }
  function $all(sel) { return Array.prototype.slice.call(document.querySelectorAll(sel)); }

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

  function formatBytes(bytes) {
    var units = ['B', 'KB', 'MB', 'GB', 'TB'];
    var value = Number(bytes || 0);
    var i = 0;
    while (value >= 1024 && i < units.length - 1) {
      value = value / 1024;
      i++;
    }
    return Math.round(value * 100) / 100 + ' ' + units[i];
  }

  function saveUiState() {
    try {
      localStorage.setItem('sharehub_ui_state', JSON.stringify({
        page: state.page,
        search: state.search,
        type: state.type,
        sort: state.sort,
        tag: state.tag,
        view: state.view,
        activeTabId: state.activeTabId
      }));
    } catch (e) {}
  }

  function loadUiState() {
    try {
      var raw = localStorage.getItem('sharehub_ui_state');
      if (!raw) return;
      var saved = JSON.parse(raw);

      state.page = saved.page || 1;
      state.search = saved.search || '';
      state.type = saved.type || 'all';
      state.sort = saved.sort || 'newest';
      state.tag = saved.tag || '';
      state.view = saved.view || 'list';
      state.activeTabId = saved.activeTabId || null;
    } catch (e) {}
  }

  function apiGet(action, params) {
    var url = 'api/index.php?action=' + encodeURIComponent(action);
    if (params) {
      Object.keys(params).forEach(function (key) {
        url += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
      });
    }
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

  function debounce(fn, wait) {
    var t;
    return function () {
      var args = arguments;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(null, args); }, wait);
    };
  }

  function switchSession(sessionId) {
    apiPost('switch_session', { session_id: sessionId }).then(function (res) {
      if (!res.ok) {
        alert(res.error || 'Could not switch session');
        return;
      }
      window.location.reload();
    }).catch(function () {
      alert('Could not switch session');
    });
  }

  function setNoteSaveState(text) {
    var el = $('#noteSaveState');
    if (el) el.textContent = text;
  }

  function setNativeFileInputFromState() {
    var fileInput = $('#sharedFile');
    if (!fileInput) return;

    var dt = new DataTransfer();
    state.selectedFiles.forEach(function (file) {
      dt.items.add(file);
    });
    fileInput.files = dt.files;
  }

  function mergeSelectedFiles(fileList) {
    var incoming = Array.prototype.slice.call(fileList || []);
    if (!incoming.length) return;

    incoming.forEach(function (file) {
      var exists = state.selectedFiles.some(function (f) {
        return f.name === file.name && f.size === file.size && f.lastModified === file.lastModified;
      });
      if (!exists) state.selectedFiles.push(file);
    });

    setNativeFileInputFromState();
    renderSelectedFiles();
    updateDuplicateWarning();
  }

  function removeSelectedFile(index) {
    if (index < 0 || index >= state.selectedFiles.length) return;
    state.selectedFiles.splice(index, 1);
    setNativeFileInputFromState();
    renderSelectedFiles();
    updateDuplicateWarning();
  }

  function clearSelectedFiles() {
    state.selectedFiles = [];
    setNativeFileInputFromState();
    renderSelectedFiles();
    updateDuplicateWarning();
  }

  function moveSelectedUploadFile(fromIndex, toIndex) {
    if (fromIndex === toIndex) return;
    if (fromIndex < 0 || toIndex < 0) return;
    if (fromIndex >= state.selectedFiles.length || toIndex >= state.selectedFiles.length) return;

    var moved = state.selectedFiles.splice(fromIndex, 1)[0];
    state.selectedFiles.splice(toIndex, 0, moved);

    setNativeFileInputFromState();
    renderSelectedFiles();
  }

  function updateDuplicateWarning() {
    var el = $('#duplicateWarning');
    if (!el) return;

    var existingNames = Object.keys(state.currentFilesById).map(function (k) {
      return String(state.currentFilesById[k].name || '').toLowerCase();
    });

    var duplicates = state.selectedFiles.filter(function (file) {
      return existingNames.indexOf(String(file.name || '').toLowerCase()) !== -1;
    });

    if (!duplicates.length) {
      el.classList.add('hidden');
      el.textContent = '';
      return;
    }

    el.classList.remove('hidden');
    el.textContent = 'Warning: some selected filenames already exist in the shared list. Upload will still continue.';
  }

  function loadNotes() {
    apiGet('notes_list').then(function (res) {
      if (!res.ok) return;
      state.noteTabs = res.tabs || [];

      if (!state.activeTabId && state.noteTabs.length) {
        state.activeTabId = state.noteTabs[0].id;
      }

      var active = state.noteTabs.find(function (t) { return t.id === state.activeTabId; });
      if (!active && state.noteTabs.length) {
        state.activeTabId = state.noteTabs[0].id;
        active = state.noteTabs[0];
      }

      renderNotesTabs();

      if (active) {
        $('#noteTitle').value = active.title || '';
        $('#noteContent').value = active.content || '';
        state.noteDirty = false;
        setNoteSaveState('Saved');
      }

      saveUiState();
    });
  }

  function renderNotesTabs() {
    var box = $('#noteTabsBar');
    if (!box) return;

    box.innerHTML = state.noteTabs.map(function (tab) {
      var cls = 'note-tab' + (tab.id === state.activeTabId ? ' active' : '');
      return '<button class="' + cls + '" draggable="true" data-tab-id="' + esc(tab.id) + '" type="button">' +
        (tab.pinned ? '<span class="note-pin">★</span>' : '') +
        '<span class="note-tab-text" data-rename-tab="' + esc(tab.id) + '">' + esc(tab.title) + '</span>' +
      '</button>';
    }).join('');

    var dragId = null;

    box.querySelectorAll('[data-tab-id]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        state.activeTabId = this.getAttribute('data-tab-id');
        loadNotes();
      });

      btn.addEventListener('dragstart', function () {
        dragId = this.getAttribute('data-tab-id');
        this.classList.add('dragging');
      });

      btn.addEventListener('dragend', function () {
        this.classList.remove('dragging');
      });

      btn.addEventListener('dragover', function (e) {
        e.preventDefault();
      });

      btn.addEventListener('drop', function (e) {
        e.preventDefault();
        var targetId = this.getAttribute('data-tab-id');
        if (!dragId || dragId === targetId) return;

        var ordered = state.noteTabs.map(function (t) { return t.id; });
        var from = ordered.indexOf(dragId);
        var to = ordered.indexOf(targetId);

        if (from === -1 || to === -1) return;

        var moved = ordered.splice(from, 1)[0];
        ordered.splice(to, 0, moved);

        apiPost('note_reorder', { ids: ordered.join(',') }).then(function (res) {
          if (res.ok) loadNotes();
        });
      });
    });

    $all('[data-rename-tab]').forEach(function (el) {
      el.addEventListener('dblclick', function (e) {
        e.stopPropagation();
        var id = this.getAttribute('data-rename-tab');
        var current = this.textContent || '';
        var next = prompt('Rename tab:', current);
        if (next === null) return;
        apiPost('note_rename', { id: id, title: next }).then(function (res) {
          if (res.ok) loadNotes();
          else alert(res.error || 'Could not rename tab');
        });
      });
    });
  }

  function saveCurrentNote() {
    if (!boot.canEdit) return Promise.resolve();
    if (!state.activeTabId) return Promise.resolve();

    setNoteSaveState('Saving…');

    return apiPost('note_save', {
      id: state.activeTabId,
      title: $('#noteTitle').value,
      content: $('#noteContent').value
    }).then(function (res) {
      if (res.ok) {
        state.noteDirty = false;
        setNoteSaveState('Saved');
        loadNotes();
      } else {
        setNoteSaveState('Save failed');
        alert(res.error || 'Could not save note');
      }
    });
  }

  function scheduleNoteAutosave() {
    if (!boot.canEdit) return;
    state.noteDirty = true;
    setNoteSaveState('Unsaved');

    clearTimeout(state.noteAutosaveTimer);
    state.noteAutosaveTimer = setTimeout(function () {
      saveCurrentNote();
    }, 1000);
  }

  function createTab() {
    var title = prompt('New tab title:', 'New Tab');
    if (title === null) return;
    apiPost('note_create', { title: title }).then(function (res) {
      if (res.ok) loadNotes();
      else alert(res.error || 'Could not create tab');
    });
  }

  function deleteTab() {
    if (!state.activeTabId) return;
    if (!confirm('Delete this tab?')) return;

    apiPost('note_delete', { id: state.activeTabId }).then(function (res) {
      if (res.ok) {
        state.activeTabId = null;
        loadNotes();
      } else {
        alert(res.error || 'Could not delete tab');
      }
    });
  }

  function pinTab() {
    if (!state.activeTabId) return;
    apiPost('note_pin', { id: state.activeTabId }).then(function (res) {
      if (res.ok) loadNotes();
      else alert(res.error || 'Could not pin tab');
    });
  }

  function exportNote() {
    if (!state.activeTabId) return;
    window.location.href = 'api/index.php?action=note_export&id=' + encodeURIComponent(state.activeTabId);
  }

  function renderRecentFiles(items) {
    var box = $('#recentFilesStrip');
    if (!box) return;

    if (!items || !items.length) {
      box.innerHTML = '';
      return;
    }

    box.innerHTML = items.map(function (file) {
      return '<div class="recent-file-card">' +
        '<div class="recent-file-name">' + esc(file.name) + '</div>' +
        '<div class="recent-file-meta">' + esc(file.size_text || '') + '</div>' +
      '</div>';
    }).join('');
  }

  function renderTagFilters(tags) {
    var box = $('#tagFilterBar');
    if (!box) return;

    var chips = ['<button class="tag-chip ' + (state.tag === '' ? 'active' : '') + '" data-tag-filter="" type="button">All Tags</button>'];
    (tags || []).forEach(function (tag) {
      chips.push('<button class="tag-chip ' + (state.tag === tag ? 'active' : '') + '" data-tag-filter="' + esc(tag) + '" type="button">#' + esc(tag) + '</button>');
    });

    box.innerHTML = chips.join('');

    $all('[data-tag-filter]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        state.tag = this.getAttribute('data-tag-filter') || '';
        state.page = 1;
        loadFiles();
      });
    });
  }

  function fileTagsHtml(tags) {
    if (!tags || !tags.length) return '';
    return '<div class="file-tags">' + tags.map(function (tag) {
      return '<span class="inline-tag">#' + esc(tag) + '</span>';
    }).join('') + '</div>';
  }

  function renderFileList(items) {
    var box = $('#listView');
    if (!box) return;

    if (!items.length) {
      box.innerHTML = '<div class="file-card"><div class="muted">No files found in this session.</div></div>';
      return;
    }

    box.innerHTML = items.map(function (file) {
      var checked = state.existingSelected[file.storage_name] ? 'checked' : '';
      return '' +
        '<div class="file-card">' +
          '<div class="file-top">' +
            '<div class="file-top-left">' +
              '<input class="file-check" type="checkbox" data-file-check="' + esc(file.storage_name) + '" ' + checked + '>' +
              '<div>' +
                '<h3>' + esc(file.name) + '</h3>' +
                '<div class="file-top-meta">' + esc(file.size_text) + ' • ' + esc(file.mtime_text) + '</div>' +
                fileTagsHtml(file.tags || []) +
              '</div>' +
            '</div>' +
            '<div class="file-actions">' +
              '<button class="ghost-btn" type="button" data-copy-link="' + esc(file.download_url) + '">Copy Link</button>' +
              '<button class="ghost-btn" type="button" data-file-details="' + esc(file.storage_name) + '">Details</button>' +
              '<a class="primary-btn" href="' + esc(file.download_url) + '">Download</a>' +
              (file.category === 'image' || file.category === 'video' || file.category === 'audio' || file.category === 'pdf'
                ? '<a class="ghost-btn" href="' + esc(file.media_url) + '" target="_blank">Open</a>'
                : '') +
            '</div>' +
          '</div>' +
        '</div>';
    }).join('');
  }

  function renderGallery(items) {
    var box = $('#galleryView');
    if (!box) return;

    var previewable = items.filter(function (f) {
      return ['image', 'video', 'audio', 'pdf'].indexOf(f.category) !== -1;
    });

    state.lightboxImages = previewable.filter(function (f) { return f.category === 'image'; });

    if (!previewable.length) {
      box.innerHTML = '<div class="file-card"><div class="muted">No previewable files in this session.</div></div>';
      return;
    }

    box.innerHTML = previewable.map(function (file) {
      var thumb = file.has_thumb && file.thumb_url
        ? '<img loading="lazy" src="' + esc(file.thumb_url) + '" alt="' + esc(file.name) + '" data-lightbox-image="' + esc(file.storage_name) + '">'
        : '<div class="muted">Preview</div>';
      var checked = state.existingSelected[file.storage_name] ? 'checked' : '';

      return '' +
        '<div class="gallery-card">' +
          '<div class="thumb-frame">' + thumb + '</div>' +
          '<div class="gallery-body">' +
            '<div class="gallery-top">' +
              '<input class="file-check" type="checkbox" data-file-check="' + esc(file.storage_name) + '" ' + checked + '>' +
              '<button class="ghost-btn" type="button" data-file-details="' + esc(file.storage_name) + '">Details</button>' +
            '</div>' +
            '<div class="gallery-title">' + esc(file.name) + '</div>' +
            '<div class="muted small">' + esc(file.size_text) + '</div>' +
            fileTagsHtml(file.tags || []) +
            '<div class="file-actions" style="margin-top:10px;">' +
              '<button class="ghost-btn" type="button" data-copy-link="' + esc(file.download_url) + '">Copy Link</button>' +
              '<a class="primary-btn" href="' + esc(file.download_url) + '">Download</a>' +
              '<a class="ghost-btn" href="' + esc(file.media_url) + '" target="_blank">Open</a>' +
            '</div>' +
          '</div>' +
        '</div>';
    }).join('');
  }

  function bindRenderedFileActions() {
    $all('[data-file-check]').forEach(function (el) {
      el.addEventListener('change', function () {
        var key = this.getAttribute('data-file-check');
        if (this.checked) state.existingSelected[key] = true;
        else delete state.existingSelected[key];
        updateSelectionBar();
      });
    });

    $all('[data-file-details]').forEach(function (el) {
      el.addEventListener('click', function () {
        openFileModal(this.getAttribute('data-file-details'));
      });
    });

    $all('[data-copy-link]').forEach(function (el) {
      el.addEventListener('click', function () {
        copyLink(this.getAttribute('data-copy-link'));
      });
    });

    $all('[data-lightbox-image]').forEach(function (el) {
      el.addEventListener('click', function () {
        var id = this.getAttribute('data-lightbox-image');
        openLightboxByStorage(id);
      });
    });
  }

  function loadFiles() {
    apiGet('files_list', {
      search: state.search,
      type: state.type,
      sort: state.sort,
      tag: state.tag,
      page: state.page,
      page_size: boot.pageSize || 24
    }).then(function (res) {
      if (!res.ok) return;

      state.page = res.page || 1;
      state.pages = res.pages || 1;
      state.currentVisibleFiles = res.items || [];
      state.recentFiles = res.recent || [];
      state.allTags = res.tags || [];
      state.currentFilesById = {};

      state.currentVisibleFiles.forEach(function (file) {
        state.currentFilesById[file.storage_name] = file;
      });
      state.recentFiles.forEach(function (file) {
        state.currentFilesById[file.storage_name] = file;
      });

      renderRecentFiles(state.recentFiles);
      renderTagFilters(state.allTags);
      renderFileList(state.currentVisibleFiles);
      renderGallery(state.currentVisibleFiles);
      bindRenderedFileActions();
      updateSelectionBar();
      updateDuplicateWarning();
      $('#pageText').textContent = 'Page ' + state.page + ' / ' + state.pages;
      saveUiState();
    });
  }

  function syncView() {
    if (state.view === 'gallery') {
      $('#listView').classList.add('hidden');
      $('#galleryView').classList.remove('hidden');
      $('#galleryViewBtn').classList.add('active');
      $('#listViewBtn').classList.remove('active');
    } else {
      $('#galleryView').classList.add('hidden');
      $('#listView').classList.remove('hidden');
      $('#listViewBtn').classList.add('active');
      $('#galleryViewBtn').classList.remove('active');
    }
    saveUiState();
  }

  function renderSelectedFiles() {
    var panel = $('#selectedFilesPanel');
    var textEl = $('#selectedFileText');
    var listEl = $('#selectedFilesList');

    if (!panel || !textEl || !listEl) return;

    if (!state.selectedFiles.length) {
      panel.classList.add('hidden');
      textEl.textContent = 'No files selected';
      listEl.innerHTML = '';
      return;
    }

    panel.classList.remove('hidden');

    var totalBytes = state.selectedFiles.reduce(function (sum, file) {
      return sum + Number(file.size || 0);
    }, 0);

    textEl.textContent = 'Total size: ' + formatBytes(totalBytes);

    listEl.innerHTML = state.selectedFiles.map(function (file, index) {
      return '' +
        '<div class="selected-file-item" draggable="true" data-upload-index="' + index + '">' +
          '<div class="selected-file-left">' +
            '<div class="selected-file-name">' + esc(file.name) + '</div>' +
            '<div class="selected-file-size">' + esc(formatBytes(file.size || 0)) + '</div>' +
          '</div>' +
          '<div class="selected-file-actions">' +
            '<button class="selected-file-remove" type="button" data-remove-index="' + index + '">Remove</button>' +
          '</div>' +
        '</div>';
    }).join('');

    listEl.querySelectorAll('[data-remove-index]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        removeSelectedFile(parseInt(this.getAttribute('data-remove-index'), 10));
      });
    });

    var dragIndex = null;
    listEl.querySelectorAll('[data-upload-index]').forEach(function (row) {
      row.addEventListener('dragstart', function () {
        dragIndex = parseInt(this.getAttribute('data-upload-index'), 10);
      });
      row.addEventListener('dragover', function (e) {
        e.preventDefault();
      });
      row.addEventListener('drop', function (e) {
        e.preventDefault();
        var targetIndex = parseInt(this.getAttribute('data-upload-index'), 10);
        if (dragIndex === null) return;
        moveSelectedUploadFile(dragIndex, targetIndex);
      });
    });
  }

  function updateSelectionBar() {
    var bar = $('#selectionBar');
    var text = $('#selectionBarText');
    if (!bar || !text) return;

    var keys = Object.keys(state.existingSelected);
    if (!keys.length) {
      bar.classList.add('hidden');
      text.textContent = 'No files selected';
      return;
    }

    var totalBytes = 0;
    keys.forEach(function (key) {
      if (state.currentFilesById[key]) totalBytes += Number(state.currentFilesById[key].size || 0);
    });

    text.textContent = keys.length + ' uploaded file(s) selected • ' + formatBytes(totalBytes);
    bar.classList.remove('hidden');
  }

  function openFileModal(storageName) {
    var file = state.currentFilesById[storageName];
    if (!file) return;

    state.currentModalFile = storageName;

    $('#modalFileName').textContent = file.name || 'File';
    $('#modalFileMeta').textContent = (file.size_text || '') + ' • ' + (file.mtime_text || '');
    $('#modalEditTitle').value = file.title || '';
    $('#modalFileUploader').textContent = file.uploader || '-';
    $('#modalEditDescription').value = file.description || '';
    $('#modalEditTags').value = (file.tags || []).join(', ');
    $('#modalDownloadBtn').href = file.download_url || '#';
    $('#modalOpenBtn').href = file.media_url || file.download_url || '#';
    $('#modalCopyLinkBtn').setAttribute('data-copy-link', file.download_url || '#');

    var preview = $('#modalFilePreview');
    if (file.category === 'image') {
      preview.innerHTML = '<img src="' + esc(file.media_url) + '" alt="' + esc(file.name) + '">';
    } else if (file.category === 'video') {
      preview.innerHTML = '<video src="' + esc(file.media_url) + '" controls preload="metadata"></video>';
    } else if (file.category === 'audio') {
      preview.innerHTML = '<audio src="' + esc(file.media_url) + '" controls></audio>';
    } else if (file.category === 'pdf') {
      preview.innerHTML = '<a class="primary-btn" href="' + esc(file.media_url) + '" target="_blank">Open PDF</a>';
    } else {
      preview.innerHTML = '<div class="muted">No inline preview available.</div>';
    }

    $('#fileModal').classList.remove('hidden');
  }

  function saveModalMetadata() {
    if (!state.currentModalFile) return;

    apiPost('file_update_meta', {
      file: state.currentModalFile,
      title: $('#modalEditTitle').value,
      description: $('#modalEditDescription').value,
      tags: $('#modalEditTags').value
    }).then(function (res) {
      if (res.ok) {
        loadFiles();
        closeFileModal();
      } else {
        alert(res.error || 'Could not save metadata');
      }
    });
  }

  function closeFileModal() {
    $('#fileModal').classList.add('hidden');
    state.currentModalFile = null;
  }

  function absoluteUrl(path) {
    var base = window.location.origin + window.location.pathname.replace(/index\.php$/, '');
    if (path.indexOf('http') === 0) return path;
    return base + path;
  }

  function copyLink(path) {
    var url = absoluteUrl(path);
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(function () {
        alert('Link copied');
      }).catch(function () {
        prompt('Copy this link:', url);
      });
    } else {
      prompt('Copy this link:', url);
    }
  }

  function openLightboxByStorage(storageName) {
    var index = state.lightboxImages.findIndex(function (f) {
      return f.storage_name === storageName;
    });
    if (index === -1) return;
    state.lightboxIndex = index;
    renderLightbox();
    $('#lightboxModal').classList.remove('hidden');
  }

  function renderLightbox() {
    var file = state.lightboxImages[state.lightboxIndex];
    if (!file) return;
    $('#lightboxContent').innerHTML = '<img src="' + esc(file.media_url) + '" alt="' + esc(file.name) + '">';
  }

  function closeLightbox() {
    $('#lightboxModal').classList.add('hidden');
  }

  function lightboxPrev() {
    if (!state.lightboxImages.length) return;
    state.lightboxIndex = (state.lightboxIndex - 1 + state.lightboxImages.length) % state.lightboxImages.length;
    renderLightbox();
  }

  function lightboxNext() {
    if (!state.lightboxImages.length) return;
    state.lightboxIndex = (state.lightboxIndex + 1) % state.lightboxImages.length;
    renderLightbox();
  }

  function downloadSelectedExisting() {
    var keys = Object.keys(state.existingSelected);
    if (!keys.length) {
      alert('Select at least one uploaded file.');
      return;
    }

    var names = keys.map(function (k) {
      return state.currentFilesById[k] ? state.currentFilesById[k].name : k;
    });

    var preview = names.slice(0, 8).join('\n');
    if (names.length > 8) preview += '\n...';

    if (!confirm('Download these selected files as ZIP?\n\n' + preview)) return;

    var url = 'api/index.php?action=batch_download';
    keys.forEach(function (key) {
      url += '&files[]=' + encodeURIComponent(key);
    });
    window.location.href = url;
  }

  function trashSelectedExisting() {
    var keys = Object.keys(state.existingSelected);
    if (!keys.length) {
      alert('Select at least one uploaded file.');
      return;
    }

    if (!confirm('Move selected files to Trash?')) return;

    apiPost('files_bulk_trash', {
      files: keys.join(',')
    }).then(function (res) {
      if (res.ok) {
        state.existingSelected = {};
        loadFiles();
      } else {
        alert(res.error || 'Could not move files to trash');
      }
    });
  }

  function initUpload() {
    if (!boot.canEdit) return;

    var fileInput = $('#sharedFile');
    var dropZone = $('#dropZone');
    var uploadForm = $('#uploadForm');
    var chooseFilesBtn = $('#chooseFilesBtn');
    var chooseMoreFilesBtn = $('#chooseMoreFilesBtn');
    var clearSelectedFilesBtn = $('#clearSelectedFilesBtn');

    if (!fileInput || !uploadForm) return;

    if (chooseFilesBtn) chooseFilesBtn.addEventListener('click', function () { fileInput.click(); });
    if (chooseMoreFilesBtn) chooseMoreFilesBtn.addEventListener('click', function () { fileInput.click(); });
    if (clearSelectedFilesBtn) clearSelectedFilesBtn.addEventListener('click', function () { clearSelectedFiles(); });

    if (dropZone) {
      dropZone.addEventListener('click', function () { fileInput.click(); });

      ['dragenter', 'dragover'].forEach(function (evt) {
        dropZone.addEventListener(evt, function (e) {
          e.preventDefault();
          dropZone.classList.add('drag');
        });
      });

      ['dragleave', 'drop'].forEach(function (evt) {
        dropZone.addEventListener(evt, function (e) {
          e.preventDefault();
          dropZone.classList.remove('drag');
        });
      });

      dropZone.addEventListener('drop', function (e) {
        if (e.dataTransfer.files && e.dataTransfer.files.length) mergeSelectedFiles(e.dataTransfer.files);
      });
    }

    fileInput.addEventListener('change', function () {
      mergeSelectedFiles(fileInput.files);
    });

    document.addEventListener('paste', function (e) {
      if (!boot.canEdit) return;

      var items = (e.clipboardData && e.clipboardData.items) ? e.clipboardData.items : [];
      var pasted = [];

      for (var i = 0; i < items.length; i++) {
        var item = items[i];
        if (item.kind === 'file') {
          var file = item.getAsFile();
          if (file && file.type && file.type.indexOf('image/') === 0) {
            var ext = file.type.split('/')[1] || 'png';
            var renamed = new File([file], 'pasted-image-' + Date.now() + '-' + i + '.' + ext, { type: file.type });
            pasted.push(renamed);
          }
        }
      }

      if (pasted.length) {
        mergeSelectedFiles(pasted);
      }
    });

    uploadForm.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!state.selectedFiles.length) {
        alert('Choose at least one file.');
        return;
      }

      var progressWrap = $('#uploadProgressWrap');
      var progressBar = $('#uploadProgressBar');
      var uploadStatus = $('#uploadStatus');

      var fd = new FormData();
      state.selectedFiles.forEach(function (file) {
        fd.append('shared_file[]', file);
      });

      var xhr = new XMLHttpRequest();
      xhr.open('POST', 'api/index.php?action=upload');

      if (progressWrap) progressWrap.classList.remove('hidden');
      if (progressBar) progressBar.style.width = '0%';
      if (uploadStatus) uploadStatus.textContent = 'Uploading…';

      xhr.upload.addEventListener('progress', function (evt) {
        if (!evt.lengthComputable) return;
        var pct = Math.round((evt.loaded / evt.total) * 100);
        if (progressBar) progressBar.style.width = pct + '%';
        if (uploadStatus) uploadStatus.textContent = 'Uploading… ' + pct + '%';
      });

      xhr.onload = function () {
        try {
          var res = JSON.parse(xhr.responseText);
          if (res.ok) {
            if (uploadStatus) uploadStatus.textContent = 'Upload complete';
            clearSelectedFiles();
            loadFiles();
          } else {
            if (uploadStatus) uploadStatus.textContent = res.error || 'Upload failed';
          }
        } catch (e) {
          if (uploadStatus) uploadStatus.textContent = 'Upload failed';
        }
      };

      xhr.onerror = function () {
        if (uploadStatus) uploadStatus.textContent = 'Upload error';
      };

      xhr.send(fd);
    });
  }

  function pollVersions() {
    apiGet('poll').then(function (res) {
      if (!res.ok) return;

      var next = res.versions || {};

      if (next.notes !== state.versions.notes) {
        state.versions.notes = next.notes;
        loadNotes();
      }

      if (next.files !== state.versions.files) {
        state.versions.files = next.files;
        loadFiles();
      }

      $('#liveStatus').textContent = 'Live sync OK';
    }).catch(function () {
      $('#liveStatus').textContent = 'Retrying…';
    });
  }

  function renameCurrentSession() {
    apiGet('session_info').then(function (res) {
      if (!res.ok) {
        alert(res.error || 'Could not load session info');
        return;
      }

      var session = res.current_session || {};
      var nextName = prompt('Rename session:', session.name || 'Session');
      if (nextName === null) return;

      apiPost('admin_update_session', {
        id: session.id || '',
        name: nextName,
        description: session.description || '',
        brand_title: session.brand_title || nextName,
        brand_color: session.brand_color || '#2563eb',
        accent_color: session.accent_color || '#0f172a',
        archive_mode: session.archive_mode ? '1' : ''
      }).then(function (r) {
        if (!r.ok) {
          alert(r.error || 'Could not rename session');
          return;
        }
        window.location.reload();
      });
    });
  }

  function deleteCurrentSession() {
    apiGet('session_info').then(function (res) {
      if (!res.ok) {
        alert(res.error || 'Could not load session info');
        return;
      }

      var session = res.current_session || {};
      apiGet('admin_delete_session_check', { session_id: session.id || '' }).then(function (impact) {
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
          session_id: session.id || '',
          confirmed: '1'
        }).then(function (r) {
          if (!r.ok) {
            alert(r.error || 'Could not delete session');
            return;
          }
          window.location.reload();
        });
      });
    });
  }

  function bindUi() {
    if ($('#renameCurrentSessionBtn')) {
      $('#renameCurrentSessionBtn').addEventListener('click', renameCurrentSession);
    }

    if ($('#deleteCurrentSessionBtn')) {
      $('#deleteCurrentSessionBtn').addEventListener('click', deleteCurrentSession);
    }

    if ($('#saveNoteBtn')) $('#saveNoteBtn').addEventListener('click', saveCurrentNote);
    if ($('#newTabBtn')) $('#newTabBtn').addEventListener('click', createTab);
    if ($('#deleteTabBtn')) $('#deleteTabBtn').addEventListener('click', deleteTab);
    if ($('#pinTabBtn')) $('#pinTabBtn').addEventListener('click', pinTab);
    if ($('#exportNoteBtn')) $('#exportNoteBtn').addEventListener('click', exportNote);

    if ($('#sessionSwitcher')) {
      $('#sessionSwitcher').addEventListener('change', function () {
        switchSession(this.value);
      });
    }

    if ($('#noteTitle')) {
      $('#noteTitle').addEventListener('input', function () {
        scheduleNoteAutosave();
        saveUiState();
      });
    }

    if ($('#noteContent')) {
      $('#noteContent').addEventListener('input', scheduleNoteAutosave);
    }

    var searchInput = $('#searchInput');
    var typeFilter = $('#typeFilter');
    var sortSelect = $('#sortSelect');
    var clearFiltersBtn = $('#clearFiltersBtn');
    var listViewBtn = $('#listViewBtn');
    var galleryViewBtn = $('#galleryViewBtn');
    var prevPageBtn = $('#prevPageBtn');
    var nextPageBtn = $('#nextPageBtn');
    var selectVisibleBtn = $('#selectVisibleBtn');
    var clearVisibleSelectionBtn = $('#clearVisibleSelectionBtn');

    if (searchInput) searchInput.value = state.search;
    if (typeFilter) typeFilter.value = state.type;
    if (sortSelect) sortSelect.value = state.sort;

    if (searchInput) {
      searchInput.addEventListener('input', debounce(function (e) {
        state.search = e.target.value || '';
        state.page = 1;
        loadFiles();
      }, 220));
    }

    if (typeFilter) {
      typeFilter.addEventListener('change', function () {
        state.type = this.value;
        state.page = 1;
        loadFiles();
      });
    }

    if (sortSelect) {
      sortSelect.addEventListener('change', function () {
        state.sort = this.value;
        state.page = 1;
        loadFiles();
      });
    }

    if (clearFiltersBtn) {
      clearFiltersBtn.addEventListener('click', function () {
        state.search = '';
        state.type = 'all';
        state.sort = 'newest';
        state.tag = '';
        state.page = 1;
        if (searchInput) searchInput.value = '';
        if (typeFilter) typeFilter.value = 'all';
        if (sortSelect) sortSelect.value = 'newest';
        loadFiles();
      });
    }

    if (listViewBtn) {
      listViewBtn.addEventListener('click', function () {
        state.view = 'list';
        syncView();
      });
    }

    if (galleryViewBtn) {
      galleryViewBtn.addEventListener('click', function () {
        state.view = 'gallery';
        syncView();
      });
    }

    if (prevPageBtn) {
      prevPageBtn.addEventListener('click', function () {
        if (state.page > 1) {
          state.page--;
          loadFiles();
        }
      });
    }

    if (nextPageBtn) {
      nextPageBtn.addEventListener('click', function () {
        if (state.page < state.pages) {
          state.page++;
          loadFiles();
        }
      });
    }

    if (selectVisibleBtn) {
      selectVisibleBtn.addEventListener('click', function () {
        state.currentVisibleFiles.forEach(function (file) {
          state.existingSelected[file.storage_name] = true;
        });
        loadFiles();
      });
    }

    if (clearVisibleSelectionBtn) {
      clearVisibleSelectionBtn.addEventListener('click', function () {
        state.existingSelected = {};
        loadFiles();
      });
    }

    if ($('#downloadSelectedExistingBtn')) {
      $('#downloadSelectedExistingBtn').addEventListener('click', downloadSelectedExisting);
    }

    if ($('#trashSelectedExistingBtn')) {
      $('#trashSelectedExistingBtn').addEventListener('click', trashSelectedExisting);
    }

    if ($('#closeFileModalBtn')) {
      $('#closeFileModalBtn').addEventListener('click', closeFileModal);
    }

    $all('[data-close-modal]').forEach(function (el) {
      el.addEventListener('click', closeFileModal);
    });

    if ($('#modalCopyLinkBtn')) {
      $('#modalCopyLinkBtn').addEventListener('click', function () {
        copyLink(this.getAttribute('data-copy-link'));
      });
    }

    if ($('#modalSaveMetaBtn')) {
      $('#modalSaveMetaBtn').addEventListener('click', saveModalMetadata);
    }

    if ($('#lightboxCloseBtn')) {
      $('#lightboxCloseBtn').addEventListener('click', closeLightbox);
    }

    if ($('#lightboxPrevBtn')) {
      $('#lightboxPrevBtn').addEventListener('click', lightboxPrev);
    }

    if ($('#lightboxNextBtn')) {
      $('#lightboxNextBtn').addEventListener('click', lightboxNext);
    }

    $all('[data-close-lightbox]').forEach(function (el) {
      el.addEventListener('click', closeLightbox);
    });

    document.addEventListener('keydown', function (e) {
      if ($('#lightboxModal') && !$('#lightboxModal').classList.contains('hidden')) {
        if (e.key === 'Escape') closeLightbox();
        if (e.key === 'ArrowLeft') lightboxPrev();
        if (e.key === 'ArrowRight') lightboxNext();
      }
      if ($('#fileModal') && !$('#fileModal').classList.contains('hidden') && e.key === 'Escape') {
        closeFileModal();
      }
    });
  }

  function init() {
    loadUiState();
    bindUi();
    initUpload();
    syncView();
    loadNotes();
    loadFiles();
    pollVersions();
    setInterval(pollVersions, boot.pollIntervalMs || 3000);
  }

  document.addEventListener('DOMContentLoaded', init);
})();