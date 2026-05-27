<?php

namespace App\Form;

use App\Entity\Message;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class ChatReplyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('content', TextType::class, [
            'label' => false,
            'constraints' => [
                new NotBlank(message: 'Le message est obligatoire.'),
                new Length(
                    max: 5000,
                    maxMessage: 'Le message ne peut pas dépasser {{ limit }} caractères.'
                ),
            ],
            'attr' => [
                'maxlength' => 5000,
                'placeholder' => 'Écrire un message...',
                'autocomplete' => 'off',
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Message::class,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'chat_reply',
        ]);
    }
}
