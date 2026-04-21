<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\ResetFrontendCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ResetFrontendCommandTest extends TestCase
{
    public function testItPurgesCachesAndRunsFrontendCommandsSuccessfully(): void
    {
        $projectDir = $this->createTempProjectDir();
        $this->seedDirectory($projectDir . '/var/tailwind', ['old.css' => 'stale']);
        $this->seedDirectory($projectDir . '/public/assets/nested', ['app.js' => 'compiled']);
        $this->writeConsoleScript($projectDir, <<<'PHP'
<?php
echo implode(' ', array_slice($argv, 1));
exit(0);
PHP);

        $tester = new CommandTester(new ResetFrontendCommand($projectDir));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame([], $this->listDirectoryItems($projectDir . '/var/tailwind'));
        self::assertSame([], $this->listDirectoryItems($projectDir . '/public/assets'));
        self::assertStringContainsString('Caches front locaux réinitialisés', $tester->getDisplay());
    }

    public function testItStopsWhenAFrontendSubCommandFails(): void
    {
        $projectDir = $this->createTempProjectDir();
        $this->writeConsoleScript($projectDir, <<<'PHP'
<?php
$command = $argv[1] ?? '';
if ('tailwind:build' === $command) {
    fwrite(STDERR, 'tailwind failed');
    exit(1);
}
echo $command;
exit(0);
PHP);

        $tester = new CommandTester(new ResetFrontendCommand($projectDir));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('La commande "tailwind:build" a échoué.', $tester->getDisplay());
    }

    /**
     * @param array<string, string> $files
     */
    private function seedDirectory(string $dir, array $files): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        foreach ($files as $name => $content) {
            file_put_contents($dir . '/' . $name, $content);
        }
    }

    private function createTempProjectDir(): string
    {
        $dir = sys_get_temp_dir() . '/devspot-reset-' . bin2hex(random_bytes(6));
        mkdir($dir . '/bin', 0777, true);
        mkdir($dir . '/var/tailwind', 0777, true);
        mkdir($dir . '/public/assets', 0777, true);

        return $dir;
    }

    /**
     * @return list<string>
     */
    private function listDirectoryItems(string $dir): array
    {
        $items = scandir($dir);

        return false === $items ? [] : array_values(array_filter($items, static fn (string $item): bool => '.' !== $item && '..' !== $item));
    }

    private function writeConsoleScript(string $projectDir, string $content): void
    {
        file_put_contents($projectDir . '/bin/console', $content);
    }
}