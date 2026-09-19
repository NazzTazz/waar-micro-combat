'use strict';
const assert=require('node:assert/strict');
const {spawnSync}=require('node:child_process');
const path=require('node:path');
const cwd=path.join(__dirname,'..');
const run=args=>spawnSync('php',['bin/benchmark-cohort.php',...args],{cwd,encoding:'utf8',timeout:30000});
assert.equal(run(['--combats=33']).status,2);
assert.equal(run(['--batch=101']).status,2);
assert.equal(run(['--output=composer.json']).status,2);
const result=run(['--combats=64','--seconds=10','--batch=1']);
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
console.log('cohort-benchmark: exact count, paired seeds/results, workloads, argument guards, overwrite refusal OK');
