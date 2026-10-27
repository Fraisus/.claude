# Report Template & Post-Report Visuals

> Référence du skill design-auditor, chargée à la demande depuis [SKILL.md](../SKILL.md). Contenu déplacé tel quel depuis SKILL.md.

### Strict Output Template

> **MANDATORY — ALL AGENTS — NO EXCEPTIONS**
> This template is not optional. Every audit report must use this exact structure regardless
> of input type (Figma, code, screenshot, live URL, wireframe). Do not substitute a free-form
> critique, UX review, or bullet-point list. The scored report IS the output.

Every audit report must use this exact structure — sections marked *(always)* appear on every audit. Sections marked *(conditional)* appear only when applicable.

---

#### REPORT HEADER *(always)*

```
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
DESIGN AUDIT REPORT
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

| Field        | Value                                    |
|--------------|------------------------------------------|
| Input        | [component/page name]                    |
| Type         | [Figma MCP / HTML / React / Screenshot]  |
| Framework    | [detected framework] *(code only)*       |
| Confidence   | [High / Medium / Low]           |
| Scope        | [frame name + page / filename]           |
| Date         | [date]                                   |

*(Figma only)*
| Component Health | [N]% coverage · [N] detached · [N] unnamed |
| Auto Layout      | [N]% frames using Auto Layout · [N] manual-position frames |
| Code Connect     | [N] mapped · [N] unmapped *(if available)* |

*(Code only)*
| Design System  | [MUI / Chakra / shadcn/ui / Ant Design / Radix / None detected] |
| Token Coverage | colors [N]% · spacing [N]% · radius [N]% |
```

> **Medium confidence audit** — input was a screenshot. Values are estimated.
> For an exact audit, share the Figma file or code.
> *(Only show this block when confidence is Medium)*

---

#### SCORES *(always)*

```
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
SCORES
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Overall       [██████████░░░░░░░░░░]  [X]/100
Accessibility [████████░░░░░░░░░░░░]  [X]/100  *(Cat 2, 6, 7, 15, 16)*
Ethics        [███████████████░░░░░]  [X]/100  *(Cat 18)*
Usability     [████████████░░░░░░░░]  [X]/100  *(Cat 19 — H1/H2/H3/H6/H7/H10)*
              ↳ H4 Consistency → Cat 5 · H5 Error Prevention → Cat 7 · H8 Aesthetics → Cat 4 · H9 Recovery → Cat 11/12

Score formula: 100 − ([N] × Blocker 12) − ([N] × Critical 8) − ([N] × Warning 4) − ([N] × Tip 1) = [X]/100
Ethics Score uses its own formula: Critical −15 (Deceptive) · Warning −7 (Questionable) · Tip 0 (Noted) — do not apply the standard formula to Ethics.

[One sentence — what dragged the score down most.]
```

**Progress bar generation rule:**
- Bar is 20 characters wide. Fill = round(score / 5) filled blocks.
- Use `█` for filled, `░` for empty.
- Example: 68/100 → 14 filled → `██████████████░░░░░░`
- Omit Ethics and Usability bars if those categories were not audited.
- Append `Legal compliance failures` after Accessibility score if any Blockers present.

```
### Score by Category

| Category | Score | Bar | Blocker | Critical | Warning | Tip |
|---|---|---|---|---|---|---|
| 1 · Typography | X/10 | ██████░░░░ | 0 | 1 | 2 | 0 |
| 2 · Color & Contrast | X/10 | █████░░░░░ | 1 | 0 | 1 | 0 |
| [other audited categories] | X/10 | ██████████ | — | — | — | — |

*(Bar is 10 chars wide. Fill = score value. █ filled, ░ empty. Score ≤ 5 → render bar in red context.)*
*(Only include audited categories. Use — for unchecked severity levels.)*
*(Score bands: ≥ 8 → good · 5–7 → Warning needs work · ≤ 4 → critical)*
```

---

#### ISSUES *(always — follow deduplication rules)*

```
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
BLOCKERS  ·  cannot ship  ·  −12pts each
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
*(Omit this entire section if no Blockers found)*

> **[Issue name]**
> [What's wrong — one sentence]
> Fix: [Specific how-to]
> Legal basis: [WCAG SC X.X.X / GDPR Article X]
> Location: [node IDs / line numbers]

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
CRITICAL ISSUES  ·  must fix  ·  −8pts each
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

> **[Issue name]**
> [What's wrong — one sentence]
> Fix: [Specific how-to]
> Why: [One sentence rule reference]
> Location: [node IDs / line numbers]

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
WARNINGS  ·  should fix  ·  −4pts each
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

> **[Issue name]**
> [What's wrong] → Fix: [how-to]

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
TIPS  ·  nice to have  ·  −1pt each
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

> **[Issue name]** — [What's wrong] → [Fix]
```

---

#### POSITIVES *(always — min 2, max 4)*

```
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
 WHAT'S WORKING WELL
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

- [Specific genuine positive — not generic praise]
- [Specific genuine positive]
```

---

#### CROSS-FRAME INCONSISTENCIES *(conditional — only when 2+ frames audited)*

```
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
CROSS-FRAME INCONSISTENCIES
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

| Property | Frame A | Frame B |
|---|---|---|
| [Property] | [value] | [value] |
```

---

#### RE-AUDIT DELTA *(conditional — only on 2nd+ audit in session)*

```
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
PROGRESS SINCE LAST AUDIT
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Score:    [prev] → [current]  ([+/−N] pts)
Fixed:    [category] (+Npts)  [category] (+Npts)
Open:     Critical [N] critical issues remaining
New:      [N] new issues found
```

**After the delta text block, render a Visualizer widget showing a before/after score bar pair:**
```
Two horizontal bars, stacked:
  Previous:  ████████████░░░░░░░░  [prev]/100
  Current:   ███████████████░░░░░  [current]/100  [+N ↑ in green / −N ↓ in red]

Bar width: 20 chars. Colour: previous bar in muted gray (#888), current bar in #534AB7 (improved)
or #E24B4A (regressed). Delta badge right-aligned.
```
- English intro: *"Here's how your score moved since the last audit."*
- If score is unchanged: show both bars at same fill, no delta badge, note "No change in overall score."

---

#### REPORT FOOTER *(always)*

```
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
*Audit run with Design Auditor Skill v1.2.13 · [input type] · [confidence level]*
*Re-audit after fixes to track progress.*
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
```

---

After the report, immediately render:
1. **Radar chart** (Step 3b) — always
2. **Issue Priority Matrix widget** — always if 3+ issues
3. **Severity filter widget** — if 5+ issues
4. **"What next?" widget** (Step 4) — always

**Issue Priority Matrix — effort/impact heuristics:**

Effort (1–10): 1–2 = single value change · 3–4 = one component rework · 5–6 = multi-component refactor · 7–9 = architectural change

Impact (1–10): 9–10 = breaks a11y or core usability · 6–8 = degrades experience · 3–5 = polish-level · 1–2 = cosmetic

Use deterministic positioning (no random jitter). Render severity as both color AND a letter inside the dot (C/W/T) for colorblind accessibility. Axis labels: English: "Effort to Fix" (x) / "Impact on Design" (y). Introduce with one sentence:
- English: *"Here's every issue mapped by how hard it is to fix versus how much it will improve the design — start in the top-left."*

### Step 3b: Radar Chart Visualizer (always run after report)

Immediately after presenting the markdown report, use the Visualizer tool to render an interactive radar chart widget. This gives users a visual at-a-glance summary of all category scores.

**How to generate it:**
- Extract the per-category scores (X/10) from the audit you just ran
- Use only the categories that were actually audited (skip ones marked as not applicable)
- Pass real scores — do not use placeholder data
- Use three colours based on score band: `#534AB7` (purple) for scores 8–10, `#E8A838` (amber) for scores 6–7, `#E24B4A` (red) for scores ≤ 5 — applied to both the filled shape segments and the dots
- Label categories scoring ≤ 5 in red bold, 6–7 in amber, 8–10 in muted gray
- Each segment and label must be clickable via `sendPrompt()` to drill into that category
- Show a compact score-bar legend below the chart listing all audited categories
- Display the overall score in the centre of the radar

**Session history awareness:**
Store the current overall score in a JS variable accessible to the widget. If a previous score exists in the session (from a re-audit), show a delta badge next to the centre score:
- Score improved: `+N ↑` in green
- Score dropped: `−N ↓` in red  
- No change: omit the badge

**Tone:** Introduce the chart with one short sentence before the Visualizer call:
- English: *"Here's a visual breakdown of how your design scores across each category — red spots are your highest-priority fixes."*

**Fallback (if Visualizer is unavailable or rendering fails):**
Do not skip the visual entirely. Fall back to the Score by Category table from the Strict Output Template — it carries the same per-category data in tabular form. Add a note:
- English: *"(Radar chart unavailable in this context — see Score by Category table above.)"*

---

### Severity Filter
After presenting the report, offer a filter by AskUserQuestion if there are 5+ issues:

- question: "Would you like to filter the issues?"
- type: single_select
- options: "Show only Critical" / "Show Critical + Warning" / "Show everything" / "No filter"

Apply the filter and re-present only the relevant issue sections. Score and category breakdown always remain visible regardless of filter.

### Re-audit: Session Progress Tracker

Maintain a running audit history across the session. Every time an audit completes, record:
- Overall score
- Accessibility score
- Count of critical / warning / tip issues
- Timestamp label (e.g. "Audit 1", "Audit 2")

**On re-audit**, open with a delta summary before the report:
- English: *"Since the last audit: score improved from [X] → [Y]. Critical issues down from [N] to [N]. Here's what's new..."*

Then show only the **changed or new** issues — do not re-list resolved ones. Acknowledge wins explicitly:
- English: *"Fixed since last audit: Color & Contrast (+3pts), States (+4pts)"*

**After the radar chart**, if 2+ audits exist in the session, render a second small Visualizer widget — a simple horizontal progress bar or sparkline showing score history across audits (e.g. 58 → 71 → 84). Keep it minimal: one line, scores as labels, no axes. This helps users feel the momentum of improvement.

---
