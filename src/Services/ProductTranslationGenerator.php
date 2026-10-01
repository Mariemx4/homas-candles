<?php

declare(strict_types=1);

namespace App\Services;

interface ProductTranslationGenerator
{
    /** @return array{name: string, description: string} */
    public function translate(string $sourceLanguage, string $targetLanguage, string $name, string $description): array;
}
