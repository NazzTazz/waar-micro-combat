(() => {
'use strict';
const stable=value=>JSON.stringify(value,Object.keys(value||{}).sort());
function cost(army,costs){return Object.entries(army).reduce((sum,[type,count])=>sum+count*costs[type],0)}
function responseIsCurrent(response,requestId,configurationSignature,currentSignature){return String(response.requestId)===String(requestId)&&configurationSignature===currentSignature}
function stale(resultSignature,currentSignature){return Boolean(resultSignature&&resultSignature!==currentSignature)}
globalThis.WaarWorkshopModel={stable,cost,responseIsCurrent,stale};
})();
