<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Business service for Contact Request; it turns a public contact-form submission into a custom request.
 *
 * DATA FLOW: public/contact.php supplies untrusted form values after the CSRF
 * and ContactAbuseGuard checks. This service resolves the optional product
 * context, validates contact and preference details, applies database-wide
 * rate limits, then asks CustomRequestRepository to save a SUBMITTED request
 * that appears in the administrator's Custom Requests queue.
 */

namespace App\Services;

use App\Auth\CustomerAuthService;
use App\Database\TransactionManager;
use App\Repositories\CustomRequestRepository;
use App\Repositories\OrderRepository;
use Closure;
use DateTimeImmutable;

final class ContactRequestService
{
    // WHAT: a free message, or a request to be called/messaged back.
    // WHY:  a callback needs no written message, but it must name a channel
    //       we can actually call or text on.
    public const REQUEST_TYPES = ['MESSAGE', 'CALLBACK'];
    public const CALLBACK_METHODS = ['PHONE', 'WHATSAPP'];
    // Must match chk_custom_requests_contact_method (migration 008).
    public const CONTACT_METHODS = ['PHONE', 'WHATSAPP', 'INSTAGRAM', 'FACEBOOK', 'TIKTOK', 'EMAIL'];
    // For these channels the customer's handle is needed to reach them.
    public const SOCIAL_METHODS = ['INSTAGRAM', 'FACEBOOK', 'TIKTOK'];

    private readonly Closure $requestNumberGenerator;
    private readonly Closure $today;

    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     *
     * @param array<string, mixed> $settings Values from app/config/contact.php.
     */
    public function __construct(
        private readonly CustomRequestRepository $requests,
        private readonly OrderRepository $orders,
        private readonly ProductService $products,
        private readonly TransactionManager $transactions,
        private readonly array $settings,
        ?callable $requestNumberGenerator = null,
        ?callable $today = null
    ) {
        $this->requestNumberGenerator = $requestNumberGenerator === null
            ? static fn (): string => 'REQ-W-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)))
            : Closure::fromCallable($requestNumberGenerator);
        $this->today = $today === null
            ? static fn (): DateTimeImmutable => new DateTimeImmutable('today')
            : Closure::fromCallable($today);
    }

    /**
     * FUNCTION PURPOSE: Resolve the product a customer arrived from, or null when there is none.
     *
     * WHAT: only active catalogue products are accepted.
     * WHY:  the slug comes from the URL, so it is re-checked here rather than
     *       trusted; an unknown slug falls back to a general enquiry.
     *
     * @return array{id: int, slug: string, name: string, category_id: int, category_name: string, image: string, minimum_quantity: int, is_request_only: bool}|null
     */
    public function productContext(mixed $slug): ?array
    {
        if (!is_string($slug) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            return null;
        }
        $data = $this->products->findForCatalogue($slug);
        if ($data === null) {
            return null;
        }
        $product = $data['product'];

        return [
            'id' => (int) $product['id'],
            'slug' => (string) $product['slug'],
            'name' => (string) $product['name'],
            'category_id' => (int) $product['category_id'],
            'category_name' => (string) $product['category_name'],
            'image' => (string) $product['main_image_path'],
            'minimum_quantity' => max(1, (int) $product['minimum_quantity']),
            // Bouquets and Event Favors are agreed manually (purchase_mode is
            // already forced to REQUEST_ONLY for them by ProductRepository).
            'is_request_only' => $product['purchase_mode'] === 'REQUEST_ONLY',
        ];
    }

    /**
     * FUNCTION PURPOSE: Return the active categories offered as the enquiry topic.
     *
     * @return list<array{id: mixed, slug: mixed, name: mixed}>
     */
    public function topics(): array
    {
        return $this->requests->findPublicCategories(Locale::current());
    }

    /**
     * FUNCTION PURPOSE: Pre-fill contact details for a signed-in customer.
     *
     * @return array<string, string>
     */
    public function defaultsFor(?int $customerId): array
    {
        $defaults = [
            'request_type' => 'MESSAGE', 'customer_name' => '', 'customer_phone' => '', 'customer_email' => '',
            'preferred_contact_method' => 'PHONE', 'social_handle' => '', 'category_id' => '',
            'message' => '', 'requested_quantity' => '', 'required_date' => '', 'budget' => '',
            'color_preferences' => '', 'scent_preferences' => '', 'packaging_preferences' => '',
        ];
        $customer = $customerId === null ? null : $this->orders->findCustomer($customerId);
        if ($customer !== null) {
            $defaults['customer_name'] = (string) $customer['customer_name'];
            $defaults['customer_phone'] = (string) $customer['customer_phone'];
            $defaults['customer_email'] = (string) ($customer['customer_email'] ?? '');
        }

        return $defaults;
    }

    /**
     * FUNCTION PURPOSE: Validate one submission, apply database rate limits, and save the request.
     *
     * @return array{success: bool, errors: array<string, string>, values: array<string, string>, requestNumber?: string}
     */
    public function submit(array $input, ?int $customerId): array
    {
        $product = $this->productContext($input['product'] ?? null);
        [$values, $errors, $clean] = $this->validate($input, $product);
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors, 'values' => $values];
        }

        // WHAT: database-wide limits that a new browser session cannot reset.
        // WHY:  ContactAbuseGuard counts per session; these stop one phone
        //       number, or a distributed flood, from filling the admin queue.
        if ($this->requests->countRecentWebRequests($this->settings['global_window_seconds']) >= $this->settings['global_limit']) {
            return ['success' => false, 'errors' => ['form' => 'We are receiving many messages right now. Please try again shortly or contact us directly.'], 'values' => $values];
        }
        if ($this->requests->countRecentWebRequests($this->settings['phone_window_seconds'], $clean['phone']) >= $this->settings['phone_limit']) {
            return ['success' => false, 'errors' => ['form' => 'We already have several recent requests from this phone number and will contact you soon. For anything urgent, please call or message us directly.'], 'values' => $values];
        }
        if ($customerId !== null && $this->orders->findCustomer($customerId) === null) {
            $customerId = null;
        }

        $requestNumber = ($this->requestNumberGenerator)();
        if (!is_string($requestNumber) || preg_match('/^REQ-W-[A-Z0-9-]{4,24}$/', $requestNumber) !== 1) {
            throw new \RuntimeException('The request number generator returned an invalid value.');
        }
        $this->transactions->run(fn (): int => $this->requests->insertWebRequest([
            'request_number' => $requestNumber,
            'customer_id' => $customerId,
            'category_id' => $clean['category_id'],
            'product_id' => $product['id'] ?? null,
            'customer_name' => $clean['name'],
            'customer_email' => $clean['email'],
            'customer_phone' => $clean['phone'],
            'contact_method' => $clean['method'],
            'budget' => $clean['budget'],
            'quantity' => $clean['quantity'],
            'required_date' => $clean['required_date'],
            'color_preferences' => $clean['color_preferences'],
            'scent_preferences' => $clean['scent_preferences'],
            'packaging_preferences' => $clean['packaging_preferences'],
            'customer_message' => $this->composeMessage($clean, $product),
        ]));

        return ['success' => true, 'errors' => [], 'values' => $values, 'requestNumber' => $requestNumber];
    }

    /**
     * Return form values (for redisplay), field errors, and typed clean values.
     *
     * @param array<string, mixed>|null $product
     * @return array{0: array<string, string>, 1: array<string, string>, 2: array<string, mixed>}
     */
    private function validate(array $input, ?array $product): array
    {
        $errors = [];
        $string = static fn (string $key): string => isset($input[$key]) && is_string($input[$key]) ? trim($input[$key]) : '';
        $values = [];
        foreach (array_keys($this->defaultsFor(null)) as $key) {
            $values[$key] = $string($key);
        }

        $type = strtoupper($values['request_type']);
        if (!in_array($type, self::REQUEST_TYPES, true)) {
            $errors['request_type'] = 'Choose whether to send a message or request a callback.';
            $type = 'MESSAGE';
        }
        $values['request_type'] = $type;

        $name = preg_replace('/\s+/u', ' ', $values['customer_name']) ?? '';
        if ($this->length($name) < 2 || $this->length($name) > 201) {
            $errors['customer_name'] = 'Enter your name (2 to 201 characters).';
        }
        // Same rule as signup and checkout: "+216 28 713 024" equals "28713024".
        $phone = CustomerAuthService::normalizeTunisianPhone($values['customer_phone']);
        if ($phone === null) {
            $errors['customer_phone'] = 'Enter an 8-digit Tunisian phone number, with or without +216.';
        }
        $email = $values['customer_email'];
        if ($email !== '' && ($this->length($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errors['customer_email'] = 'Enter a valid email address, such as name@example.com.';
        }

        // WHAT: the preferred channel decides which extra detail is required.
        // WHY:  we can only reply by email with an address, and only on social
        //       media with the customer's handle; callbacks need phone/WhatsApp.
        $method = strtoupper($values['preferred_contact_method']);
        $allowedMethods = $type === 'CALLBACK' ? self::CALLBACK_METHODS : self::CONTACT_METHODS;
        if (!in_array($method, $allowedMethods, true)) {
            $errors['preferred_contact_method'] = $type === 'CALLBACK'
                ? 'Choose a phone call or WhatsApp for a callback.'
                : 'Choose how you would like us to reply.';
        }
        $values['preferred_contact_method'] = $method;
        if ($method === 'EMAIL' && $email === '') {
            $errors['customer_email'] = 'Enter your email address so we can reply by email.';
        }
        $handle = $values['social_handle'];
        if (in_array($method, self::SOCIAL_METHODS, true)) {
            if ($this->length($handle) < 2 || $this->length($handle) > 100 || preg_match('/[\r\n<>]/', $handle) === 1) {
                $errors['social_handle'] = 'Enter your username or profile name on that platform (up to 100 characters).';
            }
        } else {
            $handle = '';
        }

        // WHAT: a product visit fixes the category; otherwise the visitor picks a topic.
        // WHY:  custom_product_requests.category_id is required, and the
        //       administrator queue filters requests by it.
        $categoryId = $product['category_id'] ?? null;
        if ($product === null) {
            $topicIds = array_map(static fn (array $row): int => (int) $row['id'], $this->topics());
            $topic = filter_var($values['category_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($topic === false || !in_array($topic, $topicIds, true)) {
                $errors['category_id'] = 'Choose what your enquiry is about.';
            } else {
                $categoryId = $topic;
            }
        }

        $message = str_replace("\r\n", "\n", $values['message']);
        $maxMessage = $this->settings['message_max_length'];
        if ($type === 'MESSAGE' && $this->length($message) < 10) {
            $errors['message'] = 'Tell us a little more (at least 10 characters).';
        } elseif ($this->length($message) > $maxMessage) {
            $errors['message'] = 'Keep your message to ' . $maxMessage . ' characters or fewer.';
        } elseif (preg_match_all('~(?:https?://|www\.)~i', $message) > $this->settings['maximum_links']) {
            // Link-stuffed messages are the most common form spam.
            $errors['message'] = 'Please include no more than ' . $this->settings['maximum_links'] . ' links in your message.';
        }

        // WHAT: request details are only collected when a product gives them meaning.
        // WHY:  documented rule — budget, quantity, colour, scent, packaging and
        //       date are agreed manually for Bouquets and Event Favors.
        $clean = [
            'quantity' => null, 'required_date' => null, 'budget' => null,
            'color_preferences' => null, 'scent_preferences' => null, 'packaging_preferences' => null,
        ];
        if ($product !== null) {
            if ($values['requested_quantity'] !== '') {
                $quantity = filter_var($values['requested_quantity'], FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => $product['minimum_quantity'], 'max_range' => 100000],
                ]);
                if ($quantity === false) {
                    $errors['requested_quantity'] = 'Enter a whole quantity of at least ' . $product['minimum_quantity'] . '.';
                } else {
                    $clean['quantity'] = $quantity;
                }
            }
            if ($values['required_date'] !== '') {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $values['required_date']);
                if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $values['required_date']) {
                    $errors['required_date'] = 'Choose a valid date or leave it blank.';
                } elseif ($date <= ($this->today)()) {
                    $errors['required_date'] = 'Choose a date after today.';
                } else {
                    $clean['required_date'] = $values['required_date'];
                }
            }
            if ($values['budget'] !== '') {
                $budget = $this->parseMoney($values['budget']);
                if ($budget === null || $budget <= 0) {
                    $errors['budget'] = 'Enter a budget in DT, such as 120 or 120.500, or leave it blank.';
                } else {
                    $clean['budget'] = $this->millisToMoney($budget);
                }
            }
            foreach (['color_preferences', 'scent_preferences', 'packaging_preferences'] as $key) {
                if ($this->length($values[$key]) > 500) {
                    $errors[$key] = 'Keep this preference to 500 characters or fewer.';
                } elseif ($values[$key] !== '') {
                    $clean[$key] = $values[$key];
                }
            }
        }

        return [$values, $errors, $clean + [
            'type' => $type,
            'name' => $name,
            'phone' => $phone,
            'email' => $email === '' ? null : $email,
            'method' => $method,
            'handle' => $handle,
            'category_id' => $categoryId,
            'message' => $message,
        ]];
    }

    /**
     * FUNCTION PURPOSE: Build the text the administrator reads on the request detail page.
     *
     * WHAT: a short header (request type, social handle, product) above the
     *       customer's own words.
     * WHY:  custom_product_requests has no columns for the request type or a
     *       social handle; putting them at the top of customer_message makes
     *       them visible in the existing admin view without a schema change.
     *
     * @param array<string, mixed> $clean
     * @param array<string, mixed>|null $product
     */
    private function composeMessage(array $clean, ?array $product): string
    {
        $lines = [$clean['type'] === 'CALLBACK' ? '[Website callback request]' : '[Website message]'];
        if ($clean['handle'] !== '') {
            $lines[] = ucfirst(strtolower($clean['method'])) . ' handle: ' . $clean['handle'];
        }
        if ($product !== null) {
            $lines[] = 'Product: ' . $product['name'] . ' (' . $product['slug'] . ')';
        }
        if ($clean['message'] !== '') {
            $lines[] = '';
            $lines[] = $clean['message'];
        }

        return implode("\n", $lines);
    }

    private function parseMoney(string $value): ?int
    {
        // Accept up to three decimals and convert to thousandths without a float.
        if (preg_match('/^(?:0|[1-9][0-9]{0,8})(?:[.,]([0-9]{1,3}))?$/', $value, $matches) !== 1) {
            return null;
        }
        return ((int) preg_split('/[.,]/', $value)[0] * 1000) + (int) str_pad($matches[1] ?? '', 3, '0');
    }

    private function millisToMoney(int $millis): string
    {
        return intdiv($millis, 1000) . '.' . str_pad((string) ($millis % 1000), 3, '0', STR_PAD_LEFT);
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
