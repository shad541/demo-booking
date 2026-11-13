<?php

namespace App\Rules;

use Closure;
use DateTimeImmutable;
use Exception;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class Iso8601Timestamp implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $pattern = '/\A[0-9]{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])T(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9](?:\.[0-9]{1,6})?)?(?:Z|[+-](?:[01][0-9]|2[0-3])(?::?[0-5][0-9])?)\z/';

        if (! is_string($value) || ! preg_match($pattern, $value) || str_starts_with($value, '0000-')) {
            $fail('Поле :attribute должно содержать дату и время ISO 8601 с явным смещением (например, 2026-11-02T10:00:00+03:00).');

            return;
        }

        try {
            new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();

            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                $fail('Поле :attribute содержит несуществующую дату или время.');
            }
        } catch (Exception) {
            $fail('Поле :attribute содержит некорректную дату или время.');
        }
    }
}
