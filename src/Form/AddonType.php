<?php

namespace App\Form;

use App\Entity\Addon;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The v15 admin-addons.php editor. Billing is fixed to "One-time per booking".
 */
class AddonType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('addonKey', TextType::class, ['label' => 'Key', 'attr' => ['maxlength' => 80, 'placeholder' => 'child-seat']])
            ->add('name', TextType::class, ['label' => 'Name', 'attr' => ['maxlength' => 140]])
            ->add('price', IntegerType::class, ['label' => 'Price', 'attr' => ['min' => 0]])
            ->add('icon', TextType::class, ['label' => 'Bootstrap icon class', 'required' => false, 'attr' => ['maxlength' => 80]])
            ->add('isActive', CheckboxType::class, ['label' => 'Available during booking', 'required' => false])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Addon::class,
        ]);
    }
}
