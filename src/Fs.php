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
use CraftCms\Cms\Filesystem\Filesystems\Filesystem;
use CraftCms\Cms\Support\Env;
use CraftCms\Cms\Twig\TemplateRenderer;
use CraftCms\Cms\View\TemplateMode;
use DateTime;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Class Fs
 *
 * @property mixed $settingsHtml
 * @property string $rootUrl
 * @author Jarrod D Nix
 * @since 1.0
 */
class Fs extends Filesystem
{
    // Constants
    // =========================================================================

    public const STORAGE_STANDARD = 'STANDARD';
    public const STORAGE_REDUCED_REDUNDANCY = 'REDUCED_REDUNDANCY';
    public const STORAGE_STANDARD_IA = 'STANDARD_IA';

    public const CACHE_KEY_PREFIX = 'r2.';
    public const CACHE_DURATION_SECONDS = 3600;

    // Static
    // =========================================================================

    public static function displayName(): string
    {
        return 'Cloudflare R2';
    }

    // Properties
    // =========================================================================

    /** @var string Subfolder to use */
    public string $subfolder = '';

    /** @var string R2 account ID */
    public string $accountId = '';

    /** @var string R2 key ID */
    public string $keyId = '';

    /** @var string R2 key secret */
    public string $secret = '';

    /** @var string Bucket selection mode ('choose' or 'manual') */
    public string $bucketSelectionMode = 'choose';

    /** @var string|null Bucket from the dropdown (choose mode) */
    public ?string $bucket = null;

    /**
     * @var string|null Bucket entered manually (manual mode).
     * Stored separately so Craft 6's post-construction property injection
     * cannot overwrite whichever field the user actually filled in.
     */
    public ?string $manualBucket = null;

    /** @var string Region to use (always 'auto' for R2) */
    public string $region = 'auto';

    /** @var string Cache expiration period */
    public string $expires = '';

    /** @var bool Set ACL for uploads */
    public bool $makeUploadsPublic = false;

    /**
     * @var string S3 storage class to use.
     * @deprecated in 1.1.1
     */
    public string $storageClass = '';

    /** @var bool Whether the specified subfolder should be added to the root URL */
    public bool $addSubfolderToRootUrl = true;

    // Public Methods
    // =========================================================================

    public function getRules(): array
    {
        return array_merge(parent::getRules(), [
            'accountId' => ['required', 'string'],
            'bucket' => [Rule::requiredIf(fn() => $this->bucketSelectionMode !== 'manual')],
            'manualBucket' => [Rule::requiredIf(fn() => $this->bucketSelectionMode === 'manual')],
        ]);
    }

    public function getSettingsHtml(): ?string
    {
        return app(TemplateRenderer::class)->renderTemplate('cloudflare-r2/fsSettings', [
            'fs' => $this,
            'periods' => array_merge(['' => ''], self::_periodList()),
        ], TemplateMode::Cp);
    }

    /**
     * @inheritdoc
     */
    public function getDiskConfig(): array
    {
        $config = [
            'driver' => 'r2',
            'key' => Env::parse($this->keyId),
            'secret' => Env::parse($this->secret),
            'bucket' => Env::parse($this->_effectiveBucket()),
            'endpoint' => 'https://' . Env::parse($this->accountId) . '.r2.cloudflarestorage.com',
            'region' => $this->region,
            'url' => $this->getRootUrl(),
        ];

        $subfolder = $this->_subfolder();
        if ($subfolder !== '') {
            $config['prefix'] = $subfolder;
        }

        if (!empty($this->expires)) {
            $seconds = self::_expiresInSeconds($this->expires);
            if ($seconds > 0) {
                $config['options']['CacheControl'] = 'max-age=' . $seconds;
            }
        }

        return $config;
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

        $bucketList = [];
        foreach ($objects['Buckets'] as $bucket) {
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

        if ($rootUrl && $this->addSubfolderToRootUrl) {
            $rootUrl .= $this->_subfolder();
        }

        return $rootUrl;
    }

    /**
     * Attempt to detect focal point for a path on the bucket and return the
     * focal point position as an array of decimal parts.
     *
     * @param string $filePath
     * @return array
     */
    public function detectFocalPoint(string $filePath): array
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if (!in_array($extension, ['jpeg', 'jpg', 'png'], true)) {
            return [];
        }

        $client = new RekognitionClient($this->_getConfigArray());
        $params = [
            'Image' => [
                'S3Object' => [
                    'Name' => Env::parse($filePath),
                    'Bucket' => Env::parse($this->_effectiveBucket()),
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
     * Build the config array based on a keyId and secret.
     *
     * @param string|null $keyId The key ID
     * @param string|null $secret The key secret
     * @param string|null $accountId The account ID
     * @param bool $refreshToken If true will always refresh token
     * @return array
     */
    public static function buildConfigArray(
        ?string $keyId = null,
        ?string $secret = null,
        ?string $accountId = null,
        bool $refreshToken = false,
    ): array {
        return [
            'region' => 'auto',
            'endpoint' => 'https://' . $accountId . '.r2.cloudflarestorage.com',
            'version' => 'latest',
            'credentials' => new Credentials($keyId, $secret),
        ];
    }

    // Protected Methods
    // =========================================================================

    /**
     * Get the S3 client.
     *
     * @param array $config client config
     * @param array $credentials credentials for generating a new token
     * @return S3Client
     */
    protected static function client(array $config = [], array $credentials = []): S3Client
    {
        if (!empty($config['credentials']) && $config['credentials'] instanceof Credentials) {
            $config['generateNewConfig'] = static function() use ($credentials) {
                return call_user_func_array(
                    self::class . '::buildConfigArray',
                    [$credentials['keyId'], $credentials['secret'], $credentials['accountId'], true],
                );
            };
        }

        return new S3Client($config);
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the active bucket name based on the current selection mode.
     * Reads from $manualBucket in manual mode, $bucket in choose mode.
     */
    private function _effectiveBucket(): string
    {
        if ($this->bucketSelectionMode === 'manual') {
            return $this->manualBucket ?? '';
        }

        return $this->bucket ?? '';
    }

    private function _subfolder(): string
    {
        if ($this->subfolder && ($subfolder = rtrim(Env::parse($this->subfolder), '/')) !== '') {
            return $subfolder . '/';
        }

        return '';
    }

    private function _getConfigArray(): array
    {
        $credentials = $this->_getCredentials();
        return self::buildConfigArray($credentials['keyId'], $credentials['secret'], $credentials['accountId']);
    }

    private function _getCredentials(): array
    {
        return [
            'keyId' => Env::parse($this->keyId),
            'secret' => Env::parse($this->secret),
            'accountId' => Env::parse($this->accountId),
        ];
    }

    private static function _periodList(): array
    {
        return [
            'seconds' => 'Seconds',
            'minutes' => 'Minutes',
            'hours' => 'Hours',
            'days' => 'Days',
            'weeks' => 'Weeks',
            'months' => 'Months',
            'years' => 'Years',
        ];
    }

    private static function _expiresInSeconds(string $expires): int
    {
        if (empty(trim($expires))) {
            return 0;
        }

        try {
            $now = new DateTime();
            $future = (clone $now)->modify('+' . $expires);
            if ($future === false) {
                return 0;
            }
            return max(0, $future->getTimestamp() - $now->getTimestamp());
        } catch (\Throwable) {
            return 0;
        }
    }
}
