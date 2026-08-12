<?php

namespace jrrdnx\cloudflarer2\controllers;

use Craft;
use craft\elements\Asset;
use craft\events\ReplaceAssetEvent;
use craft\fields\Assets as AssetsField;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\Image;
use craft\helpers\Json;
use craft\i18n\Formatter;
use craft\models\Volume;
use craft\models\VolumeFolder;
use craft\services\Assets as AssetsService;
use craft\web\Controller as BaseController;
use DateTime;
use jrrdnx\cloudflarer2\models\MultipartUpload;
use jrrdnx\cloudflarer2\SupportsPresignedUploads;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
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

        // Replacing swaps the file under an asset that already exists, so it
        // resolves its target from the asset rather than from a folder.
        if ($assetId = (int)$this->request->getBodyParam('assetId')) {
            return $this->_startReplace($assetId, $filename, $size, $mimeType);
        }

        $folder = $this->_resolveFolder();

        // Nothing to upload into. Hand it back rather than failing, and Craft's
        // own uploader takes it from here.
        if (!$folder) {
            return $this->asJson(['mode' => 'traditional']);
        }

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

        // Sealed into the token rather than stored, so there's no state to clean
        // up if the client walks away mid-upload.
        $state = [
            'mode' => 'create',
            'userId' => (int)Craft::$app->getUser()->getId(),
            'volumeId' => (int)$volume->id,
            'folderId' => (int)$folder->id,
            'filename' => $filename,
            'volumePath' => $volumePath,
            'size' => $size,
            'expiresAt' => time() + self::TOKEN_TTL,
        ];

        return $this->_presignInto($fs, $fsPath, $size, $mimeType, $state, $filename);
    }

    /**
     * Presigns a replacement for an existing asset's file.
     *
     * Overwriting in place is safe: S3 and R2 only ever swap an object once the
     * whole thing has arrived, and a multipart upload doesn't materialize until
     * it's completed. A cancelled or failed replacement leaves the original
     * exactly as it was.
     *
     * @param int $assetId
     * @param string $filename
     * @param int $size
     * @param string|null $mimeType
     * @return Response
     * @throws BadRequestHttpException
     * @throws NotFoundHttpException
     */
    private function _startReplace(int $assetId, string $filename, int $size, ?string $mimeType): Response
    {
        $asset = Craft::$app->getAssets()->getAssetById($assetId);

        if (!$asset) {
            throw new NotFoundHttpException('Asset not found.');
        }

        $volume = $asset->getVolume();
        $fs = $volume->getFs();

        if (!$fs instanceof SupportsPresignedUploads || !$fs->shouldPresignUpload($size)) {
            return $this->asJson(['mode' => 'traditional']);
        }

        $this->_requireReplacePermission($asset);

        $filename = AssetsHelper::prepareAssetName($filename);
        $this->_requireAllowedExtension($filename);

        // Fire this before presigning, since a handler may rename the file and
        // the new name decides where the object lands.
        $assets = Craft::$app->getAssets();

        if ($assets->hasEventHandlers(AssetsService::EVENT_BEFORE_REPLACE_ASSET)) {
            $event = new ReplaceAssetEvent([
                'asset' => $asset,
                'replaceWith' => '',
                'filename' => $filename,
            ]);
            $assets->trigger(AssetsService::EVENT_BEFORE_REPLACE_ASSET, $event);
            $filename = AssetsHelper::prepareAssetName($event->filename);
        }

        $oldPath = $asset->getPath();
        $folderPath = $asset->folderPath ? rtrim($asset->folderPath, '/') . '/' : '';

        // Renaming onto a name another asset already holds would leave two
        // records pointing at one object.
        if ($filename !== $asset->getFilename() && $volume->fileExists($folderPath . $filename)) {
            $filename = $assets->getNameReplacementInFolder($filename, $asset->folderId);
        }

        $volumePath = $folderPath . $filename;
        $fsPath = $volume->getSubpath() . $volumePath;

        $state = [
            'mode' => 'replace',
            'userId' => (int)Craft::$app->getUser()->getId(),
            'assetId' => $asset->id,
            'volumeId' => (int)$volume->id,
            'filename' => $filename,
            'volumePath' => $volumePath,
            'oldPath' => $oldPath,
            'size' => $size,
            'mimeType' => $mimeType,
            'expiresAt' => time() + self::TOKEN_TTL,
        ];

        return $this->_presignInto($fs, $fsPath, $size, $mimeType, $state, $filename);
    }

    /**
     * Presigns an upload to a resolved path and builds the response for it.
     *
     * @param SupportsPresignedUploads $fs
     * @param string $fsPath
     * @param int $size
     * @param string|null $mimeType
     * @param array $state
     * @param string $filename
     * @return Response
     */
    private function _presignInto(
        SupportsPresignedUploads $fs,
        string $fsPath,
        int $size,
        ?string $mimeType,
        array $state,
        string $filename,
    ): Response {
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

        if (($state['mode'] ?? null) === 'replace') {
            return $this->_completeReplace($volume, $state);
        }

        $asset = $this->_indexAsset($volume, $state);

        return $this->asJson([
            'assetId' => $asset->id,
            'filename' => $asset->getFilename(),
            'url' => $asset->getUrl(),
        ]);
    }

    /**
     * Points an existing asset at its newly uploaded file.
     *
     * This is the tail of craft\elements\Asset::_relocateFile() minus the part
     * that moves bytes around, since the object is already sitting where it
     * needs to be. Neither `tempFilePath` nor `newLocation` is set, so saving
     * won't try to relocate anything.
     *
     * @param Volume $volume
     * @param array $state
     * @return Response
     * @throws BadRequestHttpException
     * @throws NotFoundHttpException
     */
    private function _completeReplace(Volume $volume, array $state): Response
    {
        $asset = Craft::$app->getAssets()->getAssetById($state['assetId']);

        if (!$asset) {
            throw new NotFoundHttpException('Asset not found.');
        }

        $this->_requireReplacePermission($asset);

        // Renamed, so the old object is now orphaned.
        if ($state['oldPath'] !== $state['volumePath'] && $volume->fileExists($state['oldPath'])) {
            $volume->deleteFile($state['oldPath']);
        }

        Craft::$app->getImageTransforms()->deleteAllTransformData($asset);

        $asset->setFilename($state['filename']);
        $asset->kind = AssetsHelper::getFileKindByExtension($state['filename']);
        $asset->size = $volume->getFileSize($state['volumePath']);
        $asset->uploaderId = Craft::$app->getUser()->getId();

        if ($state['mimeType']) {
            $asset->setMimeType($state['mimeType']);
        }

        $dateModified = $volume->getDateModified($state['volumePath']);
        $asset->dateModified = $dateModified ? new DateTime('@' . $dateModified) : null;

        [$width, $height] = $this->_imageSize($volume, $asset, $state['volumePath']);
        $asset->setWidth($width);
        $asset->setHeight($height);

        $asset->setScenario(Asset::SCENARIO_INDEX);
        Craft::$app->getElements()->saveElement($asset, false);

        $assets = Craft::$app->getAssets();

        if ($assets->hasEventHandlers(AssetsService::EVENT_AFTER_REPLACE_ASSET)) {
            $assets->trigger(AssetsService::EVENT_AFTER_REPLACE_ASSET, new ReplaceAssetEvent([
                'asset' => $asset,
                'filename' => $asset->getFilename(),
            ]));
        }

        // Matches what craft\controllers\AssetsController::actionReplaceFile()
        // returns, so Craft's own replace handlers can consume this unchanged.
        return $this->asJson([
            'success' => true,
            'assetId' => $asset->id,
            'filename' => $asset->getFilename(),
            'formattedSize' => $asset->getFormattedSize(0),
            'formattedSizeInBytes' => $asset->getFormattedSizeInBytes(false),
            'formattedDateUpdated' => Craft::$app->getFormatter()->asDatetime(
                $asset->dateUpdated,
                Formatter::FORMAT_WIDTH_SHORT,
                true,
            ),
            'dimensions' => $asset->getDimensions(),
            'updatedTimestamp' => $asset->dateUpdated->getTimestamp(),
            'resultingUrl' => $asset->getUrl(),
        ]);
    }

    /**
     * Reads an image's dimensions straight off the bucket.
     *
     * Only the leading bytes get read, so this doesn't pull the whole file back
     * down just to find out how big the picture is.
     *
     * @param Volume $volume
     * @param Asset $asset
     * @param string $path
     * @return array{int|null, int|null}
     */
    private function _imageSize(Volume $volume, Asset $asset, string $path): array
    {
        if ($asset->kind !== Asset::KIND_IMAGE) {
            return [null, null];
        }

        $stream = null;

        try {
            $stream = $volume->getFileStream($path);
            $size = Image::imageSizeByStream($stream);

            if (is_array($size) && count($size) === 2) {
                return [(int)$size[0] ?: null, (int)$size[1] ?: null];
            }
        } catch (Throwable $e) {
            Craft::warning("Couldn’t read image dimensions for $path: {$e->getMessage()}", __METHOD__);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return [null, null];
    }

    /**
     * Requires permission to replace a given asset's file.
     *
     * @param Asset $asset
     * @throws ForbiddenHttpException
     */
    private function _requireReplacePermission(Asset $asset): void
    {
        $volume = $asset->getVolume();
        $this->requirePermission("replaceFiles:$volume->uid");

        if ($asset->uploaderId !== Craft::$app->getUser()->getId()) {
            $this->requirePermission("replacePeerFiles:$volume->uid");
        }
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
     * @return VolumeFolder|null Null if the request names no upload target
     * @throws BadRequestHttpException if a target was named but isn't usable
     */
    private function _resolveFolder(): ?VolumeFolder
    {
        $folderId = (int)$this->request->getBodyParam('folderId') ?: null;
        $fieldId = (int)$this->request->getBodyParam('fieldId') ?: null;

        if (!$folderId && !$fieldId) {
            return null;
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
