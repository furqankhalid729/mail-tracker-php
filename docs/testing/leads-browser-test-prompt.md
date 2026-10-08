# Browser test: Call Leads (for Claude in Chrome)

Paste everything below the line into Claude in Chrome. Fill in the four values in **Setup** first.

Before you run it: create a member with the **Closer** role (*Settings → Members*). Without one, section 5 can't run and the assign steps can't use a closer. For section 6, start the mock Zoom server (`php -S 127.0.0.1:8099 scripts/mock-zoom/router.php`) and point `.env` at it as described in that file.

---

You are testing the **Call Leads** feature of a PHP CRM web app in this browser. Work through every test below in order, check each expected result yourself, and finish with the report described at the end. Do not skip a test because an earlier one failed; note the failure and carry on. Do not change any data except what these tests create. Everything you create uses the prefix `QA-` so it can be found and cleaned up.

## Setup

- App URL: `APP_URL` (for example `http://127.0.0.1:8088`)
- Admin login: `ADMIN_EMAIL` / `ADMIN_PASSWORD` (role Admin or Super Admin)
- Closer login: `CLOSER_EMAIL` / `CLOSER_PASSWORD` (role Closer, a member of the same workspace). If no Closer exists, stop and ask for one rather than assigning to another role.
- Closer's display name: `CLOSER_NAME`
- Zoom tests (section 6): run them only if the admin says the mock Zoom server is configured. Otherwise mark them SKIPPED.

## How the app behaves

- The login page is `/login.php`. To switch users, open `/logout.php` and then log in again.
- Delete and bulk actions open an **in-page** confirmation dialog (not a browser alert). Click its confirm button (labelled Delete, Apply or Remove).
- Success and error messages appear as a toast in the bottom-right corner after the page reloads.
- The leads list subtitle shows the result count, for example "4 matching", "9 total" or "6 assigned to you". Use it to check filter results.
- Status filter buttons sit above the table. Other filters are under the **Filters** button and apply when you click **Apply**.

## Uploading test CSVs

The import page (`/leads/import.php`) needs a file. You cannot pick files from disk, so attach one with JavaScript. On the upload step, run the snippet below with the CSV text in `csv` and a file name in `name`, then click **Upload & continue**:

```js
const name = 'QA-sheet1.csv';
const csv = `PASTE CSV HERE`;
const input = document.querySelector('input[type=file][name=csv]');
const dt = new DataTransfer();
dt.items.add(new File([csv], name, { type: 'text/csv' }));
input.files = dt.files;
input.dispatchEvent(new Event('change', { bubbles: true }));
```

**QA-sheet1.csv**
```
Website,Email,Phones
https://kathyspamperedpaws.com,kathyspamperedpaws@live.com,(931) 647-0586
https://www.petpalaceofclarksville.com,helpservice.petpalace@gmail.com,(931) 436-6363; (931) 472-9026; (931) 503-2284
https://stbethlehemanimalclinic.com,,(931) 645-4111
https://www.petbutler.com,cs@petbutler.com; franinfo@petbutler.com,(800) 738-2885
https://kathyspamperedpaws.com,,(931) 647-0586
```

**QA-sheet2.csv**
```
name,googleMapsUrl,rating,reviewsCount,rawCardText,website,phone,fullAddress,duplicate
QA ARC Realty - Vestavia,https://www.google.com/maps/place/a2,4.5,12,ARC Realty | Real estate agency,https://www.google.com/url?q=https://arcrealty.com/&opi=1,+1 205-969-8910,"4501 Pine Tree Cir, Vestavia Hills, AL",1
QA AMC Realty,https://www.google.com/maps/place/a3,4.8,40,AMC Realty | 4.8 | Real estate agency,https://amcrealty.com,+1 205-522-9870,"300 18th St W, Jasper, AL",1
QA ARC Realty - Mountain Brook,https://www.google.com/maps/place/a4,4.3,7,ARC Realty - Mountain Brook,https://www.google.com/url?q=https://arcrealty.com/&opi=1,+1 205-969-8910,"2718 Cahaba Rd, Mountain Brook, AL",2
```

**QA-zoomtest.csv**
```
name,phone
QA Zoom Test A,+1 212-555-0007
QA Zoom Test B,+1 212-555-0014
QA Zoom Test C,(212) 555-0021
```

Leave the "List name" field on the mapping step as its default (the file name without `.csv`, for example `QA-sheet1`). Later tests filter on it.

Note: if any of these phone numbers already belong to leads in this workspace from an earlier run, imports will skip them as duplicates. If the first import reports duplicates you didn't expect, run the **Cleanup** section first and start again.

## Tests

### 1. Statuses (log in as Admin)

1.1 Open `/leads/index.php`, then click **Statuses**. Expect a list of statuses. On a new workspace that's New, No answer, Callback, Interested, Not interested, Won. The first one is labelled "default for new leads". Write down the full list.
1.2 Add a status named `QA Voicemail` with any color. Expect a success message and the status at the bottom of the list.
1.3 Add `QA Voicemail` again. Expect the error "A status with that name already exists."
1.4 Click ↑ on `QA Voicemail` once. Expect it to move up one place.
1.5 Go back to `/leads/index.php`. Expect a filter button for every status, in the same order as on the Statuses page, including `QA Voicemail`.

### 2. Import (Admin)

2.1 Import **QA-sheet1.csv**. On the mapping step, expect: Website → Website, Email → Email(s), Phones → Phone(s). Set **Assign all imported leads to** = `CLOSER_NAME`, Starting status = the first status, Duplicates = **Skip duplicates**. Click Continue, then **Start import**. Expect: Rows read 5, Imported 4, Duplicates skipped 1, Empty rows 0.
2.2 Import **QA-sheet2.csv**. Expect: name → Business name, googleMapsUrl → Google Maps URL, rating → Rating, reviewsCount → Reviews count, website → Website, phone → Phone(s), fullAddress → Address, and rawCardText and duplicate set to "Keep as …". Assign to **Nobody yet**. Expect: Imported 2, Duplicates skipped 1.
2.3 Import **QA-zoomtest.csv**, assigned to `CLOSER_NAME`. Expect: Imported 3.
2.4 Import **QA-sheet1.csv** again with Skip duplicates. Expect: Imported 0, Duplicates skipped 5.
2.5 On the leads list, search `QA ARC Realty - Vestavia` and open it. Expect: Website shows `arcrealty.com` (not a google.com link). Rating ★ 4.5 (12 reviews). A "Google Maps ↗" link. "Other imported columns (2)" expands to show rawCardText and duplicate.
2.6 Search `petpalace` and open the lead. Expect the name `petpalaceofclarksville.com`, 3 phone numbers shown as links whose href starts with `tel:`, and the email `helpservice.petpalace@gmail.com`.

### 3. Filters (Admin, on `/leads/index.php`; click Reset between tests)

3.1 Click each status filter button in turn. Expect the subtitle count to equal the number on that button. Click **All**.
3.2 Search `436-6363`. Expect exactly 1 result (petpalaceofclarksville.com).
3.3 Filters → Source / list = `QA-sheet2`. Expect 2 results.
3.4 Search `QA` and set Filters → Minimum rating = 4.5+. Expect 2 results: QA AMC Realty (4.8) and QA ARC Realty - Vestavia (4.5).
3.5 Filters → Assigned to = Unassigned, Source = `QA-sheet2`. Expect 2 results.
3.6 Filters → Source = `QA-sheet1`, Email = No email. Expect 1 result (stbethlehemanimalclinic.com).
3.7 Filters → Other column contains: column `rawCardText`, text `agency`. Expect both QA ARC Vestavia and QA AMC.
3.8 Click a status button, then add a Source filter. Expect both to apply together. Click **Reset**. Expect all filters cleared.
3.9 Click the column headers Lead, Attempts, Last call and Rating twice each. Expect the arrow and the order to flip each time, with no error page.

### 4. Assigning and bulk actions (Admin)

4.1 Filter Source = `QA-sheet2`. Tick the header checkbox, choose **Assign to** = `CLOSER_NAME`, click Apply and confirm. Expect "2 leads assigned."
4.2 Open `QA AMC Realty`. In **Assigned to**, pick Unassigned and click Save. Expect a success message and a timeline entry saying the lead was unassigned. Set it back to `CLOSER_NAME`.
4.3 Filter Source = `QA-sheet1`. Tick two rows, choose **Set status** = `QA Voicemail`, then Apply and confirm. Expect "2 leads set to QA Voicemail." Open one of them: its timeline shows the status change.

### 5. Closer view (log out, then log in as Closer)

5.1 After login, expect to land on `/leads/index.php`. The sidebar shows only Leads, Calls, Inbox, Customers and Settings.
5.2 Expect the subtitle to say "… assigned to you". There are no Statuses, Import CSV or Add lead buttons, and no "Assigned to" filter. The bulk action menu, after ticking a row, offers only "Set status".
5.3 Search `QA AMC`. Change its status with the dropdown in the Status column. Expect the page to reload with the new status, and the button counts to update.
5.4 Open `QA AMC Realty`. In **Update**, set status `Callback` (or any other status), add the note `QA call back Tuesday`, and Save. Expect the timeline to show the status change with the note underneath.
5.5 Visit `/leads/import.php`, `/leads/statuses.php` and `/leads/edit.php`. Expect an error page with 403 on each.
5.6 Log back in as Admin, find a lead that is **not** assigned to the closer (or unassign one), and copy its URL (`/leads/view.php?id=…`). Log in as Closer and open that URL. Expect a 404 page. If you unassigned a QA lead for this, reassign it afterwards.
5.7 As Closer, visit `/dashboard.php`. Expect a redirect to `/leads/index.php`.

### 6. Zoom attempts (Admin; only if mock Zoom is configured, otherwise SKIPPED)

6.1 Open `/calls/zoom.php`. If it isn't connected, click **Connect Zoom**. Then click **Sync now**. Expect a success message.
6.2 On Leads, filter Source = `QA-zoomtest`. Expect all 3 leads to show Attempts above 0, a "Last call" time and a result badge.
6.3 Open `QA Zoom Test A`. Expect the Zoom calls table to list calls, and the attempt count on the card to match the outbound calls in the table.
6.4 Filters → Total attempts = Not called yet, Source = `QA-sheet1`. Expect all 4 sheet1 leads (they were never called).
6.5 Edit `QA Zoom Test A` (Admin) and replace its phone with `+1 999 000 0000`. Save. Expect Attempts to be 0 and the calls table to be empty.
6.6 Edit it again and set the phone back to `+1 212-555-0007`. Expect the calls and attempts to come back straight away.

### 7. Add, edit, delete (Admin)

7.1 Click **Add lead**. Fill in only Phones = `+1 555 010 9999` and Source = `QA-manual`. Save. Expect the lead page, with the phone number used as the name.
7.2 Click **Add lead** and save with every field empty. Expect the error "Enter a name, or at least a phone, email or website."
7.3 Delete the lead from 7.1 with the trash button on its page, and confirm. Expect "Lead deleted." and that the lead no longer appears in the list.
7.4 On the Statuses page, click the trash icon for `QA Voicemail`. Expect a row to open **below** the status, fully inside the card (no sideways scrolling), with a "move its leads to" picker, a red Delete button and Cancel. Choose the first status, click Delete, and confirm in the dialog. Expect "Status “QA Voicemail” deleted." The 2 sheet1 leads from 4.3 now have the first status, and their timeline says "Previous status was deleted".

### 8. Regression

8.1 Open `/customers/index.php`, `/calls/index.php` and `/calls/numbers.php`. Expect each to load without an error page.
8.2 On the leads list, select some rows and choose **Delete** in the bulk menu. Expect the confirmation to say "Delete N leads?" with a red Delete button. Cancel it.
8.3 Phone links: on any lead from QA-sheet1 and from QA-sheet2, check that every phone link's href starts with `tel:+1`.
8.4 Throughout the run, note any page that showed a PHP warning, "Fatal error", "Something went wrong", or a broken layout.

## Cleanup (Admin, always run)

On `/leads/index.php`, for each source `QA-sheet1`, `QA-sheet2`, `QA-zoomtest` and `QA-manual`: filter by it, tick the header checkbox, click "Select all N matching" if shown, choose **Delete**, and confirm. Then check that a search for `QA` returns no leads, and that `QA Voicemail` no longer exists on the Statuses page.

## Report

Finish with:

1. A table with columns **Test #**, **Result** (PASS / FAIL / SKIPPED), and **Notes**. For every FAIL, give what you expected, what you actually saw (exact message or count), and the URL.
2. A short list of anything else odd you noticed, such as slow pages, layout problems or confusing wording.
3. Totals: passed, failed, skipped.
