<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'app:frontend:reset',
    description: 'Réinitialise les caches front locaux puis reconstruit Tailwind et l’asset-map.',
)]
final class ResetFrontendCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Réinitialisation du frontend local');

        $this->purgeDirectory($this->projectDir . '/var/tailwind');
        $this->purgeDirectory($this->projectDir . '/public/assets');

        $io->section('Reconstruction des caches et assets');

        foreach ([
            ['cache:clear'],
            ['cache:warmup'],
            ['tailwind:build'],
            ['asset-map:compile'],
        ] as $arguments) {
            $process = new Process([PHP_BINARY, 'bin/console', ...$arguments], $this->projectDir, null, null, 300);
            $process->setTimeout(300);
            $process->run(static function (string $type, string $buffer) use ($output): void {
                $output->write($buffer);
            });

            if (!$process->isSuccessful()) {
                $io->error(sprintf('La commande "%s" a échoué.', implode(' ', $arguments)));

                return Command::FAILURE;
            }
        }

        $io->success('Caches front locaux réinitialisés et assets régénérés.');
        $io->note('Pense à faire un hard refresh du navigateur si le rendu reste étrange.');

        return Command::SUCCESS;
    }

    private function purgeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0777, true);

            return;
        }

        $items = scandir($path);
        if (false === $items) {
            return;
        }

        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $itemPath = $path . '/' . $item;

            if (is_dir($itemPath) && !is_link($itemPath)) {
                $this->purgeDirectory($itemPath);
                rmdir($itemPath);

                continue;
            }

            if (file_exists($itemPath) || is_link($itemPath)) {
                unlink($itemPath);
            }
        }
    }
}
