<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class OpenAiProductTranslationGenerator implements ProductTranslationGenerator
{
    public function __construct(private readonly string $apiKey, private readonly string $model = 'gpt-4o-mini')
    {
    }

    public function translate(string $sourceLanguage, string $targetLanguage, string $name, string $description): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('OpenAI API key is not configured in app/config/translation.local.php.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is unavailable.');
        }
        $languages = ['en' => 'English', 'fr' => 'French', 'ar' => 'Arabic'];
        if (!isset($languages[$sourceLanguage], $languages[$targetLanguage])) {
            throw new RuntimeException('Unsupported translation language.');
        }
        $payload = [
            'model' => $this->model,
            'store' => false,
            'input' => [
                ['role' => 'system', 'content' => 'Translate product catalogue copy faithfully. Preserve product details, quantities, brand names, and meaning. Do not add claims. Return only the requested JSON fields.'],
                ['role' => 'user', 'content' => json_encode([
                    'source_language' => $languages[$sourceLanguage],
                    'target_language' => $languages[$targetLanguage],
                    'name' => $name,
                    'description' => $description,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
            ],
            'text' => ['format' => [
                'type' => 'json_schema', 'name' => 'product_translation', 'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => ['name' => ['type' => 'string'], 'description' => ['type' => 'string']],
                    'required' => ['name', 'description'], 'additionalProperties' => false,
                ],
            ]],
        ];
        $handle = curl_init('https://api.openai.com/v1/responses');
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->apiKey, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 25,
        ]);
        $body = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        if ($body === false || $status < 200 || $status >= 300) {
            throw new RuntimeException($status === 401 ? 'OpenAI rejected the configured API key.' : 'OpenAI translation request failed. Check the API account and retry.');
        }
        $response = json_decode($body, true);
        if (!is_array($response) || ($response['status'] ?? '') !== 'completed') {
            throw new RuntimeException('OpenAI did not complete the translation.');
        }
        $text = '';
        foreach (($response['output'] ?? []) as $output) {
            foreach (($output['content'] ?? []) as $part) {
                if (($part['type'] ?? '') === 'output_text') {
                    $text .= (string) ($part['text'] ?? '');
                }
            }
        }
        $translated = json_decode($text, true);
        if (!is_array($translated) || !is_string($translated['name'] ?? null) || !is_string($translated['description'] ?? null)) {
            throw new RuntimeException('OpenAI returned an invalid translation.');
        }
        return ['name' => trim($translated['name']), 'description' => trim($translated['description'])];
    }
}
