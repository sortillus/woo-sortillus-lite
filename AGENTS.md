# Agent notes

## Agent skills (Addy Osmani's agent-skills plugin)

The `agent-skills` plugin is installed. Use its skills when a task matches, by
invoking the Skill tool with the name below, instead of improvising the workflow:

- Fixing a bug or changing behavior → `agent-skills:test-driven-development` (prove the bug with a failing test first)
- Unexpected error, failing test or broken deploy → `agent-skills:debugging-and-error-recovery`
- Change touching more than one file → `agent-skills:incremental-implementation`
- New feature with unclear requirements → `agent-skills:spec-driven-development`, then `agent-skills:planning-and-task-breakdown`
- Plugin ↔ Sortillus API contract, offer payloads → `agent-skills:api-and-interface-design`
- Shop admin settings, API keys, untrusted input, escaping output → `agent-skills:security-and-hardening`
- Storefront chat widget and admin screens → `agent-skills:frontend-ui-engineering`
- Sync/export speed on large catalogs → `agent-skills:performance-optimization`
- Before opening a PR or merging → `agent-skills:code-review-and-quality`
- Before publishing a plugin release → `agent-skills:shipping-and-launch`

For independent review, use the plugin's subagents (`agent-skills:code-reviewer`,
`agent-skills:security-auditor`, `agent-skills:test-engineer`) via the Agent tool.

Skip the skills for small edits, questions and read-only investigation.
