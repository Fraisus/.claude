"use strict";
// Fenêtre d'essai d'un projet, pilotée à la main par le serveur MCP playwright-cdp du plugin : le port CDP est celui que
// déclare le .mcp.json du plugin (source unique), la commande celle de .claude/ux-e2e.json du projet.
//   node ux-live.js lancer  [--projet=<dossier>]  lance la commande du projet avec le port, détachée, et attend que le port réponde
//   node ux-live.js etat    [--projet=<dossier>]  dit si la fenêtre d'essai répond
//   node ux-live.js arreter [--projet=<dossier>]  l'arrête ; la commande du projet fait alors son propre ménage
//   --port=<n> : un autre port que celui du plugin, pour un banc du script seul (le serveur MCP ne s'y branche pas)
//   lancer [...] -- <options> : ce qui suit « -- » est passé tel quel à la commande du projet (un vrai moteur d'essai, par exemple)
const fs = require("fs"), path = require("path"), http = require("http"), { spawn } = require("child_process");

const PLUGIN = path.resolve(__dirname, "..", "..", "..");
const SERVEUR = "playwright-cdp";
const SEP = process.argv.indexOf("--");
const args = SEP < 0 ? process.argv.slice(2) : process.argv.slice(2, SEP);
const pourCommande = SEP < 0 ? [] : process.argv.slice(SEP + 1);
const ordre = args.find((a) => !a.startsWith("--")) || "etat";
const option = (nom) => (args.find((a) => a.startsWith(`--${nom}=`)) || "").slice(nom.length + 3);
const dire = (t) => process.stderr.write(`ux-live : ${t}\n`);
const dormir = (ms) => new Promise((r) => setTimeout(r, ms));

// la racine du projet : le premier dossier, en remontant, qui porte .claude/ux-e2e.json
function projet() {
  const depart = path.resolve(option("projet") || process.cwd());
  for (let d = depart; ; d = path.dirname(d)) {
    if (fs.existsSync(path.join(d, ".claude", "ux-e2e.json"))) return d;
    if (path.dirname(d) === d) throw new Error(`aucun .claude/ux-e2e.json depuis ${depart}`);
  }
}
function portCdp() {
  if (/^\d{4,5}$/.test(option("port"))) return Number(option("port"));
  const fichier = path.join(PLUGIN, ".mcp.json");
  const s = JSON.parse(fs.readFileSync(fichier, "utf8")).mcpServers?.[SERVEUR];
  const i = (s?.args || []).indexOf("--cdp-endpoint");
  const m = i >= 0 && /^https?:\/\/127\.0\.0\.1:(\d{4,5})\/?$/.exec(s.args[i + 1] || "");
  if (!m) throw new Error(`serveur ${SERVEUR} sans --cdp-endpoint sur 127.0.0.1 dans ${fichier}`);
  return Number(m[1]);
}
function lire(racine) {
  const conf = JSON.parse(fs.readFileSync(path.join(racine, ".claude", "ux-e2e.json"), "utf8"));
  if (conf.cible !== "cdp" || !Array.isArray(conf.commande) || !conf.commande.length || typeof conf.option_cdp !== "string" || !conf.option_cdp.includes("{port}"))
    throw new Error('ux-e2e.json : il faut "cible": "cdp", "commande": [...] et "option_cdp" contenant {port}');
  const sortie = path.join(racine, conf.sortie || path.join("tmp_essais", "ux"));
  return { ...conf, racine, sortie, attente_ms: conf.attente_ms || 60000, etat: path.join(sortie, "ux-live.json"), arret: path.join(sortie, "ux-live.stop"), journal: path.join(sortie, "ux-live.log") };
}
// le port CDP répond-il ? -> la version de Chromium, sinon null
function sonder(port) {
  return new Promise((res) => {
    const q = http.get({ host: "127.0.0.1", port, path: "/json/version", timeout: 1500 }, (r) => {
      let t = "";
      r.on("data", (d) => (t += d));
      r.on("end", () => { try { res(JSON.parse(t).Browser || "?"); } catch { res(null); } });
    });
    q.on("error", () => res(null));
    q.on("timeout", () => { q.destroy(); res(null); });
  });
}
const vivant = (pid) => { try { process.kill(pid, 0); return true; } catch { return false; } };
const lireEtat = (c) => { try { return JSON.parse(fs.readFileSync(c.etat, "utf8")); } catch { return null; } };

// le gardien, détaché de la session qui l'a lancé : il tient ouverte l'entrée standard de la commande du projet et la
// ferme quand arreter dépose le fichier d'arrêt (un signal ne traverse pas Windows) ; la commande fait alors son ménage
function garder(c, port) {
  const [cmd, ...reste] = c.commande;
  const enfant = spawn(cmd, [...reste, ...pourCommande, c.option_cdp.replace("{port}", String(port))], { cwd: c.racine, stdio: ["pipe", "inherit", "inherit"], windowsHide: true });
  fs.writeFileSync(c.etat, JSON.stringify({ pid: process.pid, enfant: enfant.pid, port, debut: new Date().toISOString() }));
  const finir = () => { clearInterval(veille); try { enfant.stdin.end(); } catch {} setTimeout(() => { try { enfant.kill(); } catch {} }, 15000).unref(); };
  const veille = setInterval(() => { if (fs.existsSync(c.arret)) finir(); }, 500);
  enfant.on("exit", (code) => { clearInterval(veille); for (const f of [c.etat, c.arret]) try { fs.unlinkSync(f); } catch {} process.exit(code || 0); });
  for (const s of ["SIGINT", "SIGTERM", "SIGBREAK", "SIGHUP"]) process.on(s, finir);
}
async function lancer(c, port) {
  const deja = await sonder(port);
  if (deja) { dire(`le port ${port} répond déjà (${deja})`); return 0; }
  const ancien = lireEtat(c);
  if (ancien && vivant(ancien.pid)) { dire(`un gardien vit déjà (pid ${ancien.pid}) sans fenêtre : arreter d'abord`); return 1; }
  fs.mkdirSync(c.sortie, { recursive: true });
  const journal = fs.openSync(c.journal, "a");
  const gardien = spawn(process.execPath, [__filename, "garder", `--projet=${c.racine}`, `--port=${port}`, ...(pourCommande.length ? ["--", ...pourCommande] : [])], { cwd: c.racine, detached: true, stdio: ["ignore", journal, journal], windowsHide: true });
  gardien.unref();
  fs.closeSync(journal);
  for (const limite = Date.now() + c.attente_ms; Date.now() < limite && vivant(gardien.pid); ) {
    await dormir(500);
    const v = await sonder(port);
    if (v) { dire(`fenêtre d'essai prête : CDP sur 127.0.0.1:${port} (${v}) ; journal ${c.journal}`); return 0; }
  }
  dire(`pas de port CDP après ${c.attente_ms} ms ; voir ${c.journal}`);
  return 1;
}
async function arreter(c, port) {
  const e = lireEtat(c);
  if (!e) { dire((await sonder(port)) ? `le port ${port} répond mais aucun gardien connu ici : fenêtre lancée autrement` : "rien à arrêter"); return 0; }
  fs.writeFileSync(c.arret, "");
  // 20 s pour un arrêt propre, puis la force
  for (let i = 0; i < 60 && (vivant(e.pid) || (await sonder(e.port))); i++) {
    await dormir(500);
    if (i === 40) for (const pid of [e.enfant, e.pid]) if (vivant(pid)) try { process.kill(pid); } catch {}
  }
  for (const f of [c.etat, c.arret]) try { fs.unlinkSync(f); } catch {}
  const encore = await sonder(e.port);
  dire(encore ? `le port ${e.port} répond encore` : "fenêtre d'essai arrêtée");
  return encore ? 1 : 0;
}
async function etat(c, port) {
  const e = lireEtat(c), v = await sonder(port);
  if (v) dire(`prête : CDP sur 127.0.0.1:${port} (${v})${e ? ` ; gardien ${e.pid} depuis ${e.debut}` : ""}`);
  else if (e && vivant(e.pid)) dire(`gardien ${e.pid} vivant, port ${port} muet (démarrage en cours ? voir ${c.journal})`);
  else dire(`aucune fenêtre d'essai (port ${port} muet)`);
  return v ? 0 : 1;
}

(async () => {
  try {
    const c = lire(projet()), port = portCdp();
    if (ordre === "garder") return garder(c, port);
    const faire = { lancer, arreter, etat }[ordre];
    if (!faire) throw new Error(`ordre inconnu : ${ordre} (lancer, etat, arreter)`);
    process.exit(await faire(c, port));
  } catch (e) { dire(e.message); process.exit(2); }
})();
