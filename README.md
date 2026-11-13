# EcoLink – Smart Surplus Resource Optimization & Redistribution Platform

CSE 416 (Software Project Design and Development), Department of CSE, UITS.
Group: Md. Tanvir Rahman, Faisal Ahmed, Md. Sayed.

A web platform that unifies five surplus-resource categories (Food, Clothes,
Books, Electronics, Furniture) and replaces manual "post-and-claim" donation
listings with an automated, priority-based, category-weighted matching
algorithm between donors and verified receivers.

## Tech stack
- Frontend: HTML, CSS, vanilla JavaScript
- Backend: PHP 8 (PDO/MySQL, no framework — matches the proposal's tech stack)
- Database: MySQL
- Server: XAMPP / Apache

## Setup (XAMPP)

1. Copy the `ecolink` folder into `C:/xampp/htdocs/` (or your `htdocs`
   equivalent), so the site is reachable at `http://localhost/ecolink/`.
2. Start Apache and MySQL from the XAMPP control panel.
3. Open phpMyAdmin (`http://localhost/phpmyadmin`), create nothing manually —
   instead import `sql/schema.sql` directly (it creates the `ecolink_db`
   database, all tables, and seeds the five resource categories with their
   matching weights).
4. Check `config/db.php` — the defaults (`root` user, empty password) match
   a stock XAMPP install. Edit if your MySQL credentials differ.
5. Visit `http://localhost/ecolink/setup_admin.php` once to create your
   first admin account, then **delete `setup_admin.php`** for security.
6. Visit `http://localhost/ecolink/` — register a donor and a receiver
   account to try the flow. New receiver accounts need admin verification
   (`admin/verify_users.php`) before they can be matched against donations.

## How the matching algorithm works

See `matching/PriorityMatcher.php`. For every donation still marked
`available`, it scores each verified, active receiver on four 0–100
sub-scores:

| Factor    | What it measures                                             |
|-----------|---------------------------------------------------------------|
| Urgency   | Time-to-expiry (perishables like food score higher when closer to expiry) |
| Distance  | Haversine distance between donor and receiver coordinates    |
| Demand    | Receiver's active demand profile for that category (priority level + quantity coverage) |
| Capacity  | Receiver's stated intake capacity (1–10)                      |

These are combined using **category-specific weights** stored in
`resource_categories` (e.g. Food weighs urgency heavily; Books/Electronics
weigh demand-fit heavily), giving:

```
total_score = w_urgency*urgency + w_distance*distance
            + w_demand*demand   + w_capacity*capacity
```

Every candidate is saved to the `matches` table (for auditability/analytics),
the highest-scoring receiver is auto-assigned, and both parties get a
notification. Admins can tune each category's weights live from
`admin/manage_categories.php`. A receiver can decline a match, which
re-queues the donation and re-runs matching automatically.

Run `cron/run_matching.php` on a schedule (cron / Task Scheduler) to keep
re-matching donations that haven't found a receiver yet as new receivers
register or update their demand profiles.

## Donation lifecycle

`Available → Matched → Accepted → Pickup → Delivered → Completed`
(or `Cancelled`). Every transition is written to `lifecycle_log` for a full
audit trail. Reaching `Completed` also writes a row to `impact_log`
(estimated weight + CO₂ saved), which feeds `admin/reports.php`.

## Two matching flows

EcoLink now supports two complementary ways a donation finds a receiver:

1. **Auto-matching (`matching/PriorityMatcher.php`)** — when a donor uploads a
   resource, it's immediately scored against every verified receiver's
   **standing demand profile** (`receiver/demand_profile.php`) using
   category-weighted urgency/distance/demand/capacity, and auto-assigned.
2. **Request-driven matching (`matching/RequestMatcher.php`)** — a receiver
   can also post a specific, trackable **request** (`receiver/post_request.php`,
   e.g. "Office Chairs, 10–15 units"), browse scored candidate donations
   (`receiver/find_resources.php` → `receiver/resource_details.php`), and
   accept one directly. Donors get the mirror-image experience from
   `donor/smart_matching.php` → `donor/match_details.php`.

Both flows write to the same `donations` table and lifecycle
(`Available → Matched/Accepted → Pickup → Delivered → Completed`), so a
donor's `my_donations.php` page and the impact dashboards reflect either
path automatically. `sql/migration_add_requests.sql` adds the `requests`
table to an existing database if you'd already imported an earlier schema.

## Account & auth flow

`get_started.php` (choose role) → `register.php` → `verify_email.php`
(6-digit code) → role dashboard. `login.php` has a Donor/Receiver/Admin
toggle, and `forgot_password.php` → `reset_password.php` handles recovery.
`settings.php` gives every role a tabbed Profile & Settings page
(personal info, matching preferences, notification preferences, password).

**No mail server is configured in this academic setup**, so the email
verification code and the password-reset link are displayed on screen
instead of being emailed. Swap those two spots for `mail()`/SMTP
(e.g. PHPMailer) before any real deployment.

## Admin control center

| Page | What it does |
|---|---|
| `dashboard.php` | Platform overview + "Run Matching Now" |
| `users.php` | All donors/receivers, capacity scores, restrict/reactivate |
| `verify_users.php` | Verification queue (verify / reject) |
| `manage_categories.php` | Categories, per-category matching weights, resource records |
| `requests.php` | Every receiver request, with priority and match score |
| `matches.php` | Match queue with scores and lifecycle state |
| `donations.php` | Lifecycle monitoring with stage distribution |
| `matching_analytics.php` | Trend chart, per-category success rate, recent activity |
| `impact_dashboard.php` | Platform impact growth + category breakdown |
| `reports.php` | Reports & waste analytics |
| `moderation.php` | Report queue fed by `receiver/report_resource.php` |

## Folder structure

```
ecolink/
├── admin/              admin dashboard, user verification, category weight tuning, reports
├── donor/              donor dashboard, upload resource, my donations (lifecycle actions)
├── receiver/           receiver dashboard, browse matches, accept/decline/complete, demand profile
├── matching/           PriorityMatcher.php - the core algorithm
├── config/             db.php, app.php (session/helpers/constants)
├── includes/           header.php, footer.php
├── assets/css, assets/js, assets/uploads
├── cron/run_matching.php
├── sql/schema.sql
├── setup_admin.php     one-time first-admin creation (delete after use)
├── index.php, login.php, register.php, logout.php, notifications.php
```

## Notes / out of scope (per proposal)
- Physical logistics/last-mile delivery is coordinated manually between donor
  and receiver via the pickup address shown on a matched donation.
- No payment gateway (SSLCommerz, etc.) — not needed for a donation platform.
- No native mobile app in this version.


## NEW donation flow (admin approval + receiver request)

1. Donor uploads a resource -> status `pending_approval` (receivers cannot see it).
2. Admin opens **Donation Approvals** (`admin/approve_donations.php`) -> Approve (`available`) or Reject.
3. Approved donations show up for ALL verified receivers in **Browse Donations**
   (`receiver/browse_donations.php`), whether or not any algorithm "matched" them.
4. Receiver clicks **Request this Donation** -> row in `donation_requests`; donor is notified.
5. Donor opens **Receiver Requests** (`donor/donation_requests.php`) -> Accept / Reject.
   Accept -> donation `accepted`, other pending requests are auto-rejected.
6. Donor: Mark Picked Up -> Mark Delivered (My Donations). Receiver: **Confirm Received** -> `completed` + impact log.

Existing database? Import `sql/migration_donation_approval.sql`. Fresh install: `sql/schema.sql`.
`PriorityMatcher::matchDonation()` now only stores scores (no auto-assign) unless `$autoAssign = true`.


## Delivery Hub + live pop-ups (added)

Instead of donors and receivers arranging one-to-one delivery, an admin creates
area-based **hubs**. Several donors in an area drop items at the same hub and
each receiver collects theirs with a personal **Pickup ID**.

1. Admin: `admin/hubs.php` creates hubs (name, area, address, phone, hours).
2. Donor: after a receiver is accepted, `donor/hub_dropoff.php` -> choose a hub (hubs in the donor's area are listed first). A Pickup ID (`EH-XXXXXX`) is generated and the receiver is notified.
3. Admin/hub staff: `admin/hub_desk.php` -> **Confirm drop-off** (donation -> `pickup`, receiver notified "ready").
4. Receiver: `receiver/hub_pickup.php` shows the hub address and the Pickup ID card.
5. Hub desk verifies the ID (type it or look it up) -> **Hand over** (donation -> `delivered`); receiver then uses the existing **Confirm Received** -> `completed`.

The existing direct flow still works untouched. Hub tables (`delivery_hubs`, `hub_items`)
are created automatically on first use (or import `sql/migration_delivery_hub.sql`).

**Pop-ups:** `includes/activity_popup.php` (loaded from `footer.php`) + `api/activity.php`
poll every 12 s and show animated cards for: new donor/receiver joined, new donation
approved, item received, and hub activity. Only first name + area are shown (no
email/phone). Visitors can mute them with the "Live updates" chip.

### Hub staff accounts
Hub staff can sign up themselves on the **Register** page (role **Hub Staff**, pick the hub). An admin approves them in
Admin -> Delivery Hubs; until then they only see a "waiting for approval" screen. The admin can also create a staff
account manually under a hub. The staff member logs in with the
**Hub Staff** tab on the login page and only sees the Hub Desk of their own hub (`hub/desk.php`).
The new `hub` role and `delivery_hubs.manager_user_id` column are added automatically on first use
(or run the ALTER at the end of `sql/migration_delivery_hub.sql`).


## First-time setup / "nothing works" checklist
1. Open `/setup_check.php` (e.g. http://localhost/setup_check.php or https://yoursite/setup_check.php).
2. Press **Install / repair database** - it creates every missing table AND adds any missing columns, the 'hub' role, the 5 categories and the upload folder.
3. Create the admin account there (only possible while no admin exists), or press "Add demo data" on an empty DB.
4. **Delete `setup_check.php`** when it shows everything green.

`config/db.php` now picks the database automatically: opened as `localhost` -> local XAMPP DB `ecolink_db` (user `root`, no password);
any other domain -> the InfinityFree DB. Override with `config/db.local.php` if needed.

## Final delivery flow (4 roles: admin, donor, receiver, hub staff)
1. **Admin** verifies every account (donors, receivers, hub staff) in *User Verification* and approves every donation. Admin creates the hubs.
2. **Donor** chooses the **delivery hub while adding the donation** (Add a Resource -> Delivery Hub).
3. After admin approval the donor takes the item to that hub. **Hub staff** press **"Yes, donation received"** on the Hub Desk.
4. When a receiver is confirmed for the donation, they get a personal **Pickup ID** (Hub Pickup page + notification).
5. The **receiver** goes to the hub and shows the ID. **Hub staff** enter/verify it and press **Verify & complete** -> the delivery is marked
   delivered + completed and impact is recorded. (The receiver does not have to confirm separately.)
