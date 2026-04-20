<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/settings')]
#[IsGranted('ROLE_USER')]
final class SettingsController extends AbstractController
{
    #[Route('', name: 'app_settings_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('settings/index.html.twig');
    }

    #[Route('/admin/colors', name: 'app_settings_admin_colors', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminColors(KernelInterface $kernel): Response
    {
        $cssPath = $kernel->getProjectDir() . '/public/styles/base-theme.css';
        $cssContent = @file_get_contents($cssPath);

        if ($cssContent === false) {
            throw $this->createNotFoundException('Impossible de lire le fichier base-theme.css');
        }

        $lightTokens = $this->extractColorTokensFromRootBlock($cssContent, '/:root\s*,\s*:root\[data-theme="light"\]\s*\{(.*?)\n\}/s');
        $darkTokens = $this->extractColorTokensFromRootBlock($cssContent, '/:root\[data-theme="dark"\]\s*\{(.*?)\n\}/s');
        $lightTokenMap = $this->indexTokensByName($lightTokens);
        $darkTokenMap = $this->indexTokensByName($darkTokens);
        $colorTripletCatalog = $this->buildColorTripletCatalog($lightTokens, $darkTokens);

        return $this->render('settings/admin_colors.html.twig', [
            'lightTokens' => $lightTokens,
            'darkTokens' => $darkTokens,
            'pairedColorTriplets' => $colorTripletCatalog['paired'],
            'sharedColorTriplets' => $colorTripletCatalog['shared'],
            'dayPreviewStage' => $this->buildPreviewStageTokens($lightTokenMap),
            'nightPreviewStage' => $this->buildPreviewStageTokens($darkTokenMap),
            'cssPath' => 'public/styles/base-theme.css',
        ]);
    }

    /**
     * @param array<int, array{token: string, value: string}> $lightTokens
     * @param array<int, array{token: string, value: string}> $darkTokens
     *
     * @return array<int, array{name: string, dayBg: string, dayText: string, dayBorder: string, nightBg: string, nightText: string, nightBorder: string, dayTokens: string, nightTokens: string}>
     */
    private function buildColorTripletSets(array $lightTokens, array $darkTokens): array
    {
        $lightMap = [];
        foreach ($lightTokens as $token) {
            $lightMap[$token['token']] = $token['value'];
        }

        $darkMap = [];
        foreach ($darkTokens as $token) {
            $darkMap[$token['token']] = $token['value'];
        }

        $groups = [];
        foreach (array_keys($lightMap) as $tokenName) {
            if (!preg_match('/^--([a-z0-9-]+)-(bg|text|border)$/', $tokenName, $matches)) {
                continue;
            }

            $base = $matches[1];
            $kind = $matches[2];
            $groups[$base][$kind] = true;
        }

        $rows = [];
        foreach ($groups as $base => $kinds) {
            if (!isset($kinds['bg'], $kinds['text'], $kinds['border'])) {
                continue;
            }

            $bgToken = '--' . $base . '-bg';
            $textToken = '--' . $base . '-text';
            $borderToken = '--' . $base . '-border';

            $dayBg = $lightMap[$bgToken] ?? '-';
            $dayText = $lightMap[$textToken] ?? '-';
            $dayBorder = $lightMap[$borderToken] ?? '-';

            if ($dayBg === '-' || $dayText === '-' || $dayBorder === '-') {
                continue;
            }

            $nightBg = $darkMap[$bgToken] ?? $dayBg;
            $nightText = $darkMap[$textToken] ?? $dayText;
            $nightBorder = $darkMap[$borderToken] ?? $dayBorder;

            $rows[] = [
                'name' => $base,
                'dayBg' => $dayBg,
                'dayText' => $dayText,
                'dayBorder' => $dayBorder,
                'nightBg' => $nightBg,
                'nightText' => $nightText,
                'nightBorder' => $nightBorder,
                'dayTokens' => $bgToken . ' / ' . $textToken . ' / ' . $borderToken,
                'nightTokens' => $bgToken . ' / ' . $textToken . ' / ' . $borderToken,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return $a['name'] <=> $b['name'];
        });

        return $rows;
    }

    /**
     * @param array<int, array{token: string, value: string}> $lightTokens
     * @param array<int, array{token: string, value: string}> $darkTokens
     *
     * @return array{paired: array<int, array{family: string, label: string, previewLabel: string, dayBg: string, dayText: string, dayBorder: string, nightBg: string, nightText: string, nightBorder: string, dayTokens: string, nightTokens: string}>, shared: array<int, array{family: string, label: string, previewLabel: string, dayBg: string, dayText: string, dayBorder: string, nightBg: string, nightText: string, nightBorder: string, dayTokens: string, nightTokens: string}>}
     */
    private function buildColorTripletCatalog(array $lightTokens, array $darkTokens): array
    {
        $triplets = $this->buildColorTripletSets($lightTokens, $darkTokens);
        $darkMap = $this->indexTokensByName($darkTokens);
        $catalog = [
            'paired' => [],
            'shared' => [],
        ];

        foreach ($triplets as $row) {
            $family = $row['name'];
            $bgToken = '--' . $family . '-bg';
            $textToken = '--' . $family . '-text';
            $borderToken = '--' . $family . '-border';
            $hasDarkEquivalent = isset($darkMap[$bgToken], $darkMap[$textToken], $darkMap[$borderToken]);
            $bucket = $hasDarkEquivalent ? 'paired' : 'shared';
            $entry = [
                'family' => $family,
                'label' => $this->humanizeColorTripletFamily($family),
                'previewLabel' => $this->buildColorTripletPreviewLabel($family),
                'dayBg' => $row['dayBg'],
                'dayText' => $row['dayText'],
                'dayBorder' => $row['dayBorder'],
                'nightBg' => $row['nightBg'],
                'nightText' => $row['nightText'],
                'nightBorder' => $row['nightBorder'],
                'dayTokens' => $row['dayTokens'],
                'nightTokens' => $hasDarkEquivalent ? $row['nightTokens'] : 'Identiques au mode jour',
            ];
            $deduplicationKey = $this->buildColorTripletDeduplicationKey($family);
            $existing = $catalog[$bucket][$deduplicationKey] ?? null;

            if ($existing !== null && $this->getColorTripletFamilyPriority($existing['family']) <= $this->getColorTripletFamilyPriority($family)) {
                continue;
            }

            $catalog[$bucket][$deduplicationKey] = $entry;
        }

        foreach (['paired', 'shared'] as $bucket) {
            $catalog[$bucket] = array_values($catalog[$bucket]);
            usort($catalog[$bucket], static function (array $a, array $b): int {
                return [$a['label'], $a['family']] <=> [$b['label'], $b['family']];
            });
        }

        return $catalog;
    }

    /**
     * @param array<int, array{token: string, value: string}> $tokens
     *
     * @return array<string, string>
     */
    private function indexTokensByName(array $tokens): array
    {
        $map = [];
        foreach ($tokens as $token) {
            $map[$token['token']] = $token['value'];
        }

        return $map;
    }

    /**
     * @param array<string, string> $tokenMap
     *
     * @return array{background: string, border: string, text: string}
     */
    private function buildPreviewStageTokens(array $tokenMap): array
    {
        $backgroundStart = $tokenMap['--indeed-bg-start'] ?? ($tokenMap['--indeed-bg'] ?? '#ffffff');
        $backgroundEnd = $tokenMap['--indeed-bg-end'] ?? $backgroundStart;

        return [
            'background' => sprintf('linear-gradient(180deg, %s 0%%, %s 100%%)', $backgroundStart, $backgroundEnd),
            'border' => $tokenMap['--indeed-border'] ?? 'rgba(148, 163, 184, 0.32)',
            'text' => $tokenMap['--indeed-ink'] ?? '#0f172a',
        ];
    }

    private function humanizeColorTripletFamily(string $family): string
    {
        return ucwords(str_replace('-', ' ', $family));
    }

    private function buildColorTripletPreviewLabel(string $family): string
    {
        $previewFamily = $family;

        foreach (['premium-icon-', 'premium-', 'indeed-', 'ui-', 'pale-'] as $prefix) {
            if (str_starts_with($previewFamily, $prefix)) {
                $previewFamily = substr($previewFamily, strlen($prefix));
                break;
            }
        }

        $label = ucwords(str_replace('-', ' ', $previewFamily));

        return $label !== '' ? $label : 'Apercu';
    }

    private function buildColorTripletDeduplicationKey(string $family): string
    {
        foreach (['ui-', 'premium-icon-'] as $prefix) {
            if (!str_starts_with($family, $prefix)) {
                continue;
            }

            $suffix = substr($family, strlen($prefix));
            if (in_array($suffix, ['info', 'success', 'warning', 'danger'], true)) {
                return $suffix;
            }
        }

        return $family;
    }

    private function getColorTripletFamilyPriority(string $family): int
    {
        if (str_starts_with($family, 'ui-')) {
            return 0;
        }

        if (str_starts_with($family, 'premium-icon-')) {
            return 1;
        }

        return 2;
    }

    /**
     * @return array<int, array{token: string, value: string}>
     */
    private function extractColorTokensFromRootBlock(string $cssContent, string $blockPattern): array
    {
        if (!preg_match($blockPattern, $cssContent, $matches)) {
            return [];
        }

        $blockContent = $matches[1] ?? '';
        if ($blockContent === '') {
            return [];
        }

        preg_match_all('/--([a-z0-9-]+)\s*:\s*([^;]+);/i', $blockContent, $declarations, PREG_SET_ORDER);

        $tokens = [];
        foreach ($declarations as $declaration) {
            $tokenName = '--' . trim((string) ($declaration[1] ?? ''));
            $tokenValue = trim((string) ($declaration[2] ?? ''));

            if ($tokenName === '--' || $tokenValue === '' || !$this->isColorLikeValue($tokenValue)) {
                continue;
            }

            $tokens[] = [
                'token' => $tokenName,
                'value' => $tokenValue,
            ];
        }

        return $tokens;
    }

    private function isColorLikeValue(string $value): bool
    {
        return (bool) preg_match('/#|rgba?\(|hsla?\(|oklch\(|oklab\(|lab\(|lch\(|linear-gradient\(|radial-gradient\(|conic-gradient\(/i', $value);
    }

    /**
     * @param array<int, array{token: string, value: string}> $lightTokens
     *
     * @return array<int, array{class: string, value: string, kind: string}>
     */
    private function buildPaleUtilityClassRows(array $lightTokens): array
    {
        $paleTokenMap = [];

        foreach ($lightTokens as $token) {
            if (!preg_match('/^--pale-([a-z0-9-]+)-(bg|text|border)$/', $token['token'], $matches)) {
                continue;
            }

            $name = $matches[1];
            $kind = $matches[2];
            $paleTokenMap[$name][$kind] = $token['value'];
        }

        ksort($paleTokenMap);

        $rows = [];
        foreach ($paleTokenMap as $name => $kinds) {
            if (isset($kinds['bg'])) {
                $rows[] = [
                    'class' => '.u-bg-pale-' . $name,
                    'value' => $kinds['bg'],
                    'kind' => 'background',
                ];
            }

            if (isset($kinds['text'])) {
                $rows[] = [
                    'class' => '.u-text-pale-' . $name,
                    'value' => $kinds['text'],
                    'kind' => 'text',
                ];
            }

            if (isset($kinds['border'])) {
                $rows[] = [
                    'class' => '.u-border-pale-' . $name,
                    'value' => $kinds['border'],
                    'kind' => 'border',
                ];
            }
        }

        return $rows;
    }

    /**
     * @param array<int, array{token: string, value: string}> $lightTokens
     * @param array<int, array{token: string, value: string}> $darkTokens
     *
     * @return array<int, array{family: string, bgClass: string, textClass: string, borderClass: string, dayBg: string, dayText: string, dayBorder: string, nightBg: string, nightText: string, nightBorder: string}>
     */
    private function buildPaleUtilityFamilyRows(array $lightTokens, array $darkTokens): array
    {
        $lightMap = [];
        foreach ($lightTokens as $token) {
            $lightMap[$token['token']] = $token['value'];
        }

        $darkMap = [];
        foreach ($darkTokens as $token) {
            $darkMap[$token['token']] = $token['value'];
        }

        $families = [];
        foreach (array_keys($lightMap) as $tokenName) {
            if (preg_match('/^--pale-([a-z0-9-]+)-(bg|text|border)$/', $tokenName, $matches)) {
                $families[$matches[1]] = true;
            }
        }

        $familyNames = array_keys($families);
        sort($familyNames, SORT_STRING);

        $rows = [];
        foreach ($familyNames as $family) {
            $bgToken = '--pale-' . $family . '-bg';
            $textToken = '--pale-' . $family . '-text';
            $borderToken = '--pale-' . $family . '-border';

            $rows[] = [
                'family' => $family,
                'bgClass' => '.u-bg-pale-' . $family,
                'textClass' => '.u-text-pale-' . $family,
                'borderClass' => '.u-border-pale-' . $family,
                'dayBg' => $lightMap[$bgToken] ?? '-',
                'dayText' => $lightMap[$textToken] ?? '-',
                'dayBorder' => $lightMap[$borderToken] ?? '-',
                'nightBg' => $darkMap[$bgToken] ?? ($lightMap[$bgToken] ?? '-'),
                'nightText' => $darkMap[$textToken] ?? ($lightMap[$textToken] ?? '-'),
                'nightBorder' => $darkMap[$borderToken] ?? ($lightMap[$borderToken] ?? '-'),
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array{class: string, property: string, value: string}>
     */
    private function extractClassColorRules(string $cssContent): array
    {
        $rows = [];
        $seen = [];

        $cssWithoutComments = preg_replace('/\/\*.*?\*\//s', '', $cssContent) ?? $cssContent;

        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $cssWithoutComments, $rules, PREG_SET_ORDER);

        foreach ($rules as $rule) {
            $selectorBlock = trim((string) ($rule[1] ?? ''));
            $declarationBlock = (string) ($rule[2] ?? '');

            if ($selectorBlock === '' || $declarationBlock === '') {
                continue;
            }

            if (str_contains($selectorBlock, ':root') || str_contains($selectorBlock, '@')) {
                continue;
            }

            preg_match_all('/\.([a-zA-Z0-9_-]+)/', $selectorBlock, $classMatches);
            $classes = array_values(array_unique($classMatches[1] ?? []));
            if ($classes === []) {
                continue;
            }

            preg_match_all('/\b(color|background(?:-color)?|border-color)\s*:\s*([^;]+);/i', $declarationBlock, $declarations, PREG_SET_ORDER);

            foreach ($declarations as $declaration) {
                $property = strtolower(trim((string) ($declaration[1] ?? '')));
                $value = trim((string) ($declaration[2] ?? ''));

                if ($property === '' || $value === '' || !$this->isColorLikeValue($value)) {
                    continue;
                }

                foreach ($classes as $className) {
                    $rowKey = $className . '|' . $property . '|' . $value;
                    if (isset($seen[$rowKey])) {
                        continue;
                    }

                    $seen[$rowKey] = true;
                    $rows[] = [
                        'class' => '.' . $className,
                        'property' => $property,
                        'value' => $value,
                    ];
                }
            }
        }

        usort($rows, static function (array $a, array $b): int {
            return [$a['class'], $a['property'], $a['value']] <=> [$b['class'], $b['property'], $b['value']];
        });

        return $rows;
    }

    /**
     * @param array<int, array{token: string, value: string}> $lightTokens
     * @param array<int, array{token: string, value: string}> $darkTokens
     *
     * @return array<int, array{token: string, light: string, dark: string}>
     */
    private function buildTokenComparisons(array $lightTokens, array $darkTokens): array
    {
        $lightMap = [];
        foreach ($lightTokens as $token) {
            $lightMap[$token['token']] = $token['value'];
        }

        $darkMap = [];
        foreach ($darkTokens as $token) {
            $darkMap[$token['token']] = $token['value'];
        }

        $allTokenNames = array_unique(array_merge(array_keys($lightMap), array_keys($darkMap)));
        sort($allTokenNames, SORT_STRING);

        $rows = [];
        foreach ($allTokenNames as $tokenName) {
            $rows[] = [
                'token' => $tokenName,
                'light' => $lightMap[$tokenName] ?? '-',
                'dark' => $darkMap[$tokenName] ?? '-',
            ];
        }

        return $rows;
    }
}
