<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\KnowledgeCatalogService;

abstract class KnowledgeCatalogOperation implements Operation
{
    abstract protected function resource(): string;

    abstract protected function action(): string;

    abstract protected function idField(): string;

    public function __construct(private readonly KnowledgeCatalogService $catalog) {}

    public function execute(User $actor, array $input): mixed
    {
        $enterprise = isset($input['enterprise']) && $input['enterprise'] instanceof Enterprise
            ? $input['enterprise']
            : Enterprise::query()->findOrFail((int) $input['enterprise_id']);
        $resource = $this->resource();

        return match ($this->action()) {
            'create' => $this->catalog->create($actor, $enterprise, $resource, $input),
            'get' => $this->catalog->get($actor, $enterprise, $resource, (int) $input[$this->idField()]),
            'list' => $this->catalog->list($actor, $enterprise, $resource, $input),
            'update' => $this->catalog->update($actor, $enterprise, $resource, (int) $input[$this->idField()], $input),
            default => throw new \LogicException('Unsupported Knowledge catalog operation.'),
        };
    }
}
