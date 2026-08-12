<?php

namespace jrrdnx\cloudflarer2\controllers;

use Craft;
use craft\elements\Asset;
use craft\fields\Assets as AssetsField;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\Json;
use craft\models\Volume;
use craft\models\VolumeFolder;
use craft\web\Controller as BaseController;
use jrrdnx\cloudflarer2\models\MultipartUpload;
use jrrdnx\cloudflarer2\SupportsPresignedUploads;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Hands out presigned URLs so the browser can upload straight to the bucket,
 * then indexes the finished object as an asset.
 *
 * Nothing here trusts the client with a path. The target is resolved from the
 * folder or field the upload was started against, then sealed into an HMAC'd
 * token that has to come back untouched before anything is written or indexed.
 *
 * @author Jarrod D Nix
 * @since 1.2.0
 */
class PresignedUploadController extends BaseController
{
    /**
     * How long an upload has to finish before its token stops being accepted.
     */
    private const TOKEN_TTL = 21600;

    /**
     * How long the presigned URLs themselves stay valid.
     */
    private const PRESIGN_TTL = 10800;

    /**
     * Uploads at or above this size are sent as multipart, so they can be
     * retried a part at a time instead of starting over.
     */
    private const MULTIPART_THRESHOLD = 104857600;

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        return parent::beforeAction($action);
    }

    /**
     * Resolves the upload target and returns the presigned URLs for it.
     *
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionStart(): Response
    {
        $filename = (string)$this->request->getRequiredBodyParam('filename');
        $size = (int)$this->request->getRequiredBodyParam('size');
        $mimeType = $this->request->getBodyParam('mimeType') ?: null;

        if ($size < 1) {
            throw new BadRequestHttpException('A positive file size is required.');
        }

        $folder = $this->_resolveFolder();
        $volume = $folder->getVolume();
        $fs = $volume->getFs();

        // Whether an upload goes direct is the filesystem's call, not the
        // client's. Anything it declines falls back to a normal upload.
        if (!$fs instanceof SupportsPresignedUploads || !$fs->shouldPresignUpload($size)) {
            return $this->asJson(['mode' => 'traditional']);
        }

        $this->requirePermission("saveAssets:$volume->uid");

        $filename = AssetsHelper::prepareAssetName($filename);
        $this->_requireAllowedExtension($filename);

        if ($size > SupportsPresignedUploads::MAX_MULTIPART_PART_SIZE * SupportsPresignedUploads::MAX_MULTIPART_PARTS) {
            throw new BadRequestHttpException('That file is too large to upload.');
        }

        // Resolve the name up front so the presigned URL and the eventual index
        // agree on where the object lives.
        $volumePath = ($folder->path ? rtrim($folder->path, '/') . '/' : '') . $filename;

        if ($volume->fileExists($volumePath)) {
            $filename = Craft::$app->getAssets()->getNameReplacementInFolder($filename, $folder->id);
            $volumePath = ($folder->path ? rtrim($folder->path, '/') . '/' : '') . $filename;
        }

        $fsPath = $volume->getSubpath() . $volumePath;

        $state = [
            'userId' => (int)Craft::$app->getUser()->getId(),
            'volumeId' => (int)$volume->id,
            'folderId' => (int)$folder->id,
            'filename' => $filename,
            'volumePath' => $volumePath,
            'size' => $size,
            'expiresAt' => time() + self::TOKEN_TTL,
        ];

        if ($size < self::MULTIPART_THRESHOLD) {
            $upload = $fs->getPresignedUpload($fsPath, $mimeType, self::PRESIGN_TTL);

            return $this->asJson([
                'mode' => 'single',
                'token' => $this->_token($state),
                'filename' => $filename,
                'url' => $upload->url,
                'method' => $upload->method,
                'headers' => $upload->headers,
            ]);
        }

        $partSize = $fs->getMultipartPartSize($size);
        $partCount = (int)ceil($size / $partSize);

        $upload = $fs->beginMultipartUpload($fsPath, $mimeType);

        // Everything needed to finish or abort the upload later. It's sealed
        // into the token rather than stored, so there's no state to clean up if
        // the client walks away mid-upload.
        $state['uploadId'] = $upload->uploadId;
        $state['bucket'] = $upload->bucket;
        $state['fsPath'] = $upload->path;
        $state['partCount'] = $partCount;

        $parts = [];

        foreach ($fs->getPresignedUploadParts($upload, range(1, $partCount), self::PRESIGN_TTL) as $part) {
            $parts[] = [
                'partNumber' => $part->partNumber,
                'url' => $part->url,
            ];
        }

        return $this->asJson([
            'mode' => 'multipart',
            'token' => $this->_token($state),
            'filename' => $filename,
            'partSize' => $partSize,
            'parts' => $parts,
        ]);
    }

    /**
     * Finishes the upload and indexes the object as an asset.
     *
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionComplete(): Response
    {
        $state = $this->_validateToken();
        $volume = Craft::$app->getVolumes()->getVolumeById($state['volumeId']);

        if (!$volume) {
            throw new BadRequestHttpException('The target volume no longer exists.');
        }

        $this->requirePermission("saveAssets:$volume->uid");

        if (isset($state['uploadId'])) {
            /** @var SupportsPresignedUploads $fs */
            $fs = $volume->getFs();
            $upload = $this->_multipartUpload($state);

            $fs->completeMultipartUpload($upload, $this->_resolveParts($fs, $upload, $state));
        }

        // The client reports success; the bucket is what decides it.
        if (!$volume->fileExists($state['volumePath'])) {
            throw new BadRequestHttpException('The upload didn’t make it to the bucket.');
        }

        $actualSize = $volume->getFileSize($state['volumePath']);

        if ($actualSize !== $state['size']) {
            $volume->deleteFile($state['volumePath']);

            throw new BadRequestHttpException(
                "The uploaded file is {$actualSize} bytes, but {$state['size']} bytes were expected."
            );
        }

        $asset = $this->_indexAsset($volume, $state);

        return $this->asJson([
            'assetId' => $asset->id,
            'filename' => $asset->getFilename(),
            'url' => $asset->getUrl(),
        ]);
    }

    /**
     * Discards an abandoned multipart upload, so its parts stop costing storage.
     *
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionAbort(): Response
    {
        $state = $this->_validateToken();

        if (!isset($state['uploadId'])) {
            return $this->asJson(['aborted' => false]);
        }

        $volume = Craft::$app->getVolumes()->getVolumeById($state['volumeId']);

        if (!$volume) {
            return $this->asJson(['aborted' => false]);
        }

        /** @var SupportsPresignedUploads $fs */
        $fs = $volume->getFs();

        try {
            $fs->abortMultipartUpload($this->_multipartUpload($state));
        } catch (Throwable $e) {
            // Already gone, or never existed. Either way there's nothing to do.
            Craft::warning("Couldn’t abort multipart upload: {$e->getMessage()}", __METHOD__);

            return $this->asJson(['aborted' => false]);
        }

        return $this->asJson(['aborted' => true]);
    }

    /**
     * Works out which folder an upload is destined for.
     *
     * Mirrors how craft\controllers\AssetsController::actionUpload() resolves a
     * target, so field-driven uploads land where Craft would have put them.
     *
     * @return VolumeFolder
     * @throws BadRequestHttpException
     */
    private function _resolveFolder(): VolumeFolder
    {
        $folderId = (int)$this->request->getBodyParam('folderId') ?: null;
        $fieldId = (int)$this->request->getBodyParam('fieldId') ?: null;

        if (!$folderId && !$fieldId) {
            throw new BadRequestHttpException('No target destination provided for uploading.');
        }

        if (!$folderId) {
            $field = Craft::$app->getFields()->getFieldById($fieldId);

            if (!$field instanceof AssetsField) {
                throw new BadRequestHttpException('The field provided is not an Assets field.');
            }

            if ($field->getSelectionCondition() !== null) {
                throw new BadRequestHttpException('Fields with a selection condition don’t support presigned uploads.');
            }

            $element = null;

            if ($elementId = $this->request->getBodyParam('elementId')) {
                $siteId = $this->request->getBodyParam('siteId') ?: null;
                $element = Craft::$app->getElements()->getElementById((int)$elementId, null, $siteId);
            }

            $folderId = $field->resolveDynamicPathToFolderId($element);
        }

        if (!$folderId) {
            throw new BadRequestHttpException('The target destination provided for uploading is not valid.');
        }

        $folder = Craft::$app->getAssets()->findFolder(['id' => $folderId]);

        if (!$folder) {
            throw new BadRequestHttpException('The target folder provided for uploading is not valid.');
        }

        return $folder;
    }

    /**
     * Rejects extensions Craft wouldn't accept through a normal upload.
     *
     * @param string $filename
     * @throws BadRequestHttpException
     */
    private function _requireAllowedExtension(string $filename): void
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowed = array_map('strtolower', Craft::$app->getConfig()->getGeneral()->allowedFileExtensions);

        if ($extension === '' || !in_array($extension, $allowed, true)) {
            throw new BadRequestHttpException("Uploading “{$extension}” files isn’t allowed.");
        }
    }

    /**
     * Seals the resolved upload target so the client can't alter it.
     *
     * @param array $state
     * @return string
     */
    private function _token(array $state): string
    {
        return Craft::$app->getSecurity()->hashData(Json::encode($state));
    }

    /**
     * Unseals and sanity checks the token the client sent back.
     *
     * @return array
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     */
    private function _validateToken(): array
    {
        $token = (string)$this->request->getRequiredBodyParam('token');
        $data = Craft::$app->getSecurity()->validateData($token);

        if ($data === false) {
            throw new BadRequestHttpException('Invalid upload token.');
        }

        $state = Json::decodeIfJson($data);

        if (!is_array($state) || !isset($state['volumeId'], $state['volumePath'], $state['expiresAt'])) {
            throw new BadRequestHttpException('Malformed upload token.');
        }

        if ($state['expiresAt'] < time()) {
            throw new BadRequestHttpException('This upload token has expired.');
        }

        // A valid token still belongs to whoever started the upload.
        if ((int)$state['userId'] !== (int)Craft::$app->getUser()->getId()) {
            throw new ForbiddenHttpException('This upload belongs to a different user.');
        }

        return $state;
    }

    /**
     * Works out the parts list to finish a multipart upload with.
     *
     * Asking the bucket is both safer and less fragile than trusting the client:
     * the browser can only read an `ETag` if the bucket's CORS policy exposes it,
     * and a client could otherwise name whatever parts it liked. The client's own
     * list is only used if the bucket won't tell us.
     *
     * @param SupportsPresignedUploads $fs
     * @param MultipartUpload $upload
     * @param array $state
     * @return array[]
     * @throws BadRequestHttpException
     */
    private function _resolveParts(SupportsPresignedUploads $fs, MultipartUpload $upload, array $state): array
    {
        $expected = (int)($state['partCount'] ?? 0);

        try {
            $parts = $fs->getUploadedParts($upload);
        } catch (Throwable $e) {
            Craft::warning("Couldn’t list uploaded parts: {$e->getMessage()}", __METHOD__);
            $parts = [];
        }

        if ($parts && (!$expected || count($parts) === $expected)) {
            return $parts;
        }

        $clientParts = $this->request->getBodyParam('parts');

        if (is_array($clientParts) && $clientParts) {
            return $clientParts;
        }

        if ($expected && count($parts) !== $expected) {
            throw new BadRequestHttpException(
                sprintf('Only %d of %d parts reached the bucket.', count($parts), $expected)
            );
        }

        throw new BadRequestHttpException('Couldn’t determine which parts were uploaded.');
    }

    /**
     * Rebuilds the multipart handle from sealed token state.
     *
     * @param array $state
     * @return MultipartUpload
     */
    private function _multipartUpload(array $state): MultipartUpload
    {
        return new MultipartUpload([
            'uploadId' => $state['uploadId'],
            'path' => $state['fsPath'],
            'bucket' => $state['bucket'],
        ]);
    }

    /**
     * Creates the asset element for an object that's already in the bucket.
     *
     * Indexing rather than assigning `tempFilePath` is the whole point: setting
     * a temp path would send Craft off to download the file and upload it right
     * back again.
     *
     * @param Volume $volume
     * @param array $state
     * @return Asset
     */
    private function _indexAsset(Volume $volume, array $state): Asset
    {
        $indexer = Craft::$app->getAssetIndexer();
        $session = $indexer->createIndexingSession([$volume], false);

        try {
            $asset = $indexer->indexFile($volume, $state['volumePath'], $session->id, false, true);
        } finally {
            $indexer->stopIndexingSession($session);
        }

        if (!$asset->uploaderId) {
            $asset->uploaderId = $state['userId'];
            $asset->setScenario(Asset::SCENARIO_INDEX);
            Craft::$app->getElements()->saveElement($asset, false);
        }

        return $asset;
    }
}
