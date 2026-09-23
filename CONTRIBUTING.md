# Contributing

Thank you for taking the time. This repository is the public mirror of the MandrakeCRM WooCommerce plugin: every tag here is a release that is also published on [WordPress.org](https://wordpress.org/plugins/mandrakecrm/).

## Reporting a bug

Open an [issue](https://github.com/mandrakecrm/mandrakecrm-wordpress/issues/new) with:

- the plugin version (shown on the plugins list),
- WordPress, WooCommerce and PHP versions, and whether the store uses the classic or the block checkout,
- the page where it happens, what you expected and what you saw.

Please do **not** report security issues in a public issue. See [SECURITY.md](SECURITY.md).

## Proposing a change

Development happens in a private monorepo, next to the MandrakeCRM backend the plugin talks to. Pull requests here are welcome and are reviewed like any other change; when accepted, the change is applied upstream, released, and mirrored back here with credit in the changelog. A pull request against this repository is therefore the right way to propose a fix — it just does not merge here directly.

Before opening one, please make sure that:

1. `php -l` passes on every file for PHP 7.4 through 8.4.
2. The [official Plugin Check](https://wordpress.org/plugins/plugin-check/) reports no errors.
3. Every input is sanitized, every output is escaped where it is printed, and every admin action checks a nonce **and** a capability.
4. User-facing strings are in English and wrapped in the `mandrakecrm` text domain.
