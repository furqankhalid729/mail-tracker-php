<?php
/**
 * Expense and subscription actions (POST).
 *   delete_expense      id
 *   remove_receipt      id
 *   save_subscription   [id], name, vendor, category, amount, billing_cycle, next_due, payment_method, manage_url, notes
 *   pay_subscription    id, paid_on, amount   adds an expense and moves next_due one cycle forward
 *   toggle_subscription id                    pause / resume
 *   delete_subscription id                    its past expenses stay
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_post();
require_permission('expenses.manage');
$ws = ws_id();
$action = (string) input('action');

switch ($action) {
    case 'delete_expense':
        $expense = find_or_404('expenses', input_int('id'));
        q('DELETE FROM expenses WHERE id = ? AND workspace_id = ?', [$expense['id'], $ws]);
        delete_private_uploads([$expense['receipt_path']], 'expenses/');
        flash('success', 'Expense “' . $expense['title'] . '” deleted.');
        redirect_back('expenses/index.php');

    case 'remove_receipt':
        $expense = find_or_404('expenses', input_int('id'));
        db_update('expenses', ['receipt_path' => null, 'receipt_name' => null, 'receipt_mime' => null, 'updated_at' => now()], 'id = ? AND workspace_id = ?', [$expense['id'], $ws]);
        delete_private_uploads([$expense['receipt_path']], 'expenses/');
        flash('success', 'Receipt removed.');
        redirect_back('expenses/index.php');

    case 'save_subscription':
        $sub = input_int('id') ? find_or_404('subscriptions', input_int('id')) : null;
        $errors = validate($_POST, ['name' => 'required|max:150', 'vendor' => 'max:150', 'notes' => 'max:1000'], []);
        $amount = amount_from_input(input('amount'));
        if ($amount === null || $amount <= 0) {
            $errors['amount'] = 'Enter the amount you pay each cycle.';
        }
        $due = (string) input('next_due');
        if ($due !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) || !strtotime($due))) {
            $errors['next_due'] = 'Next payment date is invalid.';
        }
        $manageUrl = trim((string) input('manage_url'));
        if ($manageUrl !== '' && !is_valid_http_url($manageUrl)) {
            $errors['manage_url'] = 'Use a full https:// link.';
        }
        if ($errors) {
            keep_old_input();
            flash_errors($errors);
            redirect(url('expenses/subscriptions.php', ['edit' => $sub['id'] ?? 'new']));
        }
        $data = [
            'name' => trim((string) input('name')),
            'vendor' => trim((string) input('vendor')) ?: null,
            'category' => isset(EXPENSE_CATEGORIES[input('category')]) ? (string) input('category') : 'software',
            'amount' => $amount,
            'currency' => APP_CURRENCY,
            'billing_cycle' => isset(BILLING_CYCLES[input('billing_cycle')]) ? (string) input('billing_cycle') : 'monthly',
            'next_due' => $due ?: null,
            'payment_method' => mb_substr(trim((string) input('payment_method')), 0, 50) ?: null,
            'manage_url' => $manageUrl ?: null,
            'notes' => trim((string) input('notes')) ?: null,
            'updated_at' => now(),
        ];
        if ($sub) {
            db_update('subscriptions', $data, 'id = ? AND workspace_id = ?', [$sub['id'], $ws]);
            flash('success', 'Subscription saved.');
        } else {
            db_insert('subscriptions', $data + ['workspace_id' => $ws, 'is_active' => 1, 'created_by' => user_id(), 'created_at' => now()]);
            flash('success', 'Subscription added.');
        }
        clear_old_input();
        redirect('expenses/subscriptions.php');

    case 'pay_subscription':
        $sub = find_or_404('subscriptions', input_int('id'));
        $paidOn = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) input('paid_on')) ? (string) input('paid_on') : date('Y-m-d');
        $amount = amount_from_input(input('amount')) ?: (float) $sub['amount'];
        transaction(function () use ($sub, $paidOn, $amount, $ws) {
            db_insert('expenses', [
                'workspace_id' => $ws,
                'expense_date' => $paidOn,
                'title' => $sub['name'] . ' (' . strtolower(BILLING_CYCLES[$sub['billing_cycle']]) . ')',
                'vendor' => $sub['vendor'],
                'category' => $sub['category'],
                'amount' => $amount,
                'currency' => APP_CURRENCY,
                'payment_method' => $sub['payment_method'],
                'subscription_id' => $sub['id'],
                'created_by' => user_id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            // Next due: one cycle after the date it was due (or after the payment date when none was set)
            db_update('subscriptions', ['next_due' => subscription_next_due($sub['next_due'] ?: $paidOn, $sub['billing_cycle']), 'updated_at' => now()], 'id = ?', [$sub['id']]);
        });
        flash('success', 'Recorded ' . money($amount, $sub['currency']) . ' for ' . $sub['name'] . '.');
        redirect_back('expenses/subscriptions.php');

    case 'toggle_subscription':
        $sub = find_or_404('subscriptions', input_int('id'));
        db_update('subscriptions', ['is_active' => $sub['is_active'] ? 0 : 1, 'updated_at' => now()], 'id = ?', [$sub['id']]);
        flash('success', $sub['is_active'] ? $sub['name'] . ' paused.' : $sub['name'] . ' resumed.');
        redirect('expenses/subscriptions.php');

    case 'delete_subscription':
        $sub = find_or_404('subscriptions', input_int('id'));
        q('DELETE FROM subscriptions WHERE id = ? AND workspace_id = ?', [$sub['id'], $ws]);
        flash('success', $sub['name'] . ' deleted. Its past payments stay in expenses.');
        redirect('expenses/subscriptions.php');
}
abort(400, 'Unknown action.');
