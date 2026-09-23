# Changelog

All notable changes to the MandrakeCRM WooCommerce plugin. This file is generated from the
`== Changelog ==` section of `readme.txt`, the one published on WordPress.org.

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

