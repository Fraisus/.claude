export const meta = {
  name: 'research-arbitrate',
  description: 'Recherche multi-sources VÉRIFIÉE et ARBITRÉE sur une question liée à un projet : balaye jusqu\'à 5 voies de sources (Context7 quand le sujet est une lib, dépôts/guides officiels, sources officielles du sujet, sources communautaires, code/contexte du projet courant), croise les affirmations, fait challenger les affirmations pivot fragiles (une lentille choisie selon leur nature), puis un arbitre tranche selon une vue globale en signalant ce qui reste incertain. Sortie = rapport tranché et cité (ne modifie rien dans le repo).',
  whenToUse: 'Question de réflexion à enjeu où il faut croiser doc officielle, communauté et vérité terrain du projet, puis trancher — pas une simple lookup factuelle (le Gate refuse alors). Lancer par scriptPath vers ce fichier : la garde guard-budget-agents bloque un lancement par name seul.',
  phases: [
    { title: 'Gate', detail: 'la recherche multi-sources est-elle justifiée ?' },
    { title: 'Cadrage', detail: 'sous-questions + voies de sources pertinentes' },
    { title: 'Sweep', detail: '4 ou 5 voies de sources en parallèle, affirmations sourcées' },
    { title: 'Croisement', detail: 'dédup + accords/contradictions + lentille par affirmation' },
    { title: 'Challenge', detail: 'réfuter les affirmations pivot fragiles, dans le budget restant' },
    { title: 'Arbitrage', detail: 'vue globale tranche + incertitudes' },
  ],
}
// agents-max: 12
// Fan-out mécanique : Gate 1 + Cadrage 1 + Sweep ≤5 + Croisement 1 + Arbitrage 1 = 9 au plus,
// Challenge = floor((AGENTS_MAX - 4 - voies) / N_CHAL) × N_CHAL ≤ 3 (5 voies) ou 4 (context7 sautée).
// Total ≤ 12 dans tous les cas. Toute évolution du script recalcule cette ligne.

// ── Entrées (args) ───────────────────────────────────────────────────────────
// args.question     (requis) la question de réflexion
// args.projectHint  (optionnel) où regarder dans le projet (chemins, libs, sujet)
// args.maxChallengers (optionnel, défaut 1, max 2) nb de challengers par affirmation ; le nombre
//                    d'affirmations challengées baisse d'autant pour tenir AGENTS_MAX
let input = args
if (typeof input === 'string') { try { input = JSON.parse(input) } catch { input = {} } }
input = input ?? {}

const question = input.question
const projectHint = input.projectHint ?? '(non précisé — découvre le contexte projet pertinent)'
// Plafond total d'agents du run : doit rester égal à la déclaration « agents-max » en tête.
const AGENTS_MAX = 12
const N_CHAL = Math.max(1, Math.min(input.maxChallengers ?? 1, 2))

if (!question) {
  log('ABORT — `question` requise.')
  return { aborted: true, reason: 'missing question' }
}

// ── Schemas ──────────────────────────────────────────────────────────────────
const GATE = {
  type: 'object',
  required: ['justified', 'reason', 'cheaperAlternative'],
  properties: {
    justified: { type: 'boolean', description: 'true seulement si la question a un enjeu réel ET bénéficie de croiser plusieurs types de sources + arbitrage. false si simple lookup factuelle ou réponse triviale.' },
    reason: { type: 'string' },
    cheaperAlternative: { type: 'string' },
  },
}
const SCOPE = {
  type: 'object',
  required: ['subQuestions', 'context7Relevant'],
  properties: {
    subQuestions: { type: 'array', items: { type: 'string' } },
    // Les 4 voies officialRepos/officialDocs/community/project sont TOUJOURS interrogées
    // (le but est de croiser). La voie context7 n'est lancée que si le sujet porte sur une
    // lib/framework/SDK/API documentée : sinon elle ne rapporterait que notFound.
    context7Relevant: { type: 'boolean', description: 'true si la question porte sur une lib, un framework, un SDK, une API ou un outil CLI documentable dans Context7 ; false sinon (méthode, stratégie, marché, doctrine, question propre au projet).' },
    hints: {
      type: 'object',
      properties: {
        context7: { type: 'string', description: 'lib/sujet probable à résoudre dans Context7' },
        officialRepos: { type: 'string', description: 'éditeur/orga GitHub probable' },
        officialDocs: { type: 'string', description: 'site/doc officielle probable' },
        community: { type: 'string', description: 'termes/forums probables' },
        project: { type: 'string', description: 'chemins/fichiers/version à inspecter dans le projet' },
      },
    },
    notes: { type: 'string' },
  },
}
const FINDINGS = {
  type: 'object',
  required: ['voie', 'claims'],
  properties: {
    voie: { type: 'string' },
    claims: {
      type: 'array',
      items: {
        type: 'object',
        required: ['claim', 'source', 'confidence'],
        properties: {
          claim: { type: 'string', description: 'affirmation atomique et vérifiable' },
          source: { type: 'string', description: 'URL / fichier:ligne / ID Context7 — TRAÇABLE, jamais "de mémoire"' },
          confidence: { type: 'string', enum: ['high', 'medium', 'low'] },
        },
      },
    },
    notFound: { type: 'string', description: 'ce qui a été cherché mais non trouvé (ex. "aucun repo officiel" APRÈS recherche, pas par défaut)' },
  },
}
const CROSS = {
  type: 'object',
  required: ['agreements', 'contradictions', 'keyClaimsToChallenge', 'solidClaims'],
  properties: {
    agreements: { type: 'array', items: { type: 'string' } },
    contradictions: { type: 'array', items: { type: 'object', properties: { topic: { type: 'string' }, positions: { type: 'string' } } } },
    keyClaimsToChallenge: {
      type: 'array',
      description: 'les affirmations pivot dont dépend la réponse ET qui méritent réfutation : contestées entre voies, OU mono-source, OU confiance ≤ medium. Une claim pivot corroborée par 2+ voies en high ne va PAS ici — elle va dans solidClaims. Classées de la plus décisive pour la réponse à la moins décisive.',
      items: {
        type: 'object',
        required: ['claim', 'sources', 'lens'],
        properties: {
          claim: { type: 'string' },
          sources: { type: 'string', description: 'les sources qui la portent (URL / fichier:ligne / ID Context7), telles que relevées au Sweep' },
          lens: { type: 'string', enum: ['officialSource', 'projectTruth', 'communityCounterexample', 'obsolescence'], description: 'la lentille la plus apte à la casser selon sa nature : API/spec/limite chiffrée → officialSource ; comportement ou version dans CE projet → projectTruth ; tenue en pratique → communityCounterexample ; affirmation datée ou liée à une version → obsolescence' },
        },
      },
    },
    solidClaims: { type: 'array', items: { type: 'string' }, description: 'affirmations pivot corroborées par 2+ voies indépendantes avec confiance high — considérées solides, dispensées de challenge (elles entrent à l\'arbitrage marquées comme telles)' },
  },
}
const CHALLENGE = {
  type: 'object',
  required: ['claim', 'survives', 'evidence', 'exercised'],
  properties: {
    claim: { type: 'string' },
    survives: { type: 'boolean', description: 'false si tu parviens à la réfuter ou à montrer qu\'elle est fausse dans le cadre de la question' },
    evidence: { type: 'string', description: 'preuve concrète (contre-source, contre-exemple, version réelle) — pas une opinion' },
    exercised: { type: 'string', description: 'ce qui a été réellement consulté ou exécuté (URLs, fichiers, commandes) ; un survives=true sans cette liste ne vaut rien' },
  },
}
const VERDICT = {
  type: 'object',
  required: ['answer', 'reasoning', 'confidence', 'uncertainties'],
  properties: {
    answer: { type: 'string', description: 'la réponse finale tranchée' },
    reasoning: { type: 'string', description: 'comment l\'arbitrage a pesé les sources (au cas par cas : selon la question, vérité terrain projet OU officiel OU communauté prime — JUSTIFIER le choix)' },
    confidence: { type: 'string', enum: ['high', 'medium', 'low'] },
    uncertainties: { type: 'array', items: { type: 'string' }, description: 'ce qui reste non résolu / à vérifier empiriquement' },
    keySources: { type: 'array', items: { type: 'string' } },
  },
}

// ── Phase Gate ───────────────────────────────────────────────────────────────
phase('Gate')
const gate = await agent(
  `Décide si une recherche multi-sources arbitrée (4 à 5 voies + croisement + challenge + arbitrage) est JUSTIFIÉE, ou si c'est de la sur-ingénierie pour cette question.
Question : ${question}
justified=true seulement si la question a un vrai enjeu ET gagne à croiser doc officielle / communauté / vérité terrain du projet puis trancher. Si c'est une lookup factuelle simple ou une réponse évidente → justified=false + alternative la moins chère.`,
  { phase: 'Gate', schema: GATE, effort: 'low' }
)
if (!gate || !gate.justified) {
  log(`GATE: non justifié — ${gate?.reason ?? 'échec gate'}. Alternative: ${gate?.cheaperAlternative ?? 'répondre en un appel'}`)
  return { aborted: true, gate }
}
log(`GATE: justifié — ${gate.reason}`)

// ── Phase Cadrage ────────────────────────────────────────────────────────────
phase('Cadrage')
const scope = await agent(
  `Cadre cette question de recherche.
Question : ${question}
Contexte projet : ${projectHint}
1) Décompose en sous-questions atomiques. 2) context7Relevant : true seulement si la question porte sur une lib, un framework, un SDK, une API ou un outil CLI documentable dans Context7. 3) Pour chaque voie (context7 si pertinente, officialRepos, officialDocs, community, project), donne un INDICE de recherche (où/quoi chercher). Pour 'project', identifie précisément les chemins/fichiers/version réelle à inspecter.`,
  { phase: 'Cadrage', schema: SCOPE, effort: 'medium' }
)
// Les 4 voies générales sont TOUJOURS lancées : une voie sans résultat le RAPPORTE via
// notFound, elle n'est jamais coupée d'avance. Seule context7 dépend du sujet (une question
// sans lib n'y trouve rien) ; en cas de doute (cadrage absent), elle est lancée.
const voies = [...(scope?.context7Relevant === false ? [] : ['context7']), 'officialRepos', 'officialDocs', 'community', 'project']
const hints = scope?.hints ?? {}
log(`Cadrage: ${scope?.subQuestions?.length ?? 0} sous-questions, ${voies.length} voies interrogées${voies.includes('context7') ? '' : ' (context7 sautée : sujet hors lib)'}`)

// ── Phase Sweep : 4 ou 5 voies, en parallèle (barrière : on croise APRÈS tout) ─
phase('Sweep')
// Doctrine ressources :
//  - SKILLS : les agents peuvent invoquer un skill spécialisé (outil Skill) si pertinent —
//    laissé à leur jugement, pas d'injonction ici.
//  - MCP : utilisé UNIQUEMENT là où le workflow le prescrit explicitement (voie context7
//    ci-dessous). Pas d'usage MCP implicite dans les autres voies.
//  - AGENTS : total borné par AGENTS_MAX (déclaré en tête pour guard-budget-agents) ;
//    le Challenge prend ce qui reste après les phases fixes.
// Économie d'input partagée par les voies web : le Sweep est le poste input dominant
// du run — on borne les fetches sans borner la recherche (chercher large, charger ciblé).
const FETCH_ECONOMY = `RÈGLE D'ÉCONOMIE : cherche large mais ne CHARGE que 2-3 pages ciblées maximum ; extrais les claims au fil de l'eau, ne charge jamais une page entière "au cas où".`
const VOIE_PROMPT = {
  context7: `Voie CONTEXT7 (quota partagé — économe) : interroge la doc à jour via Context7. RÈGLES D'ÉCONOMIE : (a) si tu connais déjà l'ID de lib (ex. /withastro/docs, /wordpress/gutenberg, /freemius/freemius-js), appelle query-docs DIRECTEMENT — saute resolve-library-id ; ne l'utilise que pour une lib inconnue ; (b) regroupe TOUS tes besoins doc en UN SEUL query-docs à 'topic' large, jamais une rafale de requêtes étroites. Extrais des affirmations sourcées (ID Context7). Si le sujet n'a pas de lib Context7, dis-le dans notFound.`,
  officialRepos: `Voie DÉPÔTS/GUIDES OFFICIELS : cherche les repos GitHub officiels / guides de référence de l'éditeur du sujet (et un éventuel skill-creator, llms.txt, .well-known). Affirmations sourcées (URLs réelles). Ne conclus "n'existe pas" qu'APRÈS recherche. ${FETCH_ECONOMY}`,
  officialDocs: `Voie DOC OFFICIELLE DU SUJET : la documentation officielle (site éditeur, spec). Affirmations sourcées (URLs). Chiffres/limites/signatures exacts si pertinents. ${FETCH_ECONOMY}`,
  community: `Voie COMMUNAUTÉ : sources communautaires sérieuses (Stack Overflow, blogs techniques reconnus, issues GitHub, discussions). Affirmations sourcées + signale leur fiabilité (officiel-adjacent vs opinion isolée). ${FETCH_ECONOMY}`,
  project: `Voie PROJET (vérité terrain) : inspecte le CODE et le contexte du projet courant (Grep/Glob/Read, package.json, versions réellement installées dans node_modules, conventions). Affirmations sourcées en fichier:ligne. C'est la réalité du terrain, prioritaire sur les généralités quand la question porte sur "ici".
Indices projet : ${projectHint}`,
}
// Routage par RÔLE générique : la voie 'project' (fouille du code/terrain) va à
// l'agent Explore, taillé pour balayer une codebase et localiser sans tout lire.
// Les autres voies restent en agent générique (recherche web/MCP ouverte).
// Fallback sûr : si 'Explore' n'existe pas, le runtime retombe sur le générique.
const AGENT_TYPE = { project: 'Explore' }
// Routage MODÈLE par nature de tâche : les 4 voies de COLLECTE web tournent sur Sonnet
// (récupération/extraction bien spécifiée = sa zone ; ~40-60 % moins cher par token),
// en GARDANT effort high — on ne cumule jamais les deux downgrades (modèle ET effort).
// La voie 'project' (vérité terrain, prioritaire à l'arbitrage) et toutes les phases
// d'analyse (Croisement/Challenge/Arbitrage) restent sur le modèle de session.
// Chaque sous-agent a un contexte isolé → aucun coût de cache lié au changement de modèle.
const VOIE_MODEL = { context7: 'sonnet', officialRepos: 'sonnet', officialDocs: 'sonnet', community: 'sonnet' }
const findings = (await parallel(
  voies.map((v) => () =>
    agent(
      `${VOIE_PROMPT[v]}\n\nQuestion globale : ${question}\nSous-questions : ${(scope?.subQuestions ?? []).join(' | ')}${hints[v] ? `\nIndice de recherche pour cette voie : ${hints[v]}` : ''}\nRends des claims atomiques, chacune avec sa source TRAÇABLE et un niveau de confiance. Si cette voie ne donne rien, remplis notFound (ne jamais conclure "rien" sans avoir cherché). Jamais d'affirmation "de mémoire" sans source.`,
      { phase: 'Sweep', label: `sweep:${v}`, schema: FINDINGS, effort: 'high', ...(AGENT_TYPE[v] ? { agentType: AGENT_TYPE[v] } : {}), ...(VOIE_MODEL[v] ? { model: VOIE_MODEL[v] } : {}) }
    )
  )
)).filter(Boolean)
const allClaims = findings.flatMap((f) => (f.claims ?? []).map((c) => ({ ...c, voie: f.voie })))
log(`Sweep: ${allClaims.length} affirmations sur ${findings.length} voies`)

// ── Phase Croisement (barrière nécessaire : besoin de TOUTES les voies) ───────
phase('Croisement')
const cross = await agent(
  `Croise ces affirmations issues de voies différentes. Déduplique, liste les ACCORDS (plusieurs voies concordent), les CONTRADICTIONS (voies en désaccord — précise les positions), puis TRIE les affirmations pivot dont dépend la réponse finale :
- keyClaimsToChallenge : celles qui méritent réfutation (contestées entre voies, OU mono-source, OU confiance ≤ medium), classées de la plus décisive à la moins décisive, chacune avec ses sources et la lentille la plus apte à la casser ;
- solidClaims : celles corroborées par 2+ voies indépendantes en high — solides, on ne dépense pas de challenge dessus.
Affirmations (voie | claim | source | confiance) :
${allClaims.map((c) => `- [${c.voie}/${c.confidence}] ${c.claim}  (src: ${c.source})`).join('\n')}`,
  { phase: 'Croisement', schema: CROSS, effort: 'high' }
)
// Budget du Challenge = ce qui reste sous AGENTS_MAX après Gate, Cadrage, Sweep, Croisement, Arbitrage.
const CHAL_BUDGET = Math.max(0, AGENTS_MAX - (4 + voies.length))
const MAX_CLAIMS = Math.floor(CHAL_BUDGET / N_CHAL)
const LENS_BY_VOIE = { context7: 'officialSource', officialRepos: 'officialSource', officialDocs: 'officialSource', community: 'communityCounterexample', project: 'projectTruth' }
const candidates = cross?.keyClaimsToChallenge?.length
  ? cross.keyClaimsToChallenge
  : allClaims.filter((c) => c.confidence !== 'high').map((c) => ({ claim: c.claim, sources: c.source, lens: LENS_BY_VOIE[c.voie] ?? 'officialSource' }))
const toChallenge = candidates.slice(0, MAX_CLAIMS)
const notChallenged = candidates.slice(MAX_CLAIMS).map((c) => c.claim)
log(`Croisement: ${cross?.agreements?.length ?? 0} accords, ${cross?.contradictions?.length ?? 0} contradictions, ${toChallenge.length} à challenger, ${cross?.solidClaims?.length ?? 0} solides (dispensées)`)
if (notChallenged.length) log(`Budget d'agents : ${notChallenged.length} affirmation(s) fragile(s) NON challengée(s), transmises à l'arbitre comme telles`)

// ── Phase Challenge : une lentille choisie par affirmation selon sa nature ────
// La lentille est celle qui a le plus de chances de casser l'affirmation (choisie au
// Croisement). Avec maxChallengers=2, la seconde est la suivante dans l'ordre, pour
// diversifier plutôt que redoubler. Le challenger reçoit l'affirmation, ses sources et
// le critère, jamais le raisonnement du Croisement.
phase('Challenge')
const LENS_ORDER = ['officialSource', 'projectTruth', 'communityCounterexample', 'obsolescence']
const LENSES = {
  officialSource: `CONTRE-SOURCE OFFICIELLE : cherche la doc, spec ou changelog officiel qui la contredit (API réelle, limites chiffrées, version exacte)`,
  projectTruth: `VÉRITÉ TERRAIN PROJET : confronte-la au code et aux versions réellement installées du projet courant (Grep/Read, package.json, node_modules)`,
  communityCounterexample: `CONTRE-EXEMPLE COMMUNAUTAIRE : cherche issues GitHub, bug reports ou retours sérieux montrant qu'elle ne tient pas en pratique`,
  obsolescence: `OBSOLESCENCE : vérifie si elle était vraie mais ne l'est plus (breaking change, dépréciation, changement de version)`,
}
const lensFor = (c, k) => {
  const first = LENS_ORDER.includes(c.lens) ? c.lens : 'officialSource'
  return LENS_ORDER[(LENS_ORDER.indexOf(first) + k) % LENS_ORDER.length]
}
const challenged = (await parallel(
  toChallenge.flatMap((c, i) =>
    Array.from({ length: N_CHAL }, (_, k) => () =>
      agent(
        `Challenger, lentille ${LENSES[lensFor(c, k)]}. Tente de RÉFUTER cette affirmation par le signal le plus direct accessible à ta lentille.
Affirmation : "${c.claim}"
Sources qui la portent : ${c.sources}
Critère : l'affirmation est-elle vraie telle qu'énoncée, dans le cadre de cette question : ${question}
Périmètre : la justesse de l'affirmation, sans seuil de gravité. Une nuance qui ne la rend pas fausse n'est pas une réfutation. survives=false seulement avec une preuve (contre-source, contre-exemple, version réelle). Remplis exercised avec ce que tu as réellement consulté ou exécuté.`,
        { phase: 'Challenge', label: `challenge:${i + 1}:${lensFor(c, k)}`, schema: CHALLENGE, effort: 'high' }
      ).then((r) => (r ? { ...r, claim: c.claim } : null))
    )
  )
)).filter(Boolean)
// Consolidation : une affirmation est "fragile" si une majorité de challengers la réfute.
const byClaim = {}
for (const c of challenged) {
  ;(byClaim[c.claim] ??= []).push(c)
}
const claimVerdicts = Object.entries(byClaim).map(([claim, votes]) => {
  const refuted = votes.filter((v) => !v.survives).length
  return { claim, fragile: refuted * 2 >= votes.length, refuted, total: votes.length, evidence: votes.map((v) => v.evidence) }
})
log(`Challenge: ${claimVerdicts.filter((v) => v.fragile).length}/${claimVerdicts.length} affirmations pivot fragilisées`)

// ── Phase Arbitrage : vue globale, tranche au cas par cas ─────────────────────
phase('Arbitrage')
const verdict = await agent(
  `Tu es l'ARBITRE. Tranche la réponse finale selon une vue GLOBALE.
Question : ${question}

ACCORDS entre voies : ${(cross?.agreements ?? []).join(' | ') || '—'}
CONTRADICTIONS : ${(cross?.contradictions ?? []).map((c) => `${c.topic}: ${c.positions}`).join(' | ') || '—'}
CLAIMS SOLIDES (corroborées par 2+ voies en high — dispensées de challenge, à traiter comme fiables) : ${(cross?.solidClaims ?? []).join(' | ') || '—'}
VERDICTS DE CHALLENGE (affirmation → fragile ?) :
${claimVerdicts.map((v) => `- "${v.claim}" → ${v.fragile ? 'FRAGILE' : 'tient'} (${v.refuted}/${v.total} réfutent)`).join('\n')}
NON CHALLENGÉES faute de budget d'agents (fragiles d'origine, à traiter comme non vérifiées) : ${notChallenged.join(' | ') || '—'}
Toutes les affirmations sourcées :
${allClaims.map((c) => `- [${c.voie}] ${c.claim} (src: ${c.source})`).join('\n')}

Règles d'arbitrage : pèse les sources AU CAS PAR CAS selon la nature de la question — si elle porte sur "comment c'est fait dans CE projet" ou sur une version réellement installée, la vérité terrain PROJET prime ; si c'est une question factuelle d'API/spec, l'OFFICIEL prime ; la communauté éclaire mais ne tranche pas seule. JUSTIFIE le poids donné. Écarte les affirmations FRAGILES sauf si une source forte les rétablit. Donne la réponse tranchée, ta confiance, et liste honnêtement ce qui reste INCERTAIN (à vérifier empiriquement).`,
  { phase: 'Arbitrage', schema: VERDICT, effort: 'high' }
)

return {
  question,
  voiesUtilisees: voies,
  nbClaims: allClaims.length,
  contradictions: cross?.contradictions ?? [],
  solidClaims: cross?.solidClaims ?? [],
  fragileClaims: claimVerdicts.filter((v) => v.fragile).map((v) => v.claim),
  notChallenged,
  verdict,
  note: 'Recherche en lecture seule — aucun fichier modifié. Sources tracées dans verdict.keySources et dans les claims.',
}
