# AI Campaigns — Design

Date: 2026-10-24
Status: Draft for approval

## Intent

A new, separate page, "AI Campaigns", that works like Campaigns but writes every email for you.
You pick customers, give a research prompt and an example email (template + subject),
and for each customer the system:

1. reads the customer's website to learn their niche, services and location,
2. searches the web for their real competitors,
3. has the model write a personalised email following your template's format,
4. sends it automatically through the existing email queue.

Why: personalised competitor-based outreach converts better than a static template,
but researching each customer by hand doesn't scale.

## Goals

- Pick customers the same way as a normal campaign (same filters as campaign contacts).
- Per AI campaign: research prompt, email template (format guide) and subject, model, web-search on/off.
- Fully automatic: research → draft → queue → send, no review step.
- Each customer's research summary and generated email are stored and visible on the campaign page.
- Cost visible: OpenRouter usage (tokens, searches, cost) logged per customer and totalled per campaign.
- Sending, tracking, unsubscribe, replies and daily limits behave like normal campaigns.
- **The AI drafting logic is a standalone, reusable function** (`includes/ai-mail.php`), not tied to the AI Campaigns
  pages, so normal campaigns (or single emails) can use AI-written mails later.

## Non-goals (for now)

- Changing the existing Campaigns module. It keeps working exactly as today. (User: "make this separate for now".)
- Manual review/edit of drafts before sending (user chose auto-send).
- Fixed-template-with-AI-placeholders mode (user chose "model writes the whole email").
- Follow-up sequences.

## Anti-goals

- Emails that invent facts about the customer or name fake competitors.
- A run that silently burns API credit with no visibility.
- A batch that bypasses existing daily send limits.
- OpenRouter calls inside a normal page request for a whole batch (would time out). Batch work runs in cron.
- Any behaviour change for existing standard campaigns.

## Decisions made with the user

- **Separate module** with its own tables and pages; Campaigns untouched.
- **Provider:** OpenRouter Chat Completions (`https://openrouter.ai/api/v1/chat/completions`).
- **Model:** builder's choice. My call: per-campaign model field, default from `OPENROUTER_DEFAULT_MODEL` in `.env`,
  falling back to `deepseek/deepseek-v4.1-flash` (the model the user wants to test with).
- **Research via OpenRouter server tools** in a single request: `openrouter:web_fetch` (site) and
  `openrouter:web_search` (competitors).
- **Model writes the whole email**, using the user's template as the format/tone guide.
- **Auto-send**, no review.

## Data model

Migration `migrations/013_ai_campaigns.sql` (also appended to `database.sql`):

```sql
CREATE TABLE IF NOT EXISTS ai_campaigns (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  workspace_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  description TEXT NULL,
  status ENUM('draft','active','paused','completed','archived') NOT NULL DEFAULT 'draft',
  mail_account_id INT UNSIGNED NULL,
  model VARCHAR(120) NOT NULL,
  research_prompt TEXT NOT NULL,
  subject VARCHAR(255) NOT NULL,
  keep_subject TINYINT(1) NOT NULL DEFAULT 0,   -- 1 = use subject as-is (with {vars}); 0 = AI may personalise it
  email_template MEDIUMTEXT NOT NULL,           -- example email / format guide
  web_search TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_ai_campaigns_status (workspace_id, status),
  CONSTRAINT fk_ai_campaigns_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  CONSTRAINT fk_ai_campaigns_account FOREIGN KEY (mail_account_id) REFERENCES mail_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per customer in an AI campaign; also holds that customer's draft
CREATE TABLE IF NOT EXISTS ai_campaign_contacts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  workspace_id INT UNSIGNED NOT NULL,
  ai_campaign_id INT UNSIGNED NOT NULL,
  customer_id INT UNSIGNED NOT NULL,
  draft_status ENUM('pending','processing','ready','queued','failed','skipped') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  research TEXT NULL,
  subject VARCHAR(255) NULL,
  html_body MEDIUMTEXT NULL,
  text_body MEDIUMTEXT NULL,
  prompt_tokens INT UNSIGNED NULL,
  completion_tokens INT UNSIGNED NULL,
  web_requests SMALLINT UNSIGNED NULL,
  cost_usd DECIMAL(10,6) NULL,
  error VARCHAR(500) NULL,
  email_message_id INT UNSIGNED NULL,
  locked_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_ai_cc (ai_campaign_id, customer_id),
  KEY idx_ai_cc_status (ai_campaign_id, draft_status),
  CONSTRAINT fk_ai_cc_campaign FOREIGN KEY (ai_campaign_id) REFERENCES ai_campaigns(id) ON DELETE CASCADE,
  CONSTRAINT fk_ai_cc_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Link sent mail back to the AI campaign (additive, nullable; existing rows unaffected)
ALTER TABLE email_messages ADD COLUMN ai_campaign_id INT UNSIGNED NULL, ADD KEY idx_messages_ai_campaign (ai_campaign_id);
ALTER TABLE email_jobs     ADD COLUMN ai_campaign_id INT UNSIGNED NULL, ADD KEY idx_jobs_ai_campaign (ai_campaign_id, status);
ALTER TABLE email_threads  ADD COLUMN ai_campaign_id INT UNSIGNED NULL;
```

AI campaign mail is stored with `campaign_id = NULL` and `ai_campaign_id` set, so nothing in the standard
campaign code paths (stats, `campaign_contacts`, completion) ever sees it.

## Pipeline

```
Start AI campaign (page request, fast)
  └─ status = active; contacts already have draft_status = pending

cron/process-ai-campaigns.php (every minute, new)
  └─ release drafts stuck in 'processing' > 10 min back to 'pending'
  └─ claim up to AI_DRAFT_BATCH (default 3) pending contacts of ACTIVE AI campaigns → 'processing'
  └─ for each: ai_draft_email() → save research/subject/body/usage → 'ready'
       error: attempts++ → back to 'pending'; after 3 attempts → 'failed' with error
  └─ queue every 'ready' contact: create_thread + save_outbound_message + email_jobs row
       (ai_campaign_id set, campaign_id NULL) → 'queued', store email_message_id
  └─ mark AI campaigns 'completed' when no contacts are pending/processing/ready and no jobs pending

process_email_queue() (existing cron) sends the jobs.
```

### Small, additive changes to the existing queue sender

Only in `process_email_queue()` in `includes/queue.php`. Both are new `if` branches, and standard behaviour stays the same:

1. **Suppression check.** It currently runs only when `campaign_id` is set. Extend it to `campaign_id || ai_campaign_id`,
   so unsubscribed or bounced customers never get AI mail.
2. **Active check.** When `ai_campaign_id` is set, the AI campaign must be `active`, otherwise release the job for 5 min
   (mirrors the existing campaign check).

`save_outbound_message()` and `create_thread()` need to accept an optional `ai_campaign_id`. Add it as an
optional key/param with a NULL default so existing callers don't change. Unsubscribe links and tracking must be
added the same way they are for campaign mail. The builder should check how `save_outbound_message` decides to
add the unsubscribe footer (if that depends on `campaign_id`, extend the condition to `ai_campaign_id`).

## Reusable AI drafting (`includes/ai-mail.php`)

```php
/**
 * Research a customer and write one email. Not tied to AI Campaigns; any feature can call it.
 * $opts: model, research_prompt, subject, keep_subject, email_template, web_search
 * Returns: ['research','subject','html_body','text_body','usage'=>[prompt_tokens, completion_tokens, web_requests, cost]]
 * Throws RuntimeException on API/parse failure.
 */
function ai_draft_email(array $customer, array $opts): array
```

and `includes/openrouter.php` with `openrouter_chat(array $payload): array` (curl, 120 s timeout,
`Authorization: Bearer OPENROUTER_API_KEY`, `HTTP-Referer: APP_URL`, `X-Title: <app name>`).
`OPENROUTER_API_KEY` and `OPENROUTER_DEFAULT_MODEL` are defined in `config/config.php` via `env()`, like `PAGESPEED_API_KEY`.

### OpenRouter request

```json
{
  "model": "<model>",
  "tools": [
    { "type": "openrouter:web_fetch",
      "parameters": { "engine": "openrouter", "max_uses": 3, "max_content_tokens": 8000 } },
    { "type": "openrouter:web_search",
      "parameters": { "engine": "parallel", "max_results": 5, "max_total_results": 10 } }
  ],
  "max_tool_calls": 6,
  "response_format": { "type": "json_object" },
  "usage": { "include": true },
  "messages": [
    { "role": "system", "content": "<fixed system prompt>" },
    { "role": "user", "content": "<customer details + research prompt + template + subject>" }
  ]
}
```

- `web_search` is left out of `tools` when it's turned off for the campaign.
- The `openrouter` fetch engine is free, and Parallel search costs $0.001–0.005, so expect about $0.01 or less per customer plus model tokens.
- If the model rejects `response_format` (some models don't support it), retry once without it and rely on the prompt + JSON extraction.

**System prompt** (fixed, in code):
- You research a business and write one outreach email.
- Fetch the website first. Work out the niche, services and location. If web search is available, search for
  competitors in that niche and location.
- Only state facts you fetched or found. If unsure about a competitor, leave it out. Never invent numbers.
- Follow the user's template for structure, length, tone and sign-off; fill in specifics for this customer.
- Return only JSON: `{"research": "...", "subject": "...", "html_body": "...", "text_body": "..."}`;
  `html_body` uses only p, br, strong, em, ul, li, a.

**User message:** the customer's fields from `template_vars($customer)` (name, company, website, city/country…),
the research prompt (with `{vars}` rendered via `render_vars`), the subject and the template.

**After the response:** parse the JSON (fall back to extracting the first `{...}` block). Missing `html_body` or
`text_body` is a failure. Run `html_body` through the existing sanitizer. If `keep_subject` is set, the subject is
`render_vars(subject, $customer)`. Usage comes from `usage` (tokens, `cost`) and `usage.server_tool_use.web_search_requests`.

### Customers without a website (my call)

The email is still drafted from the company name, location and web search. If a customer has no website and no
company name, the contact is marked `skipped` ("no website or company").

## Pages (`ai-campaigns/`)

Sidebar: "AI Campaigns" in the Marketing group after Campaigns, permission `campaigns.manage` to manage and the same
view permission as Campaigns to view.

- `index.php`: list with status, contacts, drafted, sent, total cost.
- `create.php` / `edit.php` / `_form.php` / `_save.php`: name, description, sending account, model
  (text input, prefilled with default), research prompt, subject, "use subject exactly" toggle, email template
  (textarea), web search toggle. Shows the available `{vars}`.
- `contacts.php`: add/remove customers using the same filters as `campaigns/contacts.php`
  (reuse `contact_filters_from_request()` / filter SQL where possible, inserting into `ai_campaign_contacts`).
  Unsubscribed or bounced customers are excluded.
- `test.php`: **"Test on one customer"** (my call: auto-send makes a preview the only safe way to tune a prompt).
  Runs `ai_draft_email()` synchronously for one chosen customer and shows research, subject, body and cost without
  saving or sending.
- `start.php` / `pause.php` / `delete.php`: same rules as campaigns. Start requires a connected account, prompt,
  template, subject, `OPENROUTER_API_KEY` set and at least one pending contact.
- `view.php`: totals (contacts, drafted, failed, skipped, sent, opened, replied, total cost). Opened and replied
  come from `email_messages` by `ai_campaign_id`. Below that, a contacts table with customer, draft status, subject,
  send status, cost and error, plus an expandable row showing the research summary and email body. A "Retry failed"
  button resets failed contacts to pending with attempts = 0.

## Constraints

- PHP, plain includes, existing helpers (`q`, `q_one`, `q_all`, `transaction`, `render_vars`, `flash`, `url`,
  CSRF, `require_permission`). Match the style of `campaigns/` and `includes/queue.php`.
- No new Composer dependencies; use curl.
- Respect existing daily send limits (handled by the existing sender).
- API key never logged or shown.
- Register the new cron in `cron/` with `cron_run()` and document the crontab line at the top of the file
  like the others.

## Left to the builder

- UI layout (copy the campaigns pages).
- Constant names/defaults (`AI_DRAFT_BATCH`, max attempts, lock timeout).
- How tracking events (opened/replied) attribute to AI campaigns. Counting from `email_messages.status` by
  `ai_campaign_id` is enough for the view page.
