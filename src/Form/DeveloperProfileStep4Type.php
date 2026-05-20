<?php

namespace App\Form;

use App\Entity\DeveloperProfile;
use App\Entity\Position;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

class DeveloperProfileStep4Type extends AbstractType
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('desiredPositions', EntityType::class, [
                'class' => Position::class,
                'label' => 'Postes recherchés',
                'choice_label' => fn (Position $position) => $this->translateEntityName('position', $position->getName()),
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'help' => 'Sélection directe, sans Ctrl ni Cmd.',
                'attr' => [
                    'data-multiselect-accent' => 'emerald',
                    'data-multiselect-search-placeholder' => 'Rechercher un poste',
                    'data-multiselect-selection-placeholder' => 'Choisir un ou plusieurs postes',
                    'data-multiselect-empty' => 'Aucun poste ne correspond à cette recherche.',
                    'data-multiselect-selected-singular' => 'poste',
                    'data-multiselect-selected-plural' => 'postes',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DeveloperProfile::class,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'developer_profile';
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
