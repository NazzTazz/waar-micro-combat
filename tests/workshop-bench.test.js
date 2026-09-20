'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const fakeClock=require('./helpers/fake-clock');
require('../public/workshop/model.js');
const M=globalThis.WaarWorkshopModel;

(async()=>{
  const clock=fakeClock(),storageData=new Map(),storage={getItem:k=>storageData.get(k),setItem:(k,v)=>storageData.set(k,v)};
  const root={innerHTML:'',controls:new Map(),querySelectorAll(){return []},querySelector(selector){if(!this.controls.has(selector))this.controls.set(selector,{});return this.controls.get(selector)}};
  const context=vm.createContext({WaarWorkshopModel:M,structuredClone,console,setTimeout:clock.setTimeout,clearTimeout:clock.clearTimeout});
  vm.runInContext(fs.readFileSync('public/workshop/bench.js','utf8'),context);
  let profile=JSON.parse(fs.readFileSync('resources/workshop-default-profile.json','utf8')),condition='neutral';
  const cache=new Map(),calls=[],measureCalls=[];let pendingCompare=null,deferComparison=false;
  const cached=(p,w)=>cache.get(M.measurementKey(p,w));
  const measure=async(p,w)=>{measureCalls.push({profile:structuredClone(p),weather:w});const value={id:M.measurementKey(p,w),mechanisms:{}};cache.set(M.measurementKey(p,w),value);return value};
  const rateRow={initialCount:100,winRate:.5,drawRate:0,rawLossRatio:.1,rawWoundedRatio:.1,appliedLossRatio:.05,woundedRatio:.05,captureRatio:0};
  const comparison=()=>({summary:'Résultat de la case épinglée',sides:Object.fromEntries(['attacker','defender'].map(side=>[side,{before:rateRow,after:rateRow,deltas:Object.fromEntries(Object.keys(rateRow).map(k=>[k,0]))}])),mechanisms:{before:null,after:null}});
  const api=async(path,body)=>{
    calls.push({path,body:structuredClone(body)});
    if(path==='profile-feedback'){
      if(profile.units.soldier.attack==='invalid')throw Object.assign(new Error('Invalid'),{status:422});
      const changes=body.before.units.soldier.attack===body.after.units.soldier.attack?[]:[{path:'units.soldier.attack',label:'Attaque des soldats',before:body.before.units.soldier.attack,after:body.after.units.soldier.attack,explanation:'Explication du geste'}];
      return {changes,interactions:[]};
    }
    if(path==='compare-monotypes'){
      if(deferComparison)return new Promise(resolve=>pendingCompare=resolve);
      const result=comparison();
      result.settingsChanges=body.beforeProfile.units.soldier.attack===body.afterProfile.units.soldier.attack?[]:[{explanation:'Attaque des soldats : '+body.beforeProfile.units.soldier.attack+' → '+body.afterProfile.units.soldier.attack}];
      result.mechanicalChanges=['Description mécanique renvoyée par PHP'];return result;
    }
    throw Error(path);
  };
  const options={root,api,getProfile:()=>profile,getWeather:()=>condition,weatherLabels:{neutral:'Beau temps',rain:'Pluie'},measure,cached,runManual:fn=>fn(),onSelect(){},storage};
  const bench=context.WaarBench.create(options);bench.restore();await measure(profile,condition);bench.select('spearman-vs-knight');bench.pin();await clock.tick(0);
  const saved=()=>JSON.parse(storageData.get('waar-workshop-bench-v2'));
  const witness=structuredClone(saved().reference.profile);
  assert.equal(saved().reference.scenarioId,'spearman-vs-knight');assert.match(root.innerHTML,/confrontation épinglée/);
  bench.pin();await clock.tick(0);assert.match(root.innerHTML,/Résultat de la case épinglée/,'repinning the same state must restore its comparison');
  bench.reset();await bench.updated();assert.match(root.innerHTML,/Résultat de la case épinglée/,'loading a profile must not strand the pinned comparison');
  // One continuous pointer gesture can exceed the debounce period without adding entries.
  bench.gesture(true);profile.units.soldier.attack='10';bench.changed();await clock.tick(1000);
  profile.units.soldier.attack='11';bench.changed();await clock.tick(1000);
  assert.equal(calls.filter(c=>c.path==='profile-feedback').length,0);
  bench.gesture(false);await clock.tick(0);assert.equal(saved().history.length,1);
  assert.equal(saved().history[0].before.units.soldier.attack,witness.units.soldier.attack);
  assert.equal(saved().history[0].after.units.soldier.attack,'11');assert.deepEqual(saved().reference.profile,witness);
  // Invalid text is never committed. Returning to the anchor cancels pending input.
  profile.units.soldier.attack='invalid';bench.changed();await clock.tick(500);assert.equal(saved().history.length,1);
  profile.units.soldier.attack='11';bench.changed();await clock.tick(500);assert.equal(saved().history.length,1);
  profile.units.soldier.attack='12';bench.changed();await clock.tick(200);profile.units.soldier.attack='11';bench.changed();await clock.tick(500);assert.equal(saved().history.length,1);
  for(let i=12;i<=34;i++){profile.units.soldier.attack=String(i);bench.changed();await clock.tick(500)}
  assert.equal(saved().history.length,20);assert.equal(saved().limited,true);assert.deepEqual(saved().reference.profile,witness);
  // A comparison requested for an old revision cannot overwrite newer edits.
  await measure(profile,condition);deferComparison=true;bench.updated();await clock.tick(0);
  assert.ok(pendingCompare);const requested=calls.filter(c=>c.path==='compare-monotypes').at(-1).body;
  assert.equal(requested.scenarioId,'spearman-vs-knight');assert.deepEqual(requested.beforeProfile,witness);
  for(const value of ['35','36','37']){profile.units.soldier.attack=value;bench.changed()}
  pendingCompare(comparison());await clock.tick(0);assert.doesNotMatch(root.innerHTML,/Résultat de la case épinglée/);
  deferComparison=false;await clock.tick(500);await measure(profile,condition);await bench.updated();assert.match(root.innerHTML,/Résultat de la case épinglée/);
  assert.match(root.innerHTML,/1 réglage modifié depuis la référence/);
  assert.match(root.innerHTML,/Attaque des soldats : 9 → 37/);
  assert.match(root.innerHTML,/Description mécanique renvoyée par PHP/);
  assert.ok(root.innerHTML.indexOf('Attaque des soldats : 9 → 37')<root.innerHTML.indexOf('Résultat de la case épinglée'),'the acknowledged settings must be visible before the outcome');
  // Merely inspecting another matrix cell does not move the pinned experiment.
  bench.select('archer-vs-archer');assert.equal(saved().reference.scenarioId,'spearman-vs-knight');
  condition='rain';bench.changed();await clock.tick(500);assert.match(root.innerHTML,/Météo modifiée/);assert.doesNotMatch(root.innerHTML,/Résultat de la case épinglée/);
  const count=measureCalls.length;await bench.remesure();assert.equal(measureCalls.length,count+2);assert.deepEqual(measureCalls.at(-2).profile,witness);assert.equal(measureCalls.at(-2).weather,'rain');assert.deepEqual(saved().reference.profile,witness);
  // Reload retains the witness, but no result produced by an unknown previous runtime build.
  storageData.set('waar-workshop-feedback-v1',JSON.stringify({summary:'stale archers story'}));
  const reloaded=context.WaarBench.create(options);reloaded.restore();assert.match(root.innerHTML,/renouveler après le rechargement/);assert.doesNotMatch(root.innerHTML,/Résultat de la case épinglée|stale archers story/);
  assert.deepEqual(saved().reference.profile,witness);
  console.log('workshop-bench: ok (fixed witness, gestures, invalid input, bounded history, stale comparisons, weather, reload)');
})().catch(e=>{console.error(e);process.exitCode=1});
