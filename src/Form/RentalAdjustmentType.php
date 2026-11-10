<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The early-return and extension forms of v15 rental-adjustment.php. Values
 * stay plain strings: the constraints catch malformed input, and
 * RentalAdjustmentManager applies the v15 scheduling rules and messages.
 */
class RentalAdjustmentType extends AbstractType
{
    private const INVALID_RETURN = 'Choose a valid return date and time.';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('requested_date', TextType::class, ['empty_data' => '', 'constraints' => [
                new Assert\NotBlank(message: self::INVALID_RETURN),
                new Assert\Date(message: self::INVALID_RETURN),
            ]])
            ->add('requested_time', TextType::class, ['empty_data' => '', 'constraints' => [
                new Assert\NotBlank(message: self::INVALID_RETURN),
                new Assert\Regex('/^(?:[01]\d|2[0-3]):[0-5]\d$/', message: self::INVALID_RETURN),
            ]])
            ->add('reason', TextareaType::class, ['empty_data' => '', 'constraints' => [
                new Assert\Length(max: 1500, maxMessage: 'Keep the reason to {{ limit }} characters or fewer.'),
            ]])
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
