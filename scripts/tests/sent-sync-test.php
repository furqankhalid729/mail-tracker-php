<?php
// php scripts/tests/sent-sync-test.php — Sent-folder sync (migration 015): IMAP LIST parsing, import rules, end-to-end via a fake IMAP server
require __DIR__ . '/_assert.php';
require __DIR__ . '/_fixtures.php';

fx_cleanup();
$account = fx_account();

function raw_mail(string $from, string $to, string $subject, string $msgId, string $body, array $extra = []): string
{
    $h = ["From: Me <$from>", "To: $to", "Subject: $subject", "Message-ID: <$msgId>", 'Date: ' . date('r', time() - 60), 'Content-Type: text/plain; charset=utf-8'];
    return implode("\r\n", [...$h, ...$extra]) . "\r\n\r\n" . $body . "\r\n";
}

t('LIST lines: quoted, atom and literal names with flags', function () {
    eq(ImapClient::parseListLine("* LIST (\\HasNoChildren \\Sent) \"/\" \"Sent Items\"\r\n"), ['Sent Items', ['\\hasnochildren', '\\sent']]);
    eq(ImapClient::parseListLine("* LIST () \".\" INBOX.Sent\r\n"), ['INBOX.Sent', []]);
    eq(ImapClient::parseListLine("* LIST (\\Sent) \"/\" {9}\r\n\r\n", ['Gesendet!']), ['Gesendet!', ['\\sent']]);
    eq(ImapClient::parseListLine("* LIST (\\Noselect) NIL \"a \\\"b\\\"\"\r\n"), ['a "b"', ['\\noselect']]);
    eq(ImapClient::parseListLine('* OK nothing'), null);
});
t('Sent folder: \\Sent flag wins, else a known name (case-insensitive), never \\Noselect', function () {
    eq(ImapClient::pickSentMailbox([['INBOX', []], ['Sent', []], ['Elementos enviados', ['\\sent']]]), 'Elementos enviados');
    eq(ImapClient::pickSentMailbox([['INBOX', []], ['inbox.sent', []]]), 'inbox.sent');
    eq(ImapClient::pickSentMailbox([['Sent', ['\\noselect']], ['Archive', []]]), null);
});
t('address lists: every To/Cc address', function () {
    $p = parse_raw_email("From: a@x.test\r\nTo: \"Doe, Jane\" <Jane@X.test>, bob@y.test\r\nCc: c@z.test\r\nSubject: s\r\n\r\nhi");
    eq($p['to_all'], ['jane@x.test', 'bob@y.test']);
    eq($p['cc_all'], ['c@z.test']);
});

t('external mail to a customer is imported into a new thread', function () use ($account) {
    $c = fx_customer();
    $res = process_sent_email($account, parse_raw_email(raw_mail($account['email'], $c['email'], 'Quick question', 'ext-1-' . $c['id'] . '@x.test', 'Hello there')));
    eq($res, 'imported');
    $m = q_one("SELECT * FROM email_messages WHERE customer_id = ? AND direction = 'outbound'", [$c['id']]);
    eq((int) $m['is_external'], 1);
    eq($m['status'], 'sent');
    eq($m['to_email'], $c['email']);
    ok((bool) $m['thread_id'], 'thread created');
    eq(q_val('SELECT subject FROM email_threads WHERE id = ?', [$m['thread_id']]), 'Quick question');
    eq((int) q_val("SELECT COUNT(*) FROM email_events WHERE email_message_id = ? AND type = 'sent'", [$m['id']]), 1);
    // Same message seen again (rescan, Gmail overlap) is not duplicated
    eq(process_sent_email($account, parse_raw_email(raw_mail($account['email'], $c['email'], 'Quick question', 'ext-1-' . $c['id'] . '@x.test', 'Hello there'))), 'skipped');
});
t('mail the CRM sent itself is skipped (by Message-ID, and by recipient+subject+time)', function () use ($account) {
    $c = fx_customer();
    $tid = create_thread(fx_ws(), (int) $c['id'], null, (int) $account['id'], 'Ours');
    $mid = save_outbound_message(['workspace_id' => fx_ws(), 'customer' => $c, 'account' => $account, 'thread_id' => $tid, 'subject' => 'Ours', 'html' => '<p>x</p>', 'status' => 'queued']);
    q("UPDATE email_messages SET status = 'sent', sent_at = ?, message_id = ? WHERE id = ?", [now(), 'ours-' . $mid . '@x.test', $mid]);
    eq(process_sent_email($account, parse_raw_email(raw_mail($account['email'], $c['email'], 'Ours', 'ours-' . $mid . '@x.test', 'x'))), 'skipped');
    eq(process_sent_email($account, parse_raw_email(raw_mail($account['email'], $c['email'], 'Ours', 'rewritten-' . $mid . '@server.test', 'x'))), 'skipped');
    eq((int) q_val('SELECT COUNT(*) FROM email_messages WHERE customer_id = ?', [$c['id']]), 1);
});
t('reply from another client joins the thread and marks it read', function () use ($account) {
    $c = fx_customer();
    $tid = create_thread(fx_ws(), (int) $c['id'], null, (int) $account['id'], 'Pricing');
    $inId = db_insert('email_messages', [
        'workspace_id' => fx_ws(), 'customer_id' => $c['id'], 'thread_id' => $tid, 'mail_account_id' => $account['id'], 'direction' => 'inbound',
        'message_id' => 'in-' . $tid . '@cust.test', 'from_email' => $c['email'], 'subject' => 'Pricing', 'status' => 'received', 'is_read' => 0,
        'sent_at' => date('Y-m-d H:i:s', time() - 3600), 'created_at' => now(), 'updated_at' => now(),
    ]);
    q('UPDATE email_threads SET is_unread = 1, has_reply = 1 WHERE id = ?', [$tid]);
    $raw = raw_mail($account['email'], $c['email'], 'Re: Pricing', 'rep-' . $tid . '@x.test', 'Sure', ['In-Reply-To: <in-' . $tid . '@cust.test>']);
    eq(process_sent_email($account, parse_raw_email($raw)), 'imported');
    eq((int) q_val("SELECT thread_id FROM email_messages WHERE message_id = ?", ['rep-' . $tid . '@x.test']), $tid);
    eq((int) q_val('SELECT is_unread FROM email_threads WHERE id = ?', [$tid]), 0);
    eq((int) q_val('SELECT is_read FROM email_messages WHERE id = ?', [$inId]), 1);
});
t('mail to someone who is not a customer is not imported', function () use ($account) {
    eq(process_sent_email($account, parse_raw_email(raw_mail($account['email'], 'stranger-' . random_token(4) . '@nowhere.test', 'Lunch?', 'lunch-' . random_token(6) . '@x.test', 'hi'))), 'skipped');
});
t('imported mail does not count toward daily send limits', function () use ($account) {
    $before = sent_today_for_account((int) $account['id']);
    $c = fx_customer();
    process_sent_email($account, parse_raw_email(raw_mail($account['email'], $c['email'], 'Limit check', 'lim-' . $c['id'] . '@x.test', 'x')));
    eq(sent_today_for_account((int) $account['id']), $before);
});

t('end to end: check_imap_account finds the Sent folder (literal name) and imports once', function () use ($account) {
    $c = fx_customer();
    $port = 14300 + random_int(0, 500);
    $fixture = [
        'list' => ['* LIST (\\HasNoChildren) "/" INBOX', '* LIST (\\Noselect \\HasChildren) "/" "[Folders]"', "* LIST (\\HasNoChildren \\Sent) \"/\" {10}\r\nSent Items"],
        'boxes' => [
            'INBOX' => ['uidvalidity' => 7, 'messages' => (object) []],
            'Sent Items' => ['uidvalidity' => 9, 'messages' => [
                '1' => raw_mail($account['email'], $c['email'], 'From my phone', 'e2e-' . $c['id'] . '@x.test', 'Sent from phone'),
                '2' => raw_mail($account['email'], 'friend@nowhere.test', 'Weekend', 'e2e-friend-' . $c['id'] . '@x.test', 'hey'),
            ]],
        ],
    ];
    $file = tempnam(sys_get_temp_dir(), 'fimap');
    file_put_contents($file, json_encode($fixture));
    $proc = proc_open([PHP_BINARY, __DIR__ . '/fake-imap.php', (string) $port, $file, '2'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    try {
        eq(trim((string) fgets($pipes[1])), 'READY');
        q("UPDATE mail_accounts SET imap_host = '127.0.0.1', imap_port = ?, imap_encryption = 'none', imap_username = 'u', encrypted_imap_password = ? WHERE id = ?", [$port, encrypt_value('p'), $account['id']]);
        $acc = q_one('SELECT * FROM mail_accounts WHERE id = ?', [$account['id']]);
        $stats = check_imap_account($acc);
        ok(!isset($stats['sent_error']), $stats['sent_error'] ?? '');
        eq([$stats['sent_fetched'], $stats['imported'], $stats['skipped']], [2, 1, 1]);
        $acc = q_one('SELECT * FROM mail_accounts WHERE id = ?', [$account['id']]);
        eq($acc['imap_sent_folder'], 'Sent Items');
        eq((int) $acc['imap_sent_last_uid'], 2);
        eq((int) $acc['imap_sent_uidvalidity'], 9);
        eq((int) q_val("SELECT is_external FROM email_messages WHERE message_id = ?", ['e2e-' . $c['id'] . '@x.test']), 1);
        // Second run: nothing new past the last UID
        $stats = check_imap_account($acc);
        eq($stats['sent_fetched'], 0);
    } finally {
        proc_terminate($proc);
        proc_close($proc);
        @unlink($file);
    }
});

fx_cleanup();
t_done();
