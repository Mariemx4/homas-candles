<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\FormInput;
use App\Repositories\CustomRequestRepository;
use DateTimeImmutable;

/**
 * Checks the "record a request" form an administrator fills in after a phone
 * call, social-media message or visit.
 *
 * validate() returns [values, errors] with every field problem at once; the
 * values are ready for CustomRequestRepository::insertAdministratorRequest()
 * except for the generated request number and the customer account ID.
 */
final class CustomRequestIntakeValidator
{
    public function __construct(private readonly CustomRequestRepository $requests)
    {
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public function validate(array $input, int $recordingAdministratorId): array
    {
        $string = static fn (string $key): string => FormInput::trimmed($input, $key);
        $errors = [];

        $name = $string('customer_name');
        if ($name === '' || Text::length($name) > 201) {
            $errors['customer_name'] = 'Enter a customer name of no more than 201 characters.';
        }
        $email = $string('customer_email');
        if ($email !== '' && (Text::length($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errors['customer_email'] = 'Enter a valid email address or leave it blank.';
        }
        $phone = $string('customer_phone');
        if (preg_match('/^[0-9]{8}$/', $phone) !== 1) {
            $errors['customer_phone'] = 'Enter an eight-digit phone number.';
        }
        $method = strtoupper($string('preferred_contact_method'));
        if (!in_array($method, CustomRequestService::CONTACT_METHODS, true)) {
            $errors['preferred_contact_method'] = 'Select a supported contact method.';
        }

        $category = filter_var($string('category_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $categoryIds = array_map(static fn (array $row): int => (int) $row['id'], $this->requests->findCategories());
        if ($category === false || !in_array((int) $category, $categoryIds, true)) {
            $errors['category_id'] = 'Select a valid category.';
        }

        $assignedText = $string('assigned_administrator_id');
        $assigned = $assignedText === ''
            ? $recordingAdministratorId
            : filter_var($assignedText, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        // An omitted owner safely defaults to the administrator recording the
        // request. A supplied but malformed owner must not be silently changed.
        if ($assigned === false) {
            $errors['assigned_administrator_id'] = 'Select an active administrator.';
            $assigned = $recordingAdministratorId;
        }
        if (!$this->requests->activeAdministratorExists((int) $assigned)) {
            $errors['assigned_administrator_id'] = 'Select an active administrator.';
        }

        $budget = $string('budget');
        $budgetMillis = $budget === '' ? null : Money::parseMillis($budget);
        if ($budget !== '' && $budgetMillis === null) {
            $errors['budget'] = 'Enter a positive budget with up to three decimals, or leave it blank.';
        } elseif ($budgetMillis !== null && $budgetMillis <= 0) {
            $errors['budget'] = 'Budget must be greater than zero.';
        }
        $quantityText = $string('requested_quantity');
        $quantity = $quantityText === '' ? null : filter_var($quantityText, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
        if ($quantity === false) {
            $errors['requested_quantity'] = 'Enter a whole quantity greater than zero, or leave it blank.';
        }
        $requiredDate = $string('required_date');
        if ($requiredDate !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $requiredDate);
            if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $requiredDate) {
                $errors['required_date'] = 'Select a valid required date or leave it blank.';
            }
        }

        $preferences = [];
        foreach (['color_preferences', 'scent_preferences', 'packaging_preferences'] as $key) {
            $preferences[$key] = $string($key);
            if (Text::length($preferences[$key]) > 500) {
                $errors[$key] = 'Keep this preference to 500 characters or fewer.';
            }
        }
        $message = $string('customer_message');
        if (Text::length($message) > 10000) {
            $errors['customer_message'] = 'The request message must be 10,000 characters or fewer.';
        }

        return [[
            'category_id' => (int) $category,
            'customer_name' => $name,
            'customer_email' => $email === '' ? null : $email,
            'customer_phone' => $phone,
            'contact_method' => $method,
            // Persist the exact three-decimal representation; never round money
            // through a binary floating-point value.
            'budget' => $budgetMillis === null ? null : Money::formatMillis($budgetMillis),
            'quantity' => $quantityText === '' ? null : (int) $quantity,
            'required_date' => $requiredDate === '' ? null : $requiredDate,
            'color_preferences' => $preferences['color_preferences'] === '' ? null : $preferences['color_preferences'],
            'scent_preferences' => $preferences['scent_preferences'] === '' ? null : $preferences['scent_preferences'],
            'packaging_preferences' => $preferences['packaging_preferences'] === '' ? null : $preferences['packaging_preferences'],
            'customer_message' => $message === '' ? null : $message,
            'administrator_id' => (int) $assigned,
        ], $errors];
    }
}
