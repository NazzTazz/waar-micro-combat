(() => {
'use strict';
const canonical=value=>Array.isArray(value)?value.map(canonical):value&&typeof value==='object'?Object.fromEntries(Object.keys(value).sort().map(key=>[key,canonical(value[key])])):value;
const stable=value=>JSON.stringify(canonical(value));
function cost(army,costs){return Object.entries(army).reduce((sum,[type,count])=>sum+count*costs[type],0)}
function responseIsCurrent(response,requestId,configurationSignature,currentSignature){return String(response.requestId)===String(requestId)&&configurationSignature===currentSignature}
function stale(resultSignature,currentSignature){return Boolean(resultSignature&&resultSignature!==currentSignature)}
function createRevisionGate(){let revision=0;return {invalidate(){revision++},capture(signature){return {revision,signature}},accept(token,signature){return token.revision===revision&&token.signature===signature}}}
function prefillUnits(profile,defaults){return {...structuredClone(profile),units:structuredClone(defaults.units)}}
function plotRows(reference,candidate){return reference.rows.map(row=>({id:row.id,reference:{x:row.winRate,y:row.appliedLossRatio},candidate:candidate?.rows.find(other=>other.id===row.id)})).map(row=>({...row,candidate:row.candidate?{x:row.candidate.winRate,y:row.candidate.appliedLossRatio}:null}))}
globalThis.WaarWorkshopModel={stable,cost,responseIsCurrent,stale,createRevisionGate,prefillUnits,plotRows};
})();
