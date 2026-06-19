<?php

declare(strict_types=1);
/**
 * @link https://jarrodnix.dev/
 * @copyright Copyright (c) Jarrod D Nix
 * @license MIT
 */

namespace jrrdnx\cloudflarer2;

use Aws\CommandInterface;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client as AwsS3Client;

/**
 * Extended S3 client that refreshes credentials on ExpiredToken errors.
 *
 * @author Jarrod D Nix
 * @since 1.0
 */
class S3Client extends AwsS3Client
{
    /** @var callable|null Callback for generating new config with fresh credentials */
    private $_generateNewConfig;

    /** @var AwsS3Client The wrapped client used for all requests */
    private AwsS3Client $_wrappedClient;

    public function __construct(array $args)
    {
        if (!empty($args['generateNewConfig'])) {
            $this->_generateNewConfig = $args['generateNewConfig'];
            unset($args['generateNewConfig']);
        }

        $this->_wrappedClient = new AwsS3Client($args);

        parent::__construct($args);
    }

    public function execute(CommandInterface $command)
    {
        try {
            return $this->_wrappedClient->execute($command);
        } catch (S3Exception $exception) {
            if ($exception->getAwsErrorCode() == 'ExpiredToken' && $this->_generateNewConfig !== null) {
                $clientConfig = call_user_func($this->_generateNewConfig);
                $this->_wrappedClient = new AwsS3Client($clientConfig);

                $newCommand = $this->getCommand($command->getName(), $command->toArray());
                return $this->_wrappedClient->execute($newCommand);
            }

            throw $exception;
        }
    }

    public function getCommand($name, array $args = [])
    {
        return $this->_wrappedClient->getCommand($name, $args);
    }
}
