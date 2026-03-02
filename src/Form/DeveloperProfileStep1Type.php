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
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class DeveloperProfileStep1Type extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName')
            ->add('lastName')
            ->add('headline')
            ->add('city', null, ['required' => false])
            ->add('country', null, ['required' => false])
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
            ->add('yearsExperience', IntegerType::class, ['required' => false])
            ->add('bio', TextareaType::class, ['required' => false])
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

                        $extension = strtolower($value->getClientOriginalExtension());
                        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
                        if (!in_array($extension, $allowedExtensions, true) || false === @getimagesize($value->getPathname())) {
                            $context->buildViolation('Merci de téléverser une image valide.')->addViolation();
                        }
                    }),
                ],
                'attr' => [
                    'accept' => 'image/*',
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
