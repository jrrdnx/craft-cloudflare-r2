<?php
/**
 * @link https://jarrodnix.dev/
 * @copyright Copyright (c) Jarrod D Nix
 * @license MIT
 */

namespace jrrdnx\cloudflarer2;

use Craft;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Fs as FsService;
use craft\web\View;
use yii\base\Event;

/**
 * Plugin represents the Amazon S3 filesystem.
 *
 * @author Jarrod D Nix
 */
class Plugin extends \craft\base\Plugin
{
    // Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    public string $schemaVersion = '1.0';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        Event::on(FsService::class, FsService::EVENT_REGISTER_FILESYSTEM_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = Fs::class;
        });

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $this->_registerUploader();
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * Swaps in a control panel uploader that sends files straight to the bucket.
     *
     * Craft picks an uploader per filesystem type, so this only ever applies to
     * R2 volumes — everything else keeps using the stock uploader. It's skipped
     * entirely unless at least one filesystem has direct uploads switched on.
     */
    private function _registerUploader(): void
    {
        Event::on(View::class, View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE, function() {
            $threshold = $this->_uploadThreshold();

            if ($threshold === null) {
                return;
            }

            $view = Craft::$app->getView();

            $view->registerJsVar('CloudflareR2PresignedUpload', [
                // Only a floor, so tiny files don't pay for a pointless round
                // trip. The server decides for real, per filesystem, and may
                // still say to upload the ordinary way.
                'threshold' => $threshold,
                'fsType' => Fs::class,
                'actions' => [
                    'start' => 'cloudflare-r2/presigned-upload/start',
                    'complete' => 'cloudflare-r2/presigned-upload/complete',
                    'abort' => 'cloudflare-r2/presigned-upload/abort',
                ],
            ]);

            $view->registerAssetBundle(PresignedUploadBundle::class);
        });
    }

    /**
     * Returns the smallest threshold across filesystems with direct uploads on,
     * or null if none of them do.
     *
     * The browser gets one number for the whole page, so it has to be the most
     * permissive one.
     *
     * @return int|null
     */
    private function _uploadThreshold(): ?int
    {
        $threshold = null;

        foreach (Craft::$app->getFs()->getAllFilesystems() as $fs) {
            if (!$fs instanceof SupportsPresignedUploads || !$fs->isPresignedUploadEnabled()) {
                continue;
            }

            $threshold = $threshold === null
                ? $fs->getPresignedUploadThreshold()
                : min($threshold, $fs->getPresignedUploadThreshold());
        }

        return $threshold;
    }
}
