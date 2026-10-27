#!/usr/bin/env node
// Module API Asana réutilisable — global, scalable, portable, sans gid/nom/chemin en dur.
// Identité + workspaces découverts via /users/me ; pagination automatique ; Premium détecté
// dynamiquement (402 sur /search) ; retry sur 429/5xx. Le token est lu depuis le Windows
// Credential Manager via asana-cred.ps1 (situé à côté de ce module) — jamais passé en argv.
// TOUTE erreur HTTP remonte en exit code != 0 (jamais de succès silencieux).
//
// Usage : node asana-api.mjs <cmd>
//   me                         -> identité + workspaces (JSON)
//   assigned [--involved]      -> tickets non terminés assignés (et follower si --involved), TOUS workspaces, paginés
//   task <gid>                 -> détail d'une tâche
//   stories <gid>              -> commentaires + stories
//   subtasks <gid>             -> sous-tâches directes (paginé)
//   attachments <gid>          -> pièces jointes (download_url inclus)
//   download <gid> <dossier>   -> télécharge les pièces jointes dans un dossier existant et vide
//                                 (download_url est une URL signée : le token n'y est jamais envoyé)
//   comment <gid> <texte...>   -> poste un commentaire (le texte n'est pas un secret)

import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const BASE = 'https://app.asana.com/api/1.0';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

function readToken() {
  if (process.env.ASANA_PAT && process.env.ASANA_PAT.trim()) return process.env.ASANA_PAT.trim();
  // helper à côté de ce module -> chemin portable (fileURLToPath gère espaces/accents/encodage)
  const ps1 = path.join(path.dirname(fileURLToPath(import.meta.url)), 'asana-cred.ps1');
  let out;
  try {
    out = execFileSync('powershell.exe', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', ps1, '-Action', 'read'], { encoding: 'utf8' });
  } catch (e) {
    const detail = (e.stderr || e.message || '').toString().trim();
    throw new Error('Lecture du token impossible (Credential Manager vide ou helper en erreur). ' + detail);
  }
  const tok = out.trim();
  if (!tok) throw new Error('Token Asana introuvable : le Credential Manager ne contient pas "ASANA_PAT". Lancer le setup (store du PAT).');
  return tok;
}

const TOKEN = readToken();
const AUTH = { Authorization: 'Bearer ' + TOKEN };

// 1 requête avec retry sur 429/503 (backoff respectant Retry-After si présent)
async function api(pathq, opts = {}) {
  for (let attempt = 0; ; attempt++) {
    const r = await fetch(BASE + pathq, { ...opts, headers: { ...AUTH, 'Content-Type': 'application/json', ...(opts.headers || {}) } });
    if ((r.status === 429 || r.status === 503) && attempt < 3) {
      const ra = Number(r.headers.get('retry-after'));
      await sleep(Number.isFinite(ra) && ra > 0 ? ra * 1000 : 1000 * (attempt + 1));
      continue;
    }
    let body = {};
    try { body = await r.json(); } catch { /* corps vide/non-JSON */ }
    return { status: r.status, body };
  }
}

// GET paginé : suit next_page.offset jusqu'au bout (anti-troncature). Au-delà de 100 pages
// (10 000 items) on s'arrête en le SIGNALANT (jamais de troncature muette).
async function getAll(pathq, optFields) {
  const sep = pathq.includes('?') ? '&' : '?';
  const base = pathq + sep + 'limit=100' + (optFields ? '&opt_fields=' + optFields : '');
  const all = [];
  let url = base, guard = 0;
  while (url) {
    if (guard++ >= 100) return { error: 'pagination > 10000 items (plafond de sécurité atteint)', status: 0, data: all, truncated: true };
    const { status, body } = await api(url);
    if (status !== 200) return { error: body.errors || ('HTTP ' + status), status, data: all };
    all.push(...(body.data || []));
    const off = body.next_page && body.next_page.offset;
    url = off ? base + '&offset=' + encodeURIComponent(off) : null;
  }
  return { data: all };
}

async function me() {
  const { status, body } = await api('/users/me?opt_fields=gid,name,workspaces.name');
  if (status !== 200 || !body.data) throw new Error('users/me a échoué : ' + JSON.stringify(body.errors || status));
  return body.data;
}

// Assignés (+ follower si involved) non terminés, TOUS workspaces, dédupliqués par gid (rôle 'assigned' prioritaire)
async function listAssigned({ involved = false } = {}) {
  const u = await me();
  const notes = [];
  const map = new Map();
  const FIELDS = 'name,permalink_url,due_on,completed,projects.name,num_attachments,assignee.name,memberships.section.name';
  for (const ws of u.workspaces) {
    const a = await getAll(`/tasks?assignee=me&workspace=${ws.gid}&completed_since=now`, FIELDS);
    if (a.error) notes.push({ _note: `tâches assignées indisponibles sur "${ws.name}" (résultat partiel)`, status: a.status, error: a.error });
    for (const t of (a.data || [])) if (!t.completed && !map.has(t.gid)) map.set(t.gid, { ...t, _role: 'assigned', _workspace: ws.name });
    if (involved) {
      const s = await getAll(`/workspaces/${ws.gid}/tasks/search?followers.any=${u.gid}&completed=false`, FIELDS);
      if (s.error) {
        const why = s.status === 402 ? 'non-Premium (search indisponible)' : `erreur search (HTTP ${s.status})`;
        notes.push({ _note: `branche "impliqué" ignorée sur "${ws.name}" : ${why}`, status: s.status });
      }
      for (const t of (s.data || [])) if (!map.has(t.gid)) map.set(t.gid, { ...t, _role: 'follower', _workspace: ws.name });
    }
  }
  return { user: { gid: u.gid, name: u.name }, count: map.size, tickets: [...map.values()], notes };
}

// --- CLI ---
const argv = process.argv.slice(2);
const cmd = argv[0];
const positional = argv.slice(1).filter((a) => !a.startsWith('--'));
const flags = new Set(argv.slice(1).filter((a) => a.startsWith('--')).map((a) => a.slice(2)));
const out = (v) => console.log(JSON.stringify(v, null, 2));
const die = (msg) => { console.error('ERREUR: ' + (typeof msg === 'string' ? msg : JSON.stringify(msg))); process.exit(1); };

try {
  switch (cmd) {
    case 'me': out(await me()); break;
    case 'assigned': out(await listAssigned({ involved: flags.has('involved') })); break;
    case 'task': {
      const { status, body } = await api(`/tasks/${positional[0]}?opt_fields=name,notes,html_notes,assignee.name,due_on,completed,permalink_url,projects.name,memberships.section.name,custom_fields.name,custom_fields.display_value,num_subtasks,created_at,modified_at`);
      if (status !== 200 || !body.data) die(body.errors || ('HTTP ' + status));
      out(body.data); break;
    }
    case 'stories': {
      const r = await getAll(`/tasks/${positional[0]}/stories`, 'type,resource_subtype,text,created_by.name,created_at');
      if (r.error) die(r.error); out(r.data); break;
    }
    case 'attachments': {
      const r = await getAll(`/attachments?parent=${positional[0]}`, 'name,resource_subtype,download_url,permanent_url,host');
      if (r.error) die(r.error); out(r.data); break;
    }
    case 'subtasks': {
      if (!positional[0]) die('usage: subtasks <gid>');
      const r = await getAll(`/tasks/${positional[0]}/subtasks`, 'name,completed,assignee.name,due_on,permalink_url,num_subtasks');
      if (r.error) die(r.error); out(r.data); break;
    }
    case 'download': {
      const [gid, dir] = positional;
      if (!gid || !dir) die('usage: download <gid> <dossier>');
      if (!fs.existsSync(dir) || !fs.statSync(dir).isDirectory()) die('dossier absent : ' + dir);
      if (fs.readdirSync(dir).length) die('dossier non vide (un dossier neuf par ticket) : ' + dir);
      const r = await getAll(`/attachments?parent=${gid}`, 'name,download_url');
      if (r.error) die(r.error);
      const files = [];
      for (const [i, a] of r.data.entries()) {
        if (!a.download_url) { files.push({ name: a.name, skipped: 'pas de download_url (lien externe)' }); continue; }
        const res = await fetch(a.download_url); // URL signée, sans en-tête Authorization
        if (!res.ok) die(`téléchargement de "${a.name}" : HTTP ${res.status}`);
        const safe = String(i + 1).padStart(2, '0') + '-' + path.basename(a.name || 'piece').replace(/[^\w.\-]+/g, '_');
        const buf = Buffer.from(await res.arrayBuffer());
        fs.writeFileSync(path.join(dir, safe), buf);
        files.push({ name: a.name, file: safe, bytes: buf.length });
      }
      out({ dir, files }); break;
    }
    case 'comment': {
      // tout ce qui suit le gid = le texte (les '--' éventuels du texte sont préservés)
      const gid = argv[1];
      const text = argv.slice(2).join(' ');
      if (!gid || !text) die('usage: comment <gid> <texte...>');
      const { status, body } = await api(`/tasks/${gid}/stories`, { method: 'POST', body: JSON.stringify({ data: { text } }) });
      if (status >= 300 || !body.data) die(body.errors || ('HTTP ' + status));
      out({ status, result: { gid: body.data.gid, created_by: body.data.created_by && body.data.created_by.name } }); break;
    }
    default:
      console.error('usage: me | assigned [--involved] | task <gid> | stories <gid> | subtasks <gid> | attachments <gid> | download <gid> <dossier> | comment <gid> <texte...>');
      process.exit(2);
  }
} catch (e) {
  die(e.message);
}
