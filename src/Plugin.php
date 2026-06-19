<?php

declare(strict_types=1);
/**
 * @link https://jarrodnix.dev/
 * @copyright Copyright (c) Jarrod D Nix
 * @license MIT
 */

namespace jrrdnx\cloudflarer2;

use Aws\Credentials\Credentials;
use CraftCms\Cms\Filesystem\Events\FilesystemTypesResolving;
use CraftCms\Cms\Plugin\Events\PluginEnabling;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\PortableVisibilityConverter;
use League\Flysystem\Filesystem as FlysystemFilesystem;
use League\Flysystem\Visibility;

/**
 * Plugin represents the Cloudflare R2 filesystem plugin.
 *
 * @author Jarrod D Nix
 */
class Plugin extends \CraftCms\Cms\Plugin\Plugin
{
    public string $handle = 'cloudflare-r2';
    public ?string $name = 'Cloudflare R2';
    public string $schemaVersion = '1.0.0';
    public ?string $developer = 'Jarrod D Nix';
    public ?string $developerUrl = 'https://jarrodnix.dev/';
    public ?string $documentationUrl = 'https://jarrodnix.dev/plugins/cloudflare-r2/';

    // getBasePath() returns the src/ directory, so resources live at src/resources/
    public function getResourcesPath(): string
    {
        return $this->getBasePath() . '/resources';
    }

    public function registerPlugin(): void
    {
        // Copy the CP JS file to the public directory when the plugin is enabled.
        // HasFrontendAssets only does this automatically when $vite is configured.
        Event::listen(PluginEnabling::class, function (PluginEnabling $event) {
            if (!($event->plugin instanceof static)) {
                return;
            }
            // Use $event->plugin for the package name — the singleton may not be
            // registered yet during a first-time enable.
            $this->publishJs($event->plugin->packageName);
        });
    }

    public function bootPlugin(): void
    {
        Event::listen(function (FilesystemTypesResolving $event) {
            $event->types->push(Fs::class);
        });

        Storage::extend('r2', function ($app, $config) {
            $client = new S3Client([
                'credentials' => new Credentials($config['key'], $config['secret']),
                'region' => $config['region'] ?? 'auto',
                'endpoint' => $config['endpoint'],
                'version' => 'latest',
            ]);

            $adapter = new CloudflareR2Adapter(
                $client,
                $config['bucket'],
                $config['prefix'] ?? '',
                new PortableVisibilityConverter(Visibility::PRIVATE),
                null,
                $config['options'] ?? [],
                false,
            );

            // Subclass FilesystemAdapter to add URL support via the config's 'url' key,
            // since Laravel's base class only does this for FTP/local adapters.
            return new class(new FlysystemFilesystem($adapter), $adapter, $config) extends FilesystemAdapter {
                public function url($path)
                {
                    if (isset($this->config['prefix'])) {
                        $path = $this->concatPathToUrl($this->config['prefix'], $path);
                    }
                    if (isset($this->config['url'])) {
                        return $this->concatPathToUrl($this->config['url'], $path);
                    }
                    throw new \RuntimeException(
                        'No Base URL configured for this R2 filesystem. ' .
                        'Set a Base URL in the filesystem settings and ensure the environment variable is defined.'
                    );
                }
            };
        });

        // Use getInstance() — $this is the service provider instance (packageName is null),
        // not the Craft plugin singleton (which has packageName set from plugin config).
        $plugin = static::getInstance();

        // Self-heal: publish the JS if it was never copied (e.g. after a path fix).
        $this->publishJs($plugin->packageName);

        // Register the CP script URL.
        $version = md5($plugin->version);
        $this->pluginsService->addScript(
            $plugin->packageName,
            asset($this->getPublishablePath("js/editVolume.js?v=$version")),
        );
    }

    private function publishJs(string $packageName): void
    {
        $source = $this->getResourcesPath() . '/js/editVolume.js';
        $dest = public_path("vendor/{$packageName}/js/editVolume.js");

        if (!file_exists($source) || file_exists($dest)) {
            return;
        }

        if (!is_dir(dirname($dest))) {
            mkdir(dirname($dest), 0755, true);
        }

        copy($source, $dest);
    }
}
