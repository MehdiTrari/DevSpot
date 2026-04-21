<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\SeedMatchingDemoCommand;
use App\Entity\DeveloperProfile;
use App\Entity\JobOffer;
use App\Entity\RecruiterProfile;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SeedMatchingDemoCommandTest extends TestCase
{
    public function testItFailsWhenDatasetFileDoesNotExist(): void
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $passwordHasher = $this->createStub(UserPasswordHasherInterface::class);
        $tester = new CommandTester(new SeedMatchingDemoCommand('/tmp/devspot-missing', $entityManager, $passwordHasher));

        self::assertSame(Command::FAILURE, $tester->execute(['dataset' => 'missing.json']));
        self::assertStringContainsString('Dataset introuvable', $tester->getDisplay());
    }

    public function testItImportsMinimalDatasetAndPersistsProfilesAndOffer(): void
    {
        $projectDir = sys_get_temp_dir() . '/devspot-seed-' . bin2hex(random_bytes(6));
        mkdir($projectDir, 0777, true);
        $datasetPath = $projectDir . '/dataset.json';
        file_put_contents($datasetPath, json_encode([
            'recruiter' => [
                'user' => [
                    'email' => 'recruiter.demo@demo.devspot.local',
                    'password' => 'Demo1234!',
                    'roles' => ['ROLE_RECRUITER'],
                    'status' => 'active',
                ],
                'profile' => [
                    'firstName' => 'Nora',
                    'lastName' => 'Boss',
                    'jobTitle' => 'CTO',
                    'workEmail' => 'nora@company.test',
                    'phone' => '0102030405',
                ],
            ],
            'developers' => [[
                'user' => [
                    'email' => 'dev.demo@demo.devspot.local',
                    'password' => 'Demo1234!',
                    'roles' => ['ROLE_APPLICANT'],
                    'status' => 'active',
                ],
                'profile' => [
                    'firstName' => 'Alice',
                    'lastName' => 'Dev',
                    'headline' => 'Développeuse Symfony',
                    'bio' => 'API et produit',
                    'city' => 'Paris',
                    'country' => 'France',
                    'locationType' => 'remote',
                    'experienceLevel' => 'junior',
                    'yearsExperience' => 2,
                    'isPublic' => true,
                    'slug' => 'alice-dev',
                    'desiredPositions' => [],
                    'profileSkills' => [],
                    'experiences' => [],
                    'education' => [],
                ],
            ]],
            'offers' => [[
                'title' => 'Backend Symfony',
                'description' => 'API Platform et Symfony',
                'location' => 'Paris',
                'locationType' => 'remote',
                'contractType' => 'full_time',
                'experienceLevel' => 2,
                'salaryMin' => 40000,
                'salaryMax' => 50000,
                'isActive' => true,
            ]],
        ], JSON_THROW_ON_ERROR));

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(10))->method('executeStatement');

        $persisted = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('getConnection')->willReturn($connection);
        $entityManager->expects(self::once())->method('clear');
        $entityManager->expects(self::exactly(5))->method('persist')->willReturnCallback(function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $entityManager->expects(self::once())->method('flush');

        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $passwordHasher
            ->expects(self::exactly(2))
            ->method('hashPassword')
            ->willReturnCallback(static fn (): string => 'hashed-password');

        $tester = new CommandTester(new SeedMatchingDemoCommand($projectDir, $entityManager, $passwordHasher));

        self::assertSame(Command::SUCCESS, $tester->execute(['dataset' => 'dataset.json']));
        self::assertStringContainsString('Dataset de matching importé: 1 développeurs, 1 offres', $tester->getDisplay());
        self::assertCount(5, $persisted);
        self::assertContainsOnlyInstancesOf(User::class, array_filter($persisted, static fn (object $entity): bool => $entity instanceof User));
        self::assertCount(1, array_filter($persisted, static fn (object $entity): bool => $entity instanceof RecruiterProfile));
        self::assertCount(1, array_filter($persisted, static fn (object $entity): bool => $entity instanceof DeveloperProfile));
        self::assertCount(1, array_filter($persisted, static fn (object $entity): bool => $entity instanceof JobOffer));
    }
}