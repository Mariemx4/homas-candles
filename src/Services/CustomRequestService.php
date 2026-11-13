<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\TransactionManager;
use App\Repositories\CustomRequestRepository;
use DateTimeImmutable;
use DomainException;

/**
 * Administrator workflow for custom product requests (enquiries that are
 * discussed before they become an order).
 *
 * Owner: custom requests (CustomRequestController). Responsibilities:
 * - the filtered request list and the request detail view data;
 * - recording a request received by phone/social media/in person (create);
 * - correcting a working brief, with optimistic concurrency (briefVersion)
 *   and an append-only revision history;
 * - assignment, status transitions (see TRANSITIONS) and contact attempts.
 *
 * Requested-product definitions are validated by CustomRequestDefinitionValidator
 * so creation and correction accept exactly the same data; the rest of the
 * intake form by CustomRequestIntakeValidator. Inbox filters live in
 * CustomRequestListFilters and the status badge in CustomRequestAttention. Converting an agreed
 * request into an order belongs to CustomRequestConversionService.
 *
 * Writes run inside TransactionManager::run(); DomainException aborts a
 * transaction with a message the administrator can read.
 */
final class CustomRequestService
{
    public const STATUSES = ['SUBMITTED', 'CONTACTED', 'DISCUSSING', 'AGREED', 'CONVERTED', 'DECLINED', 'CANCELLED'];
    public const CONTACT_METHODS = ['PHONE', 'WHATSAPP', 'INSTAGRAM', 'FACEBOOK', 'TIKTOK', 'EMAIL'];
    public const CONTACT_OUTCOMES = ['NO_ANSWER', 'CONTACTED', 'FOLLOW_UP_REQUIRED', 'AGREEMENT_REACHED', 'DECLINED'];
    public const TERMINAL_STATUSES = ['CONVERTED', 'DECLINED', 'CANCELLED'];
    public const PER_PAGE = 20;

    private readonly CustomRequestDefinitionValidator $definitions;
    private readonly CustomRequestIntakeValidator $intake;

    private const TRANSITIONS = [
        'SUBMITTED' => ['CONTACTED', 'DECLINED', 'CANCELLED'],
        'CONTACTED' => ['DISCUSSING', 'DECLINED', 'CANCELLED'],
        'DISCUSSING' => ['AGREED', 'DECLINED', 'CANCELLED'],
        'AGREED' => ['CANCELLED'],
    ];

    public function __construct(
        private readonly CustomRequestRepository $requests,
        private readonly TransactionManager $transactions,
        private readonly ?CustomerModeService $customerModes = null
    ) {
        // Built here so the constructor used by the bootstrap and tests stays unchanged.
        $this->definitions = new CustomRequestDefinitionValidator($requests);
        $this->intake = new CustomRequestIntakeValidator($requests);
    }

    /** The filtered, paginated request inbox with an attention badge per request. */
    public function list(array $query, DateTimeImmutable $now): array
    {
        $categories = $this->requests->findCategories();
        $administrators = $this->requests->findAdministratorsForFilter();
        $normalized = CustomRequestListFilters::normalize($query, $categories, $administrators);
        $total = $this->requests->countForList($normalized['filters']);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($normalized['page'], $totalPages);
        $rows = $total === 0 ? [] : $this->requests->findForList(
            $normalized['filters'],
            ($page - 1) * self::PER_PAGE,
            self::PER_PAGE
        );
        foreach ($rows as &$row) {
            $row['attention'] = CustomRequestAttention::for($row, $now);
        }
        unset($row);
        $state = CustomRequestListFilters::queryState($normalized, $page);

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
            'returnQuery' => http_build_query($state),
            'filtersAdjusted' => $normalized['adjusted'],
        ];
    }

    /** Preserve validated filter input even when the inbox cannot reach its data. */
    public function unavailableList(array $query): array
    {
        $selectedOption = static function (mixed $value, string $name): array {
            if (!is_string($value) || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                return [];
            }
            return [['id' => $value, 'name' => $name]];
        };
        $categories = $selectedOption($query['category'] ?? null, 'Selected category (unavailable)');
        $administrators = $selectedOption($query['administrator'] ?? null, 'Selected administrator (unavailable)');
        $normalized = CustomRequestListFilters::normalize($query, $categories, $administrators);
        $state = CustomRequestListFilters::queryState($normalized, $normalized['page']);

        return [
            'requests' => [], 'categories' => $categories, 'administrators' => $administrators,
            'filters' => $normalized['filters'],
            'page' => $normalized['page'], 'perPage' => self::PER_PAGE,
            'total' => null, 'totalPages' => 1,
            'queryState' => $state, 'returnQuery' => http_build_query($state),
            'filtersAdjusted' => $normalized['adjusted'],
        ];
    }

    /**
     * Everything the request page shows: summary, products, contacts, allowed
     * next statuses and a page of brief revisions. Null if the request does not exist.
     */
    public function detail(int $requestId, DateTimeImmutable $now, int $revisionPage = 1): ?array
    {
        $request = $this->requests->findSummary($requestId);
        if ($request === null) {
            return null;
        }
        $request['custom_products'] = $this->requests->findRequestProducts($requestId);
        $request['production_completed'] = $request['converted_order_id'] !== null && $this->requests->productionCompleted((int) $request['converted_order_id']);
        $request['attention'] = CustomRequestAttention::for($request, $now);
        $status = (string) $request['request_status'];
        $revisionPages = max(1, (int) ceil($this->requests->briefRevisionCount($requestId) / 3));
        $revisionPage = max(1, min($revisionPage, $revisionPages));

        return [
            'request' => $request,
            'contacts' => $this->requests->findContactHistory($requestId),
            'administrators' => $this->requests->findActiveAdministrators(),
            'allowedTransitions' => self::TRANSITIONS[$status] ?? [],
            'editable' => empty($request['archived_at']) && !in_array($status, self::TERMINAL_STATUSES, true),
            'briefEditable' => self::briefEditable($request),
            'briefVersion' => self::briefVersion($request),
            'briefRevisions' => $this->requests->briefRevisions($requestId, $revisionPage),
            'revisionPage' => $revisionPage, 'revisionPages' => $revisionPages,
        ];
    }

    /** Whether the working brief may still be corrected: active, unconverted and not yet agreed. */
    public static function briefEditable(array $request): bool
    {
        return empty($request['archived_at']) && $request['converted_order_id'] === null
            && in_array($request['request_status'], ['SUBMITTED', 'CONTACTED', 'DISCUSSING'], true);
    }

    private static function briefSnapshot(array $request): array
    {
        return array_intersect_key($request, array_flip(['category_id', 'product_id', 'requested_quantity',
            'budget', 'required_date', 'color_preferences', 'scent_preferences', 'packaging_preferences',
            'customer_message', 'custom_products']));
    }

    /** A fingerprint of the brief; editBrief() refuses to save when it changed in another tab. */
    public static function briefVersion(array $request): string
    {
        return hash('sha256', json_encode(self::briefSnapshot($request), JSON_THROW_ON_ERROR));
    }

    /** Correct a working brief; agreement and conversion lock its definitions. */
    public function editBrief(int $requestId, int $actor, array $input): array
    {
        try {
            return $this->transactions->run(function () use ($requestId, $actor, $input): array {
                $request = $this->requests->lockRequest($requestId);
                if ($request === null || !self::briefEditable($request)) {
                    throw new DomainException('Brief corrections are available only before agreement, on an active, unconverted request.');
                }
                $request['custom_products'] = $this->requests->findRequestProducts($requestId);
                if (!is_string($input['version'] ?? null) || !hash_equals(self::briefVersion($request), $input['version'])) {
                    throw new DomainException('This brief changed in another tab. Your entries are retained below. Reload the current brief before applying them.');
                }
                $reason = is_string($input['revision_reason'] ?? null) ? trim($input['revision_reason']) : '';
                if ($reason === '' || Text::length($reason) > 500) {
                    return ['success' => false,'errors' => ['revision_reason' => 'Explain this correction in 500 characters or fewer.']];
                }
                if ($request['custom_products'] === []) {
                    throw new DomainException('This request has no separate product definitions. Record clarifications in Contact history before agreeing the final quotation.');
                }
                $prepared = $this->definitions->validate($input['custom_products'] ?? null);
                if (!$prepared['success']) {
                    return $prepared;
                }
                $before = self::briefSnapshot($request);
                $this->requests->replaceBriefProducts($requestId, $prepared['definitions']);
                $after = $this->requests->lockRequest($requestId);
                $after['custom_products'] = $this->requests->findRequestProducts($requestId);
                $this->requests->recordBriefRevision($requestId, $actor, $reason, $before, self::briefSnapshot($after));
                return ['success' => true,'errors' => []];
            });
        } catch (DomainException $error) {
            return ['success' => false,'errors' => ['form' => $error->getMessage()]];
        }
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
            'categories' => $this->requests->findPublicCategories(),
            'types' => $this->requests->productTypes(),
            'components' => $this->requests->componentOptions(),
            'colors' => $this->requests->optionChoices('colors'), 'scents' => $this->requests->optionChoices('scents'),
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
            if ($this->customerModes === null) {
                return ['success' => false, 'errors' => ['customer_mode' => 'Customer accounts are temporarily unavailable.']];
            }
            [$input, $modeErrors] = $this->customerModes->prepare($input);
            if ($modeErrors !== []) {
                return ['success' => false, 'errors' => $modeErrors];
            }
        }
        $definitions = [];
        if (array_key_exists('custom_products', $input)) {
            $prepared = $this->definitions->validate($input['custom_products']);
            if (!$prepared['success']) {
                return $prepared;
            }
            $definitions = $prepared['definitions'];
            $input['category_id'] = $definitions[0]['category_id'];
            $input['requested_quantity'] = '';
        }
        [$values, $errors] = $this->intake->validate($input, $recordingAdministratorId);
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        $requestNumber = 'REQ-A-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $customerId = ($input['customer_mode'] ?? '') === 'existing' ? (int) $input['customer_id'] : null;
        if (($input['customer_mode'] ?? '') === 'new') {
            $customerId = $this->customerModes->createAccount($input, $recordingAdministratorId);
        }
        $requestId = $this->requests->insertAdministratorRequest([
            'customer_id' => $customerId,
            'request_number' => $requestNumber,
        ] + $values);

        foreach ($definitions as $index => $definition) {
            $this->requests->insertRequestProduct($requestId, $index + 1, $definition);
        }
        return ['success' => true, 'errors' => [], 'requestId' => $requestId, 'requestNumber' => $requestNumber];
    }

    /**
     * Assigns the request to an active administrator, or unassigns it (null).
     *
     * @return array{success: bool, errors: array<string, string>}
     */
    public function assign(int $requestId, ?int $administratorId): array
    {
        try {
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
     * Moves the request to an allowed next status; except for a cancellation, it also logs
     * the contact that led to it.
     *
     * CONVERTED is reached only through conversion to an order.
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
        if (Text::length($notes) > 5000) {
            $errors['notes'] = 'Contact notes must be 5,000 characters or fewer.';
        }
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        try {
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
     * Logs a contact attempt; a follow-up time (Tunisia time, stored as UTC) must be in the future.
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
        if (Text::length($notes) > 5000) {
            $errors['notes'] = 'Contact notes must be 5,000 characters or fewer.';
        }
        $followUpValue = null;
        if ($followUp !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $followUp, new \DateTimeZone(BusinessClock::ZONE));
            if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d\TH:i') !== $followUp || $date <= $now) {
                $errors['follow_up'] = 'Choose a follow-up time in the future.';
            } else {
                $followUpValue = $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        }
        if ($outcome === 'FOLLOW_UP_REQUIRED' && $followUpValue === null) {
            $errors['follow_up'] = 'A follow-up time is required for this outcome.';
        }
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        try {
            $this->transactions->run(function () use (
                $requestId,
                $administratorId,
                $method,
                $outcome,
                $notes,
                $followUpValue
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

    private function assertEditable(?array $request): void
    {
        if ($request === null) {
            throw new DomainException('The custom request could not be found.');
        }
        if (!empty($request['archived_at'])) {
            throw new DomainException('Restore this archived request before editing it.');
        }
        if (in_array((string) $request['request_status'], self::TERMINAL_STATUSES, true)) {
            throw new DomainException('Completed, declined, and cancelled requests cannot be edited.');
        }
    }
}
