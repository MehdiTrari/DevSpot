<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\MatchingPreviewController;
use App\Matching\Service\CvAnonymizer;
use App\Matching\Service\FairnessAuditor;
use App\Matching\Service\SkillMatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;

final class MatchingPreviewControllerTest extends TestCase
{
    public function testInvokeReturnsPreviewPayloadWithAnonymizedCv(): void
    {
        $controller = new MatchingPreviewController(new SkillMatcher(), new CvAnonymizer(), new FairnessAuditor());
        $controller->setContainer(new Container());

        $request = Request::create(
            '/api/matching/preview',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'offer' => [
                    'id' => 'offer-1',
                    'title' => 'Backend Symfony',
                    'requiredHardSkills' => ['PHP', 'Symfony'],
                    'desiredSoftSkills' => ['Communication'],
                    'description' => 'API role',
                ],
                'candidates' => [[
                    'id' => 'cand-1',
                    'yearsOfExperience' => 1,
                    'hardSkills' => ['php', 'symfony'],
                    'softSkills' => ['Communication'],
                    'rawCv' => 'alice@example.com +33 6 12 34 56 78',
                ]],
            ], JSON_THROW_ON_ERROR),
        );

        $response = $controller($request);
        $payload = json_decode($response->getContent() ?: '[]', true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('offer-1', $payload['offer']['id']);
        self::assertSame('cand-1', $payload['matches'][0]['candidateId']);
        self::assertSame(1, $payload['matches'][0]['score']);
        self::assertStringContainsString('[EMAIL]', $payload['matches'][0]['anonymizedCv']);
        self::assertStringContainsString('[PHONE]', $payload['matches'][0]['anonymizedCv']);
        self::assertSame(1, $payload['fairness']['disparate_impact_ratio']);
    }
}