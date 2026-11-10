<?php

namespace App\Form;

use App\Entity\Vehicle;
use App\Service\VehicleImageStorage;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

/**
 * The v15 admin-vehicles.php editor.
 */
class VehicleType extends AbstractType
{
    public function __construct(
        private readonly VehicleImageStorage $vehicleImages,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = static fn (array $values): array => array_combine($values, $values);

        $builder
            ->add('name', TextType::class, ['label' => 'Name', 'attr' => ['maxlength' => 180]])
            ->add('slug', TextType::class, ['label' => 'URL slug', 'attr' => ['maxlength' => 140]])
            ->add('brand', TextType::class, ['label' => 'Brand', 'attr' => ['maxlength' => 100]])
            ->add('category', ChoiceType::class, ['label' => 'Category', 'choices' => $choices(Vehicle::CATEGORIES)])
            ->add('price', IntegerType::class, ['label' => 'Daily rate', 'attr' => ['min' => 500, 'max' => 100000]])
            ->add('deposit', IntegerType::class, ['label' => 'Deposit', 'attr' => ['min' => 0, 'max' => 100000]])
            ->add('dailyKm', IntegerType::class, ['label' => 'Daily km', 'attr' => ['min' => 0, 'max' => 2000]])
            ->add('seats', IntegerType::class, ['label' => 'Seats', 'attr' => ['min' => 1, 'max' => 30]])
            ->add('availabilityStatus', ChoiceType::class, [
                'label' => 'Fleet status',
                'choices' => array_combine(array_map('ucfirst', Vehicle::AVAILABILITY_STATUSES), Vehicle::AVAILABILITY_STATUSES),
            ])
            ->add('doors', IntegerType::class, ['label' => 'Doors', 'attr' => ['min' => 1, 'max' => 8]])
            ->add('transmission', ChoiceType::class, ['label' => 'Transmission', 'choices' => $choices(Vehicle::TRANSMISSIONS)])
            ->add('fuel', ChoiceType::class, ['label' => 'Fuel', 'choices' => $choices(Vehicle::FUELS)])
            ->add('luggage', TextType::class, ['label' => 'Luggage guide'])
            ->add('image', TextType::class, [
                'label' => 'Existing image filename',
                'required' => false,
                'attr' => ['placeholder' => 'vehicle-slug.png'],
            ])
            ->add('vehicleImage', FileType::class, [
                'label' => 'Upload replacement image',
                'mapped' => false,
                'required' => false,
                'attr' => ['accept' => 'image/png,image/jpeg,image/webp'],
                'help' => 'PNG, JPG, or WebP, up to 8 MB. Existing files are not automatically deleted.',
                'constraints' => [
                    new File(
                        maxSize: '8M',
                        mimeTypes: ['image/png', 'image/jpeg', 'image/webp'],
                        maxSizeMessage: 'The uploaded file is larger than allowed.',
                        mimeTypesMessage: 'The uploaded file type is not allowed.',
                    ),
                ],
            ])
            ->add('overviewTitle', TextType::class, ['label' => 'Overview heading', 'attr' => ['maxlength' => 255]])
            ->add('overview', TextareaType::class, ['label' => 'Overview', 'attr' => ['rows' => 4]])
            ->add('description', TextareaType::class, ['label' => 'Description', 'attr' => ['rows' => 4]])
            ->add('inclusions', TextareaType::class, ['label' => 'Inclusions—one per line', 'attr' => ['rows' => 7]])
            ->add('features', TextareaType::class, ['label' => 'Features—one per line', 'attr' => ['rows' => 7]])
            ->add('isActive', CheckboxType::class, ['label' => 'Active and bookable', 'required' => false])
        ;

        $lines = new CallbackTransformer(
            static fn (?array $items): string => implode("\n", $items ?? []),
            static fn (?string $text): array => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $text) ?: []))),
        );
        $builder->get('inclusions')->addModelTransformer($lines);
        $builder->get('features')->addModelTransformer($lines);

        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->applyV15Rules(...));
    }

    /**
     * v15 rules that span several fields: an inactive vehicle is always "unavailable",
     * and the image must be a new upload or a file that already exists.
     */
    private function applyV15Rules(FormEvent $event): void
    {
        $form = $event->getForm();
        /** @var Vehicle $vehicle */
        $vehicle = $event->getData();

        if (!$vehicle->isActive()) {
            $vehicle->setAvailabilityStatus('unavailable');
        }

        $vehicle->setImage(basename((string) $vehicle->getImage()) ?: null);
        $hasUpload = null !== $form->get('vehicleImage')->getData();

        if (!$hasUpload && !$this->vehicleImages->exists($vehicle->getImage())) {
            $form->get('image')->addError(new FormError('Choose an uploaded image or enter an existing filename from assets/images/cars.'));
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Vehicle::class,
        ]);
    }
}
