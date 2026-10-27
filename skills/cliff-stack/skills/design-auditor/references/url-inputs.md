# URL Input Spec

> Référence du skill design-auditor, chargée à la demande depuis [SKILL.md](../SKILL.md). Contenu déplacé tel quel depuis SKILL.md.

### URL Input Spec

When the user shares any non-Figma URL, apply this decision tree before auditing:

---

#### Live Website URL (`https://domain.com/...`)
*Includes Vercel previews (`*.vercel.app`), Netlify previews (`*.netlify.app`), staging URLs*

```
1. Fetch the URL using WebFetch to retrieve rendered HTML/CSS
2. Treat fetched content as code input — run Step 1.6 framework detection
3. Set confidence: Medium
   Limitations:
   - Cannot see non-rendered states (hover, focus, error, loading)
   - Cannot audit authenticated pages without credentials
   - Dynamic JS-rendered content may be incomplete
   - Note in REPORT HEADER: "Live URL — non-rendered states not assessed"

4. Login redirect handling:
   → Inform user: "This page requires login. I can audit the login page,
     or share credentials and I'll attempt the authenticated experience."
   → Never guess at content behind a login wall

5. Credentials handling:
   → Note in REPORT HEADER: "Authenticated audit — credentials provided"
   → Never repeat credentials back in the report output

6. Multi-page offer (after audit):
   → "Would you like me to audit other pages on this site?"
```

---

#### GitHub File URL (`github.com/user/repo/blob/main/path/to/file`)

```
1. Convert to raw URL: replace /blob/ with /raw/ or use raw.githubusercontent.com
   Example: github.com/user/repo/blob/main/src/Button.tsx
         → raw.githubusercontent.com/user/repo/main/src/Button.tsx
2. Fetch the raw file content via WebFetch
3. Treat as direct code input — full confidence High
4. Run Step 1.6 framework detection on the file content
5. Note in REPORT HEADER: "GitHub file: [filename] · [repo]"

If the URL points to a directory (not a file):
  → Treat as GitHub repo URL (see below)
```

---

#### GitHub Repo URL (`github.com/user/repo`)

```
1. Fetch the repo's default branch file listing
2. Identify the most relevant files to audit:
   Priority order:
   a. src/components/ or components/ — UI components
   b. src/App.tsx / App.vue / index.html — root component
   c. src/styles/ or *.css / *.scss / tailwind.config.js — style files
   d. package.json — detect framework/dependencies

3. Fetch the 2–3 most relevant component/style files
4. Set confidence: Medium (partial codebase view)
5. Note in REPORT HEADER: "GitHub repo: [repo name] · [N] files audited"

Present scope selector before auditing:
  "I found [N] component files in this repo. Which should I focus on?"
  Options: [list of found component files] + "Audit all visible files"

If repo is private or returns 404:
  → "This repo is private or unavailable. Share the code directly
     or make the repo public to audit it."
```

---

#### CodeSandbox / StackBlitz URL

```
CodeSandbox: codesandbox.io/s/[id] or codesandbox.io/p/sandbox/[id]
StackBlitz:  stackblitz.com/edit/[id]

1. Fetch the URL to get the rendered preview HTML
2. Also attempt to fetch the source files if accessible:
   CodeSandbox API: codesandbox.io/api/v1/sandboxes/[id]
   StackBlitz: check for embedded source in the page
3. If source available → treat as code input (High confidence)
4. If preview only → treat as screenshot/live URL (Medium confidence)
5. Note in REPORT HEADER: "CodeSandbox/StackBlitz — [source/preview only]"
```

#### CodePen URL

```
CodePen: codepen.io/[user]/pen/[id]

1. Fetch the debug URL for clean HTML: codepen.io/[user]/debug/[id]
   Or fetch the embed: cdpn.io/pen/debug/[id]
2. Treat rendered output as live URL input (Medium confidence)
3. CSS/JS source is often visible in page source — extract if available
4. Note in REPORT HEADER: "CodePen — rendered preview audited"
```

---

#### Storybook URL (`[domain]/storybook` or `storybook.[domain]`)

```
Detecting Storybook: URL contains /storybook, /story/, ?path=/story/
or page title contains "Storybook"

1. Fetch the Storybook URL
2. Identify component being shown from the URL path:
   ?path=/story/button--primary → auditing Button component, Primary variant
3. Treat rendered HTML as live URL input for visual checks
4. If source iframe is accessible, extract component HTML for code checks
5. Set confidence: Medium
6. Note in REPORT HEADER: "Storybook — [component name] · [variant]"

Multi-component offer:
  → After audit: "This Storybook has other components. Want me to audit
    another? Share the story URL for that component."
```

---

**REPORT HEADER format for all URL inputs:**
```
English:
**Input:** [URL type] — [url or repo/filename]
**Type:** [Live URL / GitHub / CodeSandbox / Storybook]
**Confidence:** Medium (or High for GitHub file)
**Limitations:** [what can't be assessed — list the specific gaps]

```
