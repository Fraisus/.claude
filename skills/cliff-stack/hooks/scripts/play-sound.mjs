// play-sound.mjs - plays the notification bell (sounds/notify.wav). Argument "always" (default): rings every time
// (Notification hook). Argument "stop": rings only when the turn stamped by turn-timer.mjs lasted over 12 seconds
// (Stop hook); the marker is consumed, and a missing marker stays silent. Never blocks.
import { spawn } from 'node:child_process';
import { existsSync, readFileSync, unlinkSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { run } from './lib/hook.mjs';
import { markerPath, nowTicks } from './lib/turn-timing.mjs';

const THRESHOLD_SEC = 12;
const SOUND = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..', 'sounds', 'notify.wav');

function turnWasLong(sid) {
  const marker = markerPath(sid);
  if (!existsSync(marker)) return false;
  const start = BigInt(readFileSync(marker, 'utf8').trim());
  unlinkSync(marker);
  return Number(nowTicks() - start) / 1e7 > THRESHOLD_SEC;
}

run((payload) => {
  const mode = process.argv[2] || 'always';
  const sid = (payload && payload.session_id) || 'default';
  if (mode.toLowerCase() === 'stop' && !turnWasLong(sid)) return;
  if (!existsSync(SOUND)) return;
  // PlaySync inside a detached process: it outlives the hook and the harness killing it at the end of the turn.
  const command = `(New-Object System.Media.SoundPlayer '${SOUND.replaceAll("'", "''")}').PlaySync()`;
  spawn('powershell.exe', ['-NoProfile', '-WindowStyle', 'Hidden', '-Command', command], {
    detached: true,
    stdio: 'ignore',
    windowsHide: true,
  }).unref();
});
