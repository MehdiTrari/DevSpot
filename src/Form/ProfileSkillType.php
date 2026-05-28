<?php

namespace App\Form;

use App\Entity\ProfileSkill;
use App\Entity\Skill;
use App\Enum\SkillLevel;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\PositiveOrZero;
use Symfony\Contracts\Translation\TranslatorInterface;

class ProfileSkillType extends AbstractType
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('skill', EntityType::class, [
                'required' => true,
                'label' => 'Competence',
                'class' => Skill::class,
                'choice_label' => fn (Skill $skill) => $this->translateEntityName('skill', $skill->getName()),
                'placeholder' => 'Choisir une competence',
                'constraints' => [
                    new NotNull(message: 'La competence est obligatoire.'),
                ],
            ])
            ->add('level', EnumType::class, [
                'label' => 'Niveau',
                'class' => SkillLevel::class,
                'required' => true,
                'placeholder' => 'Niveau',
                'constraints' => [
                    new NotNull(message: 'Le niveau de competence est obligatoire.'),
                ],
                'choice_label' => static fn (SkillLevel $choice) => match ($choice) {
                    SkillLevel::BEGINNER => 'Debutant',
                    SkillLevel::INTERMEDIATE => 'Intermediaire',
                    SkillLevel::ADVANCED => 'Avance',
                    SkillLevel::EXPERT => 'Expert',
                },
            ])
            ->add('years', IntegerType::class, [
                'label' => 'Annees d\'experience',
                'required' => true,
                'constraints' => [
                    new NotNull(message: 'Le nombre d\'annees est obligatoire.'),
                    new PositiveOrZero(message: 'Le nombre d\'annees doit etre positif ou nul.'),
                ],
                'attr' => [
                    'min' => 0,
                    'inputmode' => 'numeric',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProfileSkill::class,
        ]);
    }

    private function translateEntityName(string $prefix, ?string $value): string
    {
        $value = trim((string) $value);
        if ('' === $value) {
            return '';
        }

        $key = sprintf('%s.%s', $prefix, $this->normalizeTranslationKey($value));
        $translated = $this->translator->trans($key);

        return $translated === $key ? $value : $translated;
    }

    private function normalizeTranslationKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/i', '_', $value) ?? $value;

        return trim($value, '_');
    }
}
