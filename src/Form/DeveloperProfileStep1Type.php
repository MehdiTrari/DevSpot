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
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\PositiveOrZero;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class DeveloperProfileStep1Type extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', null, [
                'required' => true,
                'label' => 'Prenom',
                'help' => 'Champ obligatoire.',
                'constraints' => [
                    new NotBlank(message: 'Le prenom est obligatoire.'),
                ],
            ])
            ->add('lastName', null, [
                'required' => true,
                'label' => 'Nom',
                'help' => 'Champ obligatoire.',
                'constraints' => [
                    new NotBlank(message: 'Le nom est obligatoire.'),
                ],
            ])
            ->add('headline', null, [
                'required' => true,
                'label' => 'Titre professionnel',
                'help' => 'Champ obligatoire. Exemple : Developpeur Symfony / React.',
                'constraints' => [
                    new NotBlank(message: 'Le titre professionnel est obligatoire.'),
                ],
            ])
            ->add('city', null, [
                'required' => true,
                'label' => 'Ville',
                'constraints' => [
                    new NotBlank(message: 'La ville est obligatoire.'),
                ],
            ])
            ->add('country', null, [
                'required' => true,
                'label' => 'Pays',
                'constraints' => [
                    new NotBlank(message: 'Le pays est obligatoire.'),
                ],
            ])
            ->add('locationType', EnumType::class, [
                'class' => LocationType::class,
                'required' => true,
                'label' => 'Mode de travail',
                'placeholder' => 'Choisir un mode de travail',
                'constraints' => [
                    new NotNull(message: 'Le mode de travail est obligatoire.'),
                ],
                'choice_label' => static fn (LocationType $choice) => match ($choice) {
                    LocationType::REMOTE => 'Remote',
                    LocationType::HYBRID => 'Hybride',
                    LocationType::ONSITE => 'Sur site',
                },
            ])
            ->add('experienceLevel', EnumType::class, [
                'class' => ExperienceLevel::class,
                'required' => true,
                'label' => 'Niveau d\'experience',
                'placeholder' => 'Choisir un niveau d\'experience',
                'constraints' => [
                    new NotNull(message: 'Le niveau d\'experience est obligatoire.'),
                ],
                'choice_label' => static fn (ExperienceLevel $choice) => match ($choice) {
                    ExperienceLevel::INTERN => 'Stagiaire',
                    ExperienceLevel::JUNIOR => 'Junior',
                    ExperienceLevel::MID => 'Confirme',
                    ExperienceLevel::SENIOR => 'Senior',
                    ExperienceLevel::LEAD => 'Lead',
                },
            ])
            ->add('yearsExperience', IntegerType::class, [
                'required' => true,
                'label' => 'Annees d\'experience',
                'help' => 'Indique un nombre positif ou nul.',
                'constraints' => [
                    new NotNull(message: 'Le nombre d\'annees d\'experience est obligatoire.'),
                    new PositiveOrZero(message: 'Le nombre d\'annees d\'experience doit etre positif ou nul.'),
                ],
                'attr' => [
                    'min' => 0,
                    'inputmode' => 'numeric',
                ],
            ])
            ->add('bio', TextareaType::class, [
                'required' => true,
                'label' => 'Presentation',
                'help' => 'Presente ton parcours, tes forces et ce que tu recherches.',
                'constraints' => [
                    new NotBlank(message: 'La presentation est obligatoire.'),
                ],
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
                            $context->buildViolation('L\'image ne doit pas depasser 4 Mo.')->addViolation();

                            return;
                        }

                        $imageInfo = @getimagesize($value->getPathname());
                        $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
                        $detectedMimeType = is_array($imageInfo) ? ($imageInfo['mime'] ?? null) : null;

                        if (null === $detectedMimeType || !in_array($detectedMimeType, $allowedMimeTypes, true)) {
                            $context->buildViolation('Merci de televerser une image valide.')->addViolation();
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
