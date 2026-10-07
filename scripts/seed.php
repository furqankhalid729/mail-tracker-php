<?php
/**
 * Development seed data. CLI only:
 *   php scripts/seed.php                   demo workspace, customers, tags, templates, campaign
 *   php scripts/seed.php --with-activity   also simulates sent/opened/clicked/replied history
 * Login: demo@example.com / demo12345   (never run this against production)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('NO_SESSION', true);
require __DIR__ . '/../includes/init.php';

if (APP_ENV === 'production' && !in_array('--force', $argv, true)) {
    fwrite(STDERR, "APP_ENV=production: refusing to seed. Pass --force if you really mean it.\n");
    exit(1);
}
$withActivity = in_array('--with-activity', $argv, true);

if (q_val('SELECT id FROM users WHERE email = ?', ['demo@example.com'])) {
    echo "Demo user already exists.\n";
    exit(0);
}

$userId = create_user_with_workspace('Demo User', 'demo@example.com', 'demo12345', 'Demo Workspace');
$ws = (int) q_val('SELECT current_workspace_id FROM users WHERE id = ?', [$userId]);
$_SESSION['user_id'] = $userId;
$now = now();

$tagIds = [];
foreach ([['Shopify', '#10b981'], ['USA', '#3b82f6'], ['SaaS', '#8b5cf6'], ['Hot Lead', '#ef4444'], ['Enterprise', '#f59e0b'], ['SEO', '#14b8a6'], ['Follow Up', '#ec4899']] as [$n, $c]) {
    $tagIds[$n] = db_insert('tags', ['workspace_id' => $ws, 'name' => $n, 'color' => $c, 'created_at' => $now, 'updated_at' => $now]);
}

$first = ['John', 'Sarah', 'Mike', 'Emma', 'David', 'Olivia', 'James', 'Sophia', 'Daniel', 'Ava', 'Liam', 'Mia', 'Noah', 'Isabella', 'Lucas', 'Amelia', 'Ethan', 'Harper', 'Mason', 'Ella'];
$last = ['Smith', 'Johnson', 'Adams', 'Brown', 'Garcia', 'Miller', 'Davis', 'Wilson', 'Moore', 'Taylor', 'Anderson', 'Thomas', 'Lee', 'Clark', 'Lewis'];
$companies = ['Acme Inc.', 'Globex', 'Initech', 'Umbrella Co', 'Hooli', 'Stark Goods', 'Wayne Retail', 'Wonka Foods', 'Soylent', 'Vandelay Imports', 'Pied Piper', 'Bluth Co'];
$countries = ['USA', 'USA', 'USA', 'Canada', 'UK', 'Germany', 'Australia'];
$titles = ['Founder', 'CEO', 'Head of Growth', 'Marketing Manager', 'E-commerce Lead', 'CTO'];

$customerIds = [];
for ($i = 0; $i < 60; $i++) {
    $fn = $first[$i % count($first)];
    $ln = $last[($i * 7) % count($last)];
    $co = $companies[$i % count($companies)];
    $domain = strtolower(preg_replace('/[^a-z]/i', '', $co)) . '.example';
    $id = db_insert('customers', [
        'workspace_id' => $ws, 'first_name' => $fn, 'last_name' => $ln, 'full_name' => "$fn $ln",
        'email' => strtolower("$fn.$ln$i@$domain"), 'company' => $co, 'job_title' => $titles[$i % count($titles)],
        'website' => "https://$domain", 'country' => $countries[$i % count($countries)], 'source' => $i % 3 ? 'CSV import' : 'LinkedIn',
        'status' => 'active', 'crm_status' => 'New',
        'custom_fields' => json_encode(['industry' => $i % 2 ? 'SaaS' : 'E-commerce', 'company_size' => ['1-10', '11-50', '50-100', '100+'][$i % 4]]),
        'created_at' => date('Y-m-d H:i:s', strtotime("-" . (60 - $i) . " days")), 'updated_at' => $now, 'last_activity_at' => $now,
    ]);
    $customerIds[] = $id;
    $assign = [$i % 2 ? 'Shopify' : 'SaaS'];
    if ($countries[$i % count($countries)] === 'USA') {
        $assign[] = 'USA';
    }
    if ($i % 5 === 0) {
        $assign[] = 'Hot Lead';
    }
    foreach ($assign as $t) {
        q('INSERT IGNORE INTO customer_tags (customer_id, tag_id, workspace_id, created_at) VALUES (?, ?, ?, ?)', [$id, $tagIds[$t], $ws, $now]);
    }
}

$tpl1 = db_insert('email_templates', [
    'workspace_id' => $ws, 'name' => 'Shopify intro', 'subject' => 'Quick question about {{company}}',
    'html_body' => "<p>Hi {{firstName|there}},</p><p>I noticed that {{company}} is using Shopify and wanted to reach out about speeding up your store.</p><p>We recently helped a similar brand cut page load time in half. <a href=\"https://example.com/case-study\">Here's the case study</a>.</p><p>Worth a quick 15-minute chat next week?</p><p>Regards,<br>Demo User</p>",
    'created_at' => $now, 'updated_at' => $now,
]);
db_insert('email_templates', [
    'workspace_id' => $ws, 'name' => 'Follow-up #1', 'subject' => 'Re: Quick question about {{company}}',
    'html_body' => '<p>Hi {{firstName}},</p><p>Just bumping this up in case it got buried. Happy to send over a short video instead if that is easier.</p><p>Best,<br>Demo User</p>',
    'created_at' => $now, 'updated_at' => $now,
]);

// A placeholder SMTP account so campaigns can be configured; sending will fail until real credentials are set
$acc = db_insert('mail_accounts', [
    'workspace_id' => $ws, 'name' => 'Demo mailbox (edit me)', 'provider' => 'smtp', 'email' => 'sales@demo.example', 'from_name' => 'Demo User',
    'smtp_host' => 'smtp.demo.example', 'smtp_port' => 587, 'smtp_encryption' => 'tls', 'smtp_username' => 'sales@demo.example',
    'encrypted_smtp_password' => encrypt_value('not-a-real-password'), 'daily_limit' => 100, 'status' => 'error',
    'last_error' => 'Demo account: replace with real SMTP credentials', 'created_at' => $now, 'updated_at' => $now,
]);

$campaignId = db_insert('campaigns', [
    'workspace_id' => $ws, 'name' => 'Shopify Outreach', 'description' => 'Intro emails to Shopify store owners', 'status' => 'draft',
    'mail_account_id' => $acc, 'template_id' => $tpl1, 'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
]);
$added = add_contacts_by_filter($ws, $campaignId, ['tags' => [$tagIds['Shopify']], 'status' => 'active'], 'Shopify Outreach');
echo "Seeded workspace #$ws with 60 customers, 7 tags, 2 templates, campaign with $added contacts.\n";

if ($withActivity) {
    // Simulated history: mark the campaign as having been sent over the last 2 weeks
    $account = q_one('SELECT * FROM mail_accounts WHERE id = ?', [$acc]);
    $template = q_one('SELECT * FROM email_templates WHERE id = ?', [$tpl1]);
    $contacts = q_all('SELECT c.* FROM campaign_contacts cc JOIN customers c ON c.id = cc.customer_id WHERE cc.campaign_id = ?', [$campaignId]);
    foreach ($contacts as $i => $c) {
        $sentAt = date('Y-m-d H:i:s', strtotime('-' . (14 - ($i % 14)) . ' days ' . (9 + $i % 8) . ':' . sprintf('%02d', $i * 7 % 60)));
        $threadId = create_thread($ws, (int) $c['id'], $campaignId, $acc, render_vars($template['subject'], $c));
        $mid = save_outbound_message(['workspace_id' => $ws, 'customer' => $c, 'campaign_id' => $campaignId, 'account' => $account, 'thread_id' => $threadId,
            'subject' => render_vars($template['subject'], $c), 'html' => render_vars($template['html_body'], $c, true), 'status' => 'sent']);
        $msgId = generate_message_id($account['email']);
        q('UPDATE email_messages SET sent_at = ?, queued_at = ?, message_id = ?, created_at = ? WHERE id = ?', [$sentAt, $sentAt, $msgId, $sentAt, $mid]);
        $m = q_one('SELECT * FROM email_messages WHERE id = ?', [$mid]);
        $ev = function (string $type, string $at, array $meta = []) use ($m) {
            db_insert('email_events', ['workspace_id' => $m['workspace_id'], 'email_message_id' => $m['id'], 'customer_id' => $m['customer_id'], 'campaign_id' => $m['campaign_id'], 'type' => $type, 'metadata' => $meta ? json_encode($meta) : null, 'created_at' => $at]);
        };
        $ev('queued', $sentAt);
        $ev('sent', $sentAt);
        $status = 'sent';
        q('UPDATE campaign_contacts SET last_email_id = ?, system_status = ?, last_activity_at = ? WHERE campaign_id = ? AND customer_id = ?', [$mid, 'sent', $sentAt, $campaignId, $c['id']]);
        if ($i % 10 === 9) {
            $ev('bounced', $sentAt, ['reason' => '550 5.1.1 mailbox does not exist']);
            q("UPDATE email_messages SET status = 'bounced', bounced_at = ? WHERE id = ?", [$sentAt, $mid]);
            q("UPDATE campaign_contacts SET system_status = 'bounced' WHERE last_email_id = ?", [$mid]);
            continue;
        }
        if ($i % 3 !== 0) {
            $at = date('Y-m-d H:i:s', strtotime($sentAt . ' +' . (1 + $i % 5) . ' hours'));
            $ev('opened', $at);
            q("UPDATE email_messages SET status = 'opened', open_count = 1 + ?, first_opened_at = ?, last_opened_at = ? WHERE id = ?", [$i % 3, $at, $at, $mid]);
            $status = 'opened';
        }
        if ($i % 4 === 1) {
            $at = date('Y-m-d H:i:s', strtotime($sentAt . ' +6 hours'));
            $ev('clicked', $at, ['url' => 'https://example.com/case-study']);
            q("UPDATE email_messages SET status = 'clicked', click_count = 1, first_clicked_at = ?, last_clicked_at = ? WHERE id = ?", [$at, $at, $mid]);
            $status = 'clicked';
        }
        if ($i % 6 === 1) {
            $at = date('Y-m-d H:i:s', strtotime($sentAt . ' +1 day'));
            $ev('replied', $at, ['from' => $c['email']]);
            db_insert('email_messages', ['workspace_id' => $ws, 'customer_id' => $c['id'], 'campaign_id' => $campaignId, 'thread_id' => $threadId, 'mail_account_id' => $acc,
                'direction' => 'inbound', 'message_id' => random_token(8) . '@' . substr(strrchr($c['email'], '@'), 1), 'in_reply_to' => $msgId,
                'from_email' => $c['email'], 'from_name' => customer_name($c), 'to_email' => $account['email'], 'subject' => 'Re: ' . $m['subject'],
                'text_body' => "Hi,\n\nThanks for reaching out — this sounds interesting. Can you send over pricing?\n\nBest,\n" . $c['first_name'],
                'html_body' => '', 'status' => 'received', 'is_read' => 0, 'sent_at' => $at, 'created_at' => $at, 'updated_at' => $at]);
            q('UPDATE email_messages SET status = ?, replied_at = ? WHERE id = ?', ['replied', $at, $mid]);
            q('UPDATE email_threads SET has_reply = 1, is_unread = 1, last_message_at = ? WHERE id = ?', [$at, $threadId]);
            $status = 'replied';
            q("UPDATE campaign_contacts SET manual_status = ? WHERE last_email_id = ?", [['Interested', 'Qualified', 'Follow Up'][$i % 3], $mid]);
        }
        q('UPDATE campaign_contacts SET system_status = ? WHERE last_email_id = ?', [$status, $mid]);
    }
    q("UPDATE campaigns SET status = 'completed', started_at = ?, completed_at = ? WHERE id = ?", [date('Y-m-d H:i:s', strtotime('-14 days')), $now, $campaignId]);
    echo "Simulated activity for " . count($contacts) . " contacts.\n";
}
echo "Login: demo@example.com / demo12345\n";
