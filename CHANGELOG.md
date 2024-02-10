# Release Notes for Cloudflare R2

## 1.1.0 - 2024-02-10

### Changed
- Updated requirements for Craft CMS 5 compatibility
- Updated documentation URLs

## 1.0.1 - 2023-09-02

### Fixed
- Prevent calls to GetObjectAcl when copying and moving files ([#6](https://github.com/jrrdnx/craft-cloudflare-r2/issues/6))
- Cleanup unused classes

## 1.0.0 - 2022-12-29

### Changed
- Version change since [R2 is now generally available](https://blog.cloudflare.com/r2-ga/)
- Updated README.md

## 0.2.0-beta - 2022-07-14

### Added
- Added `$region` property (fixes manual bucket selection issue)

### Changed
- Replaced instances of `Craft::parseEnv()` with `App::parseEnv()`

### Removed
- Reverted `createAdapter()` back to using `visibility()` method (reverts object visibility to be based on `$makeUploadsPublic` property)
- Removed Cloudfront-related logic

## 0.1.0-beta - 2022-07-13

### Initial release
