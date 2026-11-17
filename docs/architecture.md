# Pixelforge architecture

Status: **accepted target architecture**. This records the decisions agreed during design; it is not a claim that the application already implements them.

The existing app is the Laravel/Vue/Inertia authentication scaffold with a live quote-preview workspace, Postgres and a single FrankenPHP/Caddy app container behind Fairlead. Postgres supersedes the initial SQLite choice: it matches the employer's stack and supports the concurrent settlement workload without SQLite-specific write coordination. Mercure is present in the running image, but is not enabled. The state-owning provider lifecycle machine and generation-request persistence (model, schema, factory, owner scoping and safe history formatting) are implemented and tested. The model hydrates a fresh lifecycle machine but does not persist its mutations. Saloon's public pricing request, validated five-minute catalogue cache, exact quote/actual-cost calculator and authenticated quote endpoint are implemented. The Tailwind workspace previews estimated/maximum prices with token/rate detail; quotes are read-only and Generate stays disabled. A separate Generations sidebar item opens an authenticated placeholder page for future generation history. Provider submission, provider-result/settlement application, generated-image history, background processes and live updates remain to be implemented.

## 1. Intent and scope

Build a small, production-minded image-generation product that demonstrates judgement, financial correctness, resilience and product quality—not merely the minimum assessment submission.

The initial scope includes the required generation experience, exact billing, live history, verified webhooks, public deployment, an operator report and provider-cost reconciliation. Balances and fake credit purchases are deferred until that foundation is complete.

Use one Laravel monolith. Do not introduce a separate frontend API, Vue Router, a global frontend store, microservices, repositories, generic AI-provider interfaces, event sourcing or a general-purpose wallet ledger.

## 2. Layer ownership and coding rules

| Layer                      | Owns                                                                                                  | Does not own                                                                                        |
| -------------------------- | ----------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| Controllers                | HTTP input/output, invoking validation and authorisation, calling actions, responses and redirects    | Business decisions, provider requests, queries, pricing, persistence rules or response-data shaping |
| Form Requests and policies | Request validation and access decisions                                                               | Workflow orchestration                                                                              |
| Actions/services           | Business logic, use-case orchestration, transaction boundaries and integration calls                  | Scattered query-builder updates or duplicate model rules                                            |
| Eloquent models            | Queries/scopes, relationships, casts, persistence rules, valid mutations and returned-data formatting | HTTP responses, provider calls or event-stream publication                                          |
| Database                   | Unique constraints, foreign keys and integrity backing model rules                                    | Application workflow                                                                                |
| Saloon integration         | Provider HTTP contract, authentication, payloads, response parsing and transport failures             | Local settlement, ownership or lifecycle decisions                                                  |
| Vue/Inertia                | Form interaction, page presentation and refreshing authoritative server props                         | Authoritative prices, financial arithmetic or lifecycle decisions                                   |

Controllers should read as **validated input → action → response**. Form Requests handle request validation; models still guard mutation invariants because jobs and webhooks do not pass through those requests.

Actions decide what happens and coordinate it. Models govern how valid data is queried, persisted and exposed. For example, the apply action coordinates a state update and charge, but the model owns the conditional transition and charge-creation rules. Models expose explicit page/report representations; controllers do not assemble ad hoc arrays or return unrestricted model attributes.

Use focused actions for named use cases and cohesive services for reusable behaviour such as pricing. Do not wrap an action in a job and then another forwarding service without a distinct responsibility.

### Styling

Use Tailwind CSS for all application styling, including responsive layout, typography, spacing, colours and interaction states. Reuse existing Tailwind-styled components and shared theme tokens. Do not add component-scoped stylesheets, CSS-in-JS, another styling framework or ad hoc inline styles. The Tailwind entrypoint and shared theme definitions remain; page-specific styling belongs in utility classes.

## 3. End-to-end shape

```text
Vue page --Inertia form--> Controller / Form Request / Policy
                                      |
                               RequestGeneration
                                      |
                          Persist request and estimate
                                      |
                           Durable submission work
                                      |
                               SubmitGeneration
                                      |
                             Saloon --> Pixelforge

Pixelforge webhook --> Signature verification --> Durable event inbox
                                                        |
                                               ApplyProviderGeneration
                                                        |
                                      One database transaction:
                                      - forward-only state update
                                      - immutable charge on success
                                      - processed inbox marker, if applicable
                                      - notification outbox entry
                                                        |
                                               Outbox publisher
                                                        |
                                             Mercure private SSE
                                                        |
                                          Inertia props refresh

Scheduled reconciliation --> Saloon GetGeneration --> same apply action
```

Database state is authoritative. Queue jobs and notifications may be delivered more than once. The guarantee is **idempotent effects**, not exactly-once transport.

## 4. Provider integration: Saloon

Use Saloon's connector/request structure directly, without a forwarding `PixelforgeClient` wrapper. Connectors hold integration-wide settings; request classes hold individual HTTP operations.[1]

```text
app/Http/Integrations/Pixelforge/
  PixelforgeConnector.php
  Requests/
    CreateGeneration.php
    GetGeneration.php
    GetPricing.php
    GetUsage.php
  Data/
    ProviderGeneration.php
```

The connector owns base URL, bearer authentication, common headers and explicit timeouts. Requests own endpoints, bodies/query parameters and the create request's idempotency header. Validate provider payloads at this boundary; malformed success responses must not silently produce incorrect state or charges.

A typed provider-generation representation is shared by create responses, retrieval responses and webhook object parsing. It does not persist itself. Preserve rate strings exactly and validate usage, timestamps and provider errors before applying them.

The submission job owns delayed retry policy. Do not multiply queue attempts by blanket Saloon retries, although Saloon supports automatic retrying.[2] Respect provider error codes, not just status codes:

- Rate/concurrency limits and overload: bounded delayed retries respecting `Retry-After`, with backoff/jitter when appropriate.
- Daily quota exhaustion: stop short retries and expose a meaningful blocked state.
- Invalid input/content rejection: no provider generation or charge; expose the rejection.
- Authentication/configuration failure: operator attention, not repeated customer retries.
- Timeout/connection failure after submission may have reached the provider: preserve uncertainty and retry with the **same** key and unchanged payload.

A retry budget ending does not prove the provider rejected a submission. Leave unresolved requests available for reconciliation/operator attention; never label an uncertain request as a known provider failure.

## 5. Lifecycle and application actions

Keep **local submission state** separate from **provider generation status**. Before acceptance is known, provider status/ID may be absent. The UI can describe submitting, waiting to retry, or awaiting confirmation without falsely claiming Pixelforge has queued the work.

Provider progression is `queued → processing → succeeded | failed`, enforced by an explicit model-owned state machine and a tested transition matrix. The first observation may already be processing or terminal; intermediate events may be missing. Same-state observations are idempotent transitions, but still require payload validation and settlement checks. Stale nonterminal observations are safely ignored/recorded rather than generating retry loops. Terminal states never regress. A contradictory terminal payload is an operational inconsistency to investigate, not permission to rewrite a settled charge.

The state machine owns current provider state in memory, applies lifecycle observations and exposes capability guards, following the state-owning pattern rather than a stateless current/next classifier. Keep all state knowledge in the self-contained class, with no third-party package, speculative states or generic workflow engine. Keep database, HTTP, billing and publication side effects outside it; the model uses it to enforce guarded transitions and the shared apply action coordinates charge/notification intent in its transaction.

The [provider machine](../app/StateMachines/ProviderGenerationStateMachine.php) is constructed with an optional current provider status (null means no prior observation). Named `applyQueued()`, `applyProcessing()`, `applySucceeded()` and `applyFailed()` methods evolve its private state. `status()`, `isTerminal()`, `isSucceeded()` and `isFailed()` read that state; `canProcess()` and `canComplete()` express new-transition eligibility. Processing is possible before observing processing/terminal; completion is possible before either terminal result. A guard being false does not turn an idempotent duplicate into an error.

For example, a machine hydrated as queued can process, then complete; after `applyProcessing()` and `applySucceeded()`, its own `status()` is succeeded and `canComplete()` is false. Callers do not assign its next state themselves. [Pure unit tests](../tests/Unit/ProviderGenerationStateMachineTest.php) cover evolving state, guards and delivery sequences as well as the exhaustive observation matrix. Provider statuses remain string-backed enum values matching the API. Application methods retain diagnostic outcomes:

| Outcome     | Meaning                                                          | Caller responsibility                                                                       |
| ----------- | ---------------------------------------------------------------- | ------------------------------------------------------------------------------------------- |
| `Advance`   | First observation or valid forward movement; owned state updated | Validate payload before application and coordinate guarded persistence/settlement           |
| `Duplicate` | Same status observed again                                       | Validate consistency and check idempotent settlement; do not assume the payload is harmless |
| `Stale`     | An earlier nonterminal status arrived late; owned state retained | Acknowledge/record the observation                                                          |
| `Conflict`  | Opposing terminal results; owned state retained                  | Surface the inconsistency without rewriting settled state                                   |

Only `Advance` changes the owned in-memory status; duplicate/stale/conflicting observations leave it intact. Named application methods and guards share one internal rule source. The machine neither persists itself nor validates rates/usage, and does not throw for normal out-of-order delivery. Hydrate a fresh instance from the locked model inside each transaction attempt; do not register mutable singleton instances or reuse an in-memory state after rollback. Exhaustive transition-matrix tests cover every current/observed combination; new enum cases require explicit review of the rules and tests.

Core use cases:

- `RequestGeneration`: receive validated options, obtain an estimate, persist the owned immutable request and enqueue submission.
- `SubmitGeneration`: send the persisted payload through Saloon; apply the returned object through the shared apply action.
- `ApplyProviderGeneration`: coordinate correlation, forward-only transition, success settlement and notification intent in one transaction using model methods.
- `ReconcileGeneration`: retrieve stale/uncertain work from Pixelforge and call the same apply action.
- Pricing service: pure estimate/actual-cost calculations given validated token rules, rates and usage; no persistence or HTTP.

Persist the generation **before** contacting Pixelforge. Give it a stable idempotency key, include its correlation ID in provider metadata, and scope local form-submission deduplication to the authenticated user. Editing options creates a new request/key; retries do not.

A webhook may arrive before the create response.[4] Correlate it to the existing request, verify API-key/environment context and any existing provider-ID binding, and apply it safely. The later create response must not overwrite newer state. Never infer ownership from a user ID supplied in an incoming payload or create an arbitrary local user/generation from a webhook.

## 6. Persistence and financial invariants

Use Postgres in development, integration tests and production, with the Laravel database queue initially. Fairlead owns local Postgres; Dokploy will run a separate Postgres service in deployment. Add:

| Table                | Responsibility                                                                                                                                                                               |
| -------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `generations`        | User ownership, immutable submitted options/key, submission bookkeeping, provider identity/status, estimates, pricing snapshots, usage, result/error, provider timestamps and local revision |
| `webhook_events`     | Unique provider event ID, verified payload, received timestamp, processing outcome and retry/operator diagnostics                                                                            |
| `generation_charges` | One immutable settlement per successful generation: provider cost, customer charge, markup/rounding policy and provider completion timestamp                                                 |
| `outbox_messages`    | Durable user-scoped notification intent, stable identity, payload/revision, publication attempts and completion marker                                                                       |

### First persistence slice

Create the owned generation request and immutable quote before any provider call. Generation IDs are ULIDs and double as provider idempotency keys; a separate per-user UUID submission token deduplicates browser requests. Store input/quote dictionaries as JSONB, money/counters as nonnegative BIGINTs and timestamps as timezone-aware values. Use PHP-backed enums with Postgres CHECK constraints, unique owner/token and provider identifiers, and an owner/history index. Preserve reference-image order and duplicates because they affect the submitted/billed request.

The request model owns validation for non-HTTP callers, write-once request/quote rules, owner-scoped queries and an explicit history projection. Callers must supply the UUID submission token; the model does not invent a new deduplication identity on a retried request. Money inputs must be PHP integers, not coerced floats/numeric strings. Owner scopes require a persisted user so a missing user ID cannot query anonymised records. Monetary projection values are exact nine-decimal USD strings, never JavaScript numeric amounts; default JSON/array serialization uses the same conservative history projection. Internal submission tokens, provider identifiers/fingerprints, raw quote/evidence and operational diagnostics are not exposed by that projection. User deletion nulls ownership rather than cascading generation/financial deletion; detailed personal-data anonymisation/retention must be completed before exposing real generations with account deletion.

Implement request storage independently from provider-result settlement. Until the shared apply/settlement handler exists, new model records must be pending/unobserved and direct provider-status/result writes must fail closed. Hydrating a fresh lifecycle machine from the model supports lifecycle queries but does not persist machine mutations automatically. Do not invent a successful generation without its charge, return fake actual prices or expose a half-built ingestion route. Reserved provider schema fields are not evidence that provider ingestion, billing, inbox/outbox or live UI is implemented.

Required database-backed invariants:

1. A provider generation ID belongs to at most one local generation; a submission token is unique within its user scope.
2. A webhook event ID is recorded once. Delivery-attempt metadata does not create another event.
3. A generation has at most one settlement, enforced by a unique generation foreign key.
4. Only valid succeeded usage can create a charge. Failed generations display actual cost zero and have no charge entry. Pending actual cost is unknown, not zero.
5. State, settlement and notification intent commit atomically. Mark inbox processing complete in that same transaction.
6. Snapshotted request/pricing evidence and recorded financial entries cannot be silently rewritten. Account deletion must not cascade away provider-cost evidence; anonymise ownership if deletion is supported.

Use short Postgres transactions, generation-row locking for settlement, conditional mutations, unique constraints and foreign keys. Acquire locks in a consistent order and use bounded deadlock retries. Row locks coordinate concurrent work; unique constraints remain the final defence against duplicate effects.[6] No network operations or publication inside a database transaction.

### Money and estimates

- USD only. Store provider costs and customer charges as integer **nanodollars** (`$1 = 1,000,000,000` units).
- Parse decimal rate strings and calculate exactly; never convert through binary floats. Current provider costs are reported at nine decimal places.[4] A future rate that cannot be represented exactly at this scale must fail visibly rather than silently round provider cost.
- Customer price is provider cost × `5/4`. Round half-up **once**, at final customer settlement, to one nanodollar. Record that policy; do not round each token component separately.
- Preserve provider cost unchanged for reconciliation. Profit is customer charge minus provider cost, not a separately rounded calculation.
- Fetch/cache pricing and token rules instead of hardcoding them. The implemented catalogue reader validates fresh and cached data, evicts malformed cache entries, uses a five-minute TTL and fails closed rather than serving expired rates on provider failure. Saloon's public pricing request needs no API key, has explicit connect/total timeouts and no hidden web-request retry sleeps.
- Estimates ceil Unicode code-point count divided by published characters per token and round an odd reasoning midpoint up to a whole token. The upper bound ceils the text-variance bound and uses published reasoning maxima; output/reference counts use the selected options and include duplicate references. Reuse Brick Math for exact arithmetic, require whole-nanodollar provider totals after summing components, and reject overflow/noninteger money inputs rather than coercing them. `total_tokens` validates the component sum, never adds another charge.
- The authenticated/CSRF-protected quote endpoint is rate-limited and returns exact decimal strings with no-store caching. Vue debounces, aborts and versions previews, clears old prices synchronously on input changes and never computes monetary prices. The preview's labels, explanatory copy and permanently visible token/rate breakdown stay mounted during recalculation; only numeric values become loading placeholders. Estimate and maximum explanations are available through labelled info-button tooltips supporting hover, keyboard focus and touch. A quote neither persists a request nor starts work; submission will re-quote server-side.
- Store the quote snapshot. Actual settlement uses the generation's provider rate snapshot, not current pricing. The displayed maximum is conditional on the quoted rates, not a guaranteed price cap if provider rates change before acceptance; disclose that distinction.
- Models format exact decimal strings and user-facing breakdowns. Return money to JavaScript as strings. Display enough precision to avoid making small positive charges appear free; report totals from stored amounts, never summed display-rounded values.

## 7. Durable webhooks, jobs and recovery

Webhook HTTP handling is deliberately short:

1. Verify HMAC against the raw body, with constant-time comparison and bounded timestamp tolerance.
2. Validate the envelope/object; persist or recognise the unique event through a model.
3. Dispatch processing and return 2xx only after durable receipt. A database failure returns non-2xx so Pixelforge can retry.

Keep signature verification in middleware/a dedicated verifier, not business logic in the controller. Exclude only this provider endpoint from CSRF protection. Handle ping and safely ignored event types explicitly. Unmatched/conflicting valid events remain inspectable instead of being silently discarded.

Queue dispatch is an accelerator, not the only recovery mechanism. A scheduled sweep finds pending submissions, unprocessed inbox events, unpublished outbox entries and stale accepted generations. It safely redispatches/reconciles them. This covers a crash between committing a record and dispatching its job; duplicate work remains safe.

Publish an outbox notification only after its state transaction committed. Mark it published after the hub acknowledges it. A crash between publish and marking may duplicate the notification, which is acceptable. A plain `afterCommit` callback alone is not durable publication.

Configure worker timeout shorter than queue reservation expiry; bound provider requests accordingly. Record exhausted jobs and expose operational failures. Recovery scans are bounded and respect provider quotas; never poll Pixelforge per browser connection.

## 8. Live UI: private SSE and Inertia

Enable the bundled Mercure hub in Caddy. FrankenPHP includes it, disabled by default.[3] Caddy/Mercure holds SSE connections; PHP does not keep a request open while repeatedly querying Postgres.

- Use one private user-authorised subscription for generation changes. Laravel authenticates the user and grants access only to that user's topic; a guessed topic is not authorisation.
- Use secure, scoped subscriber credentials; separate publish authority from subscribe authority. Do not place bearer tokens in URLs or expose publisher secrets to JavaScript. Verify the exact bundled Mercure authentication/version contract before implementation.
- Publish small invalidation messages containing generation identity/revision, not financial truth or sensitive request payloads.
- On an event, coalesce refreshes of the relevant Inertia props. Models own the returned shape and owner-scoped queries.
- Once the subscription is established, refresh authoritative state; repeat after reconnect. This closes the initial-load/subscription gap and repairs missed notifications. Duplicates/out-of-order notifications must be harmless.
- Show reconnecting/disconnected state and offer manual refresh; do not pretend a disconnected page is live. A page reload always reconstructs current state.
- Verify streaming through Caddy and Traefik, exclude the stream from response compression/buffering, and verify logout/token expiry prevents continued private access.

Inertia supplies page props, forms, validation errors and navigation—not a second application state store.[5] Any browser-side estimate is advisory; Laravel recalculates authoritative values.

## 9. Product, reporting and operations

Build one cohesive workspace: generation form, estimate/breakdown, live progress, result/history, prompt reuse, accessible controls and deliberate empty/error/expired-image states. Distinguish submission rejection, temporary retry, unknown outcome and accepted-generation failure.

The operator report aggregates immutable settlements by **provider completion day in UTC** and user. Fetch provider usage via Saloon and compare exact costs/token totals for the same API key/date range. Show zero-use days and unmatched/provider-only generations as discrepancies; do not overwrite local evidence to manufacture agreement. Restrict reports, diagnostics and reconciliation controls to authorised operators.

Use one provider API key/signing secret per environment.[4] Keep provider secrets, app key and Mercure credentials outside Git and image layers. Log correlation IDs and error codes without secrets or unnecessarily duplicating prompts/reference URLs. Public deployment requires a genuine public HTTPS webhook endpoint; local Fairlead DNS/Tailscale does not provide one by itself.

### Runtime target

One app container contains compiled Vue assets, FrankenPHP/Caddy with Mercure, a Laravel queue worker and scheduler under a process supervisor. Handle termination/graceful shutdown, worker restarts on deployment and failures of supervised processes. HTTP health alone must not conceal dead background processing.

Dokploy deployment requires a separate Postgres container/service with persistent storage, backups and tested restore; do not put Postgres inside the app container or expose its port publicly. Start with Postgres 18 to match local Fairlead. Supply the private connection string as `DATABASE_URL`; never reuse local credentials. Persistent app storage remains for runtime files and any configured Mercure history, not the primary database. Migrations are explicit deployment operations, never destructive startup behaviour. The initial app deployment remains single-instance; scaling still requires decisions about sessions, storage, background work and hub delivery.

Fairlead provides local Postgres, HTTPS routing and SMTP. It provisions `pixelforge` for the app and a separate `pixelforge_test` database/role for tests. Laravel maps Fairlead's `DATABASE_URL` into its Postgres connection. No S3 resource is provisioned; Valkey is available but not required for this design. Image URLs remain provider-hosted and may expire. Do not add permanent image storage until it is a product requirement.

## 10. Evidence and implementation order

Tests must cover the invariants rather than only the happy path:

- Pricing tables, Unicode prompts, reference-image usage, reasoning variation, markup, rounding and quote/final-rate differences.
- Duplicate submissions, timeout then same-key retry, error-specific delays and exhausted-but-uncertain work.
- Duplicate/concurrent completion, missing intermediate events, processing-after-success, conflicting terminal payload and early webhook/create-response races.
- Signature/payload rejection, ping, durable receipt, processing/publication crash recovery and duplicate outbox delivery.
- Cross-user access, operator access, private SSE credentials, reconnect refresh and expired/logout subscriptions.
- Exact daily/user reporting, UTC midnight boundaries, provider-only costs and reconciliation discrepancies.
- Postgres concurrency and rollback tests using independent connections/processes, not just sequential tests. Tests use the dedicated `pixelforge_test` database and role via `TEST_DATABASE_URL`; bootstrap refuses the application database or cached application config before any destructive database reset. Do not substitute in-memory SQLite for Postgres integration tests.
- Browser smoke coverage of the complete journey, plus a real streaming check through the deployed proxy.

Build in vertical slices: (1) local form/history and model boundaries; (2) pricing and immutable submission; (3) Saloon submission and recovery; (4) webhook application/settlement; (5) private SSE/outbox; (6) reporting/reconciliation and public deployment. Run a deployment smoke check early rather than leaving hosting until the end. Balance functionality remains deferred.

## Sources

[1] https://docs.saloon.dev/the-basics/connectors

[2] https://docs.saloon.dev/digging-deeper/retrying-requests

[3] https://frankenphp.dev/docs/mercure/

[4] https://pixelforge.promptedit.dev/docs

[5] https://inertiajs.com/docs/v3/the-basics/forms

[6] https://www.postgresql.org/docs/current/explicit-locking.html
