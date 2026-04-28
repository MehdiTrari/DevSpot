<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\DeveloperProfileRepository;
use App\Service\CandidateProfileEmbeddingService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:matching:refresh-embeddings',
    description: 'Pre-calcule et stocke les embeddings de matching des profils publics.',
)]
final class RefreshMatchingEmbeddingsCommand extends Command
{
    public function __construct(
        private readonly DeveloperProfileRepository $developerProfileRepository,
        private readonly CandidateProfileEmbeddingService $candidateProfileEmbeddingService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Recalcule tous les embeddings, meme si le hash du texte n a pas change.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximum de profils publics a traiter.')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Taille des batchs envoyes au service IA.', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limitOption = $input->getOption('limit');
        $limit = is_numeric($limitOption) ? max(1, (int) $limitOption) : null;
        $force = (bool) $input->getOption('force');
        $batchSizeOption = $input->getOption('batch-size');
        $batchSize = is_numeric($batchSizeOption) ? max(1, (int) $batchSizeOption) : 10;

        $profiles = $this->developerProfileRepository->findPublicProfilesForMatchingEmbeddings($limit);
        if ([] === $profiles) {
            $io->warning('Aucun profil public a traiter.');

            return Command::SUCCESS;
        }

        $stats = $this->candidateProfileEmbeddingService->refreshEmbeddings($profiles, $force, true, $batchSize);

        $io->success(sprintf(
            'Embeddings candidats mis a jour. Profils traites: %d, refresh: %d, skip: %d, echec: %d.',
            $stats['processed'],
            $stats['refreshed'],
            $stats['skipped'],
            $stats['failed'],
        ));

        return Command::SUCCESS;
    }
}
