# Fieldwork contact plugin release

Hosted plugin **0.4.11** was published to the Vumbua Labs marketplace on 19 October 2026 at commit `17af30ee503ca2f7c49aa14d35bc809cd57cc471`. All 11 packaged plugin files match the workspace source after line-ending/BOM normalization. Public Reach discovery exposes 34 tools, including contact creation and update schemas. This publication did not update installed plugin caches. Local plugin **0.1.2** remains prepared and unpublished; publishing the hosted package does not distribute the loopback-only plugin.

Hosted `create_lead` takes a `contacts` array; local MCP takes that array as a JSON string. Each contact supports optional name/department, phone and/or email, role/tag, ownership `direct`/`shared`/`unknown`, and optional `is_primary`. Unknown names stay empty. Contact ownership is independent of salesperson assignment.

Both expose `update_lead_contacts`: current lead version, explicit project, factual provenance and operations `add`, `update`, `remove`, `set_primary`. Local versions/operations are strings; hosted versions are integers and operations are arrays. `get_lead` returns every visible contact with its stable ID. Omitted contacts are preserved, and stale edits fail. Existing flat phone/email tools edit only primary. Removing primary clears old phone/email unless a replacement is explicitly selected.

No new OAuth scope, endpoint, mailbox configuration, permission grant or poller setup is required. Reopen the app/start a fresh chat after updating the installed plugin so the new tool catalog and instructions are loaded. This document does not assume that an app-bundled executable is a complete standalone Codex CLI.

See `CONTACTS-2026-10-19.md` for deployment, acceptance and database/file recovery requirements. Checksums are recorded in `RELEASE-CHECKSUMS-2026-10-19.json` after packaging verification.
