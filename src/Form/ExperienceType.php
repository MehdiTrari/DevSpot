<?php

namespace App\Form;

use App\Entity\Experience;
use App\Entity\Technology;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Contracts\Translation\TranslatorInterface;

class ExperienceType extends AbstractType
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('companyName', null, [
                'required' => true,
                'label' => 'Entreprise',
                'constraints' => [
                    new NotBlank(message: 'L\'entreprise est obligatoire.'),
                ],
            ])
            ->add('title', null, [
                'required' => true,
                'label' => 'Poste',
                'constraints' => [
                    new NotBlank(message: 'Le poste est obligatoire.'),
                ],
            ])
            ->add('startDate', DateType::class, [
                'required' => true,
                'label' => 'Date de debut',
                'widget' => 'single_text',
                'constraints' => [
                    new NotNull(message: 'La date de debut est obligatoire.'),
                ],
            ])
            ->add('endDate', DateType::class, [
                'label' => 'Date de fin (non applicable si poste actuel)',
                'widget' => 'single_text',
                'required' => false,
                'attr' => [
                    'data-end-date-field' => 'true',
                ],
            ])
            ->add('isCurrent', CheckboxType::class, [
                'label' => 'Poste actuel',
                'required' => false,
                'attr' => [
                    'data-is-current-toggle' => 'true',
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => true,
                'constraints' => [
                    new NotBlank(message: 'La description de l\'experience est obligatoire.'),
                ],
            ])
            ->add('technologies', EntityType::class, [
                'label' => 'Technologies',
                'class' => Technology::class,
                'choice_label' => fn (Technology $technology) => $this->translateEntityName('technology', $technology->getName()),
                'required' => true,
                'multiple' => true,
                'expanded' => false,
                'constraints' => [
                    new Count(min: 1, minMessage: 'Ajoute au moins une technologie.'),
                ],
                'attr' => [
                    'data-multiselect-accent' => 'emerald',
                    'data-multiselect-search-placeholder' => 'Rechercher une technologie',
                    'data-multiselect-selection-placeholder' => 'Choisir les technologies utilisees',
                    'data-multiselect-empty' => 'Aucune technologie ne correspond a cette recherche.',
                    'data-multiselect-selected-singular' => 'technologie',
                    'data-multiselect-selected-plural' => 'technologies',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Experience::class,
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
