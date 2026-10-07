<?php
defined('APP_ROOT') || exit;
/**
 * Shared create/update handler included by create.php and edit.php.
 * Expects $ws and optionally $existing (array) for updates. Returns via redirect.
 */
$data = customer_data_from_input($_POST);
$errors = validate($data, ['email' => 'required|email|max:190']);
if (trim((string) ($_POST['website'] ?? '')) !== '' && $data['website'] === null) {
    $errors['website'] = 'Website must be a valid URL.';
}

// Custom fields: key/value pairs → JSON
$custom = [];
foreach ((array) ($_POST['cf_key'] ?? []) as $i => $key) {
    $key = trim((string) $key);
    $val = trim((string) ($_POST['cf_value'][$i] ?? ''));
    if ($key === '') {
        continue;
    }
    if (!preg_match('/^[A-Za-z0-9_.-]{1,50}$/', $key)) {
        $errors['custom_fields'] = 'Custom field names may only contain letters, numbers, _ . - (max 50).';
        break;
    }
    $custom[$key] = mb_substr($val, 0, 1000);
}

$status = in_array(input('status'), CUSTOMER_STATUSES, true) ? (string) input('status') : 'active';
$crm = in_array(input('crm_status'), CRM_STATUSES, true) ? (string) input('crm_status') : 'New';

if ($errors) {
    keep_old_input();
    flash_errors($errors);
    redirect_back('customers/index.php');
}

$now = now();
$row = $data + [
    'crm_status' => $crm,
    'custom_fields' => $custom ? json_encode($custom, JSON_UNESCAPED_UNICODE) : null,
    'updated_at' => $now,
];

$id = transaction(function () use ($ws, $row, $status, $existing, $now) {
    if (!empty($existing)) {
        db_update('customers', $row, 'id = ? AND workspace_id = ?', [$existing['id'], $ws]);
        $id = (int) $existing['id'];
        if ($existing['crm_status'] !== $row['crm_status']) {
            log_activity($ws, 'status_changed', 'CRM stage changed to ' . $row['crm_status'], $id);
        }
    } else {
        $id = db_insert('customers', $row + ['workspace_id' => $ws, 'status' => 'active', 'created_at' => $now, 'last_activity_at' => $now]);
        log_activity($ws, 'customer_created', 'Customer created', $id);
    }
    // Status changes to/from unsubscribed go through the audited helpers
    $current = q_one('SELECT * FROM customers WHERE id = ?', [$id]);
    if ($status === 'unsubscribed' && !$current['unsubscribed_at']) {
        unsubscribe_customer($current, 'manual', null, user_id());
    } elseif ($status !== 'unsubscribed' && $current['unsubscribed_at']) {
        resubscribe_customer($current, user_id());
        q('UPDATE customers SET status = ? WHERE id = ?', [$status, $id]);
    } elseif ($status !== 'unsubscribed') {
        q('UPDATE customers SET status = ? WHERE id = ?', [$status, $id]);
    }
    set_customer_tags($ws, $id, input_ids('tags'));
    return $id;
});

clear_old_input();
flash('success', empty($existing) ? 'Customer created.' : 'Customer updated.');
redirect(url('customers/view.php', ['id' => $id]));
