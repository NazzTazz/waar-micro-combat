import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import {Budget, canonical, digest, mergeResults, partitionRequest} from '../bin/benchmark-b1.mjs';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const run = args => spawnSync('node', ['bin/benchmark-b1.mjs', ...args], {cwd:root, encoding:'utf8', timeout:30000});
assert.deepEqual(canonical({z:2,a:{b:3,a:4}}), {a:{a:4,b:3},z:2});
assert.equal(digest({b:2,a:1}), digest({a:1,b:2}));
assert.deepEqual(partitionRequest({totalIterations:100,startIteration:0,iterations:100},20,5), {totalIterations:100,startIteration:20,iterations:5});
assert.throws(() => partitionRequest({totalIterations:100},99,2), /Invalid range/);
const budget = new Budget({maximumCombats:10,maximumSeconds:20,usedCombats:5,usedSeconds:0});
assert.equal(budget.canStart(5,100), true);
budget.reserve(5);
assert.equal(budget.canStart(1,100), false);
const base = {
  startIteration:0,iterations:1,iterationRange:{start:0,endExclusive:1,total:2,complete:false},
  stochasticEngineVersion:'same',consequenceProvenance:{policyVersion:'same'},scenarios:[{
    id:'A-B',armyIdentities:{attacker:'A',defender:'B'},result:{samples:1,attackerWins:1,defenderWins:0,draws:0,roundSum:3,attackerInitialByType:[5],defenderInitialByType:[5],attackerRawDeathsByType:[1],defenderRawDeathsByType:[2],attackerRawWoundedByType:[0],defenderRawWoundedByType:[0],attackerProjectedByType:[[4,0,1,0]],defenderProjectedByType:[[3,0,2,0]]}
  }],totalCombats:1,
};
const second = structuredClone(base);
second.startIteration=1;second.iterationRange={start:1,endExclusive:2,total:2,complete:false};
const merged=mergeResults([base,second]);
assert.equal(merged.totalCombats,2);
assert.equal(merged.scenarios[0].result.attackerWins,2);
assert.deepEqual(merged.scenarios[0].result.attackerInitialByType,[5]);
assert.deepEqual(merged.scenarios[0].result.attackerProjectedByType,[[8,0,2,0]]);
assert.throws(()=>mergeResults([second,base]),/Noncontiguous/);
const preview=run(['--dry-run','--cases=nazz/archer-5-mirror','--repeat=1','--prior-combats=0']);
assert.equal(preview.status,0,preview.stderr);
assert.equal(JSON.parse(preview.stdout).plannedMaximumCombats,1408);
const directory=fs.mkdtempSync(path.join(os.tmpdir(),'waar-b1-test-'));
try {
  const output=path.join(directory,'result.json');
  const result=run(['--cases=nazz/archer-5-mirror','--repeat=1','--prior-combats=0','--prior-seconds=0',`--output=${output}`]);
  assert.equal(result.status,0,result.stderr);
  const report=JSON.parse(fs.readFileSync(output,'utf8'));
  assert.equal(report.status,'completed');
  assert.equal(report.completedCombats,1408);
  assert.equal(report.attemptedCombats,1408);
  assert.equal(report.parity.length,5);
  assert(report.parity.every(row=>row.exact));
  assert(report.results.every(row=>row.identicalReplays && row.errors.length===0));
  const overwrite=run(['--cases=nazz/archer-5-mirror','--repeat=1',`--output=${output}`]);
  assert.notEqual(overwrite.status,0);
  assert.match(overwrite.stderr,/Output already exists/);
} finally {
  fs.rmSync(directory,{recursive:true,force:true});
}
console.log('b1 benchmark: budgets, ranges, exact merge, process parity and overwrite refusal OK (1 408 combats)');
