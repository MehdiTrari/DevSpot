<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('accountType', ChoiceType::class, [
                'mapped' => false,
                'label' => 'Type de compte',
                'choices' => [
                    'Postulant' => 'applicant',
                    'Recruteur' => 'recruiter',
                ],
                'expanded' => true,
                'multiple' => false,
                'constraints' => [
                    new NotBlank(message: 'Veuillez choisir un type de compte.'),
                ],
            ])
            ->add('firstName', TextType::class, [
                'mapped' => false,
                'label' => 'Prénom',
                'attr' => ['autocomplete' => 'given-name'],
                'constraints' => [
                    new NotBlank(message: 'Le prénom est obligatoire.'),
                    new Length(max: 255),
                ],
            ])
            ->add('lastName', TextType::class, [
                'mapped' => false,
                'label' => 'Nom',
                'attr' => ['autocomplete' => 'family-name'],
                'constraints' => [
                    new NotBlank(message: 'Le nom est obligatoire.'),
                    new Length(max: 255),
                ],
            ])
            ->add('companyName', TextType::class, [
                'mapped' => false,
                'label' => 'Entreprise',
                'required' => false,
                'attr' => ['autocomplete' => 'organization'],
                'constraints' => [
                    new Length(max: 255),
                ],
            ])
            ->add('workEmail', EmailType::class, [
                'mapped' => false,
                'label' => 'Email professionnel',
                'required' => false,
                'attr' => ['autocomplete' => 'email'],
                'constraints' => [
                    new Email(message: 'Veuillez entrer un email professionnel valide.'),
                    new Length(max: 255),
                ],
            ])
            ->add('email', null, [
                'label' => 'Email de connexion',
                'constraints' => [
                    new Email(
                        message: 'Please enter a valid email address.',
                    ),
                ],
            ])
            ->add('agreeTerms', CheckboxType::class, [
                'mapped' => false,
                'constraints' => [
                    new IsTrue(
                        message: 'You should agree to our terms.',
                    ),
                ],
            ])
            ->add('plainPassword', PasswordType::class, [
                'mapped' => false,
                'attr' => ['autocomplete' => 'new-password'],
                'constraints' => [
                    new NotBlank(
                        message: 'Please enter a password',
                    ),
                    new Regex(
                        pattern: '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).{8,}$/',
                        message: 'Le mot de passe doit contenir au moins 8 caractères, une minuscule, une majuscule, un chiffre et un caractère spécial.',
                    ),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}

