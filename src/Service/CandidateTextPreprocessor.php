<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DeveloperProfile;
use App\Entity\Education;
use App\Entity\Experience;
use App\Entity\ProfileSkill;
use App\Entity\Skill;
use Symfony\Component\String\UnicodeString;

final class CandidateTextPreprocessor
{
    /**
     * @var array<string, string>
     */
    private const DISPLAY_REPLACEMENTS = [
        '/\breact(?:\.js|js)?\b/i' => 'React',
        '/\bvue(?:\.js|js)?\b/i' => 'Vue.js',
        '/\bnode(?:\.js|js)?\b/i' => 'Node.js',
        '/\btypescript\b/i' => 'TypeScript',
        '/\bjavascript\b/i' => 'JavaScript',
        '/\bpostgres(?:ql)?\b|\bpostgrela base de donnees\b/i' => 'PostgreSQL',
        '/\bmysql\b|\bmyla base de donnees\b/i' => 'MySQL',
        '/\bapi\s*platform\b/i' => 'API Platform',
        '/\b(?:rest api|api rest)\b/i' => 'REST API',
        '/\bci\s*\/?\s*cd\b/i' => 'CI/CD',
        '/\bgitlab ci\b/i' => 'CI/CD',
        '/\bprometheus\b/i' => 'Observability',
        '/\btailwind css\b/i' => 'Tailwind CSS',
        '/\btesting library\b/i' => 'Testing Library',
        '/\bfigma\b/i' => 'Figma',
        '/\bqa engineer\b|\bqa\b/i' => 'QA',
        '/\bsymfony\b/i' => 'Symfony',
        '/\bdocker\b/i' => 'Docker',
        '/\bkubernetes\b/i' => 'Kubernetes',
        '/\bphp\b/i' => 'PHP',
        '/\bsql\b/i' => 'SQL',
        '/\bhtml\b/i' => 'HTML',
        '/\bcss\b/i' => 'CSS',
        '/\betl\b/i' => 'ETL',
        '/\bfull-stack\b/i' => 'full stack',
        '/\bdevops\b/i' => 'DevOps',
        '/\ble front\b|\bfront end\b/i' => 'frontend',
        '/\ble backend\b|\bback end\b/i' => 'backend',
    ];

    public function buildCandidateText(DeveloperProfile $developer): string
    {
        $sections = $this->filterNonEmpty([
            $this->formatHeadlineSection($developer),
            $this->formatSummarySection($developer),
            $this->formatTargetRolesSection($developer),
            $this->formatSkillsSection($developer, false),
            $this->formatSkillsSection($developer, true),
            $this->formatExperiencesSection($developer),
            $this->formatEducationSection($developer),
        ]);

        return implode("\n\n", $sections);
    }

    private function formatHeadlineSection(DeveloperProfile $developer): ?string
    {
        $headline = $this->cleanText($developer->getHeadline());
        if ('' === $headline) {
            return null;
        }

        return 'Headline: ' . $headline;
    }

    private function formatSummarySection(DeveloperProfile $developer): ?string
    {
        $summaryParts = [];

        $bio = $this->cleanText($developer->getBio());
        if ('' !== $bio) {
            $summaryParts[] = $this->trimSentenceFragment($bio);
        }

        $experienceLevel = $developer->getExperienceLevel()?->value;
        if (null !== $experienceLevel && '' !== trim($experienceLevel)) {
            $summaryParts[] = 'Experience level: ' . $experienceLevel;
        }

        $yearsExperience = $developer->getYearsExperience();
        if (null !== $yearsExperience) {
            $summaryParts[] = sprintf('Years of experience: %d', $yearsExperience);
        }

        if ([] === $summaryParts) {
            return null;
        }

        return 'Summary: ' . implode('. ', $summaryParts);
    }

    private function formatTargetRolesSection(DeveloperProfile $developer): ?string
    {
        $roles = [];

        foreach ($developer->getDesiredPositions() as $position) {
            $role = $this->cleanText($position->getName());
            if ('' !== $role) {
                $roles[] = $role;
            }
        }

        $roles = $this->uniqueValues($roles);
        if ([] === $roles) {
            return null;
        }

        return 'Target roles: ' . implode(', ', $roles);
    }

    private function formatSkillsSection(DeveloperProfile $developer, bool $softSkillsOnly): ?string
    {
        $skills = [];

        foreach ($developer->getProfileSkills() as $profileSkill) {
            $skill = $profileSkill->getSkill();
            if (!$skill instanceof Skill || null === $skill->getName()) {
                continue;
            }

            $isSoftSkill = 'Soft Skills' === $skill->getCategory();
            if ($softSkillsOnly !== $isSoftSkill) {
                continue;
            }

            $formatted = $this->formatSkillDescriptor($profileSkill);
            if (null !== $formatted) {
                $skills[] = $formatted;
            }
        }

        $skills = $this->uniqueValues($skills);
        if ([] === $skills) {
            return null;
        }

        return ($softSkillsOnly ? 'Soft skills: ' : 'Core skills: ') . implode(', ', $skills);
    }

    private function formatExperiencesSection(DeveloperProfile $developer): ?string
    {
        $experiences = $developer->getExperiences()->toArray();
        usort($experiences, [$this, 'compareExperiences']);

        $rows = [];
        foreach ($experiences as $experience) {
            if (!$experience instanceof Experience) {
                continue;
            }

            $title = $this->cleanText($experience->getTitle());
            $description = $this->cleanText($experience->getDescription());
            $technologies = [];

            foreach ($experience->getTechnologies() as $technology) {
                $technologyName = $this->cleanText($technology->getName());
                if ('' !== $technologyName) {
                    $technologies[] = $technologyName;
                }
            }

            $parts = $this->filterNonEmpty([
                '' !== $title ? 'Role: ' . $title : null,
                '' !== $description ? 'Summary: ' . $this->trimSentenceFragment($description) : null,
                [] !== $technologies ? 'Technologies: ' . implode(', ', $this->uniqueValues($technologies)) : null,
                $this->formatExperiencePeriod($experience),
            ]);

            if ([] !== $parts) {
                $rows[] = '- ' . implode('. ', $parts);
            }
        }

        if ([] === $rows) {
            return null;
        }

        return "Experience:\n" . implode("\n", $this->uniqueValues($rows));
    }

    private function formatEducationSection(DeveloperProfile $developer): ?string
    {
        $educationEntries = $developer->getEducation()->toArray();
        usort($educationEntries, [$this, 'compareEducation']);

        $rows = [];
        foreach ($educationEntries as $education) {
            if (!$education instanceof Education) {
                continue;
            }

            $parts = $this->filterNonEmpty([
                '' !== $this->cleanText($education->getDegree()) ? 'Degree: ' . $this->cleanText($education->getDegree()) : null,
                '' !== $this->cleanText($education->getField()) ? 'Field: ' . $this->cleanText($education->getField()) : null,
                '' !== $this->cleanText($education->getDescription()) ? 'Summary: ' . $this->trimSentenceFragment($this->cleanText($education->getDescription())) : null,
            ]);

            if ([] !== $parts) {
                $rows[] = '- ' . implode('. ', $parts);
            }
        }

        if ([] === $rows) {
            return null;
        }

        return "Education:\n" . implode("\n", $this->uniqueValues($rows));
    }

    private function formatSkillDescriptor(ProfileSkill $profileSkill): ?string
    {
        $skill = $profileSkill->getSkill();
        if (!$skill instanceof Skill || null === $skill->getName()) {
            return null;
        }

        $name = $this->cleanText($skill->getName());
        if ('' === $name) {
            return null;
        }

        $details = [];

        $level = $profileSkill->getLevel()?->value;
        if (null !== $level && '' !== trim($level)) {
            $details[] = $level;
        }

        $years = $profileSkill->getYears();
        if (null !== $years && $years > 0) {
            $details[] = sprintf('%d year%s', $years, 1 === $years ? '' : 's');
        }

        if ([] === $details) {
            return $name;
        }

        return sprintf('%s (%s)', $name, implode(', ', $details));
    }

    private function formatExperiencePeriod(Experience $experience): ?string
    {
        $startDate = $experience->getStartDate();
        if (!$startDate instanceof \DateTimeInterface) {
            return null;
        }

        $start = $startDate->format('Y/m');
        if (true === $experience->isCurrent()) {
            return 'Period: ' . $start . ' to present';
        }

        $endDate = $experience->getEndDate();
        if (!$endDate instanceof \DateTimeInterface) {
            return 'Period: ' . $start;
        }

        return sprintf('Period: %s to %s', $start, $endDate->format('Y/m'));
    }

    private function cleanText(?string $value): string
    {
        $cleaned = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $cleaned = (string) preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', ' ', $cleaned);
        $cleaned = (string) preg_replace('/\+?[0-9][0-9\s().-]{7,}/', ' ', $cleaned);
        $cleaned = (string) preg_replace('~https?://[^\s<>"\']+|www\.[^\s<>"\']+~i', ' ', $cleaned);
        $cleaned = (string) preg_replace('/\b(?:contact|portfolio|github|linkedin)\b\s*:?/i', ' ', $cleaned);
        $cleaned = (string) preg_replace('/(?:\s*-\s*)+/u', ' ', $cleaned);

        foreach (self::DISPLAY_REPLACEMENTS as $pattern => $replacement) {
            $cleaned = (string) preg_replace($pattern, $replacement, $cleaned);
        }

        return $this->normalizeWhitespace($cleaned);
    }

    private function normalizeWhitespace(string $value): string
    {
        return trim((new UnicodeString($value))
            ->replaceMatches('/[[:space:]]+/u', ' ')
            ->collapseWhitespace()
            ->toString());
    }

    private function trimSentenceFragment(string $value): string
    {
        return rtrim(trim($value), " .");
    }

    private function normalizeKey(string $value): string
    {
        return (new UnicodeString($value))
            ->ascii()
            ->lower()
            ->collapseWhitespace()
            ->toString();
    }

    /**
     * @param list<string|null> $values
     *
     * @return list<string>
     */
    private function filterNonEmpty(array $values): array
    {
        return array_values(array_filter($values, static fn (?string $value): bool => null !== $value && '' !== trim($value)));
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function uniqueValues(array $values): array
    {
        $unique = [];
        $seen = [];

        foreach ($values as $value) {
            $trimmed = trim($value);
            if ('' === $trimmed) {
                continue;
            }

            $key = $this->normalizeKey($trimmed);
            if ('' === $key || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $trimmed;
        }

        return $unique;
    }

    private function compareExperiences(Experience $left, Experience $right): int
    {
        if ($left->isCurrent() !== $right->isCurrent()) {
            return ($right->isCurrent() ? 1 : 0) <=> ($left->isCurrent() ? 1 : 0);
        }

        return ($right->getStartDate()?->getTimestamp() ?? 0) <=> ($left->getStartDate()?->getTimestamp() ?? 0);
    }

    private function compareEducation(Education $left, Education $right): int
    {
        return ($right->getEndDate()?->getTimestamp() ?? 0) <=> ($left->getEndDate()?->getTimestamp() ?? 0);
    }
}
