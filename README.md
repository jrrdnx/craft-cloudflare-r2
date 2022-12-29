# Cloudflare R2

[Cloudflare R2](https://www.cloudflare.com/products/r2/) filesystem for Craft CMS

## Requirements

This plugin requires Craft CMS ^4.0.0-beta.1 and PHP ^8.0.2

## Installation

To install the plugin, follow these instructions.

1. Open your terminal and go to your Craft project:

        cd /path/to/project

2. Then tell Composer to load the plugin:

        composer require jrrdnx/craft-cloudflare-r2

3. Navigate to Settings -> Plugins and click the "Install" button for Cloudflare R2.

## Configuring filesystem

1. Navigate to Settings -> Filesystems and click the "New Filesystem" button.
2. Select "Cloudflare R2" from the "Filesystem Type" dropdown.
3. Enter your Account ID, Access Key ID, and Secret Access Key (it's recommended to store these in your `.env` file and reference the environment variables here).
4. Hit Refresh to load the bucket list, or choose the Manual option and enter the bucket name (again, you can store this in your `.env` file and reference the environment variable).
5. Optionally add a Subfolder, determine whether or not to add the Subfolder to the Base URL, and set the Cache Control duration.

### Making objects public (optional)

See [Create Public Buckets on R2](https://developers.cloudflare.com/r2/data-access/public-buckets/) for details on adding a custom domain or managing public buckets through r2.dev, or [Use R2 from Workers](https://developers.cloudflare.com/r2/data-access/workers-api/workers-api-usage/) for more fine-tuned access control. Also feel free to reference a full guide to [Configuring a Cloudflare R2 Bucket and Worker for Public Access](https://jarrodnix.dev/blog/configuring-a-cloudflare-r2-bucket-and-worker-for-public-access).

## Misc

[Open an Issue](https://github.com/jrrdnx/craft-cloudflare-r2/issues)

Brought to you by [Jarrod D Nix](https://jarrodnix.dev)