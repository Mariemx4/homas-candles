<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Business service for Custom Request Service; it contains application rules and coordinates related persistence work.
 *
 * DATA FLOW: Controllers call this layer. It validates and normalizes domain input, calls repositories or storage collaborators, and returns structured results without rendering HTML.
 */

namespace App\Services;

use App\Database\TransactionManager;
use App\Repositories\CustomRequestRepository;
use DateTimeImmutable;
use DomainException;

final class CustomRequestService
{
    public const STATUSES = ['SUBMITTED', 'CONTACTED', 'DISCUSSING', 'AGREED', 'CONVERTED', 'DECLINED', 'CANCELLED'];
    public const CONTACT_METHODS = ['PHONE', 'WHATSAPP', 'INSTAGRAM', 'FACEBOOK', 'TIKTOK', 'EMAIL'];
    public const CONTACT_OUTCOMES = ['NO_ANSWER', 'CONTACTED', 'FOLLOW_UP_REQUIRED', 'AGREEMENT_REACHED', 'DECLINED'];
    public const TERMINAL_STATUSES = ['CONVERTED', 'DECLINED', 'CANCELLED'];
    public const PER_PAGE = 20;

    private const TRANSITIONS = [
        'SUBMITTED' => ['CONTACTED', 'DECLINED', 'CANCELLED'],
        'CONTACTED' => ['DISCUSSING', 'DECLINED', 'CANCELLED'],
        'DISCUSSING' => ['AGREED', 'DECLINED', 'CANCELLED'],
        'AGREED' => ['CANCELLED'],
    ];

    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(
        private readonly CustomRequestRepository $requests,
        private readonly TransactionManager $transactions,
        private readonly ?CustomerModeService $customerModes = null
    ) {
    }

    /**
     * FUNCTION PURPOSE: Prepare a filtered and paginated collection from the supplied query options.
     *
     * @return array<string, mixed>
     */
    public function list(array $query, DateTimeImmutable $now): array
    {
        $categories = $this->requests->findCategories();
        $administrators = $this->requests->findAdministratorsForFilter();
        $normalized = $this->normalizeListQuery($query, $categories, $administrators);
        $total = $this->requests->countForList($normalized['filters']);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($normalized['page'], $totalPages);
        $rows = $total === 0 ? [] : $this->requests->findForList(
            $normalized['filters'],
            ($page - 1) * self::PER_PAGE,
            self::PER_PAGE
        );
        foreach ($rows as &$row) {
            $row['attention'] = $this->attention($row, $now);
        }
        unset($row);
        $state = $this->queryState($normalized, $page);

        return [
            'requests' => $rows,
            'categories' => $categories,
            'administrators' => $administrators,
            'filters' => $normalized['filters'],
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'total' => $total,
            'totalPages' => $totalPages,
            'queryState' => $state,
            // URL IDIOM: Encode an associative array into a valid query string instead of concatenating raw parameter text.
            'returnQuery' => http_build_query($state),
            'filtersAdjusted' => $normalized['adjusted'],
        ];
    }

    /**
     * FUNCTION PURPOSE: Load and assemble all data needed by one detail screen.
     *
     * @return array<string, mixed>|null
     */
    public function detail(int $requestId, DateTimeImmutable $now): ?array
    {
        $request = $this->requests->findSummary($requestId);
        if ($request === null) {
            return null;
        }
        $request['attention'] = $this->attention($request, $now);
        $status = (string) $request['request_status'];

        return [
            'request' => $request,
            'contacts' => $this->requests->findContactHistory($requestId),
            'administrators' => $this->requests->findActiveAdministrators(),
            'allowedTransitions' => self::TRANSITIONS[$status] ?? [],
            'editable' => !in_array($status, self::TERMINAL_STATUSES, true),
        ];
    }

    /**
     * Return the controlled choices needed by the administrator creation form.
     * Keeping option queries here prevents the view from accessing repositories.
     *
     * @return array{categories: list<array<string, mixed>>, administrators: list<array<string, mixed>>}
     */
    public function creationOptions(): array
    {
        return [
            'categories' => $this->requests->findCategories(),
            'administrators' => $this->requests->findActiveAdministrators(),
        ];
    }

    /**
     * Record an enquiry received by phone, social media, or in person.
     *
     * This creates a request, not an order: pricing and final specifications can
     * still be discussed through the normal request workflow before conversion.
     *
     * @return array{success: bool, errors: array<string, string>, requestId?: int, requestNumber?: string}
     */
    public function create(int $recordingAdministratorId, array $input): array
    {
        return $this->transactions->run(fn (): array => $this->createInTransaction($recordingAdministratorId, $input));
    }

    private function createInTransaction(int $recordingAdministratorId, array $input): array
    {
        if (array_key_exists('customer_mode', $input)) {
            if ($this->customerModes === null) return ['success' => false, 'errors' => ['customer_mode' => 'Customer accounts are temporarily unavailable.']];
            [$input, $modeErrors] = $this->customerModes->prepare($input);
            if ($modeErrors !== []) return ['success' => false, 'errors' => $modeErrors];
        }
        $string = static fn (string $key): string => isset($input[$key]) && is_string($input[$key]) ? trim($input[$key]) : '';
        $errors = [];

        $name = $string('customer_name');
        if ($name === '' || $this->length($name) > 201) {
            $errors['customer_name'] = 'Enter a customer name of no more than 201 characters.';
        }
        $email = $string('customer_email');
        if ($email !== '' && ($this->length($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errors['customer_email'] = 'Enter a valid email address or leave it blank.';
        }
        $phone = $string('customer_phone');
        if (preg_match('/^[0-9]{8}$/', $phone) !== 1) {
            $errors['customer_phone'] = 'Enter an eight-digit phone number.';
        }
        $method = strtoupper($string('preferred_contact_method'));
        if (!in_array($method, self::CONTACT_METHODS, true)) {
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
        $budgetMillis = $budget === '' ? null : $this->parseMoney($budget);
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
            if ($this->length($preferences[$key]) > 500) {
                $errors[$key] = 'Keep this preference to 500 characters or fewer.';
            }
        }
        $message = $string('customer_message');
        if ($this->length($message) > 10000) {
            $errors['customer_message'] = 'The request message must be 10,000 characters or fewer.';
        }
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        $requestNumber = 'REQ-A-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $customerId = ($input['customer_mode'] ?? '') === 'existing' ? (int) $input['customer_id'] : null;
        if (($input['customer_mode'] ?? '') === 'new') {
            $customerId = $this->customerModes->createAccount($input);
        }
        $requestId = $this->requests->insertAdministratorRequest([
            'customer_id' => $customerId,
            'request_number' => $requestNumber,
            'category_id' => (int) $category,
            'customer_name' => $name,
            'customer_email' => $email === '' ? null : $email,
            'customer_phone' => $phone,
            'contact_method' => $method,
            // Persist the exact three-decimal representation; never round money
            // through a binary floating-point value.
            'budget' => $budgetMillis === null ? null : $this->millisToMoney($budgetMillis),
            'quantity' => $quantityText === '' ? null : (int) $quantity,
            'required_date' => $requiredDate === '' ? null : $requiredDate,
            'color_preferences' => $preferences['color_preferences'] === '' ? null : $preferences['color_preferences'],
            'scent_preferences' => $preferences['scent_preferences'] === '' ? null : $preferences['scent_preferences'],
            'packaging_preferences' => $preferences['packaging_preferences'] === '' ? null : $preferences['packaging_preferences'],
            'customer_message' => $message === '' ? null : $message,
            'administrator_id' => (int) $assigned,
        ]);

        return ['success' => true, 'errors' => [], 'requestId' => $requestId, 'requestNumber' => $requestNumber];
    }

    /**
     * FUNCTION PURPOSE: Assign the supplied value to the selected owner after validation.
     *
     * @return array{success: bool, errors: array<string, string>}
     */
    public function assign(int $requestId, ?int $administratorId): array
    {
        try {
            // TRANSACTION IDIOM: Run related writes as one unit; an exception rolls back every write in this closure.
            $this->transactions->run(function () use ($requestId, $administratorId): void {
                $request = $this->requests->lockRequest($requestId);
                $this->assertEditable($request);
                if ($administratorId !== null && !$this->requests->activeAdministratorExists($administratorId)) {
                    throw new DomainException('Select an active administrator.');
                }
                $this->requests->assign($requestId, $administratorId);
            });
        } catch (DomainException $exception) {
            return ['success' => false, 'errors' => ['assignment' => $exception->getMessage()]];
        }

        return ['success' => true, 'errors' => []];
    }

    /**
     * FUNCTION PURPOSE: Move the supplied value to an allowed next state and record the change.
     *
     * @return array{success: bool, errors: array<string, string>}
     */
    public function transition(
        int $requestId,
        int $administratorId,
        string $newStatus,
        string $method,
        string $notes
    ): array {
        $errors = [];
        if (!in_array($newStatus, self::STATUSES, true) || $newStatus === 'CONVERTED') {
            $errors['new_status'] = 'Select an available request status.';
        }
        if ($newStatus !== 'CANCELLED' && !in_array($method, self::CONTACT_METHODS, true)) {
            $errors['contact_method'] = 'Select the contact method used.';
        }
        $notes = trim($notes);
        if ($this->length($notes) > 5000) {
            $errors['notes'] = 'Contact notes must be 5,000 characters or fewer.';
        }
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        try {
            // TRANSACTION IDIOM: Run related writes as one unit; an exception rolls back every write in this closure.
            $this->transactions->run(function () use ($requestId, $administratorId, $newStatus, $method, $notes): void {
                $request = $this->requests->lockRequest($requestId);
                $this->assertEditable($request);
                $current = (string) $request['request_status'];
                if (!in_array($newStatus, self::TRANSITIONS[$current] ?? [], true)) {
                    throw new DomainException('That status transition is not available from the current state.');
                }
                if ($newStatus !== 'CANCELLED') {
                    // BRANCHING IDIOM: `match` selects exactly one result using strict comparisons and rejects/handles a default explicitly.
                    $outcome = match ($newStatus) {
                        'AGREED' => 'AGREEMENT_REACHED',
                        'DECLINED' => 'DECLINED',
                        default => 'CONTACTED',
                    };
                    $this->requests->insertContactAttempt(
                        $requestId,
                        $administratorId,
                        $method,
                        $outcome,
                        $notes === '' ? null : $notes,
                        null
                    );
                }
                $this->requests->updateStatus($requestId, $newStatus);
            });
        } catch (DomainException $exception) {
            return ['success' => false, 'errors' => ['new_status' => $exception->getMessage()]];
        }

        return ['success' => true, 'errors' => []];
    }

    /**
     * FUNCTION PURPOSE: Validate and record contact as a new event or movement.
     *
     * @return array{success: bool, errors: array<string, string>}
     */
    public function recordContact(
        int $requestId,
        int $administratorId,
        string $method,
        string $outcome,
        string $notes,
        string $followUp,
        DateTimeImmutable $now
    ): array {
        $errors = [];
        if (!in_array($method, self::CONTACT_METHODS, true)) {
            $errors['contact_method'] = 'Select a valid contact method.';
        }
        if (!in_array($outcome, self::CONTACT_OUTCOMES, true)) {
            $errors['contact_outcome'] = 'Select a valid contact outcome.';
        }
        $notes = trim($notes);
        if ($this->length($notes) > 5000) {
            $errors['notes'] = 'Contact notes must be 5,000 characters or fewer.';
        }
        $followUpValue = null;
        if ($followUp !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $followUp);
            if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d\TH:i') !== $followUp || $date <= $now) {
                $errors['follow_up'] = 'Choose a follow-up time in the future.';
            } else {
                $followUpValue = $date->format('Y-m-d H:i:s');
            }
        }
        if ($outcome === 'FOLLOW_UP_REQUIRED' && $followUpValue === null) {
            $errors['follow_up'] = 'A follow-up time is required for this outcome.';
        }
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        try {
            // TRANSACTION IDIOM: Run related writes as one unit; an exception rolls back every write in this closure.
            $this->transactions->run(function () use (
                $requestId, $administratorId, $method, $outcome, $notes, $followUpValue
            ): void {
                $request = $this->requests->lockRequest($requestId);
                $this->assertEditable($request);
                $this->requests->insertContactAttempt(
                    $requestId,
                    $administratorId,
                    $method,
                    $outcome,
                    $notes === '' ? null : $notes,
                    $followUpValue
                );
            });
        } catch (DomainException $exception) {
            return ['success' => false, 'errors' => ['contact' => $exception->getMessage()]];
        }

        return ['success' => true, 'errors' => []];
    }

    /**
     * FUNCTION PURPOSE: Validate and convert list query into a consistent internal shape.
     *
     * @param list<array<string, mixed>> $categories @param list<array<string, mixed>> $administrators
     */
    private function normalizeListQuery(array $query, array $categories, array $administrators): array
    {
        $adjusted = false;
        $search = $this->queryString($query, 'q', $adjusted);
        $search = trim($search);
        if ($this->length($search) > 100) {
            $search = substr($search, 0, 100);
            $adjusted = true;
        }
        $status = $this->allowQuery($query, 'status', [...self::STATUSES, 'OPEN'], $adjusted);
        // COLLECTION IDIOM: Transform every array item and return a new array without changing the original one.
        $categoryIds = array_map(static fn (array $row): string => (string) $row['id'], $categories);
        $category = $this->allowQuery($query, 'category', $categoryIds, $adjusted);
        // COLLECTION IDIOM: Transform every array item and return a new array without changing the original one.
        $administratorIds = array_map(static fn (array $row): string => (string) $row['id'], $administrators);
        $administratorIds[] = 'unassigned';
        $administrator = $this->allowQuery($query, 'administrator', $administratorIds, $adjusted);
        $dateFrom = $this->queryDate($query, 'date_from', $adjusted);
        $dateTo = $this->queryDate($query, 'date_to', $adjusted);
        if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
            $dateFrom = $dateTo = null;
            $adjusted = true;
        }
        $pageText = $this->queryString($query, 'page', $adjusted);
        // VALIDATION IDIOM: Ask PHP to validate/convert this untrusted scalar instead of relying on a loose type cast.
        $page = $pageText === '' ? 1 : filter_var($pageText, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
        if ($page === false) {
            $page = 1;
            $adjusted = true;
        }

        return ['filters' => [
            'search' => $search === '' ? null : $search,
            'status' => $status, 'category' => $category, 'administrator' => $administrator,
            'date_from' => $dateFrom, 'date_to' => $dateTo,
        ], 'page' => (int) $page, 'adjusted' => $adjusted];
    }

    /**
     * FUNCTION PURPOSE: Read and normalize the state query value.
     *
     * @return array<string, mixed>
     */
    private function queryState(array $normalized, int $page): array
    {
        $filters = $normalized['filters'];
        // COLLECTION IDIOM: Keep only array items for which the callback returns true.
        return array_filter([
            'q' => $filters['search'], 'status' => $filters['status'], 'category' => $filters['category'],
            'administrator' => $filters['administrator'], 'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'], 'page' => $page,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * FUNCTION PURPOSE: Stop the operation unless editable satisfies the required rule.
     *
     * @param array<string, mixed>|null $request
     */
    private function assertEditable(?array $request): void
    {
        if ($request === null) {
            throw new DomainException('The custom request could not be found.');
        }
        if (in_array((string) $request['request_status'], self::TERMINAL_STATUSES, true)) {
            throw new DomainException('Completed, declined, and cancelled requests cannot be edited.');
        }
    }

    /**
     * @return array{label: string, tone: string}
     */
    private function attention(array $request, DateTimeImmutable $now): array
    {
        if (in_array((string) $request['request_status'], self::TERMINAL_STATUSES, true)) {
            return ['label' => 'Closed', 'tone' => 'neutral'];
        }
        $followUp = $request['next_follow_up_at'] ?? null;
        if (is_string($followUp) && $followUp !== '' && $followUp <= $now->format('Y-m-d H:i:s')) {
            return ['label' => 'Follow-up due', 'tone' => 'danger'];
        }
        if ((string) $request['request_status'] === 'SUBMITTED') {
            return ['label' => 'Response needed', 'tone' => 'warning'];
        }
        $requiredDate = $request['required_date'] ?? null;
        if (is_string($requiredDate) && $requiredDate !== '' && $requiredDate < $now->format('Y-m-d')) {
            return ['label' => 'Date overdue', 'tone' => 'danger'];
        }
        if (is_string($followUp) && $followUp !== '') {
            return ['label' => 'Follow-up scheduled', 'tone' => 'info'];
        }

        return ['label' => 'In progress', 'tone' => 'neutral'];
    }

    /**
     * FUNCTION PURPOSE: Read and normalize the string query value.
     */
    private function queryString(array $query, string $key, bool &$adjusted): string
    {
        if (!array_key_exists($key, $query)) {
            return '';
        }
        if (!is_string($query[$key])) {
            $adjusted = true;
            return '';
        }
        return $query[$key];
    }

    /**
     * FUNCTION PURPOSE: Accept query only when it belongs to the allowed set.
     *
     * @param list<string> $allowed
     */
    private function allowQuery(array $query, string $key, array $allowed, bool &$adjusted): ?string
    {
        $value = $this->queryString($query, $key, $adjusted);
        if ($value === '') {
            return null;
        }
        if (!in_array($value, $allowed, true)) {
            $adjusted = true;
            return null;
        }
        return $value;
    }

    /**
     * FUNCTION PURPOSE: Read and normalize the date query value.
     */
    private function queryDate(array $query, string $key, bool &$adjusted): ?string
    {
        $value = $this->queryString($query, $key, $adjusted);
        if ($value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            $adjusted = true;
            return null;
        }
        return $value;
    }

    /**
     * FUNCTION PURPOSE: Count characters in the supplied value safely for UTF-8 validation.
     */
    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    /** Convert a validated TND string into integer thousandths exactly. */
    private function parseMoney(string $value): ?int
    {
        if (preg_match('/^(?:0|[1-9][0-9]{0,8})(?:\.([0-9]{1,3}))?$/', $value, $matches) !== 1) {
            return null;
        }

        return ((int) explode('.', $value)[0] * 1000)
            + (int) str_pad($matches[1] ?? '', 3, '0');
    }

    /** Convert integer thousandths back to the database DECIMAL(12,3) shape. */
    private function millisToMoney(int $millis): string
    {
        return intdiv($millis, 1000) . '.' . str_pad((string) ($millis % 1000), 3, '0', STR_PAD_LEFT);
    }
}
