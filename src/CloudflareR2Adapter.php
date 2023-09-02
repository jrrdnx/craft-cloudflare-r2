<?php

namespace jrrdnx\cloudflarer2;

use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\FileAttributes;
use League\Flysystem\Visibility;

class CloudflareR2Adapter extends AwsS3V3Adapter
{
    public function visibility(string $path): FileAttributes
    {
        // R2 assets are always private
        return new FileAttributes($path, null, Visibility::PRIVATE);
    }
}
