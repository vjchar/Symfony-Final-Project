<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * The v15 booking-cancellation.php form. The reason stays a plain string:
 * BookingCancellationManager applies the v15 rule and message.
 */
class BookingCancellationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('reason', TextareaType::class, ['empty_data' => '']);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
