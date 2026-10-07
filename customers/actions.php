<?php
/** Small state changes from the customer profile (notes, tags, stages, campaign membership, subscription). */
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_post();
$ws = ws_id();
$customer = find_or_404('customers', input_int('customer_id'));
$cid = (int) $customer['id'];
$back = url('customers/view.php', ['id' => $cid, 'tab' => input('tab')]);

switch ((string) input('action')) {
    case 'note_add':
        $body = trim((string) input('body'));
        if ($body === '' || mb_strlen($body) > 10000) {
            flash('error', 'Note must be between 1 and 10,000 characters.');
            break;
        }
        db_insert('notes', ['workspace_id' => $ws, 'customer_id' => $cid, 'user_id' => user_id(), 'body' => $body, 'created_at' => now(), 'updated_at' => now()]);
        q('UPDATE customers SET last_activity_at = ? WHERE id = ?', [now(), $cid]);
        flash('success', 'Note added.');
        $back = url('customers/view.php', ['id' => $cid, 'tab' => 'notes']);
        break;

    case 'note_delete':
        q('DELETE FROM notes WHERE id = ? AND customer_id = ? AND workspace_id = ?', [input_int('note_id'), $cid, $ws]);
        flash('success', 'Note deleted.');
        $back = url('customers/view.php', ['id' => $cid, 'tab' => 'notes']);
        break;

    case 'tags':
        set_customer_tags($ws, $cid, input_ids('tags'));
        flash('success', 'Tags updated.');
        break;

    case 'crm_status':
        $status = (string) input('crm_status');
        if (in_array($status, CRM_STATUSES, true) && $status !== $customer['crm_status']) {
            q('UPDATE customers SET crm_status = ?, updated_at = ? WHERE id = ?', [$status, now(), $cid]);
            log_activity($ws, 'status_changed', 'CRM stage changed to ' . $status, $cid);
            flash('success', 'CRM stage set to ' . $status . '.');
        }
        break;

    case 'campaign_add':
        $campaign = find_or_404('campaigns', input_int('campaign_id'));
        ensure_campaign_contact($ws, (int) $campaign['id'], $cid)
            ? flash('success', 'Added to ' . $campaign['name'] . ($campaign['status'] === 'active' ? '. The email will be queued on the next cron run.' : '.'))
            : flash('info', 'Already in this campaign.');
        $back = url('customers/view.php', ['id' => $cid, 'tab' => 'campaigns']);
        break;

    case 'campaign_remove':
        $campaign = find_or_404('campaigns', input_int('campaign_id'));
        transaction(function () use ($campaign, $cid, $ws) {
            q(
                "UPDATE email_jobs j JOIN email_messages m ON m.id = j.email_message_id
                 SET j.status = 'cancelled', m.status = 'cancelled' WHERE m.campaign_id = ? AND m.customer_id = ? AND j.status = 'pending'",
                [$campaign['id'], $cid]
            );
            q('DELETE FROM campaign_contacts WHERE campaign_id = ? AND customer_id = ?', [$campaign['id'], $cid]);
            log_activity($ws, 'campaign_removed', 'Removed from campaign "' . $campaign['name'] . '"', $cid, (int) $campaign['id']);
        });
        flash('success', 'Removed from ' . $campaign['name'] . '.');
        $back = url('customers/view.php', ['id' => $cid, 'tab' => 'campaigns']);
        break;

    case 'campaign_status':
        $campaign = find_or_404('campaigns', input_int('campaign_id'));
        $status = (string) input('manual_status');
        if (in_array($status, CRM_STATUSES, true)) {
            q('UPDATE campaign_contacts SET manual_status = ?, updated_at = ? WHERE campaign_id = ? AND customer_id = ?', [$status, now(), $campaign['id'], $cid]);
            q('UPDATE customers SET crm_status = ?, updated_at = ? WHERE id = ?', [$status, now(), $cid]);
            log_activity($ws, 'status_changed', 'CRM stage changed to ' . $status, $cid, (int) $campaign['id']);
            flash('success', 'Stage updated.');
        }
        $back = url('customers/view.php', ['id' => $cid, 'tab' => 'campaigns']);
        break;

    case 'unsubscribe':
        unsubscribe_customer($customer, 'manual', null, user_id());
        log_activity($ws, 'unsubscribed', 'Marked as unsubscribed', $cid);
        flash('success', 'Customer unsubscribed. Pending campaign emails were cancelled.');
        break;

    case 'resubscribe':
        resubscribe_customer($customer, user_id());
        flash('success', 'Customer resubscribed.');
        break;

    default:
        flash('error', 'Unknown action.');
}
// Allow the inbox conversation view to reuse these actions (only a fixed internal path is accepted)
$return = (string) input('return');
if (preg_match('#^inbox/thread\.php\?id=\d+$#', $return)) {
    $back = url($return);
}
redirect($back);
