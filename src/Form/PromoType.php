<?php

namespace App\Form;

use App\Entity\Promo;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The v15 admin-promos.php editor.
 */
class PromoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $date = [
            'widget' => 'single_text',
            'input' => 'datetime_immutable',
            'required' => false,
            'invalid_message' => 'Use valid promotion dates.',
        ];

        $builder
            ->add('code', TextType::class, ['label' => 'Code', 'attr' => ['maxlength' => 40, 'pattern' => '[A-Za-z0-9_-]+']])
            ->add('description', TextType::class, ['label' => 'Description', 'attr' => ['minlength' => 3, 'maxlength' => 255]])
            ->add('discountType', ChoiceType::class, [
                'label' => 'Type',
                'choices' => ['Percent' => Promo::TYPE_PERCENT, 'Fixed amount' => Promo::TYPE_FIXED],
                'invalid_message' => 'Choose a valid discount type.',
            ])
            ->add('discountValue', IntegerType::class, [
                'label' => 'Value',
                'attr' => ['min' => 1, 'max' => 100000],
                'invalid_message' => 'Enter a valid discount value.',
            ])
            ->add('startsAt', DateType::class, ['label' => 'Starts'] + $date)
            ->add('endsAt', DateType::class, ['label' => 'Ends'] + $date)
            ->add('maxUses', IntegerType::class, [
                'label' => 'Maximum uses',
                'required' => false,
                'attr' => ['min' => 1, 'max' => 1000000, 'placeholder' => 'No limit'],
                'invalid_message' => 'Maximum uses must be a positive whole number or blank.',
            ])
            ->add('isActive', CheckboxType::class, ['label' => 'Promotion is active', 'required' => false])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Promo::class,
        ]);
    }
}
