# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.0.0] - 2026-07-31

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

### Changed

- Plugin now supports dynamic base URL to enable proper operation in GLPI subdirectories
- Error messages localized to Portuguese; improved internal event handling

### Fixed

- Improved dark theme color contrast for better readability in dark mode
- Fixed the "Create invite" button getting stuck on "Creating..." after a successful invite creation
- Fixed a guest session crash caused by an unhandled error when the session expired mid-action
- Fixed invite acceptance showing raw unstyled text instead of a proper error page when invite limits were exceeded
- Fixed invite and accept rate limits not being enforced due to an incorrect internal counter reference
- Fixed an incorrectly formatted internal event cursor used for guest activity tracking
- Corrected CSS syntax error in linear-gradient background property
- Fixed invite revocation logic enforcement

### Security

- Hardened XSS protection in URL path handling to prevent injection attacks
- Hardened guest session validation and controller access controls
- Implemented security hardening for file uploads with size validation and type checking
- Enforced CSRF token validation on all state-changing operations
- Implemented protection against Stored XSS vulnerabilities
- Restricted asset loading to prevent unauthorized resource inclusion
- Validated all file uploads to prevent malicious file handling

[1.0.0]: https://github.com/forq-dev/glpichat/releases/tag/v1.0.0
