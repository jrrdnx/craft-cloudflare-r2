<?php

declare(strict_types=1);
/**
 * @link https://jarrodnix.dev/
 * @copyright Copyright (c) Jarrod D Nix
 * @license MIT
 */

namespace jrrdnx\cloudflarer2;

use jrrdnx\cloudflarer2\models\MultipartUpload;
use jrrdnx\cloudflarer2\models\PresignedUpload;
use jrrdnx\cloudflarer2\models\PresignedUploadPart;
use InvalidArgumentException;

/**
 * Implemented by filesystems that can hand out presigned URLs, allowing clients
 * to upload directly to the bucket without proxying the bytes through PHP.
 *
 * Consumers should type check against this interface rather than against a
 * concrete filesystem class:
 *
 * ```php
 * if ($fs instanceof SupportsPresignedUploads) {
 *     $upload = $fs->getPresignedUpload($path, $mimeType);
 * }
 * ```
 *
 * @author Jarrod D Nix
 * @since 1.2.0
 */
interface SupportsPresignedUploads
{
    /**
     * The smallest allowed multipart part size, in bytes (5 MiB).
     *
     * Every part except the final one must be at least this large.
     */
    public const MIN_MULTIPART_PART_SIZE = 5242880;

    /**
     * The largest allowed multipart part size, in bytes (5 GiB).
     */
    public const MAX_MULTIPART_PART_SIZE = 5368709120;

    /**
     * The maximum number of parts a single multipart upload may contain.
     */
    public const MAX_MULTIPART_PARTS = 10000;

    /**
     * The smallest part size to use by default, in bytes (16 MiB).
     *
     * [[MIN_MULTIPART_PART_SIZE]] is what the protocol permits, not what's
     * sensible: at 5 MiB a 600 MB upload turns into 120-odd requests.
     */
    public const DEFAULT_MULTIPART_PART_SIZE = 16777216;

    /**
     * The part size to stop growing at, in bytes (256 MiB).
     *
     * Past this, a single failed part costs more to re-upload than the extra
     * requests were saving.
     */
    public const PREFERRED_MAX_MULTIPART_PART_SIZE = 268435456;

    /**
     * How many parts a multipart upload should aim for.
     *
     * Enough to keep retries cheap and let parts upload concurrently, without
     * turning a large file into hundreds of requests.
     */
    public const TARGET_MULTIPART_PART_COUNT = 64;

    /**
     * The largest object that can be sent as a single presigned `PUT` (5 GiB).
     *
     * Anything larger has to go through a multipart upload.
     */
    public const MAX_SINGLE_UPLOAD_SIZE = 5368709120;

    /**
     * How long presigned URLs are valid for by default, in seconds.
     */
    public const DEFAULT_PRESIGNED_EXPIRES = 3600;

    /**
     * The longest a presigned URL may be valid for, in seconds (7 days).
     *
     * This is a hard limit imposed by AWS Signature V4.
     */
    public const MAX_PRESIGNED_EXPIRES = 604800;

    /**
     * Returns whether this filesystem is configured to hand out presigned uploads.
     *
     * Implementing this interface says the filesystem *can*; this says whether it
     * *should*. Callers are expected to fall back to an ordinary upload when this
     * returns `false`, so direct uploads can be turned off per bucket without any
     * code changes.
     *
     * @return bool
     * @since 1.2.0
     */
    public function isPresignedUploadEnabled(): bool;

    /**
     * Returns the size, in bytes, at which an upload is worth sending directly.
     *
     * Below this, an ordinary upload through PHP is usually the better trade:
     * one request instead of three. Zero means every upload should go direct.
     *
     * @return int
     * @since 1.2.0
     */
    public function getPresignedUploadThreshold(): int;

    /**
     * Returns whether an upload of a given size should be sent directly.
     *
     * @param int $fileSize The size of the file, in bytes
     * @return bool
     * @since 1.2.0
     */
    public function shouldPresignUpload(int $fileSize): bool;

    /**
     * Returns a presigned `PUT` for a single-request upload.
     *
     * Only the `Host` header forms part of the signature, so the client is free
     * to send whatever headers it likes without invalidating the URL. It should
     * still send the ones on the returned model: a single `PUT` stores whatever
     * `Content-Type` and `Cache-Control` arrive with the request, so omitting
     * them leaves the object with the wrong metadata.
     *
     * @param string $path The path to upload to, relative to the filesystem root
     * @param string|null $mimeType The `Content-Type` the object should be stored with
     * @param int|null $expires Seconds until the URL expires, or `null` for [[DEFAULT_PRESIGNED_EXPIRES]]
     * @return PresignedUpload
     * @throws InvalidArgumentException if `$path` is empty or `$expires` is outside the allowed range
     */
    public function getPresignedUpload(string $path, ?string $mimeType = null, ?int $expires = null): PresignedUpload;

    /**
     * Starts a multipart upload and returns a handle for it.
     *
     * Unlike a single `PUT`, the metadata here is recorded server-side when the
     * upload is created, so the client's part requests can't influence it.
     *
     * The upload occupies storage until it is either completed or aborted, so
     * callers are responsible for pairing this with [[completeMultipartUpload()]]
     * or [[abortMultipartUpload()]].
     *
     * @param string $path The path to upload to, relative to the filesystem root
     * @param string|null $mimeType The `Content-Type` to store on the finished object
     * @return MultipartUpload
     * @throws InvalidArgumentException if `$path` is empty
     */
    public function beginMultipartUpload(string $path, ?string $mimeType = null): MultipartUpload;

    /**
     * Returns presigned `PUT` URLs for the given part numbers.
     *
     * Parts may be signed all at once, or one at a time as an upload progresses.
     * Signing individually is preferable for long uploads, since a URL signed up
     * front may expire before the client reaches that part.
     *
     * @param MultipartUpload $upload The upload returned by [[beginMultipartUpload()]]
     * @param int[] $partNumbers The part numbers to sign, each between 1 and [[MAX_MULTIPART_PARTS]]
     * @param int|null $expires Seconds until the URLs expire, or `null` for [[DEFAULT_PRESIGNED_EXPIRES]]
     * @return PresignedUploadPart[] Indexed in the same order as `$partNumbers`
     * @throws InvalidArgumentException if a part number or `$expires` is out of range
     */
    public function getPresignedUploadParts(MultipartUpload $upload, array $partNumbers, ?int $expires = null): array;

    /**
     * Returns the parts that have actually landed in the bucket.
     *
     * Saves having to take the client's word for what it uploaded. It also means
     * the bucket's CORS policy doesn't need to expose `ETag`, since the browser
     * never has to read one.
     *
     * @param MultipartUpload $upload The upload returned by [[beginMultipartUpload()]]
     * @return array[] One entry per part, each with a `PartNumber`, `ETag` and `Size`,
     * ordered by part number
     * @since 1.2.0
     */
    public function getUploadedParts(MultipartUpload $upload): array;

    /**
     * Assembles the uploaded parts into a single object.
     *
     * @param MultipartUpload $upload The upload returned by [[beginMultipartUpload()]]
     * @param array[] $parts One entry per uploaded part, each with a `PartNumber` and an `ETag`
     * @throws InvalidArgumentException if `$parts` is empty or malformed
     */
    public function completeMultipartUpload(MultipartUpload $upload, array $parts): void;

    /**
     * Discards a multipart upload and any parts already uploaded to it.
     *
     * @param MultipartUpload $upload The upload returned by [[beginMultipartUpload()]]
     */
    public function abortMultipartUpload(MultipartUpload $upload): void;

    /**
     * Returns the part size to use for a multipart upload of a given size.
     *
     * The result is uniform across every part but the last, which some S3
     * implementations — R2 included — require.
     *
     * @param int $fileSize The total size of the file, in bytes
     * @return int The part size, in bytes
     * @throws InvalidArgumentException if the file is too large to upload
     */
    public function getMultipartPartSize(int $fileSize): int;
}
