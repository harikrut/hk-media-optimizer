# HK Media Optimizer

Lightweight, batch-based scanner that finds unused media files in your WordPress Media Library and lets you safely review and delete them. Built to run on shared hosting without spiking server load.

- **Requires WordPress:** 5.8 or higher
- **Requires PHP:** 7.4 or higher
- **License:** GPL-2.0-or-later

## Features

- **Unused media scan** across post/page content, featured images, attachment relationships, custom fields/post meta, Advanced Custom Fields values, widgets (including block widgets), the Customizer (logo, background, header), and the site icon.
- **Batched by design** — every scan runs in small, configurable batches over AJAX, so it works comfortably on modest shared hosting.
- **Duplicate finder** that compares files by their actual contents (MD5), groups true duplicates, and always keeps at least one copy per group.
- **CSV export** of unused, in-use, or full scan results.
- **Optional scheduled scans** (daily/weekly/monthly) with an email summary — nothing is ever deleted automatically.
- **Safety controls** — per-source toggles, a safety window for recent uploads, whitelisting, excluded file types, adjustable batch size, and a type-to-confirm deletion safeguard.

Results are stored in the plugin's own database tables (not `wp_postmeta` / `wp_options`), and assets load only on the plugin's own admin screens.

## Installation

1. Upload the plugin to `/wp-content/plugins/hk-media-optimizer`, or install it through the WordPress plugins screen.
2. Activate it from the **Plugins** screen.
3. Go to **Media Optimizer** in the admin menu to run your first scan.
4. Review **Media Optimizer → Settings** to tune scan sources and safety rules before deleting.

## Local development

This plugin bundles [WooCommerce Action Scheduler](https://actionscheduler.org/) via Composer. The `vendor/` directory is **not** committed — install dependencies before running the plugin from a git checkout.

### Requirements

- PHP 7.4+
- [Composer](https://getcomposer.org/)
- Node.js 24+ (see [`.nvmrc`](.nvmrc)) — only needed for linting and the local WP environment

### Setup

```bash
# PHP dependencies (installs Action Scheduler into vendor/)
composer install

# JS tooling and local environment helpers
npm install
```

### Coding standards & linting

```bash
composer phpcs          # WordPress Coding Standards
composer phpcs:compat   # PHP 7.4+ cross-version compatibility
composer lint-fix       # auto-fix fixable PHPCS violations

npm run lint:js         # ESLint on assets/js
npm run lint:js-fix     # auto-fix JS issues
```

### Local WordPress environment

Uses [`@wordpress/env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/):

```bash
npm run env:start   # start a local WordPress with the plugin active
npm run env:stop
```

## Contributing

Bug reports and pull requests are welcome. Please read [CONTRIBUTING.md](CONTRIBUTING.md) and the [Code of Conduct](CODE_OF_CONDUCT.md) first.

## License

HK Media Optimizer is free software, released under the
[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html) license.
