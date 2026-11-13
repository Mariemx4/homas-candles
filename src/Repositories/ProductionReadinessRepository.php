<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\Money;
use PDO;

/** Read-only, bounded checks for the queue; actual transitions recheck under locks. */
final class ProductionReadinessRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function forOrders(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds), static fn (int $id): bool => $id > 0)));
        if ($orderIds === []) {
            return [];
        }
        if (count($orderIds) > 100) {
            throw new \InvalidArgumentException('Readiness checks require a bounded order list.');
        }
        $params = $orderIds;
        $ids = implode(',', array_fill(0, count($orderIds), '?'));
        $result = [];
        foreach ($orderIds as $id) {
            $result[$id] = ['missing' => [],'shortages' => [],'items' => 0,'ready' => true];
        }
        foreach ((new OrderProductionRepository($this->db))->findItemProductionData($orderIds) as $item) {
            $id = (int)$item['order_id'];
            ++$result[$id]['items'];
            if (!$item['has_custom_recipe']) {
                $result[$id]['missing'][] = (string)$item['product_name_snapshot'];
            }
        }
        $q = $this->db->prepare("SELECT i.order_id,m.id,m.name,m.unit,m.is_active,m.stock_quantity,
            SUM(r.quantity_required*i.quantity) AS needed FROM order_items i
            JOIN order_item_materials r ON r.order_item_id=i.id JOIN materials m ON m.id=r.material_id
            JOIN material_types t ON t.id=m.material_type_id
            WHERE i.order_id IN ($ids) AND m.tracks_stock=1 AND t.is_consumable=1
            AND NOT EXISTS(SELECT 1 FROM order_status_history h WHERE h.order_id=i.order_id AND h.new_status='PREPARED')
            GROUP BY i.order_id,m.id ORDER BY i.order_id,m.id");
        $q->execute($params);
        foreach ($q->fetchAll() as $row) {
            $needed = Money::parseMillis((string)$row['needed']);
            $stock = Money::parseMillis((string)$row['stock_quantity']);
            if ((int)$row['is_active'] !== 1 || $needed === null || $stock === null || $needed > $stock) {
                $result[(int)$row['order_id']]['shortages'][] = ['kind' => 'material','id' => (int)$row['id'],'name' => (string)$row['name'],
                    'needed' => (string)$row['needed'],'stock' => (string)$row['stock_quantity'],'unit' => (string)$row['unit'],'inactive' => (int)$row['is_active'] !== 1];
            }
        }
        $q = $this->db->prepare("WITH selected AS (SELECT id FROM active_orders WHERE id IN ($ids)), demand AS (
            SELECT i.order_id,p.id AS product_id,i.quantity AS quantity FROM order_items i JOIN selected s ON s.id=i.order_id JOIN products p ON p.id=i.product_id WHERE p.stock_quantity IS NOT NULL
            UNION ALL SELECT i.order_id,p.id,i.quantity*c.quantity FROM order_items i JOIN selected s ON s.id=i.order_id JOIN set_components c ON c.set_product_id=i.product_id JOIN products p ON p.id=c.component_product_id WHERE p.stock_quantity IS NOT NULL
            UNION ALL SELECT i.order_id,p.id,i.quantity*c.quantity FROM order_items i JOIN selected s ON s.id=i.order_id JOIN custom_request_products cp ON cp.converted_order_item_id=i.id JOIN custom_request_components c ON c.request_product_id=cp.id JOIN products p ON p.id=c.product_id WHERE p.stock_quantity IS NOT NULL
            UNION ALL SELECT i.order_id,p.id,i.quantity*c.quantity*sc.quantity FROM order_items i JOIN selected s ON s.id=i.order_id JOIN custom_request_products cp ON cp.converted_order_item_id=i.id JOIN custom_request_components c ON c.request_product_id=cp.id JOIN set_components sc ON sc.set_product_id=c.product_id JOIN products p ON p.id=sc.component_product_id WHERE p.stock_quantity IS NOT NULL
            ) SELECT d.order_id,p.id,COALESCE(pt.name,p.slug,'Accessory') AS name,p.stock_quantity,SUM(d.quantity) AS needed
            FROM demand d JOIN products p ON p.id=d.product_id LEFT JOIN product_translations pt ON pt.product_id=p.id AND pt.language_code='en'
            WHERE NOT EXISTS(SELECT 1 FROM order_status_history h WHERE h.order_id=d.order_id AND h.new_status='PREPARED')
            GROUP BY d.order_id,p.id,pt.name ORDER BY d.order_id,p.id");
        $q->execute($params);
        foreach ($q->fetchAll() as $row) {
            if ((int)$row['needed'] > (int)$row['stock_quantity']) {
                $result[(int)$row['order_id']]['shortages'][] =
                    ['kind' => 'product','id' => (int)$row['id'],'name' => $row['name'],'needed' => $row['needed'],'stock' => $row['stock_quantity'],'unit' => 'units','inactive' => false];
            }
        }
        foreach ($result as &$check) {
            $check['ready'] = $check['items'] > 0 && $check['missing'] === [] && $check['shortages'] === [];
        }
        return $result;
    }
}
