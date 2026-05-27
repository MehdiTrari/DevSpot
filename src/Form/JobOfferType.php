<?php

namespace App\Form;

use App\Entity\JobOffer;
use App\Enum\ContractType;
use App\Enum\LocationType;
use App\Enum\OfferStatus;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\PositiveOrZero;

class JobOfferType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Titre de l\'offre',
                'help' => 'Champ obligatoire. Exemple : Développeur PHP/Symfony Senior.',
                'constraints' => [
                    new NotBlank(message: 'Le titre est obligatoire.'),
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'help' => 'Décris le poste, les missions et le profil recherché.',
                'constraints' => [
                    new NotBlank(message: 'La description est obligatoire.'),
                ],
            ])
            ->add('location', TextType::class, [
                'required' => false,
                'label' => 'Localisation',
                'help' => 'Ville, région ou « France entière ».',
            ])
            ->add('locationType', EnumType::class, [
                'class' => LocationType::class,
                'required' => false,
                'label' => 'Mode de travail',
                'placeholder' => 'Choisir un mode de travail',
                'choice_label' => static fn (LocationType $choice) => match ($choice) {
                    LocationType::REMOTE => 'Remote',
                    LocationType::HYBRID => 'Hybride',
                    LocationType::ONSITE => 'Sur site',
                },
            ])
            ->add('contractType', EnumType::class, [
                'class' => ContractType::class,
                'required' => false,
                'label' => 'Type de contrat',
                'placeholder' => 'Choisir un type de contrat',
                'choice_label' => static fn (ContractType $choice) => match ($choice) {
                    ContractType::PERMANENT => 'CDI',
                    ContractType::FIXED_TERM => 'CDD',
                    ContractType::FULL_TIME => 'Temps plein',
                    ContractType::PART_TIME => 'Temps partiel',
                    ContractType::INTERNSHIP => 'Stage',
                    ContractType::APPRENTICESHIP => 'Alternance',
                    ContractType::FREELANCE => 'Freelance',
                    ContractType::CONTRACT => 'Contrat',
                },
            ])
            ->add('experienceLevel', IntegerType::class, [
                'required' => false,
                'label' => 'Années d\'expérience requises',
                'help' => 'Nombre minimum d\'années d\'expérience souhaitées.',
                'constraints' => [
                    new PositiveOrZero(message: 'La valeur doit être positive ou nulle.'),
                ],
                'attr' => [
                    'min' => 0,
                    'inputmode' => 'numeric',
                ],
            ])
            ->add('salaryMin', IntegerType::class, [
                'required' => false,
                'label' => 'Salaire minimum (€/an)',
                'constraints' => [
                    new PositiveOrZero(message: 'La valeur doit être positive ou nulle.'),
                ],
                'attr' => [
                    'min' => 0,
                    'inputmode' => 'numeric',
                    'placeholder' => '30000',
                ],
            ])
            ->add('salaryMax', IntegerType::class, [
                'required' => false,
                'label' => 'Salaire maximum (€/an)',
                'constraints' => [
                    new PositiveOrZero(message: 'La valeur doit être positive ou nulle.'),
                ],
                'attr' => [
                    'min' => 0,
                    'inputmode' => 'numeric',
                    'placeholder' => '55000',
                ],
            ])
            ->add('applicationDeadline', DateType::class, [
                'label' => 'Date limite de candidature',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => true,
                'help' => 'Au lendemain de cette date, l’offre passe automatiquement en expirée/fermée.',
                'constraints' => [
                    new NotBlank(message: 'La date limite est obligatoire.'),
                ],
            ])
            ->add('status', EnumType::class, [
                'class' => OfferStatus::class,
                'label' => 'Statut de l\'offre',
                'choice_label' => static fn (OfferStatus $choice) => match ($choice) {
                    OfferStatus::PUBLISHED => 'Publiée',
                    OfferStatus::DRAFT => 'Brouillon',
                    OfferStatus::CLOSED => 'Fermée',
                },
            ])
        ;

        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
            $offer = $event->getData();
            if (!$offer instanceof JobOffer) {
                return;
            }

            $deadline = $offer->getApplicationDeadline();
            if (
                OfferStatus::PUBLISHED === $offer->getStatus()
                && null !== $deadline
                && $deadline < new \DateTimeImmutable('today')
            ) {
                $event->getForm()->get('applicationDeadline')->addError(new FormError('La date limite doit être aujourd\'hui ou dans le futur pour publier l\'offre.'));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => JobOffer::class,
        ]);
    }
}
