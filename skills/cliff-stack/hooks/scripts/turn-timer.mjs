// turn-timer.mjs - UserPromptSubmit: stamps the start of a turn in ~/.claude/.turn-timing/<session_id>.txt, read then
// consumed by play-sound.mjs (stop mode rings only after a long turn). Never blocks.
import { mkdirSync, writeFileSync } from 'node:fs';
import { run } from './lib/hook.mjs';
import { markerDir, markerPath, nowTicks } from './lib/turn-timing.mjs';

run((payload) => {
  const sid = (payload && payload.session_id) || 'default';
  mkdirSync(markerDir(), { recursive: true });
  writeFileSync(markerPath(sid), String(nowTicks()));
});
