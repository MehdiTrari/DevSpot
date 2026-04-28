<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\DeveloperProfileRepository;
use App\Repository\JobOfferRepository;
use App\Service\CandidateProfileEmbeddingService;
use App\Service\CandidateTextPreprocessor;
use App\Service\EnrichedMatchingService;
use App\Service\OfferMatchingService;
use App\Service\SemanticMatchingService;
use App\Matching\Service\CvAnonymizer;
use App\Matching\Service\FairnessAuditor;
use App\Matching\Service\SkillMatcher;
use App\Repository\PositionRepository;
use App\Repository\SkillRepository;
use App\Repository\TechnologyRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:matching:benchmark',
    description: 'Compare plusieurs valeurs de top-k pour le pipeline de matching actuel.',
)]
final class BenchmarkMatchingCommand extends Command
{
    public function __construct(
        private readonly DeveloperProfileRepository $developerProfileRepository,
        private readonly JobOfferRepository $jobOfferRepository,
        private readonly SkillMatcher $skillMatcher,
        private readonly FairnessAuditor $fairnessAuditor,
        private readonly CvAnonymizer $cvAnonymizer,
        private readonly CandidateTextPreprocessor $candidateTextPreprocessor,
        private readonly CandidateProfileEmbeddingService $candidateProfileEmbeddingService,
        private readonly SemanticMatchingService $semanticMatchingService,
        private readonly EnrichedMatchingService $enrichedMatchingService,
        private readonly SkillRepository $skillRepository,
        private readonly TechnologyRepository $technologyRepository,
        private readonly PositionRepository $positionRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('k', null, InputOption::VALUE_REQUIRED, 'Liste des valeurs top-k a comparer, separees par des virgules.', '30,50,100')
            ->addOption('iterations', null, InputOption::VALUE_REQUIRED, 'Nombre de repetitions par configuration.', '2')
            ->addOption('offers-limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximum d offres a benchmarker.')
            ->addOption('developers-limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximum de profils publics a inclure.')
            ->addOption('demo-only', null, InputOption::VALUE_NONE, 'Limite le benchmark au dataset demo @demo.devspot.local.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $kValues = $this->parseKValues((string) $input->getOption('k'));
        $iterations = max(1, (int) $input->getOption('iterations'));
        $offersLimit = $this->parseNullablePositiveInt($input->getOption('offers-limit'));
        $developersLimit = $this->parseNullablePositiveInt($input->getOption('developers-limit'));
        $demoOnly = (bool) $input->getOption('demo-only');

        if ([] === $kValues) {
            $io->error('Aucune valeur de top-k valide n a ete fournie.');

            return Command::INVALID;
        }

        $developers = $this->developerProfileRepository->findPublicProfilesForMatchingEmbeddings($developersLimit);
        $offers = $this->jobOfferRepository->findActiveForMatching();

        if ($demoOnly) {
            $developers = array_values(array_filter(
                $developers,
                static fn ($developer): bool => str_ends_with((string) $developer->getUser()?->getEmail(), '@demo.devspot.local'),
            ));
            $offers = array_values(array_filter(
                $offers,
                static fn ($offer): bool => str_ends_with((string) $offer->getRecruiterProfile()?->getUser()?->getEmail(), '@demo.devspot.local'),
            ));
        }

        if (null !== $offersLimit) {
            $offers = array_slice($offers, 0, $offersLimit);
        }

        if ([] === $developers || [] === $offers) {
            $io->error('Le benchmark a besoin d au moins un profil public et une offre active.');

            return Command::FAILURE;
        }

        $referenceK = max($kValues);
        $reports = [];
        $topFiveByK = [];

        foreach ($kValues as $k) {
            $service = new OfferMatchingService(
                $this->skillMatcher,
                $this->fairnessAuditor,
                $this->cvAnonymizer,
                $this->candidateTextPreprocessor,
                $this->candidateProfileEmbeddingService,
                $this->semanticMatchingService,
                $this->enrichedMatchingService,
                $this->skillRepository,
                $this->technologyRepository,
                $this->positionRepository,
                $k,
            );

            $totalDurationMs = 0.0;
            $currentTopFive = [];

            for ($iteration = 0; $iteration < $iterations; ++$iteration) {
                foreach ($offers as $offer) {
                    $start = microtime(true);
                    $payload = $service->buildSingleOfferMatch($offer, $developers);
                    $totalDurationMs += (microtime(true) - $start) * 1000;

                    if (0 === $iteration) {
                        $currentTopFive[(string) $offer->getId()] = $this->extractTopFiveIdentifiers($payload['matches']);
                    }
                }
            }

            $topFiveByK[$k] = $currentTopFive;
            $reports[$k] = [
                'k' => $k,
                'iterations' => $iterations,
                'offers' => count($offers),
                'developers' => count($developers),
                'totalDurationMs' => round($totalDurationMs, 1),
                'averageMsPerOffer' => round($totalDurationMs / (count($offers) * $iterations), 1),
            ];
        }

        $referenceTopFive = $topFiveByK[$referenceK] ?? [];
        foreach ($reports as $k => &$report) {
            $overlap = $this->averageTopFiveOverlap($topFiveByK[$k] ?? [], $referenceTopFive);
            $sameTopOneRate = $this->sameTopOneRate($topFiveByK[$k] ?? [], $referenceTopFive);
            $durationGain = $referenceK === $k
                ? 0.0
                : round(($reports[$referenceK]['averageMsPerOffer'] ?? 0.0) - $report['averageMsPerOffer'], 1);

            $report['top5OverlapVsRef'] = round($overlap * 100, 1);
            $report['sameTop1VsRef'] = round($sameTopOneRate * 100, 1);
            $report['avgMsGainVsRef'] = $durationGain;
        }
        unset($report);

        $io->section('Benchmark matching');
        $io->text(sprintf(
            'Jeu mesure: %d offre(s), %d profil(s) public(s), %d iteration(s), reference top-k=%d%s',
            count($offers),
            count($developers),
            $iterations,
            $referenceK,
            $demoOnly ? ', filtre demo-only actif' : '',
        ));

        $table = new Table($output);
        $table->setHeaders(['k', 'avg ms/offre', 'total ms', 'overlap top5 ref', 'same top1 ref', 'gain vs ref']);
        foreach ($kValues as $k) {
            $report = $reports[$k];
            $table->addRow([
                $report['k'],
                number_format((float) $report['averageMsPerOffer'], 1, '.', ''),
                number_format((float) $report['totalDurationMs'], 1, '.', ''),
                number_format((float) $report['top5OverlapVsRef'], 1, '.', '') . '%',
                number_format((float) $report['sameTop1VsRef'], 1, '.', '') . '%',
                ($report['avgMsGainVsRef'] >= 0 ? '+' : '') . number_format((float) $report['avgMsGainVsRef'], 1, '.', '') . ' ms',
            ]);
        }
        $table->render();

        $io->note('L overlap est calcule par rapport au top 5 produit avec la valeur de k la plus elevee demandee.');

        return Command::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function parseKValues(string $input): array
    {
        $values = array_map('trim', explode(',', $input));
        $parsed = [];

        foreach ($values as $value) {
            if (!is_numeric($value)) {
                continue;
            }

            $intValue = (int) $value;
            if ($intValue > 0) {
                $parsed[] = $intValue;
            }
        }

        $parsed = array_values(array_unique($parsed));
        sort($parsed);

        return $parsed;
    }

    private function parseNullablePositiveInt(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        return max(1, (int) $value);
    }

    /**
     * @param list<array<string, mixed>> $matches
     *
     * @return list<string>
     */
    private function extractTopFiveIdentifiers(array $matches): array
    {
        $identifiers = [];

        foreach (array_slice($matches, 0, 5) as $match) {
            $identifiers[] = (string) ($match['developerId'] ?? $match['slug'] ?? $match['fullName'] ?? '');
        }

        return $identifiers;
    }

    /**
     * @param array<string, list<string>> $current
     * @param array<string, list<string>> $reference
     */
    private function averageTopFiveOverlap(array $current, array $reference): float
    {
        if ([] === $reference) {
            return 0.0;
        }

        $sum = 0.0;
        $count = 0;

        foreach ($reference as $offerId => $referenceTopFive) {
            $currentTopFive = $current[$offerId] ?? [];
            $denominator = max(1, min(5, count($referenceTopFive)));
            $sum += count(array_intersect($currentTopFive, $referenceTopFive)) / $denominator;
            ++$count;
        }

        return 0 === $count ? 0.0 : $sum / $count;
    }

    /**
     * @param array<string, list<string>> $current
     * @param array<string, list<string>> $reference
     */
    private function sameTopOneRate(array $current, array $reference): float
    {
        if ([] === $reference) {
            return 0.0;
        }

        $same = 0;
        $count = 0;

        foreach ($reference as $offerId => $referenceTopFive) {
            $referenceTopOne = $referenceTopFive[0] ?? null;
            $currentTopOne = $current[$offerId][0] ?? null;

            if (null === $referenceTopOne) {
                continue;
            }

            if ($referenceTopOne === $currentTopOne) {
                ++$same;
            }

            ++$count;
        }

        return 0 === $count ? 0.0 : $same / $count;
    }
}
