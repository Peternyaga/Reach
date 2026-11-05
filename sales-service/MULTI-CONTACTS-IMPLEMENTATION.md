# Multiple lead contacts — 19 October 2026

Authorized scope: database, API, hosted/local plugin tools, profile and intake UI; preserve single-contact compatibility. Prepare and test locally. No server deployment or marketplace publication is part of this change.

Design: retain the existing `contacts` row as the opportunity anchor. Store labelled routes in `lead_contacts`, scoped through their parent lead. A primary route mirrors phone/email/contact_role into the legacy lead and anchor fields; the lead name and assigned salesperson remain independent. Legacy migration retains phone/email with unknown ownership and no inferred person name. Initialization is marked so deleting the last route cannot resurrect it.

Mutations are explicit add/update/remove/set_primary operations, serialized with existing workspace authority locks and checked against the current lead version. Omitted routes are untouched; malformed or stale batches roll back entirely. Existing profile edits touch only the primary route. Removing a primary clears legacy fields unless an explicit replacement is selected in the same batch. Existing email consent continues to bind the exact primary recipient and lead version.

Completed locally: schema/backfill, versioned individual contact operations, primary/legacy synchronization, secondary-route duplicate detection, hosted/local MCP creation and update tools, intake/profile controls and readable contact history. Sourced changes appear in queued agent reports; completed requests cannot authorize another change.

Validation:
- `php tests/lead-contacts.php`: 30 checks, including hosted create/update, three labels, shared/unknown identity, legacy edits/migration, rollback, source authority and project/role restrictions.
- `php tests/contact-protocol.php`: 12 checks through the real local MCP bridge and loopback HTTP API.
- `php tests/contact-race.php`: 5 checks with two independent PHP writers, one commit/one conflict and one primary.
- Affected regression runner: lead-profile, projects (91), agent-automation, workspace-sync, agent-stage, research (55), integration, mail. No failing check. Existing local MCP handshake/discovery and research protocol (8), team access (81) and team concurrency (6) also pass. Mail uses the existing fake transport; no outreach.
- Production build, all backend PHP syntax, local MCP syntax and all three edited skill validators pass.
- Browser fixture using production components: three labels/direct/shared/unknown, legacy one-contact view, individual edit, add unnamed shared office address, remove office while preserving three routes, select primary, and submit three labelled intake rows. Browser state is synthetic; durable persistence is separately verified by database/HTTP/MCP tests. Screenshot: `var/contact-ui/three-contacts.png`.
- ZIP verifier passes CRC, file inventory, exclusions, known-secret absence and byte-for-byte workspace/staging/archive parity.

Prepared artifacts: `publish/fieldwork-reach-upgrade.zip` (40 files), `publish/fieldwork-reach-plugin-0.4.11.zip` and `publish/fieldwork-local-plugin-0.1.2.zip`. Checksums: `publish/RELEASE-CHECKSUMS-2026-10-19.json`. Server SHA-256: `824eafa11e14ad68993a44c273cc644843e1c1b619968a186837d72fe1637eb8`; hosted plugin: `c81f774cf60ef72e17e132d154759f66593ba724645fa54151a1da5093a12973`; local plugin: `8b180cc2e8122f598f7ca56d69ac700aa7db6adc7e4ef3a74283617220367b45`.

At implementation completion there was no remaining blocker and no deployment/publication or installed-cache update had been performed. A downgrade requires matching database and files.

Publication follow-up, 19 October 2026: Peter explicitly authorized marketplace publication. Hosted 0.4.11 is now on Vumbua Labs main at `17af30ee503ca2f7c49aa14d35bc809cd57cc471`, with all 11 plugin source files matching the workspace after newline/BOM normalization. Public discovery confirms the live server exposes 34 tools and the new contact schemas. Installed caches were not refreshed in this publication. Local 0.1.2 remains prepared and unpublished. Regenerated plugin ZIP checksums supersede the initial preparation hashes above; use `publish/RELEASE-CHECKSUMS-2026-10-19.json` and `deploy/PLUGIN-PUBLICATION-2026-10-19.md` for current evidence. The server ZIP is unchanged.
