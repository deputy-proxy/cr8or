<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Enterprise;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeVersion;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class CreateKnowledgeItem implements Operation
{
    public function execute(User $actor, array $input): mixed
    {
        $enterprise = $input['enterprise'] ?? Enterprise::query()->findOrFail((int) $input['enterprise_id']);
        Gate::forUser($actor)->authorize('createForEnterprise', [KnowledgeItem::class, $enterprise]);
        $item = KnowledgeItem::query()->create([
            'enterprise_id' => $enterprise->getKey(),
            'title' => $input['title'],
            'type' => $input['type'] ?? 'fact',
            'summary' => $input['summary'] ?? null,
        ]);
        $version = KnowledgeVersion::query()->create([
            'enterprise_id' => $enterprise->getKey(),
            'knowledge_item_id' => $item->getKey(),
            'content' => $input['content'],
            'context_snapshot' => $input['context_snapshot'] ?? null,
        ]);

        return [
            'id' => $item->getKey(), 'enterprise_id' => $item->enterprise_id, 'title' => $item->title,
            'type' => $item->type, 'summary' => $item->summary,
            'version' => ['id' => $version->getKey(), 'version' => $version->version, 'content' => $version->content],
        ];
    }
}
