<?php

namespace App\Form;

use App\Entity\Vehicle;
use App\Form\Model\VehicleFilter;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The v15 "Refine results" fleet filter. Field names match v15's query string
 * (?search=&category=&brand=...). An unknown value fails transformation and
 * leaves that filter at its default, which is how v15 resets invalid input;
 * a missing value falls back to the same default through empty_data.
 */
class VehicleFilterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = static fn (string $allLabel, array $values): array => [$allLabel => 'All'] + array_combine($values, $values);

        $builder
            ->add('search', SearchType::class, [
                'label' => 'Search',
                'required' => false,
                'attr' => ['placeholder' => 'Brand or model'],
            ])
            ->add('category', ChoiceType::class, [
                'label' => 'Category',
                'choices' => $choices('All types', Vehicle::CATEGORIES),
                'empty_data' => 'All',
            ])
            ->add('brand', ChoiceType::class, [
                'label' => 'Brand',
                'choices' => $choices('All brands', $options['brands']),
                'empty_data' => 'All',
            ])
            ->add('fuel', ChoiceType::class, [
                'label' => 'Fuel type',
                'choices' => $choices('All fuel types', ['Gasoline', 'Diesel', 'Electric', 'Hybrid']),
                'empty_data' => 'All',
            ])
            ->add('seats', ChoiceType::class, [
                'label' => 'Minimum seats',
                'choices' => VehicleFilter::SEAT_OPTIONS,
                'empty_data' => '0',
            ])
            ->add('transmission', ChoiceType::class, [
                'label' => 'Transmission',
                'choices' => $choices('Any transmission', Vehicle::TRANSMISSIONS),
                'empty_data' => 'All',
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Fleet status',
                'choices' => ['All operational states' => 'All'] + array_combine(array_map('ucwords', VehicleFilter::STATUS_OPTIONS), VehicleFilter::STATUS_OPTIONS),
                'empty_data' => 'All',
            ])
            ->add('max_price', ChoiceType::class, [
                'label' => 'Maximum daily rate',
                'property_path' => 'maxPrice',
                'choices' => VehicleFilter::PRICE_OPTIONS,
                'empty_data' => '0',
            ])
            ->add('sort', ChoiceType::class, [
                'label' => 'Sort results',
                'choices' => VehicleFilter::SORT_OPTIONS,
                'empty_data' => 'featured',
            ])
            ->add('from', DateType::class, [
                'label' => 'Pick-up date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'attr' => ['min' => (new \DateTimeImmutable('today'))->format('Y-m-d')],
            ])
            ->add('to', DateType::class, [
                'label' => 'Return date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'attr' => ['min' => (new \DateTimeImmutable('today'))->format('Y-m-d')],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => VehicleFilter::class,
            'method' => 'GET',
            'csrf_protection' => false,
            'allow_extra_fields' => true,
            'brands' => [],
        ]);
        $resolver->setAllowedTypes('brands', 'array');
    }

    // Plain v15 parameter names: ?category=SUV instead of ?vehicle_filter[category]=SUV.
    public function getBlockPrefix(): string
    {
        return '';
    }
}
