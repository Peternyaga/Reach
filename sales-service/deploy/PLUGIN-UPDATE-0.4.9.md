# Fieldwork Reach plugin 0.4.9 — prepared, not published

This archive contains the `sales-workspace-hosted` source directory. Installed cache inspected on 15 October 2026 is **0.4.8**; the previous publication checkpoint records marketplace 0.4.8. The new **0.4.9** source is prepared locally and has not been published, installed, or copied into an active cache/poller. Running a marketplace refresh now will not retrieve this unreleased package.

0.4.9 adds durable research workflow guidance and a bounded document reference, plus the pre-existing uncommitted agent-stage guidance and poller prompt. Preserve those stage changes when publishing. OAuth endpoint, requested scopes and permissions are semantically unchanged from the installed0.4.8 configuration. Raw `.mcp.json` formatting differs; the parsed configuration is identical. No new email permission or outreach authorization is introduced.

## Deployment and distribution order

1. Peter self-deploys the matching server ZIP first, using `RESEARCH-2026-10-15.md`. Check existing workflows and the new research endpoint before using research tools. The website's deliberate JSON preview/save works independently of publishing this plugin.
2. If Peter chooses to publish the plugin, extract this ZIP, review the complete diff, and copy its `sales-workspace-hosted` directory to `plugins/sales-workspace-hosted` in the existing Vumbua Labs marketplace repository. Preserve other plugins and catalog entries. Update any catalog version for this plugin to0.4.9, then commit/push through the established publication workflow. This preparation does not perform that publication.
3. **Only after0.4.9 has actually been published**, update each worker's marketplace/plugin:

```powershell
codex plugin marketplace upgrade vumbua-labs
codex plugin add sales-workspace-hosted@vumbua-labs
```

Restart Codex, confirm installed version0.4.9 and use a fresh chat to discover `get_lead_research` / `update_lead_research`. Verify account/project with read-only calls before authorized writes. Existing valid OAuth grants remain valid; do not rotate or expand permissions merely for this release. The local CLI's `plugin marketplace --help` and `plugin add --help` confirm these commands; [official OpenAI plugin packaging guidance](https://developers.openai.com/plugins/build/plugins) describes marketplace refresh and cache behavior.

4. If using the existing Windows poller, refresh its script from the newly installed package according to that package's README, preserving the existing workspace, pairing/configuration and scheduled task. Its installer supports:

```powershell
& '<newly installed plugin path>\scripts\install-poller.ps1' -Accept -WorkspaceRoot 'C:\Users\HomePC\Documents\ChatGPT\local control plugins'
```

Resolve the actual installed0.4.9 path rather than guessing a cache folder. Review the scheduled task and script version before resuming it. No poller has been refreshed or triggered by this implementation.

Do not upload this plugin ZIP to `public_html` as part of server deployment. It is a separate source/distribution artifact, not a server installer. The package contains no pairing tokens or runtime credentials.
