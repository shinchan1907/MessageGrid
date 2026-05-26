# 🚀 Release Notes: v1.0.0-RC1 (Enterprise Release Candidate)

We are thrilled to announce the availability of the **Enterprise Release Candidate (RC-1)** for the **EspoCRM WhatsApp Cloud API Enterprise Integration Module**. This module converts your self-hosted CRM into a powerful omnichannel communication hub.

---

## 💎 Release Highlights

*   **⚡ High-Speed Omnichannel Interface**: Real-time multi-agent conversation feed, canned response snippets, sidebar user-profile context mappings, and dynamic search filter lists.
*   **🛡️ Strong Cryptographic Credentials Vault**: Full symmetric **AES-256-CBC** encryption linking sensitive credentials (tokens, app secrets, S3 passwords) directly to your local EspoCRM unique `cryptKey` salt.
*   **📦 Native Windows & Cross-Platform Compilers**: Built-in Windows PowerShell (`build_package.ps1`) and PHP compiler utilities to package extensions in seconds.
*   **⚙️ SaaS-Ready Architectural Prep**: Encapsulated abstractions for per-tenant configuration scopes, multi-tenant file folder namespaces on AWS S3, and dedicated queue routing identifiers.
*   **📊 Industrial Prometheus Diagnostics Exposer**: Compliant `/WhatsApp/diagnostics?format=prometheus` metrics scraper alongside an admin Support Telemetry Zip Bundle generator.
*   **🔒 GDPR Compliance Core**: Scheduled background cron job sweeps to purge historical message strings and corresponding file attachments securely in chunked database cycles.

---

## 🛠️ Deploying this Release

1.  Download the compiled extension bundle **`whatsapp-integration-1.0.0.zip`**.
2.  Log in to your EspoCRM dashboard as an administrator.
3.  Go to **Administration -> Extensions -> Upload**.
4.  Upload the ZIP file and run the installer. The CRM automatically flushes cache paths and compiles new metadata definitions in seconds!
