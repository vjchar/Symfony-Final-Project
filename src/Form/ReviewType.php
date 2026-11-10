<?php

namespace App\Form;

use App\Entity\Review;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * The v15 rate-trip.php review form. Values stay plain strings: ReviewManager
 * applies the v15 rules and messages.
 */
class ReviewType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('reference', TextType::class, ['empty_data' => '']);
        foreach (['overall', ...array_keys(Review::CATEGORIES)] as $field) {
            $builder->add($field, TextType::class, ['empty_data' => '']);
        }
        $builder
            ->add('title', TextType::class, ['empty_data' => ''])
            ->add('body', TextareaType::class, ['empty_data' => ''])
            ->add('consent', TextType::class, ['empty_data' => ''])
            ->add('photos', FileType::class, ['multiple' => true, 'required' => false])
        ;
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
