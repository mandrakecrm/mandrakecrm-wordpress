# Changelog

All notable changes to the MandrakeCRM WooCommerce plugin. This file is generated from the
`== Changelog ==` section of `readme.txt`, the one published on WordPress.org.

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

