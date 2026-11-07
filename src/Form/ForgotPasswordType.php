<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * v15 forgot-password.php.
 */
class ForgotPasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'Email address',
            'attr' => ['maxlength' => 190, 'autocomplete' => 'email'],
            'constraints' => [
                new Assert\NotBlank(message: 'Enter a valid email address.'),
                new Assert\Email(message: 'Enter a valid email address.'),
            ],
        ]);
    }
}
