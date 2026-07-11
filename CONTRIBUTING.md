# Contributing to HK Media Optimizer

Thanks for your interest in improving HK Media Optimizer! This document explains how to report issues and submit changes.

By participating in this project, you agree to abide by our [Code of Conduct](CODE_OF_CONDUCT.md).

## Reporting bugs

Open a GitHub issue and include:

- What you expected to happen and what actually happened.
- Steps to reproduce.
- Your WordPress version, PHP version, and active theme/plugins if relevant.
- Any error messages (check `wp-content/debug.log` with `WP_DEBUG` enabled).

For **security vulnerabilities**, do not open a public issue — see [SECURITY.md](SECURITY.md).

## Suggesting enhancements

Open an issue describing the use case and the problem it solves. Please check existing issues first to avoid duplicates.

## Development setup

See the **Local development** section of the [README](README.md#local-development). In short:

```bash
composer install   # PHP dependencies (Action Scheduler)
npm install        # linting + local environment tooling
```

## Pull requests

1. Fork the repository and create a topic branch off `develop`.
2. Make your change, keeping commits focused and descriptive.
3. Run the linters before pushing:
   ```bash
   composer phpcs
   npm run lint:js
   ```
4. Open a pull request against `develop` describing what changed and why. Reference any related issue.

Code should follow the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/) enforced by [`phpcs.xml`](phpcs.xml), stay compatible with PHP 7.4+, and use the `hkmo`/`HKMO` prefix and the `hk-media-optimizer` text domain.

## License

By contributing, you agree that your contributions are licensed under the
[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html) license.
