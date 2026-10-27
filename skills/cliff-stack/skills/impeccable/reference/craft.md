# Craft Flow

Build a feature with impeccable UX and UI quality: shape the design, land the visual direction, build real production code, inspect and improve in-browser until it meets a high-end studio bar.

Before writing code, you need: PRODUCT.md loaded, register identified and the matching reference loaded, and a confirmed design direction for this task (either from `shape` or supplied by the user). PRODUCT.md is project context, not a task-specific brief.

Treat any approved visual direction (generated mock or stated reference) as a concrete contract for composition, hierarchy, density, atmosphere, signature motifs, and distinctive visual moves. Don't let mocks replace structure, copy, accessibility, or state design. But if the live result lacks the approved direction's major ingredients, the implementation is wrong.

## Step 0: Project Foundation

Before shape, before code: figure out what kind of project you're working in.

Look at the working directory. Run `ls`. Check for:

- An existing framework: `astro.config.mjs/ts`, `next.config.js/ts`, `nuxt.config.ts`, `svelte.config.js`, `vite.config.js/ts`, `package.json` with framework deps, `Cargo.toml` + Leptos/Yew, `Gemfile` + Rails. **If found, use it.** Do not start a parallel build, do not introduce a second framework, do not write to `dist/` or `build/` directly. Whatever pipeline the project has, respect it.
- An existing component library or design system: `src/components/`, `app/components/`, a `tokens.css` / `theme.ts`, an `astro.config` `integrations`. Read what's there before adding to it.
- An existing icon set: `lucide-react`, `@phosphor-icons/react`, `@iconify/*`, hand-rolled SVG sprites in `assets/icons/`. **Use what's already in the project**; don't introduce a second set.

If the directory is empty (greenfield), don't pick a framework silently. Ask the user via the AskUserQuestion tool, with sensible defaults framed by the brief:

```text
What should this be built on?
  - Astro (default for content-led brand sites, landing pages, marketing surfaces)
  - SvelteKit / Next.js / Nuxt (when the brief implies an app surface or significant interactivity)
  - Single index.html (one-shot demo, prototype, or a deliberately framework-free experiment)
```

Default: Astro for brand briefs, the project's existing framework for product briefs. Ask once; don't re-ask mid-task.

## Step 1: Shape the Design

Run /impeccable shape, passing along whatever feature description the user provided. Shape is **required** for craft; it is what produces a confirmed direction.

Présenter le résultat de shape, puis enchaîner sur la construction.

If the user already supplied a confirmed brief or ran shape separately, use it and skip this step.

When the original prompt + PRODUCT.md already answer scope, content, and visual direction with no real ambiguity, the shape output can be **compact** (3-5 bullets stating what you're building and the visual lane). The full 10-section structured brief is reserved for genuinely ambiguous, multi-screen, or stakeholder-heavy tasks. Don't pad a clear brief into a long one to look thorough.

## Step 2: Load References

Based on the design brief's "Recommended References" section, consult the relevant impeccable reference files. At minimum, always consult:

- [layout.md](layout.md) for layout, spacing, grid, container queries, optical adjustments
- [typeset.md](typeset.md) for type hierarchy, font selection, web font loading, OpenType features (Reference Material section)

Then add references based on the brief's needs:
- Complex interactions or forms? Consult [interaction-design.md](interaction-design.md)
- Animation or transitions? Consult [animate.md](animate.md) (Reference Material covers motion materials, durations, easing, perceived performance)
- Color-heavy or themed? Consult [colorize.md](colorize.md) (Reference Material covers OKLCH, palette structure, dark mode, contrast)
- Responsive requirements? Consult [adapt.md](adapt.md) (Reference Material covers breakpoints, input methods, safe areas, responsive images)
- Heavy on copy, labels, or errors? Consult [clarify.md](clarify.md) (Reference Material covers button labels, error formula, voice/tone, translation)

## Step 3: Visual Direction & Assets

Le brief, et le canevas Claude Design quand shape en a dessiné un, est la référence visuelle. Implémenter directement depuis lui, en traitant les références nommées et la « Design Direction » du brief comme le contrat.

Don't replace required imagery with generic cards, bullets, emoji, fake metrics, decorative CSS panels, or filler copy. Image-led briefs (restaurants, hotels, magazines, photography, hobbyist communities, food, travel, fashion, product) need real or sourced imagery in the build, not CSS scenery.

## Step 4: Build to Production Quality

Implement the feature following the design brief. Build in passes so structure, visual system, states, motion/media, and responsive behavior each get deliberate attention. The list below is the definition of done, not inspiration.

### Production bar

- **Real content.** No placeholder copy, placeholder images, dead links, fake controls, or unused scaffold at presentation time.
- **Preserve the approved mock's major ingredients.** Missing hero objects, world/product imagery, section structure, CTA/nav treatment, or distinctive motifs are blocking defects unless the user accepted the change.
- **Semantic first.** Real headings, landmarks, labels, form associations, button/link semantics, accessible names, state announcements where needed.
- **Deliberate spacing and alignment.** No default gaps, arbitrary margins, unbalanced whitespace, or accidental optical misalignment.
- **Intentional typography.** Chosen loading strategy, clear hierarchy, readable measure, stable line breaks, no overflow at any width.
- **Realistic state coverage.** Default, hover, focus-visible, active, disabled, loading, error, success, empty, overflow, long/short text, first-run.
- **Finished interaction quality.** Keyboard paths, touch targets, feedback timing, scroll behavior, state transitions, no hover-only functionality.
- **Coherent icon set.** Use the project's established set; otherwise pick one library or use accessible text. Don't mix.
- **Respect the build pipeline.** Edit source files and run the project's build (`npm run build` or equivalent). Don't write to `build/` / `dist/` / `.next/` with `cat`, heredoc, or Bash redirects; that skips asset hashing, image optimization, code splitting, and CSS extraction, and produces output the dev server won't serve.
- **Verify image URLs before referencing them.** Use image-search MCP or web-fetch when available; guessed photo IDs ship as broken-image placeholders. Without verification, prefer fewer images you're confident about.
- **Optimized imagery and media.** Correct dimensions, useful alt text, lazy loading below the fold, modern formats when practical, responsive `srcset`/`picture` for raster, no project-referenced asset left outside the workspace.
- **Premium motion.** Use atmospheric blur, filter, mask, shadow, reveal when they improve the experience. Avoid casual layout-property animation, bound expensive effects, verify smoothness in-browser, respect reduced motion, and avoid choreography that blocks task completion.
- **Maintainable.** Reusable local patterns, clear component boundaries, project conventions. No rasterized UI text or one-off hacks when a local pattern exists.
- **Technically clean.** Production build passes, no console errors, no avoidable layout shift, no needless dependencies, no broken asset paths.
- **Ask when uncertain.** If a discovery materially changes the brief or approved direction, stop and ask. Don't guess.

## Step 5: Iterate Visually

Regarder ce qui a été construit comme le ferait un designer, preuve par le contenu : le DOM et l'état calculé en mobile, tablette et bureau au moins (débordements, éléments coupés, hiérarchie, valeurs de contraste), par le parcours réel du projet (`testing-real-ux`). Une capture seulement si l'aspect visuel lui-même est la question, une fois.

After the first pass, write an honest critique against the brief, the approved mock's major ingredients (hero silhouette, motifs, imagery, nav/CTA, density), and impeccable's DON'Ts. Patch material defects and re-inspect. **Don't invent defects to demonstrate iteration.** A confident "first pass clean, shipping" beats a fake fix.

Actively check: responsive behavior (composes, not shrinks), every state (empty / error / loading / edge), craft details (spacing, alignment, hierarchy, contrast, motion timing, focus), performance basics. The exit bar: defensible in a high-end studio review.

Detector or QA output is defect evidence only; never proof the work is finished.

## Step 6: Present

Present the result to the user:
- Show the feature in its primary state
- Summarize the browser/viewports checked and the most important fixes made after inspection
- Walk through the key states (empty, error, responsive)
- Explain design decisions that connect back to the design brief and, when used, the chosen north-star mock. Include any accepted deviations from the mock; do not hide unimplemented mock ingredients.
- Note any remaining limitations or follow-up risks honestly
- Ask: "What's working? What isn't?"
