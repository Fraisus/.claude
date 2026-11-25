// turn-timing.mjs - the start-of-turn marker written by turn-timer.mjs and read by play-sound.mjs.
import { homedir } from 'node:os';
import { join } from 'node:path';

// The marker holds .NET ticks (100 ns since 0001-01-01 UTC), the format the PowerShell hooks wrote.
const TICKS_AT_UNIX_EPOCH = 621355968000000000n;

export const markerDir = () => join(homedir(), '.claude', '.turn-timing');
export const markerPath = (sid) => join(markerDir(), `${sid}.txt`);
export const nowTicks = () => BigInt(Date.now()) * 10000n + TICKS_AT_UNIX_EPOCH;
