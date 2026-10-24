# AI Campaigns Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A separate "AI Campaigns" module that researches each selected customer's website and competitors with OpenRouter, writes a personalised email following the user's template, and auto-sends it through the existing email queue.

**Architecture:** New tables `ai_campaigns` + `ai_campaign_contacts` (holds each customer's draft). A new cron drafts a few contacts per minute through a reusable `ai_draft_email()` (OpenRouter server tools `web_fetch` + `web_search` in one request), then queues ready drafts as normal `email_messages` + `email_jobs` rows tagged with `ai_campaign_id` (`campaign_id` NULL). The existing sender delivers them; a few additive `ai_campaign_id` branches give AI mail the same suppression, pause, unsubscribe-footer and unsubscribe-cancel behaviour as campaign mail.

**Tech Stack:** PHP 8.1+, MySQL/InnoDB, curl, existing helpers (`q*`, `db_insert`, `transaction`, `render_vars`, `sanitize_html`, `cron_run`). No new Composer deps.

**Spec:** `docs/superpowers/specs/2026-10-24-ai-campaigns-design.md`

## Global Constraints

- Existing standard Campaigns behaviour must not change. Every edit to shared code is an additive `ai_campaign_id` branch.
- Endpoint: `OPENROUTER_API_BASE` (default `https://openrouter.ai/api/v1`) + `/chat/completions`.
- `.env`: `OPENROUTER_API_KEY`, `OPENROUTER_DEFAULT_MODEL` (fallback `deepseek/deepseek-v4.1-flash`), `OPENROUTER_API_BASE`.
- Tools: `openrouter:web_fetch` `{engine: "openrouter", max_uses: 3, max_content_tokens: 8000}`; `openrouter:web_search` `{engine: "parallel", max_results: 5, max_total_results: 10}` (omitted when web search off); `max_tool_calls: 6`; `response_format: {type: json_object}`; `usage: {include: true}`.
- Draft batch `AI_DRAFT_BATCH = 3` per cron run, `AI_DRAFT_MAX_ATTEMPTS = 3`, stale `processing` lock 10 minutes.
- API key never logged, stored in errors, or rendered.
- Permission: `campaigns.manage` to manage; viewing follows whoever can see Campaigns.
- Match the code style of `campaigns/` and `includes/queue.php` (strict_types in includes, doc comments, `q_*` helpers).
- Migration must be safe to re-run (use the `information_schema` + `PREPARE` pattern from `migrations/003_call_leads.sql` for ADD COLUMN).

## Review Focus

1. Customer unsubscribes after a draft is queued but before send → job cancelled, never sent (Task 3 test).
2. Model returns prose or a ```json fenced block instead of bare JSON → still parsed. If unparseable → retried, then `failed` with a readable error (Task 2 test).
3. AI campaign paused mid-run → no new drafts are claimed (no API spend) and queued jobs are held, not sent (Task 3 + Task 4 tests).
4. Two overlapping cron runs → a contact is drafted and queued exactly once (Task 4 test: claim is an atomic UPDATE with a lock token; queueing re-checks under `FOR UPDATE`).
5. OpenRouter HTTP error (401 bad key, 402 no credit, 429, timeout) → stored error names the status and message, never the key. 401/402 fail fast without burning attempts on every contact (Task 4 test: such errors pause the campaign and record why).

## Testing approach

The project has no test framework and no new dependencies are allowed. Tests are plain CLI scripts in `scripts/tests/`, run with `php scripts/tests/<file>.php`, using a tiny assert helper. They print `PASS <name>` / `FAIL <name>: <detail>` and exit non-zero on any failure. DB tests run against the local dev database (seeded via `scripts/seed.php`), create their own rows, and delete them at the end. OpenRouter is faked by `scripts/mock-openrouter/router.php` (same pattern as `scripts/mock-zoom`).

---

### Task 1: Schema, config and test harness

**Files:**
- Create: `migrations/013_ai_campaigns.sql`
- Modify: `database.sql` (append the same tables/columns to the fresh-install schema, in the email section)
- Modify: `config/config.php` (next to `PAGESPEED_API_KEY`, line 84)
- Modify: `.env.example` if present (add the three keys)
- Create: `scripts/tests/_assert.php`
- Create: `scripts/tests/schema-test.php`

**Interfaces:**
- Produces: tables/columns exactly as in the spec's Data model section, plus `ai_campaign_contacts.lock_token VARCHAR(32) NULL` (indexed) and `ai_campaigns.last_error VARCHAR(500) NULL`; constants `OPENROUTER_API_KEY`, `OPENROUTER_DEFAULT_MODEL`, `OPENROUTER_API_BASE`, `AI_DRAFT_BATCH`, `AI_DRAFT_MAX_ATTEMPTS`, `AI_DRAFT_LOCK_MINUTES`; test helpers `t(string $name, callable $fn): void`, `eq(mixed $actual, mixed $expected, string $msg = ''): void`, `ok(bool $cond, string $msg = ''): void`, `t_done(): never` (prints summary, exits 1 on failures). `_assert.php` also requires `includes/init.php` with `NO_SESSION` defined.

- [ ] **Step 1: Write `scripts/tests/_assert.php` and the failing `schema-test.php`**

```php
t('ai tables exist', fn() => ok((bool) q_val("SHOW TABLES LIKE 'ai_campaign_contacts'")));
t('email_messages.ai_campaign_id', fn() => ok((bool) q_one("SHOW COLUMNS FROM email_messages LIKE 'ai_campaign_id'")));
t('email_jobs.ai_campaign_id', fn() => ok((bool) q_one("SHOW COLUMNS FROM email_jobs LIKE 'ai_campaign_id'")));
t('email_threads.ai_campaign_id', fn() => ok((bool) q_one("SHOW COLUMNS FROM email_threads LIKE 'ai_campaign_id'")));
t('config defaults', function () {
    ok(OPENROUTER_DEFAULT_MODEL !== '');
    eq(rtrim(OPENROUTER_API_BASE, '/') !== '', true);
    eq(AI_DRAFT_BATCH, 3);
});
t_done();
```

- [ ] **Step 2: Run, expect FAIL** — `php scripts/tests/schema-test.php` → FAIL on tables / undefined constant.

- [ ] **Step 3: Write the migration (header comment in the 012 style: "Run once after 012… Safe to re-run"), append to `database.sql`, add constants via `env()`.**

- [ ] **Step 4: Apply and run** — import `migrations/013_ai_campaigns.sql` into the dev DB twice (the second run must not error), then `php scripts/tests/schema-test.php` → all PASS.

- [ ] **Step 5: Commit** — `git commit -m "feat(ai-campaigns): schema, config and CLI test harness"`

---

### Task 2: OpenRouter client and reusable `ai_draft_email()`

**Files:**
- Create: `includes/openrouter.php`
- Create: `includes/ai-mail.php`
- Modify: `includes/init.php` (require both, where other includes are loaded)
- Create: `scripts/mock-openrouter/router.php`
- Create: `scripts/tests/ai-mail-test.php`

**Interfaces:**
- Consumes: constants from Task 1, `template_vars()`, `render_vars()`, `sanitize_html()`, `html_to_text()`.
- Produces:
  - `openrouter_chat(array $payload): array`: decoded response. Throws `OpenRouterException` (extends `RuntimeException`, has `public int $status`) on curl error, non-2xx, or a body with `error`. The message is `"OpenRouter <status>: <error.message>"` and never includes the key.
  - `ai_build_payload(array $customer, array $opts): array`: pure function. `$opts` keys: `model`, `research_prompt`, `subject`, `keep_subject` (bool), `email_template`, `web_search` (bool), `json_mode` (bool, default true).
  - `ai_parse_reply(string $content): array`: pure function. Returns `['research','subject','html_body','text_body']`. Throws `RuntimeException('AI reply was not valid JSON')` or `RuntimeException('AI reply missing html_body/text_body')`.
  - `ai_draft_email(array $customer, array $opts): array`: returns `['research','subject','html_body','text_body','usage'=>['prompt_tokens'=>int,'completion_tokens'=>int,'web_requests'=>int,'cost'=>float]]`. Not tied to AI Campaigns.
  - Mock: `php -S 127.0.0.1:8098 scripts/mock-openrouter/router.php`, with `.env` `OPENROUTER_API_BASE=http://127.0.0.1:8098/api/v1`. It answers `/api/v1/chat/completions` with a fixed valid JSON reply, and records the last request body in `scripts/mock-openrouter/last-request.json`. The `X-Mock-Mode` request header (or a model name containing it) picks `fenced` (reply wrapped in ```json), `prose` (no JSON), `401`, `402` or `no-json-mode` (400 whenever `response_format` is present).

- [ ] **Step 1: Write failing tests in `ai-mail-test.php`**

```php
$c = ['id' => 1, 'full_name' => 'Ann Lee', 'company' => 'Lee Plumbing', 'website' => 'https://leeplumbing.example', 'email' => 'ann@x.test'];
$o = ['model' => 'm/x', 'research_prompt' => 'Find competitors of {company}', 'subject' => 'Idea for {company}',
      'keep_subject' => false, 'email_template' => 'Hi {first_name}, ...', 'web_search' => true];

t('payload has both tools', function () use ($c, $o) {
    $p = ai_build_payload($c, $o);
    eq(array_column($p['tools'], 'type'), ['openrouter:web_fetch', 'openrouter:web_search']);
    eq($p['tools'][0]['parameters'], ['engine' => 'openrouter', 'max_uses' => 3, 'max_content_tokens' => 8000]);
    eq($p['max_tool_calls'], 6);
    eq($p['response_format'], ['type' => 'json_object']);
    ok(str_contains($p['messages'][1]['content'], 'Find competitors of Lee Plumbing'));
    ok(str_contains($p['messages'][1]['content'], 'https://leeplumbing.example'));
});
t('web search off drops tool', fn() => eq(array_column(ai_build_payload($c, ['web_search' => false] + $o)['tools'], 'type'), ['openrouter:web_fetch']));
t('json_mode off drops response_format', fn() => ok(!isset(ai_build_payload($c, ['json_mode' => false] + $o)['response_format'])));
t('parse bare json', fn() => eq(ai_parse_reply('{"research":"r","subject":"s","html_body":"<p>h</p>","text_body":"t"}')['subject'], 's'));
t('parse fenced json', fn() => eq(ai_parse_reply("Here:\n```json\n{\"research\":\"r\",\"subject\":\"s\",\"html_body\":\"<p>h</p>\",\"text_body\":\"t\"}\n```")['text_body'], 't'));
t('parse prose throws', function () { try { ai_parse_reply('Sorry, I cannot.'); ok(false); } catch (RuntimeException $e) { eq($e->getMessage(), 'AI reply was not valid JSON'); } });
t('missing body throws', function () { try { ai_parse_reply('{"subject":"s"}'); ok(false); } catch (RuntimeException $e) { eq($e->getMessage(), 'AI reply missing html_body/text_body'); } });
t('html sanitised', fn() => ok(!str_contains(ai_parse_reply('{"research":"","subject":"s","html_body":"<p>x</p><script>1</script>","text_body":"t"}')['html_body'], '<script')));
// Against the mock (skip with a notice if 127.0.0.1:8098 is not listening)
t('draft via mock', function () use ($c, $o) { $r = ai_draft_email($c, $o); eq($r['subject'] !== '', true); ok(is_float($r['usage']['cost'])); });
t('keep_subject uses rendered subject', fn() => eq(ai_draft_email($c, ['keep_subject' => true] + $o)['subject'], 'Idea for Lee Plumbing'));
t('no-json-mode retries without response_format', fn() => ok(ai_draft_email($c, ['model' => 'mock/no-json-mode'] + $o)['html_body'] !== ''));
t('401 error message has status, not key', function () use ($c, $o) {
    try { ai_draft_email($c, ['model' => 'mock/401'] + $o); ok(false); }
    catch (OpenRouterException $e) { eq($e->status, 401); ok(str_starts_with($e->getMessage(), 'OpenRouter 401')); ok(!str_contains($e->getMessage(), OPENROUTER_API_KEY ?: '§')); }
});
t_done();
```

- [ ] **Step 2: Run, expect FAIL** — `php scripts/tests/ai-mail-test.php` → FAIL "Call to undefined function ai_build_payload".

- [ ] **Step 3: Implement `includes/openrouter.php`, then `includes/ai-mail.php`, then the mock router.**
  - curl: POST JSON, `CURLOPT_TIMEOUT` 120, headers `Authorization: Bearer …`, `Content-Type: application/json`, `HTTP-Referer: APP_URL`, `X-Title: APP_NAME` (or the app-name constant the project uses).
  - The system prompt is the copy from the spec's "System prompt" bullets, kept as a `const AI_MAIL_SYSTEM_PROMPT` in `ai-mail.php`.
  - The user message lists the non-empty `template_vars($customer)` fields as `Label: value` lines, then `Research task:` (the prompt run through `render_vars`), then `Subject:`, then `Email template:`.
  - `ai_parse_reply`: first `json_decode`. If that fails, take the substring from the first `{` to the last `}` and decode it. `html_body` goes through `sanitize_html`. An empty `text_body` gets `html_to_text(html_body)` only when `html_body` is present.
  - `ai_draft_email`: if the response is a 400 whose message mentions `response_format`/`json`, retry once with `json_mode => false`. Content comes from `choices[0].message.content`, usage from `usage.prompt_tokens`, `usage.completion_tokens`, `usage.cost` (float, 0.0 if absent) and `usage.server_tool_use.web_search_requests` (0 if absent).

- [ ] **Step 4: Run with the mock up** — terminal A: `php -S 127.0.0.1:8098 scripts/mock-openrouter/router.php`; terminal B: `php scripts/tests/ai-mail-test.php` → all PASS.

- [ ] **Step 5: Commit** — `git commit -m "feat(ai-campaigns): OpenRouter client and reusable ai_draft_email"`

---

### Task 3: Shared sender support for `ai_campaign_id`

**Files:**
- Modify: `includes/email.php:63` `create_thread()`: add a trailing optional `?int $aiCampaignId = null` and write it to `ai_campaign_id`.
- Modify: `includes/email.php:83` `save_outbound_message()`: new optional key `ai_campaign_id` (doc comment line 80 updated).
- Modify: `includes/email.php:248`: `$isCampaign = (!empty($message['campaign_id']) || !empty($message['ai_campaign_id'])) && empty($message['in_reply_to']);`. Leave the `advance_contact_status` calls at lines 342 and 358 keyed on `campaign_id` only.
- Modify: `includes/queue.php:80` `enqueue_message()`: also insert `ai_campaign_id` when the message has one.
- Modify: `includes/queue.php:120` `claim_jobs()`: add `AND (ai_campaign_id IS NULL OR ai_campaign_id IN (SELECT id FROM ai_campaigns WHERE status = 'active'))`.
- Modify: `includes/queue.php:222`: suppression condition `($message['campaign_id'] || $message['ai_campaign_id']) && …`.
- Modify: `includes/queue.php:230`: add a parallel `if ($message['ai_campaign_id'])` block checking `ai_campaigns.status`, releasing the job for 300 s with `'AI campaign not active'`.
- Modify: `includes/tracking.php:233`: unsubscribe cancellation `AND (m.campaign_id IS NOT NULL OR m.ai_campaign_id IS NOT NULL)`.
- Modify: `includes/imap.php:227-235`: replies to AI mail keep `ai_campaign_id` on the thread and the inbound message (pass `$original['ai_campaign_id']`).
- Test: `scripts/tests/ai-sender-test.php`

**Interfaces:**
- Produces: `create_thread(int $wsId, int $customerId, ?int $campaignId, ?int $accountId, string $subject, ?int $aiCampaignId = null): int`; `save_outbound_message()` accepts the `ai_campaign_id` key.

- [ ] **Step 1: Write failing tests** (insert an `ai_campaigns` row directly, one customer, one queued message + job with `ai_campaign_id`; pass a stub `$limitOverride` and stub sending by pointing the message at a mail account the test creates with status `disconnected`, so that `send_message` returns a non-permanent error; assert on job state, not delivery)

```php
t('paused AI campaign: job not claimed', function () { /* pause */ eq(count(claim_jobs(50)), 0); });
t('unsubscribed before send: cancelled', function () { /* active, set customers.unsubscribed_at */ process_email_queue(5); eq(q_val('SELECT status FROM email_messages WHERE id=?', [$mid]), 'cancelled'); });
t('unsubscribe cancels pending AI jobs', function () { unsubscribe_customer($customer /* + args as its signature requires */); eq(q_val('SELECT status FROM email_jobs WHERE email_message_id=?', [$mid2]), 'cancelled'); });
t('standard message unaffected', function () { /* message with campaign_id NULL, ai_campaign_id NULL, unsubscribed customer → not cancelled by suppression (existing behaviour) */ });
t('create_thread stores ai id', fn() => eq((int) q_val('SELECT ai_campaign_id FROM email_threads WHERE id=?', [create_thread($ws, $cust, null, null, 'S', $cid)]), $cid));
t_done();
```

- [ ] **Step 2: Run, expect FAIL** — `php scripts/tests/ai-sender-test.php`.
- [ ] **Step 3: Make the edits listed above.**
- [ ] **Step 4: Run** — `php scripts/tests/ai-sender-test.php` → PASS, `php -l` every touched file.
- [ ] **Step 5: Commit** — `git commit -m "feat(ai-campaigns): sender, suppression and unsubscribe support for AI mail"`

---

### Task 4: Draft worker and queueing (`includes/ai-campaigns.php`)

**Files:**
- Create: `includes/ai-campaigns.php` (required from `includes/init.php`)
- Create: `cron/process-ai-campaigns.php`
- Create: `scripts/tests/ai-campaigns-test.php`

**Interfaces:**
- Consumes: `ai_draft_email()`, `OpenRouterException`, `create_thread(..., ?int $aiCampaignId)` and `save_outbound_message(['ai_campaign_id' => …])` from Task 3.
- Produces:
  - `ai_release_stale_drafts(): int`
  - `ai_claim_drafts(int $limit): array`: atomic `UPDATE ai_campaign_contacts SET draft_status='processing', locked_at=NOW(), attempts=attempts+1 … WHERE draft_status='pending' AND ai_campaign_id IN (SELECT id FROM ai_campaigns WHERE status='active') … LIMIT ?`, followed by a select of the claimed rows. Set `lock_token = random_token(16)` in the UPDATE, then select by that token (same pattern as `claim_jobs()`).
  - `ai_process_draft(array $contact): string`: returns the new `draft_status` (`ready|pending|failed|skipped`). Skips when the customer has neither website nor company (error `"no website or company"`). On `OpenRouterException` with status 401 or 402, the draft goes back to `pending` with `attempts - 1`, and the campaign is paused with the reason stored in `ai_campaigns.last_error`. On any other error it goes back to `pending`, or to `failed` once `attempts >= AI_DRAFT_MAX_ATTEMPTS`, with the error trimmed to 500 characters.
  - `ai_queue_ready(int $limit = 50): int`: for each `ready` contact of an active campaign whose customer is not unsubscribed or bounced, inside `transaction()` with `SELECT … FOR UPDATE` re-check: `create_thread`, then `save_outbound_message([... 'ai_campaign_id' => $id, 'status' => 'queued'])`, then `INSERT IGNORE INTO email_jobs (… ai_campaign_id …)`, then contact → `queued` + `email_message_id`, then `record_event($message, 'queued', [], 'queued:' . $messageId)`. Returns the count queued.
  - `ai_complete_finished(): void`: active campaigns with no `pending|processing|ready` contacts and no `pending|processing` jobs, and at least one job, become `completed`.
  - `ai_campaign_tick(): array`: release stale → claim `AI_DRAFT_BATCH` → process each (stop early past `CRON_MAX_RUNTIME`) → queue ready → complete. Returns the counts.
  - `cron/process-ai-campaigns.php`: header comment with the crontab line, then `cron_run('ai-campaigns', fn() => ai_campaign_tick());`.

- [ ] **Step 1: Write failing tests** (create a workspace-scoped AI campaign + 2 test customers with the mock as base; clean up after)

```php
t('claim only active campaigns', function () { /* campaign paused → ai_claim_drafts(10) returns [] ; active → 2 rows */ });
t('double claim is exclusive', function () { $a = ai_claim_drafts(10); $b = ai_claim_drafts(10); eq(count($a), 2); eq(count($b), 0); });
t('process → ready with usage', function () { eq(ai_process_draft($row), 'ready'); ok(q_val('SELECT cost_usd FROM ai_campaign_contacts WHERE id=?', [$row['id']]) !== null); });
t('no website or company → skipped', function () { /* customer with both empty */ eq(ai_process_draft($row2), 'skipped'); });
t('prose reply retried then failed', function () { /* campaign model mock/prose; process 3 times */ eq($statuses, ['pending', 'pending', 'failed']); });
t('402 pauses campaign, keeps attempts', function () { /* model mock/402 */ eq(q_val('SELECT status FROM ai_campaigns WHERE id=?', [$cid]), 'paused'); eq((int) q_val('SELECT attempts FROM ai_campaign_contacts WHERE id=?', [$rid]), 0); ok(str_contains((string) q_val('SELECT last_error FROM ai_campaigns WHERE id=?', [$cid]), '402')); });
t('queue ready creates message+job once', function () { eq(ai_queue_ready(), 1); eq(ai_queue_ready(), 0); eq((int) q_val('SELECT COUNT(*) FROM email_jobs WHERE ai_campaign_id=?', [$cid]), 1); eq(q_val('SELECT campaign_id FROM email_messages WHERE ai_campaign_id=?', [$cid]), null); });
t('stale processing released', function () { /* set locked_at = NOW() - INTERVAL 11 MINUTE */ eq(ai_release_stale_drafts(), 1); });
t_done();
```

- [ ] **Step 2: Run, expect FAIL** — `php scripts/tests/ai-campaigns-test.php` → undefined function.
- [ ] **Step 3: Implement `includes/ai-campaigns.php` and the cron file.**
- [ ] **Step 4: Run with the mock up** — expect all PASS. Re-run `php scripts/tests/ai-sender-test.php` → still PASS. Also `php cron/process-ai-campaigns.php` prints a summary without errors.
- [ ] **Step 5: Commit** — `git commit -m "feat(ai-campaigns): draft worker, queueing and cron"`

---

### Task 5: AI Campaigns pages

**Files (all new under `ai-campaigns/`, modelled on the matching `campaigns/` file):**
- `index.php`: list (name, status, contacts, drafted, sent, total cost), "New AI campaign" button.
- `_form.php`, `_save.php`, `create.php`, `edit.php`: fields and validation per the spec "Pages" section. Model input prefilled with `OPENROUTER_DEFAULT_MODEL`. Shows the `{vars}` list from `template_vars()` keys. Required: name, mail account, research prompt (≤ 5000), subject (≤ 255), template (≤ 20000).
- `contacts.php`: add customers using the same filters as `campaigns/contacts.php` (q, tags, company, …). `INSERT IGNORE INTO ai_campaign_contacts` with `draft_status='pending'`, excluding unsubscribed/bounced. Remove contacts that are not yet `queued`.
- `test.php`: GET picks one customer from the campaign's contacts (or any customer by id). POST runs `ai_draft_email()` synchronously (`set_time_limit(150)`) and renders research, subject, HTML preview (sanitised), text and cost. Saves and sends nothing. Errors show `OpenRouterException` messages.
- `start.php`, `pause.php`, `delete.php`: same flow as the campaigns versions, plus the start checks from the spec (including `OPENROUTER_API_KEY !== ''`, otherwise flash "Add OPENROUTER_API_KEY to .env"). Start clears `last_error`.
- `view.php`: totals (contacts, drafted, failed, skipped, sent, opened, replied, total `SUM(cost_usd)`), `last_error` banner if paused by an API error, contacts table with an expandable research/body row, "Retry failed" POST (resets `failed` → `pending`, `attempts = 0`, `error = NULL`).
- Modify: `includes/sidebar.php`: add `['ai-campaigns', 'AI Campaigns', 'ai-campaigns/index.php', 'megaphone']` after `campaigns`, and `'ai-campaigns'` to the `marketing` group after `'campaigns'`. Use a different icon if the icon set has a fitting one (check what `icon()` supports, e.g. `sparkles`).

Every page: `require_auth()`; mutating pages `require_permission('campaigns.manage')` + `require_post()` + CSRF as in the campaigns equivalents; every query scoped by `workspace_id = ws_id()`, loading via `find_or_404('ai_campaigns', …)`.

- [ ] **Step 1: Build pages in order index → form/save/create/edit → contacts → test → start/pause/delete → view → sidebar.**
- [ ] **Step 2: `php -l` every new file** — no syntax errors.
- [ ] **Step 3: Manual end-to-end against the mock** (`OPENROUTER_API_BASE` pointing at it). Each step must succeed:
  1. Create an AI campaign, then add 3 customers through the filters.
  2. Test on one customer: research, subject and preview render, and nothing new appears in `email_messages`.
  3. Start it, run `php cron/process-ai-campaigns.php` twice, and check that view shows 3 drafted and queued, with costs.
  4. Run `php cron/process-email-queue.php` and check that the messages leave the queue (sent, or failed against a dev SMTP), with the unsubscribe footer present in the sent body.
  5. Pause it: view shows paused, and the cron claims nothing.
  6. Using a member-role user, check that the manage buttons are hidden and that POSTs return 403.
  7. A user from another workspace gets a 404 for a campaign id that isn't theirs.
- [ ] **Step 4: Real API smoke test** — set the real `OPENROUTER_API_KEY` and unset `OPENROUTER_API_BASE`. Use "Test on one customer" with `deepseek/deepseek-v4.1-flash` on a customer with a real website. Confirm that the research names real competitors and that the cost shown is under $0.02. If the model rejects server tools, record that in the PR, try `openai/gpt-5-mini`, and set it as the `OPENROUTER_DEFAULT_MODEL` fallback.
- [ ] **Step 5: Commit** — `git commit -m "feat(ai-campaigns): AI Campaigns pages and sidebar entry"`

---

### Task 6: Docs and crontab

**Files:**
- Modify: `README.md`: an AI Campaigns section covering the `.env` keys, the crontab line `* * * * * php /home/USER/public_html/cron/process-ai-campaigns.php`, the approximate cost per customer, and a mock-server note.
- Modify: `deploy/` crontab/docs file if one lists the cron jobs.

- [ ] **Step 1: Update docs.**
- [ ] **Step 2: Run every test script** — `for f in scripts/tests/*-test.php; do php "$f" || exit 1; done` (with the mock running) → all PASS.
- [ ] **Step 3: Commit** — `git commit -m "docs(ai-campaigns): setup, cron and costs"`
