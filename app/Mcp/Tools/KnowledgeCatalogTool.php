<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

abstract class KnowledgeCatalogTool extends KnowledgeResourceTool
{
    abstract protected function resource(): string;

    abstract protected function action(): string;

    abstract protected function idField(): string;

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        $fields = ['enterprise_id' => $schema->integer()->min(1)->required()];

        if (in_array($this->action(), ['get', 'update'], true)) {
            $fields[$this->idField()] = $schema->integer()->min(1)->required();
        }

        if ($this->action() === 'list') {
            $fields += [
                'search' => $schema->string()->max(255),
                'per_page' => $schema->integer()->min(1)->max(50),
                'page' => $schema->integer()->min(1),
            ];
            if ($this->resource() === 'document') {
                $fields['status'] = $schema->string()->max(100);
            } elseif ($this->resource() === 'specification') {
                $fields['version'] = $schema->string()->max(255);
            } else {
                $fields['type'] = $schema->string()->max(100);
            }
            if ($this->resource() === 'document') {
                $fields['knowledge_source_id'] = $schema->integer()->min(1);
            }
            if (in_array($this->resource(), ['specification', 'reference'], true)) {
                $fields['knowledge_item_id'] = $schema->integer()->min(1);
            }
            if ($this->resource() === 'reference') {
                $fields['knowledge_document_id'] = $schema->integer()->min(1);
            }
        }

        if ($this->action() === 'create') {
            $fields += [
                'correlation_id' => $schema->string()->max(255),
                'idempotency_key' => $schema->string()->min(1)->max(255),
            ];
        }

        if (in_array($this->action(), ['create', 'update'], true)) {
            $required = $this->action() === 'create';
            $fields += match ($this->resource()) {
                'source' => [
                    'name' => $required ? $schema->string()->min(1)->max(255)->required() : $schema->string()->min(1)->max(255),
                    'type' => $required ? $schema->string()->min(1)->max(255)->required() : $schema->string()->min(1)->max(255),
                    'description' => $schema->string()->nullable(),
                    'uri' => $schema->string()->max(255)->nullable(),
                    'metadata' => $schema->object()->nullable(),
                ],
                'document' => [
                    'knowledge_source_id' => $schema->integer()->min(1)->nullable(),
                    'title' => $required ? $schema->string()->min(1)->max(255)->required() : $schema->string()->min(1)->max(255),
                    'identifier' => $schema->string()->max(255)->nullable(),
                    'status' => $required ? $schema->string()->min(1)->max(255)->required() : $schema->string()->min(1)->max(255),
                    'content' => $required ? $schema->string()->min(1)->required() : $schema->string()->min(1),
                    'metadata' => $schema->object()->nullable(),
                ],
                'specification' => [
                    'knowledge_item_id' => $schema->integer()->min(1)->nullable(),
                    'name' => $required ? $schema->string()->min(1)->max(255)->required() : $schema->string()->min(1)->max(255),
                    'version' => $schema->string()->max(255)->nullable(),
                    'content' => $required ? $schema->string()->min(1)->required() : $schema->string()->min(1),
                ],
                'reference' => [
                    'knowledge_item_id' => $required ? $schema->integer()->min(1)->required() : $schema->integer()->min(1),
                    'knowledge_document_id' => $schema->integer()->min(1)->nullable(),
                    'type' => $required ? $schema->string()->min(1)->max(255)->required() : $schema->string()->min(1)->max(255),
                    'label' => $schema->string()->max(255)->nullable(),
                    'locator' => $schema->string()->max(255)->nullable(),
                    'metadata' => $schema->object()->nullable(),
                ],
                'context' => [
                    'name' => $required ? $schema->string()->min(1)->max(255)->required() : $schema->string()->min(1)->max(255),
                    'type' => $required ? $schema->string()->min(1)->max(255)->required() : $schema->string()->min(1)->max(255),
                    'description' => $schema->string()->nullable(),
                    'data' => $schema->object()->nullable(),
                ],
                default => throw new \LogicException('Unsupported Knowledge resource.'),
            };
        }

        return $fields;
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $rules = ['enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id']];

        if (in_array($this->action(), ['get', 'update'], true)) {
            $rules[$this->idField()] = ['required', 'integer', 'min:1'];
        }

        if ($this->action() === 'list') {
            $rules += [
                'search' => ['nullable', 'string', 'max:255'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
                'page' => ['nullable', 'integer', 'min:1'],
            ];
            if ($this->resource() === 'document') {
                $rules['status'] = ['nullable', 'string', 'max:100'];
            } elseif ($this->resource() === 'specification') {
                $rules['version'] = ['nullable', 'string', 'max:255'];
            } else {
                $rules['type'] = ['nullable', 'string', 'max:100'];
            }
            if ($this->resource() === 'document') {
                $rules['knowledge_source_id'] = ['nullable', 'integer', 'min:1'];
            }
            if (in_array($this->resource(), ['specification', 'reference'], true)) {
                $rules['knowledge_item_id'] = ['nullable', 'integer', 'min:1'];
            }
            if ($this->resource() === 'reference') {
                $rules['knowledge_document_id'] = ['nullable', 'integer', 'min:1'];
            }
        }

        if ($this->action() === 'create') {
            $rules += [
                'correlation_id' => ['nullable', 'string', 'max:255'],
                'idempotency_key' => ['nullable', 'string', 'min:1', 'max:255'],
            ];
        }

        if (in_array($this->action(), ['create', 'update'], true)) {
            $required = $this->action() === 'create' ? 'required' : 'sometimes';
            $rules += match ($this->resource()) {
                'source' => [
                    'name' => [$required, 'string', 'min:1', 'max:255'],
                    'type' => [$required, 'string', 'min:1', 'max:255'],
                    'description' => ['sometimes', 'nullable', 'string'],
                    'uri' => ['sometimes', 'nullable', 'url', 'max:255'],
                    'metadata' => ['sometimes', 'nullable', 'array'],
                ],
                'document' => [
                    'knowledge_source_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
                    'title' => [$required, 'string', 'min:1', 'max:255'],
                    'identifier' => ['sometimes', 'nullable', 'string', 'max:255'],
                    'status' => [$required, 'string', 'min:1', 'max:255'],
                    'content' => [$required, 'string', 'min:1'],
                    'metadata' => ['sometimes', 'nullable', 'array'],
                ],
                'specification' => [
                    'knowledge_item_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
                    'name' => [$required, 'string', 'min:1', 'max:255'],
                    'version' => ['sometimes', 'nullable', 'string', 'max:255'],
                    'content' => [$required, 'string', 'min:1'],
                ],
                'reference' => [
                    'knowledge_item_id' => [$required, 'integer', 'min:1'],
                    'knowledge_document_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
                    'type' => [$required, 'string', 'min:1', 'max:255'],
                    'label' => ['sometimes', 'nullable', 'string', 'max:255'],
                    'locator' => ['sometimes', 'nullable', 'string', 'max:255'],
                    'metadata' => ['sometimes', 'nullable', 'array'],
                ],
                'context' => [
                    'name' => [$required, 'string', 'min:1', 'max:255'],
                    'type' => [$required, 'string', 'min:1', 'max:255'],
                    'description' => ['sometimes', 'nullable', 'string'],
                    'data' => ['sometimes', 'nullable', 'array'],
                ],
                default => throw new \LogicException('Unsupported Knowledge resource.'),
            };
        }

        return $this->executeValidatedOperation($request, $rules);
    }
}
