<?php

function load_notes_state() {
    $state = load_json(current_session_notes_file(), ['tabs' => []]);

    if (empty($state['tabs'])) {
        $state['tabs'] = [[
            'id' => 'tab_' . time(),
            'title' => 'Shared Note',
            'content' => '',
            'updated_at' => now_text(),
            'pinned' => false,
            'sort_order' => 1,
        ]];
        save_notes_state($state);
    }

    foreach ($state['tabs'] as $i => $tab) {
        if (!isset($state['tabs'][$i]['pinned'])) $state['tabs'][$i]['pinned'] = false;
        if (!isset($state['tabs'][$i]['sort_order'])) $state['tabs'][$i]['sort_order'] = $i + 1;
    }

    usort($state['tabs'], function ($a, $b) {
        if (!empty($a['pinned']) !== !empty($b['pinned'])) {
            return !empty($a['pinned']) ? -1 : 1;
        }
        return (int)$a['sort_order'] <=> (int)$b['sort_order'];
    });

    return $state;
}

function save_notes_state($state) {
    save_json(current_session_notes_file(), $state);
    bump_version('notes');
}

function list_note_tabs() {
    $state = load_notes_state();
    return $state['tabs'];
}

function get_note_tab($id) {
    $tabs = list_note_tabs();
    foreach ($tabs as $tab) {
        if ($tab['id'] === $id) return $tab;
    }
    return null;
}

function create_note_tab($title) {
    $state = load_notes_state();
    $maxSort = 0;
    foreach ($state['tabs'] as $tab) {
        $maxSort = max($maxSort, (int)$tab['sort_order']);
    }

    $state['tabs'][] = [
        'id' => 'tab_' . bin2hex(random_bytes(4)),
        'title' => mb_substr(trim((string)$title) ?: 'Untitled Tab', 0, 80),
        'content' => '',
        'updated_at' => now_text(),
        'pinned' => false,
        'sort_order' => $maxSort + 1,
    ];

    save_notes_state($state);
    add_activity('note', 'Created note tab', ['session_id' => current_session_id()]);
}

function update_note_tab($id, $title, $content) {
    $state = load_notes_state();

    foreach ($state['tabs'] as &$tab) {
        if ($tab['id'] === $id) {
            $tab['title'] = mb_substr(trim((string)$title) ?: 'Untitled Tab', 0, 80);
            $tab['content'] = (string)$content;
            $tab['updated_at'] = now_text();
            save_notes_state($state);
            add_activity('note', 'Updated note tab', ['session_id' => current_session_id()]);
            return true;
        }
    }

    return false;
}

function rename_note_tab_only($id, $title) {
    $state = load_notes_state();

    foreach ($state['tabs'] as &$tab) {
        if ($tab['id'] === $id) {
            $tab['title'] = mb_substr(trim((string)$title) ?: 'Untitled Tab', 0, 80);
            $tab['updated_at'] = now_text();
            save_notes_state($state);
            add_activity('note', 'Renamed note tab', ['session_id' => current_session_id()]);
            return true;
        }
    }

    return false;
}

function delete_note_tab($id) {
    $state = load_notes_state();
    if (count($state['tabs']) <= 1) return false;

    $next = [];
    $deleted = false;

    foreach ($state['tabs'] as $tab) {
        if ($tab['id'] === $id) {
            $deleted = true;
            continue;
        }
        $next[] = $tab;
    }

    if (!$deleted) return false;

    $state['tabs'] = array_values($next);

    foreach ($state['tabs'] as $i => $tab) {
        $state['tabs'][$i]['sort_order'] = $i + 1;
    }

    save_notes_state($state);
    add_activity('note', 'Deleted note tab', ['session_id' => current_session_id()]);
    return true;
}

function toggle_note_pin($id) {
    $state = load_notes_state();

    foreach ($state['tabs'] as &$tab) {
        if ($tab['id'] === $id) {
            $tab['pinned'] = empty($tab['pinned']);
            save_notes_state($state);
            add_activity('note', $tab['pinned'] ? 'Pinned note tab' : 'Unpinned note tab', ['session_id' => current_session_id()]);
            return true;
        }
    }

    return false;
}

function reorder_note_tabs($orderedIds) {
    $state = load_notes_state();
    $map = [];

    foreach ($state['tabs'] as $tab) {
        $map[$tab['id']] = $tab;
    }

    $next = [];
    $sort = 1;

    foreach ($orderedIds as $id) {
        if (isset($map[$id])) {
            $tab = $map[$id];
            $tab['sort_order'] = $sort++;
            $next[] = $tab;
            unset($map[$id]);
        }
    }

    foreach ($map as $tab) {
        $tab['sort_order'] = $sort++;
        $next[] = $tab;
    }

    $state['tabs'] = $next;
    save_notes_state($state);
    add_activity('note', 'Reordered note tabs', ['session_id' => current_session_id()]);
    return true;
}