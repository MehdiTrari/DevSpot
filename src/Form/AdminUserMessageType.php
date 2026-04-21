<?php

namespace App\Form;

use App\Entity\User;
use App\Form\Model\AdminUserMessageData;
use App\Repository\UserRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class AdminUserMessageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var User|null $senderUser */
        $senderUser = $options['sender_user'];

        $builder
            ->add('recipients', EntityType::class, [
                'class' => User::class,
                'multiple' => true,
                'choice_label' => static function (User $user): string {
                    $firstName = $user->getDeveloperProfile()?->getFirstName() ?? $user->getRecruiterProfile()?->getFirstName();
                    $lastName = $user->getDeveloperProfile()?->getLastName() ?? $user->getRecruiterProfile()?->getLastName();
                    $fullName = trim(sprintf('%s %s', (string) $firstName, (string) $lastName));

                    $roleLabel = match (true) {
                        in_array('ROLE_ADMIN', $user->getRoles(), true) => 'Admin',
                        in_array('ROLE_RECRUITER', $user->getRoles(), true) => 'Recruteur',
                        default => 'Candidat',
                    };

                    if ('' !== $fullName) {
                        return sprintf('%s - %s (%s)', $user->getEmail() ?? 'Utilisateur', $fullName, $roleLabel);
                    }

                    return sprintf('%s (%s)', $user->getEmail() ?? 'Utilisateur', $roleLabel);
                },
                'choice_attr' => static function (User $user): array {
                    $firstName = $user->getDeveloperProfile()?->getFirstName() ?? $user->getRecruiterProfile()?->getFirstName();
                    $lastName = $user->getDeveloperProfile()?->getLastName() ?? $user->getRecruiterProfile()?->getLastName();
                    $fullName = trim(sprintf('%s %s', (string) $firstName, (string) $lastName));
                    $email = (string) ($user->getEmail() ?? 'Utilisateur');

                    $roleKey = match (true) {
                        in_array('ROLE_ADMIN', $user->getRoles(), true) => 'admin',
                        in_array('ROLE_RECRUITER', $user->getRoles(), true) => 'recruiter',
                        default => 'applicant',
                    };

                    $roleLabel = match ($roleKey) {
                        'admin' => 'Admin',
                        'recruiter' => 'Recruteur',
                        default => 'Candidat',
                    };

                    return [
                        'data-option-title' => $email,
                        'data-option-description' => '' !== $fullName ? $fullName : 'Profil sans nom complet',
                        'data-option-badge' => $roleLabel,
                        'data-option-badge-tone' => $roleKey,
                        'data-option-tag-label' => '' !== $fullName ? $fullName : $email,
                        'data-option-search' => trim(sprintf('%s %s %s', $email, $fullName, $roleLabel)),
                    ];
                },
                'query_builder' => static function (UserRepository $userRepository) use ($senderUser) {
                    $queryBuilder = $userRepository
                        ->createQueryBuilder('u')
                        ->orderBy('u.email', 'ASC');

                    if ($senderUser instanceof User && null !== $senderUser->getId()) {
                        $queryBuilder
                            ->andWhere('u != :senderUser')
                            ->setParameter('senderUser', $senderUser);
                    }

                    return $queryBuilder;
                },
                'label' => 'Destinataires',
                'help' => 'Sélection directe, sans Ctrl ni Cmd.',
                'attr' => [
                    'data-multiselect-accent' => 'blue',
                    'data-multiselect-search-placeholder' => 'Rechercher par nom, email ou rôle',
                    'data-multiselect-hide-empty-selection' => 'true',
                    'data-multiselect-empty' => 'Aucun destinataire ne correspond à cette recherche.',
                    'data-multiselect-selected-singular' => 'destinataire',
                    'data-multiselect-selected-plural' => 'destinataires',
                    'size' => 10,
                ],
            ])
            ->add('title', TextType::class, [
                'label' => 'Objet',
                'attr' => [
                    'placeholder' => 'Ex : Information importante concernant votre compte',
                ],
            ])
            ->add('content', TextareaType::class, [
                'label' => 'Message',
                'attr' => [
                    'rows' => 8,
                    'placeholder' => 'Rédigez le message qui sera visible dans le centre de notifications de l’utilisateur.',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AdminUserMessageData::class,
            'sender_user' => null,
        ]);

        $resolver->setAllowedTypes('sender_user', ['null', User::class]);
    }
}
