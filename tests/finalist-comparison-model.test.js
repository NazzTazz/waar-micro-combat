'use strict';

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

vm.runInThisContext(fs.readFileSync(path.join(__dirname, '../resources/finalist-comparison-model.js'), 'utf8'));
const model = globalThis.WaarFinalistComparisonModel;

function candidate(id, state = 'outside', overlap = false) {
  const rows = [];
  for (let index = 0; index < 16; index++) {
    for (const side of ['attacker', 'defender']) {
      const scenarioId = `s${index + 1}`;
      const objectiveId = `${scenarioId}-${side}-tip-survivors`;
      const x = overlap ? .5 : .1 + index / 100;
      const y = overlap ? .5 : side === 'attacker' ? .3 : .7;
      const observation = {winRate:x,survivors:y,economicValue:y,structure:y+.01,wins:20,draws:0,iterations:200};
      const objective = {id:objectiveId,target:{center:{x:.5,y:.5},radii:{x:.05,y:.1}},state,normalizedRadialDistance:state==='inside'?0:5,excess:state==='inside'?0:4,lossContribution:state==='inside'?0:.125,weight:.03125};
      rows.push({objectiveId,scenarioId,scenarioLabel:`Scénario ${index+1}`,side,shape:side==='attacker'?'circle':'diamond',observation,neutral:{...observation,winRate:.5},objectives:{survivors:objective,economicValue:{...objective,aliasOf:'survivors'},structure:null}});
    }
  }
  return {kind:id==='initial'?'initial':'finalist',rank:id==='initial'?null:1,id,label:id,version:'1',parameterFingerprint:'a'.repeat(64),summary:{continuousLoss:0,objectivesSatisfied:state==='inside'?32:0,objectiveCount:32,worstObjectiveId:rows[0].objectiveId,worstExcess:state==='inside'?0:4,drawCount:0,strictControls:{passed:state==='inside'}},rows,downloads:{variant:{filename:`${id}-variant.json`,json:JSON.stringify({candidateId:id})},evaluation:{filename:`${id}-evaluation.json`,json:JSON.stringify({candidateId:id})}}};
}

function fixture(state = 'outside', overlap = false) {
  return {schemaVersion:'waar-monotype-finalist-comparison/0.1',run:{complete:true},experiment:{},axes:{y:[{id:'survivors',objectiveMode:'canonical'},{id:'economicValue',objectiveMode:'same-as-survivors'},{id:'structure',objectiveMode:'diagnostic-only'}]},scenarios:Array.from({length:16},(_,i)=>({id:`s${i+1}`,label:`Scénario ${i+1}`})),initial:candidate('initial',state,overlap),finalists:[candidate('one',state,overlap)],defaultFinalistId:'one',browserContract:{simulationAllowed:false,objectiveInferenceAllowed:false,inputMutationAllowed:false,objectiveCount:32}};
}

for (const objectiveState of ['inside','outside']) {
  const data = fixture(objectiveState, true);
  const snapshot = JSON.stringify(data);
  model.validate(data);
  const state = model.createState(data);
  assert.equal(model.view(data,state).rows.every(row => row.initial.winRate === row.observation.winRate && row.initial.survivors === row.observation.survivors), true, 'Superposed points remain available as distinct initial/candidate rows.');
  const objectives = model.globalObjectives(data,state);
  assert.equal(objectives.length,32);
  assert.equal(objectives.every(objective => objective.state === objectiveState),true, `${objectiveState} must remain visible for all objectives.`);
  state.selectAxis('economicValue'); state.selectScenario('s16'); state.toggleNeutral(true);
  assert.equal(model.view(data,state).objectiveMode,'same-as-survivors');
  state.selectAxis('structure');
  assert.equal(model.view(data,state).rows.every(row => row.objectives.structure === null),true);
  assert.equal(model.selectedDownload(data,state,'variant').filename,'one-variant.json');
  assert.equal(JSON.stringify(data),snapshot,'Changing candidate/scenario/Y must not mutate source JSON.');
}

const duplicate = fixture();
duplicate.finalists[0].rows[1].objectiveId = duplicate.finalists[0].rows[0].objectiveId;
assert.throws(()=>model.validate(duplicate),/uniques/);

const wrongExport = fixture();
wrongExport.finalists.push({...candidate('two'),rank:2});
const state = model.createState(wrongExport);
state.selectCandidate('two');
assert.equal(JSON.parse(model.selectedDownload(wrongExport,state,'evaluation').json).candidateId,'two');
assert.equal(model.globalObjectives(wrongExport,state).length,32,'Candidate switching never duplicates objectives.');

console.log('finalist-comparison-model: ok');
