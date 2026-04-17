<?php

declare(strict_types=1);

namespace App\Tests\Matching\Service;

use App\Matching\Model\CandidateProfile;
use App\Matching\Model\JobOffer;
use App\Matching\Service\SkillMatcher;
use PHPUnit\Framework\TestCase;

final class SkillMatcherTest extends TestCase
{
    public function testItRanksCandidatesByScore(): void
    {
        $matcher = new SkillMatcher();

        $offer = new JobOffer(
            'offer-1',
            'Junior Symfony Developer',
            ['php', 'symfony', 'sql'],
            ['communication'],
            'Build Symfony APIs',
        );

        $junior = new CandidateProfile(
            'cand-1',
            1,
            ['PHP', 'Symfony', 'SQL'],
            ['communication'],
            'Junior profile',
        );

        $senior = new CandidateProfile(
            'cand-2',
            5,
            ['PHP', 'Symfony'],
            ['communication'],
            'Senior profile',
        );

        $results = $matcher->rankCandidates($offer, [$senior, $junior]);

        self::assertSame('cand-1', $results[0]->candidate->id);
        self::assertGreaterThan($results[1]->score, $results[0]->score);
    }
}
