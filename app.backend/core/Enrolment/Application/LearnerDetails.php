<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application;

use Illuminate\Validation\ValidationException;

/** The checks on a learner's own details, shared by admitting and correcting. */
final class LearnerDetails
{
    /**
     * @param  array{first_name: string, other_names: string|null, surname: string, sex: string, date_of_birth: string}  $input
     * @return array{first_name: string, other_names: string|null, surname: string, sex: string, date_of_birth: string}
     */
    public static function check(array $input, string $today): array
    {
        $errors = [];
        $first = self::name($input['first_name']);
        $other = self::name((string) $input['other_names']);
        $surname = self::name($input['surname']);

        if ($first === '' || mb_strlen($first) > 80) {
            $errors['first_name'] = ['Enter the first name, up to 80 characters.'];
        }

        if (mb_strlen($other) > 120) {
            $errors['other_names'] = ['Other names can be up to 120 characters.'];
        }

        if ($surname === '' || mb_strlen($surname) > 80) {
            $errors['surname'] = ['Enter the surname, up to 80 characters.'];
        }

        if (! in_array($input['sex'], ['female', 'male'], true)) {
            $errors['sex'] = ['Choose female or male.'];
        }

        $born = null;

        try {
            $born = self::day($input['date_of_birth'], 'date_of_birth', 'The date of birth');
        } catch (ValidationException $e) {
            $errors = [...$errors, ...$e->errors()];
        }

        if ($born !== null && ($born >= $today || $born < '1900-01-01')) {
            $errors['date_of_birth'] = ['Enter a date of birth in the past.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['first_name' => $first, 'other_names' => $other !== '' ? $other : null, 'surname' => $surname, 'sex' => $input['sex'], 'date_of_birth' => (string) $born];
    }

    /** A calendar day as YYYY-MM-DD, or a refusal naming the field. */
    public static function day(string $value, string $field, string $what): string
    {
        $value = trim($value);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw ValidationException::withMessages([$field => ["{$what} is not a valid date."]]);
        }

        return $value;
    }

    /** Spaces made single, ends trimmed. */
    public static function name(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
