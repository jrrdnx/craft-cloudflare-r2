<?php

declare(strict_types=1);
/**
 * @link https://jarrodnix.dev/
 * @copyright Copyright (c) Jarrod D Nix
 * @license MIT
 */

namespace jrrdnx\cloudflarer2;

use Aws\Credentials\Credentials;
use Aws\Rekognition\RekognitionClient;
use Craft;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\flysystem\base\FlysystemFs;
use craft\helpers\App;
use craft\helpers\ArrayHelper;
use craft\helpers\Assets;
use craft\helpers\DateTimeHelper;
use craft\helpers\StringHelper;
use DateTime;
use InvalidArgumentException;
use jrrdnx\cloudflarer2\models\MultipartUpload;
use jrrdnx\cloudflarer2\models\PresignedUpload;
use jrrdnx\cloudflarer2\models\PresignedUploadPart;
use League\Flysystem\AwsS3V3\PortableVisibilityConverter;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Visibility;

/**
 * Class Fs
 *
 * @property mixed $settingsHtml
 * @property string $rootUrl
 * @author Jarrod D Nix
 * @since 1.0
 */
class Fs extends FlysystemFs implements SupportsPresignedUploads
{
    // Constants
    // =========================================================================

    public const STORAGE_STANDARD = 'STANDARD';
    public const STORAGE_REDUCED_REDUNDANCY = 'REDUCED_REDUNDANCY';
    public const STORAGE_STANDARD_IA = 'STANDARD_IA';

    /**
     * Cache key to use for caching purposes
     */
    public const CACHE_KEY_PREFIX = 'r2.';

    /**
     * Cache duration for access token
     */
    public const CACHE_DURATION_SECONDS = 3600;

    // Static
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'Cloudflare R2';
    }

    // Properties
    // =========================================================================

    /**
     * @var string Subfolder to use
     */
    public string $subfolder = '';

    /**
     * @var string R2 account ID
     */
    public string $accountId = '';

    /**
     * @var string R2 key ID
     */
    public string $keyId = '';

    /**
     * @var string R2 key secret
     */
    public string $secret = '';

    /**
     * @var string Bucket selection mode ('choose' or 'manual')
     */
    public string $bucketSelectionMode = 'choose';

    /**
     * @var string Bucket to use
     */
    public string $bucket = '';

    /**
     * @var string Region to use
     */
    public static string $region = 'auto';

    /**
     * @var string Cache expiration period.
     */
    public string $expires = '';

    /**
     * @var bool Set ACL for Uploads
     */
    public bool $makeUploadsPublic = false;

    /**
     * @var string S3 storage class to use.
     * @deprecated in 1.1.1
     */
    public string $storageClass = '';

    /**
     * @var bool Whether the specified sub folder should be added to the root URL
     */
    public bool $addSubfolderToRootUrl = true;

    /**
     * @var bool Whether clients may upload directly to this bucket using presigned URLs
     * @since 1.2.0
     */
    public bool $presignedUploads = false;

    /**
     * @var int Files at least this large (in bytes) should be uploaded directly
     *
     * Smaller uploads are usually better off going through PHP: it's a single
     * request rather than three, and it stays inside the paths Craft already
     * handles. Zero means every upload goes direct.
     * @since 1.2.0
     */
    public int $presignedUploadThreshold = 16777216;

    /**
     * @var array A list of paths to invalidate at the end of request.
     */
    protected array $pathsToInvalidate = [];

    /**
     * @var S3Client|null Memoized client used for presigning and multipart calls.
     * @since 1.2.0
     */
    private ?S3Client $_presignClient = null;

    // Public Methods
    // =========================================================================

	/**
     * @inheritdoc
     */
    public function __construct(array $config = [])
    {
        if (isset($config['manualBucket'])) {
            if (isset($config['bucketSelectionMode']) && $config['bucketSelectionMode'] === 'manual') {
                $config['bucket'] = ArrayHelper::remove($config, 'manualBucket');
            } else {
                unset($config['manualBucket'], $config['manualRegion']);
            }
        }

        // The settings screen collects the threshold in MB, since bytes are
        // unpleasant to type. Everything past this point works in bytes.
        if (isset($config['presignedUploadThresholdMb'])) {
            $config['presignedUploadThreshold'] = (int)ArrayHelper::remove($config, 'presignedUploadThresholdMb') * 1024 * 1024;
        }

        parent::__construct($config);
    }

    /**
     * @inheritdoc
     */
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        $behaviors['parser'] = [
            'class' => EnvAttributeParserBehavior::class,
            'attributes' => [
				'accountId',
                'keyId',
                'secret',
                'bucket',
                'subfolder',
            ],
        ];
        return $behaviors;
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['bucket', 'accountId'], 'required'],
            [['presignedUploadThreshold'], 'integer', 'min' => 0],
            [['presignedUploads'], 'boolean'],
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('cloudflare-r2/fsSettings', [
            'fs' => $this,
            'periods' => array_merge(['' => ''], Assets::periodList()),
        ]);
    }

    /**
     * Get the bucket list using the specified credentials.
     *
     * @param string|null $accountId The account ID
	 * @param string|null $keyId The key ID
     * @param string|null $secret The key secret
     * @return array
     * @throws InvalidArgumentException
     */
    public static function loadBucketList(?string $accountId, ?string $keyId, ?string $secret): array
    {
        $config = self::buildConfigArray($keyId, $secret, $accountId);

        $client = static::client($config);

        $objects = $client->listBuckets();

        if (empty($objects['Buckets'])) {
            return [];
        }

        $buckets = $objects['Buckets'];
        $bucketList = [];

        foreach ($buckets as $bucket) {
            $bucketList[] = [
                'bucket' => $bucket['Name'],
                'urlPrefix' => 'https://' . $accountId . '.r2.cloudflarestorage.com/' . $bucket['Name'] . '/',
            ];
        }

        return $bucketList;
    }

    /**
     * @inheritdoc
     */
    public function getRootUrl(): ?string
    {
        $rootUrl = parent::getRootUrl();

        if ($rootUrl) {
            $rootUrl .= $this->_getRootUrlPath();
        }

        return $rootUrl;
    }

    /**
     * @inheritdoc
     * @since 1.2.0
     */
    public function isPresignedUploadEnabled(): bool
    {
        return $this->presignedUploads;
    }

    /**
     * @inheritdoc
     * @since 1.2.0
     */
    public function getPresignedUploadThreshold(): int
    {
        return max(0, $this->presignedUploadThreshold);
    }

    /**
     * @inheritdoc
     * @since 1.2.0
     */
    public function shouldPresignUpload(int $fileSize): bool
    {
        return $this->isPresignedUploadEnabled() && $fileSize >= $this->getPresignedUploadThreshold();
    }

    /**
     * @inheritdoc
     * @since 1.2.0
     */
    public function getPresignedUpload(string $path, ?string $mimeType = null, ?int $expires = null): PresignedUpload
    {
        $expires = $this->_normalizeExpires($expires);
        $key = $this->_objectKey($path);
        $headers = [];

        $args = [
            'Bucket' => $this->_bucket(),
            'Key' => $key,
        ];

        // SigV4 drops Content-Type and Cache-Control from presigned requests
        // (see SignatureV4::getHeaderBlacklist()), so these end up outside the
        // signature. Whatever the client actually sends is what gets stored,
        // which is why they're handed back on the model as well.
        if ($mimeType !== null) {
            $args['ContentType'] = $mimeType;
            $headers['Content-Type'] = $mimeType;
        }

        if (($cacheControl = $this->_cacheControl()) !== null) {
            $args['CacheControl'] = $cacheControl;
            $headers['Cache-Control'] = $cacheControl;
        }

        $client = $this->_presignClient();
        $request = $client->createPresignedRequest(
            $client->getCommand('PutObject', $args),
            '+' . $expires . ' seconds'
        );

        return new PresignedUpload([
            'url' => (string)$request->getUri(),
            'method' => 'PUT',
            'headers' => $headers,
            'path' => $key,
            'expiresAt' => time() + $expires,
        ]);
    }

    /**
     * @inheritdoc
     * @since 1.2.0
     */
    public function beginMultipartUpload(string $path, ?string $mimeType = null): MultipartUpload
    {
        $key = $this->_objectKey($path);
        $bucket = $this->_bucket();

        $args = [
            'Bucket' => $bucket,
            'Key' => $key,
        ];

        if ($mimeType !== null) {
            $args['ContentType'] = $mimeType;
        }

        if (($cacheControl = $this->_cacheControl()) !== null) {
            $args['CacheControl'] = $cacheControl;
        }

        $result = $this->_presignClient()->createMultipartUpload($args);

        return new MultipartUpload([
            'uploadId' => (string)$result['UploadId'],
            'path' => $key,
            'bucket' => $bucket,
            'mimeType' => $mimeType,
        ]);
    }

    /**
     * @inheritdoc
     * @since 1.2.0
     */
    public function getPresignedUploadParts(MultipartUpload $upload, array $partNumbers, ?int $expires = null): array
    {
        if (empty($partNumbers)) {
            throw new InvalidArgumentException('At least one part number must be given.');
        }

        $expires = $this->_normalizeExpires($expires);
        $expiresAt = time() + $expires;
        $client = $this->_presignClient();
        $parts = [];

        foreach ($partNumbers as $partNumber) {
            $partNumber = (int)$partNumber;

            if ($partNumber < 1 || $partNumber > self::MAX_MULTIPART_PARTS) {
                throw new InvalidArgumentException(
                    "Part numbers must be between 1 and " . self::MAX_MULTIPART_PARTS . ", $partNumber given."
                );
            }

            // Note: no ChecksumAlgorithm here. The AWS SDK only drops its checksum
            // middleware while presigning when none was requested, and a browser
            // can't produce the resulting x-amz-checksum-* headers anyway.
            $request = $client->createPresignedRequest(
                $client->getCommand('UploadPart', [
                    'Bucket' => $upload->bucket,
                    'Key' => $upload->path,
                    'UploadId' => $upload->uploadId,
                    'PartNumber' => $partNumber,
                ]),
                '+' . $expires . ' seconds'
            );

            $parts[] = new PresignedUploadPart([
                'partNumber' => $partNumber,
                'url' => (string)$request->getUri(),
                'method' => 'PUT',
                'expiresAt' => $expiresAt,
            ]);
        }

        return $parts;
    }

    /**
     * @inheritdoc
     * @since 1.2.0
     */
    public function getUploadedParts(MultipartUpload $upload): array
    {
        $client = $this->_presignClient();
        $parts = [];
        $marker = 0;

        do {
            $result = $client->listParts([
                'Bucket' => $upload->bucket,
                'Key' => $upload->path,
                'UploadId' => $upload->uploadId,
                'MaxParts' => 1000,
                'PartNumberMarker' => $marker,
            ]);

            foreach ($result['Parts'] ?? [] as $part) {
                $parts[] = [
                    'PartNumber' => (int)$part['PartNumber'],
                    'ETag' => (string)$part['ETag'],
                    'Size' => (int)($part['Size'] ?? 0),
                ];
            }

            $marker = $result['NextPartNumberMarker'] ?? null;
        } while (!empty($result['IsTruncated']) && $marker !== null);

        usort($parts, static fn(array $a, array $b): int => $a['PartNumber'] <=> $b['PartNumber']);

        return $parts;
    }

    /**
     * @inheritdoc
     * @since 1.2.0
     */
    public function completeMultipartUpload(MultipartUpload $upload, array $parts): void
    {
        if (empty($parts)) {
            throw new InvalidArgumentException('A multipart upload must have at least one part.');
        }

        $normalized = [];
        $seen = [];

        foreach ($parts as $part) {
            if (!is_array($part)) {
                throw new InvalidArgumentException('Each part must be an array with a PartNumber and an ETag.');
            }

            $partNumber = (int)($part['PartNumber'] ?? $part['partNumber'] ?? 0);
            $etag = trim((string)($part['ETag'] ?? $part['etag'] ?? $part['Etag'] ?? ''));

            if ($partNumber < 1 || $partNumber > self::MAX_MULTIPART_PARTS) {
                throw new InvalidArgumentException(
                    "Part numbers must be between 1 and " . self::MAX_MULTIPART_PARTS . ", $partNumber given."
                );
            }

            if ($etag === '') {
                throw new InvalidArgumentException("Part $partNumber is missing an ETag.");
            }

            if (isset($seen[$partNumber])) {
                throw new InvalidArgumentException("Part $partNumber was given more than once.");
            }

            $seen[$partNumber] = true;
            $normalized[] = [
                'PartNumber' => $partNumber,
                'ETag' => $etag,
            ];
        }

        // R2 rejects a parts list that isn't in ascending part-number order.
        usort($normalized, static fn(array $a, array $b): int => $a['PartNumber'] <=> $b['PartNumber']);

        $this->_presignClient()->completeMultipartUpload([
            'Bucket' => $upload->bucket,
            'Key' => $upload->path,
            'UploadId' => $upload->uploadId,
            'MultipartUpload' => [
                'Parts' => $normalized,
            ],
        ]);

        $this->invalidateCdnPath($upload->path);
    }

    /**
     * @inheritdoc
     * @since 1.2.0
     */
    public function abortMultipartUpload(MultipartUpload $upload): void
    {
        $this->_presignClient()->abortMultipartUpload([
            'Bucket' => $upload->bucket,
            'Key' => $upload->path,
            'UploadId' => $upload->uploadId,
        ]);
    }

    /**
     * @inheritdoc
     * @since 1.2.0
     */
    public function getMultipartPartSize(int $fileSize): int
    {
        if ($fileSize < 1) {
            throw new InvalidArgumentException("File size must be a positive integer, $fileSize given.");
        }

        // Doubling keeps every part uniform, which R2 requires of all but the
        // final one, and keeps the sizes tidy powers of two.
        $partSize = self::DEFAULT_MULTIPART_PART_SIZE;

        // Grow towards the target part count, but stop at the preferred ceiling
        // so one flaky part never costs an enormous re-upload.
        while (
            $partSize < self::PREFERRED_MAX_MULTIPART_PART_SIZE &&
            (int)ceil($fileSize / $partSize) > self::TARGET_MULTIPART_PART_COUNT
        ) {
            $partSize *= 2;
        }

        // Past the ceiling only the hard 10,000-part limit matters.
        while ((int)ceil($fileSize / $partSize) > self::MAX_MULTIPART_PARTS) {
            $partSize *= 2;

            if ($partSize > self::MAX_MULTIPART_PART_SIZE) {
                throw new InvalidArgumentException("File size $fileSize is too large to upload.");
            }
        }

        return $partSize;
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @return FilesystemAdapter
     */
    protected function createAdapter(): FilesystemAdapter
    {
        $client = static::client($this->_getConfigArray(), $this->_getCredentials());
        return new CloudflareR2Adapter($client, App::parseEnv($this->bucket), $this->_subfolder(), new PortableVisibilityConverter($this->visibility()), null, [], false);
    }

    /**
     * Get the Amazon S3 client.
     *
     * @param array $config client config
     * @param array $credentials credentials to use when generating a new token
     * @return S3Client
     */
    protected static function client(array $config = [], array $credentials = []): S3Client
    {
        if (!empty($config['credentials']) && $config['credentials'] instanceof Credentials) {
            $config['generateNewConfig'] = static function() use ($credentials) {
                $args = [
                    $credentials['keyId'],
                    $credentials['secret'],
                    $credentials['accountId'],
                    true,
                ];
                return call_user_func_array(self::class . '::buildConfigArray', $args);
            };
        }

        return new S3Client($config);
    }

    /**
     * @inheritdoc
     */
    protected function addFileMetadataToConfig(array $config): array
    {
        if (($cacheControl = $this->_cacheControl()) !== null) {
            $config['CacheControl'] = $cacheControl;
        }

        return parent::addFileMetadataToConfig($config);
    }

    /**
     * @inheritdoc
     */
    protected function invalidateCdnPath(string $path): bool
    {
        return true;
    }

    /**
     * Purge any queued paths from the CDN.
     */
    public function purgeQueuedPaths(): void
    {
        return;
    }

    /**
     * Attempt to detect focal point for a path on the bucket and return the
     * focal point position as an array of decimal parts
     *
     * @param string $filePath
     * @return array
     */
    public function detectFocalPoint(string $filePath): array
    {
        $extension = StringHelper::toLowerCase(pathinfo($filePath, PATHINFO_EXTENSION));

        if (!in_array($extension, ['jpeg', 'jpg', 'png'])) {
            return [];
        }


        $client = new RekognitionClient($this->_getConfigArray());
        $params = [
            'Image' => [
                'S3Object' => [
                    'Name' => App::parseEnv($filePath),
                    'Bucket' => App::parseEnv($this->bucket),
                ],
            ],
        ];

        $faceData = $client->detectFaces($params);

        if (!empty($faceData['FaceDetails'])) {
            $face = array_shift($faceData['FaceDetails']);
            if ($face['Confidence'] > 80) {
                $box = $face['BoundingBox'];
                return [
                    number_format($box['Left'] + ($box['Width'] / 2), 4),
                    number_format($box['Top'] + ($box['Height'] / 2), 4),
                ];
            }
        }

        return [];
    }

    /**
     * Build the config array based on a keyID and secret
     *
     * @param ?string $keyId The key ID
     * @param ?string $secret The key secret
     * @param ?string $accountId The account id
     * @param bool $refreshToken If true will always refresh token
     * @return array
     */
    public static function buildConfigArray(?string $keyId = null, ?string $secret = null, ?string $accountId = null, bool $refreshToken = false): array
    {
		$config = [
            'region' => self::$region,
			'endpoint' => 'https://'.$accountId.'.r2.cloudflarestorage.com',
            'version' => 'latest',
			'credentials' => new Credentials($keyId, $secret)
        ];

        return $config;
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the client used for presigning and multipart calls.
     *
     * @return S3Client
     * @since 1.2.0
     */
    private function _presignClient(): S3Client
    {
        if ($this->_presignClient === null) {
            $this->_presignClient = static::client($this->_getConfigArray(), $this->_getCredentials());
        }

        return $this->_presignClient;
    }

    /**
     * Returns the parsed bucket name
     *
     * @return string
     * @since 1.2.0
     */
    private function _bucket(): string
    {
        return (string)App::parseEnv($this->bucket);
    }

    /**
     * Turns a filesystem-relative path into a full object key.
     *
     * Flysystem applies the subfolder prefix for us on ordinary reads and writes,
     * but a presigned URL bypasses Flysystem entirely, so it has to be applied here.
     *
     * @param string $path
     * @return string
     * @throws InvalidArgumentException if the path is empty
     * @since 1.2.0
     */
    private function _objectKey(string $path): string
    {
        $path = ltrim($path, '/');

        if ($path === '') {
            throw new InvalidArgumentException('A path is required.');
        }

        return $this->_subfolder() . $path;
    }

    /**
     * Returns the `Cache-Control` value implied by the `expires` setting.
     *
     * @return string|null Null if no valid cache duration is configured
     * @since 1.2.0
     */
    private function _cacheControl(): ?string
    {
        if (empty($this->expires) || !DateTimeHelper::isValidIntervalString($this->expires)) {
            return null;
        }

        $expires = new DateTime();
        $now = new DateTime();
        $expires->modify('+' . $this->expires);
        $diff = (int)$expires->format('U') - (int)$now->format('U');

        return 'max-age=' . $diff;
    }

    /**
     * Validates a requested presigned URL lifetime.
     *
     * @param int|null $expires Seconds, or null for the default
     * @return int
     * @throws InvalidArgumentException if the value is outside what SigV4 allows
     * @since 1.2.0
     */
    private function _normalizeExpires(?int $expires): int
    {
        $expires ??= self::DEFAULT_PRESIGNED_EXPIRES;

        if ($expires < 1 || $expires > self::MAX_PRESIGNED_EXPIRES) {
            throw new InvalidArgumentException(
                'Presigned URLs must expire between 1 and ' . self::MAX_PRESIGNED_EXPIRES . " seconds from now, $expires given."
            );
        }

        return $expires;
    }

    /**
     * Returns the parsed subfolder path
     *
     * @return string
     */
    private function _subfolder(): string
    {
        if ($this->subfolder && ($subfolder = rtrim(App::parseEnv($this->subfolder), '/')) !== '') {
            return $subfolder . '/';
        }

        return '';
    }

    /**
     * Returns the root path for URLs
     *
     * @return string
     */
    private function _getRootUrlPath(): string
    {
        if ($this->addSubfolderToRootUrl) {
            return $this->_subfolder();
        }
        return '';
    }

    /**
     * Get the config array for AWS Clients.
     *
     * @return array
     */
    private function _getConfigArray(): array
    {
        $credentials = $this->_getCredentials();

        return self::buildConfigArray($credentials['keyId'], $credentials['secret'], $credentials['accountId']);
    }

    /**
     * Return the credentials as an array
     *
     * @return array
     */
    private function _getCredentials(): array
    {
        return [
            'keyId' => App::parseEnv($this->keyId),
            'secret' => App::parseEnv($this->secret),
            'accountId' => App::parseEnv($this->accountId),
        ];
    }

    /**
     * Returns the visibility setting for the Fs.
     *
     * @return string
     */
    protected function visibility(): string
    {
        return $this->makeUploadsPublic ? Visibility::PUBLIC : Visibility::PRIVATE;
    }
}
