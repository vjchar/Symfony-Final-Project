<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

/**
 * v15 password_errors(): at least 8 characters with an uppercase letter, a lowercase letter and a number.
 */
#[\Attribute]
final class V15Password extends Compound
{
    public const MIN_LENGTH = 8;

    protected function getConstraints(array $options): array
    {
        return [
            new Assert\NotBlank(message: 'Password must contain at least '.self::MIN_LENGTH.' characters.'),
            new Assert\Length(min: self::MIN_LENGTH, max: 4096, minMessage: 'Password must contain at least '.self::MIN_LENGTH.' characters.'),
            new Assert\Regex('/[A-Z]/', message: 'Password must include an uppercase letter.'),
            new Assert\Regex('/[a-z]/', message: 'Password must include a lowercase letter.'),
            new Assert\Regex('/\d/', message: 'Password must include a number.'),
        ];
    }
}
