#!/usr/bin/env node
// Issue #25: bounded process/JSONL benchmark, never used by the web application.
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import crypto from 'node:crypto';
import {spawn, spawnSync} from 'node:child_process';
import {performance} from 'node:perf_hooks';
import {fileURLToPath} from 'node:url';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const corpusPath = path.join(root, 'docs/benchmarks/2026-09-26-b1/corpus.json');
const defaultBinary = path.join(root, 'engines/waar-cohort/rust/target/release/waar-cohort-cli' + (process.platform === 'win32' ? '.exe' : ''));
const phpWorker = path.join(root, 'bin/b1-php-worker.php');
const corpus = JSON.parse(fs.readFileSync(corpusPath, 'utf8'));
const options = Object.fromEntries(process.argv.slice(2).map(arg => {
  const match = /^--([a-z-]+)(?:=(.*))?$/.exec(arg);
  if (!match) throw new Error(`Invalid argument: ${arg}`);
  return [match[1], match[2] ?? true];
}));
const allowed = new Set(['output','dry-run','repeat','timeout-ms','max-combats','max-seconds','prior-combats','prior-seconds','cases','binary','transports','partition-only','partition-total','partition-timeout-ms','sizes','skip-partition','restart-each']);
for (const key of Object.keys(options)) if (!allowed.has(key)) throw new Error(`Unknown option: ${key}`);
const number = (key, fallback, low, high) => {
  const value = options[key] === undefined ? fallback : Number(options[key]);
  if (!Number.isFinite(value) || value < low || value > high) throw new Error(`Invalid --${key}`);
  return value;
};
const repeat = number('repeat', 5, 1, 10);
const timeoutMs = number('timeout-ms', 2000, 100, 10000);
const maxCombats = number('max-combats', 250000, 1, 250000);
const maxSeconds = number('max-seconds', 600, 1, 600);
const priorCombats = number('prior-combats', 500, 0, maxCombats);
const priorSeconds = number('prior-seconds', 5, 0, maxSeconds);
const partitionTotal = number('partition-total', 20, 10, 100);
if (!Number.isInteger(partitionTotal) || partitionTotal % 4 !== 0) throw new Error('--partition-total must be a multiple of four');
const partitionTimeoutMs = number('partition-timeout-ms', 6000, 2000, 10000);
const binary = options.binary ? path.resolve(String(options.binary)) : defaultBinary;
const transports = options.transports ? String(options.transports).split(',') : ['php','rust'];
if (transports.some(value => !['php','rust'].includes(value))) throw new Error('Invalid transports');
const selectedSizes = options.sizes ? String(options.sizes).split(',').map(Number) : null;
if (selectedSizes && selectedSizes.some(value => ![1,5,20,50,100].includes(value))) throw new Error('Invalid sizes');
const chosen = options.cases ? String(options.cases).split(',') : [
  'nazz/archer-5-mirror', 'nazz/spear-171-knight-21',
  'nazz/soldier-12000-spear-1714', 'nazz/mixed-120000',
  'nazz/mixed-scale-800000', 'test2/mixed-120000', 'test2/monotypes-16',
];
for (const key of chosen) if (!corpus.cases[key]) throw new Error(`Unknown case: ${key}`);
if (options.output && fs.existsSync(options.output)) throw new Error('Output already exists');
if (!fs.existsSync(binary)) throw new Error(`Rust release binary missing: ${binary}`);

export function canonical(value) {
  if (Array.isArray(value)) return value.map(canonical);
  if (value !== null && typeof value === 'object') return Object.fromEntries(Object.keys(value).sort().map(key => [key, canonical(value[key])]));
  return value;
}
export function digest(value) {
  return crypto.createHash('sha256').update(JSON.stringify(canonical(value))).digest('hex');
}
export function partitionRequest(request, start, iterations) {
  if (!Number.isInteger(start) || !Number.isInteger(iterations) || start < 0 || iterations < 1 || start + iterations > request.totalIterations) throw new Error('Invalid range');
  return {...request, startIteration: start, iterations};
}
export function mergeResults(parts) {
  if (!parts.length) throw new Error('No parts');
  const first = parts[0];
  const merged = structuredClone(first);
  const sum = (left, right) => Array.isArray(left) ? left.map((value, i) => sum(value, right[i])) : left + right;
  let next = 0;
  for (const [partIndex, part] of parts.entries()) {
    if (part.startIteration !== next || part.iterationRange.start !== next || part.scenarios.length !== first.scenarios.length) throw new Error('Noncontiguous or incompatible ranges');
    if (part.iterationRange.total !== first.iterationRange.total || part.stochasticEngineVersion !== first.stochasticEngineVersion || digest(part.consequenceProvenance) !== digest(first.consequenceProvenance)) throw new Error('Changed context');
    for (let i = 0; i < part.scenarios.length; i++) {
      const source = part.scenarios[i], target = merged.scenarios[i];
      if (source.id !== target.id || digest(source.armyIdentities) !== digest(target.armyIdentities)) throw new Error('Changed scenario');
      if (partIndex === 0) continue;
      const a = target.result, b = source.result;
      for (const key of ['samples','attackerWins','defenderWins','draws','roundSum','attackerRawDeathsByType','defenderRawDeathsByType','attackerRawWoundedByType','defenderRawWoundedByType','attackerProjectedByType','defenderProjectedByType']) {
        if (a[key] !== null) a[key] = sum(a[key], b[key]);
      }
      for (const key of ['attackerInitialByType','defenderInitialByType']) if (digest(a[key]) !== digest(b[key])) throw new Error('Changed initial army');
    }
    next += part.iterations;
  }
  merged.iterations = next;
  merged.startIteration = 0;
  merged.iterationRange = {start: 0, endExclusive: next, total: first.iterationRange.total, complete: next === first.iterationRange.total};
  merged.totalCombats = next * first.scenarios.length;
  return merged;
}
export class Budget {
  constructor({maximumCombats, maximumSeconds, usedCombats = 0, usedSeconds = 0, started = performance.now()}) {
    this.maximumCombats = maximumCombats;
    this.maximumSeconds = maximumSeconds;
    this.usedCombats = usedCombats;
    this.usedSeconds = usedSeconds;
    this.started = started;
  }
  canStart(combats, timeout) {
    return this.usedCombats + combats <= this.maximumCombats && this.usedSeconds + (performance.now() - this.started) / 1000 + timeout / 1000 <= this.maximumSeconds;
  }
  reserve(combats) { this.usedCombats += combats; }
  elapsedSeconds() { return this.usedSeconds + (performance.now() - this.started) / 1000; }
}

class Worker {
  constructor(kind) { this.kind = kind; this.child = null; this.buffer = ''; this.pending = null; this.errors = ''; }
  async start() {
    const startup = performance.now();
    const command = this.kind === 'php' ? 'php' : binary;
    const args = this.kind === 'php' ? [phpWorker] : [];
    this.child = spawn(command, args, {cwd: root, stdio: ['pipe','pipe','pipe'], windowsHide: true});
    await new Promise((resolve, reject) => {this.child.once('spawn', resolve); this.child.once('error', reject)});
    this.spawnMs = performance.now() - startup;
    this.child.stdout.setEncoding('utf8');
    this.child.stdout.on('data', chunk => {
      this.buffer += chunk;
      for (let index; (index = this.buffer.indexOf('\n')) !== -1;) {
        const line = this.buffer.slice(0, index); this.buffer = this.buffer.slice(index + 1);
        const pending = this.pending; this.pending = null;
        if (pending) pending.resolve(line);
      }
    });
    this.child.stderr.setEncoding('utf8');
    this.child.stderr.on('data', chunk => {
      const room = 262144 - this.errors.length;
      if (room > 0) this.errors += chunk.slice(0, room);
    });
    this.child.once('exit', code => {if (this.pending) {this.pending.reject(new Error(`Worker exited ${code}: ${this.errors}`)); this.pending = null}});
  }
  async call(request, timeout) {
    if (this.pending) throw new Error('Concurrent calls are forbidden');
    const payload = this.kind === 'php' ? request : {operation: 'batch', request};
    const start = performance.now();
    let timer;
    let line;
    try {
      line = await Promise.race([
        new Promise((resolve, reject) => {this.pending = {resolve,reject}; this.child.stdin.write(JSON.stringify(payload) + '\n') }),
        new Promise((_, reject) => {timer = setTimeout(() => reject(new Error('watchdog-timeout')), timeout)}),
      ]);
    } finally {
      clearTimeout(timer);
    }
    const elapsedMs = performance.now() - start;
    const value = JSON.parse(line);
    if (value.error || value.ok === false) throw new Error(value.error || 'Worker failed');
    if (this.kind === 'rust' && options.binary) await new Promise(resolve => setTimeout(resolve, 2));
    const diagnostics = this.errors.split(/\r?\n/).filter(line => line.startsWith('B1_PROFILE ') || line.startsWith('B1_TRANSPORT ')).map(line => ({kind:line.slice(0,line.indexOf(' ')),...JSON.parse(line.slice(line.indexOf(' ')+1))}));
    this.errors = '';
    return {elapsedMs, result: this.kind === 'php' ? value.result : value, diagnostics};
  }
  stop(force = false) {
    if (!this.child) return;
    const child = this.child; this.child = null;
    if (!force) child.stdin.end();
    else {
      if (process.platform === 'win32') spawnSync('taskkill', ['/PID', String(child.pid), '/T', '/F'], {windowsHide: true, timeout: 3000});
      else child.kill('SIGKILL');
      child.stdin.destroy(); child.stdout.destroy(); child.stderr.destroy();
    }
  }
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
const sizes = key => key.endsWith('monotypes-16') ? [1,5,20,100] : key.includes('scale-') ? [1,5] : [1,5,20,50,100];
const plan = options['partition-only'] ? [] : chosen.flatMap(key => sizes(key).filter(value => !selectedSizes || selectedSizes.includes(value)).flatMap(iterations => transports.map(transport => ({key, iterations, transport, repetitions: repeat, warmups: 1, combatsPerCall: iterations * corpus.cases[key].request.scenarios.length}))));
if (options['dry-run']) {
  console.log(JSON.stringify({schemaVersion:'waar-b1-plan/1', cells:plan, plannedMaximumCombats:plan.reduce((n,c)=>n+c.combatsPerCall*(c.repetitions+c.warmups),priorCombats)+(chosen.includes('nazz/mixed-120000')&&!options['skip-partition']?partitionTotal*2*3:0),maxCombats,maxSeconds}, null, 2));
  process.exit(0);
}
if (!options.output) throw new Error('--output is required');
fs.mkdirSync(path.dirname(options.output), {recursive:true});
const handle = fs.openSync(options.output, 'wx'); fs.closeSync(handle);
const binaryHash = crypto.createHash('sha256').update(fs.readFileSync(binary)).digest('hex');
const report = {schemaVersion:'waar-b1-measurements/1', status:'running', start:new Date().toISOString(), head:corpus.baseHead, machine:os.hostname(), os:os.platform(), release:os.release(), arch:os.arch(), cpus:os.cpus().map(x=>x.model), node:process.version, php:spawnSync('php',['-v'],{encoding:'utf8'}).stdout.split('\n')[0], binaryHash, corpusHash:crypto.createHash('sha256').update(fs.readFileSync(corpusPath)).digest('hex'), budget:{maxCombats,maxSeconds,priorCombats,priorSeconds}, results:[], completedCombats:priorCombats, attemptedCombats:priorCombats};
const save = () => fs.writeFileSync(options.output, JSON.stringify(report,null,2)+'\n');
const budget = new Budget({maximumCombats:maxCombats,maximumSeconds:maxSeconds,usedCombats:priorCombats,usedSeconds:priorSeconds});
save();
let stopped = false;
for (const cell of plan) {
  if (stopped) break;
  const request = partitionRequest(corpus.cases[cell.key].request, 0, cell.iterations);
  const row = {...cell, timingsMs:[], spawnMs:[], hashes:[], profileStats:[], warmups:[], errors:[]};
  report.results.push(row); save();
  let worker = new Worker(cell.transport);
  await worker.start();
  row.spawnMs.push(worker.spawnMs);
  for (let i=0; i<cell.warmups+cell.repetitions; i++) {
    if (options['restart-each'] && i > 0) {
      const previous = worker.child;
      worker.stop();
      if (previous && previous.exitCode === null) await new Promise(resolve => {
        const timer = setTimeout(resolve, 1000);
        previous.once('exit', () => {clearTimeout(timer); resolve()});
      });
      worker = new Worker(cell.transport);
      await worker.start();
      row.spawnMs.push(worker.spawnMs);
    }
    if (!budget.canStart(cell.combatsPerCall, timeoutMs)) {stopped=true; report.stopReason='global-budget'; break}
    budget.reserve(cell.combatsPerCall); report.attemptedCombats += cell.combatsPerCall; save();
    try {
      const {elapsedMs,result,diagnostics} = await worker.call(request, timeoutMs);
      if (result.totalCombats !== cell.combatsPerCall || result.scenarios.length !== request.scenarios.length) throw new Error('Wrong combat count');
      report.completedCombats += cell.combatsPerCall;
      if (i < cell.warmups) row.warmups.push({elapsedMs, hash:digest(result)});
      else {row.timingsMs.push(elapsedMs); row.hashes.push(digest(result)); row.profileStats.push(...diagnostics)}
    } catch (error) {
      row.errors.push({attempt:i, message:String(error)});
      worker.stop(true); worker=null;
      break;
    } finally {save()}
  }
  worker?.stop();
  row.identicalReplays = new Set(row.hashes).size <= 1;
  save();
}
report.parity = [];
for (const key of chosen) {
  for (const iterations of sizes(key).filter(value => !selectedSizes || selectedSizes.includes(value))) {
    const rows = report.results.filter(row => row.key === key && row.iterations === iterations);
    if (rows.length !== 2 || rows.some(row => row.hashes.length === 0)) continue;
    report.parity.push({key, iterations, exact: rows[0].hashes[0] === rows[1].hashes[0], phpHash: rows[0].hashes[0], rustHash: rows[1].hashes[0]});
  }
}
if (chosen.includes('nazz/mixed-120000') && transports.includes('rust') && !options['skip-partition'] && !stopped) {
  const key = 'nazz/mixed-120000';
  const template = corpus.cases[key].request;
  const partition = {key, transport:'rust', groups:[], exact:null};
  report.partition = partition;
  const worker = new Worker('rust'); await worker.start();
  let reference = null;
  for (const groupSize of [partitionTotal, partitionTotal/2, partitionTotal/4]) {
    const group = {groupSize, arrivalMs:[], rangeHashes:[], totalMs:null};
    partition.groups.push(group);
    const parts = [];
    const began = performance.now();
    for (let start=0; start<partitionTotal; start+=groupSize) {
      const request = partitionRequest(template, start, groupSize);
      const combats = groupSize * request.scenarios.length;
      if (!budget.canStart(combats, partitionTimeoutMs)) {stopped=true; report.stopReason='global-budget'; break}
      budget.reserve(combats); report.attemptedCombats += combats; save();
      try {
        const {result} = await worker.call(request, partitionTimeoutMs);
        report.completedCombats += combats;
        parts.push(result);
        group.arrivalMs.push(performance.now()-began);
        group.rangeHashes.push(digest(result));
      } catch (error) {
        group.error = String(error); worker.stop(true); stopped=true; report.stopReason='partition-error'; break;
      } finally {save()}
    }
    if (stopped) break;
    group.totalMs = performance.now()-began;
    const merged = mergeResults(parts);
    group.mergedHash = digest(merged);
    if (reference === null) reference = group.mergedHash;
    else if (group.mergedHash !== reference) {partition.exact=false; report.stopReason='partition-mismatch'; stopped=true; break}
  }
  if (!stopped) partition.exact = true;
  worker.stop(); save();
}
if (report.parity.some(item => !item.exact)) {stopped = true; report.stopReason = 'transport-parity-mismatch'}
report.status = stopped ? 'partial-' + (report.stopReason || 'unknown') : 'completed';
report.end = new Date().toISOString(); report.elapsedSeconds = budget.elapsedSeconds();
save();
console.log(JSON.stringify({output:options.output,status:report.status,attemptedCombats:report.attemptedCombats,completedCombats:report.completedCombats,elapsedSeconds:report.elapsedSeconds,cells:report.results.length}));
}
