<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The v15 payments.php submit form. Values stay plain strings: PaymentManager
 * applies the v15 rules and messages.
 */
class PaymentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('payment_type', TextType::class, ['empty_data' => ''])
            ->add('method', TextType::class, ['empty_data' => ''])
            ->add('amount', TextType::class, ['empty_data' => ''])
            ->add('transaction_reference', TextType::class, ['empty_data' => ''])
            ->add('payment_proof', FileType::class, ['required' => false])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // the booking reference arrives alongside as reference
        $resolver->setDefaults(['allow_extra_fields' => true]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
