'use strict';

// Exercise the actual application handlers with a minimal DOM and deferred HTTP.
// No combat or objective evaluation is implemented in this fixture.
const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');
const path=require('node:path');
const clock=require('./helpers/fake-clock')();

class Element {
  constructor(){this.value='';this.dataset={};this.style={};this.children=[];this.textContent='';this.listeners={};this.width=900;this.height=440;this.classList={add(){},remove(){},toggle(){}}}
  set innerHTML(value){this.html=value;this.children=value?[new Element(),new Element(),new Element()]:[]}
  get innerHTML(){return this.html||''}
  set textContent(value){this.text=value;this.html='';this.children=[]}
  get textContent(){return this.text||''}
  append(...children){this.children.push(...children)}
  before(){}
  setAttribute(name,value){this[name]=value}
  removeAttribute(name){delete this[name]}
  replaceChildren(...children){this.children=children;this.html=''}
  add(option){this.children.push(option);if(this.children.length===1)this.value=option.value}
  addEventListener(name,handler){this.listeners[name]=handler}
  querySelector(){return new Element()}
  querySelectorAll(){return []}
  scrollIntoView(){}
  focus(){}
  showModal(){}
  click(){}
  setPointerCapture(){}
  getBoundingClientRect(){return {left:0,top:0,width:900,height:440}}
  getContext(){return new Proxy({}, {get:(_,key)=>typeof key==='string'?()=>{}:undefined})}
}

(async()=>{
  const elements=new Map();
  const element=selector=>{if(!elements.has(selector))elements.set(selector,new Element());return elements.get(selector)};
  const units=Object.fromEntries(['soldier','spearman','archer','knight'].map(type=>[type,{attack:'7',structure:'18',baseAccuracy:'0.15',accuracySpread:'0.02',strikesPerAttack:1,defendingEfficiency:'1',cost:80,capturable:false}]));
  const weatherIds=['neutral','cloudy','snow','blizzard','heat','canicule','wind','storm','rain','thunderstorm'];
  const profile={schemaVersion:'waar-engine-profile/0.2',id:'test',label:'Keep me',units,relations:[],weather:Object.fromEntries(weatherIds.map(w=>[w,Object.fromEntries(Object.keys(units).map(t=>[t,{attack:'1',baseAccuracy:'1'}]))])),combat:{maxRounds:3,surrenderEnabled:false,surrenderDeadPercent:20,tieBreakCriterion:'economic',equalityPolicy:'defender',lossCompressionPercent:8,capturePercent:0,woundDamageThreshold:'0.2'}};
  const measurement={profileFingerprint:'fp',modelVersion:'waar-cohort-v2',context:{weather:'neutral',baseSeed:42,iterations:100,budget:400400,objectiveMetric:'rawCasualtyRatio',modelVersion:'waar-cohort-v2',rulesetVersion:'test',runtime:{kind:'rust',transport:'process-jsonl',modelVersion:'waar-cohort-v2'},consequences:{lossCompressionPercent:8,capturePercent:0}},rows:[{id:'soldier-vs-soldier/attacker',scenarioId:'soldier-vs-soldier',side:'attacker',winRate:.51,rawCasualtyRatio:.06}]};
  const overviewMeasurement={...structuredClone(measurement),context:{...structuredClone(measurement.context),iterations:50},rows:[]};
  for(const attackerType of Object.keys(units))for(const defenderType of Object.keys(units))for(const side of ['attacker','defender'])overviewMeasurement.rows.push({id:`${attackerType}-vs-${defenderType}/${side}`,scenarioId:`${attackerType}-vs-${defenderType}`,attackerType,defenderType,side,initialCount:5005,winRate:side==='attacker'?.6:.3,drawRate:.1,rawLossRatio:.1,rawWoundedRatio:.05,rawCasualtyRatio:.15,appliedLossRatio:.08,woundedRatio:.04,captureRatio:.01});
  let pendingSearch,searchBody,confirmResult=true,malformedResponse=false,overviewCalls=0;
  let holdMeasurement=false,releaseMeasurement=null,busyMeasurement=false,invalidProfile=false;
  class ClockDate extends Date {static now(){return 1700000000000+clock.now()}}
  const storage=new Map(),messages=[],listeners={};
  element('#t27-editor').contentWindow={postMessage:message=>messages.push(message)};
  const origin='http://localhost';
  const editZones=x=>listeners.message({source:element('#t27-editor').contentWindow,origin,data:{type:'waar-t27-zones',fingerprint:'editor-fp',document:{schemaVersion:'waar-consequence-editor-zones/0.1',corpusFingerprint:'editor-fp',zones:[{id:measurement.rows[0].id,center:{x,y:.06},radii:{x:.05,y:.1},enabled:true,source:{referenceId:'fp'}}]}}});
  const documentListeners={};
  const document={querySelector:element,createElement:()=>new Element(),addEventListener(name,fn){(documentListeners[name]??=[]).push(fn)},querySelectorAll(selector){
    if(selector==='[data-combat]')return ['maxRounds','surrenderEnabled','surrenderDeadPercent','woundDamageThreshold','tieBreakCriterion','equalityPolicy','lossCompressionPercent','capturePercent'].map(key=>{const input=element('[data-combat='+key+']');input.dataset.combat=key;input.type=key==='surrenderEnabled'?'checkbox':key.includes('Policy')||key.includes('Criterion')?'select':'range';return input});
    if(selector==='[data-pick]'||selector==='[data-compare]'){
      const attribute=selector.slice(1,-1),key=attribute==='data-pick'?'pick':'compare';
      return [...element('#search-results').innerHTML.matchAll(new RegExp(attribute+'="(\\d+)"','g'))].map(match=>{const button=element(selector+match[1]);button.dataset[key]=match[1];return button});
    }
    if(selector==='.journey button')return [Object.assign(new Element(),{dataset:{step:'units'}})];
    return [];
  }};
  const context=vm.createContext({window:{addEventListener:(type,handler)=>listeners[type]=handler},location:{origin},document,structuredClone,console,Date:ClockDate,confirm:()=>confirmResult,clearTimeout:clock.clearTimeout,setTimeout:clock.setTimeout,Option:class{constructor(text,value){this.text=text;this.value=value}},localStorage:{getItem:k=>storage.get(k)||null,setItem:(k,v)=>storage.set(k,v)},fetch:async(url,options)=>{
    if(malformedResponse)return {ok:false,status:500,json:async()=>{throw new SyntaxError('Unexpected token <')}};
    const body=options?.body?JSON.parse(options.body):null;
    let data;
    if(url.endsWith('default-profile'))data={profile:structuredClone(profile)};
    else if(url.endsWith('migrate-profile'))data={profile:structuredClone(body.profile),migration:{performed:false}};
    else if(url.endsWith('/editor'))data={html:'T27 fixture',fingerprint:'editor-fp'};
    else if(url.endsWith('/validate'))data={errors:invalidProfile?[{message:'Invalid'}]:[]};
    else if(url.endsWith('/profile-feedback'))data={changes:[],interactions:[]};
    else if(url.endsWith('/duel-summary'))data={requestId:body.requestId,rows:[]};
    else if(url.endsWith('/measure')){
      if(body.iterations===50){overviewCalls++;
        if(busyMeasurement){busyMeasurement=false;return {ok:false,status:429,headers:{get:()=> '3'},json:async()=>({errors:[{message:'Occupé'}]})}}
        if(holdMeasurement){holdMeasurement=false;await new Promise(resolve=>releaseMeasurement=resolve)}
        data=structuredClone(overviewMeasurement);
      }else data=structuredClone(measurement);
    }
    else if(url.endsWith('/optimize')){searchBody=body;data=await new Promise(resolve=>{pendingSearch=resolve})}
    else data={errors:[]};
    return {ok:true,json:async()=>({data})};
  }});
  for(const file of ['model.js','bench.js','app.js']){
    let source=fs.readFileSync(path.join(__dirname,'../public/workshop',file),'utf8');
    if(file==='app.js')source=source.replace('function renderDuel(result){','globalThis.reportViews={orderedSides,stateTable,roundAttacks,detailedCombat,consequenceSummary,consequenceTable};\nfunction renderDuel(result){');
    vm.runInContext(source,context);
  }
  await new Promise(resolve=>setImmediate(resolve));
  assert.equal(element('#notice').textContent,'','application initializes without errors');
  element('#show-overview').onclick();await clock.tick(0);
  assert.equal(overviewCalls,1,'overview uses one 50-iteration monotype batch');
  assert.match(element('#live-duel-results').children[1].innerHTML,/Attaque ↓ \/ Défense →/);
  assert.equal((element('#live-duel-results').children[1].innerHTML.match(/data-overview=/g)||[]).length,16,'overview renders a 4 × 4 matrix');
  assert.match(element('#live-duel-results').children[2].innerHTML,/Brut : morts/);
  const autoRounds=element('[data-combat=maxRounds]');
  holdMeasurement=true;autoRounds.value='4';autoRounds.oninput();await clock.tick(500);
  assert.equal(overviewCalls,2);assert.ok(releaseMeasurement);
  autoRounds.value='5';autoRounds.oninput();autoRounds.value='4';autoRounds.oninput();
  releaseMeasurement();await clock.tick(0);
  assert.match(element('#live-duel-status').textContent,/précédent|actualisation/,'editing and returning cannot publish an old revision');
  await clock.tick(500);assert.equal(overviewCalls,3,'discarded old response is not revived through the cache');
  holdMeasurement=true;autoRounds.value='6';autoRounds.oninput();await clock.tick(500);
  const beforeToggle=overviewCalls;element('#show-live-duel').onclick();element('#show-overview').onclick();await clock.tick(0);
  assert.equal(overviewCalls,beforeToggle,'view toggle does not overlap a pending calculation');
  releaseMeasurement();await clock.tick(500);assert.equal(overviewCalls,beforeToggle+1);
  invalidProfile=true;autoRounds.value='7';autoRounds.oninput();await clock.tick(500);
  assert.equal(overviewCalls,beforeToggle+1,'invalid profile never reaches the simulation endpoint');
  invalidProfile=false;busyMeasurement=true;autoRounds.value='8';autoRounds.oninput();await clock.tick(500);
  const beforeRetry=overviewCalls;autoRounds.value='9';autoRounds.oninput();await clock.tick(2999);
  assert.equal(overviewCalls,beforeRetry,'editing cannot bypass Retry-After');await clock.tick(1);assert.equal(overviewCalls,beforeRetry+1);
  autoRounds.value='10';autoRounds.oninput();document.hidden=true;
  for(const fn of documentListeners.visibilitychange)fn();await clock.tick(1000);
  assert.equal(overviewCalls,beforeRetry+1,'hidden document pauses automatic work');document.hidden=false;
  for(const fn of documentListeners.visibilitychange)fn();await clock.tick(0);
  autoRounds.value='3';autoRounds.oninput();await clock.tick(500);
  assert.equal(typeof element('#measure').onclick,'function');
  assert.equal(element('#profile-modified').hidden,true,'new profile starts unmodified');
  const armyRow=element('#army-a').children[0];
  assert.match(armyRow.innerHTML,/max="100000"/);
  armyRow.children[2].value='25000';armyRow.children[2].oninput();
  assert.equal(Number(armyRow.children[1].value),25000);
  armyRow.children[2].value='100001';armyRow.children[2].oninput();
  assert.equal(Number(armyRow.children[2].value),100001);
  assert.match(armyRow.innerHTML,/max="1000000"/);
  armyRow.children[2].value='1000001';armyRow.children[2].oninput();
  assert.equal(Number(armyRow.children[2].value),1000000,'numeric counts stop at the one-million-per-type limit');
  const mixedRow=element('#army-a').children[1];
  mixedRow.children[2].value='20000';mixedRow.children[2].oninput();
  assert.equal(Number(mixedRow.children[2].value),20000,'numeric counts are independent of slider and camp totals');
  const beforeB=JSON.parse(storage.get('waar-workshop-draft-v1')).armies.B;
  element('#composition-a').value='sl';element('#budget-a').value='250000';element('#apply-army-a').onclick();
  const filled=JSON.parse(storage.get('waar-workshop-draft-v1')).armies;
  assert.deepEqual(filled.A,{soldier:2500,spearman:625,archer:0,knight:0});
  assert.deepEqual(filled.B,beforeB,'shortcut only replaces the chosen camp');
  element('#weather-tabs').children[3].children[1].onclick();
  assert.equal(JSON.parse(storage.get('waar-workshop-draft-v1')).duelWeather,'rain');
  const rounds=element('[data-combat=maxRounds]');
  assert.equal(rounds['aria-label'],'Rounds');
  rounds.value='5';rounds.oninput();
  assert.equal(element('#rounds-out').textContent,5);
  assert.equal(element('#profile-modified').hidden,false,'engine changes mark the profile modified');
  assert.equal(JSON.parse(storage.get('waar-workshop-draft-v1')).profile.combat.maxRounds,5);
  const woundThreshold=element('[data-combat=woundDamageThreshold]');
  assert.equal(woundThreshold.value,20);
  woundThreshold.value='0';woundThreshold.oninput();
  assert.equal(JSON.parse(storage.get('waar-workshop-draft-v1')).profile.combat.woundDamageThreshold,'0','an explicit zero threshold is preserved');
  woundThreshold.value='20';woundThreshold.oninput();
  assert.equal(JSON.parse(storage.get('waar-workshop-draft-v1')).profile.combat.woundDamageThreshold,'0.2');
  await element('#measure').onclick();
  woundThreshold.value='0';woundThreshold.oninput();
  assert.match(element('#measure-progress').textContent,/obsolète/,'editing the threshold invalidates the current measurement');
  woundThreshold.value='20';woundThreshold.oninput();
  await new Promise(resolve=>setImmediate(resolve));await new Promise(resolve=>setImmediate(resolve));
  await element('#measure').onclick();
  assert.equal(element('#t27-editor').srcdoc,'T27 fixture');
  editZones(.8);
  assert.equal(measurement.rows[0].winRate,.51,'editing T27 does not alter observations');
  const searchResult=()=>({referenceProfile:structuredClone(profile),evaluated:1,candidateBudget:32,stopReason:'objectives_satisfied',generations:[{number:1,improved:true,best:{metrics:{inside:32,score:0}}}],candidates:[{rank:1,generation:1,operator:'reference',fingerprint:'candidate',inside:32,score:0,worst:{id:'a'},profile:structuredClone(profile),observations:{rows:[{id:measurement.rows[0].id,winRate:.8,rawCasualtyRatio:.04}]}}]});

  const run=element('#search').onclick();
  await new Promise(resolve=>setImmediate(resolve));
  assert.equal(searchBody.measurementBaseSeed,42);
  assert.equal(searchBody.seed,314159);
  assert.equal(searchBody.budget,32);
  pendingSearch(searchResult());await run;
  element('[data-compare]1').onclick();
  assert.equal(messages.at(-1).rows[0].winRate,.8,'candidate observations sent to T27');
  editZones(.2);
  assert.equal(document.querySelectorAll('[data-pick]').length,0,'edited zones remove candidate adoption');

  const late=element('#search').onclick();
  await new Promise(resolve=>setImmediate(resolve));
  element('#search-bounds').listeners.input();
  pendingSearch(searchResult());await late;
  assert.match(element('#search-results').textContent,/ignoré/,'late result after bounds edit is rejected');
  assert.equal(document.querySelectorAll('[data-pick]').length,0);

  const adopt=element('#search').onclick();await new Promise(resolve=>setImmediate(resolve));pendingSearch(searchResult());await adopt;
  element('[data-pick]1').onclick();
  assert.equal(element('#search-archive').hidden,false,'reference and report remain available after adoption');
  assert.equal(typeof element('#export-search').onclick,'function');

  // Prefill cancellation and confirmation preserve non-unit settings.
  confirmResult=false;await element('#prefill').onclick();
  confirmResult=true;await element('#prefill').onclick();
  assert.equal(JSON.parse(storage.get('waar-workshop-draft-v1')).profile.label,'Keep me');
  const unitTypes=Object.keys(units);
  const emptyCell=()=>({sourceCount:0,strikesPerAttack:1,allocatedAttempts:0,consumedAttempts:0,reallocatedAttempts:0,sampledHits:0,appliedHits:0,attackPerStrike:'7',accuracy:'0.15',attackFactor:'1',defendingEfficiency:'1',damagePerHit:'7',damageEmitted:'0',damageAbsorbed:'0',overkill:'0'});
  const createMatrix=()=>Object.fromEntries(unitTypes.map(source=>[source,Object.fromEntries(unitTypes.map(target=>[target,emptyCell()]))]));
  const defenderMatrix=createMatrix();for(const target of unitTypes)defenderMatrix.soldier[target].sourceCount=10;defenderMatrix.soldier.archer={...emptyCell(),sourceCount:10,allocatedAttempts:10,consumedAttempts:10,sampledHits:10,appliedHits:10,attackPerStrike:'7',accuracy:'1',attackFactor:'2',defendingEfficiency:'1.5',damagePerHit:'21',damageEmitted:'210',damageAbsorbed:'190',overkill:'20'};
  const attackerMatrix=createMatrix();attackerMatrix.archer.soldier={...emptyCell(),sourceCount:5,allocatedAttempts:5,sampledHits:1,appliedHits:1};
  const round={number:1,attackerAction:{attempts:5,hits:1,deaths:0,matrix:attackerMatrix},defenderAction:{attempts:10,hits:10,deaths:2,matrix:defenderMatrix},attackerDeathRatio:'0',defenderDeathRatio:'0.2'};
  const prepared=unitTypes.map(type=>({type,attack:'7',structure:'18',cost:80,baseAccuracy:'0.15',accuracySpread:'0.02',strikesPerAttack:1,defendingEfficiency:'1',capturable:false,base:{type,attack:'7',structure:'18',cost:80,baseAccuracy:'0.15',accuracySpread:'0.02',strikesPerAttack:1,defendingEfficiency:'1',capturable:false},effects:[]}));
  const armies={attacker:{soldier:0,spearman:0,archer:5,knight:0},defender:{soldier:10,spearman:0,archer:0,knight:0}};
  const final={attacker:{healthy:{soldier:0,spearman:0,archer:5,knight:0},wounded:{soldier:0,spearman:0,archer:0,knight:0},dead:{soldier:0,spearman:0,archer:0,knight:0}},defender:{healthy:{soldier:7,spearman:0,archer:0,knight:0},wounded:{soldier:1,spearman:0,archer:0,knight:0},dead:{soldier:2,spearman:0,archer:0,knight:0}}};
  const consequenceSide=(initial,economicLoss,percent)=>({economicLoss,economicLossPercent:String(percent),types:Object.fromEntries(unitTypes.map(type=>[type,{initial:initial[type],raw:{healthy:initial[type],wounded:0,dead:0},projected:{healthy:initial[type],wounded:0,dead:0,prisoners:0,freeSurvivors:initial[type]}}]))});
  const direction={labels:{attacker:'B',defender:'A'},result:{initialArmies:armies,snapshot:{prepared:{attacker:{units:prepared},defender:{units:prepared}}},rounds:[round],...final},consequences:{attacker:consequenceSide(armies.attacker,480,6),defender:consequenceSide(armies.defender,100,3.333333)}};
  assert.deepEqual(Array.from(context.reportViews.orderedSides(direction)),['defender','attacker']);
  assert.match(context.reportViews.consequenceSummary(direction,'attacker',{A:10000,B:8000}),/480 \(6 %\)/,'percentage comes from the engine consequence report');
  assert.match(context.reportViews.consequenceSummary(direction,'defender',{A:3000,B:8000}),/100 \(3,333333 %\)/);
  const details=context.reportViews.detailedCombat(direction);
  assert.ok(details.indexOf('Camp A')<details.indexOf('Camp B'),'A remains on the left when B attacks');
  assert.match(details,/Attaque/);assert.match(details,/Structure/);assert.match(details,/Précision/);
  assert.match(details,/Soldat A vers Archer B : 10 touches sur 10 tentatives/);
  assert.match(details,/210 émis/,'damage is displayed from the native trace');
  assert.match(details,/Coefficient défensif ×1,5/);
  assert.match(details,/Contre ×2/);
  assert.match(details,/Attaque par frappe : 7/);
  assert.match(details,/10 combattants × 1 frappes/);
  assert.match(details,/scope="col">Archer/);assert.match(details,/scope="row">Soldat/);
  assert.match(details,/tabindex="0"/);assert.match(details,/role="tooltip"/);
  const matrix=context.reportViews.roundAttacks(direction,round,'defender').match(/<table class="impact-matrix">(.*?)<\/table>/s)[1];
  assert.equal((matrix.match(/scope="col"/g)||[]).length,5,'source heading plus all four target types');
  assert.equal((matrix.match(/scope="row"/g)||[]).length,4,'all four source types, including empty cohorts');
  assert.equal((matrix.match(/<td[ >]/g)||[]).length,16,'stable 4 × 4 matrix');
  for(const name of ['Soldat','Lancier','Archer','Chevalier']){assert.ok(matrix.includes('scope="row">'+name));assert.ok(matrix.includes('scope="col">'+name));}
  assert.match(matrix,/Cohorte source vide/);assert.match(matrix,/Aucune tentative allouée/);
  assert.doesNotMatch(matrix,/NaN|Infinity/);
  assert.match(details,/Round 1/);assert.doesNotMatch(details,/<details[^>]* open/);
  assert.match(details,/Valides \(≤ seuil\)/,'lightly damaged survivors are not presented as necessarily intact');
  const projectedSummary=context.reportViews.consequenceSummary(direction,'attacker',{A:10000,B:8000});
  assert.match(projectedSummary,/Valides en sortie/);
  assert.doesNotMatch(projectedSummary,/≤ seuil/,'projected survivors do not imply physical healing');
  const projectedTable=context.reportViews.consequenceTable(direction,'attacker','B');
  assert.equal((projectedTable.match(/Valides \(≤ seuil\)/g)||[]).length,1,'threshold qualification applies only to raw classification');
  assert.match(projectedTable,/Valides en sortie/);
  document.hidden=true;await new Promise(resolve=>setImmediate(resolve));await new Promise(resolve=>setImmediate(resolve));
  malformedResponse=true;
  await element('#measure').onclick();
  assert.match(element('#measure-progress').textContent,/Réponse serveur invalide \(HTTP 500\)/);
  assert.doesNotMatch(element('#measure-progress').textContent,/Unexpected token/);
  console.log('workshop-ui: ok (initialization, independent observations, vectors, stale responses, protected prefill)');
})().catch(error=>{console.error(error);process.exitCode=1});
