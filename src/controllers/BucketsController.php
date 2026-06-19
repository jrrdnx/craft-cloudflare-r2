<?php

declare(strict_types=1);

namespace jrrdnx\cloudflarer2\controllers;

use CraftCms\Cms\Support\Env;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use jrrdnx\cloudflarer2\Fs;

/**
 * This controller provides functionality to load data from Cloudflare.
 *
 * @author Jarrod D Nix
 * @since 1.0
 */
class BucketsController extends Controller
{
    public function loadBucketData(Request $request): JsonResponse
    {
        $accountId = Env::parse($request->input('accountId', ''));
        $keyId = Env::parse($request->input('keyId', ''));
        $secret = Env::parse($request->input('secret', ''));

        try {
            return response()->json([
                'buckets' => Fs::loadBucketList($accountId, $keyId, $secret),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }
}
