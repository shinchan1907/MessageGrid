# Changelog

All notable changes to this project will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0-RC1] - 2026-05-26

### Added
*   **GDPR Retention Purge Engine (`cleanupRetentionData`)**: Implemented a database-safe, weekly scheduled background worker purge chunking old messages and S3/local attachments.
*   **Structured JSON Logs Tracer (`WhatsAppLogger`)**: Formatted telemetry stdout logs with unique Correlation Request IDs for Lok/Splunk.
*   **Prometheus Exposer Endpoint (`WhatsAppDiagnostics`)**: Exposed `/WhatsApp/diagnostics?format=prometheus` metrics gauges.
*   **Support Telemetry Bundle (`WhatsAppSupportBundle`)**: Compile-and-download secure diagnostic details with credential masks.
*   **SaaS tenant isolations abstractions (`SaaSManager`)**: Config mapping prefixes, per-tenant storage buckets, and dedicated tenant queues.
*   **Native Windows Packager (`build_package.ps1`)**: Added PowerShell ZIP compression scripts.

### Security
*   **Cryptographic Random IVs**: Swapped legacy PHP calls with native `random_bytes()` in `SecurityHelper`.
*   **Row-Level Assignment Locks**: Protected multi-agent claim race conditions using atomic database transactions.

---

[1.0.0-RC1]: https://github.com/YOUR_USERNAME/espo-whatsapp-integration/releases/tag/v1.0.0-RC1
