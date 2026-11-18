# Changelog

All notable changes to this project are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [3.0.0] - 2026-11-18

### Changed

- **Breaking:** require PHP 8.2 and WordPress 6.4 (previously PHP 5.6 and WordPress 4.4).
- The new site is created and the origin tables are listed in the current process, instead of
  parsing the output of WP-CLI subprocesses. Site creation now prints
  `Success: Site N created: <url>` before the final message.

### Fixed

- The command no longer fails with "Invalid new site ID" when PHP prints a notice before
  WordPress loads, e.g. from `wp-config.php` with `WP_DEBUG` enabled ([#1]).
- URLs stored with escaped slashes (`https:\/\/`), as in page builders, ACF JSON fields and
  plugin settings, are now rewritten on the copy.
- Copying the main site keeps links to other sites of a subdirectory network, and their
  uploads URLs, instead of pointing them to the copy.
- Copying the main site matches other sites on their real table prefix, so plugin tables such
  as `wp_404_to_301` are copied, and a main site other than blog 1 keeps its own tables.
- Copying the main site skips network-wide tables and the tables of other sites.
- The user passed as `<email>` stays administrator of the copy, `user_level` is copied, and
  only the `{prefix}user_roles` option is renamed.
- The command stops when copying the rows of a table fails.

### Added

- End-to-end tests on a DDEV multisite (`ddev e2e-test`), and a GitHub Actions workflow that
  runs PHPCS, Psalm and the e2e tests.

## [2.0.3] - 2023-03-27

Earlier versions are documented by their Git tags only.

[3.0.0]: https://github.com/BeAPI/wp-cli-site-duplicator-command/compare/2.0.3...3.0.0
[2.0.3]: https://github.com/BeAPI/wp-cli-site-duplicator-command/releases/tag/2.0.3
[#1]: https://github.com/BeAPI/wp-cli-site-duplicator-command/issues/1
