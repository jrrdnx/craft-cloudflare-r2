<?php

declare(strict_types=1);
/**
 * @link https://jarrodnix.dev/
 * @copyright Copyright (c) Jarrod D Nix
 * @license MIT
 */

namespace jrrdnx\cloudflarer2\models;

use craft\base\Model;
use DateTime;
use DateTimeZone;

/**
 * A presigned request the client can use to upload a single object.
 *
 * @author Jarrod D Nix
 * @since 1.2.0
 */
class PresignedUpload extends Model
{
    /**
     * @var string The presigned URL to upload to
     */
    public string $url = '';

    /**
     * @var string The HTTP method the client must use
     */
    public string $method = 'PUT';

    /**
     * @var array The headers the client should send along with the upload
     *
     * These sit outside the signature — SigV4 strips `Content-Type` and
     * `Cache-Control` from presigned requests — so getting them wrong won't
     * break the upload. It will, however, leave the object stored with the
     * wrong metadata, since a single `PUT` records whatever the client sends.
     */
    public array $headers = [];

    /**
     * @var string The full object key the upload will land at, including any
     * filesystem subfolder
     */
    public string $path = '';

    /**
     * @var int Unix timestamp of when the URL stops working
     */
    public int $expiresAt = 0;

    /**
     * Returns how many seconds the URL has left before it expires.
     *
     * @return int Zero once the URL has expired
     */
    public function getExpiresIn(): int
    {
        return max(0, $this->expiresAt - time());
    }

    /**
     * Returns the expiry as a `DateTime`.
     *
     * @return DateTime
     */
    public function getExpiryDate(): DateTime
    {
        return (new DateTime('@' . $this->expiresAt))->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['url', 'method', 'path', 'expiresAt'], 'required'],
            [['expiresAt'], 'integer'],
        ]);
    }
}
