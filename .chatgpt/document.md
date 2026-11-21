# Conversation-to-CR8OR Knowledge Capture

## Purpose

Use this procedure whenever the user invokes `.chatgpt/document.md` or asks to preserve the important knowledge from the current conversation in CR8OR.

Your job is to turn a conversation into a curated, traceable set of durable organizational knowledge records and persist the supported records through the **CR8OR MCP**.

This is a knowledge-capture workflow, not a transcript-export workflow. Do not dump the entire conversation into one oversized record. Extract what will remain useful, preserve its meaning and provenance, and store it using the existing CR8OR domain model and authorized MCP capabilities.

The user's current conversation is the primary source. Earlier conversation context, project files, connected data, and external sources may be used only when they are actually available and relevant. Never invent missing context or claim that a source was reviewed when it was not.

## Non-negotiable principles

1. **Use the existing domain model.** Do not propose or create parallel Knowledge models, duplicate Decisions, or ad hoc abstractions.
2. **Use CR8OR MCP for persistence.** Do not use direct database access, SQL, application internals, Filament UI automation, GitHub, or another integration as a workaround.
3. **Respect authorization and tenancy.** Resolve the target Enterprise from explicit user context and the enterprises the MCP identity is authorized to access. Never guess an enterprise or bypass a failed authorization check.
4. **Preserve provenance.** Every captured claim must be traceable to this conversation or to an explicitly identified supporting source.
5. **Preserve epistemic status.** A fact, decision, preference, hypothesis, recommendation, assumption, and open question are not interchangeable.
6. **Separate user intent from assistant output.** An assistant suggestion is not a user decision. A proposal becomes an agreed direction only when the user explicitly accepts or adopts it.
7. **Capture durable knowledge, not conversational noise.** Exclude greetings, repetition, temporary logistics, discarded ideas, and details with no plausible future value.
8. **Do not silently reinterpret.** Preserve qualifications, uncertainty, scope, dates, constraints, dissent, and unresolved issues.
9. **Do not overstate persistence.** Report exactly which records were created, which derived records were generated, and which model artifacts could not be created through the available MCP interface.
10. **Be idempotent where possible.** Check for existing records before creating duplicates. Reuse existing records when they represent the same knowledge and update only through an explicitly supported operation.
11. **Do not store secrets.** Never persist passwords, access tokens, API keys, private credentials, security answers, or other secrets. Avoid storing sensitive personal information in enterprise knowledge unless the user explicitly requests it and the storage is appropriate.
12. **No unrequested domain changes.** Capturing knowledge does not authorize creating projects, tasks, objectives, strategies, campaigns, workflows, or other business records.

## Phase 1: Understand the conversation

Read the conversation available in the current context before extracting anything.

If the user refers to an earlier conversation that is not available in the current context, use the available personal-context retrieval mechanism to recover it. Do not pretend that an unavailable conversation was read. If the relevant content cannot be recovered, ask the user to provide or identify it.

Identify:

- the main subject and business/domain area;
- the user, organization, product, project, or Enterprise the knowledge concerns;
- the important discussion threads;
- conclusions and explicit decisions;
- durable facts and constraints;
- preferences and operating principles;
- hypotheses and assumptions;
- recommendations that were accepted, rejected, or left undecided;
- unresolved questions, risks, dependencies, and follow-up considerations;
- any later correction that supersedes an earlier statement.

Use the latest explicit user correction when statements conflict. Preserve a meaningful history of changed decisions when the change itself matters. Do not merge contradictory statements into a false consensus.

### 1.1 Extract knowledge atoms

Break the conversation into the smallest useful, independently understandable knowledge units.

A knowledge atom should express one primary claim, decision, rule, preference, insight, or unresolved issue. Split unrelated claims even when they appeared in the same paragraph.

For each candidate atom, determine:

- **Statement:** What exactly should be remembered?
- **Knowledge class:** Fact, decision, preference, business rule, strategic insight, operational insight, hypothesis, assumption, recommendation, constraint, risk, or open question.
- **Epistemic status:** Confirmed, explicitly decided, user preference, accepted recommendation, proposed, hypothesized, assumed, disputed, unresolved, or superseded.
- **Owner/subject:** Whose organization, product, process, or situation does it concern?
- **Scope:** Where does it apply, and where does it not apply?
- **Rationale:** Why was it stated or decided?
- **Evidence/provenance:** Which conversation passage or supporting source establishes it?
- **Time sensitivity:** Is it current, historical, time-bound, or likely to expire?
- **Confidence:** Is it directly stated, inferred, or uncertain?
- **Future value:** Would an agent or human reasonably need this information in a later task?

Do not promote an inference to a fact. Mark inferred content explicitly and omit it if the inference is not useful or adequately supported.

### 1.2 Preserve decision semantics

Use these rules:

- A user saying “we will do X” or explicitly approving X can be captured as a decision.
- A user saying “I prefer X” is a preference unless they explicitly make it a binding decision.
- An assistant saying “you should do X” is a recommendation, not a decision.
- A user asking “what if we did X?” is exploration, not approval.
- A user accepting a recommendation makes it an accepted direction; capture the user's acceptance and the relevant conditions.
- A rejected proposal may be worth retaining only when the rejection rationale will prevent repeated work or explain a current constraint.
- A hypothesis must remain labelled as a hypothesis until validated.
- An unresolved question must remain unresolved. Never manufacture an answer to make the summary look complete.

When a decision changes, capture the current decision as current and retain the superseded decision only when its history or rationale is useful. Make the supersession explicit.

## Phase 2: Build the capture summary

Prepare a concise but sufficiently complete synthesis before calling any write operation.

Use this structure:

### Conversation overview
- Descriptive title.
- Main subject and domain.
- Relevant date or period, when known.
- One-paragraph summary of the discussion and its outcome.

### Key knowledge
A numbered list of atomic knowledge statements. Each entry includes:
- title;
- knowledge class;
- epistemic status;
- concise summary;
- detailed explanation and scope;
- rationale/evidence;
- provenance locator, if available;
- confidence or validation state, when relevant.

### Decisions
List only decisions actually made or explicitly accepted by the user. For each, preserve:
- decision statement;
- decision owner/authority, if known;
- date or effective period, if known;
- rationale;
- conditions and constraints;
- alternatives rejected, if relevant;
- status: current, superseded, or pending.

### Preferences and constraints
Separate durable preferences from mandatory business rules and temporary constraints.

### Hypotheses and recommendations
Keep hypotheses and recommendations separate from confirmed knowledge. Record whether each recommendation was accepted, rejected, or left open.

### Open questions and risks
Record unresolved matters without inventing answers. Include dependencies and risks only when supported by the conversation.

### Follow-up
Include actions only when the user explicitly committed to them or asks to preserve them. Do not create CR8OR Tasks as part of this workflow unless separately requested.

Do not include a section merely to fill a template. Omit empty sections.

## Phase 3: Resolve the CR8OR Enterprise and inspect MCP capabilities

Before persistence:

1. Discover the authorized Enterprise using `mcp__cr8or__list_enterprises`.
2. Resolve the intended Enterprise from explicit conversation context. If multiple Enterprises are plausible, ask the user which one to use. Do not choose based on convenience.
3. Confirm that the selected Enterprise is accessible to the current MCP identity.
4. Retrieve the selected Enterprise's canonical context with `mcp__cr8or__retrieve_enterprise_context`. Use it to understand current business identity, terminology, strategy, constraints, and context relevant to the conversation. Treat returned context as existing organizational information, not as proof that a new statement in the current conversation is true.
5. Discover current Knowledge capabilities using `mcp__cr8or__list_capabilities` and inspect relevant capability descriptors with `mcp__cr8or__get_capability` when needed.
6. Inspect the actual schemas/descriptions of the available Knowledge MCP tools at runtime. Tool availability and schemas are authoritative; this document is guidance, not a substitute for the live contract.
7. Search existing Knowledge semantically with `mcp__cr8or__retrieve_knowledge` (prefer `mode: "hybrid"`) using the selected `enterprise_id` and focused queries/objectives derived from the candidate knowledge atoms. Use the returned provenance to identify potentially equivalent records and related context.
8. Use `mcp__cr8or__list_knowledge_items` for bounded discovery and `mcp__cr8or__get_knowledge_item` to inspect likely duplicates. Also inspect relevant index state where required.
9. Compare records semantically, not just by title. Avoid duplicate records for the same durable claim.

If Enterprise resolution or authorization fails, stop. Explain the exact issue and ask for the minimum information or access correction needed. Never try another Enterprise, identity, or tool to evade the failure.

### 3.1 Current MCP Knowledge capability baseline

The following is the capability baseline verified against the current CR8OR implementation. It is not a permanent tool contract: rediscover the live tools and inspect their schemas on every run.

**Authoritative Knowledge catalog operations**

Knowledge Sources, Documents, and Contexts each expose create/get/list/update operations through the Knowledge Expert and governed Capability boundary:

- `create-knowledge-source`, `get-knowledge-source`, `list-knowledge-sources`, `update-knowledge-source`.
- `create-knowledge-document`, `get-knowledge-document`, `list-knowledge-documents`, `update-knowledge-document`.
- `create-knowledge-context`, `get-knowledge-context`, `list-knowledge-contexts`, `update-knowledge-context`.

These operations are Enterprise-scoped. Document creation/update validates that any linked Knowledge Source belongs to the same Enterprise. List operations use bounded pagination.

**Authoritative Knowledge Item operations**

- `create-knowledge-item` (connector alias `mcp__cr8or__create_knowledge_item`): create an Enterprise-scoped Knowledge Item and its initial content version, optionally associated with a Knowledge Source, Document, and Context.
- `get-knowledge-item` (connector alias `mcp__cr8or__get_knowledge_item`): read one Enterprise-scoped item, including its latest content version and available provenance relationships.
- `list-knowledge-items` (connector alias `mcp__cr8or__list_knowledge_items`): list Enterprise-scoped items with bounded pagination and optional title/summary search and type filtering. The list is intentionally compact and does not return full content.
- `update-knowledge-item` (connector alias `mcp__cr8or__update_knowledge_item`): update supported item metadata and/or create a new content version while preserving immutable version history.

**Derived retrieval operations**

- `list_knowledge_indexes` / `get_knowledge_index`: inspect Enterprise-scoped index records.
- `create_knowledge_index`: create or rebuild an authorized derived Knowledge index when required.
- `list_knowledge_units` / `get_knowledge_unit`: inspect derived Knowledge Units.
- `create_knowledge_unit`: create a derived unit only when the documented lifecycle requires it and index creation has not already produced it.
- `retrieve_knowledge`: retrieve bounded, authorized Knowledge with provenance.

Use the live MCP tool names and schemas. In the current implementation, tool names are exposed in hyphenated form (for example, `get-knowledge-item`); connector integrations may normalize them to underscores. Never infer a callable name from this document.

**Known model-level gaps**

Knowledge Specifications and Knowledge References are now exposed through dedicated governed create/get/list/update capabilities. Their MCP operations are Enterprise-scoped and validate related Knowledge Item and Knowledge Document ownership. There is still no standalone KnowledgeVersion authoring operation and no dedicated KnowledgeEmbedding creation operation. KnowledgeVersion creation is handled by the authoritative Knowledge Item lifecycle when content/version data is created or updated.

Therefore:

- Do not invent tool names or call nonexistent operations.
- Do not use a different domain's operation as a substitute.
- Create Knowledge Sources, Documents, and Contexts through their dedicated authorized operations when the capture requires them.
- Do not claim a Reference, Specification, or Embedding was created unless an authorized operation actually did so and returned evidence. Knowledge Specifications and Knowledge References must be persisted through their dedicated MCP operations when relevant; never use Filament or direct database access as a workaround.
- Do not create a Knowledge Item and describe it as a Knowledge Document.
- If a required model-level record cannot be created through MCP, include it in the final **MCP capability gaps** section. Do not switch to Filament, direct database access, SQL, or application internals.

### 3.2 Use the newer Enterprise context, Knowledge retrieval, and Agent Memory tools

The newer runtime exposes context and retrieval operations that should be used to improve capture quality. They complement, but do not replace, authoritative Knowledge catalog writes.

**Canonical Enterprise context**

- Call `mcp__cr8or__retrieve_enterprise_context` after resolving and authorizing the Enterprise.
- Use the returned context to resolve names, product/service terminology, current strategy, organizational constraints, and relevant domain relationships.
- Distinguish current Enterprise context from statements made in this conversation. If they conflict, do not silently choose one: prefer an explicit correction from the user for the current capture, preserve the conflict where it matters, and flag unresolved discrepancies.
- Do not treat a context retrieval response as authorization to mutate every referenced object.

**Knowledge retrieval and duplicate detection**

- Use `mcp__cr8or__retrieve_knowledge` for lexical, semantic, or hybrid retrieval. For conversation capture, use `mode: "hybrid"` by default, a focused `query` and/or `objective`, a bounded `limit` (normally 10–20), and the selected `enterprise_id`.
- Run focused searches for the most important candidate atoms or coherent topic clusters. Avoid sending the entire conversation as one unbounded search query.
- Use returned provenance to inspect the source of potentially matching knowledge. Retrieval results are candidates, not automatic proof of equivalence.
- Follow up with `mcp__cr8or__get_knowledge_item` for likely duplicates and with `mcp__cr8or__list_knowledge_items` for bounded catalog discovery.
- Do not use an arbitrary `minimum_relevance` threshold to hide relevant records. Set it only when the task requires a narrower result set and report retrieval limitations.
- Supply `agent_assignment_id` or `agent_execution_id` only when the current operation genuinely belongs to that persisted execution. Never invent execution identifiers.

**Agent Memory is a separate domain**

CR8OR now exposes episodic and semantic Agent Memory operations. Memory is not a replacement for authoritative Knowledge Items, Knowledge Sources, Documents, or Contexts.

- Use `mcp__cr8or__retrieve_memory` only when agent-specific prior experience is materially relevant and a valid authorized `agent_descriptor_id` is known. Supply the selected `enterprise_id` and a focused `topic`; use bounded limits.
- Use `mcp__cr8or__list_memories` to discover Enterprise-scoped episodic or semantic Memory when the relevant Agent or memory type is not known. Use `mcp__cr8or__get_memory` to inspect a candidate record.
- Treat episodic Memory as historical execution experience and semantic Memory as Agent-specific learned statements. Do not copy either into Knowledge automatically. Promote a memory-derived claim into Knowledge only when it is independently supported, useful to the Enterprise, and appropriate for the Knowledge domain.
- Do not create or update Agent Memory as a side effect of ordinary conversation capture. `mcp__cr8or__create_memory`, `mcp__cr8or__record_memory`, and `mcp__cr8or__update_memory` have execution provenance requirements, including `source_execution_id`; use them only when the user explicitly requests Agent Memory work and a real authorized source execution exists.
- `record_memory` additionally requires the explicit `persist` flag. Never fabricate a source execution, agent descriptor, source step, confidence, or provenance to satisfy the schema.
- Episodic Memory is immutable. Update only semantic Memory through its governed update operation, and only when the source execution and update rationale are valid.
- Never use Memory tools to bypass Knowledge authorization or to store material that belongs in the Enterprise Knowledge catalog.

**Agent execution and deterministic workflows**

- Conversation-to-Knowledge capture is normally an interactive operation. Do not create an Agent Assignment, Agent Execution, or Workflow merely to make this document's procedure run.
- Use `mcp__cr8or__execute_agent` or the assignment/execution lifecycle tools only when the user explicitly asks to delegate or run durable Agent work, or when an already-authorized execution context is part of the task.
- Use `mcp__cr8or__list_workflows` and `mcp__cr8or__get_workflow` when a persisted Workflow is relevant to the requested work. A deterministic run must use a published WorkflowVersion and the persisted stage contracts.
- Use `mcp__cr8or__start_workflow_execution`, `mcp__cr8or__get_workflow_execution`, and `mcp__cr8or__resume_workflow_execution` only when the user requests a workflow execution or the task explicitly requires one. Respect idempotency and continuation tokens. Do not replace a workflow with hardcoded stage logic.
- Do not assume an Agent Execution is required for an interactive workflow, or that a Workflow Execution creates an Agent Execution. These are distinct runtime paths.

## Phase 4: Map the conversation to the existing Knowledge models

Use the following mapping as the domain guide. Create only records that are semantically justified and supported by the current MCP contract.

### 4.1 KnowledgeSource — provenance origin

Purpose: identify where the original information came from.

For a conversation capture, the logical source is the original conversation.

Capture these source attributes in the prepared synthesis and, where supported, in Knowledge Item provenance/context:
- source type: conversation;
- source system: ChatGPT, when applicable;
- conversation title or descriptive label;
- original conversation URL/ID, if actually available;
- conversation date or date range, if known;
- source metadata and retrieval limitations.

Do not fabricate a conversation URL, message ID, timestamp, or source identifier. A descriptive label is acceptable when no stable URL or ID is available.

Create or reuse the KnowledgeSource through the dedicated MCP operations. Use `create-knowledge-source` with the selected `enterprise_id`, a descriptive `name`, `type` such as `conversation`, optional `description`, the actual conversation `uri` when available, and structured `metadata` for source system, title, date, and provenance limitations. Use list/get first to avoid duplicates. Do not fabricate a URI or stable identifier. Link the resulting `knowledge_source_id` to the KnowledgeDocument and KnowledgeItems when appropriate.

### 4.2 KnowledgeDocument — curated conversation synthesis

Purpose: represent the curated original document or coherent summary derived from the conversation.

Prepare one logical document per coherent conversation topic or deliverable, rather than one document per message.

The document content should contain:
- conversation overview;
- key conclusions;
- decisions and rationale;
- important facts and constraints;
- unresolved questions;
- references to the atomic knowledge statements.

Do not use a raw transcript as the default document content. A transcript may be retained as a source artifact only when the user explicitly requests it and an appropriate supported storage operation exists.

Create or reuse a KnowledgeDocument through `create-knowledge-document`, associating it with the source using `knowledge_source_id` when available. Supply `title`, a stable `identifier` when available, `status` (normally `active`), the curated `content`, and structured `metadata`. Use list/get first to avoid duplicates. Keep the document synthesis distinct from atomic Knowledge Items; associate each relevant item with the resulting `knowledge_document_id` and source/context IDs. Do not mislabel a Knowledge Item as a Document.

### 4.3 KnowledgeItem — durable, reusable knowledge

Purpose: store atomic knowledge that agents and humans can retrieve and reuse.

This is the primary authoritative persistence target exposed by the currently inspected CR8OR MCP.

Create one Knowledge Item per independently useful knowledge atom. Use the existing `mcp__cr8or__create_knowledge_item` operation and its live schema.

The inspected operation accepts:

- `enterprise_id` — required Enterprise scope;
- `knowledge_source_id`, `knowledge_document_id`, `knowledge_context_id` — optional existing records, each required to belong to the same Enterprise;
- `title` — required concise title;
- `type` — optional Knowledge Item type;
- `summary` — optional concise summary;
- `content` — required substantive content;
- `context_snapshot` — optional structured object;
- `correlation_id` — optional operation correlation;
- `idempotency_key` — optional idempotency protection.

For every Knowledge Item:

**Title**
- Use a concise, specific, durable statement.
- Prefer a claim or rule over a vague topic label.
- Avoid dates in the title unless they are essential to distinguish a time-bound fact or version.

**Type**
- Inspect existing Knowledge type conventions and examples first.
- Reuse an established type when semantically correct.
- Do not invent a new taxonomy casually.
- If no suitable established value exists and the field permits free-form values, use a clear, consistent type such as `fact`, `decision`, `preference`, `business_rule`, `strategic_insight`, `hypothesis`, `recommendation`, `constraint`, or `open_question`.
- Keep the epistemic status distinct from the knowledge type. A recommendation may be accepted or rejected; a fact may be unverified.

**Summary**
- State the essential knowledge in one or two sentences.
- Make it understandable without reading the original conversation.

**Content**
Use a stable structure, adapted to the knowledge class:

- Statement
- Classification and epistemic status
- Subject and scope
- Detailed explanation
- Rationale
- Conditions, constraints, and exceptions
- Evidence and provenance
- Confidence/validation state
- Temporal validity or supersession, if relevant

Do not pad content with unsupported assumptions. Include enough context for a future agent to apply the knowledge correctly.

**`context_snapshot`**
Use this structured object for relevant contextual and provenance data supported by the conversation. Prefer a consistent structure such as:

```json
{
  "capture": {
    "method": "conversation_knowledge_capture",
    "source_system": "chatgpt",
    "source_type": "conversation",
    "source_title": "Actual conversation title, if known",
    "source_reference": "Actual URL or stable identifier, if available",
    "captured_at": "Actual ISO-8601 timestamp, if available"
  },
  "classification": {
    "knowledge_type": "decision",
    "epistemic_status": "explicitly_decided",
    "confidence": "high",
    "validation_status": "user_stated"
  },
  "provenance": {
    "locator": "Actual message ID, timestamp, section, or descriptive locator",
    "evidence_summary": "Short description of the supporting passage"
  },
  "scope": {
    "subject": "Actual subject",
    "applies_to": ["Actual supported scope"],
    "limitations": ["Actual supported limitations"]
  },
  "lifecycle": {
    "status": "current",
    "supersedes": null,
    "superseded_by": null,
    "valid_from": null,
    "valid_until": null
  }
}
```

This is an illustrative structure, not a guaranteed schema for every downstream consumer. Inspect existing conventions and the live operation contract. Include only fields that are supported and useful. Do not invent IDs, timestamps, confidence, or validation evidence. If the operation or application validates a narrower structure, follow that contract and preserve the remaining details in `content`.

**Idempotency**
- Use a stable key derived from the Enterprise, source reference (or stable conversation label), and atom key.
- Reuse the same key when retrying the same logical creation.
- Do not generate a new key merely to bypass a duplicate or failed request.
- If no reliable source reference exists, use a stable topic/claim key and check for semantic duplicates first.

**Read and list existing Knowledge Items**

Use `list_knowledge_items` before creating records:
- Always scope the request to the selected `enterprise_id`.
- Use `search` for a bounded title/summary search and type when a known type filter is useful.
- Use `per_page` from 1 to 50 and paginate when necessary; do not assume the first page is exhaustive.
- Treat list results as compact metadata. They are not proof of the full content or provenance.
- Use `get_knowledge_item` with both `enterprise_id` and `knowledge_item_id` to inspect a candidate duplicate or an item that may need correction.
- Compare meaning, scope, epistemic status, and lifecycle state, not just titles. Reuse a semantically equivalent item rather than creating another one.

**Update existing Knowledge Items**

Use `update_knowledge_item` only when an existing item represents the same durable knowledge and the conversation supplies a correction or refinement that should change that record. Do not update an item merely because a new, distinct fact is related to it.

The current update contract accepts:
- required `enterprise_id` and `knowledge_item_id`;
- optional `knowledge_source_id`, `knowledge_document_id`, and `knowledge_context_id` to associate or re-associate same-Enterprise catalog records (nullable to intentionally unlink);
- optional `title` (1–255 characters), `type` (1–100 characters), and `summary` (up to 5,000 characters);
- optional `content` (1–50,000 characters) and nullable object `context_snapshot`.

Update only the fields that need to change. At least one mutable field must be supplied. Do not send placeholder values for fields that should remain unchanged.

Lifecycle semantics:
- Changes to content and/or `context_snapshot` create a new immutable KnowledgeVersion when the version data differs from the latest version. The previous version remains part of history.
- A `context_snapshot`-only change creates a version using the latest content.
- Changes to title, `type`, summary, or catalog relationships update item metadata; these metadata-only changes do not create a content version.
- Changes that affect indexed content/metadata invalidate the derived index through the application lifecycle. Check the returned/read-back index state; do not assume the index is ready merely because the update succeeded.
- The update operation returns the updated item representation, including its latest version and provenance relationships. Read it back with `get_knowledge_item` when needed to independently verify the final state.

Never manually create KnowledgeVersion records. Do not emulate a correction by creating a duplicate item just to preserve the old wording; the version lifecycle preserves prior content.

### 4.4 KnowledgeContext — meaningful grouping

Purpose: group related Knowledge Items under a real, reusable context.

Create or associate a context only when it represents a coherent domain, project, product, process, strategy, or other meaningful context that will help retrieval and interpretation.

Examples might include a specific product strategy, customer acquisition, a business process, or an organizational policy area.

Do not create a context simply because every capture needs a folder. Do not confuse KnowledgeContext with EnterpriseContext. They are distinct models unless the actual domain implementation proves otherwise.

Create or reuse KnowledgeContext through `create-knowledge-context` with `enterprise_id`, a durable `name`, a meaningful `type`, optional `description`, and structured `data`. Use list/get first to avoid duplicate groupings. Associate each relevant Knowledge Item through `knowledge_context_id`. A context is optional when the capture does not establish a coherent reusable grouping.

### 4.5 KnowledgeVersion — history of Knowledge Item changes

Purpose: preserve the evolution of a Knowledge Item.

The initial version and later versions are created through the authoritative Knowledge Item lifecycle. The current `create_knowledge_item` operation creates the initial content version; `update_knowledge_item` creates a new immutable version when content or `context_snapshot` changes. Metadata-only changes to title, `type`, or summary do not create a content version.

When updating existing knowledge:
- determine whether the change is a correction, refinement, new fact, or superseding decision;
- preserve historical meaning and do not overwrite prior version content;
- use `get_knowledge_item` to inspect the current item before changing it;
- use `update_knowledge_item` for a supported correction/refinement of the same knowledge atom;
- use the optional `knowledge_source_id`, `knowledge_document_id`, and `knowledge_context_id` fields to associate or re-associate an item with existing same-Enterprise catalog records; set a nullable relationship to `null` only when intentionally unlinking it;
- verify the latest version and item metadata through the update result and, where needed, a subsequent `get_knowledge_item` read;
- inspect derived index state because relevant updates invalidate the index.

Do not manually create KnowledgeVersion records. Do not create a duplicate Knowledge Item as a substitute for version history. If a future MCP contract changes, follow the live schema and lifecycle behavior rather than this baseline.

### 4.6 KnowledgeSpecification — formal requirements and constraints

Purpose: store a formal specification associated with an Enterprise and, optionally, a Knowledge Item.

The current model contract is:
- `enterprise_id` — required Enterprise ownership/scope;
- `knowledge_item_id` — nullable relationship to the Knowledge Item that the specification qualifies or constrains;
- `name` — required specification name;
- `version` — nullable version label stored as a string;
- `content` — required specification body.

The model belongs to an Enterprise and optionally belongs to a Knowledge Item. The Knowledge Item exposes a `specifications` relationship. The database permits a specification without a linked Knowledge Item, so do not invent a mandatory item association when the domain context does not establish one.

Create a specification conceptually only when the conversation establishes a concrete contract, requirements set, policy, protocol, acceptance criteria, or explicit constraints. Examples include:
- mandatory product or workflow requirements;
- measurable acceptance criteria;
- operational rules and limits;
- interface, data, or integration contracts;
- compliance or quality requirements.

Do not create a Specification merely because the conversation contains conclusions, advice, a list of ideas, or ordinary facts. Those remain Knowledge Items unless they form a genuine specification.

**Versioning limitation:** the `version` column is a nullable string attribute, not an implemented revision-history mechanism. The inspected model has no specification-version relationship or immutable revision lifecycle. Do not describe KnowledgeSpecification as independently versioned or assume that changing its `version` value preserves prior content. If the specification changes, preserve the previous revision in the capture synthesis/provenance where useful and report the absence of a formal revision lifecycle as a model limitation.

**MCP operations:** use the dedicated governed operations:
- `create-knowledge-specification`;
- `get-knowledge-specification`;
- `list-knowledge-specifications`;
- `update-knowledge-specification`.

These map to `knowledge.specification.create|get|list|update` capabilities and their corresponding Operations. All calls require `enterprise_id`; get/update also require `knowledge_specification_id`. List supports bounded pagination, optional `knowledge_item_id`, text search, and `version` filtering. Create requires `name` and `content`; `knowledge_item_id` and `version` are optional. Update changes only supplied fields and requires at least one mutable field.

Use the live tool schemas. The generic catalog service enforces Enterprise policy and validates that a linked Knowledge Item belongs to the same Enterprise. The specification's nullable `knowledge_item_id` is intentional.

### 4.7 KnowledgeReference — evidence and provenance link

Purpose: create an explicit link from a Knowledge Item to a supporting Knowledge Document and identify the relevant source location.

The current model contract is:
- `enterprise_id` — required Enterprise ownership/scope;
- `knowledge_item_id` — required Knowledge Item relationship;
- `knowledge_document_id` — nullable relationship to a Knowledge Document;
- `type` — required reference classification, defaulting to `source`;
- `label` — nullable human-readable label;
- `locator` — nullable location within the source/document, such as a section, page, heading, timestamp, or message locator;
- `metadata` — nullable structured metadata, cast to an array.

The model belongs to an Enterprise and a Knowledge Item, and optionally belongs to a Knowledge Document. Knowledge Items expose `references`; Knowledge Documents expose their references. A reference therefore records provenance/evidence for an item, rather than being the knowledge claim itself.

For each captured atom, preserve the best available source locator in the capture synthesis and, where supported by the current item contract, its `context_snapshot`:
- conversation message ID or timestamp, only when actually available;
- section heading or short locator;
- document page/section;
- external source identifier or URL, only when actually supplied or verified;
- a short evidence description explaining what the source supports.

**Version relationship limitation:** the current KnowledgeReference schema links to a KnowledgeItem, not to a specific KnowledgeVersion. It therefore cannot formally assert that a reference supports one particular historical version. Do not imply version-specific provenance. When this distinction matters, preserve the version/evidence association explicitly in supported provenance content and report that the relational model does not currently enforce it.

**MCP operations:** use the dedicated governed operations:
- `create-knowledge-reference`;
- `get-knowledge-reference`;
- `list-knowledge-references`;
- `update-knowledge-reference`.

These map to `knowledge.reference.create|get|list|update` capabilities and their corresponding Operations. All calls require `enterprise_id`; get/update also require `knowledge_reference_id`. Create requires `knowledge_item_id` and `type`; `knowledge_document_id`, `label`, `locator`, and `metadata` are optional. List supports bounded pagination, optional item/document filters, text search, and type filtering. Update changes only supplied fields and requires at least one mutable field. The Knowledge Item relationship cannot be nulled because the database requires it.

Use the live tool schemas. The generic catalog service enforces Enterprise policy and validates that both the linked Knowledge Item and optional Knowledge Document belong to the same Enterprise. References remain item-scoped, not version-scoped, as described above.

### 4.8 KnowledgeIndexRecord, KnowledgeIndexUnit, and KnowledgeEmbedding — derived retrieval infrastructure

These are system-managed representations, not primary authored knowledge.

- **KnowledgeIndexRecord:** tracks the index representation and lifecycle.
- **KnowledgeIndexUnit:** contains derived searchable content/chunks.
- **KnowledgeEmbedding:** contains a derived vector representation.

Do not manually author index chunks or embedding vectors. Do not write directly to these models.

After authoritative Knowledge Items have been created:

1. Inspect existing index records for each item using the authorized, Enterprise-scoped MCP operation.
2. Determine whether indexing already happened automatically.
3. If the item has no usable index, or the index is explicitly stale/failed, use the authorized `create_knowledge_index` operation when available to create or rebuild it.
4. Use `create_knowledge_unit` only if the documented lifecycle explicitly requires it and the index operation has not already generated the required units. Avoid duplicate derived units.
5. Verify index status and the existence of derived units through the read/list operations.
6. Do not claim embeddings exist unless the returned state or an authorized read confirms them.

Do not repeatedly rebuild a healthy index. Do not treat a successful index request as proof that every downstream unit and embedding is ready.

## Phase 5: Handle existing Decisions correctly

CR8OR has an existing Decision domain outside the Knowledge navigation group. Do not create a parallel Knowledge Decision model.

When the conversation contains an actual organizational decision:
- capture the decision as a Knowledge Item when it is durable and useful for retrieval;
- preserve the fact that it is an actual user-approved decision, its rationale, scope, and status;
- use the existing Decision domain only if a suitable authorized MCP operation is exposed and the user has asked for, or clearly authorized, formal Decision-register creation;
- do not create a Decision record solely because an assistant recommended something;
- do not duplicate an existing Decision. Search/read first where supported.

If no Decision creation operation is exposed, retain the decision in the Knowledge Item and report that a formal Decision record was not created.

## Phase 6: Decide what should not be saved

Exclude:

- greetings and conversational filler;
- repeated versions of the same statement;
- temporary scheduling details with no lasting value;
- discarded brainstorming that has no future relevance;
- assistant-generated claims not validated or accepted;
- speculative inferences presented as facts;
- irrelevant personal details;
- secrets, credentials, tokens, and passwords;
- sensitive personal information that is not appropriate for enterprise knowledge;
- stale values without a date or qualification;
- information that belongs in another CR8OR domain and was not requested as a separate action.

If a detail is potentially important but ambiguous, preserve it as an explicitly labelled hypothesis or open question, or ask for clarification. Do not silently upgrade it.

## Phase 7: Persist safely through CR8OR MCP

Before writing, present the proposed capture plan if the conversation contains material ambiguity about what should be retained, the target Enterprise, or whether a statement is a decision. Ask only the minimum necessary clarification.

If the user has clearly invoked this procedure, the target Enterprise is unambiguous and authorized, and the capture is straightforward, proceed with persistence without asking for redundant confirmation.

Recommended execution order:

1. Resolve Enterprise and authorization.
2. Retrieve canonical Enterprise context with `mcp__cr8or__retrieve_enterprise_context`.
3. Inspect existing Knowledge Sources, Documents, Contexts, Knowledge Items, and relevant indexes; use hybrid `mcp__cr8or__retrieve_knowledge` searches for semantic duplicate detection.
4. Consult Agent Memory only when it is materially relevant and the valid Agent descriptor is known; do not make Memory writes part of ordinary capture.
5. Prepare the curated synthesis and atom list.
6. Identify exact MCP operations and schemas available now.
5. Create or reuse the conversation KnowledgeSource, the curated KnowledgeDocument, and a KnowledgeContext only when a meaningful grouping exists. Missing optional records must not block Knowledge Item persistence.
6. For each knowledge atom, decide whether to create a new item, reuse an equivalent existing item, or update an existing item because the conversation explicitly corrects/refines that same knowledge.
7. Create only missing, supported authoritative Knowledge Items and link them to the created/reused source, document, and context IDs; use `update_knowledge_item` only for justified changes to an existing item.
8. Capture returned IDs, version identifiers/numbers where returned, and operation results.
9. Check whether indexing happened automatically or was invalidated by a create/update.
10. Create/rebuild derived indexes only when needed and supported; do not duplicate derived units.
11. Read/list the resulting catalog records and Knowledge Items and verify their Enterprise scope, relationships, metadata, latest content/version, and index state.
12. Record unsupported model artifacts and any failed operations.

### 7.1 Write safety

- Use only the selected Enterprise ID in every write.
- Use the exact live tool schema; do not guess parameters.
- Use idempotency keys where supported.
- Persist atomic items in a controlled sequence. If one fails, do not report the whole batch as successful.
- If a write times out or returns an ambiguous result, read/list by the available identifiers or search before retrying.
- Do not create duplicate items to work around uncertainty.
- Do not archive or delete existing knowledge through this workflow.
- Update an existing item only when the user-provided conversation clearly corrects/refines that same knowledge and the update is authorized by the live operation.
- Never overwrite or manually alter immutable version history. Use the supported update lifecycle so prior content remains preserved.
- Do not silently supersede a decision; preserve the new decision and explicitly relate it to the prior state in the content/context when supported.
- Do not mutate unrelated records.
- Do not create derived indexes before the authoritative item exists.
- Do not bypass a denied capability or policy.
- Do not use Agent Memory as a substitute for Enterprise Knowledge. Use Memory retrieval only as a separate, optional source of Agent-specific context under the rules in Phase 3.2.

### 7.2 Partial success

If some records are created and others fail:
- preserve the successful records;
- do not blindly retry successful writes;
- identify the failed operation and its error;
- determine whether a safe retry is possible;
- retry only when the outcome is known and the operation is idempotent or duplicate-safe;
- report partial completion accurately.

Never state that the entire capture succeeded if only some records were persisted.

## Phase 8: Verify the result

After persistence, verify every created, reused, or updated Knowledge Item through authorized read operations.

For each item:
- use `get_knowledge_item` with the selected `enterprise_id` and returned `knowledge_item_id` to inspect the authoritative record;
- confirm the Enterprise, title, `type`, summary, latest content, version number/identifier, and available provenance relationships;
- for updated items, confirm the latest version contains the intended correction and the returned version number/identifier reflects the lifecycle result; the current get operation exposes the latest version, not the complete historical version list, so do not claim the entire history was independently read back;
- confirm epistemic status, scope, and provenance/context are preserved;
- confirm no accidental duplicate was created;
- inspect index records and derived units after create/update; report pending, stale, failed, or ready states exactly as returned;
- account for failed or unsupported model artifacts.

Use `list_knowledge_items` for discovery and bounded verification only; its compact result does not replace `get_knowledge_item` when full content must be checked.

If a read operation is unavailable, explicitly say that the write was accepted but could not be independently read back. Do not claim verified persistence.

## Phase 9: Final report

Return a concise, auditable report with these sections.

### Capture summary
- Conversation/topic captured.
- Target Enterprise.
- Number of durable knowledge atoms identified.
- Important decisions, facts, constraints, preferences, hypotheses, and unresolved questions retained.

### Persisted records
For every Knowledge Item:
- returned record ID;
- title;
- type;
- epistemic status;
- short description;
- verification status;
- index status, if applicable.

Include actual created IDs only. Never invent identifiers.

### Model mapping
Show the outcome for each relevant current model:

| Model | Intended role in this capture | Outcome |
|---|---|---|
| KnowledgeSource | Original conversation provenance | Created / represented in supported provenance fields / unavailable |
| KnowledgeDocument | Curated conversation synthesis | Created / prepared but not persisted / unavailable |
| KnowledgeItem | Atomic durable knowledge | Created / reused / failed |
| KnowledgeContext | Meaningful grouping, if applicable | Created / not needed / unavailable |
| KnowledgeVersion | Item history | Created by create/update lifecycle / unverified |
| KnowledgeSpecification | Formal requirements/constraints, if applicable | Created / reused / unavailable / not applicable |
| KnowledgeReference | Evidence link from item to optional document and locator | Created / reused / unavailable / not applicable |
| KnowledgeIndexRecord | Derived index lifecycle | Ready / pending / failed / not required |
| KnowledgeIndexUnit | Derived searchable chunks | Verified / pending / unavailable |
| KnowledgeEmbedding | Derived vector representation | Verified / pending / unavailable |

Do not label an artifact “created” without returned evidence. Use “not exposed through current MCP” when no authorized operation exists.

### MCP capability gaps
List any required model records or operations that could not be created through the current MCP surface. Explain what information was preserved elsewhere and what remains missing.

### Open questions
List only questions that genuinely need the user's input. Do not end with a generic invitation to continue.

## Quality gate

Before declaring completion, confirm all of the following:

- [ ] The relevant conversation was actually available and reviewed.
- [ ] The correct authorized Enterprise was resolved.
- [ ] Facts, decisions, preferences, hypotheses, recommendations, and questions were distinguished.
- [ ] Assistant suggestions were not misrepresented as user decisions.
- [ ] Durable knowledge was split into atomic, independently useful items.
- [ ] Canonical Enterprise context was retrieved and used without confusing it with current-conversation evidence.
- [ ] Existing records were checked through hybrid Knowledge retrieval, bounded Enterprise-scoped listing, and detailed reads to avoid duplicates.
- [ ] Agent Memory was consulted only when materially relevant, and was not used as a substitute for Knowledge.
- [ ] No Agent Memory, Agent Execution, or Workflow was created merely as a side effect of ordinary conversation capture.
- [ ] Existing items were updated only when the source explicitly corrected/refined the same knowledge atom.
- [ ] Content/context changes used the authoritative version lifecycle; prior versions were not overwritten.
- [ ] Every write used an authorized CR8OR MCP operation.
- [ ] No unsupported model creation was claimed.
- [ ] Provenance and context were preserved to the extent the live MCP contract allows.
- [ ] Indexing was checked and only necessary derived operations were invoked.
- [ ] Created records were read back or the verification limitation was disclosed.
- [ ] Partial failures and capability gaps were reported accurately.

**The objective is not to maximize the number of records. The objective is to preserve the smallest complete, accurate, reusable, and traceable set of knowledge that will help CR8OR users and agents make better decisions later.**
