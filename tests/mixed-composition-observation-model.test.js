'use strict';

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

vm.runInThisContext(fs.readFileSync(path.join(__dirname, '../resources/mixed-composition-observation-model.js'), 'utf8'));
const model = globalThis.WaarMixedCompositionModel;
const unit = (initial, survivors) => ({initial, survivors, losses: initial-survivors, meanInitial: initial/10, meanSurvivors: survivors/10, meanLosses:(initial-survivors)/10});
function row(scenarioId, side, shift = 0) {
  return {scenarioId,scenarioLabel:scenarioId,side,focus:side==='attacker',army:{soldier:10,spearman:0,archer:0,knight:0},wins:5+shift,losses:5-shift,draws:0,iterations:10,winRate:.5+shift/10,meanRounds:2.5,metrics:{survivors:.4+shift/10,structure:.3+shift/10,economicValue:.4+shift/10},units:{soldier:unit(100,40+shift),spearman:unit(0,0),archer:unit(0,0),knight:unit(0,0)}};
}
const scenarios = [{id:'one',label:'One',budgets:{attacker:800,defender:800}},{id:'two',label:'Two',budgets:{attacker:800,defender:800}}];
const rows = scenarios.flatMap(s => ['attacker','defender'].map(side => row(s.id,side)));
const finalistRows = scenarios.flatMap((s,i) => ['attacker','defender'].map(side => row(s.id,side,i)));
const data = {axes:{y:[{id:'survivors',label:'Survivants'},{id:'structure',label:'Structure'},{id:'economicValue',label:'Économie'}]},scenarios,initial:{id:'initial',rows},finalists:[{id:'finalist',t31Order:1,t33Status:'objectifs-non-atteints',rows:finalistRows}]};
const snapshot = JSON.stringify(data);
let state = model.initialState(data);
assert.deepEqual(state,{candidateId:'finalist',scenarioId:'one',axis:'survivors'});
state = {...state,scenarioId:'two',axis:'economicValue'};
const view = model.view(data,state);
assert.equal(view.rows.length,2);
assert.equal(view.points.length,4,'Initial and finalist remain distinct for both sides.');
assert.equal(view.axis.id,'economicValue');
assert.equal(model.exportRows(data,state).length,4);
assert.equal(JSON.stringify(data),snapshot,'Selection never mutates measured data.');
const overlap = model.view(data,{candidateId:'finalist',scenarioId:'one',axis:'survivors'});
assert.equal(overlap.overlapPairCount,2,'Exact overlaps remain explicitly reported.');
assert.equal(model.normalize(data,{candidateId:'missing',scenarioId:'missing',axis:'missing'}).axis,'survivors');
console.log('mixed-composition-observation-model: ok');
