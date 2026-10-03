<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Persistence repository for Custom Request Repository; it owns the SQL used to read and write this feature's data.
 *
 * DATA FLOW: Services call this layer with validated values. The repository talks to PDO and returns database records or persistence results; it does not render views.
 */

namespace App\Repositories;

use PDO;

final class CustomRequestRepository
{
    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * FUNCTION PURPOSE: Count for list without loading complete records unnecessarily.
     *
     * @param array<string, string|null> $criteria
     */
    public function countForList(array $criteria): int
    {
        [$where, $parameters] = $this->listWhere($criteria);
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare("SELECT COUNT(*) FROM custom_product_requests AS requests {$where}");
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    /**
     * FUNCTION PURPOSE: Fetch for list and return the matching data when it exists.
     *
     * @param array<string, string|null> $criteria @return list<array<string, mixed>>
     */
    public function findForList(array $criteria, int $offset, int $limit): array
    {
        [$where, $parameters] = $this->listWhere($criteria);
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "SELECT requests.id, requests.request_number, requests.customer_name_snapshot,
                    requests.request_status, requests.required_date, requests.created_at,
                    COALESCE(category_text.name, 'Untranslated category') AS category_name,
                    CASE WHEN administrators.id IS NULL THEN NULL
                         ELSE CONCAT(administrators.first_name, ' ', administrators.last_name) END AS administrator_name,
                    (SELECT attempts.next_follow_up_at
                     FROM request_contact_attempts AS attempts
                     WHERE attempts.request_id = requests.id
                     ORDER BY attempts.contacted_at DESC, attempts.id DESC LIMIT 1) AS next_follow_up_at
             FROM custom_product_requests AS requests
             LEFT JOIN category_translations AS category_text
               ON category_text.category_id = requests.category_id AND category_text.language_code = 'en'
             LEFT JOIN administrators_accounts AS administrators
               ON administrators.id = requests.assigned_administrator_id
             {$where}
             ORDER BY CASE requests.request_status
                        WHEN 'SUBMITTED' THEN 1 WHEN 'CONTACTED' THEN 2 WHEN 'DISCUSSING' THEN 3
                        WHEN 'AGREED' THEN 4 WHEN 'CONVERTED' THEN 5 WHEN 'DECLINED' THEN 6
                        WHEN 'CANCELLED' THEN 7 ELSE 0 END,
                      requests.required_date IS NULL, requests.required_date ASC, requests.created_at ASC, requests.id ASC
             LIMIT :result_limit OFFSET :result_offset"
        );
        foreach ($parameters as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $statement->bindValue('result_limit', max(1, min(100, $limit)), PDO::PARAM_INT);
        $statement->bindValue('result_offset', max(0, $offset), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * FUNCTION PURPOSE: Fetch summary and return the matching data when it exists.
     *
     * @return array<string, mixed>|null
     */
    public function findSummary(int $requestId): ?array
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "SELECT requests.*,
                    categories.slug AS category_slug, products.minimum_quantity AS product_minimum_quantity,
                    COALESCE(category_text.name, 'Untranslated category') AS category_name,
                    product_text.name AS product_name,
                    CASE WHEN administrators.id IS NULL THEN NULL
                         ELSE CONCAT(administrators.first_name, ' ', administrators.last_name) END AS administrator_name,
                    orders.order_number AS converted_order_number
             FROM custom_product_requests AS requests
             LEFT JOIN categories ON categories.id = requests.category_id
             LEFT JOIN products ON products.id = requests.product_id
             LEFT JOIN category_translations AS category_text
               ON category_text.category_id = requests.category_id AND category_text.language_code = 'en'
             LEFT JOIN product_translations AS product_text
               ON product_text.product_id = requests.product_id AND product_text.language_code = 'en'
             LEFT JOIN administrators_accounts AS administrators
               ON administrators.id = requests.assigned_administrator_id
             LEFT JOIN orders ON orders.id = requests.converted_order_id
             WHERE requests.id = :request_id LIMIT 1"
        );
        $statement->execute(['request_id' => $requestId]);
        $request = $statement->fetch();

        return $request === false ? null : $request;
    }

    /**
     * FUNCTION PURPOSE: Fetch contact history and return the matching data when it exists.
     *
     * @return list<array<string, mixed>>
     */
    public function findContactHistory(int $requestId): array
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "SELECT attempts.contact_method, attempts.contact_outcome, attempts.contact_notes,
                    attempts.contacted_at, attempts.next_follow_up_at,
                    CASE WHEN administrators.id IS NULL THEN 'Former administrator'
                         ELSE CONCAT(administrators.first_name, ' ', administrators.last_name) END AS administrator_name
             FROM request_contact_attempts AS attempts
             LEFT JOIN administrators_accounts AS administrators ON administrators.id = attempts.administrator_id
             WHERE attempts.request_id = :request_id
             ORDER BY attempts.contacted_at DESC, attempts.id DESC"
        );
        $statement->execute(['request_id' => $requestId]);

        return $statement->fetchAll();
    }

    /**
     * FUNCTION PURPOSE: Fetch categories and return the matching data when it exists.
     *
     * @return list<array{id: mixed, name: mixed}>
     */
    public function findCategories(): array
    {
        $statement = $this->connection->query(
            "SELECT categories.id, COALESCE(category_text.name, 'Untranslated category') AS name
             FROM categories
             LEFT JOIN category_translations AS category_text
               ON category_text.category_id = categories.id AND category_text.language_code = 'en'
             ORDER BY name ASC, categories.id ASC"
        );

        return $statement->fetchAll();
    }

    /**
     * FUNCTION PURPOSE: Fetch active administrators and return the matching data when it exists.
     *
     * @return list<array{id: mixed, name: mixed}>
     */
    public function findActiveAdministrators(): array
    {
        $statement = $this->connection->query(
            "SELECT id, CONCAT(first_name, ' ', last_name) AS name
             FROM administrators_accounts WHERE account_status = 'ACTIVE'
             ORDER BY first_name ASC, last_name ASC, id ASC"
        );

        return $statement->fetchAll();
    }

    /**
     * FUNCTION PURPOSE: Fetch administrators for filter and return the matching data when it exists.
     *
     * @return list<array{id: mixed, name: mixed}>
     */
    public function findAdministratorsForFilter(): array
    {
        $statement = $this->connection->query(
            "SELECT id, CONCAT(first_name, ' ', last_name) AS name
             FROM administrators_accounts
             ORDER BY first_name ASC, last_name ASC, id ASC"
        );

        return $statement->fetchAll();
    }

    /**
     * Insert an enquiry recorded by an administrator.
     *
     * The repository deliberately receives already-validated snapshot values.
     * Validation stays in the service, while this class owns only the SQL.
     *
     * @param array<string, mixed> $request
     */
    public function insertAdministratorRequest(array $request): int
    {
        $statement = $this->connection->prepare(
            "INSERT INTO custom_product_requests
                (request_number, customer_id, category_id, product_id,
                 customer_name_snapshot, customer_email_snapshot, customer_phone_snapshot,
                 preferred_contact_method, budget, requested_quantity, required_date,
                 color_preferences, scent_preferences, packaging_preferences, customer_message,
                 request_status, assigned_administrator_id)
             VALUES
                (:request_number, :customer_id, :category_id, NULL,
                 :customer_name, :customer_email, :customer_phone,
                 :contact_method, :budget, :quantity, :required_date,
                 :color_preferences, :scent_preferences, :packaging_preferences, :customer_message,
                 'SUBMITTED', :administrator_id)"
        );
        $statement->execute($request + ['customer_id' => null]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * FUNCTION PURPOSE: Fetch the active categories a website visitor may choose as an enquiry topic.
     *
     * @return list<array{id: mixed, slug: mixed, name: mixed}>
     */
    public function findPublicCategories(string $languageCode = 'en'): array
    {
        $statement = $this->connection->prepare(
            "SELECT categories.id, categories.slug,
                    COALESCE(NULLIF(category_text.name, ''), english.name) AS name
             FROM categories
             INNER JOIN category_translations AS english
               ON english.category_id = categories.id AND english.language_code = 'en'
             LEFT JOIN category_translations AS category_text
               ON category_text.category_id = categories.id AND category_text.language_code = :language_code
             WHERE categories.is_active = 1
             ORDER BY name ASC, categories.id ASC"
        );
        $statement->execute(['language_code' => $languageCode]);

        return $statement->fetchAll();
    }

    /**
     * Insert an enquiry submitted through the public contact form.
     *
     * WHAT: web requests start SUBMITTED and unassigned, with a REQ-W- number.
     * WHY:  they join the same administrator queue as phone/social enquiries
     *       (admin/requests), where someone picks them up and records contact.
     *
     * @param array<string, mixed> $request
     */
    public function insertWebRequest(array $request): int
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "INSERT INTO custom_product_requests
                (request_number, customer_id, category_id, product_id,
                 customer_name_snapshot, customer_email_snapshot, customer_phone_snapshot,
                 preferred_contact_method, budget, requested_quantity, required_date,
                 color_preferences, scent_preferences, packaging_preferences, customer_message,
                 request_status, assigned_administrator_id)
             VALUES
                (:request_number, :customer_id, :category_id, :product_id,
                 :customer_name, :customer_email, :customer_phone,
                 :contact_method, :budget, :quantity, :required_date,
                 :color_preferences, :scent_preferences, :packaging_preferences, :customer_message,
                 'SUBMITTED', NULL)"
        );
        $statement->execute($request);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * FUNCTION PURPOSE: Count recent website requests, optionally for one phone number (abuse control).
     *
     * WHAT: only REQ-W- rows count, so enquiries an administrator records by
     *       phone never block the same customer from using the website.
     * WHY:  UNIX_TIMESTAMP() compares on the database clock for both sides,
     *       so a PHP/MySQL timezone difference cannot widen or close the window.
     */
    public function countRecentWebRequests(int $windowSeconds, ?string $phone = null): int
    {
        $sql = "SELECT COUNT(*) FROM custom_product_requests
                WHERE request_number LIKE 'REQ-W-%'
                  AND UNIX_TIMESTAMP(created_at) >= UNIX_TIMESTAMP() - :window_seconds";
        $parameters = ['window_seconds' => max(1, $windowSeconds)];
        if ($phone !== null) {
            $sql .= ' AND customer_phone_snapshot = :phone';
            $parameters['phone'] = $phone;
        }
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lockRequest(int $requestId): ?array
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "SELECT requests.*,
                    categories.slug AS category_slug, products.minimum_quantity AS product_minimum_quantity,
                    COALESCE(product_text.name, category_text.name, 'Custom product') AS suggested_product_name
             FROM custom_product_requests AS requests
             LEFT JOIN categories ON categories.id = requests.category_id
             LEFT JOIN products ON products.id = requests.product_id
             LEFT JOIN product_translations AS product_text
               ON product_text.product_id = requests.product_id AND product_text.language_code = 'en'
             LEFT JOIN category_translations AS category_text
               ON category_text.category_id = requests.category_id AND category_text.language_code = 'en'
             WHERE requests.id = :request_id LIMIT 1 FOR UPDATE OF requests"
        );
        $statement->execute(['request_id' => $requestId]);
        $request = $statement->fetch();

        return $request === false ? null : $request;
    }

    /**
     * FUNCTION PURPOSE: Check whether active administrator exists without loading a complete record.
     */
    public function activeAdministratorExists(int $administratorId): bool
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "SELECT 1 FROM administrators_accounts WHERE id = :administrator_id AND account_status = 'ACTIVE' LIMIT 1"
        );
        $statement->execute(['administrator_id' => $administratorId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * FUNCTION PURPOSE: Assign the supplied value to the selected owner after validation.
     */
    public function assign(int $requestId, ?int $administratorId): void
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'UPDATE custom_product_requests SET assigned_administrator_id = :administrator_id WHERE id = :request_id'
        );
        $statement->bindValue('request_id', $requestId, PDO::PARAM_INT);
        $statement->bindValue('administrator_id', $administratorId, $administratorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->execute();
    }

    /**
     * FUNCTION PURPOSE: Update the stored status values.
     */
    public function updateStatus(int $requestId, string $status): void
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'UPDATE custom_product_requests SET request_status = :request_status WHERE id = :request_id'
        );
        $statement->execute(['request_status' => $status, 'request_id' => $requestId]);
    }

    /**
     * FUNCTION PURPOSE: Insert contact attempt as a new database record.
     */
    public function insertContactAttempt(
        int $requestId,
        int $administratorId,
        string $method,
        string $outcome,
        ?string $notes,
        ?string $followUp
    ): void {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'INSERT INTO request_contact_attempts
                (request_id, administrator_id, contact_method, contact_outcome, contact_notes, next_follow_up_at)
             VALUES (:request_id, :administrator_id, :contact_method, :contact_outcome, :contact_notes, :next_follow_up_at)'
        );
        $statement->execute([
            'request_id' => $requestId,
            'administrator_id' => $administratorId,
            'contact_method' => $method,
            'contact_outcome' => $outcome,
            'contact_notes' => $notes,
            'next_follow_up_at' => $followUp,
        ]);
    }

    /**
     * FUNCTION PURPOSE: Insert manual order as a new database record.
     *
     * @param array<string, mixed> $order
     */
    public function insertManualOrder(array $order): int
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'INSERT INTO orders
                (order_number, customer_id, created_by_administrator_id, language_code, order_source,
                 order_status, delivery_method, required_date, customer_name_snapshot,
                 customer_email_snapshot, customer_phone_snapshot, street_address_snapshot,
                 delegation_snapshot, governorate_snapshot, postal_code_snapshot, subtotal,
                 delivery_fee, total_amount, required_deposit_amount, price_locked_at,
                 customer_notes, administrator_notes)
             VALUES
                (:order_number, :customer_id, :administrator_id, \'en\', \'MANUAL\', \'REQUESTED\',
                 :delivery_method, :required_date, :customer_name, :customer_email, :customer_phone,
                 :street_address, :delegation, :governorate, :postal_code, :subtotal, :delivery_fee,
                 :total_amount, :required_deposit, NOW(), :customer_notes, :administrator_notes)'
        );
        $statement->execute($order);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * FUNCTION PURPOSE: Insert order item as a new database record.
     *
     * WHAT: when the request names a catalogue product, its current production
     *       cost is frozen as unit_cost_snapshot (NULL for an unlinked request).
     * WHY:  profit reports need the cost; an item that is not linked to a
     *       product gets its cost when a custom recipe is added on the order
     *       page (OrderProductionService::saveItemRecipe), which preparation
     *       requires anyway.
     *
     * @param array<string, mixed> $item
     */
    public function insertOrderItem(array $item): int
    {
        $cost = $this->connection->prepare('SELECT actual_cost FROM products WHERE id = :id');
        $cost->execute(['id' => $item['product_id']]);
        return (new OrderRepository($this->connection))->insertManualOrderItem($item + [
            'unit_cost' => $cost->fetchColumn() ?: null,
            'color_id' => null, 'color_name' => null, 'scent_id' => null, 'scent_name' => null,
        ]);
    }

    /**
     * FUNCTION PURPOSE: Insert initial order history as a new database record.
     */
    public function insertInitialOrderHistory(int $orderId, int $administratorId): void
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "INSERT INTO order_status_history
                (order_id, previous_status, new_status, change_source, changed_by_administrator_id, change_note)
             VALUES (:order_id, NULL, 'REQUESTED', 'ADMINISTRATOR', :administrator_id, 'Created from an agreed custom request.')"
        );
        $statement->execute(['order_id' => $orderId, 'administrator_id' => $administratorId]);
    }

    public function markConverted(int $requestId, int $orderId): void
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "UPDATE custom_product_requests
             SET request_status = 'CONVERTED', converted_order_id = :order_id, converted_at = NOW()
             WHERE id = :request_id AND request_status = 'AGREED' AND converted_order_id IS NULL"
        );
        $statement->execute(['order_id' => $orderId, 'request_id' => $requestId]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('The custom request conversion state changed unexpectedly.');
        }
    }

    /**
     * FUNCTION PURPOSE: Prepare a filtered and paginated collection from the supplied query options.
     *
     * @param array<string, string|null> $criteria @return array{0: string, 1: array<string, string>}
     */
    private function listWhere(array $criteria): array
    {
        $conditions = [];
        $parameters = [];
        if (($criteria['search'] ?? null) !== null) {
            $escaped = '%' . $this->escapeLike((string) $criteria['search']) . '%';
            $conditions[] = "(requests.request_number LIKE :search_number ESCAPE '=' OR requests.customer_name_snapshot LIKE :search_name ESCAPE '=')";
            $parameters['search_number'] = $escaped;
            $parameters['search_name'] = $escaped;
        }
        if (($criteria['status'] ?? null) === 'OPEN') {
            $conditions[] = "requests.request_status IN ('SUBMITTED', 'CONTACTED', 'DISCUSSING', 'AGREED')";
        } elseif (($criteria['status'] ?? null) !== null) {
            $conditions[] = 'requests.request_status = :request_status';
            $parameters['request_status'] = (string) $criteria['status'];
        }
        if (($criteria['category'] ?? null) !== null) {
            $conditions[] = 'requests.category_id = :category_id';
            $parameters['category_id'] = (string) $criteria['category'];
        }
        if (($criteria['administrator'] ?? null) === 'unassigned') {
            $conditions[] = 'requests.assigned_administrator_id IS NULL';
        } elseif (($criteria['administrator'] ?? null) !== null) {
            $conditions[] = 'requests.assigned_administrator_id = :administrator_id';
            $parameters['administrator_id'] = (string) $criteria['administrator'];
        }
        if (($criteria['date_from'] ?? null) !== null) {
            $conditions[] = 'requests.required_date >= :date_from';
            $parameters['date_from'] = (string) $criteria['date_from'];
        }
        if (($criteria['date_to'] ?? null) !== null) {
            $conditions[] = 'requests.required_date <= :date_to';
            $parameters['date_to'] = (string) $criteria['date_to'];
        }

        return [$conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions), $parameters];
    }

    /**
     * FUNCTION PURPOSE: Escape like so special characters are treated literally.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['=', '%', '_'], ['==', '=%', '=_'], $value);
    }
}
