<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\AiMatchingClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AiMatchingClientTest extends TestCase
{
    public function testHealthNormalizesValidPayload(): void
    {
        $client = $this->createClient([
            new MockResponse(json_encode([
                'status' => 'ok',
                'model' => 'camembert',
                'dimension' => 768,
            ], JSON_THROW_ON_ERROR)),
        ]);

        self::assertSame([
            'status' => 'ok',
            'model' => 'camembert',
            'dimension' => 768,
        ], $client->health());
    }

    public function testEmbedBatchUsesBatchEndpointAndThenCache(): void
    {
        $requests = [];
        $responses = [
            new MockResponse(json_encode([
                'items' => [
                    ['embedding' => [0.1, 0.2], 'dimension' => 2, 'normalized_text' => 'first'],
                    ['embedding' => [0.3, 0.4], 'dimension' => 2, 'normalized_text' => 'second'],
                ],
            ], JSON_THROW_ON_ERROR)),
        ];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests, &$responses): MockResponse {
            $requests[] = [$method, $url, $options];

            return array_shift($responses);
        });
        $client = new AiMatchingClient($httpClient, new NullLogger(), new ArrayAdapter(), 'http://ml.test', 10.0);

        $firstCall = $client->embedBatch(['First', 'Second']);
        $secondCall = $client->embedBatch(['First', 'Second']);

        self::assertSame([
            ['embedding' => [0.1, 0.2], 'dimension' => 2, 'normalizedText' => 'first'],
            ['embedding' => [0.3, 0.4], 'dimension' => 2, 'normalizedText' => 'second'],
        ], $firstCall);
        self::assertSame($firstCall, $secondCall);
        self::assertCount(1, $requests);
    }

    public function testEmbedBatchFallsBackToSingleEndpointWhenBatchPayloadIsInvalid(): void
    {
        $requests = [];
        $responses = [
            new MockResponse(json_encode(['items' => 'invalid'], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode(['embedding' => [1, 0], 'dimension' => 2, 'normalized_text' => 'alpha'], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode(['embedding' => [0, 1], 'dimension' => 2, 'normalized_text' => 'beta'], JSON_THROW_ON_ERROR)),
        ];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests, &$responses): MockResponse {
            $requests[] = [$method, $url, $options];

            return array_shift($responses);
        });
        $client = new AiMatchingClient($httpClient, new NullLogger(), new ArrayAdapter(), 'http://ml.test', 10.0);

        $embeddings = $client->embedBatch(['Alpha', 'Beta']);

        self::assertSame([
            ['embedding' => [1.0, 0.0], 'dimension' => 2, 'normalizedText' => 'alpha'],
            ['embedding' => [0.0, 1.0], 'dimension' => 2, 'normalizedText' => 'beta'],
        ], $embeddings);
        self::assertCount(3, $requests);
    }

    public function testInferSkillsBatchNormalizesPayloadAndCachesResults(): void
    {
        $requests = [];
        $responses = [
            new MockResponse(json_encode([
                'items' => [[
                    'inferred_soft_skills' => ['communication'],
                    'inferred_transferable_skills' => ['product mindset'],
                    'inferred_technical_skills' => [
                        ['skill' => 'Symfony', 'level' => 'advanced', 'confidence' => 0.8],
                    ],
                    'confidence' => ['communication' => 0.7],
                    'normalized_text' => 'normalized cv',
                ]],
            ], JSON_THROW_ON_ERROR)),
        ];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests, &$responses): MockResponse {
            $requests[] = [$method, $url, $options];

            return array_shift($responses);
        });
        $client = new AiMatchingClient($httpClient, new NullLogger(), new ArrayAdapter(), 'http://ml.test', 10.0);

        $firstCall = $client->inferSkillsBatch(['CV text']);
        $secondCall = $client->inferSkillsBatch(['CV text']);

        self::assertSame([[
            'inferredSoftSkills' => ['communication'],
            'inferredTransferableSkills' => ['product mindset'],
            'inferredTechnicalSkills' => [
                ['skill' => 'Symfony', 'level' => 'advanced', 'confidence' => 0.8],
            ],
            'confidence' => ['communication' => 0.7],
            'normalizedText' => 'normalized cv',
        ]], $firstCall);
        self::assertSame($firstCall, $secondCall);
        self::assertCount(1, $requests);
    }

    public function testMatchReturnsNullForInvalidPayload(): void
    {
        $client = $this->createClient([
            new MockResponse(json_encode([
                'semantic_score' => 'bad',
                'offer_dimension' => 768,
                'candidate_dimension' => 768,
                'normalized_offer_text' => 'offer',
                'normalized_candidate_text' => 'candidate',
            ], JSON_THROW_ON_ERROR)),
        ]);

        self::assertNull($client->match('offer', 'candidate'));
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function createClient(array $responses): AiMatchingClient
    {
        return new AiMatchingClient(
            new MockHttpClient($responses),
            new NullLogger(),
            new ArrayAdapter(),
            'http://ml.test',
            10.0,
        );
    }
}