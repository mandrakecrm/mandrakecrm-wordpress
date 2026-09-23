# MandrakeCRM for WooCommerce

[![Latest release](https://img.shields.io/github/v/release/mandrakecrm/mandrakecrm-wordpress?label=release)](https://github.com/mandrakecrm/mandrakecrm-wordpress/releases/latest)
[![WordPress.org](https://img.shields.io/wordpress/plugin/v/mandrakecrm?label=wordpress.org)](https://wordpress.org/plugins/mandrakecrm/)
[![License: GPL v2 or later](https://img.shields.io/badge/license-GPLv2%2B-blue.svg)](LICENSE)
[![WordPress 6.0+](https://img.shields.io/badge/WordPress-6.0%2B-21759b.svg)](https://wordpress.org/)
[![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)](https://www.php.net/)

**CRM, marketing automation, campaigns and analytics for WooCommerce.** The plugin connects your store to MandrakeCRM: abandoned-cart recovery, marketing consent at checkout, campaign attribution (UTM) and review and cashback widgets — with pricing per order, not per contact.

- **Website:** https://www.mandrakecrm.com
- **WordPress.org:** https://wordpress.org/plugins/mandrakecrm/
- **Support:** hello@mandrakecrm.com

## What the plugin does

1. **Connects the store** to your MandrakeCRM account, so orders and customers reach the CRM in real time.
2. **Captures abandoned carts** at checkout (including guest checkouts) so the pre-configured recovery automations can follow up.
3. **Asks for marketing consent** with an opt-in checkbox at checkout, and records it with the order.
4. **Attributes orders to campaigns**: UTM parameters are captured in the browser and saved on the order, for both the classic and the block checkout, with or without a page cache.
5. **Shows the widgets** you enable in MandrakeCRM (reviews, cashback) on your storefront.

The complete description, FAQ and changelog are in [`readme.txt`](readme.txt) — the same file published on WordPress.org.

## Requirements

| | Minimum |
|---|---|
| WordPress | 6.0 |
| PHP | 7.4 |
| WooCommerce | 8.0 |

## Installation

**From WordPress.org:** *Plugins → Add New*, search for **MandrakeCRM**, install and activate.

**From a release:** download `mandrakecrm.zip` from the [latest release](https://github.com/mandrakecrm/mandrakecrm-wordpress/releases/latest) and upload it under *Plugins → Add New → Upload Plugin*.

**With WP-CLI:**

```sh
wp plugin install mandrakecrm --activate
```

## About this repository

This is the **public mirror** of the plugin. Every release published on WordPress.org is committed here with the exact contents of the distributed package, tagged `vX.Y` and attached to a GitHub Release. Development happens in a private monorepo next to the MandrakeCRM backend; see [CONTRIBUTING.md](CONTRIBUTING.md).

## License

GPL v2 or later. See [LICENSE](LICENSE).
