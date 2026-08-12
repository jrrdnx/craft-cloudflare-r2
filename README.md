# Cloudflare R2

[Cloudflare R2](https://www.cloudflare.com/products/r2/) filesystem for Craft CMS

## Requirements

This plugin requires Craft CMS ^4.0.0-beta.1 and PHP ^8.0.2

## Installation

To install the plugin, follow these instructions.

1. Open your terminal and go to your Craft project:

        cd /path/to/project

2. Then tell Composer to load the plugin:

        composer require jrrdnx/craft-cloudflare-r2

3. Navigate to Settings -> Plugins and click the "Install" button for Cloudflare R2.

## Configuring filesystem

1. Navigate to Settings -> Filesystems and click the "New Filesystem" button.
2. Select "Cloudflare R2" from the "Filesystem Type" dropdown.
3. Enter your Account ID, Access Key ID, and Secret Access Key (it's recommended to store these in your `.env` file and reference the environment variables here).
4. Hit Refresh to load the bucket list, or choose the Manual option and enter the bucket name (again, you can store this in your `.env` file and reference the environment variable).
5. Optionally add a Subfolder, determine whether or not to add the Subfolder to the Base URL, and set the Cache Control duration.

### Making objects public (optional)

See [Create Public Buckets on R2](https://developers.cloudflare.com/r2/data-access/public-buckets/) for details on adding a custom domain or managing public buckets through r2.dev, or [Use R2 from Workers](https://developers.cloudflare.com/r2/data-access/workers-api/workers-api-usage/) for more fine-tuned access control. Also feel free to reference a full guide to [Configuring a Cloudflare R2 Bucket and Worker for Public Access](https://jarrodnix.dev/blog/configuring-a-cloudflare-r2-bucket-and-worker-for-public-access).

## Presigned uploads

Large uploads are awkward to push through Craft: they have to clear `post_max_size` and `upload_max_filesize`, survive `max_execution_time`, and then get streamed a second time from PHP up to the bucket. Presigned URLs skip all of that by letting the browser send the bytes directly to R2. PHP only ever handles the signature.

This is off by default. Turn on **Use Presigned URLs** in the filesystem's settings, and set the **Presigned Upload Threshold** below which uploads should keep going through PHP — small files are usually better off that way, since it's one request instead of three. Set the threshold to 0 to use presigned URLs for everything.

Both paths stay available; the threshold decides which one a given file takes, per filesystem.

The filesystem exposes all of this through the `SupportsPresignedUploads` interface. Check the capability rather than a concrete class, and let the filesystem decide the policy rather than hardcoding it:

```php
use jrrdnx\cloudflarer2\SupportsPresignedUploads;

$fs = $volume->getFs();

if (!$fs instanceof SupportsPresignedUploads || !$fs->shouldPresignUpload($fileSize)) {
    // Fall back to an ordinary upload.
    return;
}
```

Keeping that check server-side matters: the browser can't be trusted to know which filesystem it's uploading to, and settings can differ from one bucket to the next.

### Single-request uploads

Good up to 5 GiB (`SupportsPresignedUploads::MAX_SINGLE_UPLOAD_SIZE`). Simple, but a dropped connection means starting over.

```php
$upload = $fs->getPresignedUpload('videos/trailer.mp4', 'video/mp4', 900);

return $this->asJson([
    'url' => $upload->url,
    'method' => $upload->method,
    'headers' => $upload->headers,
    'expiresAt' => $upload->expiresAt,
]);
```

```js
await fetch(url, { method, headers, body: file });
```

### Multipart uploads

Worth the extra round trips for anything large, since parts upload in parallel and can be retried individually.

```php
$partSize = $fs->getMultipartPartSize($fileSize);
$partCount = (int)ceil($fileSize / $partSize);

$upload = $fs->beginMultipartUpload('videos/trailer.mp4', 'video/mp4');
$parts = $fs->getPresignedUploadParts($upload, range(1, $partCount), 3600);
```

Persist `$upload` between requests — the client will need to hand it back. Parts can also be signed one at a time as the upload progresses, which is the better choice for uploads long enough that a batch of URLs might expire mid-flight.

Collect each part's `ETag` from its response header, then finish up:

```php
$fs->completeMultipartUpload($upload, [
    ['PartNumber' => 1, 'ETag' => '"d41d8cd98f00b204e9800998ecf8427e"'],
    ['PartNumber' => 2, 'ETag' => '"1b2m2y8asgtpgamy6yhcqw"'],
]);
```

Parts are sorted for you, but every part must be present and every `ETag` non-empty. If the upload is abandoned, call `abortMultipartUpload()` — uploaded parts are billed as storage until the upload is either completed or aborted.

### Headers and stored metadata

Only the `Host` header is covered by the signature. SigV4 strips `Content-Type` and `Cache-Control` from presigned requests, so a client sending the wrong headers gets an object with the wrong metadata rather than a `SignatureDoesNotMatch`. That means:

- **Single uploads** store whatever `Content-Type` and `Cache-Control` the client sends, which is why `getPresignedUpload()` hands them back on `$upload->headers`. Send them.
- **Multipart uploads** record their metadata server-side when the upload is created, so the client's part requests can't affect it.

Don't set `ChecksumAlgorithm` on a command you intend to presign. The AWS SDK only drops its checksum middleware when no algorithm was requested, and a browser can't produce the resulting `x-amz-checksum-*` headers.

### Bucket CORS

Direct uploads are cross-origin, so the bucket needs a CORS policy before any of this works. `ETag` must be exposed or multipart uploads can't be completed.

```json
[
  {
    "AllowedOrigins": ["https://example.com"],
    "AllowedMethods": ["PUT"],
    "AllowedHeaders": ["content-type", "cache-control"],
    "ExposeHeaders": ["ETag"],
    "MaxAgeSeconds": 3600
  }
]
```

### Creating the asset

Once the object is in the bucket, index it rather than setting `tempFilePath` on a new `Asset` — that would make Craft download the file and upload it right back again.

```php
$indexer = Craft::$app->getAssetIndexer();
$session = $indexer->createIndexingSession([$volume]);
$asset = $indexer->indexFile($volume, 'videos/trailer.mp4', $session->id);
```

Mind the two path flavours: `getPresignedUpload()` takes a path relative to the *filesystem* and returns the full object key on `$upload->path` (including the filesystem's Subfolder), whereas `indexFile()` wants a path relative to the *volume*.

### Replacing a file

Replacing works the same way, and needs no extra wiring — Craft's replace flows already pass the asset's ID through, so the upload is presigned straight onto that asset's path.

Overwriting in place is safe. S3 and R2 only swap an object once the whole thing has arrived, and a multipart upload doesn't materialize until it's completed, so a cancelled or failed replacement leaves the original exactly as it was. Once the object lands, the asset's filename, kind, size, modified date and dimensions are refreshed, its transforms are purged, and the `beforeReplaceFile` / `afterReplaceFile` events fire as usual.

Image dimensions are read from the leading bytes of the object rather than by downloading it.

### What still goes through PHP

Assets fields with a selection condition are left to the normal uploader, since the target folder isn't known until the file has been vetted. This falls back automatically; there's nothing to configure.

### Security

Presigning delegates write access, so the caller is responsible for the parts the filesystem can't see:

- **Authorize the path.** Derive it from the target volume and folder. A client that can influence it can write anywhere in the bucket.
- **Re-authorize on the way back.** A `MultipartUpload` returned by the client is untrusted input; check it against something you stored rather than taking it at face value.
- **There's no size ceiling.** A presigned `PUT` can't enforce one. Verify the object's size after upload and delete it if it's out of bounds.
- **Keep expiry short.** The default is an hour; SigV4 permits up to seven days, which is rarely what you want.

## Misc

[Open an Issue](https://github.com/jrrdnx/craft-cloudflare-r2/issues)

Brought to you by [Jarrod D Nix](https://jarrodnix.dev)