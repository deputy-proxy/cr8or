<?php

namespace App\Services;

use App\Contracts\KnowledgeRetrievalProvider;
use App\Data\KnowledgeRetrievalRequest;
use App\Data\KnowledgeRetrievalResult;
use App\Data\KnowledgeRetrievalResultItem;

final class KnowledgeHybridRetrievalProvider implements KnowledgeRetrievalProvider
{
    public function __construct(
        private readonly KnowledgeRetrievalProvider $lexical,
        private readonly KnowledgeRetrievalProvider $semantic,
    ) {}

    public function retrieve(KnowledgeRetrievalRequest $request): KnowledgeRetrievalResult
    {
        $mode = strtolower(trim($request->mode));

        if ($mode === 'lexical') {
            return $this->lexical->retrieve($this->modeRequest($request, 'lexical'));
        }

        if ($mode === 'semantic') {
            return $this->semantic->retrieve($this->modeRequest($request, 'semantic'));
        }

        $lexical = $this->lexical->retrieve($this->modeRequest($request, 'lexical'));
        $semantic = $this->semantic->retrieve($this->modeRequest($request, 'semantic'));

        $candidates = [];

        foreach ($lexical->items as $item) {
            $key = (string) $item->knowledgeItemId;
            $candidates[$key] = [
                'lexical' => (float) ($item->relevance ?? 0),
                'semantic' => 0.0,
                'item' => $item,
            ];
        }

        foreach ($semantic->items as $item) {
            $key = (string) $item->knowledgeItemId;
            $candidates[$key] ??= ['lexical' => 0.0, 'semantic' => 0.0, 'item' => $item];
            $candidates[$key]['semantic'] = max($candidates[$key]['semantic'], (float) ($item->relevance ?? 0));
            $candidates[$key]['item'] = $this->mergeProvenance($candidates[$key]['item'], $item);
        }

        foreach ($candidates as &$candidate) {
            $candidate['score'] = round(($candidate['lexical'] * 0.5) + ($candidate['semantic'] * 0.5), 4);
        }
        unset($candidate);

        usort($candidates, static function (array $left, array $right): int {
            return ($right['score'] <=> $left['score'])
                ?: ((string) $left['item']->knowledgeItemId <=> (string) $right['item']->knowledgeItemId);
        });

        $candidates = array_slice($candidates, 0, $request->limit());

        return new KnowledgeRetrievalResult(
            'succeeded',
            $request->correlationId ?? $lexical->correlationId,
            array_map(
                static fn (array $candidate): KnowledgeRetrievalResultItem => new KnowledgeRetrievalResultItem(
                    $candidate['item']->knowledgeItemId,
                    $candidate['item']->title,
                    $candidate['item']->summary,
                    $candidate['score'],
                    $candidate['item']->source,
                    $candidate['item']->document,
                    $candidate['item']->context,
                    $candidate['item']->references,
                    $candidate['item']->version,
                    $candidate['item']->metadata + [
                        'hybrid_score' => $candidate['score'],
                        'lexical_score' => $candidate['lexical'],
                        'semantic_score' => $candidate['semantic'],
                    ],
                ),
                $candidates,
            ),
            max($lexical->candidateCount, $semantic->candidateCount),
            [
                'mode' => 'hybrid',
                'weights' => ['lexical' => 0.5, 'semantic' => 0.5],
                'lexical_candidates' => $lexical->candidateCount,
                'semantic_candidates' => $semantic->candidateCount,
            ],
        );
    }

    private function modeRequest(KnowledgeRetrievalRequest $request, string $mode): KnowledgeRetrievalRequest
    {
        return new KnowledgeRetrievalRequest(
            actor: $request->actor,
            enterprise: $request->enterprise,
            query: $request->query,
            objective: $request->objective,
            mode: $mode,
            limits: ['limit' => $request->limit()],
            relevance: $request->relevance,
            correlationId: $request->correlationId,
        );
    }

    private function mergeProvenance(KnowledgeRetrievalResultItem $left, KnowledgeRetrievalResultItem $right): KnowledgeRetrievalResultItem
    {
        return new KnowledgeRetrievalResultItem(
            $left->knowledgeItemId,
            $left->title,
            $left->summary ?? $right->summary,
            max((float) ($left->relevance ?? 0), (float) ($right->relevance ?? 0)),
            $left->source ?? $right->source,
            $left->document ?? $right->document,
            $left->context ?? $right->context,
            $left->references !== [] ? $left->references : $right->references,
            $left->version ?? $right->version,
            $left->metadata + $right->metadata,
        );
    }
}