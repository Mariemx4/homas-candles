<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Application controller for Product Controller; it coordinates a use case for the HTTP-facing layer.
 *
 * DATA FLOW: A public entry point calls this controller with request-ready input. The controller delegates rules to services and returns data for a redirect, response, or view.
 */

namespace App\Controllers;

use App\Database\DatabaseUnavailableException;
use App\Services\ProductService;
use PDOException;
use RuntimeException;

final class ProductController
{
    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(private readonly ProductService $products)
    {
    }

    public function show(string $untrustedSlug): array
    {
        $slug = trim($untrustedSlug);
        $view = [
            'product' => null,
            'colors' => [],
            'scents' => [],
            'galleryImages' => [],
            // Candle-set component rows from ProductService; empty for other products.
            'components' => [],
            'productLoadError' => null,
            'httpStatus' => 404,
        ];

        // VALIDATION IDIOM: Test the complete string against a regular-expression format before accepting it.
        if ($slug === '' || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            return $this->withPageTitle($view);
        }

        try {
            $productData = $this->products->findForCatalogue($slug);
            if ($productData !== null) {
                $view = array_replace($view, $productData);
                $view['httpStatus'] = 200;
            }
        } catch (RuntimeException | PDOException $exception) {
            // WHAT: log the failure type and, for PDO, only its SQLSTATE/driver code.
            // WHY:  the page already shows a friendly message; the log must still
            //       explain why, without leaking a PDO message (host, username).
            $diagnostics = $exception instanceof PDOException
                ? DatabaseUnavailableException::pdoDiagnostics($exception)
                : ($exception instanceof DatabaseUnavailableException ? $exception->diagnostics() : null);
            error_log(sprintf(
                'Product page could not load a product (%s%s).',
                get_class($exception),
                $diagnostics === null ? '' : sprintf(', SQLSTATE %s, driver code %s',
                    $diagnostics['sqlstate'] ?? 'unknown', $diagnostics['driver_code'] ?? 'unknown')
            ));
            $view['productLoadError'] = 'The product service is temporarily unavailable. Please try again in a moment.';
            $view['httpStatus'] = 503;
        }

        return $this->withPageTitle($view);
    }

    private function withPageTitle(array $view): array
    {
        $view['pageTitle'] = $view['product'] !== null
            ? $view['product']['name'] . " | Hom'As Candles"
            : \App\Services\Locale::text('product.title') . " | Hom'As Candles";

        return $view;
    }
}
