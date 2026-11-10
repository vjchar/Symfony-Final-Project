<?php

namespace App\Form;

use App\Entity\User;
use App\Validator\V15Password;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * An administrator creating an account (any role, with a starting password)
 * or correcting an account's name, email and phone. Role and status changes
 * on existing accounts stay on the user list, where the last-admin rules live.
 */
class AdminUserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Full name', 'attr' => ['maxlength' => 120, 'autocomplete' => 'off']])
            ->add('email', EmailType::class, ['label' => 'Email address', 'attr' => ['maxlength' => 190, 'autocomplete' => 'off']])
            ->add('phone', TelType::class, ['label' => 'Phone number', 'required' => false, 'attr' => ['maxlength' => 40, 'autocomplete' => 'off']])
        ;

        if ($options['is_new']) {
            $builder
                ->add('role', ChoiceType::class, [
                    'label' => 'Role',
                    'choices' => ['Customer' => User::ROLE_CUSTOMER, 'Staff' => User::ROLE_STAFF, 'Admin' => User::ROLE_ADMIN],
                    'invalid_message' => 'Choose a valid role.',
                ])
                ->add('plainPassword', RepeatedType::class, [
                    'type' => PasswordType::class,
                    // hashed by AdminUserManager, never stored on the entity
                    'mapped' => false,
                    'first_name' => 'password',
                    'second_name' => 'password_confirmation',
                    'first_options' => ['label' => 'Password', 'attr' => ['minlength' => V15Password::MIN_LENGTH, 'autocomplete' => 'new-password']],
                    'second_options' => ['label' => 'Confirm password', 'attr' => ['minlength' => V15Password::MIN_LENGTH, 'autocomplete' => 'new-password']],
                    'invalid_message' => 'The password confirmation does not match.',
                    'constraints' => [new V15Password()],
                ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'is_new' => false,
            // the "registration" and "profile" groups carry the two unique-email messages
            'validation_groups' => static fn ($form): array => ['Default', $form->getConfig()->getOption('is_new') ? 'registration' : 'profile'],
        ]);
        $resolver->setAllowedTypes('is_new', 'bool');
    }
}
