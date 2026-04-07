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
                'label' => 'Compétence',
                'class' => Skill::class,
                'choice_label' => fn (Skill $skill) => $this->translateEntityName('skill', $skill->getName()),
                'placeholder' => 'Choisir une compétence',
            ])
            ->add('level', EnumType::class, [
                'label' => 'Niveau',
                'class' => SkillLevel::class,
                'required' => false,
                'placeholder' => 'Niveau',
                'choice_label' => static fn (SkillLevel $choice) => match ($choice) {
                    SkillLevel::BEGINNER => 'Débutant',
                    SkillLevel::INTERMEDIATE => 'Intermédiaire',
                    SkillLevel::ADVANCED => 'Avancé',
                    SkillLevel::EXPERT => 'Expert',
                },
            ])
            ->add('years', IntegerType::class, [
                'label' => 'Années d\'expérience',
                'required' => false,
                'constraints' => [
                    new PositiveOrZero(message: 'Le nombre d\'années doit être positif ou nul.'),
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
