<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A customer closing their own account confirms with their password.
 */
class AccountClosureType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('password', PasswordType::class, [
                'label' => 'Current password',
                'mapped' => false,
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => [new UserPassword(message: 'Your current password is not correct.')],
            ])
            ->add('confirm', CheckboxType::class, [
                'label' => 'I understand my documents, favorites, saved bookings and notifications are deleted and I can no longer sign in.',
                'mapped' => false,
                'constraints' => [new Assert\IsTrue(message: 'Confirm that you want to close your account.')],
            ])
        ;
    }
}
