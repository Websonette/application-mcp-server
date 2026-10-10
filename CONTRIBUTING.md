# Contributing

Follow [Websonette's contribution guidelines](https://github.com/Websonette/.github/blob/main/CONTRIBUTING.md) and [CI contract](https://github.com/Websonette/.github/blob/main/docs/CI.md).

Use strict types and PHP 8.3-compatible syntax. Run `composer test` before submitting a change. Changes to authorization, approvals, token handling or transactional writes require regression tests. Keep application business logic in the consumer. See `docs/architecture.md` for ownership.
