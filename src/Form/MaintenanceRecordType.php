<?php

namespace App\Form;

use App\Entity\MaintenanceRecord;
use App\Entity\Vehicle;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The v15 admin-maintenance.php "Schedule maintenance" form.
 */
class MaintenanceRecordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $invalid = ['invalid_message' => MaintenanceRecord::INVALID];

        $builder
            ->add('vehicle', EntityType::class, [
                'label' => 'Vehicle',
                'class' => Vehicle::class,
                'choice_label' => 'name',
                // v15 vehicle_all(): active vehicles in id order
                'query_builder' => static fn (EntityRepository $repository): QueryBuilder => $repository->createQueryBuilder('v')
                    ->where('v.isActive = true')
                    ->orderBy('v.id', 'ASC'),
            ] + $invalid)
            ->add('title', TextType::class, ['label' => 'Service title', 'attr' => ['maxlength' => 160]])
            ->add('startsAt', DateTimeType::class, [
                'label' => 'Starts',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ] + $invalid)
            ->add('description', TextareaType::class, ['label' => 'Description', 'attr' => ['rows' => 2, 'maxlength' => 3000]])
            ->add('cost', IntegerType::class, ['label' => 'Estimated cost', 'attr' => ['min' => 0]] + $invalid)
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MaintenanceRecord::class,
        ]);
    }
}
