'use strict';

// Exercise the actual application handlers with a minimal DOM and deferred HTTP.
// No combat or objective evaluation is implemented in this fixture.
const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');
const path=require('node:path');

class Element {
  constructor(){this.value='';this.dataset={};this.style={};this.children=[];this.textContent='';this.listeners={};this.width=900;this.height=440;this.classList={add(){},remove(){},toggle(){}}}
  set innerHTML(value){this.html=value;this.children=value?[new Element(),new Element(),new Element()]:[]}
  get innerHTML(){return this.html||''}
  set textContent(value){this.text=value;this.html='';this.children=[]}
  get textContent(){return this.text||''}
  append(...children){this.children.push(...children)}
  before(){}
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
  const units=Object.fromEntries(['soldier','spearman','archer','knight'].map(type=>[type,{attack:'7',structure:'18',defendingEfficiency:'1',cost:80,capturable:false}]));
  const profile={schemaVersion:'waar-engine-profile/0.1',id:'test',label:'Keep me',units,relations:[],weather:Object.fromEntries(['neutral','rain','snow','heat'].map(w=>[w,Object.fromEntries(Object.keys(units).map(t=>[t,'1']))])),combat:{maxRounds:3,lossCompressionPercent:8,capturePercent:0}};
  const measurement={profileFingerprint:'fp',modelVersion:'model',context:{baseSeed:42,iterations:100},rows:[{id:'soldier-vs-soldier/attacker',scenarioId:'soldier-vs-soldier',side:'attacker',winRate:.51,appliedLossRatio:.06}]};
  let pendingSearch,searchBody,confirmResult=true;
  const storage=new Map();
  const document={querySelector:element,createElement:()=>new Element(),addEventListener(){},querySelectorAll(selector){
    if(selector==='[data-pick]'||selector==='[data-compare]'){
      const attribute=selector.slice(1,-1),key=attribute==='data-pick'?'pick':'compare';
      return [...element('#search-results').innerHTML.matchAll(new RegExp(attribute+'="(\\d+)"','g'))].map(match=>{const button=element(selector+match[1]);button.dataset[key]=match[1];return button});
    }
    if(selector==='.journey button')return [Object.assign(new Element(),{dataset:{step:'units'}})];
    return [];
  }};
  const context=vm.createContext({document,structuredClone,console,confirm:()=>confirmResult,setTimeout:fn=>fn(),Option:class{constructor(text,value){this.text=text;this.value=value}},localStorage:{getItem:k=>storage.get(k)||null,setItem:(k,v)=>storage.set(k,v)},fetch:async(url,options)=>{
    const body=options?.body?JSON.parse(options.body):null;
    let data;
    if(url.endsWith('default-profile'))data={profile:structuredClone(profile)};
    else if(url.endsWith('/measure'))data=structuredClone(measurement);
    else if(url.endsWith('/search')){searchBody=body;data=await new Promise(resolve=>{pendingSearch=resolve})}
    else data={errors:[]};
    return {ok:true,json:async()=>({data})};
  }});
  for(const file of ['model.js','app.js'])vm.runInContext(fs.readFileSync(path.join(__dirname,'../public/workshop',file),'utf8'),context);
  await new Promise(resolve=>setImmediate(resolve));
  assert.equal(element('#notice').textContent,'','application initializes without errors');
  assert.equal(typeof element('#measure').onclick,'function');
  await element('#measure').onclick();
  assert.match(element('#observation-values').textContent,/51\.000/);
  element('#zone-x').value='80';element('#zone-x').onchange({target:element('#zone-x')});
  assert.match(element('#observation-values').textContent,/51\.000/,'zone edits never move measured reference');
  const chart=element('#zone-chart');
  chart.onpointerdown({button:0,pointerId:1,clientX:250,clientY:250});
  chart.onpointermove({clientX:450,clientY:350});
  chart.onpointerup();
  assert.match(element('#observation-values').textContent,/51\.000/,'drawing changes only the desired zone');
  const searchResult=()=>({referenceProfile:structuredClone(profile),candidates:[{rank:1,fingerprint:'candidate',inside:32,score:0,worst:{id:'a'},profile:structuredClone(profile),observations:{rows:[{id:measurement.rows[0].id,winRate:.8,appliedLossRatio:.04}]}}]});

  const run=element('#search').onclick();
  assert.equal(searchBody.measurementBaseSeed,42);
  assert.equal(searchBody.seed,314159);
  pendingSearch(searchResult());await run;
  element('[data-compare]1').onclick();
  assert.match(element('#observation-values').textContent,/80\.000/,'explicit comparison displays candidate');
  element('#zone-x').value='20';element('#zone-x').onchange({target:element('#zone-x')});
  assert.equal(document.querySelectorAll('[data-pick]').length,0,'edited zones remove candidate adoption');

  const late=element('#search').onclick();
  element('#search-bounds').listeners.input();
  pendingSearch(searchResult());await late;
  assert.match(element('#search-results').textContent,/ignoré/,'late result after bounds edit is rejected');
  assert.equal(document.querySelectorAll('[data-pick]').length,0);

  const adopt=element('#search').onclick();pendingSearch(searchResult());await adopt;
  element('[data-pick]1').onclick();
  assert.equal(element('#search-archive').hidden,false,'reference and report remain available after adoption');
  assert.equal(typeof element('#export-search').onclick,'function');

  // Prefill cancellation and confirmation preserve non-unit settings.
  element('#profile-label').value='Retain this name';element('#profile-label').onchange();
  confirmResult=false;await element('#prefill').onclick();
  assert.equal(element('#profile-label').value,'Retain this name');
  confirmResult=true;await element('#prefill').onclick();
  assert.equal(element('#profile-label').value,'Retain this name');
  assert.equal(JSON.parse(storage.get('waar-workshop-draft-v1')).profile.label,'Retain this name');
  console.log('workshop-ui: ok (initialization, independent observations, vectors, stale responses, protected prefill)');
})().catch(error=>{console.error(error);process.exitCode=1});
