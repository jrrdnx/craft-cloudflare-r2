<?php

declare(strict_types=1);
/**
 * @link https://jarrodnix.dev/
 * @copyright Copyright (c) Jarrod D Nix
 * @license MIT
 */

namespace jrrdnx\cloudflarer2\models;

use craft\base\Model;

/**
 * A presigned request for one part of a multipart upload.
 *
 * @author Jarrod D Nix
 * @since 1.2.0
 */
class PresignedUploadPart extends Model
{
    /**
     * @var int The part number, between 1 and
     * [[\jrrdnx\cloudflarer2\SupportsPresignedUploads::MAX_MULTIPART_PARTS]]
     */
    public int $partNumber = 0;

    /**
     * @var string The presigned URL to upload this part to
     */
    public string $url = '';

    /**
     * @var string The HTTP method the client must use
     */
    public string $method = 'PUT';

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
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['partNumber', 'url', 'expiresAt'], 'required'],
            [['partNumber', 'expiresAt'], 'integer'],
        ]);
    }
}
