# Mail CRM

A lightweight email CRM and campaign manager that runs on ordinary shared hosting such as Hostinger Web Hosting or any cPanel host. It needs only **PHP 8.1+, MySQL/MariaDB, Apache and cron**. There is no Node.js, Redis, Docker or long-running worker.

- **Customers.** Search, filters, sorting, server-side pagination, bulk actions, tags, custom fields, CSV import/export and a profile timeline.
- **Campaigns.** Build audiences from tags and filters (for example Shopify AND USA) or pick people one at a time. Each contact gets a personalised copy of the template, and the queue is processed by cron.
- **Email.** A composer with templates, `{{variables}}`, live preview, attachments and drafts. Sends through SMTP (PHPMailer) or Gmail OAuth.
- **Tracking.** Opens (approximate), signed click redirects, unsubscribe (including RFC 8058 one-click), delivery and bounces (webhooks + IMAP DSN), and replies (IMAP / Gmail API with Message-ID matching).
- **Team & roles.** Several Super Admins per workspace, plus Admin, Manager, Email Marketer and Member roles. Admins can create logins for teammates directly.
- **Calls (Zoom Phone).** Connect Zoom once with OAuth and assign phone numbers to closers. Call history syncs every 15 minutes. Closers see KPIs for their own numbers; admins see every closer, combined totals and day-by-day stats.
- **Tasks.** Create tasks, assign them to teammates, set priority and due dates, post progress (status, %, comments) and follow the team's workload in a progress report.
- **CRM.** A drag-and-drop pipeline board (the CRM stage is kept separate from the email status), an inbox with threaded replies, notes, a global activity feed, analytics charts and a dashboard.

---

## Requirements

| | |
|---|---|
| PHP | 8.1 or newer with `pdo_mysql`, `openssl`, `mbstring`, `curl`, `fileinfo`, `dom` (all standard on Hostinger) |
| Database | MySQL 5.7+ or MariaDB 10.3+ |
| Web server | Apache with `.htaccess` (`mod_rewrite`) |
| Background | Cron (hPanel → Advanced → Cron Jobs). An HTTP fallback is included. |

`ext-imap` is **not** required. The app has its own small IMAP client, so it keeps working on PHP 8.4, where `ext-imap` was removed.

---

## Deploying to Hostinger (step by step)

1. **Create the database.** hPanel → *Databases → MySQL Databases*. Create a database (for example `u123456789_crm`) and a user, and note the password.
2. **Database user.** This is created in the same step. Hostinger gives the user all privileges on that database automatically.
3. **Import the schema.** hPanel → *phpMyAdmin* → select the database → *Import* → choose `database.sql`.
   *(Or skip this step and use `install.php` in step 5, which imports it for you.)*
4. **Upload the app.** Zip the project **including the `vendor/` folder** and upload it with *File Manager* into `public_html/` (or a sub-folder such as `public_html/crm`), then extract it. You do not need `node_modules/` or `assets/css/src.css` on the server.
5. **Configure.** Choose one:
   - **Installer.** Open `https://yourdomain.com/install.php`, fill in the form, then **delete `install.php`**. The installer writes `.env` one level above `public_html` when it can.
   - **Manual.** Copy `.env.example` to `/home/USERNAME/.env` (above `public_html`, preferred) or into the app folder. Fill in the `DB_*` values and generate the secrets:
     ```
     php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
     ```
     Use separate values for `ENCRYPTION_KEY`, `CRON_SECRET` and `WEBHOOK_SECRET`. **Never change `ENCRYPTION_KEY` later**, because stored mail passwords and tokens are encrypted with it.
6. **Composer.** This is not needed on the server, because `vendor/` is uploaded. If you change dependencies, run `composer install --no-dev` locally and upload `vendor/` again.
7. **PHP version.** hPanel → *Advanced → PHP Configuration* → select **PHP 8.1** or newer (8.2+ recommended). Under *PHP options*, set `display_errors = Off` and `upload_max_filesize` ≥ 16M.
8. **HTTPS.** hPanel → *Security → SSL*: install the free SSL certificate. Keep `FORCE_HTTPS=true` in `.env`. The root `.htaccess` also redirects to HTTPS.
9. **Cron.** hPanel → *Advanced → Cron Jobs* → *Custom*. Add the jobs below, replacing `USERNAME` and the path. **Settings → Cron & webhooks** inside the app shows the exact lines for your server.
   ```
   * * * * *     /usr/bin/php /home/USERNAME/public_html/cron/process-email-queue.php
   */5 * * * *   /usr/bin/php /home/USERNAME/public_html/cron/check-inbox.php
   */5 * * * *   /usr/bin/php /home/USERNAME/public_html/cron/process-webhooks.php
   */15 * * * *  /usr/bin/php /home/USERNAME/public_html/cron/retry-failed.php
   */15 * * * *  /usr/bin/php /home/USERNAME/public_html/cron/sync-zoom-calls.php
   30 3 * * *    /usr/bin/php /home/USERNAME/public_html/cron/cleanup.php
   ```
   **Finding the PHP CLI path.** Hostinger's cron form usually pre-fills it. You can also run `which php` over SSH, or use the version-specific binary (for example `/opt/alt/php82/usr/bin/php`).

   **HTTP fallback** (only if CLI cron isn't available). Call the URL from any external cron service with the secret in a header:
   ```
   curl -s -H "Authorization: Bearer YOUR_CRON_SECRET" https://yourdomain.com/cron/process-email-queue.php
   ```
   `?token=YOUR_CRON_SECRET` also works, but the header is better because URLs can end up in logs. Without a valid secret, every cron URL returns `401`.
10. **Test SMTP.** *Mail Accounts → Add account*. The Hostinger preset fills in `smtp.hostinger.com:465 SSL` and `imap.hostinger.com:993`. Saving runs a connection test. Then use **Send test email**.
11. **Test tracking.** Send yourself a manual email from a customer profile, open it and click the link. The customer timeline should show *opened* and *clicked*.
12. **Test unsubscribe.** Add yourself to a campaign, start it, wait for cron, then click *Unsubscribe* in the email. The customer becomes *unsubscribed*, and pending campaign emails to them are cancelled.
13. **Test the campaign queue.** Start a small campaign. The banner on the campaign page shows queued/sent counts, and each cron run sends one batch (20 by default).

### Security checklist after deploying
- `install.php` is deleted (it locks itself, but should not stay public).
- `https://yourdomain.com/.env`, `/config/config.php`, `/vendor/` and `/uploads/` all return **403**.
- `ALLOW_REGISTRATION=false` once your team has accounts. Add teammates in *Settings → Members*.

### Upgrading an existing install
Import the files in `migrations/` once each, in order (phpMyAdmin → *Import*): `001_roles_and_tasks.sql`, then `002_closers_and_zoom.sql`, then `003_call_leads.sql`. Each is safe to run again. Fresh installs get these from `database.sql`.

---

## Roles & permissions

| Role | Can do |
|---|---|
| **Super Admin** | Everything, including managing other Super Admins. A workspace can have several. |
| **Admin** | Members, settings and mail accounts. Cannot change or create Super Admins. |
| **Manager** | Assigns tasks to anyone, sees all tasks and the team progress report, deletes and exports customers. |
| **Email Marketer** | Campaigns and templates, customer import, works on their own tasks. |
| **Closer** | Only their assigned Leads, the Inbox, viewing and emailing customers, and a Calls dashboard for their own assigned numbers. The pages are listed in `CLOSER_PAGES` in `includes/permissions.php`. |
| **Member** | Customers, notes and inbox, views campaigns, works on their own tasks. |

Each role also has everything the roles below it have. The rules live in one place, `PERMISSIONS` in `includes/permissions.php`. Change the minimum role there to adjust who can do what.

**Tasks.** Everyone can create tasks. Managers and above can assign tasks to anyone. Other roles' tasks are assigned to themselves, and they see only tasks assigned to them or created by them. The assignee, the creator or a manager can post progress. Marking a task *done* sets it to 100%, and posting progress on a to-do task moves it to *in progress*.

---

## How sending works

```
Start campaign → personalised email_messages + email_jobs (pending)
cron every minute → atomically claim ≤ N jobs → check: unsubscribed? bounced? campaign active?
                    daily workspace limit? per-account limit? → send → event → next
```
- **Atomic claim.** A single `UPDATE … LIMIT n` stamps a random lock token, so overlapping runs can never claim the same job. A MySQL `GET_LOCK` also makes a second overlapping run exit immediately.
- **Retries.** After 5 min, then 30 min, then 2 h, up to *max attempts*. Permanent SMTP errors (5xx, unknown user) are never retried. `retry-failed.php` gives transient failures one more attempt after the account recovers, and releases jobs stuck in *processing* for more than 10 minutes.
- **Limits.** Batch size per run, delay between emails, a workspace daily limit and a per-account daily limit. When a limit is reached, the backlog waits until tomorrow. Raising a limit releases it immediately.
- **Large campaigns.** *Start* queues what fits in ~20 seconds, and cron queues the rest in chunks of 500. Bulk customer operations over 5,000 rows are also processed by cron.

## Tracking accuracy (shown in the UI as well)
- **Sent ≠ delivered.** With SMTP, *sent* only means the server accepted the message. *Delivered* needs provider webhooks (SendGrid, Mailgun, Amazon SES or generic JSON at `/webhooks/email.php?provider=…`, authenticated with `WEBHOOK_SECRET`).
- **Opens are approximate.** Apple Mail Privacy Protection and corporate scanners pre-load images, and clients that block images never register an open.
- **Bounces** come from webhooks or from bounce (DSN) messages read through IMAP. A hard bounce suppresses the customer.
- **Privacy.** IP and user-agent storage can be switched off, are erased after a retention period you choose, and can be purged on demand (*Settings → Tracking & privacy*).

## Zoom Phone calls
1. Create an app in the [Zoom App Marketplace](https://marketplace.zoom.us/) (*Develop → Build App → General App*, **admin-managed**).
2. Set the OAuth redirect URL (and allow list) to `https://yourdomain.com/calls/zoom.php`.
3. Add the scope `phone:read:list_call_logs:admin`. Optionally add `user:read:user:admin`, which shows who connected.
4. Put the Client ID and Client Secret in `.env` as `ZOOM_CLIENT_ID` / `ZOOM_CLIENT_SECRET`.
5. In the app, open **Calls → Zoom** and click **Connect Zoom** as a Zoom account admin. The first sync fetches 90 days of history.
6. In **Calls → Closer numbers**, assign each closer their Zoom Phone numbers or extensions. You can type them in or upload a CSV with `email,phone_number,label`. Numbers match in any format, using the last 10 digits.

A call belongs to a closer when the caller or callee number matches one of their assigned numbers. Changing assignments re-attributes past calls too. A call counts as *connected* when Zoom reports it as answered. Talk time is the sum of connected-call durations. Data comes from the account-level `GET /phone/call_history` API.

## Call leads
Lists of businesses for closers to phone, such as Google Maps exports or Website / Email / Phones sheets.
- **Import** (admins): *Leads → Import CSV*. Columns are matched automatically and you can change the mapping. Several columns can go to *Phone(s)* or *Email(s)*, and a cell can hold several values separated by `;`. Google redirect links (`google.com/url?q=…`) are unwrapped to the real website. Columns you don't map are kept as extra fields and can be filtered with *Other column contains*. You can assign the whole file to one person and give it a starting status. A row is skipped as a duplicate when one of its phone numbers already belongs to a lead.
- **Assigning**: on import, in bulk from the list (*select → Assign to*, including "Select all matching"), or on the lead page. Closers see only their own leads. Managers and above see all leads.
- **Statuses**: admins define them in *Leads → Statuses* (name, color, order). Each status is a filter chip with a count, and new leads get the first one. Closers change the status from the list or from the lead page, with an optional note. Every change goes into the lead's timeline.
- **Attempts / last call** come from Zoom only. After every Zoom sync, calls are linked to a lead when the call's external number matches one of the lead's phones (last 10 digits). *Total attempts* counts outbound calls. *Connected* counts answered calls in either direction. *Last call* is the most recent call either way. Importing a lead also picks up Zoom calls that were already synced.

## Gmail / Google Workspace
- **Simplest option.** Use SMTP with an [App Password](https://myaccount.google.com/apppasswords) (`smtp.gmail.com:587 STARTTLS`, IMAP `imap.gmail.com:993`).
- **OAuth (optional).** Create an OAuth client of type *Web application* in Google Cloud Console. Add `https://yourdomain.com/mail-accounts/oauth.php` as the redirect URI, enable the Gmail API, and set `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET`. Tokens are encrypted, refreshed server-side and never reach the browser. Replies are matched by Gmail thread ID and Message-ID headers.

## Local development
```bash
composer install
npm install && npm run build:css      # only when you change Tailwind classes; app.css is committed
cp .env.example .env                  # APP_ENV=local, FORCE_HTTPS=false
mysql -u root -e "CREATE DATABASE mailcrm" && mysql -u root mailcrm < database.sql
php scripts/seed.php --with-activity  # demo@example.com / demo12345
php -S 127.0.0.1:8088
```
The PHP built-in server ignores `.htaccess`, so test the access rules on Apache.

## Project layout
```
customers/ campaigns/ tasks/ calls/ leads/ inbox/ activity/ templates/ mail-accounts/ tags/ settings/   pages (+ _form/_save partials)
api/            JSON endpoints (kanban, campaigns, customers, tags, activity, search)
tracking/       open.php (pixel), click.php (signed redirect)
unsubscribe/    public unsubscribe + one-click
webhooks/       email.php delivery/bounce receiver
cron/           short-lived background jobs
includes/       auth, csrf, db helpers, queue, email, tracking, IMAP client, MIME parser, sanitizer
config/         config.php (.env loader), database.php, mail.php, constants.php
migrations/     SQL upgrades for existing installs (import once, in order)
uploads/        attachments + CSV imports (deny-all .htaccess; set UPLOAD_PATH to move outside public_html)
storage/logs/   application + PHP error logs
```
