<?php

namespace App\Form;

use App\Entity\ContactMessage;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Blank;

/**
 * The v15 contact.php enquiry form, including its hidden "website" honeypot.
 */
class ContactType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Full name *', 'attr' => ['maxlength' => 120, 'autocomplete' => 'name']])
            ->add('email', EmailType::class, ['label' => 'Email address *', 'attr' => ['maxlength' => 190, 'autocomplete' => 'email']])
            ->add('phone', TelType::class, ['label' => 'Mobile number', 'required' => false, 'attr' => ['maxlength' => 40, 'autocomplete' => 'tel']])
            ->add('subject', ChoiceType::class, [
                'label' => 'What can we help with? *',
                'placeholder' => 'Select a topic',
                'choices' => array_combine(ContactMessage::SUBJECTS, ContactMessage::SUBJECTS),
                'invalid_message' => 'Choose a valid support topic.',
            ])
            ->add('message', TextareaType::class, [
                'label' => 'Message *',
                'attr' => ['rows' => 6, 'minlength' => 20, 'maxlength' => 5000, 'placeholder' => 'Tell us about your trip or question'],
            ])
            ->add('website', TextType::class, [
                'mapped' => false,
                'required' => false,
                'label' => false,
                'attr' => ['class' => 'visually-hidden', 'tabindex' => '-1', 'autocomplete' => 'off', 'aria-hidden' => 'true'],
                'constraints' => [new Blank(message: 'Message rejected.')],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ContactMessage::class,
        ]);
    }
}
