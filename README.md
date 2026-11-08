# VJ Car Rental (Symfony)

A Symfony 8.1 rebuild of the VJ Car Rental Version 15 PHP site. It uses the
same pages, CSS, scripts and images as Version 15, and is built in the same
steps as the course reference project.

The database has 21 tables, three fewer than Version 15, with the same
features. Records that only ever exist once per parent are stored on the
parent: review photos are a JSON list on `reviews.photos`, the open password
reset is in `users.reset_*`, and the return settlement is in
`bookings.settlement_*`. Messenger runs `sync://`, so there is no
`messenger_messages` table and no worker to run.

## Setup

```bash
composer install
docker compose up -d                        # MySQL 8 on :3310, phpMyAdmin on :8081
php bin/console doctrine:migrations:migrate # tables + the v15 fleet (75 vehicles) and add-ons
symfony serve -d
```

Open http://127.0.0.1:8000. Until an administrator exists every page shows
"Administrator setup is required" — follow it to `/setup` to create one, as in
Version 15. Customers register at `/register`.

Outside production the forgot-password page shows the reset link itself, as
Version 15 does; set `MAILER_DSN` in `.env.local` to also have it emailed.

## Deploying

The committed `.env` is for local development. On a public server, put these
in `.env.local` (never committed) or real environment variables:

```bash
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=<32+ random characters, e.g. php -r "echo bin2hex(random_bytes(32));">
SYMFONY_TRUSTED_HOSTS='^(www\.)?your-domain\.com$'
DATABASE_URL=...                 # a database user with a strong password
MAILER_DSN=...                   # so password-reset links are emailed
```

Then run `composer install --no-dev --optimize-autoloader` and
`php bin/console cache:clear`. Create the first administrator at `/setup`
straight away, since it stays open until one exists.

A debug build (`APP_ENV=dev`) only answers the addresses in
`DEBUG_ALLOWED_IPS`, because its profiler and error pages show configuration
and secrets. If the app sits behind a reverse proxy, set
`SYMFONY_TRUSTED_PROXIES` so the visitor's real IP is used.

## Tests

```bash
php bin/console doctrine:database:create --env=test --if-not-exists
php bin/console doctrine:migrations:migrate --env=test --no-interaction
php bin/phpunit
```

## Progress

| Branch | What it adds |
|---|---|
| `master` | Project initialization, MySQL + phpMyAdmin in Docker |
| `Creating_Controller` | v15 layout (header, footer, admin navigation), home, company pages, admin dashboard shell, error pages |
| `Entity_CRUD_Function` | `Vehicle` and `Addon` on the v15 tables with its reference data; v15 admin vehicle/add-on editors with image upload; fleet list and vehicle details |
| `Form_Manipulation_&_Repository` | v15 fleet filters, sorting and pagination; contact form saved to `contact_messages`; dashboard statistics |
| `User_Entity_Login_Register` | `User` on the v15 `users` table, v15 sign-in rules (15-minute lockout, inactive accounts), registration, administrator setup, customer account and profile editing, forgot/reset password, user access management, contact message inbox, promotion codes, maintenance records, audit log |
| `Booking_System` | v15 booking flow on the `bookings`, `booking_addons`, `booking_drafts` and `booking_draft_addons` tables: build a rental with live server-side estimates (dates, branch pickup or delivery, add-ons, promotion codes), save for later and resume, booking confirmation and details with the next step, manage booking by reference, My Bookings, Saved Bookings, account dashboard |

The customer pages of Version 15 are all built: payments (GCash, bank
transfer or cash, with proof upload) and invoices, rental documents,
notifications, verified-trip reviews, favorites and vehicle comparison, on the
v15 `payments`, `customer_documents`, `notifications`, `reviews` and
`favorites` tables. Uploaded documents, payment proofs and
review photos are kept privately in `storage/` and served only to their owner
and administrators.

Administrators verify payments (`/admin/payments`) and documents
(`/admin/documents`): approving the last requirement confirms the booking, and
a verified extension or modification payment applies that change. They also
moderate reviews (`/admin/reviews`), which then appear on the vehicle pages,
and have the rental calendar, reports with CSV export, and a system health
page. The old `/admin-login` address redirects to the single sign-in page.

Still to come: refunds, return settlements and the rental desk (booking
status changes, checkout and check-in).
