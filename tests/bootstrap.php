<?php

use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

if ('test' === ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? null)) {
    if (!isset($_SERVER['PANTHER_WEB_SERVER_PORT']) && !isset($_ENV['PANTHER_WEB_SERVER_PORT'])) {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if (false !== $socket) {
            $address = stream_socket_get_name($socket, false);
            fclose($socket);

            if (is_string($address) && str_contains($address, ':')) {
                $port = substr($address, strrpos($address, ':') + 1);
                $_SERVER['PANTHER_WEB_SERVER_PORT'] = $port;
                $_ENV['PANTHER_WEB_SERVER_PORT'] = $port;
            }
        }
    }

    $testToken = $_SERVER['TEST_TOKEN'] ?? $_ENV['TEST_TOKEN'] ?? '';
    $sqliteTestDatabase = dirname(__DIR__).'/var/data_test'.$testToken.'.db';

    if (is_file($sqliteTestDatabase)) {
        @unlink($sqliteTestDatabase);
    }

    $kernelClass = $_SERVER['KERNEL_CLASS'] ?? $_ENV['KERNEL_CLASS'] ?? null;

    if (\is_string($kernelClass) && class_exists($kernelClass)) {
        $kernel = new $kernelClass('test', true);
        $booted = false;

        try {
            $kernel->boot();
            $booted = true;

            $entityManager = $kernel->getContainer()->get('doctrine')->getManager();
            $metadata = $entityManager->getMetadataFactory()->getAllMetadata();

            if ([] !== $metadata) {
                $schemaTool = new SchemaTool($entityManager);
                $schemaTool->dropSchema($metadata);
                $schemaTool->createSchema($metadata);
            }

            $entityManager->clear();
        } finally {
            if ($booted) {
                $kernel->shutdown();
            }
        }
    }
}
