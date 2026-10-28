<?php
/**
 * Leave request actions (POST):
 *   action=request  any member asks for leave
 *   action=cancel   the requester withdraws a pending request
 *   action=approve / reject   Super Admin decides on a pending request, with an optional note and paid / unpaid
 *   action=set_pay  Super Admin marks an approved request paid or unpaid (unpaid by default)
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_post();
$ws = ws_id();
$me = user_id();
$action = (string) input('action');

if ($action === 'request') {
    $start = trim((string) input('start_date'));
    $end = trim((string) input('end_date')) ?: $start;
    $halfDay = (bool) input('half_day');
    $errors = validate($_POST, ['start_date' => 'required', 'reason' => 'max:2000'], ['start_date' => 'Start date']);
    foreach (['start_date' => $start, 'end_date' => $end] as $field => $d) {
        if ($d !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !strtotime($d))) {
            $errors[$field] = 'Date is invalid.';
        }
    }
    if (!$errors) {
        if ($end < $start) {
            $errors['end_date'] = 'End date cannot be before the start date.';
        } elseif ($halfDay && $end !== $start) {
            $errors['half_day'] = 'A half day must start and end on the same date.';
        } elseif ($start < date('Y-m-d', strtotime('-30 days'))) {
            $errors['start_date'] = 'Leave can be requested up to 30 days in the past.';
        } elseif (leave_days($start, $end, false) > 90) {
            $errors['end_date'] = 'One request can cover at most 90 days.';
        } elseif (q_val(
            "SELECT 1 FROM leave_requests WHERE workspace_id = ? AND user_id = ? AND status IN ('pending', 'approved') AND start_date <= ? AND end_date >= ?",
            [$ws, $me, $end, $start]
        )) {
            $errors['start_date'] = 'You already have a pending or approved leave on these dates.';
        }
    }
    if ($errors) {
        keep_old_input();
        flash_errors($errors);
        redirect(url('leaves/index.php'));
    }
    $type = array_key_exists((string) input('leave_type'), LEAVE_TYPES) ? (string) input('leave_type') : 'casual';
    db_insert('leave_requests', [
        'workspace_id' => $ws,
        'user_id' => $me,
        'leave_type' => $type,
        'start_date' => $start,
        'end_date' => $end,
        'half_day' => $halfDay ? 1 : 0,
        'days' => leave_days($start, $end, $halfDay),
        'reason' => trim((string) input('reason')) ?: null,
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    clear_old_input();
    flash('success', 'Leave requested. A Super Admin will review it.');
    redirect(url('leaves/index.php'));
}

$leave = find_or_404('leave_requests', input_int('id'));

if ($action === 'cancel') {
    if ((int) $leave['user_id'] !== $me) {
        abort(403, 'You can only cancel your own leave requests.');
    }
    if ($leave['status'] !== 'pending') {
        flash('error', 'Only pending requests can be cancelled.');
        redirect_back('leaves/index.php');
    }
    db_update('leave_requests', ['status' => 'cancelled', 'updated_at' => now()], 'id = ? AND workspace_id = ?', [$leave['id'], $ws]);
    flash('success', 'Leave request cancelled.');
    redirect_back('leaves/index.php');
}

if ($action === 'approve' || $action === 'reject') {
    require_permission('leaves.approve');
    if ($leave['status'] !== 'pending') {
        flash('error', 'This request was already ' . strtolower(LEAVE_STATUSES[$leave['status']] ?? $leave['status']) . '.');
        redirect_back('leaves/index.php?view=pending');
    }
    $note = mb_substr(trim((string) input('review_note')), 0, 500);
    db_update('leave_requests', [
        'status' => $action === 'approve' ? 'approved' : 'rejected',
        'is_paid' => $action === 'approve' && input('is_paid') && leave_can_be_paid($leave) ? 1 : 0,
        'reviewed_by' => $me,
        'reviewed_at' => now(),
        'review_note' => $note ?: null,
        'updated_at' => now(),
    ], "id = ? AND workspace_id = ? AND status = 'pending'", [$leave['id'], $ws]);
    $who = (string) q_val('SELECT name FROM users WHERE id = ?', [$leave['user_id']]);
    flash('success', ($action === 'approve' ? 'Approved' : 'Rejected') . " {$who}’s leave (" . format_leave_range($leave['start_date'], $leave['end_date']) . ').');
    redirect_back('leaves/index.php?view=pending');
}

if ($action === 'set_pay') {
    require_permission('leaves.approve');
    $paid = (bool) input('is_paid');
    if ($paid && !leave_can_be_paid($leave)) {
        flash('error', 'Unpaid-type leave cannot be marked as paid. Change its type first.');
        redirect_back('leaves/index.php?view=all');
    }
    if ($leave['status'] !== 'approved') {
        flash('error', 'Only approved leave can be marked paid or unpaid. Choose it when approving.');
        redirect_back('leaves/index.php?view=all');
    }
    db_update('leave_requests', ['is_paid' => $paid ? 1 : 0, 'updated_at' => now()], 'id = ? AND workspace_id = ?', [$leave['id'], $ws]);
    $who = (string) q_val('SELECT name FROM users WHERE id = ?', [$leave['user_id']]);
    flash('success', "Marked {$who}’s leave (" . format_leave_range($leave['start_date'], $leave['end_date']) . ') as ' . ($paid ? 'paid' : 'unpaid') . '.');
    redirect_back('leaves/index.php?view=all');
}

abort(400, 'Unknown action.');
