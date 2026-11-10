<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The early-return and extension forms of v15 rental-adjustment.php. Values
 * stay plain strings: RentalAdjustmentManager applies the v15 rules and messages.
 */
class RentalAdjustmentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('requested_date', TextType::class, ['empty_data' => ''])
            ->add('requested_time', TextType::class, ['empty_data' => ''])
            ->add('reason', TextareaType::class, ['empty_data' => ''])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // the clicked button arrives as action
        $resolver->setDefaults(['allow_extra_fields' => true]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
