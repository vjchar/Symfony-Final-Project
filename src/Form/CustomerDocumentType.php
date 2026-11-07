<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One document upload form of v15 documents.php. Values stay plain strings:
 * DocumentManager applies the v15 rules and messages.
 */
class CustomerDocumentType extends AbstractType
{
    public const CSRF_TOKEN_ID = 'customer-document';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('document_type', TextType::class, ['empty_data' => ''])
            ->add('document_number', TextType::class, ['empty_data' => ''])
            ->add('expiry_date', TextType::class, ['empty_data' => ''])
            ->add('document_file', FileType::class, ['required' => false])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // the booking the customer came from arrives alongside as booking_reference;
        // the page shows one form per document type, so they share a named token
        $resolver->setDefaults(['allow_extra_fields' => true, 'csrf_token_id' => self::CSRF_TOKEN_ID]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
