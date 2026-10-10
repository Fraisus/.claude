// Runs one user journey (a « parcours ») on the real application with Playwright: real input events (isTrusted) sent by
// Chromium's DevTools protocol, never the OS mouse, keyboard or foreground. The project declares how to open its app in
// <project>/.claude/ux-e2e.json (reference/adaptateur.md); this script never names a project.
//   node ux.mjs <parcours.mjs> [--projet <dir>] [--adaptateur <file>] [--sans-trace] [-- <extra args for the app command>]
// Prints one JSON report on stdout; exit code 0 only when every step passed and the foreground was never taken.
import { spawn, execFile, execFileSync } from "node:child_process";
import { createRequire } from "node:module";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

const WINDOWS = process.platform === "win32";
const PAUSE_HUMAINE_MS = 600;
const ARRET_MS = 15000;

// ---- arguments ----
const argv = process.argv.slice(2);
const sep = argv.indexOf("--");
const propres = sep < 0 ? argv : argv.slice(0, sep);
const pourApp = sep < 0 ? [] : argv.slice(sep + 1);
const option = (nom) => { const i = propres.indexOf(nom); return i >= 0 ? propres[i + 1] : null; };
const parcoursArg = propres.find((a, i) => !a.startsWith("--") && !["--projet", "--adaptateur"].includes(propres[i - 1]));
if (!parcoursArg) { console.error("usage : node ux.mjs <parcours.mjs> [--projet <dir>] [--adaptateur <fichier>] [--sans-trace] [-- <args>]"); process.exit(2); }
const PROJET = path.resolve(option("--projet") || process.cwd());
const ADAPTATEUR = path.resolve(PROJET, option("--adaptateur") || path.join(".claude", "ux-e2e.json"));
if (!fs.existsSync(ADAPTATEUR)) { console.error(`adaptateur absent : ${ADAPTATEUR} (format : reference/adaptateur.md)`); process.exit(2); }
const conf = JSON.parse(fs.readFileSync(ADAPTATEUR, "utf8"));
const PARCOURS = path.resolve(PROJET, conf.parcours && !path.isAbsolute(parcoursArg) && !fs.existsSync(path.resolve(PROJET, parcoursArg)) ? path.join(conf.parcours, parcoursArg) : parcoursArg);
const SORTIE = path.resolve(PROJET, conf.sortie || ".ux-e2e", `${path.basename(PARCOURS).replace(/\.\w+$/, "")}-${new Date().toISOString().replace(/[:.]/g, "-")}`);

// ---- Playwright, taken where it already is (the project's, PLAYWRIGHT, then npx's cache); never installed here ----
function chargerPlaywright() {
  const essais = [process.env.PLAYWRIGHT && path.join(process.env.PLAYWRIGHT, "package.json"), path.join(PROJET, "package.json")];
  for (const v of ["LOCALAPPDATA", "APPDATA", "HOME"]) {
    const cache = process.env[v] && path.join(process.env[v], v === "HOME" ? ".npm" : "npm-cache", "_npx");
    if (cache && fs.existsSync(cache)) for (const d of fs.readdirSync(cache)) essais.push(path.join(cache, d, "node_modules", "playwright", "package.json"));
  }
  for (const base of essais.filter(Boolean)) {
    try {
      const req = createRequire(base);
      const pw = req(base.endsWith(path.join("playwright", "package.json")) ? path.dirname(base) : "playwright");
      let expect = null;
      try { expect = req(base.endsWith(path.join("playwright", "package.json")) ? path.join(path.dirname(base), "test") : "playwright/test").expect; } catch { /* assertions by verifier() only */ }
      return { pw, expect };
    } catch { /* next */ }
  }
  throw new Error("Playwright introuvable : PLAYWRIGHT=<dossier du paquet playwright>, ou npx playwright --version une fois");
}

// the adapter's browser, else Playwright's, else one already installed (never downloaded here): which one is reported
async function lancerNavigateur(chromium, headless) {
  const essais = conf.navigateur ? [{ executablePath: path.resolve(PROJET, conf.navigateur) }] : [{}, { channel: "msedge" }, { channel: "chrome" }];
  let derniere;
  for (const e of essais) {
    try {
      const n = await chromium.launch({ headless, ...e });
      rapport.navigateur = e.executablePath || e.channel || "chromium de Playwright";
      return n;
    } catch (err) { derniere = err; }
  }
  throw derniere;
}

// ---- processes: only what this run launched is ever stopped ----
// Windows reuses process ids: a process is ours only if it descends from this script or from a root it launched (its
// commands, the owner of the announced DevTools port) and was born after its parent. The sampler (sonde.ps1) sees every
// process alive 100 ms or more and the history keeps the dead, so a launcher or an intermediate that already left still
// links its children; a process is stopped only while its birth time is still the one recorded, this script never
const ENV_ORIGINE = { ...process.env };
const racines = new Set([process.pid]);
// birth times are FILETIME strings (beyond 2^53: never a Number), compared as BigInt, always from the same sampler
const suivis = new Map();  // pid -> birth time (Windows)
const histo = new Map();   // pid -> { parent, ne }, every process the sampler saw, the dead included
const apres = (ne, borne) => BigInt(ne) >= BigInt(borne);
const SONDE = path.join(path.dirname(fileURLToPath(import.meta.url)), "sonde.ps1");
const premierPlan = new Set();
let naissanceScript = null, sonde = null;
function retenir() {
  naissanceScript ??= histo.get(process.pid)?.ne ?? null;
  if (naissanceScript === null) return;
  for (const r of racines) if (histo.has(r) && !suivis.has(r) && (r === process.pid || apres(histo.get(r).ne, naissanceScript))) suivis.set(r, histo.get(r).ne);
  for (let ajout = true; ajout;) {
    ajout = false;
    for (const [p, { parent, ne }] of histo) {
      if (suivis.has(p) || p === sonde?.pid) continue;  // the sampler and its console are never ours to stop
      const borne = histo.has(parent) ? (histo.get(parent).ne === suivis.get(parent) ? suivis.get(parent) : undefined)
        : (suivis.get(parent) ?? (racines.has(parent) ? naissanceScript : undefined));
      if (borne === undefined || !apres(ne, borne)) continue;
      suivis.set(p, ne);
      ajout = true;
    }
  }
}
// running before anything is launched; without it (not Windows, PowerShell refused) nothing is stopped by force
function demarrerSonde() {
  if (!WINDOWS) return Promise.resolve();
  sonde = spawn("powershell", ["-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-File", SONDE, "-ms", "100"], { stdio: ["ignore", "pipe", "pipe"], windowsHide: true });
  let reste = "", erreurs = "";
  sonde.stderr.on("data", (d) => { erreurs += d; });
  return new Promise((ok) => {
    const minuteur = setTimeout(() => { rapport.sonde = `sans relevé des processus : ${erreurs.slice(0, 300) || "délai"}`; ok(); }, 20000);
    sonde.on("error", (e) => { clearTimeout(minuteur); rapport.sonde = `sans relevé des processus : ${e.message}`; ok(); });
    sonde.stdout.on("data", (d) => {
      reste += d;
      const lignes = reste.split(/\r?\n/);
      reste = lignes.pop();
      for (const l of lignes) {
        const [k, a, b, c] = l.trim().split(" ");
        if (k === "P" && /^\d+$/.test(c ?? "")) histo.set(Number(a), { parent: Number(b), ne: c });
        else if (k === "F") premierPlan.add(Number(a));
        else if (k === "PRET") { clearTimeout(minuteur); ok(); }
      }
      retenir();
    });
  });
}
// the process that listens on the announced port: the app itself, even when its launcher already left
function proprietairePort(port) {
  if (!WINDOWS) return Promise.resolve(null);
  return new Promise((ok) => execFile("powershell", ["-NoProfile", "-NonInteractive", "-Command",
    `(Get-NetTCPConnection -LocalPort ${Number(port)} -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1).OwningProcess`],
  { encoding: "utf8", windowsHide: true }, (err, sortie) => ok(err ? null : Number(sortie.trim()) || null)));
}
// a .cmd or .bat (npm, pnpm, yarn…) only runs through the shell on Windows: its arguments quoted for it
function lancer(commande, env) {
  const [exe, ...args] = commande;
  const parShell = WINDOWS && (/\.(cmd|bat)$/i.test(exe) || /^(npm|npx|pnpm|yarn)$/i.test(exe));
  const q = (a) => (/[\s"&|<>^%]/.test(a) ? `"${a.replace(/"/g, '""')}"` : a);
  const options = { cwd: PROJET, env: { ...ENV_ORIGINE, ...env }, stdio: ["pipe", "pipe", "pipe"], detached: !WINDOWS, windowsHide: true };
  const p = parShell ? spawn([exe, ...args].map(q).join(" "), { ...options, shell: true }) : spawn(exe, args, options);
  p.on("error", (err) => { p.erreur = err; });
  if (p.pid) racines.add(p.pid);
  return p;
}
async function arreter(p) {
  if (!p) return;
  if (p.exitCode === null && p.signalCode === null && !p.erreur) {
    try { p.stdin.end(); } catch { /* closed */ }
    await Promise.race([new Promise((ok) => p.once("exit", ok)), new Promise((ok) => setTimeout(ok, ARRET_MS))]);
  }
  if (!WINDOWS) { try { process.kill(-p.pid, "SIGKILL"); } catch { /* gone */ } }
}
// what is left of ours once every command was asked to stop, a launcher that left without its children included
function arreterRestants() {
  if (!WINDOWS || !suivis.size) return [];
  let tous;
  // one pass of the same sampler: the same birth times, to the 100 ns
  try {
    tous = new Map();
    for (const l of execFileSync("powershell", ["-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-File", SONDE, "-une"],
      { encoding: "utf8", windowsHide: true }).split(/\r?\n/)) {
      const [k, a, , c] = l.trim().split(" ");
      if (k === "P") tous.set(Number(a), c);
    }
  } catch { return []; }
  const restants = [...suivis].filter(([p, ne]) => p !== process.pid && p !== sonde?.pid && tous.get(p) === ne).map(([p]) => p);
  for (const p of restants) { try { execFileSync("taskkill", ["/F", "/PID", String(p)], { stdio: "ignore", windowsHide: true }); } catch { /* gone */ } }
  return restants;
}
const attendreLigne = (p, motif, delai) => new Promise((ok, ko) => {
  let tampon = "";
  const lire = (d) => { tampon += d; const m = motif.exec(tampon); if (m) { fini(); ok(m); } };
  const minuteur = setTimeout(() => { fini(); ko(new Error(`rien ne correspond à ${motif} en ${delai} ms : ${tampon.slice(-600)}`)); }, delai);
  const sortie = (c) => { fini(); ko(new Error(`la commande s'est arrêtée (code ${c}) : ${tampon.slice(-600)}`)); };
  const erreur = (err) => { fini(); ko(new Error(`la commande ne s'est pas lancée : ${err.message}`)); };
  const fini = () => { clearTimeout(minuteur); p.stdout.off("data", lire); p.stderr.off("data", lire); p.off("exit", sortie); p.off("error", erreur); };
  if (p.erreur) return erreur(p.erreur);
  p.stdout.on("data", lire); p.stderr.on("data", lire); p.on("exit", sortie); p.on("error", erreur);
});

// ---- the run ----
const rapport = { ok: false, parcours: path.relative(PROJET, PARCOURS), cible: conf.cible, etapes: [], captures: [], trace: null, premier_plan_pris: [], erreur: null };
const t0 = Date.now();
let app = null, serveur = null, navigateur = null;
const TMP = path.join(SORTIE, "tmp");
const contextes = new Set();
try {
  fs.mkdirSync(TMP, { recursive: true });
  // Playwright's own temporary files (trace pieces holding the pages' text, profiles) stay in the run's folder
  for (const v of ["TEMP", "TMP", "TMPDIR"]) process.env[v] = TMP;
  const { pw, expect } = chargerPlaywright();
  const parcours = (await import(pathToFileURL(PARCOURS).href)).default;
  if (typeof parcours !== "function") throw new Error("le parcours doit exporter par défaut une fonction async ({ ... })");
  await demarrerSonde();

  if (conf.cible === "cdp") {
    app = lancer([...conf.commande, ...pourApp], conf.env);
    const m = await attendreLigne(app, /DevTools listening on ws:\/\/(127\.0\.0\.1|\[::1\]|localhost):(\d+)\//, conf.attente_ms ?? 60000);
    const proprietaire = await proprietairePort(m[2]);
    if (proprietaire) { racines.add(proprietaire); retenir(); }
    navigateur = await pw.chromium.connectOverCDP(`http://${m[1]}:${m[2]}`);
  } else if (conf.cible === "web") {
    if (conf.serveur) {
      serveur = lancer(conf.serveur.commande, conf.serveur.env);
      await attendreLigne(serveur, new RegExp(conf.serveur.pret), conf.serveur.attente_ms ?? 60000);
    }
    navigateur = await lancerNavigateur(pw.chromium, conf.headless !== false);
    const ctx = await navigateur.newContext(conf.contexte || {});
    await (await ctx.newPage()).goto(conf.url);
  } else throw new Error(`cible inconnue : ${conf.cible} (cdp ou web)`);

  const tracer = async (ctx) => {
    if (contextes.has(ctx) || propres.includes("--sans-trace")) return;
    contextes.add(ctx);
    await ctx.tracing.start({ screenshots: true, snapshots: true }).catch(() => contextes.delete(ctx));
  };
  for (const ctx of navigateur.contexts()) await tracer(ctx);
  const pages = () => navigateur.contexts().flatMap((c) => c.pages());

  // the page whose URL matches `filtre.url` or that holds `filtre.contient` (a selector or a locator factory), polled
  async function page(filtre = {}, delai = 15000) {
    const fin = Date.now() + delai;
    for (;;) {
      for (const ctx of navigateur.contexts()) await tracer(ctx);
      for (const p of pages()) {
        if (filtre.url && !(filtre.url instanceof RegExp ? filtre.url.test(p.url()) : p.url().includes(filtre.url))) continue;
        if (filtre.contient) {
          const loc = typeof filtre.contient === "function" ? filtre.contient(p) : p.locator(filtre.contient);
          if (!(await loc.first().isVisible().catch(() => false))) continue;
        }
        return p;
      }
      if (Date.now() > fin) throw new Error(`aucune page ne correspond à ${JSON.stringify({ url: String(filtre.url ?? ""), contient: String(filtre.contient ?? "") })} ; pages : ${pages().map((p) => p.url()).join(", ")}`);
      await new Promise((ok) => setTimeout(ok, 100));
    }
  }
  async function etape(nom, fn) {
    const debut = Date.now();
    try {
      const r = await fn();
      rapport.etapes.push({ nom, ok: true, ms: Date.now() - debut });
      return r;
    } catch (e) {
      rapport.etapes.push({ nom, ok: false, ms: Date.now() - debut, erreur: String(e?.message || e).split("\n")[0] });
      throw e;
    }
  }
  const pause = (ms = PAUSE_HUMAINE_MS) => new Promise((ok) => setTimeout(ok, ms));
  const verifier = (cond, message) => { if (!cond) throw new Error(`vérification : ${message}`); };
  await parcours({ page, pages, etape, pause, verifier, expect, sortie: SORTIE, navigateur });
  // a step the journey caught itself still fails the run
  rapport.ok = rapport.etapes.every((x) => x.ok);
  if (!rapport.ok) rapport.erreur = `étape en échec : ${rapport.etapes.filter((x) => !x.ok).map((x) => x.nom).join(", ")}`;
} catch (e) {
  rapport.erreur = String(e?.stack || e);
} finally {
  if (navigateur) {
    let i = 0;
    for (const p of navigateur.contexts().flatMap((c) => c.pages())) {
      const f = path.join(SORTIE, `page-${++i}.png`);
      if (await p.screenshot({ path: f }).then(() => true, () => false)) rapport.captures.push(f);
    }
    let n = 0;
    for (const ctx of contextes) {
      const f = path.join(SORTIE, `trace${n++ ? `-${n}` : ""}.zip`);
      if (await ctx.tracing.stop({ path: f }).then(() => true, () => false)) rapport.trace ??= f;
    }
    await navigateur.close().catch(() => {});
  }
  await arreter(app);
  await arreter(serveur);
  await new Promise((ok) => setTimeout(ok, 300));  // the sampler's last rounds: what the commands left behind
  retenir();
  // the script and the sampler are never counted: a console of their own could hold the foreground
  rapport.premier_plan_pris = [...premierPlan].filter((p) => suivis.has(p) && p !== process.pid && p !== sonde?.pid);
  if (rapport.premier_plan_pris.length) { rapport.ok = false; rapport.erreur ??= `premier plan pris par un processus de l'essai : ${rapport.premier_plan_pris.join(", ")}`; }
  rapport.processus_arretes_de_force = arreterRestants();
  sonde?.kill();
  try { fs.rmSync(TMP, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 }); } catch (e) { rapport.tmp_non_efface = `${TMP} (${e.code || e.message})`; }
  rapport.duree_ms = Date.now() - t0;
  rapport.sortie = SORTIE;
  console.log(JSON.stringify(rapport, null, 1));
  process.exit(rapport.ok ? 0 : 1);
}
