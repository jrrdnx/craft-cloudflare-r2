<?php
/**
 * @link https://jarrodnix.dev/
 * @copyright Copyright (c) Jarrod D Nix
 * @license MIT
 */

namespace jrrdnx\cloudflarer2;

use craft\web\assets\cp\CpAsset;
use yii\web\AssetBundle;

/**
 * Asset bundle for direct uploads from the control panel.
 *
 * @since 1.2.0
 */
class PresignedUploadBundle extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public $sourcePath = '@jrrdnx/cloudflarer2/resources';

    /**
     * @inheritdoc
     */
    public $depends = [
        // Craft.registerUploaderClass() has to exist by the time this runs.
        CpAsset::class,
    ];

    /**
     * @inheritdoc
     */
    public $css = [
        'css/presignedUpload.css',
    ];

    /**
     * @inheritdoc
     */
    public $js = [
        'js/presignedUpload.js',
    ];
}
