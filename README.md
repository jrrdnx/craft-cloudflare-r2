# Cloudflare R2

[Cloudflare R2](https://www.cloudflare.com/products/r2/) filesystem for Craft CMS

> **Note**
> This plugin will remain in Beta at least as long as Cloudflare R2 is in Beta.

[Open an Issue](https://github.com/jrrdnx/craft-cloudflare-r2/issues)

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

> **Warning**
> Cloudflare R2 does not currently (as of the time of this writing) support ACLs with public-read access. In order to make your objects readable, you will need to set up a worker (read "Making objects public" below).

1. Navigate to Settings -> Filesystems and click the "New Filesystem" button.
2. Select "Cloudflare R2" from the "Filesystem Type" dropdown.
3. Enter your Account ID, Access Key ID, and Secret Access Key (it's recommended to store these in your `.env` file and reference the environment variables here).
4. Hit Refresh to load the bucket list, or choose the Manual option and enter the bucket name (again, you can store this in your `.env` file and reference the environment variable).
5. Optionally add a Subfolder, determine whether or not to add the Subfolder to the Base URL, and set the Cache Control duration.

### Making objects public (optional)

1. In Cloudflare, you'll need to set up a subdomain for your workers. Navigate to Workers, and if you haven't already you'll be prompted to create this first.
2. Follow [Cloudflare's Get Started Guide](https://developers.cloudflare.com/r2/get-started/) to install and authenticate Wrangler, bind your bucket to a Worker, enable GET requests to your objects, and deploy your Worker.
3. Your filesystem's "Base URL" will look like `https://WORKER_NAME.SUBDOMAIN.workers.dev`

## Misc

[Open an Issue](https://github.com/jrrdnx/craft-cloudflare-r2/issues)

Brought to you by [Jarrod D Nix](https://jarrodnix.dev)