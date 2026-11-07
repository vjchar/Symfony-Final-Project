<?php

namespace App\Form;

use App\Form\Model\BookingRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The v15 booking.php form. Fields keep v15's names (pickup, addons[],
 * addon_quantities[child-seat], ...) because booking-vehicle-preview.js reads
 * them, and stay plain strings: BookingCalculator applies the v15 rules.
 */
class BookingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $text = static fn (string $property): array => ['property_path' => $property, 'empty_data' => ''];

        $builder
            ->add('vehicle', TextType::class, $text('vehicle'))
            ->add('pickup', TextType::class, $text('pickupDate'))
            ->add('pickup_time', TextType::class, $text('pickupTime'))
            ->add('return', TextType::class, $text('returnDate'))
            ->add('return_time', TextType::class, $text('returnTime'))
            ->add('pickup_method', TextType::class, $text('pickupMethod'))
            ->add('location', TextType::class, $text('location'))
            ->add('delivery_address', TextType::class, $text('deliveryAddress'))
            ->add('addons', CollectionType::class, [
                'entry_type' => TextType::class,
                'entry_options' => ['empty_data' => ''],
                'allow_add' => true,
                'allow_delete' => true,
            ])
            ->add(
                $builder->create('addon_quantities', FormType::class, ['inherit_data' => true])
                    ->add('child-seat', TextType::class, $text('childSeats'))
            )
            ->add('promo', TextType::class, $text('promo'))
            ->add('special_requests', TextType::class, $text('specialRequests'))
            ->add('terms', CheckboxType::class)
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BookingRequest::class,
            // the clicked button arrives as form_action
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
