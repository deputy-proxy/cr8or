<?php

namespace App\Services;

use App\Contracts\KnowledgeEmbeddingProvider;

final class DeterministicKnowledgeEmbeddingProvider implements KnowledgeEmbeddingProvider
{
    public function __construct(private readonly int $dimensions = 16) {}

    public function embed(string $text, string $modelVersion): array
    {
        $hash = hash('sha256', $modelVersion.'\0'.mb_strtolower(trim($text)), true);
        $vector = [];

        for ($index = 0; $index < $this->dimensions; $index++) {
            $offset = ($index * 2) % strlen($hash);
            $value = unpack('n', substr($hash, $offset, 2))[1] ?? 0;
            $vector[] = ($value / 65535) * 2 - 1;
        }

        return $this->normalize($vector);
    }

    public function version(): string
    {
        return 'deterministic-v1';
    }

    /**
     * @param  list<float>  $vector
     * @return list<float>
     */
    private function normalize(array $vector): array
    {
        $norm = sqrt(array_sum(array_map(static fn (float $value): float => $value ** 2, $vector)));

        return $norm > 0 ? array_map(static fn (float $value): float => $value / $norm, $vector) : $vector;
    }
}