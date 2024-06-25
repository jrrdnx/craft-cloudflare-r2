<?php

namespace jrrdnx\cloudflarer2\controllers;

use Craft;
use jrrdnx\cloudflarer2\Volume;
use craft\helpers\App;
use craft\web\Controller as BaseController;
use yii\web\Response;

/**
 * This controller provides functionality to load data from Cloudflare.
 *
 * @author Jarrod D Nix
 * @since 1.0
 */
class BucketsController extends BaseController
{
    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->defaultAction = 'load-bucket-data';
    }

    /**
     * Load bucket data for specified credentials.
     *
     * @return Response
     */
    public function actionLoadBucketData(): Response
    {
		$this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
		$accountId = App::parseEnv($request->getRequiredBodyParam('accountId'));
        $keyId = App::parseEnv($request->getRequiredBodyParam('keyId'));
        $secret = App::parseEnv($request->getRequiredBodyParam('secret'));

        try {
			return $this->asJson([
                'buckets' => Volume::loadBucketList($accountId, $keyId, $secret),
            ]);
        } catch (\Throwable $e) {
            return $this->asFailure($e->getMessage());
        }
    }
}
