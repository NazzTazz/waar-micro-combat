'use strict';
const assert=require('node:assert/strict');
const {spawnSync}=require('node:child_process');
const path=require('node:path');
const cwd=path.join(__dirname,'..');
const run=args=>spawnSync('php',['bin/benchmark-cohort.php',...args],{cwd,encoding:'utf8',timeout:120000});
assert.equal(run(['--combats=33']).status,2);
assert.equal(run(['--batch=101']).status,2);
assert.equal(run(['--duration=0']).status,2);
assert.equal(run(['--duration=1','--combats=32']).status,2);
assert.equal(run(['--output=composer.json']).status,2);
const result=run(['--combats=64','--seconds=60','--batch=1']);
assert.equal(result.status,0,result.stderr);
const report=JSON.parse(result.stdout);
assert.equal(report.profileId,'test-2');
assert.equal(report.requestedCombatsPerEngine,64);
for(const workload of Object.values(report.workloads)){
  const m=workload.measurements;
  assert.equal(workload.scenarios.length,16);
  assert.equal(m.php.combats,32);assert.equal(m.rust.combats,32);
  assert.equal(m.php.batches,2);assert.equal(m.rust.batches,2);
  assert.equal(m.parity.matchedCombats,32);
  assert.equal(m.parity.allMeasuredCombatsMatched,true);
  assert(m.matchedPrefixSpeedup>0);
}
assert.notEqual(report.workloads.monotypes.inputFingerprint,report.workloads.mixed.inputFingerprint);
const timed=run(['--duration=1']);
assert.equal(timed.status,0,timed.stderr);
const window=JSON.parse(timed.stdout);
assert.equal(window.mode,'duration');
assert.equal(window.requestedCombatsPerEngine,null);
assert.equal(window.batchIterations,1);
assert.equal(window.workloads.balanced.scenarios.length,32);
for(const kind of ['php','rust']){
  const m=window.workloads.balanced.measurements[kind];
  assert(m.seconds>=1);
  assert.equal(m.combats,m.batches*32);
  assert.equal(m.estimatedSecondsForWorkloadTarget,null);
  assert.equal(m.combatsPerSecond,m.combats/m.seconds);
}
assert(window.workloads.balanced.measurements.parity.matchedCombats>=32);
console.log('cohort-benchmark: exact count, paired seeds/results, workloads, argument guards, overwrite refusal OK');
