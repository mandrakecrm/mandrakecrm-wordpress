# Changelog

All notable changes to the MandrakeCRM WooCommerce plugin. This file is generated from the
`== Changelog ==` section of `readme.txt`, the one published on WordPress.org.

## 3.26
* Abandoned carts: the order now records which cart it came from, so MandrakeCRM can
  link the purchase to that exact cart instead of guessing by email and date.
* Fix: a purchase now also closes the customer's other open carts (another browser or
  device, same email or account), so nobody gets "complete your purchase" messages
  after buying.
* Fix: a cart that stays full after a paid purchase (some redirect payment methods) is
  no longer tracked again as a new abandoned cart. An unpaid order (for example a
  pending Pix or boleto) keeps the cart in the recovery flow until it is paid.
* Fix: the block checkout now closes the cart when the order is placed and keeps the
  recovery link on the order, like the classic checkout.
* Logged-in customers get a new cart for each shopping session, so carts from different
  months are no longer merged into one.
* No new settings, texts or remote calls. Deleting the plugin also removes the cart
  counter it keeps on each user.

## 3.25
* New: WhatsApp consent at checkout. A separate checkbox, "I agree to receive offers
  from <your store> on WhatsApp", sits next to the email one, in the classic and the
  block checkout. MandrakeCRM records the customer's choice with the order date.
* The checkbox is shown on every MandrakeCRM plan, so the consents are ready when you
  send WhatsApp campaigns (Scale plan). It shows your store name in MandrakeCRM. You can
  turn it off in WooCommerce > MandrakeCRM > "WhatsApp Checkbox at Checkout" (on by default).
* Fix: the email checkbox now reaches MandrakeCRM, in the classic and the block checkout.
  It only counts when the order is placed: a new customer who leaves it ticked stays
  subscribed and one who unticks it gets no campaigns (order emails still arrive); it never
  changes an existing subscriber or someone who unsubscribed.
* Fix: with "Transactional Emails" on, WooCommerce 11 also sent its own new
  account email (and, in some steps, its processing and failed order emails), so the
  customer got two. Now only MandrakeCRM's arrives.
* The order page in the admin shows the customer's WhatsApp choice.
* No remote calls were added to the checkout.
* Compatibility: the recovery token of abandoned carts and the Brazilian Market fields of
  the order emails are now read and saved through the order object, so they also work
  with High-Performance Order Storage (HPOS).
* Fix: malformed coupon or checkout-field requests no longer cause a server error.
* Abandoned carts waiting to be processed are now handled oldest first.
* Fix: a new visit that arrives with UTM parameters now replaces the campaign data of the
  previous visit instead of mixing both, so each order keeps where it really came from.

## 3.24
* Performance: order emails (processing, on hold, completed, refunded, cancelled,
  failed) are now sent in the background with Action Scheduler, the WooCommerce job
  queue. Checkout no longer waits for the MandrakeCRM API: with card payments that
  move the order to on hold and then processing, the customer saved up to ~15
  seconds on the "Place order" button. Changing order statuses in bulk from the admin
  is fast again for the same reason.
* Fix: the same order email was requested twice when an order changed status (once
  per WooCommerce notification and once per status change). It is now queued once.
* The email content is unchanged and still describes the status that triggered it,
  even if the order has moved on when it is sent. Emails go out within about a
  minute (as soon as WP-Cron or a server cron runs the queue). To send them during
  the request as before, use the `mandrakecrm_async_order_emails` filter.

## 3.23
* Tested up to WooCommerce 11.1.
* Translations updated: the bundled .pot now matches every string in the plugin,
  and the English (UK), Spanish and Portuguese (Brazil and Portugal) translations
  include the current plugin name.

## 3.22
* Fix: changing a cart quantity could fail with a fatal error when the theme sent
  the key of an item that was no longer in the cart (e.g. WoodMart's mini-cart from
  a stale tab). `WC_Cart::set_quantity()` does not validate the key and adds an
  entry without a product; that entry is now dropped before totals are calculated,
  and abandoned-cart tracking skips any cart item without a valid product.
* Tested up to WordPress 7.1.

## 3.21
* Fix: orders placed through the block checkout recorded no campaign attribution
  at all. `woocommerce_checkout_create_order` only exists in the classic checkout;
  the Store API builds the order separately and never fires it, so every block
  checkout store was losing 100% of its UTM data — with or without a page cache.
  Attribution is now also saved from
  `woocommerce_store_api_checkout_update_order_meta`.
* Attribution is written once per order: if an order already carries UTM data it
  is left untouched, so the classic and block entry points cannot stamp it twice.

## 3.20
* Fix: campaign attribution was lost on stores with a full-page cache. UTM
  parameters were only read on the server, and a page cache serves a stored
  copy without ever starting PHP, so a visitor landing on a cached page with
  `?utm_source=...` produced no tracking cookie and their order had no
  attribution. Measured on a production store: only 70% of campaign orders
  were attributed.
* UTM parameters are now also captured in the browser, which runs on every page
  view regardless of the cache. Same cookie, same 30-day window, same order
  meta — no change to stored data or to existing reports.
* Server-side capture is kept as well, so visitors with JavaScript disabled are
  still tracked and page-cache behavior stays exactly as before.
* Hardening: values read back from the tracking cookie are now restricted to
  known UTM keys and capped in length.

## 3.19
* Removed debug logging that was triggering on every request and saturating
  server logs on customer sites. Improves performance and reduces disk I/O.
* Security: removed debug entries that were writing customer emails and
  password-reset URLs to PHP error logs.

## 3.18
* Marketing opt-in checkbox now inherits the store's primary brand color
  (resolved from common theme CSS variables: Woodmart, Astra, generic
  `--primary-color`, `--brand-color`, `--wp--preset--color--primary`, and
  WooCommerce default). Border-left accent of the box also matches.
* Fix: duplicate checkmark overlay in themes that decorate native checkboxes
  with their own `::before/::after` SVG. The checkbox is now fully custom-
  rendered with `appearance:none` plus an inline SVG checkmark, so only one
  check is shown regardless of the theme.

## 3.17
* New: Cashback redemption coupons are auto-applied to the cart from a deep-link.
* When the customer clicks "Aplicar agora" on the redemption widget, the URL
  carries `?apply_coupon=CB-XXXXXXXX`. The plugin detects it, calls
  `WC()->cart->apply_coupon()` and redirects to the cart page so the discount
  shows up immediately — no manual paste needed.
* Strict whitelist: only codes matching `^CB-[A-Z0-9]{8}$` are applied; any
  other coupon code in the URL is ignored. Idempotent: re-loading the URL
  does not duplicate the coupon.
* WC native security still enforced (email_restrictions, usage_limit,
  expiration, etc.). The plugin only triggers the apply — WC validates.

## 3.16
* Widget script now loads on checkout and cart pages to support Order Bump and other checkout widgets
* No behavior change for product/home/shop pages — existing widgets continue working as before
* Each widget in the bundle internally detects its applicable context

## 3.15
* Internal release — version bump for plugin maintenance

## 3.14
* Internal release — version bump for plugin maintenance

## 3.13
* Renaming popup → widget across the entire plugin interface
* Stability and performance improvements

## 3.12
* Added Privacy Policy and Terms of Service pages
* Improved compliance with WordPress.org plugin guidelines
* Fixed activation check when WooCommerce is not installed

## 3.11
* Improved admin interface and user-facing text
* Complete internationalization (English, Portuguese, Spanish)
* Documentation updates

## 2.0.0
* New plugin architecture with HPOS and Block Checkout compatibility
* Modern admin UI and improved security
* Enhanced marketing attribution tracking

## 1.0.0
* Initial release

