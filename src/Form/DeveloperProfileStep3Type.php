<?php

namespace App\Form;

use App\Entity\DeveloperProfile;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\Url;

class DeveloperProfileStep3Type extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('githubUrl', UrlType::class, [
                'required' => true,
                'label' => 'GitHub',
                'help' => 'Lien public vers ton profil GitHub.',
                'constraints' => [
                    new NotBlank(message: 'Le lien GitHub est obligatoire.'),
                    new Url(
                        protocols: ['https'],
                        message: 'Le lien GitHub doit etre une URL HTTPS valide.',
                    ),
                    new Regex(
                        pattern: '#^$|^https://github\.com/.+#i',
                        message: 'Le lien GitHub doit commencer par https://github.com/',
                    ),
                ],
            ])
            ->add('linkedinUrl', UrlType::class, [
                'required' => true,
                'label' => 'LinkedIn',
                'help' => 'Lien public vers ton profil LinkedIn.',
                'constraints' => [
                    new NotBlank(message: 'Le lien LinkedIn est obligatoire.'),
                    new Url(
                        protocols: ['https'],
                        message: 'Le lien LinkedIn doit etre une URL HTTPS valide.',
                    ),
                    new Regex(
                        pattern: '#^$|^https://www\.linkedin\.com/in/.+#i',
                        message: 'Le lien LinkedIn doit commencer par https://www.linkedin.com/in/',
                    ),
                ],
            ])
            ->add('portfolioUrl', UrlType::class, [
                'required' => true,
                'label' => 'Portfolio externe',
                'help' => 'Lien public vers ton portfolio.',
                'constraints' => [
                    new NotBlank(message: 'Le portfolio est obligatoire.'),
                    new Url(
                        protocols: ['https'],
                        message: 'Le portfolio doit etre une URL HTTPS valide (https://...).',
                    ),
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
