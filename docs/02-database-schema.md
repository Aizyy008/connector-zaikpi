# 02 — Database Schema & Module Contract

MySQL. All domain tables carry `workspace_id` (FK → `workspaces`, indexed) unless
noted as global. Timestamps (`created_at`, `updated_at`) assumed on all tables.
Tables are grouped by the milestone that introduces them.

> Laravel already ships these: `users`, `sessions`, `cache`, `cache_locks`,
> `jobs`, `job_batches`, `failed_jobs`. We extend `users` and add the rest.

---

## Milestone 1 — Foundation

### users (extend existing)
| column | type | notes |
|---|---|---|
| id | bigint pk | |
| name | string | |
| email | string unique | login identifier |
| username | string unique nullable | optional login/display |
| password | string | bcrypt |
| is_super_admin | boolean default false | global access, bypasses workspace scope |
| status | enum(active, invited, disabled) default active | |
| last_login_at | timestamp nullable | |
| remember_token | string nullable | |

### audit_logs (created here, used throughout — see M6)
See M6 section.

---

## Milestone 2 — Workspaces, Users, Roles & Permissions

### workspaces
| column | type | notes |
|---|---|---|
| id | bigint pk | |
| name | string | |
| slug | string unique | |
| environment | enum(production, staging, development) default production | |
| status | enum(active, disabled) default active | |
| settings | json nullable | timezone, defaults |

### workspace_user (pivot)
| column | type | notes |
|---|---|---|
| id | bigint pk | |
| workspace_id | fk | |
| user_id | fk | |
| role_id | fk → roles | user's role **in this workspace** |
| unique(workspace_id, user_id) | | |

### roles  *(global catalog)*
| column | type | notes |
|---|---|---|
| id | bigint pk | |
| name | string | Super Admin, Ops Admin, Reviewer, Read-only Analyst |
| slug | string unique | `super_admin`, `ops_admin`, `reviewer`, `analyst` |
| description | string nullable | |
| is_system | boolean default false | protects built-in roles from deletion |

### permissions  *(global catalog)*
| column | type | notes |
|---|---|---|
| id | bigint pk | |
| name | string | human label |
| slug | string unique | e.g. `connectors.write`, `flows.execute` |
| group | string | UI grouping (connectors, routing, ...) |

### permission_role (pivot)
`permission_id` fk, `role_id` fk, unique pair.

See [03-permissions-model.md](03-permissions-model.md) for the seeded matrix.

---

## Milestone 3 — Connector Registry, Module Registry, Credentials

### connectors
| column | type | notes |
|---|---|---|
| id | bigint pk | |
| workspace_id | fk | |
| name | string | |
| slug | string | unique per workspace |
| type | enum(ecommerce, business_system, marketing, platform, social, other) | |
| provider | string nullable | vendor/app identifier |
| role | enum(primary_source, secondary_source, action_system, outbound, none) | |
| status | enum(healthy, warning, disconnected) default disconnected | |
| enabled | boolean default true | |
| config | json nullable | non-secret settings |
| last_health_status | string nullable | |
| health_checked_at | timestamp nullable | |

### connector_credentials
| column | type | notes |
|---|---|---|
| id | bigint pk | |
| connector_id | fk | |
| key | string | e.g. `api_token`, `client_secret` |
| value | text | **`encrypted` cast** — never rendered in plaintext |
| type | enum(bearer, hmac, oauth, basic, custom) default custom | |
| expires_at | timestamp nullable | |
| rotated_at | timestamp nullable | |

### modules  *(Module Registry — contract-driven)*
| column | type | notes |
|---|---|---|
| id | bigint pk | |
| workspace_id | fk nullable | null = globally available module |
| name | string | |
| slug | string unique | referenced by routing/flows |
| type | enum(trigger, action, transform) | |
| description | text nullable | |
| actions | json | declared actions |
| input_schema | json | expected input contract |
| output_schema | json | produced output contract |
| execution_method | enum(sync, queue, webhook) default queue | |
| scopes | json nullable | required permission scopes |
| health_status | enum(healthy, warning, unavailable) default healthy | |
| version | string default '1.0.0' | |
| enabled | boolean default true | |

---

## Milestone 4 — Webhooks, Payload Logs, Mappings

### webhook_endpoints
| column | type | notes |
|---|---|---|
| id | bigint pk | |
| workspace_id | fk | |
| connector_id | fk nullable | |
| slug | string unique | public path segment |
| secret | text | **encrypted**, HMAC signing secret |
| signature_algo | string default 'sha256' | |
| signature_header | string default 'X-Signature' | |
| enabled | boolean default true | |

### webhook_payloads  *(Payload Log)*
| column | type | notes |
|---|---|---|
| id | bigint pk | |
| workspace_id | fk | |
| connector_id | fk nullable | |
| endpoint_id | fk nullable | |
| headers | json nullable | |
| raw_payload | longtext | as received |
| parsed_payload | json nullable | after parse/mapping |
| status | enum(received, valid, invalid, processed, failed) default received | |
| error | text nullable | validation/processing error |
| received_at | timestamp | |
| processed_at | timestamp nullable | |

### canonical_entities  *(Canonical schema)*
| column | type | notes |
|---|---|---|
| id | bigint pk | |
| workspace_id | fk | |
| name | string | `orders`, `customers`, ... |
| required_fields | json | |
| optional_fields | json nullable | |
| notes | text nullable | normalization notes |

### canonical_dictionaries
| column | type | notes |
|---|---|---|
| id | bigint pk | |
| workspace_id | fk | |
| name | string | `order_status`, `currency`, ... |
| type | enum(enum, status, value_set) | |
| values | json | source→canonical map |

### field_mappings
| column | type | notes |
|---|---|---|
| id | bigint pk | |
| workspace_id | fk | |
| connector_id | fk nullable | |
| module_id | fk nullable | |
| entity | string | orders/customers/... |
| source_field | string | |
| target_field | string | canonical/action input |
| transform | json nullable | reference to value rule / inline transform |
| status | enum(validated, review, warning) default review | |

### value_rules
| column | type | notes |
|---|---|---|
| id | bigint pk | |
| workspace_id | fk | |
| type | enum(normalization, dictionary, fallback, conversion) | |
| entity | string nullable | |
| source_value | string nullable | |
| target_value | string nullable | |
| applies_to | string nullable | |
| status | enum(active, applied, review) default active | |

---

## Milestone 5 — Execution, Queues, Flows

### flows
| column | type | notes |
|---|---|---|
| id | bigint pk | |
| workspace_id | fk | |
| name | string | |
| slug | string | unique per workspace |
| version | string default '1.0.0-draft' | |
| definition | json | trigger + conditions + actions (block model) |
| status | enum(draft, active, paused) default draft | |
| idempotency_key | string nullable | |

### execution_jobs  *(domain record — distinct from Laravel `jobs`)*
| column | type | notes |
|---|---|---|
| id | bigint pk | |
| workspace_id | fk | |
| flow_id | fk nullable | |
| payload_id | fk → webhook_payloads nullable | |
| connector_id | fk nullable | |
| module_id | fk nullable | |
| type | string | action identifier |
| status | enum(pending, processing, completed, failed, retried, held) default pending | |
| input | json nullable | |
| result | json nullable | |
| error | text nullable | |
| attempts | unsignedInt default 0 | |
| queue_mode | enum(queue_first, direct) default queue_first | |
| started_at | timestamp nullable | |
| finished_at | timestamp nullable | |
| index(workspace_id, status) | | |

### approvals  *(Approval Queue items)*
| column | type | notes |
|---|---|---|
| id | bigint pk | |
| workspace_id | fk | |
| execution_job_id | fk nullable | |
| title | string | e.g. "Invoice creation #INV-1024" |
| reason | string | hold reason / policy hit |
| required_role | string | slug of role that can decide |
| severity | enum(low, medium, high) default medium | |
| status | enum(pending, approved, rejected, clarification) default pending | |
| payload | json nullable | preview of what commits on approve |
| decided_by | fk → users nullable | |
| decided_at | timestamp nullable | |
| decision_note | text nullable | |

---

## Milestone 6 — Audit & Ops

### audit_logs
| column | type | notes |
|---|---|---|
| id | bigint pk | |
| workspace_id | fk nullable | |
| user_id | fk nullable | actor (null = system) |
| action | string | `user.updated`, `connector.credential.rotated`, ... |
| auditable_type | string nullable | morph type |
| auditable_id | bigint nullable | morph id |
| changes | json nullable | before/after (secrets excluded) |
| ip_address | string nullable | |
| user_agent | string nullable | |
| created_at | timestamp | append-only; no updates/deletes |

### alerts
| column | type | notes |
|---|---|---|
| id | bigint pk | |
| workspace_id | fk | |
| level | enum(info, warning, critical) | |
| title | string | |
| message | text nullable | |
| status | enum(active, resolved) default active | |
| meta | json nullable | |

---

## Module Contract (code side)

```php
namespace App\Contracts;

interface ModuleContract
{
    public function slug(): string;                 // unique registry key
    public function type(): string;                 // trigger | action | transform
    public function actions(): array;               // declared action identifiers
    public function inputSchema(): array;           // JSON-schema-ish contract
    public function outputSchema(): array;
    public function scopes(): array;                // required permission scopes
    public function healthCheck(): ModuleHealth;    // healthy | warning | unavailable
    public function execute(array $input, ExecutionContext $ctx): ExecutionResult;
}
```

Rules:
- Concrete modules live in `app/Modules/*` and are registered into the `modules`
  table (metadata + schemas).
- Routing, mappings, and flows reference modules **by `slug`**, never by class.
- Adding a module = implement the interface + register a row. **No core edits.**
