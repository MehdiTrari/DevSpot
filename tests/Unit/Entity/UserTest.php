<?php

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testDefaultRoleIsAlwaysPresent(): void
    {
        $user = new User();

        self::assertContains('ROLE_USER', $user->getRoles());
    }

    public function testRolesAreUniqueAndKeepCustomRoles(): void
    {
        $user = new User();
        $user->setRoles(['ROLE_ADMIN', 'ROLE_USER', 'ROLE_ADMIN']);

        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], array_values($user->getRoles()));
    }

    public function testUserIdentifierUsesEmail(): void
    {
        $user = new User();
        $user->setEmail('test@example.com');

        self::assertSame('test@example.com', $user->getUserIdentifier());
    }

    public function testCreatedAndUpdatedAtAreInitialized(): void
    {
        $user = new User();

        self::assertInstanceOf(\DateTimeImmutable::class, $user->getCreatedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $user->getUpdatedAt());
        self::assertEquals($user->getCreatedAt(), $user->getUpdatedAt());
    }
}
