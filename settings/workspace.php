<?php
/** Switch the active workspace or create a new one. */
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_post();

if (input('action') === 'switch') {
    $wsId = input_int('workspace_id');
    // Membership is verified server-side; the posted id is only a request
    if (q_val('SELECT id FROM workspace_members WHERE workspace_id = ? AND user_id = ?', [$wsId, user_id()])) {
        $_SESSION['workspace_id'] = $wsId;
        q('UPDATE users SET current_workspace_id = ? WHERE id = ?', [$wsId, user_id()]);
        unset($_SESSION['import']);
        flash('success', 'Switched workspace.');
    } else {
        flash('error', 'You are not a member of that workspace.');
    }
    redirect('dashboard.php');
}

if (input('action') === 'create') {
    $name = trim((string) input('name'));
    if ($name === '' || mb_strlen($name) > 120) {
        flash('error', 'Workspace name is required (max 120 characters).');
        redirect(url('settings/index.php', ['tab' => 'workspace']));
    }
    $wsId = transaction(function () use ($name) {
        $id = db_insert('workspaces', ['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        db_insert('workspace_members', ['workspace_id' => $id, 'user_id' => user_id(), 'role' => 'owner', 'created_at' => now()]);
        return $id;
    });
    $_SESSION['workspace_id'] = $wsId;
    q('UPDATE users SET current_workspace_id = ? WHERE id = ?', [$wsId, user_id()]);
    flash('success', 'Workspace “' . $name . '” created.');
    redirect('dashboard.php');
}
redirect('settings/index.php');
