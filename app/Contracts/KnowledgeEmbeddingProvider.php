<?php

namespace App\Contracts;

interface KnowledgeEmbeddingProvider
{
    /** @return list<float> */
    public function embed(string $text, string $modelVersion): array;

    public function version(): string;
}