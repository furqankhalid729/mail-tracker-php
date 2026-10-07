# Mail CRM

A lightweight email CRM and campaign manager that runs on ordinary shared hosting such as Hostinger Web Hosting or any cPanel host. It needs only **PHP 8.1+, MySQL/MariaDB, Apache and cron**. There is no Node.js, Redis, Docker or long-running worker.

- **Customers.** Search, filters, sorting, server-side pagination, bulk actions, tags, custom fields, CSV import/export and a profile timeline.
- **Campaigns.** Build audiences from tags and filters (for example Shopify AND USA) or pick people one at a time. Each contact gets a personalised copy of the template, and the queue is processed by cron.
- **Email.** A composer with templates, `{{variables}}`, live preview, attachments and drafts. Sends through SMTP (PHPMailer) or Gmail OAuth.
- **Tracking.** Opens (approximate), signed click redirects, unsubscribe (including RFC 8058 one-click), delivery and bounces (webhooks + IMAP DSN), and replies (IMAP / Gmail API with Message-ID matching).
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
customers/ campaigns/ inbox/ activity/ templates/ mail-accounts/ tags/ settings/   pages (+ _form/_save partials)
api/            JSON endpoints (kanban, campaigns, customers, tags, activity, search)
tracking/       open.php (pixel), click.php (signed redirect)
unsubscribe/    public unsubscribe + one-click
webhooks/       email.php delivery/bounce receiver
cron/           short-lived background jobs
includes/       auth, csrf, db helpers, queue, email, tracking, IMAP client, MIME parser, sanitizer
config/         config.php (.env loader), database.php, mail.php, constants.php
uploads/        attachments + CSV imports (deny-all .htaccess; set UPLOAD_PATH to move outside public_html)
storage/logs/   application + PHP error logs
```
