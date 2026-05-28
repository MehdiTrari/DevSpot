<?php

namespace App\Form;

use App\Entity\Education;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;

class EducationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('schoolName', null, [
                'required' => true,
                'label' => 'Ecole',
                'constraints' => [
                    new NotBlank(message: 'Le nom de l\'ecole est obligatoire.'),
                ],
            ])
            ->add('degree', null, [
                'label' => 'Diplome',
                'required' => true,
                'constraints' => [
                    new NotBlank(message: 'Le diplome est obligatoire.'),
                ],
            ])
            ->add('field', null, [
                'label' => 'Domaine',
                'required' => true,
                'constraints' => [
                    new NotBlank(message: 'Le domaine est obligatoire.'),
                ],
            ])
            ->add('startDate', DateType::class, [
                'label' => 'Date de debut',
                'widget' => 'single_text',
                'required' => true,
                'constraints' => [
                    new NotNull(message: 'La date de debut est obligatoire.'),
                ],
            ])
            ->add('endDate', DateType::class, [
                'label' => 'Date de fin',
                'widget' => 'single_text',
                'required' => true,
                'constraints' => [
                    new NotNull(message: 'La date de fin est obligatoire.'),
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => true,
                'constraints' => [
                    new NotBlank(message: 'La description de la formation est obligatoire.'),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Education::class,
        ]);
    }
}
