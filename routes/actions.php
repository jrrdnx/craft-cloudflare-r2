<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use jrrdnx\cloudflarer2\controllers\BucketsController;

Route::post('buckets/load-bucket-data', [BucketsController::class, 'loadBucketData']);
