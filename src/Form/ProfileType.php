<?php

namespace App\Form;

use App\Entity\User;
use App\Validator\V15Password;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * v15 profile.php / update_customer_profile(): contact details, plus an
 * optional new password. Changing the email or password needs the current password.
 */
class ProfileType extends AbstractType
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var User $user */
        $user = $options['data'];
        // read before the submitted values are written onto the entity
        $currentEmail = (string) $user->getEmail();

        $builder
            ->add('name', TextType::class, ['label' => 'Full name', 'attr' => ['maxlength' => 120, 'autocomplete' => 'name']])
            ->add('email', EmailType::class, ['label' => 'Email address', 'attr' => ['maxlength' => 190, 'autocomplete' => 'email']])
            ->add('phone', TelType::class, ['label' => 'Phone number', 'required' => false, 'attr' => ['maxlength' => 40, 'autocomplete' => 'tel']])
            ->add('currentPassword', PasswordType::class, [
                'label' => 'Current password',
                'mapped' => false,
                'required' => false,
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => [new Assert\Callback(function (?string $password, ExecutionContextInterface $context) use ($user, $currentEmail): void {
                    /** @var FormInterface $form */
                    $form = $context->getRoot();
                    $sensitiveChange = 0 !== strcasecmp((string) $user->getEmail(), $currentEmail)
                        || '' !== (string) $form->get('newPassword')->getData();

                    if ($sensitiveChange && !$this->passwordHasher->isPasswordValid($user, (string) $password)) {
                        $context->addViolation('Your current password is required for email or password changes.');
                    }
                })],
            ])
            ->add('newPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                // hashed in the controller, never stored on the entity
                'mapped' => false,
                'required' => false,
                'first_name' => 'new_password',
                'second_name' => 'new_password_confirmation',
                'first_options' => ['label' => 'New password', 'attr' => ['minlength' => V15Password::MIN_LENGTH, 'autocomplete' => 'new-password']],
                'second_options' => ['label' => 'Confirm new password', 'attr' => ['minlength' => V15Password::MIN_LENGTH, 'autocomplete' => 'new-password']],
                'invalid_message' => 'The new password confirmation does not match.',
                // blank keeps the current password
                'constraints' => [new Assert\When(expression: 'value not in [null, ""]', constraints: [new V15Password()])],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'validation_groups' => ['Default', 'profile'],
        ]);
        $resolver->setRequired('data');
        $resolver->setAllowedTypes('data', User::class);
    }
}
