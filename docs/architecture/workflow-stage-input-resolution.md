# Workflow Stage Input Resolution

CR8OR resolves Workflow stage inputs before invoking the stage's declared Capability.

The runtime boundary is:

```
Workflow
  ↓
published WorkflowVersion
  ↓
WorkflowStage
  ↓
Stage Input Resolution
  ├── explicit input
  ├── deterministic mapping
  ├── execution context
  ├── defaults
  ├── generated input
  └── requested input
  ↓
contract validation
  ↓
Capability authorization
  ↓
Capability
  ↓
Operation
```

The resolver is workflow infrastructure. It is not a marketing-specific service and it must not contain business persistence logic.

## Resolution precedence

The canonical precedence is:

1. explicit stage input;
2. deterministic mapping from persisted Workflow data-flow;
3. stage defaults;
4. trusted execution context;
5. generated values for fields explicitly marked `generated`;
6. requested user input where the stage declares `requested`;
7. structured failure when a required value remains unresolved.

Explicit and mapped values are never overwritten by generation.

Mapped identifiers should normally be used for relationships created by upstream stages:

```yaml
mappings:
  marketing_strategy_id: stages.strategy.id
  campaign_id: stages.campaign.id
  content_series_id: stages.content_series.id
```

A generated field must not also have a deterministic mapping or default.

## Stage input contract

A stage input contract may declare:

```json
{
  "required": ["campaign_id", "name", "description"],
  "defaults": {},
  "mappings": {
    "campaign_id": "stages.campaign.id"
  },
  "generated": ["name", "description"],
  "requested": []
}
```

The contract has two roles:

- describe the data-flow requirements of the persisted Workflow stage;
- declare how each required value is resolved.

The Capability input contract remains authoritative for the actual Capability boundary. Workflow publication validates that required Capability inputs have a declared Workflow source unless they are supplied by the runtime.

## Generated inputs

Generation is opt-in.

A field is generated only when:

1. it is required by the Workflow stage;
2. it is listed in `generated`;
3. it is still missing after explicit input, mappings, defaults and trusted context have been resolved;
4. the published WorkflowVersion has `execution_policy.requires_model_provider=true`.

The generation request contains:

- the published WorkflowVersion identity;
- workflow purpose and execution policy;
- the current stage instruction;
- required and generated input names;
- the Capability input contract;
- already resolved inputs;
- authorized execution context;
- previous stage outputs.

The provider is asked for structured output containing only the missing generated fields.

Generation may produce semantic values such as:

- names;
- descriptions;
- titles;
- copy;
- creative specifications.

Generation must not fabricate identifiers or relationships that should come from deterministic Workflow mappings.

Generation must not invoke a Capability or Operation directly. The generated values are only inputs to the subsequent governed Capability invocation.

## ModelProvider policy

Provider-free Workflows remain provider-free.

A stage declaring generated inputs in a WorkflowVersion whose policy says:

```json
{
  "requires_model_provider": false
}
```

is invalid at publication time.

This prevents a supposedly deterministic provider-free Workflow from acquiring a hidden model dependency.

Workflows that require semantic input generation must explicitly opt into model-backed resolution:

```json
{
  "mode": "interactive",
  "requires_model_provider": true
}
```

The Workflow remains the persisted orchestration authority. Model reasoning is limited to resolving the declared missing stage inputs.

## Contract validation

Before Capability invocation, the resolver validates resolved values against the Capability input contract.

At minimum, primitive contracts are checked for:

- string;
- integer;
- number;
- boolean;
- array;
- object.

Unresolved required fields fail before the Capability is invoked.

This means an Operation cannot be reached with an incomplete generated payload simply because a lower layer happens to reject it.

## Provenance and auditability

Each WorkflowExecution stores stage input-resolution provenance in its durable `context`:

```text
context.stage_input_resolutions.<stage_key>
```

The record identifies:

- source of each resolved field;
- mapping paths;
- generated fields;
- requested fields;
- unresolved fields;
- validation status;
- non-sensitive generation metadata such as provider, model and invocation identifier.

Generation prompts and sensitive provider material are not persisted as part of this provenance record.

Example:

```json
{
  "sources": {
    "campaign_id": "mapped",
    "name": "generated",
    "description": "generated"
  },
  "mappings": {
    "campaign_id": "stages.campaign.id"
  },
  "generated": ["name", "description"],
  "validation": {
    "campaign_id": "validated",
    "name": "validated",
    "description": "validated"
  }
}
```

## Workflow authoring guidance

Use a **mapping** when the value is owned by another Workflow stage.

Use a **default** when the value is deterministic and should be supplied when no explicit or mapped value exists.

Use **generated** when the value is semantic and should be derived from the stage instruction and authorized execution context.

Use **requested** when the value must come from a human rather than being inferred or generated.

Do not mark relationship identifiers as generated.

Do not put business persistence in the resolver.

## Versioning

Input contracts are part of the persisted Workflow definition and therefore part of the published WorkflowVersion.

Changing:

- mappings;
- generated fields;
- requested fields;
- stage instructions;
- execution policy;

requires a new WorkflowVersion when the existing Workflow lifecycle requires version publication.

Historical WorkflowExecutions remain bound to the exact WorkflowVersion they executed.

## Testing requirements

New Workflow input-resolution behavior should test:

- explicit values are preserved;
- mapped values take precedence;
- generated values are produced only when missing;
- provider-free Workflows reject generated stages;
- generated output is contract-validated;
- unresolved required inputs fail before Capability execution;
- provenance is persisted;
- generated values flow into the declared Capability;
- Capability authorization and tenancy remain enforced;
- no MCP business Tool is used as an alternate execution path.

The generic resolver must be reusable across domains. Marketing is only one current example.
