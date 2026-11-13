<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;
use JsonException;

/** Transport a large nested brief as one bounded field, before domain validation. */
final class CustomRequestPayload
{
    public const MAX_BYTES = 2097152;

    public static function decode(array $post): array
    {
        if (isset($post['request_payload_error'])) {
            throw new DomainException('The form was incomplete or too large. Review your brief and try again. No data was saved.');
        }
        if (!array_key_exists('custom_products_json', $post)) {
            return $post;
        }
        $json = $post['custom_products_json'];
        if (!is_string($json) || strlen($json) > self::MAX_BYTES || array_key_exists('custom_products', $post)) {
            throw new DomainException('The requested products could not be read. Use one brief of at most 2 MiB. No data was saved.');
        }
        try {
            $products = json_decode($json, true, 12, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DomainException('The requested products were incomplete. No data was saved.');
        }
        if (!is_array($products) || !array_is_list($products) || count($products) < 1 || count($products) > 50) {
            throw new DomainException('Add between one and 50 requested products.');
        }
        $post['custom_products'] = $products;
        return $post;
    }
}
