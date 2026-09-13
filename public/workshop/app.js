(() => {
'use strict';
const types={soldier:'Soldat',spearman:'Lancier',archer:'Archer',knight:'Chevalier'}, costs={soldier:80,spearman:110,archer:130,knight:350}, weather={neutral:'Neutre',rain:'Pluie',snow:'Neige',heat:'Chaleur'};
let profile, armies={A:{soldier:100,spearman:0,archer:0,knight:0},B:{soldier:100,spearman:0,archer:0,knight:0}}, activeWeather='neutral', zones=[], measurement=null, measurementSignature='', currentRequest=0, resultSignature='';
const searchGate=WaarWorkshopModel.createRevisionGate();
let searchResult=null, searchToken=null, comparison=null, lastSearch=null;
const $=s=>document.querySelector(s), $$=s=>[...document.querySelectorAll(s)];
async function api(path,body){const response=await fetch('/api/'+path,{method:body?'POST':'GET',headers:body?{'Content-Type':'application/json'}:{},body:body?JSON.stringify(body):undefined});const json=await response.json();if(!response.ok)throw Object.assign(new Error(json.errors?.[0]?.message||'Erreur'),{errors:json.errors||[]});return json.data}
function duelSignature(){return JSON.stringify([profile,armies,$('#duel-weather')?.value,$('#duel-seed')?.value])}
function measurementState(){return JSON.stringify([profile,activeWeather])}
function dirty(){save();invalidateSearch();if(WaarWorkshopModel.stale(resultSignature,duelSignature()))$('#duel-stale').classList.remove('hidden');if(measurement&&measurementSignature!==measurementState()){measurement=null;zones=[];$('#zone-editor').classList.add('hidden');$('#search-results').replaceChildren();$('#measure-progress').textContent='Le profil ou la météo a changé : les observations ont été invalidées. Relancez la mesure.'}}
function save(){try{localStorage.setItem('waar-workshop-draft-v1',JSON.stringify({profile,armies,activeWeather}))}catch{$('#notice').textContent='Le stockage local est indisponible. Utilisez la sauvegarde JSON.'}}
function value(path,value){const parts=path.split('.');let cursor=profile;for(let i=0;i<parts.length-1;i++)cursor=cursor[parts[i]];cursor[parts.at(-1)]=value;dirty()}
function renderUnits(){const grid=$('#unit-grid');grid.replaceChildren();for(const [type,label] of Object.entries(types)){const u=profile.units[type];const card=document.createElement('article');card.className='unit-card';card.dataset.unit=type;card.innerHTML=`<h3>${label}</h3><div class="fields"><label>Attaque<input data-field="attack" inputmode="decimal" value="${u?.attack??''}"></label><label>Structure<input data-field="structure" inputmode="decimal" value="${u?.structure??''}"></label><label>Personnalité défensive<input data-field="defendingEfficiency" inputmode="decimal" value="${u?.defendingEfficiency??''}"></label><label>Coût<input value="${costs[type]}" disabled></label></div><div class="role-buttons"><button data-role="0.75">Offensif</button><button data-role="1">Neutre</button><button data-role="1.25">Défensif</button></div><label><input data-field="capturable" type="checkbox" ${u?.capturable?'checked':''}> Cette unité peut être capturée</label><p class="muted">Sous 1 : moins puissante en défense · 1 : neutre · au-dessus : plus puissante en défense.</p>`;
      card.querySelectorAll('[data-field]').forEach(input=>input.addEventListener('change',()=>{if(!profile.units[type])profile.units[type]={cost:costs[type],capturable:false};value(`units.${type}.${input.dataset.field}`,input.type==='checkbox'?input.checked:input.value)}));card.querySelectorAll('[data-role]').forEach(button=>button.addEventListener('click',()=>{if(!profile.units[type])profile.units[type]={cost:costs[type],capturable:false};profile.units[type].defendingEfficiency=button.dataset.role;renderUnits();dirty()}));grid.append(card)}
}
function fillSelect(select){select.replaceChildren();for(const [value,label] of Object.entries(types))select.add(new Option(label,value))}
function renderRelations(){const list=$('#relation-list');list.replaceChildren();if(!profile.relations.length)list.textContent='Aucune relation : tous les dégâts sont à ×1.';profile.relations.forEach((r,i)=>{const el=document.createElement('span');el.className='chip';el.innerHTML=`${types[r.acting]} → ${types[r.target]} : dégâts ×${r.factor} <button aria-label="Supprimer cette relation">✕</button>`;el.querySelector('button').onclick=()=>{profile.relations.splice(i,1);renderRelations();dirty()};list.append(el)})}
function renderWeather(){const tabs=$('#weather-tabs');tabs.replaceChildren();for(const [id,label] of Object.entries(weather)){const button=document.createElement('button');button.textContent=label;button.classList.toggle('active',id===activeWeather);button.onclick=()=>{activeWeather=id;renderWeather();dirty()};tabs.append(button)}const grid=$('#weather-grid');grid.replaceChildren();for(const [type,label] of Object.entries(types)){const l=document.createElement('label');l.innerHTML=`${label}<input value="${profile.weather[activeWeather][type]}" inputmode="decimal" aria-label="${label}, ${weather[activeWeather]}">`;l.querySelector('input').onchange=e=>value(`weather.${activeWeather}.${type}`,e.target.value);grid.append(l)}
}
function renderCombat(){for(const input of $$('[data-combat]')){const key=input.dataset.combat;input.value=profile.combat[key];input.oninput=()=>{profile.combat[key]=Number(input.value);renderExample();dirty()}}renderExample()}
function renderExample(){const c=profile.combat.lossCompressionPercent,p=profile.combat.capturePercent;$('#compression-out').textContent=c+' %';$('#capture-out').textContent=p+' %';const lost=Math.floor(1000*c/100),remaining=1000-lost,captured=Math.floor(remaining*p/100);$('#consequence-example').textContent=`Exemple : sur 1 000 unités et 1 000 pertes brutes, ${lost} pertes sont appliquées ; il reste ${remaining} survivants avant capture, puis ${captured} prisonniers si le type vaincu est capturable et ${remaining-captured} unités libres.`}
function renderArmies(){for(const camp of ['A','B']){const root=$('#army-'+camp.toLowerCase());root.replaceChildren();for(const [type,label] of Object.entries(types)){const row=document.createElement('label');row.className='army-row';row.innerHTML=`<span>${label}</span><input type="range" min="0" max="1000" value="${armies[camp][type]}"><input type="number" min="0" max="10000" value="${armies[camp][type]}">`;const range=row.children[1],number=row.children[2];range.oninput=()=>{number.value=range.value;armies[camp][type]=Number(range.value);renderCosts();dirty()};number.oninput=()=>{const n=Math.max(0,Math.min(10000,Number(number.value)||0));range.value=Math.min(1000,n);armies[camp][type]=n;renderCosts();dirty()};root.append(row)}}renderCosts()}
function renderCosts(){for(const camp of ['A','B'])$('#cost-'+camp.toLowerCase()).textContent=WaarWorkshopModel.cost(armies[camp],costs).toLocaleString('fr-FR')}
function renderProfileMeta(){for(const [selector,key] of [['#profile-label','label'],['#profile-id','id']]){const input=$(selector);input.value=profile[key];input.onchange=()=>value(key,input.value)}$('#profile-version').textContent=profile.schemaVersion}
function openDuel(){renderArmies();$('#duel-dialog').showModal();setTimeout(()=>$('#duel-dialog input')?.focus(),0)}
function errors(values){const root=$('#duel-errors');root.replaceChildren();for(const error of values||[]){const p=document.createElement('p'),strong=document.createElement('strong');strong.textContent=error.path||'Erreur';p.append(strong,' — '+error.message);if(error.code==='missing_unit'){const type=error.path.split('.')[1],button=document.createElement('button');button.type='button';button.textContent='Configurer '+(types[type]||type);button.onclick=()=>{$('#duel-dialog').close();document.querySelector(`[data-unit="${type}"]`)?.scrollIntoView({behavior:'smooth'});};p.append(' ',button)}root.append(p)}}
function consequenceTable(direction,side,label){const data=direction.consequences[side],rows=Object.entries(data.units).filter(([,u])=>u.initial>0).map(([type,u])=>`<tr><td>${types[type]}</td><td>${u.initial}</td><td>${u.rawLosses}</td><td>${u.appliedLosses}</td><td>${u.prisoners}</td><td>${u.free}</td></tr>`).join('');return `<h4>Armée ${label}</h4><table><thead><tr><th>Unité</th><th>Initial</th><th>Pertes brutes</th><th>Pertes appliquées</th><th>Prisonniers</th><th>Libres</th></tr></thead><tbody>${rows}<tr class="totals"><th>Total</th><td></td><td></td><td>${data.totals.appliedLosses}</td><td>${data.totals.prisoners}</td><td>${data.totals.free}</td></tr></tbody></table>`}
function renderDuel(result){$('#duel-stale').classList.add('hidden');resultSignature=duelSignature();const root=$('#duel-results');root.innerHTML='<p class="muted">'+result.notice+'</p><div class="result-grid"></div>';const grid=root.querySelector('.result-grid');for(const direction of result.directions){const article=document.createElement('article');article.className='result';article.innerHTML=`<h3>${direction.labels.attacker} attaque ${direction.labels.defender}</h3><p><strong>Vainqueur : ${direction.labels.winner??'nul'}</strong> · ${direction.raw.roundsPlayed} round(s)</p>${consequenceTable(direction,'attacker',direction.labels.attacker)}${consequenceTable(direction,'defender',direction.labels.defender)}<details><summary>Résultat brut et provenance</summary><pre>${escapeHtml(JSON.stringify({raw:direction.raw,parameters:direction.parameters,profileFingerprint:result.profileFingerprint,weather:result.weather,seed:result.seed},null,2))}</pre></details>`;grid.append(article)}save()}
function escapeHtml(s){return s.replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]))}
function download(name,data){const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([JSON.stringify(data,null,2)+'\n'],{type:'application/json'}));a.download=name;a.click();URL.revokeObjectURL(a.href)}
function makeZones(data){return data.rows.map(row=>({id:row.id,scenarioId:row.scenarioId,side:row.side,center:{x:row.winRate,y:row.appliedLossRatio},radii:{x:.05,y:.1},shape:'ellipse',approval:'draft',modelVersion:data.modelVersion,sourceFingerprint:data.profileFingerprint,context:data.context}))}
function renderBounds(){const root=$('#search-bounds');root.replaceChildren();const paths=[];for(const [type,unit] of Object.entries(profile.units))for(const field of ['attack','structure','defendingEfficiency'])paths.push({id:`units.${type}.${field}`,label:`${types[type]} · ${field}`,value:Number(unit[field]),floor:field==='structure'?.01:0,ceiling:field==='defendingEfficiency'?10:1000});for(const relation of profile.relations)paths.push({id:`relations.${relation.acting}.${relation.target}.factor`,label:`${types[relation.acting]} → ${types[relation.target]}`,value:Number(relation.factor),floor:0,ceiling:10});for(const path of paths){const min=Math.max(path.floor,Math.round(path.value*.8*100)/100),max=Math.min(path.ceiling,Math.round(path.value*1.2*100)/100),label=document.createElement('label');label.innerHTML=`<span>${path.label} <small>(actuel ${path.value})</small></span><input data-bound="${path.id}" data-kind="minimum" type="number" step=".01" value="${path.value===0?0:min}"><input data-bound="${path.id}" data-kind="maximum" type="number" step=".01" value="${path.value===0?0:max}">`;root.append(label)}}
function collectBounds(){const result={};for(const input of $$('[data-bound]')){result[input.dataset.bound]??={};result[input.dataset.bound][input.dataset.kind]=Number(input.value).toFixed(2).replace(/\.00$/,'')}return result}
function renderZoneControls(){const zone=zones.find(z=>z.id===$('#zone-select').value)||zones[0];if(!zone)return;$('#zone-select').value=zone.id;for(const [id,v] of [['#zone-x',zone.center.x*100],['#zone-y',zone.center.y*100],['#zone-rx',zone.radii.x*100],['#zone-ry',zone.radii.y*100]])$(id).value=Number(v.toFixed(3));drawZones()}

function invalidateSearch(){
  searchGate.invalidate(); comparison=null; searchResult=null;
  if($('#search-results')?.children.length) $('#search-results').textContent='Recherche à recalculer : les réglages ou objectifs ont changé.';
  drawZones();
}
function searchSignature(){return WaarWorkshopModel.stable({profile,weather:activeWeather,zones,bounds:collectBounds(),context:measurement?.context})}
function drawZones(){
  const canvas=$('#zone-chart'); if(!canvas)return;
  const ctx=canvas.getContext('2d'),pad=48,w=canvas.width-pad*2,h=canvas.height-pad*2;
  const xy=p=>[pad+p.x*w,pad+(1-p.y)*h];
  ctx.clearRect(0,0,canvas.width,canvas.height);
  ctx.strokeStyle='#d8ddd7';ctx.fillStyle='#607078';ctx.font='12px sans-serif';
  for(let i=0;i<=10;i++){const x=pad+w*i/10,y=pad+h*i/10;ctx.beginPath();ctx.moveTo(x,pad);ctx.lineTo(x,pad+h);ctx.moveTo(pad,y);ctx.lineTo(pad+w,y);ctx.stroke();ctx.fillText(i*10+'',x-7,pad+h+18);ctx.fillText((100-i*10)+'',18,y+4)}
  const selected=$('#zone-select').value;
  for(const z of zones){
    if(![z.center.x,z.center.y,z.radii.x,z.radii.y].every(Number.isFinite)||z.radii.x<=0||z.radii.y<=0)continue;
    const [cx,cy]=xy(z.center);ctx.beginPath();ctx.ellipse(cx,cy,z.radii.x*w,z.radii.y*h,0,0,Math.PI*2);
    ctx.fillStyle=z.id===selected?'#087d7f26':'#d89a3214';ctx.strokeStyle=z.id===selected?'#087d7f':'#c7a15d';ctx.fill();ctx.stroke();
  }
  const rows=measurement?WaarWorkshopModel.plotRows(measurement,comparison?.observations):[];
  for(const row of rows){
    const [x,y]=xy(row.reference);ctx.fillStyle=row.id===selected?'#18272d':'#667780';ctx.fillRect(x-3,y-3,6,6);
    if(row.candidate){
      const [tx,ty]=xy(row.candidate),angle=Math.atan2(ty-y,tx-x);
      ctx.strokeStyle='#783caf';ctx.beginPath();ctx.moveTo(x,y);ctx.lineTo(tx,ty);ctx.stroke();
      if(Math.hypot(tx-x,ty-y)>1){ctx.beginPath();ctx.moveTo(tx,ty);ctx.lineTo(tx-7*Math.cos(angle-.5),ty-7*Math.sin(angle-.5));ctx.moveTo(tx,ty);ctx.lineTo(tx-7*Math.cos(angle+.5),ty-7*Math.sin(angle+.5));ctx.stroke()}
      ctx.fillStyle='#783caf';ctx.beginPath();ctx.arc(tx,ty,4,0,Math.PI*2);ctx.fill();
    }
  }
  const current=rows.find(row=>row.id===selected);
  if($('#observation-values'))$('#observation-values').textContent=current
    ?'Observation de référence : victoire '+(current.reference.x*100).toFixed(3)+' %, pertes '+(current.reference.y*100).toFixed(3)+' %.'+
      (current.candidate?' Candidat comparé : victoire '+(current.candidate.x*100).toFixed(3)+' %, pertes '+(current.candidate.y*100).toFixed(3)+' %.':' Aucun candidat comparé.')
    :'Aucune observation.';
  ctx.fillStyle='#18272d';ctx.fillText('Taux de victoire →',canvas.width/2-50,canvas.height-8);ctx.save();ctx.translate(12,canvas.height/2+30);ctx.rotate(-Math.PI/2);ctx.fillText('Pertes appliquées →',0,0);ctx.restore();
}
function renderSearch(result){
  searchResult=result;lastSearch=structuredClone(result);comparison=null;
  $('#search-archive').hidden=false;
  $('#search-results').innerHTML='<h3>Candidats observés</h3>'+result.candidates.map(c=>`<div class="search-card"><strong>Rang descriptif ${c.rank}</strong> · ${c.inside}/32 zones · écart ${c.score.toFixed(4)}<br><small>${c.fingerprint.slice(0,16)}… · pire : ${c.worst.id}</small><br><button data-compare="${c.rank}">Comparer les observations</button><button data-pick="${c.rank}">Choisir comme nouveau brouillon</button></div>`).join('')+'<p class="muted">Carrés : référence. Disques violets : candidat comparé. Flèches : référence → candidat. Aucun candidat n’est approuvé.</p>';
  const valid=()=>searchResult===result&&searchGate.accept(searchToken,searchSignature());
  $$('[data-compare]').forEach(button=>button.onclick=()=>{if(!valid()){invalidateSearch();return}comparison=result.candidates.find(c=>c.rank===Number(button.dataset.compare));drawZones();$('#zone-chart').scrollIntoView({block:'center'})});
  $$('[data-pick]').forEach(button=>button.onclick=()=>{if(!valid()){invalidateSearch();return}const chosen=result.candidates.find(c=>c.rank===Number(button.dataset.pick));profile=structuredClone(chosen.profile);renderAll();dirty();$('#notice').textContent='Candidat choisi comme brouillon. La dernière recherche et son profil de référence restent exportables.'});
  drawZones();
}
function setupExpertTools(){
  const chart=$('#zone-chart'),tools=document.createElement('div');
  tools.innerHTML='<p>Ellipses : objectifs. Carrés : observations de référence. Disques et flèches violets : candidat comparé. Glissez sur le graphique pour dessiner la zone sélectionnée ; les champs restent disponibles au clavier.</p><p id="observation-values" role="status"></p><label class="file">Importer des zones<input id="import-zones" type="file" accept="application/json"></label>';
  chart.before(tools);chart.style.touchAction='none';
  const archive=document.createElement('div');archive.id='search-archive';archive.hidden=true;
  archive.innerHTML='<p>Dernière recherche conservée (peut concerner un ancien profil).</p><button id="export-search">Exporter la recherche</button><button id="restore-reference">Reprendre son profil de référence</button>';
  $('#expert').append(archive);
  $('#export-search').onclick=()=>{if(lastSearch)download('recherche-waar.json',lastSearch)};
  $('#restore-reference').onclick=()=>{if(lastSearch&&confirm('Reprendre le profil de référence de la dernière recherche ?')){profile=structuredClone(lastSearch.referenceProfile);renderAll();dirty()}};
  $('#search-bounds').addEventListener('input',invalidateSearch);
  document.addEventListener('input',e=>{if(e.target.closest('main'))invalidateSearch()});
  $('#import-zones').onchange=async e=>{
    const file=e.target.files[0];if(!file)return;
    const requested=searchGate.capture(searchSignature());
    try{
      const doc=JSON.parse(await file.text());
      if(!measurement||doc.schemaVersion!=='waar-consequence-acceptance-zones/0.1'||doc.profileFingerprint!==measurement.profileFingerprint||WaarWorkshopModel.stable(doc.context)!==WaarWorkshopModel.stable(measurement.context))throw new Error('Provenance des zones incompatible.');
      const validated=await api('validate-zones',{profile,zones:doc.zones,weather:activeWeather,measurementBaseSeed:measurement.context.baseSeed,iterations:measurement.context.iterations});
      if(!searchGate.accept(requested,searchSignature()))throw new Error('Le contexte a changé pendant l’import.');
      zones=validated.zones;invalidateSearch();renderZoneControls();
    }catch(error){$('#notice').textContent='Import des zones refusé : '+error.message}
    e.target.value='';
  };
  let drag=null;
  const position=e=>{const r=chart.getBoundingClientRect();return {x:Math.max(0,Math.min(1,((e.clientX-r.left)*chart.width/r.width-48)/(chart.width-96))),y:Math.max(0,Math.min(1,1-((e.clientY-r.top)*chart.height/r.height-48)/(chart.height-96)))}};
  chart.onpointerdown=e=>{if(e.button!==0||!zones.length)return;const zone=zones.find(z=>z.id===$('#zone-select').value);drag={start:position(e),zone,previous:structuredClone(zone)};chart.setPointerCapture(e.pointerId)};
  chart.onpointermove=e=>{if(!drag)return;const p=position(e);drag.zone.center={x:(p.x+drag.start.x)/2,y:(p.y+drag.start.y)/2};drag.zone.radii={x:Math.max(.001,Math.abs(p.x-drag.start.x)/2),y:Math.max(.001,Math.abs(p.y-drag.start.y)/2)};invalidateSearch();renderZoneControls()};
  chart.onpointerup=()=>{drag=null};
  chart.onpointercancel=()=>{if(drag){Object.assign(drag.zone,drag.previous);drag=null;invalidateSearch();renderZoneControls()}};
}
function renderAll(){renderUnits();renderRelations();renderWeather();renderCombat();renderArmies();renderProfileMeta()}
async function init(){let stored=null;try{stored=localStorage.getItem('waar-workshop-draft-v1')}catch{$('#notice').textContent='Stockage indisponible : utilisez la sauvegarde JSON.'}try{const d=stored?JSON.parse(stored):null;if(d?.profile){profile=d.profile;armies=d.armies||armies;activeWeather=d.activeWeather||'neutral'}else profile=(await api('default-profile')).profile}catch{profile=(await api('default-profile')).profile}fillSelect($('#relation-acting'));fillSelect($('#relation-target'));for(const [id,label] of Object.entries(weather))$('#duel-weather').add(new Option(label,id));renderAll();setupExpertTools();
  $$('.journey button').forEach(b=>b.onclick=()=>document.getElementById(b.dataset.step).scrollIntoView({behavior:'smooth'}));$('#open-duel').onclick=openDuel;$$('[data-open-duel]').forEach(b=>b.onclick=openDuel);
  $('#prefill').onclick=async()=>{if(!confirm('Remplacer les quatre fiches par les valeurs proposées ? Les autres réglages seront conservés.'))return;const defaults=(await api('default-profile')).profile;profile=WaarWorkshopModel.prefillUnits(profile,defaults);renderAll();dirty()};$('#add-relation').onclick=()=>{const acting=$('#relation-acting').value,target=$('#relation-target').value,factor=$('#relation-factor').value;if(acting===target){$('#notice').textContent='Une unité reste neutre contre elle-même dans cette V1.';return}profile.relations=profile.relations.filter(r=>!(r.acting===acting&&r.target===target));if(factor!=='1')profile.relations.push({acting,target,factor});renderRelations();dirty()};
  $('#simulate').onclick=async()=>{const id=String(++currentRequest),configurationSignature=duelSignature();errors([]);$('#simulate').disabled=true;try{const response=await api('duel',{requestId:id,profile,armies,weather:$('#duel-weather').value,seed:Number($('#duel-seed').value)});if(WaarWorkshopModel.responseIsCurrent(response,id,configurationSignature,duelSignature()))renderDuel(response);else $('#duel-stale').classList.remove('hidden')}catch(e){if(id===String(currentRequest))errors(e.errors)}finally{if(id===String(currentRequest))$('#simulate').disabled=false}};
  $('#download-profile').onclick=async()=>{try{const validation=await api('validate',{profile,mode:'draft'});if(validation.errors.length)throw Object.assign(new Error(),{errors:validation.errors});download('profil-waar.json',profile)}catch(err){$('#notice').textContent='Sauvegarde refusée : '+(err.errors?.map(error=>error.message).join(' ')||err.message)}};$('#import-profile').onchange=async e=>{const file=e.target.files[0];if(!file)return;try{const candidate=JSON.parse(await file.text());const validation=await api('validate',{profile:candidate,mode:'draft'});if(validation.errors.length)throw Object.assign(new Error(),{errors:validation.errors});profile=candidate;renderAll();dirty();$('#notice').textContent='Profil importé.'}catch(err){$('#notice').textContent='Import refusé : '+(err.errors?.[0]?.message||'JSON invalide')};e.target.value=''};$('#reset-profile').onclick=async()=>{if(confirm('Réinitialiser ce profil et ses compositions ?')){profile=(await api('default-profile')).profile;armies={A:{soldier:100,spearman:0,archer:0,knight:0},B:{soldier:100,spearman:0,archer:0,knight:0}};activeWeather='neutral';$('#duel-weather').value='neutral';renderAll();dirty()}};
  $('#start-expert').onclick=()=>{$('#expert').classList.remove('hidden');$('#expert').scrollIntoView({behavior:'smooth'});$('#measure-context').textContent=`16 scénarios × 100 répétitions = 1 600 combats · météo ${weather[activeWeather]} · seed 42 · conséquences calculées combat par combat.`};
  $('#measure').onclick=async()=>{invalidateSearch();$('#measure').disabled=true;$('#measure-progress').textContent='Mesure en cours…';const requested=measurementState();try{const response=await api('measure',{profile,weather:activeWeather,seed:42,iterations:100});if(requested!==measurementState()){$('#measure-progress').textContent='Mesure terminée pour une ancienne configuration. Relancez-la pour le profil courant.';return}measurement=response;measurementSignature=requested;zones=makeZones(measurement);const select=$('#zone-select');select.replaceChildren();for(const z of zones)select.add(new Option(z.scenarioId+' · '+(z.side==='attacker'?'attaquant':'défenseur'),z.id));$('#zone-editor').classList.remove('hidden');renderBounds();renderZoneControls();$('#measure-progress').textContent='32 observations produites. Les zones sont des brouillons à confirmer.'}catch(e){$('#measure-progress').textContent=e.errors?.map(x=>x.message).join(' ')||e.message}finally{$('#measure').disabled=false}};
  $('#zone-select').onchange=renderZoneControls;for(const [id,path] of [['#zone-x',['center','x']],['#zone-y',['center','y']],['#zone-rx',['radii','x']],['#zone-ry',['radii','y']]])$(id).onchange=e=>{const z=zones.find(x=>x.id===$('#zone-select').value);z[path[0]][path[1]]=Number(e.target.value)/100;invalidateSearch();drawZones()};$('#export-zones').onclick=()=>download('zones-waar.json',{schemaVersion:'waar-consequence-acceptance-zones/0.1',profileFingerprint:measurement.profileFingerprint,context:measurement.context,zones});
  $('#search').onclick=async()=>{
    if(!measurement||!confirm('Figer ces 32 zones pour une recherche de 8 candidats ?'))return;
    invalidateSearch();searchToken=searchGate.capture(searchSignature());
    const token=searchToken;
    $('#search').disabled=true;$('#search-results').textContent='Recherche bornée en cours…';
    try{
      const response=await api('search',{profile,zones,bounds:collectBounds(),weather:activeWeather,seed:314159,measurementBaseSeed:measurement.context.baseSeed,budget:8,iterations:measurement.context.iterations});
      if(!searchGate.accept(token,searchSignature())){$('#search-results').textContent='Résultat ignoré : les réglages ou objectifs ont changé pendant la recherche.';return}
      renderSearch(response);
    }catch(e){if(searchGate.accept(token,searchSignature()))$('#search-results').textContent=e.errors?.map(x=>x.message).join(' ')||e.message}
    finally{$('#search').disabled=false}
  };
  document.addEventListener('input',e=>{if(e.target.closest('#duel-dialog')&&resultSignature&&e.target.id!=='simulate')dirty()});
}
init().catch(e=>{$('#notice').textContent='Impossible d’initialiser la soufflerie : '+e.message});
})();
