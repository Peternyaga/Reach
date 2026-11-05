# Fieldwork Reach 0.4.10 — published marketplace release

Published to the Vumbua Labs marketplace on16 October2026 as commit5107e68. The enabled local marketplace installation is verified at0.4.10. All11 installed files match the workspace and committed release after accounting for Windows line endings/BOM; endpoint and OAuth scopes are unchanged. This release clarifies current project membership, eligible recipients, separate ownership, credential invalidation, legacy requester review and deliberate pending-work handling. Endpoint and OAuth scopes are unchanged; the server provides tool discovery/schema changes. Existing stage/research guidance is preserved.

Peter confirmed that the matching server upgrade is deployed. Public tool discovery independently verified the required project-aware team lookup and explicit reassignment handling. Publication was completed through the existing reviewed commit/push workflow; other marketplace plugins were preserved.

Published commit: https://github.com/Peternyaga/vumbua-labs-plugins/commit/5107e68f0db3b0e03618cf6285372f8660216b9c.

For other machines, refresh/install using a separately usable Codex CLI. Installing the desktop app alone does not guarantee that `codex` is available in a normal PowerShell terminal:

```powershell
codex plugin marketplace upgrade vumbua-labs
codex plugin add sales-workspace-hosted@vumbua-labs
```

Restart Codex and verify installed 0.4.10 in a fresh chat. Read projects/account before writes. A member whose access changed must reconnect deliberately with current authority; never expand scopes automatically. No poller behavior change is required for this guidance-only release, and this publication did not refresh or trigger the scheduled worker. Its scripts were parsed, not executed. The separate local plugin 0.1.1 ZIP contains its project-aware MCP schema and guidance; review/import it through the user's normal local plugin workflow only if needed. Do not upload either plugin ZIP into public_html.
