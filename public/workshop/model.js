(() => {
'use strict';
const canonical=value=>Array.isArray(value)?value.map(canonical):value&&typeof value==='object'?Object.fromEntries(Object.keys(value).sort().map(key=>[key,canonical(value[key])])):value;
const stable=value=>JSON.stringify(canonical(value));
function cost(army,costs){return Object.entries(army).reduce((sum,[type,count])=>sum+count*costs[type],0)}
function responseIsCurrent(response,requestId,configurationSignature,currentSignature){return String(response.requestId)===String(requestId)&&configurationSignature===currentSignature}
function stale(resultSignature,currentSignature){return Boolean(resultSignature&&resultSignature!==currentSignature)}
function createRevisionGate(){let revision=0;return {invalidate(){revision++},capture(signature){return {revision,signature}},accept(token,signature){return token.revision===revision&&token.signature===signature}}}
function prefillUnits(profile,defaults){return {...structuredClone(profile),units:structuredClone(defaults.units)}}
function plotRows(reference,candidate){return reference.rows.map(row=>({id:row.id,reference:{x:row.winRate,y:row.rawCasualtyRatio},candidate:candidate?.rows.find(other=>other.id===row.id)})).map(row=>({...row,candidate:row.candidate?{x:row.candidate.winRate,y:row.candidate.rawCasualtyRatio}:null}))}
function boundedAppend(items,item,limit=20){return [...items,item].slice(-limit)}
function measurementKey(profile,weather,iterations=50,seed=42){return stable({profile,weather,iterations,seed})}
// A rejected request must release the lane too. Queued work is never cancelled by aborting HTTP.
function createComputeLane(){let tail=Promise.resolve();return {run(task){const result=tail.then(task);tail=result.catch(()=>{});return result}}}
function retryDelay(header,now=Date.now()){if(!header)return 10000;const seconds=Number(header);return Number.isFinite(seconds)?Math.max(0,seconds*1000):Math.max(0,Date.parse(header)-now)||10000}
function profileKey(profile){const {id,label,...rules}=profile;return stable(rules)}
globalThis.WaarWorkshopModel={stable,cost,responseIsCurrent,stale,createRevisionGate,prefillUnits,plotRows,boundedAppend,measurementKey,createComputeLane,retryDelay,profileKey};
})();
