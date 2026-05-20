<?php

namespace App\Form;

use App\Entity\DeveloperProfile;
use App\Entity\Position;
use App\Enum\ExperienceLevel;
use App\Enum\LocationType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DeveloperProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName')
            ->add('lastName')
            ->add('headline')
            ->add('bio', TextareaType::class, [
                'required' => false,
            ])
            ->add('city', null, [
                'required' => false,
            ])
            ->add('country', null, [
                'required' => false,
            ])
            ->add('locationType', EnumType::class, [
                'class' => LocationType::class,
                'required' => false,
                'placeholder' => 'Choisir un mode de travail',
                'choice_label' => static fn (LocationType $choice) => match ($choice) {
                    LocationType::REMOTE => 'Remote',
                    LocationType::HYBRID => 'Hybride',
                    LocationType::ONSITE => 'Sur site',
                },
            ])
            ->add('experienceLevel', EnumType::class, [
                'class' => ExperienceLevel::class,
                'required' => false,
                'placeholder' => "Choisir un niveau d'expérience",
                'choice_label' => static fn (ExperienceLevel $choice) => match ($choice) {
                    ExperienceLevel::INTERN => 'Stagiaire',
                    ExperienceLevel::JUNIOR => 'Junior',
                    ExperienceLevel::MID => 'Confirmé',
                    ExperienceLevel::SENIOR => 'Senior',
                    ExperienceLevel::LEAD => 'Lead',
                },
            ])
            ->add('yearsExperience', IntegerType::class, [
                'required' => false,
            ])
            ->add('isPublic', CheckboxType::class, [
                'required' => false,
            ])
            ->add('githubUrl', UrlType::class, [
                'required' => false,
            ])
            ->add('linkedinUrl', UrlType::class, [
                'required' => false,
            ])
            ->add('portfolioUrl', UrlType::class, [
                'required' => false,
            ])
            ->add('desiredPositions', EntityType::class, [
                'class' => Position::class,
                'choice_label' => 'name',
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'attr' => [
                    'data-multiselect-accent' => 'emerald',
                    'data-multiselect-search-placeholder' => 'Rechercher un poste',
                    'data-multiselect-selection-placeholder' => 'Choisir un ou plusieurs postes',
                    'data-multiselect-empty' => 'Aucun poste ne correspond à cette recherche.',
                    'data-multiselect-selected-singular' => 'poste',
                    'data-multiselect-selected-plural' => 'postes',
                ],
            ])
            ->add('profileSkills', CollectionType::class, [
                'entry_type' => ProfileSkillType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'required' => false,
                'prototype' => true,
            ])
            ->add('experiences', CollectionType::class, [
                'entry_type' => ExperienceType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'required' => false,
                'prototype' => true,
            ])
            ->add('education', CollectionType::class, [
                'entry_type' => EducationType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'required' => false,
                'prototype' => true,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DeveloperProfile::class,
        ]);
    }
}
