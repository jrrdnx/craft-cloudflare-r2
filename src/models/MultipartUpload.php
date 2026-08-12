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
 * A handle for an in-progress multipart upload.
 *
 * This is the state that has to survive between the request that starts an
 * upload and the requests that sign its parts and complete it. It is safe to
 * serialize, but it is not safe to trust: a client that hands one of these back
 * can name any key in the bucket, so callers must re-derive or re-authorize
 * [[$path]] rather than taking the client's word for it.
 *
 * @author Jarrod D Nix
 * @since 1.2.0
 */
class MultipartUpload extends Model
{
    /**
     * @var string The upload ID issued by the storage provider
     */
    public string $uploadId = '';

    /**
     * @var string The full object key being uploaded to, including any
     * filesystem subfolder
     */
    public string $path = '';

    /**
     * @var string The bucket the upload belongs to
     */
    public string $bucket = '';

    /**
     * @var string|null The `Content-Type` the finished object will be stored with
     */
    public ?string $mimeType = null;

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['uploadId', 'path', 'bucket'], 'required'],
        ]);
    }
}
