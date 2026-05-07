# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]

## [0.2.0] - 2026-05-07
### Added
- Interactive `privacy:encrypt-field` command to encrypt existing data.
- PHP 8 Attribute support (`#[Protect]`) for defining privacy fields.
- `PrivacyEloquentBuilder` to support bulk model operations (insert, upsert, update).
- `ModelHandlingHelper` to programmatically update models with privacy configuration.

### Changed
- Migrated from `$privacyFields` property to `#[Protect]` attribute for better metadata handling.
- Enhanced `privacy:audit` command with path resolution and improved reporting.

## [0.1.0] - 2026-04-22
### Added
- Initial release of the privacy trait and audit command.
- Automatic encryption and decryption for privacy fields.
- Bulk write support for model-based inserts and updates.
- Privacy decryption exception handling.
- README and test coverage updates.
