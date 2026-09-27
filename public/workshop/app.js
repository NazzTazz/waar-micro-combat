(() => {
'use strict';
const types={soldier:'Soldat',spearman:'Lancier',archer:'Archer',knight:'Chevalier'}, costs={soldier:10,spearman:70,archer:70,knight:550}, weather={neutral:'Beau temps',cloudy:'Nuageux',snow:'Froid mordant',blizzard:'Blizzard',heat:'Ensoleillé',canicule:'Canicule',wind:'Vents violents',storm:'Tempête',rain:'Pluies diluviennes',thunderstorm:'Orages'};
let profile, profileBaseline=null, armies={A:{soldier:100,spearman:0,archer:0,knight:0},B:{soldier:100,spearman:0,archer:0,knight:0}}, activeWeather='neutral', duelWeather='neutral', armyModifiers={A:[],B:[]}, zones=[], staleZones=[], measurement=null, measurementSignature='', currentRequest=0, resultSignature='';
const searchGate=WaarWorkshopModel.createRevisionGate();
let searchResult=null, searchToken=null, comparison=null, lastSearch=null;
let selectedRelationUnit='soldier', selectedUnit='soldier', editorFingerprint=null, editorLoad=0, originalZones=[];
const $=s=>document.querySelector(s), $$=s=>[...document.querySelectorAll(s)];
async function api(path,body){const response=await fetch('/api/'+path,{method:body?'POST':'GET',headers:body?{'Content-Type':'application/json'}:{},body:body?JSON.stringify(body):undefined});let json;try{json=await response.json()}catch{throw new Error(`Réponse serveur invalide (HTTP ${response.status}). Le calcul a peut-être été interrompu ; consultez le journal PHP.`)}if(!response.ok||json.ok===false)throw Object.assign(new Error(json.errors?.[0]?.message||'Erreur'),{errors:json.errors||[],status:response.status});return json.data}
function duelSignature(){return JSON.stringify([profile,armies,duelWeather,armyModifiers,$('#duel-seed')?.value])}
const liveWaveSize=50,liveFirstResult=250,liveMaximum=10000,liveBackgroundDelay=3000;
let liveTimer=null,liveBusy=false,liveReady=false,liveSequence=0,liveCombats=0,liveSelected=null,liveShownSignature='',liveNextIteration=0;
const liveRows=new Map();
document.addEventListener('visibilitychange',()=>{if(document.hidden){if(liveShownSignature===liveSignature()&&liveNextIteration>=liveFirstResult)clearTimeout(liveTimer)}else if(liveReady&&liveShownSignature===liveSignature()&&liveNextIteration>=liveFirstResult&&liveNextIteration<liveMaximum&&!liveBusy)queueLiveWave(1000)});
function liveSignature(){return JSON.stringify([profile,armies,duelWeather,armyModifiers,$('#duel-seed')?.value])}
function queueLiveWave(delay){clearTimeout(liveTimer);liveTimer=setTimeout(runLiveDuel,delay)}
function scheduleLiveDuel(){if(!liveReady)return;const stale=liveRows.size>0&&liveShownSignature!==liveSignature();$('#live-duel-overlay').hidden=!stale;$('#live-duel').classList.toggle('is-updating',stale);$('#live-duel-confidence').hidden=stale||liveRows.size===0;if(stale||liveRows.size===0)$('#live-duel-status').textContent=stale?'Résultats précédents · actualisation…':'Actualisation…';if(liveShownSignature===liveSignature()&&liveNextIteration>=liveMaximum){clearTimeout(liveTimer);return}queueLiveWave(250)}
function hudNumber(value){return Number(value).toLocaleString('fr-FR',{maximumFractionDigits:1})}
function hudPercent(value,total){return (total?100*value/total:0).toLocaleString('fr-FR',{maximumFractionDigits:1})+' %'}
function hudWilsonBounds(row){const n=row.samples,p=row.attackerWins/n,z=1.96,denominator=1+z*z/n,center=(p+z*z/(2*n))/denominator,half=z*Math.sqrt(p*(1-p)/n+z*z/(4*n*n))/denominator;return [center-half,center+half]}
function hudWinInterval(row){const [lower,upper]=hudWilsonBounds(row),pct=value=>(100*value).toLocaleString('fr-FR',{maximumFractionDigits:1})+' %';return `Victoires ${hudPercent(row.attackerWins,row.samples)} · IC 95 % ${pct(lower)} à ${pct(upper)} · ${row.samples.toLocaleString('fr-FR')} combats`}
function updateLiveConfidence(){const n=Math.min(...[...liveRows.values()].map(row=>row.samples)),margin=100*1.96/(2*Math.sqrt(n+1.96**2));const badge=$('#live-duel-confidence');badge.textContent=`Taux de victoire · IC 95 % : ±${margin.toLocaleString('fr-FR',{maximumFractionDigits:1})} pts max (n ≥ ${n.toLocaleString('fr-FR')})`;badge.hidden=false}
function updateLiveStatus(){$('#live-duel-status').textContent=liveNextIteration<liveFirstResult?`Vague ${liveNextIteration/liveWaveSize}/5 · ${liveCombats.toLocaleString('fr-FR')} / 5 500 combats`:liveNextIteration<liveMaximum?`${liveCombats.toLocaleString('fr-FR')} combats · affinage en cours`:`${liveCombats.toLocaleString('fr-FR')} combats · précision cible atteinte`}
function mergeLiveRows(rows){for(const delta of rows){const row=liveRows.get(delta.id);if(!row){liveRows.set(delta.id,structuredClone(delta));continue}for(const key of ['samples','attackerWins','draws','defenderWins','roundSum'])row[key]+=delta[key];for(const side of ['attackerRaw','defenderRaw'])for(const key of ['dead','wounded'])row[side][key]+=delta[side][key];for(const side of ['attackerProjected','defenderProjected'])for(const key of ['dead','wounded','prisoners'])row[side][key]+=delta[side][key]}}
function hudOutcome(kind,count,row,label){const rate=row.samples?100*count/row.samples:0,visible=rate>=30?`${hudNumber(count)} · ${hudPercent(count,row.samples)}`:rate>=6?hudPercent(count,row.samples):'';return `<span class="hud-outcome-segment ${kind}" style="width:${rate}%" title="${label} : ${hudNumber(count)} · ${hudPercent(count,row.samples)}">${visible}</span>`}
function hudDirection(row){
  const cutLoss=row.defenderWins>0&&(row.draws>0||row.attackerWins>0)?`<span class="hud-cut" style="left:${100*row.defenderWins/row.samples}%"></span>`:'';
  const cutDraw=row.draws>0&&row.attackerWins>0?`<span class="hud-cut" style="left:${100*(row.defenderWins+row.draws)/row.samples}%"></span>`:'';
  const label=value=>row.kind==='free'?'Armée '+value:types[value];
  const raw=liveHudView==='numbers'&&liveLossView==='raw',suffix=raw?'Raw':'Projected';
  const average=(side,key)=>hudNumber(row[side+suffix][key]/row.samples);
  const lossRows=raw?[['Morts','dead'],['Blessés','wounded']]:[['Morts','dead'],['Blessés','wounded'],['Prisonniers','prisoners']];
  return `<section class="hud-direction" aria-label="${label(row.attacker)} attaque ${label(row.defender)}"><div class="hud-outcome" role="img" aria-label="${hudPercent(row.defenderWins,row.samples)} défaites, ${hudPercent(row.draws,row.samples)} nulles, ${hudPercent(row.attackerWins,row.samples)} victoires" title="${hudWinInterval(row)}">${hudOutcome('loss',row.defenderWins,row,'Défaites')}${hudOutcome('draw',row.draws,row,'Nulles')}${hudOutcome('win',row.attackerWins,row,'Victoires')}${cutLoss}${cutDraw}</div><table><caption>${raw?'Pertes physiques moyennes':'Conséquences moyennes après projection'}</caption><thead><tr><th>${hudNumber(row.roundSum/row.samples)} rounds</th><th>A : ${label(row.attacker)}</th><th>D : ${label(row.defender)}</th></tr></thead><tbody>${lossRows.map(([name,key])=>`<tr><th>${name}</th><td>${average('attacker',key)}</td><td>${average('defender',key)}</td></tr>`).join('')}</tbody></table></section>`;
}
function renderLiveInspector(){let rows,title;if(liveSelected){const [a,b]=liveSelected.split('>'),first=liveRows.get(`monotype:${a}>${b}`),second=a===b?null:liveRows.get(`monotype:${b}>${a}`);if(!first)return;rows=[first,second].filter(Boolean);title=`Duel observé : ${types[a]} vs ${types[b]} (${hudNumber(first.attackerBudget)} Or / ${hudNumber(first.defenderBudget)} Or)`}else{const first=liveRows.get('free:A>B'),second=liveRows.get('free:B>A');if(!first||!second)return;rows=[first,second];title=`Duel observé : Armée A vs Armée B (${hudNumber(first.attackerBudget)} Or / ${hudNumber(first.defenderBudget)} Or)`}$('#hud-inspector-title').textContent=title;$('#hud-directions').innerHTML=rows.map(hudDirection).join('')}
const hudViewKey='waar-workshop-hud-view-v1';
let liveHudView='histogram';
const hudLossViewKey='waar-workshop-hud-loss-view-v1';
let liveLossView='projected';
try{const stored=localStorage.getItem(hudViewKey);if(stored==='rose'||stored==='numbers')liveHudView=stored;if(localStorage.getItem(hudLossViewKey)==='raw')liveLossView='raw'}catch{}

function selectLivePair(pair){liveSelected=pair;renderLiveHud()}

function renderLiveBars(host){
  const units=Object.keys(types),short={soldier:'Sol',spearman:'Lan',archer:'Arc',knight:'Che'};
  host.innerHTML='<div class="hud-plot"><div class="hud-attacker-heads"><strong>Soldats</strong><strong>Lanciers</strong><strong>Archers</strong><strong>Chevaliers</strong></div><div class="hud-bars"></div><div class="hud-defender-labels">'+units.flatMap(()=>units.map(type=>'<span>'+short[type]+'</span>')).join('')+'</div></div>';
  const bars=host.querySelector('.hud-bars');
  for(const a of units)for(const b of units){
    const row=liveRows.get('monotype:'+a+'>'+b),button=document.createElement('button');
    button.type='button';button.className='hud-bar';button.dataset.pair=a+'>'+b;
    button.setAttribute('aria-pressed',String(liveSelected===button.dataset.pair));
    if(row){
      const loss=100*row.defenderWins/row.samples,draw=100*row.draws/row.samples,win=100*row.attackerWins/row.samples;
      const cutLoss=row.defenderWins&&(row.draws||row.attackerWins)?'<span class="hud-cut" style="top:'+loss+'%"></span>':'';
      const cutDraw=row.draws&&row.attackerWins?'<span class="hud-cut" style="top:'+(loss+draw)+'%"></span>':'';
      button.title=types[a]+' attaque '+types[b]+' · '+hudWinInterval(row);
      button.setAttribute('aria-label',types[a]+' attaque '+types[b]+' : '+hudWinInterval(row));
      button.innerHTML='<span class="hud-column"><span class="defense" style="height:'+loss+'%"></span><span class="draw" style="height:'+draw+'%"></span><span class="attack" style="height:'+win+'%"></span>'+cutLoss+cutDraw+'</span>';
    }else button.innerHTML='<span class="hud-column pending"></span>';
    button.onclick=()=>selectLivePair(button.dataset.pair);
    bars.append(button);
  }
}

function rosePoint(index,radius){
  const angle=-Math.PI/2+index*Math.PI/6;
  return [(240+radius*Math.cos(angle)).toFixed(1),(136+radius*Math.sin(angle)).toFixed(1)];
}

function renderLiveRose(host){
  // Opposite spokes are the reverse directions of the same matchup.
  const pairs=[['soldier','spearman'],['soldier','archer'],['soldier','knight'],['spearman','archer'],['spearman','knight'],['archer','knight'],['spearman','soldier'],['archer','soldier'],['knight','soldier'],['archer','spearman'],['knight','spearman'],['knight','archer']];
  const short={soldier:'Sol',spearman:'Lan',archer:'Arc',knight:'Che'};
  const spokes=pairs.map(([a,b],index)=>{
    const row=liveRows.get('monotype:'+a+'>'+b),score=row?100*(row.attackerWins-row.defenderWins)/row.samples:0;
    const radius=75+score*.35,point=rosePoint(index,radius),balance=rosePoint(index,75),outer=rosePoint(index,125);
    const color=!row?'pending':score>5?'attack':score< -5?'defense':'draw';
    const pair=a+'>'+b,label=types[a]+' attaque '+types[b],detail=row?hudWinInterval(row):'Calcul à venir';
    return {pair,point,markup:'<g class="hud-rose-spoke '+color+(liveSelected===pair?' selected':'')+'" data-pair="'+pair+'" role="button" tabindex="0" aria-label="'+label+' : '+detail+'" aria-pressed="'+(liveSelected===pair)+'"><title>'+label+' · '+detail+'</title><line class="hud-rose-axis" x1="'+balance[0]+'" y1="'+balance[1]+'" x2="'+outer[0]+'" y2="'+outer[1]+'"/><line class="hud-rose-ray" x1="'+balance[0]+'" y1="'+balance[1]+'" x2="'+point[0]+'" y2="'+point[1]+'"/><circle class="hud-rose-point" cx="'+point[0]+'" cy="'+point[1]+'" r="4"/><circle class="hud-rose-target" cx="'+outer[0]+'" cy="'+outer[1]+'" r="15"/><text class="hud-rose-label" x="'+outer[0]+'" y="'+(+outer[1]+3)+'">'+short[a]+'›'+short[b]+'</text></g>'};
  });
  const mirrors=Object.keys(types).map((a,index)=>{
    const row=liveRows.get('monotype:'+a+'>'+a),score=row?Math.round(100*(row.attackerWins-row.defenderWins)/row.samples):null;
    const positions=[[240,111],[265,136],[240,161],[215,136]],point=positions[index],pair=a+'>'+a;
    const label=types[a]+' contre '+types[a],detail=row?hudWinInterval(row):'Calcul à venir';
    return '<g class="hud-rose-mirror '+(score===null?'pending':score>5?'attack':score< -5?'defense':'draw')+(liveSelected===pair?' selected':'')+'" data-pair="'+pair+'" role="button" tabindex="0" aria-label="'+label+' : '+detail+'" aria-pressed="'+(liveSelected===pair)+'"><title>'+label+' · '+detail+'</title><circle cx="'+point[0]+'" cy="'+point[1]+'" r="12"/><text x="'+point[0]+'" y="'+(+point[1]+3)+'">'+short[a][0]+'</text></g>';
  });
  host.innerHTML='<div class="hud-rose-plot"><svg class="hud-rose" viewBox="85 -24 310 320" role="group" aria-label="Rose de combat : avantage attaquant moins avantage défenseur pour chaque duel orienté"><circle class="hud-rose-ring" cx="240" cy="136" r="115"/><circle class="hud-rose-balance" cx="240" cy="136" r="75"/><polygon class="hud-rose-shape" points="'+spokes.map(spoke=>spoke.point.join(',')).join(' ')+'"/>'+spokes.map(spoke=>spoke.markup).join('')+'<circle class="hud-rose-center" cx="240" cy="136" r="37"/>'+mirrors.join('')+'</svg></div>';
  host.querySelectorAll('[data-pair]').forEach(target=>{
    target.onclick=()=>selectLivePair(target.dataset.pair);
    target.onkeydown=event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();selectLivePair(target.dataset.pair)}};
  });
}

function hudNumberLoss(row,side){
  const losses=row[side+(liveLossView==='raw'?'Raw':'Projected')],n=row.samples;
  const values=[losses.dead/n,losses.wounded/n];
  if(liveLossView==='projected')values.push(losses.prisoners/n);
  const labels=['morts','blessés','prisonniers'];
  return `<span class="hud-number-loss" aria-label="${values.map((value,index)=>labels[index]+' '+hudNumber(value)).join(', ')}">${values.map(hudNumber).join(' / ')}</span>`;
}

function renderLiveNumbers(host){
  const units=Object.keys(types),projected=liveLossView==='projected';
  const rows=units.flatMap(attacker=>units.map(defender=>{
    const pair=attacker+'>'+defender,row=liveRows.get('monotype:'+pair),name=types[attacker]+' → '+types[defender];
    const button=`<button type="button" data-pair="${pair}" aria-pressed="${liveSelected===pair}" title="${row?hudNumber(row.attackerBudget)+' Or / '+hudNumber(row.defenderBudget)+' Or':'Calcul à venir'}">${name}</button>`;
    if(!row)return `<tr><th scope="row">${button}</th><td colspan="8">Calcul à venir</td></tr>`;
    const [lower,upper]=hudWilsonBounds(row),pct=value=>(100*value).toLocaleString('fr-FR',{maximumFractionDigits:1})+' %';
    return `<tr class="${liveSelected===pair?'selected':''}"><th scope="row">${button}</th><td>${row.samples.toLocaleString('fr-FR')}</td><td>${hudPercent(row.attackerWins,row.samples)}</td><td>${hudPercent(row.draws,row.samples)}</td><td>${hudPercent(row.defenderWins,row.samples)}</td><td>${pct(lower)} – ${pct(upper)}</td><td>${hudNumber(row.roundSum/row.samples)}</td><td>${hudNumberLoss(row,'attacker')}</td><td>${hudNumberLoss(row,'defender')}</td></tr>`;
  })).join('');
  host.innerHTML=`<div class="hud-number-tools"><span>Pertes</span><div role="group" aria-label="Lecture des pertes"><button type="button" data-hud-loss-view="raw" aria-pressed="${!projected}">Avant projection</button><button type="button" data-hud-loss-view="projected" aria-pressed="${projected}">Après projection</button></div></div><div class="hud-number-scroll"><table class="hud-number-table"><caption>16 confrontations monotypes orientées · budget maximal 30 000 Or par camp</caption><thead><tr><th scope="col">Attaquant → Défenseur</th><th scope="col">Combats</th><th scope="col">Vic. %</th><th scope="col">Nuls %</th><th scope="col">Déf. %</th><th scope="col">IC 95 % vic.</th><th scope="col">Rounds</th><th scope="col">A : M / B${projected?' / P':''}</th><th scope="col">D : M / B${projected?' / P':''}</th></tr></thead><tbody>${rows}</tbody></table></div>`;
  host.querySelectorAll('[data-hud-loss-view]').forEach(button=>button.onclick=()=>{
    if(liveLossView===button.dataset.hudLossView)return;
    liveLossView=button.dataset.hudLossView;
    try{localStorage.setItem(hudLossViewKey,liveLossView)}catch{}
    renderLiveHud();
  });
  host.querySelectorAll('[data-pair]').forEach(button=>button.onclick=()=>selectLivePair(button.dataset.pair));
}

function renderLiveHud(){
  const root=$('#live-duel-results');
  root.classList.toggle('is-rose',liveHudView==='rose');
  root.classList.toggle('is-numbers',liveHudView==='numbers');
  const footer=liveHudView==='numbers'?`<div class="hud-number-note">A = attaquant · D = défenseur · ${liveLossView==='raw'?'M / B = morts / blessés physiques avant compression':'M / B / P = morts / blessés / prisonniers après projection'}, moyennes par combat. IC de Wilson sur les victoires ; les nuls restent séparés.</div>`:`<div class="hud-footer"><div class="hud-legend"><span><i class="attack"></i> Attaquant</span><span><i class="draw"></i> Nul</span><span><i class="defense"></i> Défenseur</span></div><p>${liveHudView==='rose'?'Anneau pointillé : équilibre. Un rayon vers l’extérieur favorise l’attaquant ; vers le centre, le défenseur.':'Pertes moyennes projetées par combat : morts, blessés et prisonniers sont les agrégats livrés par le moteur.'}</p></div>`;
  root.innerHTML=`<section class="hud-monotypes"><div class="hud-heading"><h2>Combats monotypes</h2><div class="hud-view-choices" role="group" aria-label="Vue des combats monotypes"><button type="button" data-hud-view="histogram" aria-pressed="${liveHudView==='histogram'}">Histogrammes</button><button type="button" data-hud-view="rose" aria-pressed="${liveHudView==='rose'}">Rose de combat</button><button type="button" data-hud-view="numbers" aria-pressed="${liveHudView==='numbers'}">Résultats chiffrés</button></div></div><div id="hud-monotype-visual"></div>${footer}</section><aside class="hud-inspector"><h2 id="hud-inspector-title">Duel observé</h2><div class="hud-directions" id="hud-directions"></div></aside>`;
  root.querySelectorAll('[data-hud-view]').forEach(button=>button.onclick=()=>{
    if(liveHudView===button.dataset.hudView)return;
    liveHudView=button.dataset.hudView;
    try{localStorage.setItem(hudViewKey,liveHudView)}catch{}
    renderLiveHud();
  });
  const visual=root.querySelector('#hud-monotype-visual');
  if(liveHudView==='rose')renderLiveRose(visual);else if(liveHudView==='numbers')renderLiveNumbers(visual);else renderLiveBars(visual);
  renderLiveInspector();
}
async function runLiveDuel(){if(liveBusy)return;const signature=liveSignature(),start=signature===liveShownSignature?liveNextIteration:0;if(start>=liveMaximum||document.hidden&&start>=liveFirstResult)return;liveBusy=true;const requestId=`${++liveSequence}-${start}`;let retry=0,success=false;try{const response=await api('combat-hud',{requestId,profile,armies,weather:duelWeather,modifiers:armyModifiers,seed:Number($('#duel-seed').value),startIteration:start});if(signature!==liveSignature()||response.requestId!==requestId)return;if(response.iterationRange?.start!==start||response.iterationRange.endExclusive!==start+liveWaveSize||response.totalCombats!==1100)throw new Error('Vague HUD incomplète.');if(start===0){liveRows.clear();liveCombats=0;liveShownSignature=signature}mergeLiveRows(response.rows);liveCombats+=response.totalCombats;liveNextIteration=start+liveWaveSize;renderLiveHud();updateLiveStatus();updateLiveConfidence();$('#live-duel-overlay').hidden=true;$('#live-duel').classList.remove('is-updating');success=true}catch(e){if(signature!==liveSignature())return;$('#live-duel-status').textContent=e.status===429?'Serveur occupé · nouvel essai dans 10 s':e.message;retry=e.status===429?10000:0}finally{liveBusy=false;if(signature!==liveSignature())scheduleLiveDuel();else if(retry)queueLiveWave(retry);else if(success&&liveNextIteration<liveMaximum&&(liveNextIteration<liveFirstResult||!document.hidden))queueLiveWave(liveNextIteration<liveFirstResult?0:liveBackgroundDelay)}}
function measurementState(){return JSON.stringify([profile,activeWeather])}
function dirty(){renderProfileMeta();scheduleLiveDuel();save();invalidateSearch();if(WaarWorkshopModel.stale(resultSignature,duelSignature()))$('#duel-stale').classList.remove('hidden');if(measurement&&measurementSignature!==measurementState()){staleZones=structuredClone(zones);measurement=null;zones=[];editorFingerprint=null;editorLoad++;$('#t27-editor').srcdoc='';$('#zones-empty').hidden=false;$('#zone-editor').classList.add('hidden');$('#search-results').replaceChildren();$('#measure-progress').textContent='Mesure obsolète. La géométrie des zones est conservée et pourra être réassociée après la prochaine mesure.'}}
function save(){try{localStorage.setItem('waar-workshop-draft-v1',JSON.stringify({profile,profileBaseline,armies,activeWeather,duelWeather,armyModifiers,staleZones}))}catch{$('#notice').textContent='Le stockage local est indisponible. Utilisez la sauvegarde JSON.'}}
function value(path,value){const parts=path.split('.');let cursor=profile;for(let i=0;i<parts.length-1;i++)cursor=cursor[parts[i]];cursor[parts.at(-1)]=value;dirty()}
function selectUnit(type){selectedUnit=type;$$('[data-unit]').forEach(card=>card.hidden=card.dataset.unit!==type);$$('[data-unit-choice]').forEach(button=>button.setAttribute('aria-current',String(button.dataset.unitChoice===type)))}
function percentDecimal(value){return (Math.max(0,Math.min(100,Number(value)||0))/100).toFixed(6).replace(/0+$/,'').replace(/\.$/,'')||'0'}
function defenseExplanation(coefficient){const difference=Math.round((Number(coefficient)-1)*100);return difference===0?'Unité aussi puissante quand le camp défend':'Unité '+Math.abs(difference)+'% '+(difference>0?'plus':'moins')+' puissante quand le camp défend'}
function strikeExplanation(attack,strikes){const a=Number(attack),f=Number(strikes);if(String(attack).trim()===''||!Number.isFinite(a)||!Number.isInteger(f)||f<1)return '—';return f+' frappe'+(f===1?'':'s')+' de puissance '+(a/f).toLocaleString('fr-FR',{maximumFractionDigits:6})}
function renderUnits(){const grid=$('#unit-grid');grid.replaceChildren();for(const [type,label] of Object.entries(types)){const u=profile.units[type];const card=document.createElement('article');card.className='unit-card';card.dataset.unit=type;card.innerHTML=`<div class="fields"><label>Attaque - Puissance répartie<input data-field="attack" inputmode="decimal" value="${u?.attack??''}"></label><label>Structure - Résistance aux dégâts<input data-field="structure" inputmode="decimal" value="${u?.structure??''}"></label><label>Précision moyenne (%)<input data-field="baseAccuracy" data-percent type="number" min="0" max="100" step=".1" value="${u?Number(u.baseAccuracy)*100:''}"></label><label>Variation de précision (±%)<input data-field="accuracySpread" data-percent type="number" min="0" max="100" step=".1" value="${u?Number(u.accuracySpread)*100:''}"></label><label>Frappes par combattant<input data-field="strikesPerAttack" type="number" min="1" max="10" step="1" value="${u?.strikesPerAttack??1}" aria-describedby="strikes-help-${type}"><small class="setting-help strikes-help" id="strikes-help-${type}" aria-live="polite">${strikeExplanation(u?.attack??'',u?.strikesPerAttack??1)}</small></label><label>Coût<input data-field="cost" type="number" min="1" max="400400" step="1" value="${u?.cost??costs[type]}"></label></div><div class="defense-setting"><label for="defense-${type}">Coefficient défensif <output class="defense-value" for="defense-${type}">×${u?.defendingEfficiency??1}</output></label><input id="defense-${type}" data-field="defendingEfficiency" type="range" min="0.5" max="2" step="0.25" value="${u?.defendingEfficiency??1}" aria-describedby="defense-help-${type}"><div class="defense-ticks" aria-hidden="true">${[.5,.75,1,1.25,1.5,1.75,2].map(n=>'<span>'+n.toLocaleString('fr-FR')+'</span>').join('')}</div><p id="defense-help-${type}" class="defense-help" aria-live="polite">${defenseExplanation(u?.defendingEfficiency??1)}</p></div><label><input data-field="capturable" type="checkbox" ${u?.capturable?'checked':''}> Cette unité peut être capturée</label>`;
      card.querySelectorAll('[data-field]').forEach(input=>input.addEventListener('input',()=>{if(!profile.units[type])profile.units[type]={cost:costs[type],capturable:false};let next=input.type==='checkbox'?input.checked:['cost','strikesPerAttack'].includes(input.dataset.field)?Number(input.value):input.dataset.percent!==undefined?percentDecimal(input.value):input.value;value(`units.${type}.${input.dataset.field}`,next);if(input.dataset.field==='cost')renderCosts()}));const attackInput=card.querySelector('[data-field="attack"]'),strikesInput=card.querySelector('[data-field="strikesPerAttack"]');const updateStrikes=()=>{card.querySelector('.strikes-help').textContent=strikeExplanation(attackInput.value,strikesInput.value)};attackInput.addEventListener('input',updateStrikes);strikesInput.addEventListener('input',updateStrikes);const defense=card.querySelector('[data-field="defendingEfficiency"]');defense.oninput=()=>{if(!profile.units[type])profile.units[type]={cost:costs[type],capturable:false};const coefficient=Math.max(.5,Math.min(2,Math.round(Number(defense.value)*4)/4));defense.value=coefficient;card.querySelector('.defense-value').textContent='×'+coefficient.toLocaleString('fr-FR');card.querySelector('.defense-help').textContent=defenseExplanation(coefficient);value(`units.${type}.defendingEfficiency`,String(coefficient))};grid.append(card)}
  const selector=$('#unit-selector');selector.replaceChildren();const symbols={soldier:'♙',spearman:'⚑',archer:'⌁',knight:'♞'};for(const [type,label] of Object.entries(types)){const button=document.createElement('button');button.className='unit-choice';button.dataset.unitChoice=type;button.innerHTML=`<span class="unit-symbol" aria-hidden="true">${symbols[type]}</span><span><strong>${label}</strong></span>`;button.onclick=()=>selectUnit(type);selector.append(button)}selectUnit(selectedUnit);
}
function fillSelect(select){select.replaceChildren();for(const [value,label] of Object.entries(types))select.add(new Option(label,value))}
function renderRelations(){
  const selector=$('#relation-unit-selector');selector.replaceChildren();const symbols={soldier:'♙',spearman:'⚑',archer:'⌁',knight:'♞'};
  for(const [type,label] of Object.entries(types)){const button=document.createElement('button');button.type='button';button.className='unit-choice';button.dataset.relationUnit=type;button.setAttribute('aria-current',String(type===selectedRelationUnit));button.innerHTML=`<span class="unit-symbol" aria-hidden="true">${symbols[type]}</span><span><strong>${label}</strong></span>`;button.onclick=()=>{selectedRelationUnit=type;renderRelations()};selector.append(button)}
  const target=$('#relation-target'),previous=target.value;target.replaceChildren();for(const [type,label] of Object.entries(types))if(type!==selectedRelationUnit)target.add(new Option(label,type));if(previous!==selectedRelationUnit&&types[previous])target.value=previous;
  const updateFactor=()=>{$('#relation-factor').value=profile.relations.find(r=>r.acting===selectedRelationUnit&&r.target===target.value)?.factor??'1'};target.onchange=updateFactor;updateFactor();
  const list=$('#relation-list');list.replaceChildren();if(!profile.relations.some(r=>r.acting===selectedRelationUnit))list.textContent='Aucun contre : les dégâts de cette unité sont à ×1 contre toutes les cibles.';
  profile.relations.forEach((r,i)=>{if(r.acting!==selectedRelationUnit)return;const el=document.createElement('span');el.className='chip';el.innerHTML=`${types[r.acting]} → ${types[r.target]} : dégâts ×${r.factor} <button aria-label="Supprimer cette relation">✕</button>`;el.querySelector('button').onclick=()=>{profile.relations.splice(i,1);renderRelations();dirty()};list.append(el)})
}
function renderWeather(){const tabs=$('#weather-tabs');tabs.replaceChildren();for(const [group,ids] of [['Neutres',['neutral','cloudy']],['Température',['snow','blizzard','heat','canicule']],['Vent',['wind','storm']],['Précipitations',['rain','thunderstorm']]]){const block=document.createElement('section');block.className='weather-preset-group';const heading=document.createElement('h2');heading.textContent=group;block.append(heading);for(const id of ids){const button=document.createElement('button');button.type='button';button.className='unit-choice';button.dataset.weatherPreset=id;button.textContent=weather[id];button.setAttribute('aria-current',String(id===activeWeather));button.onclick=()=>{activeWeather=id;duelWeather=id;renderWeather();dirty()};block.append(button)}tabs.append(block)}}
function renderCombat(){for(const input of $$('[data-combat]')){const key=input.dataset.combat;input.setAttribute('aria-label',({maxRounds:'Rounds',surrenderEnabled:'Reddition',surrenderDeadPercent:'Seuil de reddition',woundDamageThreshold:'Seuil de blessure',tieBreakCriterion:'Évaluation des forces restantes',equalityPolicy:'Condition de nul',lossCompressionPercent:'Compression',capturePercent:'Prisonniers'})[key]);if(input.type==='checkbox')input.checked=profile.combat[key];else input.value=key==='surrenderDeadPercent'&&!profile.combat.surrenderEnabled?0:key==='woundDamageThreshold'?Number(profile.combat[key])*100:profile.combat[key];input.oninput=()=>{if(key==='surrenderDeadPercent'){const threshold=Number(input.value);profile.combat.surrenderEnabled=threshold>0;if(threshold>0)profile.combat.surrenderDeadPercent=threshold;renderExample();dirty();return}profile.combat[key]=key==='woundDamageThreshold'?percentDecimal(input.value):input.type==='checkbox'?input.checked:['maxRounds','surrenderDeadPercent','lossCompressionPercent','capturePercent'].includes(key)?Number(input.value):input.value;renderExample();dirty()}}renderExample()}
function renderExample(){$('#rounds-out').textContent=profile.combat.maxRounds;$('#surrender-out').textContent=profile.combat.surrenderEnabled?profile.combat.surrenderDeadPercent+' %':'Désactivée';const c=profile.combat.lossCompressionPercent,p=profile.combat.capturePercent;$('#wound-threshold-out').textContent=(Number(profile.combat.woundDamageThreshold)*100).toLocaleString('fr-FR',{maximumFractionDigits:4})+' %';$('#compression-out').textContent=c+' %';$('#capture-out').textContent=p+' %';$('#combat-summary').textContent=`${profile.combat.maxRounds} rounds · ${profile.combat.tieBreakCriterion==='economic'?'coût restant':'structure restante'}`}
function renderArmies(){for(const camp of ['A','B']){const root=$('#army-'+camp.toLowerCase());root.replaceChildren();for(const [type,label] of Object.entries(types)){const row=document.createElement('label');row.className='army-row';row.innerHTML=`<span>${label}</span><input type="range" min="0" max="100000" value="${armies[camp][type]}" aria-label="${label}, camp ${camp}"><input type="number" min="0" max="1000000" step="1" value="${armies[camp][type]}" aria-label="Nombre de ${label.toLowerCase()}s, camp ${camp}">`;const range=row.children[1],number=row.children[2];const update=input=>{const n=Math.max(0,Math.min(input===range?100000:1000000,Math.trunc(Number(input.value)||0)));number.value=n;range.value=Math.min(100000,n);armies[camp][type]=n;renderCosts();dirty()};range.oninput=()=>update(range);number.oninput=()=>update(number);root.append(row)}}renderCosts()}
function renderCosts(){for(const camp of ['A','B']){const suffix=camp.toLowerCase();$('#cost-'+suffix).textContent=WaarWorkshopModel.cost(armies[camp],Object.fromEntries(Object.keys(types).map(type=>[type,profile.units[type]?.cost??costs[type]]))).toLocaleString('fr-FR');}}
function setupArmyShortcuts(){const compositions={soldier:[100,0,0,0],spearman:[0,100,0,0],archer:[0,0,100,0],knight:[0,0,0,100],sl:[80,20,0,0],sac:[50,0,40,10]};for(const camp of ['A','B']){const suffix=camp.toLowerCase();$('#apply-army-'+suffix).onclick=()=>{const budget=Number($('#budget-'+suffix).value),shares=compositions[$('#composition-'+suffix).value],next={};for(const [i,type] of Object.keys(types).entries()){const cost=Number(profile.units[type]?.cost);if(shares[i]>0&&(!Number.isInteger(cost)||cost<1)){$('#army-shortcut-error-'+suffix).textContent='Renseignez un coût valide pour '+types[type]+'.';return}next[type]=shares[i]===0?0:Math.floor(budget*shares[i]/100/cost)}armies[camp]=next;$('#army-shortcut-error-'+suffix).textContent='';renderArmies();dirty()}}}
function renderProfileMeta(){if(!profile)return;const name=profileBaseline?.label||profile.label;$('#current-profile-name').textContent=name;$('#current-profile-name').title=name;$('#profile-modified').hidden=Boolean(profileBaseline)&&WaarWorkshopModel.stable(profile)===WaarWorkshopModel.stable(profileBaseline)}
async function restoreProfileBaseline(draft){if(draft?.profileBaseline){profileBaseline=draft.profileBaseline;return}if(!draft?.profile){profileBaseline=structuredClone(profile);return}const defaults=(await api('default-profile')).profile;profileBaseline=defaults;if(profile.id!==defaults.id){try{const list=await api('profiles'),entry=list.profiles.find(p=>p.name===profile.label);if(entry)profileBaseline=(await api('load-profile',{id:entry.id})).profile;else profileBaseline=null}catch{profileBaseline=null}}}
async function refreshSavedProfiles(){const root=$('#saved-profiles');try{const result=await api('profiles');root.replaceChildren();$('#profile-menu-status').textContent=result.profiles.length?'':'Aucune sauvegarde partagée pour le moment.';for(const saved of result.profiles){const button=document.createElement('button');button.type='button';button.textContent=saved.name;button.onclick=async()=>{button.disabled=true;try{const loaded=await api('load-profile',{id:saved.id});profile=loaded.profile;profileBaseline=structuredClone(profile);renderAll();dirty();$('#ready').open=false;$('#notice').textContent=''}catch(e){$('#profile-menu-status').textContent=e.message}finally{button.disabled=false}};root.append(button)}}catch(e){$('#profile-menu-status').textContent=e.message}}
function setupSavedProfiles(){const dialog=$('#save-profile-dialog');$('#save-profile').onclick=()=>{$('#ready').open=false;$('#saved-profile-name').value='';$('#save-profile-error').textContent='';dialog.showModal();$('#saved-profile-name').focus()};for(const id of ['#cancel-profile','#cancel-profile-x'])$(id).onclick=()=>dialog.close();$('#ready').addEventListener('toggle',()=>{if($('#ready').open)refreshSavedProfiles()});$('#save-profile-form').onsubmit=async event=>{event.preventDefault();const name=$('#saved-profile-name').value.trim();if(!name){$('#save-profile-error').textContent='Indiquez un nom pour cette proposition.';return}const button=$('#confirm-save-profile');button.disabled=true;const snapshot=structuredClone(profile),signature=WaarWorkshopModel.stable(profile);try{const saved=await api('save-profile',{name,profile:snapshot});if(signature===WaarWorkshopModel.stable(profile)){profile=saved.profile;profileBaseline=structuredClone(profile);dirty()}dialog.close();await refreshSavedProfiles();$('#notice').textContent='Profil partagé sauvegardé : '+saved.name}catch(e){$('#save-profile-error').textContent=e.message}finally{button.disabled=false}}}
function openDuel(){renderArmies();$('#modal-test-host').append($('#test-content'));$('#duel-dialog').showModal();setTimeout(()=>$('#duel-dialog input')?.focus(),0)}
function errors(values){const root=$('#duel-errors');root.replaceChildren();for(const error of values||[]){const p=document.createElement('p'),strong=document.createElement('strong');strong.textContent=error.path||'Erreur';p.append(strong,' — '+error.message);if(error.code==='missing_unit'){const type=error.path.split('.')[1],button=document.createElement('button');button.type='button';button.textContent='Configurer '+(types[type]||type);button.onclick=()=>{$('#duel-dialog').close();selectView('units');selectUnit(type);document.querySelector(`[data-unit="${type}"]`)?.scrollIntoView({behavior:'smooth'});};p.append(' ',button)}root.append(p)}}
function consequenceTable(direction,side,label){const data=direction.consequences[side],rows=Object.entries(data.types).filter(([,u])=>u.initial>0).map(([type,u])=>`<tr><th scope="row">${types[type]}</th><td>${u.initial.toLocaleString('fr-FR')}</td><td>${u.raw.healthy.toLocaleString('fr-FR')}</td><td>${u.raw.wounded.toLocaleString('fr-FR')}</td><td>${u.raw.dead.toLocaleString('fr-FR')}</td><td>${u.projected.healthy.toLocaleString('fr-FR')}</td><td>${u.projected.wounded.toLocaleString('fr-FR')}</td><td>${u.projected.dead.toLocaleString('fr-FR')}</td><td>${u.projected.prisoners.toLocaleString('fr-FR')}</td></tr>`).join('');return `<h4>Armée ${label}</h4><table><thead><tr><th rowspan="2">Unité</th><th rowspan="2">Initial</th><th colspan="3">Brut</th><th colspan="4">Après projection</th></tr><tr><th>Valides (≤ seuil)</th><th>Blessés</th><th>Morts</th><th title="Inclut les pertes non conservées par la compression ; ne décrit pas la structure physique.">Valides en sortie</th><th>Blessés</th><th>Morts</th><th>Prisonniers</th></tr></thead><tbody>${rows}</tbody></table>`}
function consequenceSummary(direction,side,initialCosts){
  const data=direction.consequences[side],label=direction.labels[side];
  const rows=Object.entries(data.types).filter(([,u])=>u.initial>0).map(([type,u])=>`<tr><th scope="row">${types[type]}</th><td>${u.projected.healthy.toLocaleString('fr-FR')}</td><td>${u.projected.wounded.toLocaleString('fr-FR')}</td><td>${u.projected.dead.toLocaleString('fr-FR')}</td><td>${u.projected.prisoners.toLocaleString('fr-FR')}</td></tr>`).join('');
  return `<section class="loss-summary camp-${label.toLowerCase()}"><h4>Camp ${label}</h4><table><thead><tr><th>Unité</th><th title="Inclut les pertes non conservées par la compression ; ne décrit pas la structure physique.">Valides en sortie</th><th>Blessés</th><th>Morts</th><th>Capturés</th></tr></thead><tbody>${rows}</tbody></table><p class="economic-loss"><span>Coût économique perdu</span><strong>${data.economicLoss.toLocaleString('fr-FR')} (${Number(data.economicLossPercent).toLocaleString('fr-FR',{maximumFractionDigits:6})} %)</strong></p></section>`;
}
function orderedSides(direction){return direction.labels.attacker==='A'?['attacker','defender']:['defender','attacker']}
function points(value){return Number(value).toLocaleString('fr-FR',{maximumFractionDigits:6})}
function stateTable(direction,side){const result=direction.result[side],rows=Object.keys(types).filter(type=>direction.result.initialArmies[side][type]>0).map(type=>`<tr><th scope="row">${types[type]}</th><td>${direction.result.initialArmies[side][type].toLocaleString('fr-FR')}</td><td>${result.healthy[type].toLocaleString('fr-FR')}</td><td>${result.wounded[type].toLocaleString('fr-FR')}</td><td>${result.dead[type].toLocaleString('fr-FR')}</td></tr>`).join('');return `<div class="table-scroll"><table><thead><tr><th>Unité</th><th>Initial</th><th>Valides (≤ seuil)</th><th>Blessés</th><th>Morts</th></tr></thead><tbody>${rows}</tbody></table></div>`}
function preparedTable(direction,side){const units=direction.result.snapshot.prepared[side].units,rows=units.map(unit=>`<tr><th scope="row">${types[unit.type]}</th><td>${points(unit.base.attack)} → ${points(unit.attack)}</td><td>${points(unit.base.structure)} → ${points(unit.structure)}</td><td>${points(unit.base.baseAccuracy)} → ${points(unit.baseAccuracy)}</td><td>±${points(unit.base.accuracySpread)} → ±${points(unit.accuracySpread)}</td><td>${unit.strikesPerAttack}</td><td>×${points(unit.defendingEfficiency)}</td><td>${unit.effects.length?unit.effects.map(effect=>escapeHtml(effect.label)).join(', '):'—'}</td></tr>`).join('');return `<div class="table-scroll"><table><thead><tr><th>Unité</th><th>Attaque</th><th>Structure</th><th>Précision</th><th>Amplitude</th><th>Frappes</th><th>Défense</th><th>Modificateurs</th></tr></thead><tbody>${rows}</tbody></table></div>`}
function impactTooltip(hit,side){return `<strong>${hit.appliedHits.toLocaleString('fr-FR')} touches appliquées</strong><span>${hit.sourceCount.toLocaleString('fr-FR')} combattants × ${hit.strikesPerAttack} frappes : ${hit.allocatedAttempts.toLocaleString('fr-FR')} tentatives allouées.</span><span>Précision tirée : ${points(hit.accuracy)}</span><span>Attaque par frappe : ${points(hit.attackPerStrike)}</span><span>${side==='defender'?'Coefficient défensif':'Camp attaquant'} ×${points(hit.defendingEfficiency)}</span><span class="${hit.attackFactor==='1'?'':'factor-impact'}">Contre ×${points(hit.attackFactor)}</span><span>Dégâts par touche : ${points(hit.damagePerHit)}</span><strong>${points(hit.damageEmitted)} émis · ${points(hit.damageAbsorbed)} absorbés</strong><small>${hit.sampledHits.toLocaleString('fr-FR')} touches tirées ; ${hit.reallocatedAttempts.toLocaleString('fr-FR')} tentatives réallouées ; ${points(hit.overkill)} dégâts excédentaires.</small>`}
function roundAttacks(direction,round,side){
  const targetSide=side==='attacker'?'defender':'attacker',camp=direction.labels[side],target=direction.labels[targetSide];
  const sources=Object.keys(types),targets=Object.keys(types),action=round[side+'Action'];
  const rows=sources.map(source=>`<tr><th scope="row">${types[source]}</th>${targets.map(type=>{
    const hit=action.matrix[source][type];
    if(hit.sourceCount===0||hit.allocatedAttempts===0){
      const reason=hit.sourceCount===0?'Cohorte source vide':'Aucune tentative allouée à cette cible';
      return `<td class="impact-empty"><span class="muted" title="${reason}" aria-label="${reason}">—</span></td>`;
    }
    const id=`impact-${direction.labels.attacker}-${round.number}-${side}-${source}-${type}`;
    return `<td><span class="impact-value ${hit.defendingEfficiency!=='1'||hit.attackFactor!=='1'?'impact-modified':''}" tabindex="0" aria-label="${types[source]} ${camp} vers ${types[type]} ${target} : ${hit.appliedHits.toLocaleString('fr-FR')} touches sur ${hit.allocatedAttempts.toLocaleString('fr-FR')} tentatives" aria-describedby="${id}">${hit.appliedHits.toLocaleString('fr-FR')}<small> / ${hit.allocatedAttempts.toLocaleString('fr-FR')}</small><span id="${id}" role="tooltip" class="impact-tooltip">${impactTooltip(hit,side)}</span></span></td>`;
  }).join('')}</tr>`).join('');
  return `<section class="round-camp camp-${camp.toLowerCase()}"><h5>${side==='attacker'?'Frappes':'Ripostes'} du camp ${camp} → camp ${target}</h5><p class="matrix-caption">Touches appliquées / tentatives allouées · détail au survol ou au clavier</p><table class="impact-matrix"><thead><tr><th scope="col">Source ↓ / Cible →</th>${targets.map(type=>`<th scope="col">${types[type]}</th>`).join('')}</tr></thead><tbody>${rows}</tbody></table><p class="round-summary">${action.attempts.toLocaleString('fr-FR')} tentatives · ${action.hits.toLocaleString('fr-FR')} touches · ${action.deaths.toLocaleString('fr-FR')} morts · ratio de morts du camp ${camp} après le round : ${points(round[side+'DeathRatio']*100)} %</p></section>`;
}
function detailedCombat(direction){
  const sides=orderedSides(direction),rounds='<section class="round-timeline"><nav class="round-navigation" aria-label="Navigation des rounds"><button type="button" class="round-previous" aria-label="Round précédent" title="Round précédent">←</button><strong class="round-position" aria-live="polite">'+(direction.result.rounds.length?'Round '+direction.result.rounds[0].number+' / '+direction.result.rounds.length:'Aucun round')+'</strong><button type="button" class="round-next" aria-label="Round suivant" title="Round suivant">→</button></nav><div class="round-detail">'+(direction.result.rounds.length?'<div class="camp-columns">'+sides.map(side=>roundAttacks(direction,direction.result.rounds[0],side)).join('')+'</div>':'')+'</div></section>';
  return `<h4>Paramètres effectifs</h4><p class="muted">Base → acquis → météo. Les coefficients sont préparés une fois avant le combat.</p><div class="camp-columns">${sides.map(side=>`<section class="round-camp camp-${direction.labels[side].toLowerCase()}"><h5>Camp ${direction.labels[side]} · ${side==='attacker'?'attaque':'défense'}</h5>${preparedTable(direction,side)}</section>`).join('')}</div><h4>Chronologie du combat</h4><p class="muted">Les deux camps agissent depuis l’état du début du round. Le ciblage est proportionnel aux cohortes vivantes ; les contres modifient les dégâts, pas la cible. La compression et les captures interviennent après la résolution brute.</p>${rounds}<h4>État physique final</h4><div class="camp-columns">${sides.map(side=>`<section class="round-camp camp-${direction.labels[side].toLowerCase()}"><h5>Camp ${direction.labels[side]}</h5>${stateTable(direction,side)}</section>`).join('')}</div><h4>Projection de sortie</h4><div class="camp-columns">${sides.map(side=>`<section class="table-scroll">${consequenceTable(direction,side,direction.labels[side])}</section>`).join('')}</div>`;
}
function mountRoundTimeline(root,direction){
  const rounds=direction.result.rounds,previous=root.querySelector('.round-previous'),next=root.querySelector('.round-next'),position=root.querySelector('.round-position'),detail=root.querySelector('.round-detail');let index=0;
  const render=()=>{previous.disabled=index===0;next.disabled=index>=rounds.length-1;position.textContent=rounds.length?'Round '+rounds[index].number+' / '+rounds.length:'Aucun round';detail.innerHTML=rounds.length?'<div class="camp-columns">'+orderedSides(direction).map(side=>roundAttacks(direction,rounds[index],side)).join('')+'</div>':'<p class="muted">Le combat s’est terminé sans round.</p>'};
  previous.onclick=()=>{if(index>0){index--;render()}};next.onclick=()=>{if(index<rounds.length-1){index++;render()}};render();
}
function renderDuel(result){
  $('#duel-stale').classList.add('hidden');resultSignature=duelSignature();
  const root=$('#duel-results');root.innerHTML='<div class="result-grid"></div>';
  const grid=root.querySelector('.result-grid');
  const analysis=document.createElement('section');analysis.id='duel-analysis';analysis.className='result combat-analysis';analysis.hidden=true;analysis.setAttribute('aria-label','Analyse détaillée du combat');root.append(analysis);
  let selected=null;const buttons=[];
  const closeAnalysis=()=>{analysis.hidden=true;analysis.replaceChildren();selected=null;buttons.forEach(button=>button.setAttribute('aria-expanded','false'))};
  for(const direction of result.directions){
    const article=document.createElement('article');article.className='result';
    article.innerHTML=`<h3>${direction.labels.attacker} attaque ${direction.labels.defender}</h3><p class="winner">${direction.labels.winner===null?'Match nul':'Vainqueur : '+direction.labels.winner}</p><p class="muted">${({elimination:'Élimination',surrender:'Reddition',round_limit:'Limite de rounds',initial_empty:'Armée vide'})[direction.result.reason]||direction.result.reason} · ${result.runtime.kind.toUpperCase()} · ${direction.result.replayHash.slice(0,12)}…</p><div class="camp-columns">${orderedSides(direction).map(side=>consequenceSummary(direction,side,result.costs)).join('')}</div><p class="muted economic-note">Coût des morts et blessés après compression, hors prisonniers.</p><button type="button" class="show-analysis" aria-expanded="false" aria-controls="duel-analysis">Analyse détaillée · ${direction.result.rounds.length} round(s)</button>`;
    const button=article.querySelector('.show-analysis');buttons.push(button);
    button.onclick=()=>{if(selected===direction){closeAnalysis();return}closeAnalysis();selected=direction;button.setAttribute('aria-expanded','true');analysis.innerHTML=`<div class="analysis-heading"><h3>Analyse détaillée · ${direction.labels.attacker} attaque ${direction.labels.defender}</h3><button type="button" class="close-analysis">Fermer l’analyse</button></div>${detailedCombat(direction)}<p class="muted">${escapeHtml(result.notice)}</p><details><summary>Résultat brut et provenance</summary><pre>${escapeHtml(JSON.stringify({report:direction.report,profileFingerprint:result.profileFingerprint,weather:result.weather,seed:result.seed,runtime:result.runtime},null,2))}</pre></details>`;mountRoundTimeline(analysis,direction);analysis.hidden=false;analysis.querySelector('.close-analysis').onclick=()=>{closeAnalysis();button.focus()};analysis.scrollIntoView({behavior:'smooth',block:'start'})};
    grid.append(article);
  }
  save();
}
function escapeHtml(s){return s.replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]))}
function download(name,data){const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([JSON.stringify(data,null,2)+'\n'],{type:'application/json'}));a.download=name;a.click();URL.revokeObjectURL(a.href)}
function makeZones(data){return data.rows.map(row=>({id:row.id,scenarioId:row.scenarioId,side:row.side,center:{x:row.winRate,y:row.rawCasualtyRatio},radii:{x:.05,y:.1},shape:'ellipse',approval:'draft',modelVersion:data.modelVersion,sourceFingerprint:data.profileFingerprint,context:data.context}))}
function renderBounds(){const root=$('#search-bounds');root.replaceChildren();const paths=[];for(const [type,unit] of Object.entries(profile.units))for(const field of ['attack','structure','baseAccuracy','defendingEfficiency']){const value=Number(unit[field]),accuracy=field==='baseAccuracy',defense=field==='defendingEfficiency',floor=field==='structure'?Math.min(.01,value):0;paths.push({id:`units.${type}.${field}`,label:`${types[type]} · ${{attack:"Attaque",structure:"Structure",baseAccuracy:"Précision (0 à 1)",defendingEfficiency:"Dégâts en défense"}[field]}`,value,minimum:accuracy||defense?0:Math.max(floor,Math.floor(value/4*100)/100),maximum:accuracy?1:defense?10:Math.min(1000,value===0?10:Math.ceil(value*4*100)/100)})}for(const acting of Object.keys(types))for(const target of Object.keys(types))if(acting!==target){const relation=profile.relations.find(r=>r.acting===acting&&r.target===target);paths.push({id:`relations.${acting}.${target}.factor`,label:`${types[acting]} → ${types[target]}`,value:Number(relation?.factor??1),minimum:0,maximum:10})}for(const path of paths){const label=document.createElement('label');label.innerHTML=`<span>${path.label} <small>(actuel ${path.value})</small></span><input data-bound="${path.id}" data-kind="minimum" type="number" step=".01" value="${path.minimum}"><input data-bound="${path.id}" data-kind="maximum" type="number" step=".01" value="${path.maximum}">`;root.append(label)}}
function collectBounds(){const result={};for(const input of $$('[data-bound]')){result[input.dataset.bound]??={};result[input.dataset.bound][input.dataset.kind]=Number(input.value).toFixed(2).replace(/\.00$/,'')}return result}
function renderZoneControls(){drawZones()}

function invalidateSearch(){
  searchGate.invalidate(); comparison=null; searchResult=null;
  if($('#search-results')?.children.length) $('#search-results').textContent='Recherche à recalculer : les réglages ou objectifs ont changé.';
  drawZones();
}
function searchSignature(){return WaarWorkshopModel.stable({profile,weather:activeWeather,zones,bounds:collectBounds(),context:measurement?.context})}
function drawZones(){
  $('#t27-editor')?.contentWindow?.postMessage({type:'waar-t27-comparison',rows:comparison?.observations?.rows||null},location.origin);
}
async function mountEditor(){
  const version=++editorLoad, requested=measurementState();
  const result=await api('editor',{profile,measurement,zones});
  if(version!==editorLoad||requested!==measurementState())return;
  editorFingerprint=result.fingerprint;
  $('#t27-editor').srcdoc=result.html;
}
function renderSearch(result){
  searchResult=result;lastSearch=structuredClone(result);comparison=null;
  $('#search-archive').hidden=false;
  const generations=result.generations?.map(g=>`<button data-generation="${g.number}">Voir G${g.number}</button> · ${g.best.metrics.inside}/32 · écart ${g.best.metrics.score.toFixed(4)}${g.number===1?' · départ':g.improved?' · amélioration':' · plateau'}`).join('<br>')||'';
  $('#search-results').innerHTML=`<h2>Optimisation</h2><p>${result.evaluated}/${result.candidateBudget} candidats · ${result.generations?.length||1} génération(s) · arrêt : ${result.stopReason||'budget'}</p><p class="muted">${generations}</p>`+result.candidates.slice(0,8).map(c=>`<div class="search-card"><strong>Candidat ${c.rank}</strong> · G${c.generation??1} · ${c.inside}/32 zones · écart ${c.score.toFixed(4)}<br><small>${c.fingerprint.slice(0,16)}… · ${c.operator||'variation'} · pire : ${c.worst.id}</small><br><button data-compare="${c.rank}">Comparer</button><button data-pick="${c.rank}">Utiliser ce profil</button><button data-export-candidate="${c.rank}">Exporter JSON</button></div>`).join('')+'<p class="muted">Classement sur les objectifs de cette mesure. Aucun candidat n’est approuvé automatiquement.</p>';
  const valid=()=>searchResult===result&&searchGate.accept(searchToken,searchSignature());
  $$('[data-compare]').forEach(button=>button.onclick=()=>{if(!valid()){invalidateSearch();return}comparison=result.candidates.find(c=>c.rank===Number(button.dataset.compare));drawZones();$('#t27-editor').scrollIntoView({block:'start'})});
  $$('[data-pick]').forEach(button=>button.onclick=()=>{if(!valid()){invalidateSearch();return}const chosen=result.candidates.find(c=>c.rank===Number(button.dataset.pick));profile=structuredClone(chosen.profile);renderAll();dirty();$('#notice').textContent='Candidat choisi comme brouillon. La dernière recherche et son profil de référence restent exportables.'});
  $$('[data-export-candidate]').forEach(button=>button.onclick=()=>{if(!valid()){invalidateSearch();return}const chosen=result.candidates.find(c=>c.rank===Number(button.dataset.exportCandidate));download(`candidat-waar-g${chosen.generation}-rang-${chosen.rank}.json`,chosen.profile)});
  $$('[data-generation]').forEach(button=>button.onclick=()=>{if(!valid()){invalidateSearch();return}const generation=result.generations.find(g=>g.number===Number(button.dataset.generation));comparison=result.candidates.find(c=>c.id===generation.best.candidateId);drawZones()});
  drawZones();
}
function selectView(id){
  if(!['units','relations','combat','weather','trial','expert'].includes(id))id='units';
  $('#live-duel').hidden=!['units','relations','weather','combat','trial'].includes(id);
  window.scrollTo?.({top:0,behavior:'instant'});
  $$('[data-view]').forEach(section=>section.hidden=section.id!==id);
  $$('.journey button').forEach(button=>{if(button.dataset.step===id)button.setAttribute('aria-current','page');else button.removeAttribute('aria-current')});
  if(id==='expert')$('#measure-context').textContent=`16 paires · 100 répétitions · météo ${weather[activeWeather]} · seed 42`;
}
function setupExpertTools(){
  const positionTooltip=event=>{
    const cell=event.target.closest?.('.impact-value');if(!cell)return;
    const tooltip=cell.querySelector('.impact-tooltip'),box=cell.getBoundingClientRect();
    tooltip.style.display='';tooltip.style.position='fixed';
    const size=tooltip.getBoundingClientRect();
    tooltip.style.left=Math.max(12,Math.min(box.left,window.innerWidth-size.width-12))+'px';
    tooltip.style.right='auto';
    tooltip.style.top=Math.max(12,box.bottom+size.height+8<window.innerHeight?box.bottom+4:box.top-size.height-4)+'px';
  };
  document.addEventListener('pointerover',positionTooltip);
  document.addEventListener('focusin',positionTooltip);
  document.addEventListener('keydown',event=>{if(event.key==='Escape'){const cell=event.target.closest?.('.impact-value');if(cell){cell.querySelector('.impact-tooltip').style.display='none';cell.blur()}}});
  $('#export-search').onclick=()=>{if(lastSearch)download('recherche-waar.json',lastSearch)};
  $('#restore-reference').onclick=()=>{if(lastSearch&&confirm('Reprendre le profil de référence ?')){profile=structuredClone(lastSearch.referenceProfile);renderAll();dirty()}};
  $('#search-bounds').addEventListener('input',invalidateSearch);
  document.addEventListener('input',e=>{if(e.target.closest('main'))invalidateSearch()});
  $('#duel-dialog').addEventListener('close',()=>$('#test-host').append($('#test-content')));
  $$('[data-go]').forEach(button=>button.onclick=()=>selectView(button.dataset.go));
  window.addEventListener('message',event=>{
    const frame=$('#t27-editor');
    if(event.source!==frame.contentWindow||event.origin!==location.origin||event.data?.fingerprint!==editorFingerprint||!measurement)return;
    if(event.data.type==='waar-t27-ready'){drawZones();return}
    if(event.data.type!=='waar-t27-zones')return;
    invalidateSearch();
    const doc=event.data.document;
    if(doc?.schemaVersion!=='waar-consequence-editor-zones/0.1'||doc.corpusFingerprint!==editorFingerprint){zones=[];$('#measure-progress').textContent='Import incompatible : réancrez les zones dans l’éditeur.';return}
    const mapped=[];
    for(const edited of doc.zones){
      const source=originalZones.find(z=>z.id===edited.id);
      if(!source||edited.source.referenceId!==measurement.profileFingerprint){zones=[];return}
      mapped.push({...source,center:structuredClone(edited.center),radii:structuredClone(edited.radii),enabled:edited.enabled,approval:'draft'});
    }
    zones=mapped;
    $('#measure-progress').textContent=`${zones.length} objectifs · modifications conservées`;
  });
}
function renderModifierSummary(){const total=armyModifiers.A.length+armyModifiers.B.length;$('#modifier-summary').textContent=total?`${armyModifiers.A.length} effet(s) pour A · ${armyModifiers.B.length} pour B`:'Aucun effet acquis. Seule la météo sélectionnée sera appliquée.'}
function renderAll(){renderUnits();renderRelations();renderWeather();renderCombat();renderArmies();renderProfileMeta();renderModifierSummary()}
async function init(){let stored=null,d=null;try{stored=localStorage.getItem('waar-workshop-draft-v1');d=stored?JSON.parse(stored):null}catch{$('#notice').textContent='Stockage indisponible : utilisez la sauvegarde JSON.'}try{if(d?.profile){const migrated=await api('migrate-profile',{profile:d.profile});profile=migrated.profile;armies=d.armies||armies;activeWeather=weather[d.activeWeather]?d.activeWeather:'neutral';duelWeather=weather[d.duelWeather]?d.duelWeather:(d.duelWeather?.A===d.duelWeather?.B&&weather[d.duelWeather?.A]?d.duelWeather.A:'neutral');armyModifiers=d.armyModifiers||armyModifiers;staleZones=d.staleZones||[];if(migrated.migration.performed)$('#notice').textContent='Profil local migré vers le moteur cohortes. Les anciens résultats sont obsolètes ; les nouveaux réglages restent à confirmer.'}else profile=(await api('default-profile')).profile}catch{profile=(await api('default-profile')).profile}duelWeather=activeWeather;await restoreProfileBaseline(d);renderAll();setupExpertTools();setupArmyShortcuts();setupSavedProfiles();liveReady=true;scheduleLiveDuel();
  $$('.journey button').forEach(b=>b.onclick=()=>selectView(b.dataset.step));$$('[data-open-duel]').forEach(b=>b.onclick=openDuel);
  $('#prefill').onclick=async()=>{if(!confirm('Remplacer les quatre fiches par les valeurs proposées ? Les autres réglages seront conservés.'))return;const defaults=(await api('default-profile')).profile;profile=WaarWorkshopModel.prefillUnits(profile,defaults);renderAll();dirty()};$('#add-relation').onclick=()=>{const acting=selectedRelationUnit,target=$('#relation-target').value,factor=$('#relation-factor').value;if(acting===target){$('#notice').textContent='Une unité reste neutre contre elle-même dans cette V1.';return}profile.relations=profile.relations.filter(r=>!(r.acting===acting&&r.target===target));if(factor!=='1')profile.relations.push({acting,target,factor});renderRelations();dirty()};
  $('#simulate').onclick=async()=>{const id=String(++currentRequest),configurationSignature=duelSignature();errors([]);$('#simulate').disabled=true;try{const response=await api('duel',{requestId:id,profile,armies,weather:duelWeather,modifiers:armyModifiers,seed:Number($('#duel-seed').value)});if(WaarWorkshopModel.responseIsCurrent(response,id,configurationSignature,duelSignature()))renderDuel(response);else $('#duel-stale').classList.remove('hidden')}catch(e){if(id===String(currentRequest))errors(e.errors)}finally{if(id===String(currentRequest))$('#simulate').disabled=false}};
  $('#download-profile').onclick=async()=>{try{const validation=await api('validate',{profile,mode:'draft'});if(validation.errors.length)throw Object.assign(new Error(),{errors:validation.errors});download((profile.label||'profil-waar-cohortes').replace(/[^a-zA-Z0-9_-]/g,'-')+'.json',profile)}catch(err){$('#notice').textContent='Sauvegarde refusée : '+(err.errors?.map(error=>error.message).join(' ')||err.message)}};
  $('#demo-modifiers').onclick=()=>{armyModifiers={A:[{source:'training',id:'archer-drill',label:'Formation archers',unitType:'archer',parameter:'baseAccuracy',operation:'multiply',value:'1.2'},{source:'training',id:'knight-discipline',label:'Discipline chevaliers',unitType:'knight',parameter:'accuracySpread',operation:'multiply',value:'0.5'}],B:[{source:'training',id:'shield-wall',label:'Mur de boucliers',unitType:'spearman',parameter:'defendingEfficiency',operation:'multiply',value:'1.1'}]};renderModifierSummary();dirty()};$('#clear-modifiers').onclick=()=>{armyModifiers={A:[],B:[]};renderModifierSummary();dirty()};$('#import-modifiers').onchange=async e=>{const file=e.target.files[0];if(!file)return;try{const candidate=JSON.parse(await file.text());if(!candidate||!Array.isArray(candidate.A)||!Array.isArray(candidate.B))throw new Error('Objet attendu avec deux listes A et B.');armyModifiers=candidate;renderModifierSummary();dirty();$('#notice').textContent='Effets acquis importés ; ils seront appliqués avant la météo.'}catch(err){$('#notice').textContent='Import des effets refusé : '+err.message}e.target.value=''};
  $('#start-expert').onclick=()=>selectView('expert');
  $('#measure').onclick=async()=>{invalidateSearch();$('#measure').disabled=true;$('#measure-progress').textContent='Mesure native en cours…';const requested=measurementState();try{const response=await api('measure',{profile,weather:activeWeather,seed:42,iterations:100});if(requested!==measurementState()){$('#measure-progress').textContent='Mesure terminée pour une ancienne configuration. Relancez-la pour le profil courant.';return}measurement=response;measurementSignature=requested;const fresh=makeZones(measurement),reuse=staleZones.length===fresh.length&&confirm('Réassocier explicitement la géométrie des anciennes zones à cette nouvelle mesure ?');zones=reuse?fresh.map(zone=>{const previous=staleZones.find(old=>old.id===zone.id);return previous?{...zone,center:structuredClone(previous.center),radii:structuredClone(previous.radii),previousProvenance:{modelVersion:previous.modelVersion,sourceFingerprint:previous.sourceFingerprint,context:previous.context}}:zone}):fresh;staleZones=[];originalZones=structuredClone(zones);$('#zone-editor').classList.remove('hidden');$('#zones-empty').hidden=true;renderBounds();await mountEditor();$('#measure-progress').textContent=`32 observations · Rust · ${reuse?'géométrie réassociée explicitement':'objectifs éditables à la souris'}`;}catch(e){$('#measure-progress').textContent=e.errors?.map(x=>x.message).join(' ')||e.message}finally{$('#measure').disabled=false}};
  $('#export-zones').onclick=()=>download('zones-waar.json',{schemaVersion:'waar-consequence-acceptance-zones/0.1',profileFingerprint:measurement.profileFingerprint,context:measurement.context,zones});
  $('#search').onclick=async()=>{
    if(!measurement||!confirm('Figer ces 32 zones et optimiser jusqu’à 32 candidats (51 200 combats) ?'))return;
    invalidateSearch();searchToken=searchGate.capture(searchSignature());
    const token=searchToken;
    $('#search').disabled=true;$('#search-results').textContent='Optimisation de 4 générations en cours…';
    try{
      const response=await api('optimize',{profile,zones,bounds:collectBounds(),weather:activeWeather,seed:314159,measurementBaseSeed:measurement.context.baseSeed,budget:32,iterations:measurement.context.iterations});
      if(!searchGate.accept(token,searchSignature())){$('#search-results').textContent='Résultat ignoré : les réglages ou objectifs ont changé pendant la recherche.';return}
      renderSearch(response);
    }catch(e){if(searchGate.accept(token,searchSignature()))$('#search-results').textContent=e.errors?.map(x=>x.message).join(' ')||e.message}
    finally{$('#search').disabled=false}
  };
  document.addEventListener('input',e=>{if(e.target.id==='duel-seed')dirty()});
}
init().catch(e=>{$('#notice').textContent='Impossible d’initialiser la soufflerie : '+e.message});
})();
