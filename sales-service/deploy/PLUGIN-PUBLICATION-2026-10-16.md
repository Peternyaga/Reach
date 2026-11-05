# Reach plugin publication — 16 October 2026

Authorized by Peter in this chat after confirming server deployment. The previous preparation-only restriction is superseded only for marketplace publication and local plugin refresh. No server deployment, customer/lead mutation, grant expansion or scheduled-poller action occurred in this publication task.

## Published and verified

- Marketplace: https://github.com/Peternyaga/vumbua-labs-plugins
- Commit: https://github.com/Peternyaga/vumbua-labs-plugins/commit/5107e68f0db3b0e03618cf6285372f8660216b9c
- Remote main was reread and matches that commit. Fieldwork Reach manifest is0.4.10. The catalog has no separate version field; plugin identity/path/policies were preserved.
- Reviewed commit changes only the Reach manifest, README, agent/profile skills and Reach tester guide. Timeline Curator tree hash is identical before/after. Poller scripts and bounded research reference differ in checkout newline/encoding only; their text/behavior is unchanged. All three skills pass validation; four PowerShell scripts parse without execution.
- Updated documentation states the terminal commands require a usable Codex CLI. It no longer claims an unverified personal-account marketplace-add menu. The deployed Reach website connection page was not edited or redeployed.
- Public hosted discovery confirms33 tools, list_team requiring project_id, and assign_lead exposing explicit pending-follow-up transfer / request-review choices. No authenticated customer record was read.

## Local installed state

| Plugin | Workspace source | Installed cache | State |
|---|---|---|---|
| Fieldwork Reach from Vumbua Labs |0.4.10|0.4.10|Enabled;11 file contents match workspace and committed release after CRLF/BOM normalization|
| Local Sales Workspace |0.1.1|0.1.1|Enabled; all6 files byte-identical to workspace source|
| Fieldwork Reach from fieldwork-local |0.4.10 development entry|Existing disabled copy|Remains disabled; no duplicate hosted provider enabled|

Refresh used the registered Vumbua Labs marketplace upgrade, then plugin add for sales-workspace-hosted@vumbua-labs. Both completed successfully. Endpoint and requested OAuth scopes match existing configuration. No OAuth logout, grant rotation, credential printing, pairing or scheduled worker refresh/trigger was performed.

The installed files are current; restart the desktop app and open a fresh chat to load refreshed skills/tool discovery. This task did not restart or close the user's app.

## Source and archives

The original extensive dirty workspace is preserved; no reset, stash, clean or unrelated commit was made. Publication used a separate clean checkout under sales-service/publish/vumbua-labs-plugin-release-0.4.10. Local prepared ZIPs were regenerated with the release README and updated guide; see publish/RELEASE-CHECKSUMS-2026-10-16.json for current hashes. The previously delivered/deployed server ZIP is unchanged. CRC, inventory, exclusions, known-secret absence and source parity verify for all three archives.
