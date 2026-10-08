<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('customers.delete');
require_post();

$customer = find_or_404('customers', input_int('id'));
// Cascades remove tags, campaign membership, messages, events and notes
q('DELETE FROM customers WHERE id = ? AND workspace_id = ?', [$customer['id'], ws_id()]);
flash('success', customer_name($customer) . ' was deleted.');
redirect('customers/index.php');
