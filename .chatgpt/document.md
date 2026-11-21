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
4. Discover current Knowledge capabilities using `mcp__cr8or__list_capabilities` and inspect relevant capability descriptors with `mcp__cr8or__get_capability` when needed.
5. Inspect the actual schemas/descriptions of the available Knowledge MCP tools at runtime. Tool availability and schemas are authoritative; this document is guidance, not a substitute for the live contract.
6. Check for existing relevant Knowledge Items and indexes before creating new records. Use bounded, enterprise-scoped listing/retrieval tools and relevant search terms.
7. Compare existing records semantically, not just by title. Avoid duplicate records for the same durable claim.

If Enterprise resolution or authorization fails, stop. Explain the exact issue and ask for the minimum information or access correction needed. Never try another Enterprise, identity, or tool to evade the failure.

### 3.1 Current MCP capability limitation

The current inspected CR8OR MCP surface includes these Knowledge operations:

- `create_knowledge_item`: create authoritative Enterprise-scoped Knowledge content.
- `list_knowledge_units` / `get_knowledge_unit`: inspect derived Knowledge Units.
- `create_knowledge_unit`: create or rebuild a derived Knowledge Unit.
- `list_knowledge_indexes` / `get_knowledge_index`: inspect index records.
- `create_knowledge_index`: create or rebuild the authorized derived Knowledge index.
- `retrieve_knowledge`: retrieve bounded, authorized Knowledge with provenance.

The exact tools available may change. Re-discover them during each run.

The inspected MCP surface did **not** expose dedicated create operations for every Filament Knowledge model, including Knowledge Sources, Documents, Contexts, Versions, Specifications, and References. It also did not expose a dedicated Knowledge Embedding creation operation.

Therefore:

- Do not invent tool names or call nonexistent operations.
- Do not use a different domain's operation as a substitute.
- Do not claim a Document, Source, Reference, Version, Specification, or Embedding was created unless the corresponding authorized operation actually did so and returned evidence.
- Do not create a Knowledge Item and describe it as a Knowledge Document.
- Preserve available source/provenance/context information in supported Knowledge Item fields, especially `context_snapshot`, while clearly reporting that this is not equivalent to creating the missing model records.
- If a required model-level record cannot be created through MCP, include it in the final **MCP capability gaps** section. Do not switch to Filament or direct database access.

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

Create a KnowledgeSource only if an authorized MCP operation for it exists. Otherwise preserve the source details in supported fields and report that the KnowledgeSource record itself was not created.

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

Create a KnowledgeDocument only if an authorized MCP operation exists. Otherwise keep the complete curated synthesis in the capture output and preserve its relevant provenance in the supported Knowledge Item records. Do not mislabel a Knowledge Item as a Document.

### 4.3 KnowledgeItem — durable, reusable knowledge

Purpose: store atomic knowledge that agents and humans can retrieve and reuse.

This is the primary authoritative persistence target exposed by the currently inspected CR8OR MCP.

Create one Knowledge Item per independently useful knowledge atom. Use the existing `mcp__cr8or__create_knowledge_item` operation and its live schema.

The inspected operation accepts:

- `enterprise_id` — required Enterprise scope;
- `title` — required concise title;
- `type` — optional knowledge type;
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

**context_snapshot**
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

### 4.4 KnowledgeContext — meaningful grouping

Purpose: group related Knowledge Items under a real, reusable context.

Create or associate a context only when it represents a coherent domain, project, product, process, strategy, or other meaningful context that will help retrieval and interpretation.

Examples might include a specific product strategy, customer acquisition, a business process, or an organizational policy area.

Do not create a context simply because every capture needs a folder. Do not confuse KnowledgeContext with EnterpriseContext. They are distinct models unless the actual domain implementation proves otherwise.

Create or associate KnowledgeContext only through an authorized MCP operation that exists. Otherwise include the proposed context label and rationale in the final report without claiming it was persisted.

### 4.5 KnowledgeVersion — history of Knowledge Item changes

Purpose: preserve the evolution of a Knowledge Item.

The initial version and later versions should be created through the application's authoritative lifecycle/service behavior. Do not manually fabricate version records or claim that a version was created unless the MCP response or subsequent read confirms it.

When updating existing knowledge:
- determine whether the change is a correction, refinement, new fact, or superseding decision;
- preserve historical meaning;
- use a supported update operation if available;
- verify the resulting version/history behavior.

If the MCP surface does not expose the required update or version operation, do not emulate it by creating misleading duplicate records. Report the limitation.

### 4.6 KnowledgeSpecification — formal, versioned specification

Purpose: store a genuinely formal specification associated with a Knowledge Item.

Create one only when the conversation produced a concrete specification with requirements, constraints, acceptance criteria, protocol, policy, or another defined contract.

Do not create a Specification merely because the conversation contains conclusions, advice, or a list of ideas. A normal insight remains a Knowledge Item.

Use an authorized MCP operation if one exists. Otherwise report the specification as a capture artifact that could not be persisted as a Specification through MCP.

### 4.7 KnowledgeReference — explicit provenance links

Purpose: link a Knowledge Item to its supporting Knowledge Document or other source and identify the relevant passage.

For each atom, preserve the best available locator:
- message ID;
- timestamp;
- section heading;
- quoted phrase kept short;
- document section/page;
- external source identifier.

A reference should allow a future reader or agent to understand where the claim came from and inspect the original evidence.

Create KnowledgeReferences only through an authorized operation. If unavailable, preserve provenance in `context_snapshot` and/or `content`. Do not claim a formal KnowledgeReference relationship exists.

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
2. Inspect existing Knowledge Items and relevant indexes.
3. Prepare the curated synthesis and atom list.
4. Identify exact MCP operations and schemas available now.
5. Create only the missing, supported authoritative Knowledge Items.
6. Capture the returned IDs and operation results.
7. Check whether indexing happened automatically.
8. Create/rebuild derived indexes only when needed and supported.
9. Read/list the created records and verify their Enterprise scope and content.
10. Record unsupported model artifacts and any failed operations.

### 7.1 Write safety

- Use only the selected Enterprise ID in every write.
- Use the exact live tool schema; do not guess parameters.
- Use idempotency keys where supported.
- Persist atomic items in a controlled sequence. If one fails, do not report the whole batch as successful.
- If a write times out or returns an ambiguous result, read/list by the available identifiers or search before retrying.
- Do not create duplicate items to work around uncertainty.
- Do not archive, delete, overwrite, or supersede existing knowledge without explicit authorization and a supported operation.
- Do not mutate unrelated records.
- Do not create derived indexes before the authoritative item exists.
- Do not bypass a denied capability or policy.
- Do not use memory tools as a substitute for Enterprise Knowledge.

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

After persistence, verify each created Knowledge Item through an authorized read/list operation.

Check:
- correct Enterprise;
- correct title and type;
- content is complete and faithful;
- epistemic status and scope are preserved;
- provenance/context is present;
- no accidental duplicate was created;
- index state is accurately reported;
- derived units exist where expected;
- failed or unsupported artifacts are accounted for.

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
| KnowledgeVersion | Item history | Created by lifecycle / not exposed / unverified |
| KnowledgeSpecification | Formal specification, if applicable | Created / not applicable / unavailable |
| KnowledgeReference | Source/document locator links | Created / provenance embedded in item / unavailable |
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
- [ ] Existing records were checked to avoid duplicates.
- [ ] Every write used an authorized CR8OR MCP operation.
- [ ] No unsupported model creation was claimed.
- [ ] Provenance and context were preserved to the extent the live MCP contract allows.
- [ ] Indexing was checked and only necessary derived operations were invoked.
- [ ] Created records were read back or the verification limitation was disclosed.
- [ ] Partial failures and capability gaps were reported accurately.

**The objective is not to maximize the number of records. The objective is to preserve the smallest complete, accurate, reusable, and traceable set of knowledge that will help CR8OR users and agents make better decisions later.**
