<?php

namespace App\Form;

use App\Entity\User;
use App\Validator\V15Password;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;

/**
 * v15 register.php (customers) and setup.php (the first administrator, with accept_terms: false).
 */
class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Full name', 'attr' => ['maxlength' => 120, 'autocomplete' => 'name']])
            ->add('email', EmailType::class, ['label' => 'Email address', 'attr' => ['maxlength' => 190, 'autocomplete' => 'email']])
            ->add('phone', TelType::class, ['label' => 'Phone number', 'required' => false, 'attr' => ['maxlength' => 40, 'autocomplete' => 'tel']])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                // read and hashed in the controller, never stored on the entity
                'mapped' => false,
                'first_name' => 'password',
                'second_name' => 'password_confirmation',
                'first_options' => ['label' => 'Password', 'attr' => ['minlength' => V15Password::MIN_LENGTH, 'autocomplete' => 'new-password']],
                'second_options' => ['label' => 'Confirm password', 'attr' => ['minlength' => V15Password::MIN_LENGTH, 'autocomplete' => 'new-password']],
                'invalid_message' => 'The password confirmation does not match.',
                'constraints' => [new V15Password()],
            ])
        ;

        if ($options['accept_terms']) {
            $builder->add('terms', CheckboxType::class, [
                'mapped' => false,
                'constraints' => [new IsTrue(message: 'You must accept the terms and privacy policy.')],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'validation_groups' => ['Default', 'registration'],
            'accept_terms' => true,
        ]);
        $resolver->setAllowedTypes('accept_terms', 'bool');
    }
}
