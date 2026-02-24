<?php

namespace App\Form;

use App\Entity\ProfileSkill;
use App\Entity\Skill;
use App\Enum\SkillLevel;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProfileSkillType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('skill', EntityType::class, [
                'class' => Skill::class,
                'choice_label' => 'name',
                'placeholder' => 'Choisir une compétence',
            ])
            ->add('level', EnumType::class, [
                'class' => SkillLevel::class,
                'required' => false,
                'placeholder' => 'Niveau',
                'choice_label' => static fn (SkillLevel $choice) => match ($choice) {
                    SkillLevel::BEGINNER => 'Débutant',
                    SkillLevel::INTERMEDIATE => 'Intermédiaire',
                    SkillLevel::ADVANCED => 'Avancé',
                    SkillLevel::EXPERT => 'Expert',
                },
            ])
            ->add('years', IntegerType::class, [
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProfileSkill::class,
        ]);
    }
}
