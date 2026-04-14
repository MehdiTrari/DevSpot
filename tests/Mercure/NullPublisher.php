<?php

namespace App\Tests\Mercure;

use Symfony\Component\Mercure\Update;

final class NullPublisher
{
    public function __invoke(Update $update): string
    {
        return $update->getId() ?? 'test-update';
    }
}
