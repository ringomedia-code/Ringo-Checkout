# Ringo Stripe + PayPal Checkout

A WordPress plugin for [BassBoat4Sale.com](https://bassboat4sale.com) that handles the whole boat listing and payment flow: native listing forms, Stripe and PayPal checkout, package and add-on pricing, coupons, payment emails, abandoned and unpaid follow-ups, and payment failure tracking.

| | |
|---|---|
| **Version** | 9.4.7 |
| **Requires PHP** | 7.4 or newer |
| **Text domain** | `ringo-checkout` |
| **Author** | [Ringo Media](https://ringomedia.com/) |
| **License** | GPL-2.0-or-later |

## Contents

- [What it does](#what-it-does)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Pages and shortcodes](#pages-and-shortcodes)
- [How a listing is paid for](#how-a-listing-is-paid-for)
- [Add-ons](#add-ons)
- [Coupons](#coupons)
- [Emails and background jobs](#emails-and-background-jobs)
- [Payment failure monitoring](#payment-failure-monitoring)
- [Admin screens](#admin-screens)
- [Developer reference](#developer-reference)
- [Updates](#updates)
- [Project layout](#project-layout)
- [Changelog](#changelog)
- [More documentation](#more-documentation)

## What it does

- **Native boat forms.** Sellers submit and edit listings with forms built into this plugin (no JetFormBuilder needed).
- **Stripe and PayPal checkout.** One checkout for the listing package plus any selected add-ons, with a test/sandbox mode and a live mode.
- **Listing packages.** Standard, Featured, VIP and Pro, each with its own price, description, photo limit and video limit.
- **Smart add-ons.** Extra photos, extra video fields and service add-ons, purchasable with a new listing or for an existing published boat.
- **Coupons.** Coupon codes validated at checkout, with a tool for emailing coupons.
- **Draft until paid.** A new listing is saved as a Draft and publishes when payment succeeds.
- **Emails.** Customer and admin payment emails, abandoned-checkout and unpaid-boat follow-ups, and plain-language payment failure alerts.
- **Dealer accounts.** Dealers pay for their plan outside the site, so the base package price is waived and only paid add-ons are charged. A dealer with nothing to pay is published directly.
- **Payment safety nets.** A thank-you page check and a 10-minute background job both verify payment status with Stripe or PayPal, so a closed browser does not leave a paid boat unpublished.

## Requirements

- WordPress with PHP 7.4+
- **JetEngine** is required. The `boats` post type, the Boat Detail meta box and the boat taxonomies are registered by JetEngine.
- A Stripe account, a PayPal REST app, or both.
- An HTTPS site (needed by Stripe and PayPal).
- A working outgoing email setup (the site uses an SMTP plugin).

Bundled in `vendor/`, nothing to install: the Stripe PHP SDK and the Plugin Update Checker library.

The plugin works with the `boats` and `dlr_boats` post types. Change that list with the `ringo_boat_post_types` filter.

## Installation

1. Upload the plugin folder to `wp-content/plugins/` (for example `Ringo-Checkout-main`) and activate it.
2. On activation the plugin seeds the default add-ons and schedules its background jobs.
3. Go to **Ringo Custom Checkout > Settings** and enter your keys (see below).
4. Make sure the pages in [Pages and shortcodes](#pages-and-shortcodes) exist.

If you are replacing an older copy of this plugin that lives in a different folder, **deactivate the old one first, then activate the new one.** Both versions schedule the same cron jobs and use the same settings, so running both at once is not supported. Settings are stored in the database and carry over.

## Configuration

Open **Ringo Custom Checkout > Settings**. Settings are saved in the `ringo_stripe_settings` option.

| Setting | Notes |
|---|---|
| Mode | `test` or `live`. New installs start in **test**. |
| Stripe keys | Test and live secret and publishable keys. |
| PayPal | `sandbox` or `live` mode, with a client ID and secret for each. |
| Package prices | Defaults: Standard 59.99, Featured 79.99, VIP 225.99, Pro 225.99. |
| Package descriptions | Shown on the checkout. |
| Abandoned after | Seconds before an unpaid checkout counts as abandoned. Default 360 (6 minutes). |

**Keep live mode on live and test mode everywhere else.** Settings are stored in each site's database, so a staging site does not inherit the live site's mode or keys.

### Keys in `wp-config.php` (optional)

Stripe keys may be supplied as constants. They are used when keys are not saved in settings:

```php
define( 'RINGO_STRIPE_SECRET_KEY',      'sk_...' );
define( 'RINGO_STRIPE_PUBLISHABLE_KEY', 'pk_...' );
```

Other optional constants:

```php
define( 'RINGO_DEBUG', true );                              // mirror the plugin log to PHP error_log
define( 'RINGO_DRAFT_FAILURE_JOSH_EMAIL', 'name@example.com' ); // extra recipient for payment failure alerts
```

**Never commit API keys, webhook secrets or tokens to this repository.**

## Pages and shortcodes

The native forms are placed automatically on:

- `/add-boat/` for a new listing
- `/account/edit-post/?_post_id=BOAT_ID` for editing a boat, or paying for an unpaid Draft

Logged-out visitors are sent to `/register-login/`, with the form URL passed along as `redirect_to` so they return after logging in.

Manual shortcodes:

| Shortcode | Purpose |
|---|---|
| `[ringo_boat_submission_form]` | New boat listing form with checkout |
| `[ringo_boat_edit_form]` | Edit form for an existing boat |
| `[ringo_boat_pay_now]` | Pay for an existing Draft boat |
| `[ringo_addon_services]` | Add-on services page: choose **New listing** or **Existing published boat** |

Turn off automatic placement with the `ringo_native_auto_inject_forms` filter, and change the login URL with `ringo_native_login_url`.

## How a listing is paid for

1. The seller chooses a package and optional add-ons. The form's photo and video limits update to match.
2. The seller submits the form. The boat is saved as a **Draft** with its meta, taxonomies, featured image and gallery.
3. The seller pays through Stripe or PayPal. A coupon, if used, is applied.
4. On success the Draft is published and the seller and admin are emailed.
5. If the buyer closes the page early, the thank-you page and the background checker confirm the payment status with Stripe or PayPal and publish the boat if it was paid.

Package base limits:

| Package | Gallery photos | Video URL fields |
|---|---|---|
| Standard | 4 | 0 |
| Featured | 10 | 1 |
| VIP | 25 | 2 |
| Pro | 25 | 2 |

## Add-ons

- **Form add-ons** change the form's limits (extra gallery photos or extra video fields).
- **System add-ons** change the price and record the service without changing the form.

Manage them under **Ringo Custom Checkout > Add-ons**. Customers can also buy add-ons for a boat that is already published through the `[ringo_addon_services]` page. The listing package is not charged again, and completed purchases appear under the payments screen as add-on orders. After an add-on-only payment the customer lands on the boat's edit page, where the new limits apply immediately.

Details are in [`ADDON-MODULE.md`](ADDON-MODULE.md).

## Coupons

Manage codes under **Ringo Custom Checkout > Coupon Codes**. Codes are validated at checkout, and a tool on that screen emails coupons using an editable email template.

## Emails and background jobs

Two WP-Cron jobs run every 10 minutes (a custom `ringo_10min` schedule):

| Hook | What it does |
|---|---|
| `ringo_check_abandoned_checkouts` | Finds unpaid boats older than the abandoned window. If Stripe or PayPal says the payment completed, it publishes the boat. Otherwise it sends the abandoned-checkout follow-up to the customer and admin and marks the boat followed up. |
| `ringo_check_unpaid_boats` | Finds Draft boats still marked unpaid after the unpaid timeout (default 180 seconds) and sends a reminder to the customer and the admin, once per boat. It waits while a payment is still in progress. |

A third job, `puc_cron_check_updates-ringo-checkout`, runs every 12 hours and looks for plugin updates. That one is expected.

After install or an upgrade, run `wp cron event list` and confirm each job appears **once**.

Email types: customer payment confirmation, admin payment notification, abandoned-checkout, unpaid-boat reminder, payment failure alert and coupon emails. Sent emails are recorded under **Email Logs**.

## Payment failure monitoring

When a listing payment fails or stalls, a dedicated plain-language email goes to the seller and the site admin. It covers card rejections, gateway errors and timeouts, a stuck payment screen, delayed or pending status, and a checkout that was closed or abandoned. Each event is kept on the boat in `_ringo_draft_failure_history` and in the plugin log.

This channel has its own sent flags and does not mark a boat as followed up. Add recipients with the `RINGO_DRAFT_FAILURE_JOSH_EMAIL` constant or the `ringo_draft_failure_recipients` filter.

Details are in [`DRAFT-FAILURE-MONITORING.md`](DRAFT-FAILURE-MONITORING.md).

## Admin screens

All under the **Ringo Custom Checkout** menu (administrators only):

| Screen | Purpose |
|---|---|
| Payments | Payment records and add-on orders, with delete and mark-as-paid actions |
| Settings | Mode, keys, prices, descriptions, timings |
| Add-ons | Create and edit add-ons |
| Email Logs | Record of emails sent by the plugin |
| Coupon Codes | Create coupons and email them |

Two dashboard widgets are added: **Paid Boats**, and **Action Required** (unpaid and payment-failed boats).

## Developer reference

**Filters**

| Filter | Use |
|---|---|
| `ringo_boat_post_types` | Post types treated as boats (default `boats`, `dlr_boats`) |
| `ringo_native_auto_inject_forms` | Turn automatic form placement on or off |
| `ringo_native_login_url` | Login page URL for logged-out visitors |
| `ringo_draft_failure_recipients` | Recipients of payment failure alerts |
| `ringo_log_to_error_log` | Mirror a log message to PHP `error_log` |
| `ringo_allow_client_price_fallback` | Off by default for security: prices come only from the plugin settings. Return true to accept a browser-supplied price for an unknown package (legacy sites only) |

**Options:** `ringo_stripe_settings`, `ringo_checkout_addons`, `ringo_coupons`, `ringo_coupon_email_template`, `ringo_email_logs`.

**Boat meta** is prefixed `_ringo_` (for example `_ringo_checkout_status`, `_ringo_amount`, `_ringo_coupon_code`, `_ringo_draft_failure_history`). The native form also keeps the existing seller and boat meta names, and writes both `motor_hours` and `engine_hours` for compatibility.

**Taxonomies used:** `boatlength`, `boatcategories`, `boat-ownership`, `boat-status`, `boat-year`, `motor-make`, `motor-year`, `state`, `boat-make`.

**AJAX actions** are registered for form submission, payment preparation, Stripe intents, PayPal order create and capture, coupon validation, image processing and failure reporting. They live in `frontend/ajax-*.php` and `frontend/native-forms.php`.

## Updates

The plugin checks this repository for new versions using [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker), configured in `includes/updater.php`. It reads the `Version:` line in `ringo-stripe-paypal-checkout.php` on the `main` branch.

- **To release an update:** bump `Version:` in the plugin header and `RINGO_CHECKOUT_VERSION`, then merge to `main`. Every site running the plugin will then offer the update.
- **Do not change the version number casually.** Merging to `main` with a higher version makes every installed site offer an update, including production.
- If this repository is ever made **private**, the updater needs a read-only GitHub token, otherwise sites silently stop seeing updates. Keep the token in `wp-config.php`, not in the repository.

## Project layout

```
ringo-stripe-paypal-checkout.php   plugin header, constants, loader, activation
unpaid-boats-cron.php              unpaid-boat reminder job
includes/                          settings, helpers, Stripe, PayPal, add-ons, payment logic, updater
frontend/                          native forms, AJAX endpoints, thank-you page, assets
admin/                             menu, settings, payments, add-ons, logs, coupons, widget
emails/                            customer, admin, abandoned and failure email templates
cron/                              abandoned-checkout checker
vendor/                            Stripe SDK and Plugin Update Checker (bundled)
```

## Changelog

Versions follow the `Version:` line in the plugin header. Each version from 9.4.0 is a tag in this repository.

### 9.6.3 (2026-10-27)

- Edit form: fixed the "Contact For Pricing" checkbox being incorrectly pre-checked (and the Price field disabled) on dealer (`dlr_boats`) listings. The feed-sync import writes a `price_bracket` meta value as a price *range* for analytics, unrelated to whether the seller actually chose Contact For Pricing — the edit form was treating any non-empty `price_bracket` as that flag. Dealer listings now only count an exact "Contact For Pricing" / `contact_for_price` / `yes` value; regular `boats` listings are unchanged. Only `frontend/native-forms.php` changed.
- Sites on 9.6.2 will be offered this update.

### 9.6.2 (2026-10-15)

- Edit emails: the admin email after a seller edits a paid listing is now the full detailed template (listing, contact, boat and motor details, edit date, View Boat button; subject `ID - title - Was Updated`), and the seller email is a branded "Your Listing Was Updated" message with From and Reply-To headers. Changes are in `emails/admin.php`, `emails/customer.php` and `frontend/native-forms.php`.
- New listings are now always given the boat status "Available" on submission (the form has no status field, so it was left empty). The edit form is unchanged.
- The "sign in or create an account" notice is printed once per page load, so it no longer shows twice on `/add-boat/` when the form is rendered twice.
- Styles: `.ringo-native-notice` margin is now `165px auto` (`frontend/assets/native-forms.css`).
- Sites on 9.6.1 will be offered this update.

### 9.6.1 (2026-10-14)

- Seller form: the vehicle fields are reordered on the new and edit forms to Boat Year, Boat Make, Boat Model, Boat Length, Motor Year, Motor Make, Motor Model And HP, Motor Hours (Stock Number and HIN follow). Only `frontend/native-forms.php` changed.
- Sites on 9.6.0 will be offered this update.

### 9.6.0 (2026-10-14)

New seller form flow, dealer add-on handling and photo handling. Changes are in `frontend/native-forms.php`, `frontend/enqueue.php` and `includes/payment.php`.

- Seller form: fields are now Year, Make, Model, Length (new and edit forms). The package picker moved into Listing Basics, and the separate Step 1 and the "Selected checkout" box were removed.
- Add-ons are offered in a popup right before payment, with the running total, instead of cards on the form. When no add-on is enabled in the dashboard the popup is skipped. Add-ons preselected with `?ringo_addons[]=` are kept.
- Dealers: a dealer with no paid add-on publishes free from the popup, and a dealer with add-ons is charged for the add-ons only. This fixes a dealer listing being published free with the chosen add-ons dropped.
- Photo limit: too many selected photos now keep the selection, show an inline error with a Remove button per file, and block saving until the selection is within the limit. The server-side limit is unchanged.
- Photos removed on the edit form are now deleted from the media library (files and thumbnails) when the listing is saved.
- The legacy `payment_status` meta is now set to `paid` when payment completes (it was left at `unpaid`).
- Sites on 9.5.0 will be offered this update.

### 9.5.0 (2026-10-12)

Brings this repository in line with the code running on the production site and staging, which had moved ahead of the `v9.4.7` tag without a version change. 24 files changed since 9.4.7, mainly the native forms (`frontend/native-forms.php`), the Stripe and PayPal AJAX handlers, checkout helpers and payment logic, the add-on code, the customer, admin and abandoned emails, the abandoned-checkout cron and the unpaid-boat cron. Some admin and email files have small changes.

- The update checker and the `Update URI` header now point at `ringomedia-code/Ringo-Checkout` (the repository moved from `ammar458/Ringo-Checkout`).
- Add the full README and this changelog.
- Sites on 9.4.7 will be offered this update.
### 9.4.7 (2026-08-15)

- Add a helper note to the motor hours field on the native forms.

### 9.4.6 (2026-08-15)

- Add tooltips for dealer-only fields on the native forms.

### 9.4.5 (2026-08-15)

- Remove the `boatid` prefix from uploaded image filenames.

### 9.4.4 (2026-08-15)

- Restrict boat image uploads to JPG, PNG and WebP (other types are treated as JPG), and rename uploaded cover and gallery images from the boat ID. Gallery numbering continues after the images already kept when a boat is edited.

### 9.4.3 (2026-08-13)

- Force the native form top margin with `!important` so the theme cannot override it.

### 9.4.2 (2026-08-13)

- Increase the top margin of the native form container.

### 9.4.1 (2026-08-13)

- Remove the Checkout column from the Payments admin table.

### 9.4.0 (2026-08-13)

- Add a manual **Mark Paid** action on the Payments screen for unpaid boat listings paid outside Stripe and PayPal (bank transfer, cash, comped listings).
- First version published in this repository.

### Earlier versions

These are known from the project notes, not from this repository's history, so they have no dates.

- **9.3**: video-field compatibility fix. Extra Video add-ons are recognized in both the Add Boat and Edit Boat forms, and legacy rows named Extra Video or Additional Video are repaired at runtime. See [`ADDON-MODULE.md`](ADDON-MODULE.md).
- **7.5**: publish compatibility fix. Gallery IDs are stored as a comma-separated string, gallery arrays from 7.0 to 7.4 are normalized before publishing a Draft, publish-time PHP errors are caught so a third-party hook cannot show a critical-error screen, and a successful Stripe PaymentIntent can be retried through the same thank-you URL.
- **7.4**: the seller phone field auto-formats and validates as `xxx-xxx-xxxx`.
- **7.0**: the two JetFormBuilder payment forms (new boat, and Pay Now for a Draft) are replaced by native forms inside this plugin. See [`NATIVE-FORM-MIGRATION.md`](NATIVE-FORM-MIGRATION.md).
- **6.3**: dedicated draft payment failure emails, with plain-language content and their own sent flags. See [`DRAFT-FAILURE-MONITORING.md`](DRAFT-FAILURE-MONITORING.md).
## More documentation

- [`ADDON-MODULE.md`](ADDON-MODULE.md): add-on types, limits and the published-boat flow
- [`NATIVE-FORM-MIGRATION.md`](NATIVE-FORM-MIGRATION.md): the move from JetFormBuilder to native forms, taxonomy and meta preserved, and a staging test order
- [`DRAFT-FAILURE-MONITORING.md`](DRAFT-FAILURE-MONITORING.md): failure conditions, recipients and email behavior

---

Maintained by [Ringo Media](https://ringomedia.com/).
