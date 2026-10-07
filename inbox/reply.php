<?php
/** Send a reply (same thread, correct headers) or forward from a conversation. */
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_post();
$ws = ws_id();
$thread = find_or_404('email_threads', input_int('thread_id'));
$back = url('inbox/thread.php', ['id' => $thread['id']]);
$customer = q_one('SELECT * FROM customers WHERE id = ? AND workspace_id = ?', [$thread['customer_id'], $ws]);
$account = q_one("SELECT * FROM mail_accounts WHERE id = ? AND workspace_id = ? AND status <> 'disconnected'", [input_int('mail_account_id'), $ws]);
$mode = input('mode') === 'forward' ? 'forward' : 'reply';
$body = trim((string) input('body'));
[$cc, $badCc] = parse_email_list((string) input('cc'));

if (!$customer || !$account) {
    flash('error', 'Choose a valid sending account.');
    redirect($back);
}
if ($body === '' || $badCc) {
    flash('error', $badCc ? 'Invalid Cc address.' : 'Write a message first.');
    redirect($back);
}
$forwardTo = strtolower(trim((string) input('forward_to')));
if ($mode === 'forward' && !is_valid_email($forwardTo)) {
    flash('error', 'Enter a valid address to forward to.');
    redirect($back);
}
[$newAttachments, $attErrors] = store_uploaded_attachments('attachments', $ws);
if ($attErrors) {
    flash('error', implode(' ', $attErrors));
    redirect($back);
}

// Reply headers chain off the latest message in the thread that has a Message-ID
$last = q_one(
    "SELECT * FROM email_messages WHERE thread_id = ? AND message_id IS NOT NULL AND (sent_at IS NOT NULL OR direction = 'inbound') ORDER BY COALESCE(sent_at, created_at) DESC, id DESC LIMIT 1",
    [$thread['id']]
);
$baseSubject = (string) ($thread['subject'] ?: ($last['subject'] ?? ''));
$html = text_to_html(render_vars($body, $customer));

if ($mode === 'reply') {
    $subject = preg_match('/^re:/i', $baseSubject) ? $baseSubject : 'Re: ' . $baseSubject;
    $inReplyTo = $last['message_id'] ?? null;
    $references = null;
    if ($last) {
        $refs = trim((string) $last['references_header']);
        $references = trim($refs . ' <' . $last['message_id'] . '>');
    }
    $to = $customer['email'];
} else {
    $subject = 'Fwd: ' . preg_replace('/^(re|fwd?):\s*/i', '', $baseSubject);
    $inReplyTo = $references = null;
    $to = $forwardTo;
    if ($last) {
        $html .= '<br><div style="color:#64748b">---------- Forwarded message ----------<br>From: ' . e($last['from_email']) . '<br>Date: ' . e(format_dt($last['sent_at'] ?? $last['created_at'])) . '<br>Subject: ' . e($last['subject']) . '</div><br>'
            . ($last['html_body'] ?: text_to_html((string) $last['text_body']));
    }
}

$id = transaction(function () use ($ws, $customer, $thread, $account, $subject, $html, $cc, $inReplyTo, $references, $to, $newAttachments) {
    $id = save_outbound_message([
        'workspace_id' => $ws,
        'customer' => $customer,
        'campaign_id' => $thread['campaign_id'] ? (int) $thread['campaign_id'] : null,
        'account' => $account,
        'thread_id' => (int) $thread['id'],
        'user_id' => user_id(),
        'to' => $to,
        'subject' => $subject,
        'html' => $html,
        'cc' => $cc ? implode(', ', $cc) : null,
        'in_reply_to' => $inReplyTo,
        'references' => $references,
        'status' => 'queued',
    ]);
    attach_files_to_message($newAttachments, $id);
    return $id;
});

$result = send_message($id);
if ($result['ok']) {
    q('UPDATE email_threads SET is_unread = 0, mail_account_id = COALESCE(mail_account_id, ?), updated_at = ? WHERE id = ?', [$account['id'], now(), $thread['id']]);
    flash('success', $mode === 'reply' ? 'Reply sent.' : 'Forwarded to ' . $to . '.');
} else {
    fail_message($id, (string) $result['error']);
    flash('error', 'Sending failed: ' . $result['error']);
}
redirect($back);
