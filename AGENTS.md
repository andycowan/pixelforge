# Pixelforge project instructions

## Standing order: record every decision and its reasoning

The decision history is private interview material. `docs/decisions.md` is intentionally Git- and Docker-ignored; keep it local, never force-add it or link it from public documentation. On a fresh checkout, obtain the private log from Andrew or start a local record with explicit missing-history context; do not invent earlier rationale. Andrew must be able to explain and defend the choices, including why an earlier choice changed.

Before design or implementation, read:

- [Current architecture](docs/architecture.md): what the agreed system should be.
- [Decision log](docs/decisions.md): why choices were made, alternatives and trade-offs.

For every product, architecture, dependency, data, security, operational, testing or implementation decision:

1. Record it in `docs/decisions.md` before or alongside the change, not as an optional end-of-session task. Small choices with consequences—rounding, retry limits, defaults, auth boundaries—count too. Routine work following an existing decision should reference that decision rather than create a duplicate.
2. Include context/constraints, the decision, specific reasoning, alternatives considered, trade-offs/consequences, and evidence or outstanding validation. Explain why it suits this project, not merely that a tool is popular or a best practice.
3. Give the record a stable ID. Distinguish proposed, accepted, deferred and superseded decisions; separately state whether implementation/validation is complete. Do not present an assistant recommendation as user approval.
4. Preserve history. When changing a decision, retain the original rationale, mark it superseded and link both records. Correct factual errors explicitly; do not rewrite history to make the current choice look inevitable.
5. Separate verified evidence from assumptions. Never invent historical reasoning, benchmark results or user statements. Mark reconstructed rationale or uncertainty clearly and ask if it affects the decision.
6. Update `docs/architecture.md` when the agreed target changes and `README.md` when setup/operations change. Keep the documents consistent, but keep historical explanations in the decision log.
7. Link relevant code, tests, documentation or sources. Never record credentials, secrets, private tokens or real user data.

Work is not complete until its decisions and rationale are recorded. In the handoff, identify new/superseded decision IDs, checks run and remaining risks. Documentation-only work does not need an application rebuild/test run unless it changes executable instructions that need verification.

## Layer boundaries

- Thin controllers own HTTP, request validation/authorisation and responses only.
- Actions/services own business logic, workflow orchestration and transaction boundaries.
- Eloquent models encapsulate database rules, queries/scopes, valid mutations and returned-data formatting, backed by database constraints.
- Saloon owns the provider HTTP integration. Vue/Inertia owns interaction/presentation, never authoritative pricing or settlement.

## Styling

Use Tailwind CSS for all application styling. Prefer existing Tailwind-styled UI components and utility classes/theme tokens; do not introduce component-scoped stylesheets, CSS-in-JS, a second styling framework or ad hoc inline styles. Keep the Tailwind entrypoint and shared theme/token definitions as infrastructure, not an escape hatch for page-specific CSS.

## Working constraints

- Build a defensible, polished assessment product, not merely the minimum passing submission. Prefer purposeful depth over gratuitous abstractions.
- Current priority: reach feature completeness before deeper refinements or redesign. Record issues for later rather than expanding the current slice; correctness, security, financial integrity and data-safety blockers must still be fixed before shipping.
- Use Fairlead for local infrastructure/lifecycle. The app and isolated tests use separate Postgres databases and roles; tests must never use the application database.
- Read the architecture for the accepted target and implementation status. Do not imply accepted but unimplemented features already work.
- Keep changes scoped, test non-trivial logic and preserve existing data. Do not provision production resources or commit changes without the user's instruction.
