<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Persistence repository for Admin Customer Repository; it owns the SQL used to read and write this feature's data.
 *
 * DATA FLOW: Services call this layer with validated values. The repository talks to PDO and returns database records or persistence results; it does not render views.
 */

namespace App\Repositories;

use PDO;

final class AdminCustomerRepository
{
    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * FUNCTION PURPOSE: Count for search without loading complete records unnecessarily.
     */
    public function countForSearch(string $search): int
    {
        $where = $this->searchWhere();
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "SELECT COUNT(*)
             FROM (
                 SELECT customers.id
                 FROM customers_accounts AS customers
                 WHERE {$where}
                 LIMIT 101
             ) AS limited_customers"
        );
        $statement->execute($this->searchParameters($search));

        return (int) $statement->fetchColumn();
    }

    /**
     * FUNCTION PURPOSE: Fetch for search and return the matching data when it exists.
     *
     * @return list<array<string, mixed>>
     */
    public function findForSearch(string $search, int $offset, int $limit): array
    {
        $where = $this->searchWhere();
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "SELECT customers.id,
                    CONCAT(customers.first_name, ' ', customers.last_name) AS customer_name,
                    CONCAT(LEFT(customers.email, 1), '***@', SUBSTRING_INDEX(customers.email, '@', -1)) AS email_hint,
                    CONCAT('****', RIGHT(customers.phone_number, 4)) AS phone_hint,
                    customers.account_status, customers.created_at,
                    (SELECT MAX(orders.created_at) FROM active_orders AS orders WHERE orders.customer_id = customers.id) AS last_order_at
             FROM customers_accounts AS customers
             WHERE {$where}
             ORDER BY customers.last_name ASC, customers.first_name ASC, customers.id ASC
             LIMIT :result_limit OFFSET :result_offset"
        );
        foreach ($this->searchParameters($search) as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $statement->bindValue('result_limit', max(1, min($limit, 20)), PDO::PARAM_INT);
        $statement->bindValue('result_offset', max(0, $offset), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }
  
    /**
     * FUNCTION PURPOSE: Fetch account and return the matching data when it exists.
     *
     * @return array<string, mixed>|null
     */
    public function findAccount(int $customerId): ?array
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'SELECT id, first_name, last_name, email, phone_number, second_phone_number,
                    account_status, last_login_at, created_at, updated_at
             FROM customers_accounts
             WHERE id = :customer_id
             LIMIT 1'
        );
        $statement->execute(['customer_id' => $customerId]);
        $customer = $statement->fetch();

        return $customer === false ? null : $customer;
    }

    /**
     * FUNCTION PURPOSE: Fetch addresses and return the matching data when it exists.
     *
     * @return list<array<string, mixed>>
     */
    public function findAddresses(int $customerId): array
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'SELECT id, address_label, street_address, delegation, governorate,
                    postal_code, is_primary, created_at
             FROM customer_addresses
             WHERE customer_id = :customer_id
             ORDER BY is_primary DESC, id ASC'
        );
        $statement->execute(['customer_id' => $customerId]);

        return $statement->fetchAll();
    }

    /**
     * FUNCTION PURPOSE: Fetch metrics and return the matching data when it exists.
     *
     * @return array<string, mixed>
     */
    public function findMetrics(int $customerId): array
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "SELECT
                (SELECT COUNT(*) FROM active_orders AS orders WHERE customer_id = :orders_customer_id) AS order_count,
                (SELECT MAX(created_at) FROM active_orders AS orders WHERE customer_id = :last_order_customer_id) AS last_order_at,
                (SELECT COALESCE(SUM(payments.amount), 0.000)
                 FROM payments
                 INNER JOIN orders ON orders.id = payments.order_id
                 WHERE orders.customer_id = :verified_customer_id
                   AND payments.payment_status = 'VERIFIED') AS verified_amount,
                (SELECT COALESCE(SUM(payments.amount), 0.000)
                 FROM payments
                 INNER JOIN orders ON orders.id = payments.order_id
                 WHERE orders.customer_id = :completed_customer_id
                   AND orders.order_status IN ('PICKED_UP', 'DELIVERED')
                   AND payments.payment_status = 'VERIFIED') AS completed_verified_amount,
                (SELECT COUNT(*) FROM custom_product_requests
                 WHERE customer_id = :requests_customer_id) AS request_count"
        );
        $statement->execute([
            'orders_customer_id' => $customerId,
            'last_order_customer_id' => $customerId,
            'verified_customer_id' => $customerId,
            'completed_customer_id' => $customerId,
            'requests_customer_id' => $customerId,
        ]);

        return $statement->fetch() ?: [
            'order_count' => 0,
            'last_order_at' => null,
            'verified_amount' => '0.000',
            'completed_verified_amount' => '0.000',
            'request_count' => 0,
        ];
    }

    /**
     * FUNCTION PURPOSE: Count orders without loading complete records unnecessarily.
     */
    public function countOrders(int $customerId): int
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare('SELECT COUNT(*) FROM active_orders AS orders WHERE customer_id = :customer_id');
        $statement->execute(['customer_id' => $customerId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * FUNCTION PURPOSE: Fetch orders and return the matching data when it exists.
     *
     * @return list<array<string, mixed>>
     */
    public function findOrders(int $customerId, int $offset, int $limit): array
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'SELECT orders.id, orders.order_number, orders.order_status, orders.order_source,
                    orders.delivery_method, orders.required_date, orders.total_amount,
                    orders.customer_name_snapshot, orders.customer_email_snapshot,
                    orders.customer_phone_snapshot, orders.created_at,
                    COUNT(order_items.id) AS item_count,
                    COALESCE(SUM(CASE WHEN order_items.id IS NOT NULL AND order_items.product_id IS NULL THEN 1 ELSE 0 END), 0) AS snapshot_only_item_count
             FROM active_orders AS orders
             LEFT JOIN order_items ON order_items.order_id = orders.id
             WHERE orders.customer_id = :customer_id
             GROUP BY orders.id
             ORDER BY orders.created_at DESC, orders.id DESC
             LIMIT :result_limit OFFSET :result_offset'
        );
        $statement->bindValue('customer_id', $customerId, PDO::PARAM_INT);
        $statement->bindValue('result_limit', max(1, min($limit, 20)), PDO::PARAM_INT);
        $statement->bindValue('result_offset', max(0, $offset), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * FUNCTION PURPOSE: Count requests without loading complete records unnecessarily.
     */
    public function countRequests(int $customerId): int
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'SELECT COUNT(*) FROM custom_product_requests WHERE customer_id = :customer_id'
        );
        $statement->execute(['customer_id' => $customerId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * FUNCTION PURPOSE: Fetch requests and return the matching data when it exists.
     *
     * @return list<array<string, mixed>>
     */
    public function findRequests(int $customerId, int $offset, int $limit): array
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "SELECT requests.id, requests.request_number, requests.request_status,
                    requests.required_date, requests.requested_quantity, requests.budget,
                    requests.customer_name_snapshot, requests.preferred_contact_method,
                    requests.converted_order_id, requests.created_at,
                    COALESCE(category_text.name, 'Untranslated category') AS category_name,
                    product_text.name AS product_name
             FROM custom_product_requests AS requests
             LEFT JOIN category_translations AS category_text
               ON category_text.category_id = requests.category_id AND category_text.language_code = 'en'
             LEFT JOIN product_translations AS product_text
               ON product_text.product_id = requests.product_id AND product_text.language_code = 'en'
             WHERE requests.customer_id = :customer_id
             ORDER BY requests.created_at DESC, requests.id DESC
             LIMIT :result_limit OFFSET :result_offset"
        );
        $statement->bindValue('customer_id', $customerId, PDO::PARAM_INT);
        $statement->bindValue('result_limit', max(1, min($limit, 20)), PDO::PARAM_INT);
        $statement->bindValue('result_offset', max(0, $offset), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    private function searchWhere(): string
    {
        return "(CONCAT(customers.first_name, ' ', customers.last_name) LIKE :search_name ESCAPE '='
            OR customers.email LIKE :search_email ESCAPE '='
            OR customers.phone_number LIKE :search_phone ESCAPE '='
            OR customers.second_phone_number LIKE :search_second_phone ESCAPE '=')";
    }

    /**
     * @return array<string, string>
     */
    private function searchParameters(string $search): array
    {
        $value = '%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], $search) . '%';

        return [
            'search_name' => $value,
            'search_email' => $value,
            'search_phone' => $value,
            'search_second_phone' => $value,
        ];
    }
}
