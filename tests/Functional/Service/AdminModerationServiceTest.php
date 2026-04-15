<?php

namespace App\Tests\Functional\Service;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Service\AdminModerationService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class AdminModerationServiceTest extends KernelTestCase
{
    private static bool $schemaInitialized = false;

    public function testNonAdminCannotWriteModerationLog(): void
    {
        self::bootKernel();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        /** @var TokenStorageInterface $tokenStorage */
        $tokenStorage = static::getContainer()->get(TokenStorageInterface::class);
        /** @var AdminModerationService $moderationService */
        $moderationService = static::getContainer()->get(AdminModerationService::class);

        $this->initializeSchemaIfNeeded($entityManager);

        $applicant = $this->createUser($entityManager, 'service_applicant_'.bin2hex(random_bytes(4)).'@example.com', ['ROLE_APPLICANT']);
        $target = $this->createUser($entityManager, 'service_target_'.bin2hex(random_bytes(4)).'@example.com', ['ROLE_RECRUITER']);

        $tokenStorage->setToken(new UsernamePasswordToken($applicant, 'main', $applicant->getRoles()));

        $this->expectException(AccessDeniedHttpException::class);
        $moderationService->logAction(AdminModerationService::ACTION_BAN, $target, 'Tentative non autorisee');
    }

    private function createUser(EntityManagerInterface $entityManager, string $email, array $roles): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setStatus(UserStatus::ACTIVE);
        $user->setIsVerified(true);
        $user->setPassword('hashed-password');

        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function initializeSchemaIfNeeded(EntityManagerInterface $entityManager): void
    {
        if (self::$schemaInitialized) {
            return;
        }

        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        if ([] === $metadata) {
            throw new \RuntimeException('Aucune metadonnee Doctrine disponible pour creer le schema de test.');
        }

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
        self::$schemaInitialized = true;
    }
}
