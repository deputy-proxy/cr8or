<?php

namespace App\Services;

use App\Data\KnowledgeNormalizedUnit;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeReference;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final class KnowledgeContentNormalizationService
{
    public function __construct(
        private readonly int $maxUnitCharacters = 1600,
    ) {
        if ($this->maxUnitCharacters < 1) {
            throw new InvalidArgumentException('Knowledge normalization unit size must be positive.');
        }
    }

    /** @return list<KnowledgeNormalizedUnit> */
    public function normalizeDocument(User $actor, KnowledgeDocument $document): array
    {
        Gate::forUser($actor)->authorize('view', $document);

        $document->loadMissing(['source', 'references']);

        return $this->normalizeText(
            enterpriseId: $document->enterprise_id,
            sourceId: $document->knowledge_source_id,
            documentId: $document->getKey(),
            knowledgeItemId: null,
            versionId: null,
            content: $document->content,
            references: $this->references($document->references),
            metadata: ['document_identifier' => $document->identifier],
        );
    }

    /** @return list<KnowledgeNormalizedUnit> */
    public function normalizeItem(User $actor, KnowledgeItem $item): array
    {
        Gate::forUser($actor)->authorize('view', $item);

        $item->load(['source', 'document', 'references', 'latestVersion']);

        $version = $item->latestVersion;

        return $this->normalizeText(
            enterpriseId: $item->enterprise_id,
            sourceId: $item->knowledge_source_id,
            documentId: $item->knowledge_document_id,
            knowledgeItemId: $item->getKey(),
            versionId: $version?->getKey(),
            content: $version !== null ? $version->content : $item->summary,
            references: $this->references($item->references),
            metadata: [
                'item_type' => $item->type,
                'document_title' => $item->document?->title,
            ],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $references
     * @param  array<string, mixed>  $metadata
     * @return list<KnowledgeNormalizedUnit>
     */
    private function normalizeText(
        int $enterpriseId,
        ?int $sourceId,
        ?int $documentId,
        ?int $knowledgeItemId,
        ?int $versionId,
        ?string $content,
        array $references,
        array $metadata,
    ): array {
        $canonical = $this->canonicalize($content);

        if ($canonical === '') {
            return [];
        }

        $blocks = preg_split('/\n{2,}/', $canonical) ?: [];
        $headingPath = [];
        $units = [];

        foreach ($blocks as $block) {
            $block = trim($block);

            if ($block === '') {
                continue;
            }

            $lines = preg_split('/\n/', $block) ?: [];
            $bodyLines = [];

            foreach ($lines as $line) {
                if (preg_match('/^(#{1,6})\s+(.+)$/', trim($line), $matches) === 1) {
                    $level = strlen($matches[1]);
                    $headingPath = array_slice($headingPath, 0, $level - 1);
                    $headingPath[] = trim($matches[2]);

                    continue;
                }

                $bodyLines[] = trim($line);
            }

            $body = trim(implode("\n", array_filter($bodyLines, static fn (string $line): bool => $line !== ''));

            if ($body === '') {
                continue;
            }

            foreach ($this->splitBounded($body) as $part) {
                $ordinal = count($units) + 1;
                $units[] = new KnowledgeNormalizedUnit(
                    enterpriseId: $enterpriseId,
                    sourceId: $sourceId,
                    documentId: $documentId,
                    knowledgeItemId: $knowledgeItemId,
                    versionId: $versionId,
                    unitKey: sprintf('chunk-%04d', $ordinal),
                    ordinal: $ordinal,
                    content: $part,
                    headingPath: $headingPath,
                    references: $references,
                    metadata: $metadata + [
                        'content_hash' => hash('sha256', $part),
                    ],
                );
            }
        }

        return $units;
    }

    private function canonicalize(?string $content): string
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content ?? '');
        $lines = array_map(
            static fn (string $line): string => rtrim($line),
            preg_split('/\n/', $content) ?: [],
        );

        return trim(preg_replace('/\n{3,}/', "\n\n", implode("\n", $lines)) ?? '');
    }

    /** @return list<string> */
    private function splitBounded(string $content): array
    {
        if (mb_strlen($content) <= $this->maxUnitCharacters) {
            return [$content];
        }

        $parts = [];
        $remaining = $content;

        while (mb_strlen($remaining) > $this->maxUnitCharacters) {
            $candidate = mb_substr($remaining, 0, $this->maxUnitCharacters);
            $boundary = mb_strrpos($candidate, ' ');

            if ($boundary === false || $boundary < (int) floor($this->maxUnitCharacters * 0.5)) {
                $boundary = $this->maxUnitCharacters;
            }

            $parts[] = trim(mb_substr($remaining, 0, $boundary));
            $remaining = ltrim(mb_substr($remaining, $boundary));
        }

        if ($remaining !== '') {
            $parts[] = trim($remaining);
        }

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /**
     * @param  iterable<KnowledgeReference>  $references
     * @return list<array<string, mixed>>
     */
    private function references(iterable $references): array
    {
        $result = [];

        foreach ($references as $reference) {
            $result[] = [
                'id' => $reference->getKey(),
                'type' => $reference->type,
                'label' => $reference->label,
                'locator' => $reference->locator,
            ];
        }

        return $result;
    }
}