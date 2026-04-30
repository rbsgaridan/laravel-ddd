# Changelog

All notable changes to `incoder/laravel-ddd` should be documented in this file.

## Unreleased

- Added package maintenance tooling: tests, CI, static analysis, formatting config, and Composer scripts
- Added `config/incoder-ddd.php` for package conventions and generator defaults
- Hardened service-provider bootstrapping and classmap loading
- Made several hard-coded package conventions configurable while preserving existing defaults
- Fixed the default TypeScript proxy output path to `resources/js/proxies`
