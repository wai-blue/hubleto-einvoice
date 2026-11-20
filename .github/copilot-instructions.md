# Hubleto e-Invoice review guidance

## Context and review scope

This repository contains an existing external Hubleto app, not a new custom app.
Its namespace is `Hubleto\App\External\WaiBlue\Einvoice`; its URL slug is
`einvoice`. Preserve the external Composer package structure.

The following Hubleto guidance is the basis for the architecture checks below:
- https://developer.hubleto.eu/v2/ai-assisted-coding/app-creation-plan
- https://developer.hubleto.eu/v2/ai-assisted-coding/app-creation-constraints

When reviewing, inspect the changed code and relevant callers. Prioritize
security, data loss and behavioral regressions. Give each finding a severity,
location, impact and concrete failure scenario. Label uncertainty and distinguish
minor convention issues from functional defects. Do not invent findings.

Review newly introduced problems and existing problems made reachable by the PR.
Do not turn a focused update into an unrelated rewrite. Planning guidance should
help identify missing decisions, risks, dependencies and integration effects;
do not demand a complete app-creation plan for every small change.

## Hubleto architecture and conventions

- Follow established Hubleto architecture and comparable community-app patterns.
  Keep responsibilities separated and naming, structure and formatting consistent
  with the surrounding code.
- Reuse existing framework services, components and extension points instead of
  duplicating behavior. Avoid unnecessary abstractions and unrelated refactoring.
- Check that data relationships, lifecycle behavior and integrations remain
  consistent, and that features are registered and reachable as intended.
- Keep the UI consistent with the shared theme and ensure user-visible text can
  be translated. Review PHP, React and Twig together where behavior spans layers.
- Verify compatibility with supported Hubleto versions. Base recommendations on
  available APIs and code, and state limitations when upstream context is missing.
- Apply documentation in the context of an existing external app, not mechanically
  as a new-app scaffold checklist. Explain the practical impact of deviations
  rather than requesting boilerplate solely for conformity.

## Security checks

- Check authentication, record-level authorization and input validation on every
  changed read or write path, including custom API actions and downloads.
  Readonly or hidden UI controls are not authorization. Validate server-side
  writable-field allowlists, status transitions and identifiers; reject or ignore
  attempts to alter protected fields, save relations or delete through save APIs.
- Check request methods and the host's CSRF protections for state-changing
  actions. Look for mass assignment, SQL injection, unsafe file paths and XSS.
  Use parameterized queries and context-appropriate output escaping. Treat XML,
  provider responses and error messages as untrusted input.
- Check XML parsing for external-entity/network access, entity expansion and
  resource-exhaustion risks. Review remote download URLs and redirects for SSRF;
  preserve TLS certificate verification and request timeouts.
- Never embed secrets in source, HTML, client responses, logs or exception traces.
  Masking an input is not encryption. Provider credentials needed for outgoing
  authentication cannot be replaced by one-way password hashes. If storage
  encryption changes, inspect key handling and compatibility with existing data.

## e-Invoice integrity and regressions

- Provider-controlled XML, transmission IDs and delivery states must not become
  arbitrarily editable. Review manual sending preferences separately from
  provider-confirmed states. Do not permit resending an already tracked document.
- `Will not be sent` documents must not offer sending or appear in unsent
  reminders/counters. Counts must agree with direction, status and transmission
  eligibility, not merely the visible label.
- Imports must be repeatable without duplicate invoices or suppliers. Check
  transaction boundaries, concurrent imports, document linking and rollback on
  failure. Preserve standard invoices, credit/debit notes, external numbers,
  quantities, discounts, VAT, currency and totals. Validate descriptive zero-value
  lines without accepting inconsistent financial data.
- Purchase-order creation/linking during inbound import is intentionally disabled.
  Do not re-enable it incidentally or recommend deleting historical orders.
- Check cron registration, pagination/cursors, overlap prevention, partial errors
  and persisted synchronization results. Account/provider changes must not reuse
  another account's cursor. Notifications need deduplication and controlled retry.
- Check whole-card mouse/keyboard navigation, nested action isolation, form Save
  availability, filter order and safe new-tab links. Do not restore list actions
  deliberately restricted to the detail form.

## Deployment, evidence and review limits

- Review schema changes for existing-data safety, foreign keys and compatibility.
  Flag destructive reinstall/update paths. The enterprise and external e-Invoice
  apps share routes/tables and must not be enabled together.
- Verify declared Composer dependencies/extensions, supported PHP/Hubleto versions,
  app manifest dependencies and frontend registration/build prerequisites.
  Derive URLs from the host environment; do not introduce localhost, developer
  filesystem paths or mandatory server-specific edits into production behavior.
- Check that changed behavior and deployment requirements are documented where
  needed. Flag undocumented core/framework patches and new configuration needs.
- Look for PHP, TypeScript, Twig/build and behavioral verification appropriate to
  the change. Identify missing evidence; never claim tests passed without results.
  Do not require local ignored vendor folders or lockfiles to be committed merely
  because they are absent from this library repository.
- Automated review is advisory and does not prove production safety. Do not merge,
  deploy or change branch protections as part of reviewing a PR.

## Review summary format

When performing a code review, include a concise overview using this structure:

1. **TL;DR:** In two or three sentences, summarize whether you found concrete
   security, data-integrity or functional problems and whether any need fixing
   before merge. If none were found, say so without claiming the PR is safe.
2. **Findings:** Order actionable findings by severity. For each, provide the
   affected file/line, the failure or attack scenario, the impact and a focused
   correction. Distinguish confirmed defects from potential issues needing
   verification. Avoid speculative findings without a plausible code path.
3. **Verification gaps:** Briefly list relevant checks or runtime behavior that
   were not verified and any specific tests needed. Do not invent test results.

Keep the overview short and use English. Put detailed findings in inline review
comments where possible; do not repeat their full text in the overview. If there
are no findings, explicitly state that and still identify verification limits.
These instructions guide review feedback; they do not replace CI or human approval.
