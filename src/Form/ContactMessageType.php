<?php

namespace App\Form;

use App\Entity\ContactMessage;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class ContactMessageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('recruiterName', TextType::class, [
                'label' => 'Nom',
                'constraints' => [
                    new NotBlank(message: 'Le nom est obligatoire.'),
                    new Length(max: 255),
                ],
            ])
            ->add('recruiterEmail', EmailType::class, [
                'label' => 'Email',
                'constraints' => [
                    new NotBlank(message: 'L email est obligatoire.'),
                    new Email(message: 'Merci de saisir un email valide.'),
                    new Length(max: 255),
                ],
            ])
            ->add('subject', TextType::class, [
                'label' => 'Sujet',
                'required' => false,
                'empty_data' => '',
                'constraints' => [
                    new Length(max: 255),
                ],
            ])
            ->add('message', TextareaType::class, [
                'label' => 'Message',
                'constraints' => [
                    new NotBlank(message: 'Le message est obligatoire.'),
                    new Length(min: 10, max: 5000, minMessage: 'Le message doit contenir au moins {{ limit }} caracteres.'),
                ],
                'attr' => [
                    'rows' => 6,
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ContactMessage::class,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'contact_message',
        ]);
    }
}
