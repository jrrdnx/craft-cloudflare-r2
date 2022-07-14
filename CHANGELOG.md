# Release Notes for Cloudflare R2

## 0.2.0-beta - 2022-07-14

### Fixes
- Added `$region` property (fixes manual bucket selection issue)
- Reverted `createAdapter()` back to using `visibility()` method (reverts object visibility to be based on `$makeUploadsPublic` property)
- Replaced instances of `Craft::parseEnv()` with `App::parseEnv()`

### Removes
- Removed Cloudfront-related logic
## 0.1.0-beta - 2022-07-13
### Initial release
