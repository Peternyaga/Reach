# Reach refinement implementation

Design contract: REACH-REFINEMENT-PLAN-2026-10-14.md. Work resumed 15 October 2026, Africa/Nairobi. No production writes, outreach, deployment or plugin publication are authorized in this implementation chat.

## Baseline and checkpoint

- Preserved the pre-existing dirty tree: signup, projects, mail, profile, agent work and plugin 0.4.8 work. No reset, stash or clean performed.
- Initial additions: `backend/lead-research.php`; additive `lead_research` and `research_requests` tables; scoped browser research endpoint and additive hosted MCP tools. List summaries omit evidence payloads; collector reads retain their existing field restrictions.
- Contract: version 1 bounded narrative fields; evidence with explicit observed/inferred/unconfirmed/conflicting status and evidence-strength confidence; uncertainties, qualification questions, priority and rationale, missing-contact reason, next action and hold reason. Proposed service/price are suggestions, never confirmed budget. Human review metadata is assigned by the server. Agent saves cannot mark reviewed. Revision 0 starts research; optimistic revision conflict returns 409; stable actor-bound request receipts deduplicate retries. No social field is required.
- Initial validation before interruption: `php -l backend/lead-research.php` passed. No database tests or UI preview completed yet. This is a partial implementation, not release-ready.
- Supervisor review fixes queued: strict date parsing (false is not null); audit payload capacity for two 60 KB documents; preserve draft during navigation/sync; safe links throughout history; populated preview early.
- Current listeners: none detected on 3306, 5178, 8788, 8792 at resume.

## Next step

Fix date/audit storage, establish local services and research tests, then implement the full-width tabbed workspace and populated fixture. Continue through responsive list/Today, agent/intake, regression tests, browser review and backup-first release ZIP. Record exact results below. Deployment and publication remain pending supervisor action.

## Frontend preview checkpoint — 15 October

- Populated local preview: http://127.0.0.1:5186/tests/reach-fixture.html (confirmed loaded in Chrome). Synthetic records only, memory-backed actions. Includes ready/hold/legacy/Lost/long-name cases, 18 activities and two opportunities. The prior 5178 listener was unresponsive; fresh Vite on 5186 is working. Earlier sandbox-only listener inventory was incomplete; no customer database changes were made.
- Added `src/reach-workspace.jsx`, `src/reach-views.jsx`, `src/reach.css`, `tests/reach-fixture.html`, `tests/reach-fixture.jsx`; integrated workspace/hash navigation, list filters, Today groups and hold-aware owner assignment in `src/main.jsx`; scoped broad shell CSS in `src/style.css`; profile editor now supports deliberate open-edit action and safe history links.
- `npm run build`: PASS (51 modules, production output generated; 1.84s).
- Date validation now explicitly checks parse success; activities body upgraded additively to MEDIUMTEXT to preserve old/new bounded research documents. Fresh local migration passed outside socket sandbox.
- In progress: real-edit draft protection in all tabs; concise revision history; deliberate JSON preview/import; backend/role/race/regression tests and browser dimensions. This remains pre-release and undeployed.

## Verification checkpoint — 15 October (before quota continuation)

### Passed

- `php tests/research.php` on loopback API 8793: **55 checks PASS**, including date 422s, unsafe URLs, role/project/workspace isolation, ready/hold validation, agent review denial, idempotent retries, stale 409, server reviewer attribution, MEDIUMTEXT audit payload >65,535 bytes, unchanged legacy notes, summary payload, sync fingerprint, research owner without first-contact task, atomic structured intake and rollback/retry.
- `node tests/run-reach-regressions.mjs integration lead-profile projects agent-automation workspace-sync`: **exit 0**. Existing integration suite all checks passed (124 baseline assertions), profile 21 PASS, project isolation **90 PASS** (two new tool-context assertions), agent suite all PASS, workspace sync integration all PASS. Runner writes/removes temporary sidecars to redirect the old hardcoded 8788 test URL to working 8793; source tests preserved.
- `node tests/workspace-sync.mjs`: **8/8 PASS**.
- All backend `php -l` checks: PASS. Local MCP `node --check`: PASS.
- Latest `npm run build`: PASS, 51 modules, 2.77s, 337.02KB JS / 44.92KB CSS before gzip.

### UI and source state

- Working synthetic preview: **http://127.0.0.1:5186/tests/reach-fixture.html**. Local API: **http://127.0.0.1:8793/api**. MariaDB reachable on3306 outside the socket sandbox. Old5178/8788 listeners were unresponsive; don't use them for validation. Avoid repeating detached starts. Socket-requiring tests need approved `require_escalated` in this environment.
- Parent desktop1440 review found readable layout; parent initial mobile390 review found no horizontal overflow but excessive header and late next action. Implemented compact mobile header/tabs and ordering: story/why, next action, contact, evidence/gaps, conversation/readiness. This hierarchy still needs post-change browser validation.
- Replaced workspace native unsaved-form confirms with accessible in-page Keep editing / Discard changes dialog. Parent's browser was stuck on the old native confirm; its failure to inspect is not UI acceptance or a confirmed app defect. Actual dirty forms now use shared draft tracking; untouched opportunity forms no longer hold sync forever.
- Research JSON supports deliberate single-document parse, server validate/preview, explicit use-in-draft then revision-safe save. Batch import rejected; different lead ID rejected. Unknowns/questions remount after intentional import. History shows concise research revision/state/count summaries; old/new full documents stay in a technical disclosure. Legacy original context remains near Overview.
- Optional structured intake now saves lead graph + research atomically in one transaction. Basic collector/CSV capture unchanged. Collector cannot acquire research write access. Local plugin-authenticated session marker and research-agent endpoint disallow human review certification by agent paths. Source local MCP has additive get/update research tools with stringified document and research revision; hosted MCP get/update tools were added earlier. No installed cache changed.

### Exact remaining work / next steps

1. **Intake/agent source finishing:** add optional research property to hosted create_lead schema (handler already accepts it), update hosted/local agent/readme/skill guidance with final bounded document shape, identity/dated sources/inference/conflicts/missing-contact/readiness/hold rules and retry/review semantics. Check normal sign-in clears local agent_session marker. Add final intake read-only/draft navigation check.
2. **Fixture behavior:** its fake API currently treats `research-preview` as generic action, so JSON import preview needs a realistic fake normalized response. Track fixture research revision beyond1, and install shared draft protection in fixture (main app already installs it). Add meaningful Today fixture groups/tasks if needed; safe synthetic only. Do not import actual production dossiers here.
3. **Browser verification:**1440x1000,1024x768,390x844,short height/keyboard; current mobile order/header; lead list filters/reset; tab keyboard and in-page keep/discard; editor save/cancel; simulated409 preserving draft + saved-doc compare; JSON preview; legacy/hold; opportunity edit/create; no accidental email. Main actual authenticated hash deep links/back/refresh and background-sync stale draft still need real local session review. Parent owns further visual review; do not claim its acceptance.
4. **Remaining tests:** focused true simultaneous research writer/retry race if feasible; hosted OAuth32, signup25, mail suite; local MCP protocol against isolated credentials after additive tools; validate prepared handoff documents locally (read only) against final contract. Re-run affected tests after actual code changes; don't rerun everything without reason.
5. **Release:** update deployment note/README/progress, include research release note in ZIP packaging; remove duplicate signup copy in packaging if editing same block; build backup-first upgrade ZIP, inspect private-file exclusions/integrity/source equality/hash. No publication/deployment/hydration from this chat. Existing production IDs are not in migrations/tests.

### Quota and continuation

Actual usage retrieved: five-hour **13% remaining**, weekly **12% remaining**. Five-hour reset timestamp1792053939 =15 October2026 11:45:39 Africa/Nairobi; weekly timestamp1792477352 =20 October2026 09:22:32 Africa/Nairobi. Parent already owns the same-chat continuation heartbeat `resume-reach-refinement-supervision` for11:50 Nairobi today. Do not create a duplicate child heartbeat or consume a reset credit. Check actual quota and filesystem state before resuming. Task remains unfinished and undeployed.

## Latest handoff (quota low; stop before extended checks)

- Follow-up source fixes after the55-check run: hosted `create_lead` schema now exposes optional atomic research; local MCP get/updateresearch tools added with research-agent endpoint; ordinary sign-in clears `agent_session`; fixture now installs shared draft tracking and returns a JSON import preview. These last fixture/sign-in/schema fixes still need focused browser/regression verification.
- Previous stuck8788 integration attempt exited with a connection timeout before assertions. It is an environment/listener failure; the full redirected8793 run subsequently passed. Do not report the timed-out attempt as a code-test pass. It may have created one disposable `Integration test` workspace/user before the request failed; identify by exact test-run records before any cleanup, never delete by broad name alone.
- Local fixture source has no durable backend writes. Current fake research revision still always1: improve it before testing repeated save/conflict behavior. Main actual UI revision comes from server and is correct.
- Parent reports five-hour7% remaining and owns verified11:50 Nairobi continuation. No child automation created. No deployment, publication, installed-plugin refresh or production dossier hydration performed. No release ZIP built for this refinement yet.
- Resume from remaining-work list above, starting with fixture/API proxy pointing8793, source workflow guidance, browser keyboard/draft/save/conflict/JSON/deep-link review, then OAuth/signup/mail/protocol tests and release packaging. Preserve all existing dirty changes and this log.

## Resumed phase — 15 October, after quota reset

- Real local app: http://127.0.0.1:5187/ (Vite env `FIELDWORK_API_PROXY=http://127.0.0.1:8793`); API8793; synthetic fixture remains http://127.0.0.1:5186/tests/reach-fixture.html.
- Peter explicitly requested starting the app and signing in. Saved `var/local-account.json` credentials were stale; existing account was not reset. Created **one separate disposable `Reach local preview` workspace** with owner `Peter · Local preview` and two demo businesses (Savanna Events / Amber Workshop). Credentials are privately saved in ignored `sales-service/var/reach-preview-account.json`; do not publish them or bundle `var`. The IAB login turn verified the Today view with both synthetic records. Screenshot: `tmp/reach-preview-signed-in.png`. Parent may use that local-only file to sign in its independent browser session.
- Added configurable Vite API target while preserving8788 default. No production credentials/data changed.
- Fixture now models increasing research revisions, idempotent receipts, distinct server revision on simulated conflict, JSON preview, activity/opportunity updates, and Overdue/Today/Upcoming sample tasks. Shared draft protection installed. Parent is currently reviewing it; avoid unnecessary HMR edits until that review completes.
- Parent reported revised mobile hierarchy, Keep editing draft preservation, successful research save and simulated409 preserved draft/compare as passed. Parent's remaining import/keyboard/list and real app review are pending.
- Added explicit hosted JSON research schema; omitted claim status now defaults **unconfirmed**, never observed. Source workflow guide added under hosted agent-work references; linking/source docs and validation are in progress.

## Final verification and prepared artifacts — 15 October

### Completed after resumption

- Research HTTP/model suite: **55 PASS** on8793 after final schema/default changes.
- True concurrency: `php tests/research-race.php` **7 PASS**, using two independent PHP processes. Competing revision0 writers produce one200/one409 and one audit. Simultaneous identical request retries produce two200s with one replay, one revision increment and one audit.
- Hosted OAuth suite: **32 PASS**. Signup suite: **25 PASS**. Mail suite: **21 PASS**, fake transport only; no real email.
- `php tests/local-protocol.php`: original handshake/discovery/authenticated project/lead test PASS plus **8 research MCP checks PASS** (discovery, absence, save, replay, stale conflict, readback, human-review denial, project isolation). Temporary credentials/workspaces are removed in finally. First research protocol harness attempt timed out because a Windows CLI path was passed as a file URL; corrected to fileURLToPath and rerun passed. This was a test harness fault, not an app result.
- All backend PHP syntax PASS; local MCP Node syntax PASS. Source poller PowerShell parser PASS. Three changed skill validators PASS (agent-work requires Python UTF8 mode on Windows; first default-codepage attempt failed decoding, rerun `python -X utf8` passed).
- Four supplied handoffs validate read-only against the supplied draft contact routes: TopSound4 evidence, Dar-ul-Shifa3, BestAfrica5, Shades2; first three ready_for_review, Shades on_hold. No database connection/write or customer fixtures. Initial document-only readiness check lacked the separate parent contact fields; corrected validator joins the supplied draft contacts. Actual current deployed contact/revision must still be reread before any later save.
- Source workflow guides/reference added for hosted agent work/profile and local MCP; scopes remain unchanged. Hosted schema exposes bounded fields instead of a generic research blob, and omitted evidence status defaults unconfirmed. Existing agent-stage guidance/poller changes from the other chat were preserved.

### Browser / sync

Supervisor acceptance evidence is in `REACH-SUPERVISOR-QA-2026-10-15.md`: desktop1440, tablet1024, mobile390x844/390x600; no horizontal overflow; hierarchy corrected; tab keyboard, hold filter, JSON preview/use, multiline fields, save/cancel/keep/discard, untouched opportunities, real deep link reload/back/forward and real two-tab stale conflict/draft preservation. Screenshots remain in `../tmp` (not packaged).

Investigated sync notice: direct preview-account fingerprint and `/sync` on8793 and5187 returned200; old5186 listener was unreachable. Restored5186 with explicit `FIELDWORK_API_PROXY=http://127.0.0.1:8793`. Rechecked **8793/5186/5187 sync200**. Parent subsequently reloaded its authenticated5186 lead: deep link/story retained and interruption notice cleared. No sync backend defect reproduced; no unverified workaround added.

### Packages prepared; not deployed/published

- `publish/fieldwork-reach-upgrade.zip`: **190,013 bytes**,36 files; SHA256 `b57d67660ff660211a671f9d501aa80bd13f769e317992fc993e89ece2316088`.
- `publish/fieldwork-reach-plugin-0.4.9.zip`: **25,305 bytes**,12 files; SHA256 `829f1781ff7a4008acab1456cafd0df7f60a21cb425beba47c88662008c8e777`.
- `publish/RELEASE-CHECKSUMS-2026-10-15.json` records current hashes/size/state. `python -X utf8 tests/verify-reach-release.py`: **PASS** CRC/inventory, safe relative paths, no var/sessions/tests/config.local/.env/keys/local helpers, known local credential absence, archive-to-stage and stage-to-backend/dist/plugin source parity,0.4.9 manifest and bundled research reference. Installer has LF newlines. Initial validation ran before the async server build completed and correctly found the old ZIP/source mismatch; after build completion the current archives passed.
- `npm run package:hosted`: PASS,51 modules; built CSS44.92KB / JS337.15KB. `node scripts/package-reach-plugins.mjs`: PASS. Server note includes Peter's self-deployment command matching prior Reach releases. Plugin guide distinguishes unpublished source, installed0.4.8, later marketplace publication, installed refresh and existing poller script refresh. Current parsed MCP configuration equals installed0.4.8 (same endpoint/scopes); raw formatting differs. No installed cache/poller or marketplace was changed.
- Plugin source version is now **0.4.9 prepared/unpublished**. Previous release checkpoint and inspected installed cache are0.4.8. The server ZIP does not publish or install the plugin. Peter can use deliberate website JSON import after server deployment before publishing0.4.9.

### Ready / not ready boundary

Ready locally: implementation, isolated backend/model/concurrency/OAuth/signup/mail/MCP verification, supervisor browser checks and inspected server/plugin deliverables. Final affected regression command is completing; record its result below before handoff. No outstanding implementation blocker is known at this checkpoint.

Not performed: production deployment/acceptance, production dossier hydration, plugin marketplace publication/installed refresh, poller refresh/trigger, outbound outreach or real mail deliverability tests. Peter will self-deploy. Existing limits retained: no email verification/CAPTCHA/automated owner recovery; one account/workspace per email. Source dirty tree preserved; no reset/stash/clean. Parent owns continuation automation; none duplicated here.

## Final signoff checkpoint

- Final affected pass completed integration, profile, project90, workspace-sync and agent-stage suites successfully. Agent-automation initially timed out at the new stage fixture HTTP note request (tests/agent-automation.php line180 at the time): the preceding direct hostedWrite rejection left the test caller's PDO transaction open, so the newly inserted stage lead was uncommitted and blocked the separate HTTP connection. Added explicit rollback immediately after that expected helper rejection. This is test cleanup, not a production behavior change. **Reran only agent-automation: exit0, all83 assertions PASS**, including the new sourced-stage block and poller revoke checks. No outstanding failing test remains. Test-created rows are cleaned in finally.
- Parent independently verified both archive integrity/exclusion/source-parity checks and the exact hashes listed above. Current guide explicitly says Peter self-deploys. The test-only rollback fix does not affect packaged source; hashes remain unchanged.
- Research model, API/MCP, optional atomic intake, responsive UI, legacy compatibility, source agent guidance, independent writer races, required regressions and inspected deployment/plugin deliverables are complete for local handoff. Peter's self-deployment, live acceptance, four existing-lead research saves,0.4.9 marketplace publication/installed refresh and poller refresh remain separate external steps, not completed work.
- No production deployment, plugin publication, installed cache edit, poller trigger, external outreach, reset-credit use, or destructive dirty-tree operation occurred. Parent owns automation. All changed pre-existing work was retained, including the other chat's agent-stage refinement.
