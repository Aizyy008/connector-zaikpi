# 00 — Product Overview

## Vision

**Automation** is an integration & automation platform (an iPaaS MVP). It lets an
operations team connect external SaaS applications, receive their events via
webhooks, normalize and map the data, and execute actions back into other systems —
with queueing, retries, human approval gates, and a complete audit trail.

The MVP is deliberately built as a **modular, extensible core** so that later phases
(public SDK, connector marketplace, third-party modules, visual flow canvas) can be
added without rewriting the foundation.

## Primary user

An **Ops Admin / Reviewer** working in a production environment. The product is
operational and safety-first: most screens are monitoring + safe actions, and
sensitive actions are gated behind review/approval.

## Core domain concepts (glossary)

| Term | Meaning |
|---|---|
| **Workspace** | Isolation boundary (tenant/project). All domain data is scoped to one. |
| **Connector** | A configured connection to an external application (e.g. an eCommerce store, a business system). Has health state: Healthy / Warning / Disconnected. |
| **Module** | A contract-driven unit of capability (a trigger or action) that declares its name, type, actions, input/output schema, execution method, scopes, and health. |
| **Capability** | An operational toggle per entity (e.g. "Orders Import enabled", "require manual review"). |
| **Canonical** | The internal normalized data contract — canonical entities, required fields, and enum dictionaries reused by mappings/validation. |
| **Routing** | Which connector is primary/secondary/fallback for an entity or event. |
| **Mapping** | Field-to-field mapping and value rules from a source payload to canonical/target fields. |
| **Flow / Automation** | A composed sequence of trigger + conditions + actions with versioning and run history. |
| **Webhook** | Inbound HTTP endpoint that receives signed JSON payloads from external systems. |
| **Payload Log** | A stored inbound payload with raw + parsed views and processing status. |
| **Execution Job** | A queued unit of work created from an accepted payload/flow; tracks status, input, result, errors, retries. |
| **Approval Queue** | Held items awaiting a reviewer decision (approve / reject / request clarification). |
| **Audit Trail** | Immutable record of who changed what across sensitive admin actions. |

## Reference entities used in mockups / demo data

Connectors: `PlatformName` (Platform/Learning — primary source), `CommerceApp`
(eCommerce — secondary), `BusinessApp` (business system / invoicing — action system),
`MarketingApp` (email marketing — outbound), `SocialApp` (social posting — outbound).

Entities: Orders, Customers, Invoices, Webhooks, Enrollments, Contacts.

## Module boundaries (from mockups — enforce in IA)

Each module owns its concern and must not leak into others:

- **Connectors** — registry, health, safe actions. Deep config lives in Connector Detail.
- **Capabilities** — operational toggles & overlap detection. Not a routing/mapping editor.
- **Routing** — primary/secondary/fallback ownership. Not field mappings or policy toggles.
- **Mappings** — field maps, value rules, validation, preview. Does not decide primary source.
- **Canonical** — the normalized contract & dictionaries. No source-specific mapping.
- **Queue & Logs** — queues, execution/error/webhook/audit logs, alerts, approvals. No config edits.
- **Roles & Policies / Settings** — governance, users, approval rules, API & webhook infra.
