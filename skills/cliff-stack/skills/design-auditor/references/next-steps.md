# Step 4: Next Steps & Fix Loop

> Référence du skill design-auditor, chargée à la demande depuis [SKILL.md](../SKILL.md). Contenu déplacé tel quel depuis SKILL.md.

**If "Fix all Critical issues"** → fix each Critical issue in turn: show the issue name and the before/after diff (code) or design direction (screenshot), apply it, move to the next. After the last one: "All critical fixes done. Want to continue with Warning issues?"

**If "Fix a specific issue"** → present a widget listing all Critical and Warning issues by name, let the user pick one, then show the before/after diff and apply the fix.

**If "Teach me the rules behind my top issues"** → pick the 3 highest-severity unique issue types from the audit and deliver a focused design principle lesson for each. Format:

```
For each of the top 3 issues (Blocker first, then Critical, then Warning):

  Lesson [N]: [Rule name]
  ─────────────────────────
  The rule: [One sentence stating the design principle, not the fix]
  Why it exists: [One sentence on the human reason — perception, cognition, law, etc.]
  What it looks like when broken: [Real-world analogy or concrete example]
  What it looks like when fixed: [Concrete before/after contrast]
  Where to learn more: [Relevant reference — WCAG SC, Nielsen heuristic, known article title]
```

- Use plain language — this mode targets learners, not experts
- If beginner mode is active: no jargon, maximum one technical term per lesson with an inline definition
- If experienced mode: go deeper, cite the cognitive science or perceptual research behind the rule


**If "Explain an issue"** → present a widget listing all issues found. When one is selected:
- Explain what the rule is and why it exists (1–2 sentences)
- Show a real-world example of what it looks like when broken vs fixed
- Explain the impact on users (e.g. "users with low vision won't be able to read this")
- Cite the specific rule from the relevant reference file
- If beginner mode is active: use plain language with no assumed knowledge
- If experienced mode: go deeper — cite WCAG success criterion, link to relevant spec

**If "Re-audit"** → re-run the audit on the same input:
```
Scope for re-audit:
  - Use the exact same scope as the original audit (same categories, same WCAG level)
  - Do NOT re-ask settings unless the user explicitly requests a scope change
  - State at the top: "Re-audit — same scope as Audit [N]"
  - Show delta summary before the new report (see Re-audit delta section in Strict Output Template)
  - Only list issues that changed — resolved, newly found, or worsened
  - Unchanged issues from the previous audit: do not re-list, just note "N issues unchanged"

If the user shares updated code or a new Figma link before selecting Re-audit:
  → Use the new input automatically. Note: "Re-auditing updated version."
```

**If "Show session progress"** → render the session sparkline widget showing all audit scores in the current session, with issue count deltas per audit. Only show this option if 2+ audits have been run.

Then respond based on their selection. If they dismiss the question, fall back to this line:
- English: *"Want me to apply any of these fixes? I can edit the code directly, or if you're in Figma, I can make changes there too. Or if you'd rather learn how to do it yourself, I can walk you through it step by step."*

### Ambiguous Input Widget
If the user triggers the skill but shares nothing (e.g. just says "audit this" with no attachment), ask by AskUserQuestion before asking in prose:

- question: "What are you sharing for the audit?"
- type: single_select
- options: "Figma link" / "Screenshot" / "Code (HTML/CSS/React)" / "Written description"

**In Figma (High confidence, MCP active)**: Call `perform_editing_operations` → specific node IDs → verify by reading back the edited node properties; `get_screenshot` only when the visual aspect is the question, once. See F5 and `references/figma-mcp.md` for operation types and safety rules. If `perform_editing_operations` is unavailable, fall back to design direction.

**In code**: Always show a before/after diff when fixing:
```
// BEFORE
padding: 13px 22px;
color: #aaa;

// AFTER
padding: 12px 24px;  /* 8pt grid */
color: #666;          /* 4.5:1 contrast on white */
```

**In screenshots (Medium confidence)**: Never write code fixes for screenshot input — there is no source to edit. Instead give **design direction**:
- Describe the change spatially: > "Increase the gap between the label and input field — it should feel like they breathe, roughly 1.5× the current distance."
- Give the target value as a design spec, not code: > "Text color needs to be darker — aim for at least 4.5:1 against that background. A dark gray like #333 or #444 would work."
- Reference visual landmarks: > "The card padding looks tight on the left side — match it to the top padding so all four sides feel equal."
- If the user needs code, prompt them: > "If you can share the component code or Figma file, I can give you the exact value to change."

**Teaching mode**: Walk through the fix step by step instead of doing it for them.

---
