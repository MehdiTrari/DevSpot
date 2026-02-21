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
    $kernelClass = $_SERVER['KERNEL_CLASS'] ?? $_ENV['KERNEL_CLASS'] ?? null;

    if (\is_string($kernelClass) && class_exists($kernelClass)) {
        $kernel = new $kernelClass('test', true);
        $kernel->boot();

        $entityManager = $kernel->getContainer()->get('doctrine')->getManager();
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();

        if ([] !== $metadata) {
            $schemaTool = new SchemaTool($entityManager);

            // NOTE:
            // We intentionally drop and recreate the database schema in the test bootstrap.
            // This runs once per test process. When using paratest, each worker has its own
            // bootstrap and (typically, via TEST_TOKEN) its own database, so isolation is preserved.
            //
            // Be aware that for complex schemas this can impact test performance, because each
            // parallel worker will repeat the schema creation. If this becomes a bottleneck,
            // consider using database snapshots, migrations-based setups, or fixtures instead.
            $schemaTool->dropSchema($metadata);
            $schemaTool->createSchema($metadata);
        }

        $entityManager->clear();
        $kernel->shutdown();
    }
}
