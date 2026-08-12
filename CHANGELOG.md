# Release Notes for Cloudflare R2

## Unreleased

### Added
- Added presigned upload support, letting clients upload straight to the bucket instead of proxying the bytes through PHP
- Added presigned support for replacing an existing asset's file, including transform invalidation and the `beforeReplaceFile`/`afterReplaceFile` events
- Added a “Use Presigned URLs” filesystem setting, off by default, with a configurable size threshold below which uploads keep going through PHP
- Added a progress readout for direct uploads whose caller doesn’t already show one, so replacing a file no longer sits on a bare spinner
- Added a warning when navigating away from an in-progress direct upload, and a best-effort abort so abandoned multipart uploads stop costing storage
- Added `SupportsPresignedUploads`, which `Fs` now implements
- Added `Fs::isPresignedUploadEnabled()`, `Fs::getPresignedUploadThreshold()`, and `Fs::shouldPresignUpload()` so consumers can ask the filesystem rather than hardcoding the policy
- Added `Fs::getPresignedUpload()` for single-request uploads
- Added `Fs::beginMultipartUpload()`, `Fs::getPresignedUploadParts()`, `Fs::completeMultipartUpload()`, and `Fs::abortMultipartUpload()` for multipart uploads
- Added `Fs::getMultipartPartSize()`, which returns a uniform part size, as R2 requires of all but the final part. Sizes scale with the file to keep the part count sane — a 626 MB upload is 40 parts, not 126
- Added `Fs::getUploadedParts()`, so callers can ask the bucket which parts arrived instead of relying on the client reading `ETag` response headers
- Added `PresignedUpload`, `PresignedUploadPart`, and `MultipartUpload` models

## 1.1.1 - 2024-06-24
- Update variables/classes to avoid conflicts with S3 plugin

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
