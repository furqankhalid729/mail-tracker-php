<?php
/**
 * Lead state changes.
 *   status  id, status_id[, note]       anyone who can see the lead (closers on their own leads)
 *   note    id, note
 *   assign  id, user_id (0 = unassign)  leads.manage
 *   delete  id                          leads.manage
 *   bulk    ids[] | all_matching + filters, bulk = set_status | assign | delete
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_post();
$ws = ws_id();
$action = (string) input('action');

if ($action === 'bulk') {
    $bulk = (string) input('bulk');
    if (in_array($bulk, ['assign', 'delete'], true)) {
        require_permission('leads.manage');
    }
    if ((string) input('all_matching') === '1') {
        [$where, $params] = lead_filter_sql(lead_filters_from(json_decode((string) input('filters'), true) ?: []));
    } else {
        $ids = input_ids('ids');
        if (!$ids) {
            flash('error', 'Select at least one lead.');
            redirect_back('leads/index.php');
        }
        [$where, $params] = lead_filter_sql([]);
        $where .= ' AND l.id IN (' . placeholders($ids) . ')';
        $params = [...$params, ...$ids];
    }

    if ($bulk === 'set_status') {
        $statusId = input_int('status_id');
        $statuses = lead_statuses($ws);
        if (!isset($statuses[$statusId])) {
            flash('error', 'Choose a status.');
            redirect_back('leads/index.php');
        }
        $n = 0;
        foreach (q_all("SELECT l.id, l.status_id FROM leads l WHERE $where AND NOT (l.status_id <=> ?)", [...$params, $statusId]) as $lead) {
            lead_change_status($lead, $statusId);
            $n++;
        }
        flash('success', number_format($n) . ' lead' . ($n === 1 ? '' : 's') . ' set to ' . $statuses[$statusId]['name'] . '.');
    } elseif ($bulk === 'assign') {
        $userId = input_int('user_id') ?: null;
        if ($userId && !is_workspace_user($userId)) {
            flash('error', 'Choose a team member.');
            redirect_back('leads/index.php');
        }
        $n = leads_assign($where, $params, $userId);
        flash('success', number_format($n) . ' lead' . ($n === 1 ? '' : 's') . ($userId ? ' assigned.' : ' unassigned.'));
    } elseif ($bulk === 'delete') {
        $ids = array_map('intval', q_col("SELECT l.id FROM leads l WHERE $where", $params));
        foreach (array_chunk($ids, 1000) as $chunk) {
            q('DELETE FROM leads WHERE workspace_id = ? AND id IN (' . placeholders($chunk) . ')', [$ws, ...$chunk]);
        }
        flash('success', number_format(count($ids)) . ' lead' . (count($ids) === 1 ? '' : 's') . ' deleted.');
    } else {
        flash('error', 'Choose an action.');
    }
    redirect_back('leads/index.php');
}

$lead = find_lead_or_404(input_int('id'));
$id = (int) $lead['id'];
$note = mb_substr(trim((string) input('note')), 0, 10000);

switch ($action) {
    case 'status':
        if (!lead_change_status($lead, input_int('status_id'), $note)) {
            flash('error', 'Choose a status.');
        } elseif (!is_ajax()) {
            flash('success', 'Status updated.');
        }
        break;

    case 'note':
        if ($note === '') {
            flash('error', 'Write a note first.');
            break;
        }
        log_lead_activity($id, 'note', $note);
        db_update('leads', ['updated_at' => now()], 'id = ?', [$id]);
        flash('success', 'Note added.');
        break;

    case 'assign':
        require_permission('leads.manage');
        $userId = input_int('user_id') ?: null;
        if ($userId && !is_workspace_user($userId)) {
            flash('error', 'Choose a team member.');
            break;
        }
        leads_assign('l.workspace_id = ? AND l.id = ?', [$ws, $id], $userId);
        flash('success', $userId ? 'Lead assigned.' : 'Lead unassigned.');
        break;

    case 'delete':
        require_permission('leads.manage');
        q('DELETE FROM leads WHERE id = ? AND workspace_id = ?', [$id, $ws]);
        flash('success', 'Lead deleted.');
        redirect('leads/index.php');

    default:
        flash('error', 'Unknown action.');
}
if (is_ajax()) {
    json_response(['ok' => true]);
}
redirect_back(url('leads/view.php', ['id' => $id]));
