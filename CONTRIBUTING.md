# Contributing to EspoCRM WhatsApp Cloud API Enterprise Integration

First of all, thank you for taking the time to contribute! We welcome contributions to enhance multi-channel sales productivity.

## Development Setup

1. Clone the repository:
   ```bash
   git clone https://github.com/YOUR_USERNAME/espo-whatsapp-integration.git
   ```
2. Make your code changes inside the isolated folder `files/custom/Espo/Modules/WhatsApp/`.
3. Never modify EspoCRM core database structures directly.

## Indentation and Coding Standards

* All PHP classes must adhere to **PSR-12** formatting rules.
* Indentation size is strictly set at **4 spaces** for PHP files and **2 spaces** for JSON files.

## Pull Request Guidelines

1. **Create a Feature Branch**: Prepend with type tags (e.g. `feature/waba-templates-sync`, `fix/crypto-random-iv`).
2. **Run Diagnostic Tests**: Verify that the automated standalone test suite passes without errors:
   ```bash
   php tests/run_tests.php
   ```
3. **Commit Messages**: Write meaningful, action-oriented, imperatively styled commit messages (e.g. `feat: implement GDPR retention cleanup cron job`).
4. **Compile ZIP Package**: Compile the final package via Windows PowerShell or PHP CLI:
   ```powershell
   powershell -ExecutionPolicy Bypass -File .\build_package.ps1
   ```
