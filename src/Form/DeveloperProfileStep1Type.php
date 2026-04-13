<?php

namespace App\Form;

use App\Entity\DeveloperProfile;
use App\Enum\ExperienceLevel;
use App\Enum\LocationType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\PositiveOrZero;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class DeveloperProfileStep1Type extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', null, [
                'label' => 'Prénom',
                'help' => 'Champ obligatoire.',
            ])
            ->add('lastName', null, [
                'label' => 'Nom',
                'help' => 'Champ obligatoire.',
            ])
            ->add('headline', null, [
                'label' => 'Titre professionnel',
                'help' => 'Champ obligatoire. Exemple : Développeur Symfony / React.',
            ])
            ->add('city', null, [
                'required' => false,
                'label' => 'Ville',
            ])
            ->add('country', null, [
                'required' => false,
                'label' => 'Pays',
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
            ->add('experienceLevel', EnumType::class, [
                'class' => ExperienceLevel::class,
                'required' => false,
                'label' => 'Niveau d\'expérience',
                'placeholder' => 'Choisir un niveau d\'expérience',
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
                'label' => 'Années d\'expérience',
                'help' => 'Indique un nombre positif ou nul.',
                'constraints' => [
                    new PositiveOrZero(message: 'Le nombre d\'années d\'expérience doit être positif ou nul.'),
                ],
                'attr' => [
                    'min' => 0,
                    'inputmode' => 'numeric',
                ],
            ])
            ->add('bio', TextareaType::class, [
                'required' => false,
                'label' => 'Présentation',
                'help' => 'Présente ton parcours, tes forces et ce que tu recherches.',
            ])
            ->add('avatarFile', FileType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'Photo de profil',
                'help' => 'Formats acceptes : JPG, JPEG, PNG, WEBP, GIF. Taille max : 4 Mo.',
                'constraints' => [
                    new Callback(static function (mixed $value, ExecutionContextInterface $context): void {
                        if (!$value instanceof UploadedFile) {
                            return;
                        }

                        if ($value->getSize() > 4 * 1024 * 1024) {
                            $context->buildViolation('L image ne doit pas dépasser 4 Mo.')->addViolation();

                            return;
                        }

                        $imageInfo = @getimagesize($value->getPathname());
                        $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
                        $detectedMimeType = is_array($imageInfo) ? ($imageInfo['mime'] ?? null) : null;

                        if (null === $detectedMimeType || !in_array($detectedMimeType, $allowedMimeTypes, true)) {
                            $context->buildViolation('Merci de téléverser une image valide.')->addViolation();
                        }
                    }),
                ],
                'attr' => [
                    'accept' => '.jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif',
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
