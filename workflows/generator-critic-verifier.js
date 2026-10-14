export const meta = {
  name: 'generator-critic-verifier',
  description: 'Boucle réutilisable Générateur→Critique-adversarial→Vérifieur-anti-régression (Evaluator-optimizer + Verifier loop), avec garde-fous coût/convergence, application en git worktree isolé.',
  whenToUse: 'Tâche à forte valeur où la rigueur multi-agents se justifie (le critique doit réfuter, le vérifieur exécute de VRAIS tests). PAS pour une tâche triviale/déterministe — la porte d\'entrée le refuse. Lancer par scriptPath vers ce fichier : la garde guard-budget-agents bloque un lancement par name seul.',
  phases: [
    { title: 'Gate', detail: 'le multi-agent est-il justifié ?' },
    { title: 'Generate', detail: 'produire le livrable' },
    { title: 'Critique', detail: 'réfuter le livrable sur les critères, pas le noter' },
    { title: 'Verify', detail: 'tests déterministes anti-régression' },
    { title: 'Synthesize', detail: 'best-so-far + rapport' },
  ],
}
// agents-max: 10
// Fan-out mécanique : Gate 1 + MAX_ITER × (Generate 1 + Critique N_CRITICS + Verify 1).
// MAX_ITER est borné par le budget : N_CRITICS=1 → 3 tours → 1 + 3×3 = 10 ;
// N_CRITICS=2 → 2 tours → 1 + 2×4 = 9. Toute évolution du script recalcule cette ligne.

// ── Entrées (args) ───────────────────────────────────────────────────────────
// args.task          (requis) description de la tâche / du livrable visé
// args.successCriteria (requis) critères explicites de succès (sinon la boucle ne converge pas)
// args.testCommand   (optionnel) commande de test/build déterministe ; sinon un agent la découvre
// args.maxIterations (optionnel, défaut 3) garde-fou de non-convergence, plafonné par AGENTS_MAX
// args.minCritics    (optionnel, défaut 1, max 2) nb de critiques par tour ; 2 critiques = un tour de moins
// Normalise args : le runtime peut le transmettre comme objet OU comme chaîne JSON
// (notamment au re-lancement via scriptPath). On parse si c'est une string.
let input = args
if (typeof input === 'string') {
  try { input = JSON.parse(input) } catch { input = {} }
}
input = input ?? {}

const task = input.task
const successCriteria = input.successCriteria
const testCommand = input.testCommand ?? null
// Plafond total d'agents du run : doit rester égal à la déclaration « agents-max » en tête.
const AGENTS_MAX = 10
const N_CRITICS = Math.max(1, Math.min(input.minCritics ?? 1, 2))
const MAX_ITER = Math.max(1, Math.min(input.maxIterations ?? 3, Math.floor((AGENTS_MAX - 1) / (2 + N_CRITICS))))

if (!task || !successCriteria) {
  log('ABORT — `task` et `successCriteria` sont requis (sans critères explicites, la boucle ne peut pas converger).')
  return { aborted: true, reason: 'missing task/successCriteria' }
}
if ((input.maxIterations ?? 3) > MAX_ITER) log(`Budget d'agents : ${MAX_ITER} tour(s) au plus avec ${N_CRITICS} critique(s) par tour (demandé : ${input.maxIterations ?? 3}).`)

// ── Schemas structurés ───────────────────────────────────────────────────────
const GATE = {
  type: 'object',
  required: ['justified', 'reason', 'cheaperAlternative'],
  properties: {
    justified: { type: 'boolean', description: 'true seulement si la tâche est à forte valeur ET non triviale ET non purement déterministe' },
    reason: { type: 'string' },
    cheaperAlternative: { type: 'string', description: 'si non justifié : le workflow simple/déterministe à faire à la place' },
  },
}
const GENERATE = {
  type: 'object',
  required: ['worktreePath', 'filesChanged', 'summary'],
  properties: {
    worktreePath: { type: 'string', description: 'chemin absolu du worktree où le livrable a été écrit (sortie de `git rev-parse --show-toplevel`)' },
    filesChanged: { type: 'array', items: { type: 'string' }, description: 'fichiers créés/modifiés, relatifs au worktree' },
    summary: { type: 'string', description: 'ce qui a été produit, fichier par fichier' },
  },
}
const CRITIQUE = {
  type: 'object',
  required: ['defects', 'verdict', 'exercised'],
  properties: {
    defects: {
      type: 'array',
      items: {
        type: 'object',
        required: ['severity', 'description', 'evidence'],
        properties: {
          severity: { type: 'string', enum: ['blocker', 'major', 'minor'], description: 'ordre de traitement, pas un filtre : blocker = un critère de succès non satisfait ; major = un cas réel qui donne un résultat faux ; minor = un cas marginal qui donne un résultat faux' },
          description: { type: 'string' },
          evidence: { type: 'string', description: 'preuve concrète (commande et sortie, ligne, contre-exemple exécuté) — pas une opinion' },
        },
      },
    },
    verdict: { type: 'string', enum: ['reject', 'accept-with-fixes', 'accept'] },
    exercised: { type: 'string', description: 'ce qui a été réellement lu, exécuté ou déclenché ; un accept sans cette liste ne vaut rien' },
  },
}
const VERIFY = {
  type: 'object',
  required: ['ranRealTests', 'commandUsed', 'pass', 'output'],
  properties: {
    ranRealTests: { type: 'boolean', description: 'false si aucun test déterministe n\'a pu être exécuté (alors pass DOIT être false)' },
    commandUsed: { type: 'string', description: 'la commande exacte exécutée (traçabilité)' },
    pass: { type: 'boolean' },
    output: { type: 'string', description: 'extrait réel de sortie (erreurs/échecs), pas un résumé' },
    regressions: { type: 'array', items: { type: 'string' } },
  },
}

// ── Phase Gate : le multi-agent est-il justifié ? (garde-fou coût ~15x) ───────
phase('Gate')
const gate = await agent(
  `Décide si une boucle multi-agents (générateur + critique adversarial + vérifieur) est JUSTIFIÉE pour cette tâche, ou si c'est de la sur-ingénierie.
Tâche : ${task}
Critères de succès : ${successCriteria}
Réponds justified=true SEULEMENT si : forte valeur ET non triviale ET la qualité bénéficie réellement d'une réfutation adversariale + vérification. Sinon justified=false et propose l'alternative la moins chère (un seul appel, ou un workflow déterministe simple). Coût d'une boucle multi-agents ≈ 15x un appel unique : ne le paie que si ça le vaut.`,
  { phase: 'Gate', schema: GATE, effort: 'low' }
)

if (!gate || !gate.justified) {
  log(`GATE: boucle NON justifiée — ${gate?.reason ?? 'gate échouée'}. Alternative: ${gate?.cheaperAlternative ?? 'faire la tâche en un appel simple'}`)
  return { aborted: true, gate }
}
log(`GATE: justifié — ${gate.reason}`)

// ── Boucle Generate → Critique → Verify ──────────────────────────────────────
let best = null
let lastCritiqueFeedback = ''
let prevWorktree = null
const history = []

for (let iter = 1; iter <= MAX_ITER; iter++) {
  // 1) GÉNÉRATEUR (en worktree isolé : il écrit réellement le livrable). Chaque agent en
  //    isolation reçoit un worktree neuf : à partir du tour 2, il reprend d'abord l'état du tour précédent.
  phase('Generate')
  const gen = await agent(
    `${iter === 1 ? 'Produis' : 'AMÉLIORE'} le livrable pour la tâche ci-dessous, dans CE worktree (écris les fichiers réels).
Tâche : ${task}
Critères de succès : ${successCriteria}
${prevWorktree ? `Pars de l'état du tour précédent, conservé dans le worktree ${prevWorktree} : reporte d'abord ses changements ici (fichiers suivis et nouveaux, par exemple \`git -C "${prevWorktree}" add -A && git -C "${prevWorktree}" diff --cached --binary HEAD | git apply --index\`), puis corrige.\nDéfauts à corriger (du tour précédent) :\n${lastCritiqueFeedback}` : ''}
Rends le chemin absolu de ce worktree, les fichiers touchés et en quoi ils satisfont les critères.`,
    { phase: 'Generate', label: `generate#${iter}`, schema: GENERATE, isolation: 'worktree' }
  )
  if (!gen) { log(`Generate#${iter} a échoué, arrêt.`); break }
  prevWorktree = gen.worktreePath

  // 2) CRITIQUE (jugement LLM, faillible) : il reçoit l'artefact et le critère, jamais le
  //    résumé argumenté du générateur. Périmètre = justesse vis-à-vis des critères, sans seuil.
  phase('Critique')
  const critiques = (await parallel(
    Array.from({ length: N_CRITICS }, (_, k) => () =>
      agent(
        `Critique #${k + 1}. Tente de RÉFUTER ce livrable : trouver une entrée, un cas ou un geste pour lequel il ne satisfait pas les critères.
Tâche visée : ${task}
Critères de succès : ${successCriteria}
Artefact : worktree ${gen.worktreePath}, fichiers ${(gen.filesChanged ?? []).join(', ') || '(aucun déclaré : lis \`git -C "' + gen.worktreePath + '" status\`)'} ; lis le diff et exécute ce qui tranche (\`git -C "${gen.worktreePath}" diff HEAD\`, tests, build).
Périmètre : tout ce qui rend le livrable faux ou non conforme aux critères, sans seuil de gravité. Hors périmètre : style, nommage, améliorations possibles. Chaque défaut porte une preuve exécutée ou citée. Aucun défaut trouvé est un résultat valable s'il s'appuie sur la liste de ce que tu as exercé ; n'en fabrique pas.`,
        { phase: 'Critique', label: `critic#${iter}.${k + 1}`, schema: CRITIQUE, effort: 'high' }
      )
    )
  )).filter(Boolean)

  const allDefects = critiques.flatMap((c) => c.defects ?? [])
  const blockers = allDefects.filter((d) => d.severity === 'blocker')
  const majors = allDefects.filter((d) => d.severity === 'major')
  lastCritiqueFeedback = allDefects.length
    ? allDefects.map((d) => `- [${d.severity}] ${d.description} (preuve: ${d.evidence})`).join('\n')
    : '(aucun défaut signalé)'
  log(`Critique#${iter}: ${blockers.length} blocker, ${majors.length} major, ${allDefects.length - blockers.length - majors.length} minor`)

  // 3) VÉRIFIEUR ANTI-RÉGRESSION (DÉTERMINISTE — distinct du critique LLM), dans le worktree du générateur.
  //    Hybride : commande fournie → exécutée telle quelle ; sinon agent qui découvre ET lance.
  phase('Verify')
  const where = `Travaille dans le worktree ${gen.worktreePath} (toutes les commandes s'y exécutent).`
  const verify = await agent(
    testCommand
      ? `${where} Exécute EXACTEMENT cette commande de test/build et rapporte le résultat réel (jamais inventé) : \`${testCommand}\`. ranRealTests=true seulement si la commande s'est réellement exécutée. Si elle échoue à se lancer, ranRealTests=false et pass=false. Rapporte commandUsed et un extrait réel de output (les erreurs).`
      : `${where} Découvre comment vérifier ce projet de façon DÉTERMINISTE (cherche package.json scripts, test runner, build, linter/typecheck) PUIS exécute-les RÉELLEMENT. Ne juge pas à l'œil : un test qui échoue = pass:false. Si aucun test déterministe n'existe, ranRealTests=false et pass=false (on ne valide pas sans signal réel). Rapporte la commande exacte (commandUsed) et un extrait réel de output.`,
    { phase: 'Verify', label: `verify#${iter}`, schema: VERIFY, effort: 'medium' }
  )
  const verifyPass = Boolean(verify?.ranRealTests && verify?.pass)
  log(`Verify#${iter}: ranRealTests=${verify?.ranRealTests} pass=${verify?.pass} cmd="${verify?.commandUsed ?? '?'}"`)

  // Score de convergence : moins de défauts pondérés + tests verts
  const weighted = blockers.length * 100 + majors.length * 10 + (allDefects.length - blockers.length - majors.length)
  const accepted = critiques.length > 0 && critiques.every((c) => c.verdict === 'accept') && allDefects.length === 0 && verifyPass
  const snapshot = { iter, deliverable: gen, defects: allDefects, verify, weighted, verifyPass, accepted }
  history.push(snapshot)

  // best-so-far : priorité aux tests verts, puis au moins de défauts pondérés
  if (
    !best ||
    (verifyPass && !best.verifyPass) ||
    (verifyPass === best.verifyPass && weighted < best.weighted)
  ) {
    best = snapshot
  }

  if (accepted) {
    log(`Convergé au tour ${iter} : aucun défaut + tests verts.`)
    break
  }
  if (iter === MAX_ITER) {
    log(`Max itérations (${MAX_ITER}) atteint — sortie best-so-far (tour ${best.iter}).`)
  } else if (history.length >= 2 && history[history.length - 1].weighted >= history[history.length - 2].weighted && verifyPass === history[history.length - 2].verifyPass) {
    log(`Non-amélioration détectée (tour ${iter}) — arrêt anticipé, best-so-far (tour ${best.iter}).`)
    break
  }
}

return {
  task,
  iterations: history.length,
  converged: Boolean(best?.accepted),
  best: best
    ? { iter: best.iter, worktreePath: best.deliverable.worktreePath, verifyPass: best.verifyPass, defects: best.defects, verifyCommand: best.verify?.commandUsed, deliverable: best.deliverable }
    : null,
  note: 'Changements écrits dans des git worktrees isolés (best.worktreePath = le meilleur tour) — review puis merge manuel. Rien n\'est appliqué au répertoire de travail principal.',
}
