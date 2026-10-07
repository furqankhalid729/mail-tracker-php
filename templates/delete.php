<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_post();

$t = find_or_404('email_templates', input_int('id'));
q('DELETE FROM email_templates WHERE id = ? AND workspace_id = ?', [$t['id'], ws_id()]);
flash('success', 'Template “' . $t['name'] . '” deleted.');
redirect('templates/index.php');
