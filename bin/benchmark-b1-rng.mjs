#!/usr/bin/env node
// Replays calls captured by the feature-gated profiler; never resolves a combat.
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import {spawnSync} from 'node:child_process';

const [source, output] = process.argv.slice(2);
if (!source || !output || fs.existsSync(output)) throw new Error('Usage: node bin/benchmark-b1-rng.mjs PROFILE-JSON NEW-OUTPUT-JSON');
const report = JSON.parse(fs.readFileSync(source, 'utf8'));
const row = report.results.find(item => item.key === 'nazz/mixed-120000' && item.iterations === 5);
const sample = row?.profileStats.find(item => item.kind === 'B1_PROFILE')?.rngSamples;
if (!Array.isArray(sample) || !sample.length) throw new Error('No captured RNG calls');
const binary = path.join(path.dirname(source), 'rust-target/release/b1-rng-bench' + (process.platform === 'win32' ? '.exe' : ''));
if (!fs.existsSync(binary)) throw new Error('Instrumented RNG binary missing: '+binary);
const runs = [];
for (let i=0; i<5; i++) {
  const result=spawnSync(binary,{input:JSON.stringify(sample),encoding:'utf8',timeout:5000,windowsHide:true});
  if (result.status !== 0) throw new Error(result.stderr || `RNG microbenchmark failed: ${result.status}`);
  runs.push(JSON.parse(result.stdout));
}
if (new Set(runs.map(run=>run.checksum)).size !== 1) throw new Error('RNG checksum changed');
const outputReport={schemaVersion:'waar-b1-rng-microbenchmark-runs/1',source,sourceHash:crypto.createHash('sha256').update(fs.readFileSync(source)).digest('hex'),binaryHash:crypto.createHash('sha256').update(fs.readFileSync(binary)).digest('hex'),samples:sample,runs};
fs.writeFileSync(output,JSON.stringify(outputReport,null,2)+'\n',{flag:'wx'});
console.log(JSON.stringify({output,samples:sample.length,runs:runs.length,checksum:runs[0].checksum}));
