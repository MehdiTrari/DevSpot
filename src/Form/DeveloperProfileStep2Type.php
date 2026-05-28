<?php

namespace App\Form;

use App\Entity\DeveloperProfile;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Valid;

class DeveloperProfileStep2Type extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('profileSkills', CollectionType::class, [
                'entry_type' => ProfileSkillType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'required' => true,
                'prototype' => true,
                'constraints' => [
                    new Count(min: 1, minMessage: 'Ajoute au moins une competence.'),
                    new Valid(),
                ],
            ])
            ->add('experiences', CollectionType::class, [
                'entry_type' => ExperienceType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'required' => true,
                'prototype' => true,
                'constraints' => [
                    new Count(min: 1, minMessage: 'Ajoute au moins une experience.'),
                    new Valid(),
                ],
            ])
            ->add('education', CollectionType::class, [
                'entry_type' => EducationType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'required' => true,
                'prototype' => true,
                'constraints' => [
                    new Count(min: 1, minMessage: 'Ajoute au moins une formation.'),
                    new Valid(),
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
}
