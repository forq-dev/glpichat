# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-08-03

### Added

- Enhanced launcher notification rendering with improved validation for typing indicators
- Improved chat widget interaction and performance optimization
- User selection for external followup associations in chat configuration
- User join/leave notification events and improved chat widget state handling
- Recipient filter to prevent unauthorized internal message notification delivery
- Per-participant send rate limiting to prevent abuse; guest authentication failure audit logging
- Last activity time tracking for chat participants with updated invite guard validation
- Notification flow for secure message handling with updated email templates
- Modular architecture for chat system with HTML sanitization security features
- Form submission helper and Enter-to-send functionality for improved user experience
- Audit log tab for ticket chat with refactored tab management for multiple entries
- Drag-and-drop file upload functionality and system notifications for chat events
- Dynamic launcher positioning, image lightbox, and persistent session state for the chat widget
- Overview section with a widget screenshot in the main README
- GitHub star badge and call-out in the README encouraging repository stars
- Contribution guide (`CONTRIBUTING.md`) for external contributors
- Environment variable example file (`.env.example`) covering the test suite
- GitHub issue templates — bug report and feature request — under `.github/ISSUE_TEMPLATE/`

### Changed

- Plugin now supports dynamic base URL to enable proper operation in GLPI subdirectories
- Error messages localized to Portuguese; improved internal event handling
- Standardized the project support footer across all documentation files (`README.md` and `docs/`)

### Fixed

- Improved dark theme color contrast for better readability in dark mode
- Fixed the "Create invite" button getting stuck on "Creating..." after a successful invite creation
- Fixed a guest session crash caused by an unhandled error when the session expired mid-action
- Fixed invite acceptance showing raw unstyled text instead of a proper error page when invite limits were exceeded
- Fixed invite and accept rate limits not being enforced due to an incorrect internal counter reference
- Fixed an incorrectly formatted internal event cursor used for guest activity tracking
- Corrected CSS syntax error in linear-gradient background property
- Fixed invite revocation logic enforcement
- Corrected the notification events table in `README.md` and `docs/features/notifications.md`, which listed 6 events when the code actually registers 8
- Fixed the screenshot link in `plugin.xml`, which pointed to a non-existent file
- Fixed the logo link in `plugin.xml`, which did not resolve to a valid image
- Fixed the published version's `download_url` in `plugin.xml`, which pointed to the unstable `main` branch zip instead of the fixed tag artifact
- Filled in the empty `homepage` field in `setup.php`
- Filled in the copyright notice placeholder in the `LICENSE` file

### Security

- Hardened XSS protection in URL path handling to prevent injection attacks
- Hardened guest session validation and controller access controls
- Implemented security hardening for file uploads with size validation and type checking
- Enforced CSRF token validation on all state-changing operations
- Implemented protection against Stored XSS vulnerabilities
- Restricted asset loading to prevent unauthorized resource inclusion
- Validated all file uploads to prevent malicious file handling
- Removed a hardcoded local absolute path exposing a developer's username and directory layout from a test helper script (`tests/helpers/patch_animations.py`)

[unreleased]: https://github.com/forq-dev/glpichat/compare/glpichat-1.0.0...HEAD
[1.0.0]: https://github.com/forq-dev/glpichat/releases/tag/glpichat-1.0.0
