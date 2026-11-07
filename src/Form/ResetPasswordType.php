<?php

namespace App\Form;

use App\Validator\V15Password;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * v15 reset-password.php.
 */
class ResetPasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'first_name' => 'password',
            'second_name' => 'password_confirmation',
            'first_options' => ['label' => 'New password', 'attr' => ['minlength' => V15Password::MIN_LENGTH, 'autocomplete' => 'new-password']],
            'second_options' => ['label' => 'Confirm password', 'attr' => ['minlength' => V15Password::MIN_LENGTH, 'autocomplete' => 'new-password']],
            'invalid_message' => 'The password confirmation does not match.',
            'constraints' => [new V15Password()],
        ]);
    }
}
