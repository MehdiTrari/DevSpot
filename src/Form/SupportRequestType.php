<?php

namespace App\Form;

use App\Entity\SupportRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

final class SupportRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('subject', TextType::class, [
                'label' => 'Objet',
                'constraints' => [
                    new NotBlank(message: 'Merci de renseigner un objet.'),
                    new Length(
                        max: 255,
                        maxMessage: 'L objet ne peut pas depasser {{ limit }} caracteres.'
                    ),
                ],
                'attr' => [
                    'placeholder' => 'Ex : Probleme d acces a mon espace',
                ],
            ])
            ->add('message', TextareaType::class, [
                'label' => 'Message',
                'constraints' => [
                    new NotBlank(message: 'Merci de renseigner un message.'),
                    new Length(
                        min: 10,
                        max: 5000,
                        minMessage: 'Le message doit contenir au moins {{ limit }} caracteres.',
                        maxMessage: 'Le message ne peut pas depasser {{ limit }} caracteres.'
                    ),
                ],
                'attr' => [
                    'rows' => 8,
                    'maxlength' => 5000,
                    'placeholder' => 'Expliquez votre probleme ou votre demande avec le plus de contexte possible.',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SupportRequest::class,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'support_request',
        ]);
    }
}
