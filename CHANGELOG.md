# Changelog

Notable changes to `tamara-php-sdk` are documented here.

## [2.0.0] - 2026-10-26

### Breaking changes

- Moved all public classes, contracts, traits, exceptions, and services from the colliding `Tamara\` namespace to `TamaraPhpSdk\` so this package can coexist with Tamara's official PHP SDK.
- Renamed the optional Laravel facade alias from `Tamara` to `TamaraPhpSdk`.

### Documentation

- Added the unofficial-project notice, an official-versus-community SDK comparison, corrected capture metadata requirements, and sandbox verification status.

## [1.1.9] - 2026-10-26

### Fixed

- Treat stale webhook events as acknowledged and future lifecycle events as retryable conflicts.
- Return an error for unknown stored Tamara statuses instead of silently ignoring events.
- Validate financial totals using each currency's minor units, including three-decimal currencies.
- Use the configured Laravel default locale and remove the unused global currency setting.
- Require webhook tokens to be passed explicitly; removed unreliable process-superglobal fallbacks.

### Added

- Checkout persistence preflight and reconciliation logging for remote checkout IDs.
- Atomic webhook processing leases and cache-backed deduplication.
- PHP 8.2, 8.3, and 8.4 CI matrix.

### Notes

- The test suite uses the repository's lightweight custom runner; the unused PHPUnit development dependency was removed.
- The SDK does not automatically retry API requests. Retrying payment mutations without endpoint idempotency guarantees could duplicate operations.
- Sandbox verification confirmed checkout, approval, authorisation, and partial refund. The account auto-captured on authorisation, so manual capture and cancellation were not verified.

