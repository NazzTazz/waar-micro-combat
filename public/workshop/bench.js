(() => {
'use strict';
const M=WaarWorkshopModel;
const types={soldier:'Soldats',spearman:'Lanciers',archer:'Archers',knight:'Chevaliers'};
const number=n=>Number(n).toLocaleString('fr-FR',{maximumFractionDigits:2});
const decimal=n=>Number(n).toLocaleString('fr-FR',{maximumFractionDigits:6});
const percent=n=>Number(n)>0&&Number(n)<0.0001?'< 0,01 %':number(Number(n)*100)+' %';
const difference=n=>Math.abs(n)>0&&Math.abs(n)<0.001?(n<0?'−':'')+'< 0,001':Number(n).toLocaleString('fr-FR',{maximumFractionDigits:3});
const html=value=>String(value).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const title=id=>{const [a,d]=id.split('-vs-');return types[a]+' attaquant '+types[d]};

function create({root,api,getProfile,getWeather,weatherLabels,measure,cached,runManual,onSelect,storage=globalThis.localStorage}) {
  const key=()=>M.measurementKey(getProfile(),getWeather());
  let selected='soldier-vs-soldier',reference=null,referenceMeasurement=null,comparison=null,comparisonKey='';
  let history=[],anchor=null,branchBase=null,limited=false,feedback=null,feedbackPending=false;
  let timer=null,dragging=false,revision=0,lastState='',working=false,error='',exam=null;
  const compareGate=M.createRevisionGate();
  let comparisonPending='',comparedAt=null;
  const refKey=()=>reference?M.stable([reference.profile,reference.scenarioId,reference.weather,key()]):'';

  function save() {
    try {storage.setItem('waar-workshop-bench-v2',JSON.stringify({reference,history,anchor,branchBase,limited,selected}));}catch{}
  }
  function restore() {
    let saved=null;try{saved=JSON.parse(storage.getItem('waar-workshop-bench-v2')||'null')}catch{}
    const validScenario=id=>Object.keys(types).some(a=>Object.keys(types).some(d=>id===a+'-vs-'+d));
    if(saved?.reference?.profile&&validScenario(saved.reference.scenarioId))reference=saved.reference;
    if(validScenario(saved?.selected))selected=saved.selected;
    // Restore snapshots, never cached measurements or old server-authored prose.
    if(saved?.anchor&&M.profileKey(saved.anchor)===M.profileKey(getProfile())) {
      anchor=saved.anchor;branchBase=saved.branchBase||anchor;history=(saved.history||[]).slice(-20);limited=Boolean(saved.limited);
    } else {anchor=structuredClone(getProfile());branchBase=structuredClone(anchor);}
    lastState=key();render();
  }
  function reset() {
    revision++;clearTimeout(timer);history=[];anchor=structuredClone(getProfile());branchBase=structuredClone(anchor);
    limited=false;feedback=null;feedbackPending=false;exam=null;lastState=key();compareGate.invalidate();comparison=null;comparisonKey='';comparisonPending='';
    // An explicitly pinned witness survives loading another profile for comparison.
    save();render();
  }
  function changed() {
    if(lastState===key())return;
    lastState=key();revision++;clearTimeout(timer);compareGate.invalidate();comparisonPending='';error='';
    feedbackPending=M.profileKey(anchor)!==M.profileKey(getProfile());render();
    if(feedbackPending&&!dragging)timer=setTimeout(record,500);
    refreshComparison();
  }
  function gesture(active){dragging=active;if(active)clearTimeout(timer);else if(feedbackPending){clearTimeout(timer);timer=setTimeout(record,0)}}
  async function record() {
    if(dragging||!feedbackPending)return;
    const rev=revision,before=structuredClone(anchor),after=structuredClone(getProfile());
    const base=history.length>=20?history[0].after:branchBase;
    try {
      const result=await api('profile-feedback',{before,after,branchBase:base});
      if(rev!==revision)return;
      if(result.changes.length) {
        if(history.length>=20){branchBase=structuredClone(base);limited=true;}
        history=M.boundedAppend(history,{date:new Date().toISOString(),before,after,changes:result.changes});
      }
      anchor=after;feedback=result;feedbackPending=false;save();render();
    }catch(e){if(rev===revision){error=e.status===422?'Réglage incomplet : terminez la saisie.':e.message;render()}}
  }
  function select(scenario) {selected=scenario;save();onSelect();render();}
  function pin() {
    const measurement=cached(getProfile(),getWeather());
    if(!measurement)return;
    reference={profile:structuredClone(getProfile()),scenarioId:selected,weather:getWeather(),date:new Date().toISOString()};
    referenceMeasurement=measurement;compareGate.invalidate();comparison=null;comparisonKey='';comparisonPending='';error='';save();render();refreshComparison();
  }
  async function remesure() {
    if(working||!reference)return;
    working=true;error='';const pinned=reference,weather=getWeather(),snapshot=structuredClone(getProfile());render();
    try {
      await runManual(async()=>{
        const before=await measure(pinned.profile,weather,50);
        await measure(snapshot,weather,50);
        if(reference!==pinned)return;
        referenceMeasurement=before;reference={...pinned,weather};save();
      });
      await refreshComparison();
    }catch(e){error=e.message;}finally{working=false;render();}
  }
  async function refreshComparison() {
    if(!reference||!referenceMeasurement||reference.weather!==getWeather())return;
    const after=cached(getProfile(),getWeather());if(!after)return;
    const signature=refKey();if(signature===comparisonKey||signature===comparisonPending)return;
    const token=compareGate.capture(signature);comparisonPending=signature;
    try {
      const result=await api('compare-monotypes',{beforeProfile:reference.profile,afterProfile:structuredClone(getProfile()),before:referenceMeasurement,after,scenarioId:reference.scenarioId});
      if(!compareGate.accept(token,refKey()))return;
      comparison=result;comparisonKey=signature;comparedAt=new Date();error='';
    }catch(e){if(compareGate.accept(token,refKey()))error=e.message;}
    finally{if(comparisonPending===signature)comparisonPending='';render();}
  }
  async function examine(id) {
    if(working||feedbackPending)return;
    const signature=key(),snapshot=structuredClone(getProfile()),base=structuredClone(branchBase),weather=getWeather();
    working=true;error='';render();
    try {const result=await runManual(()=>api('examine-interaction',{profile:snapshot,branchBase:base,weather,interactionId:id}));exam={result,signature,weather};}
    catch(e){error=e.message;}finally{working=false;render();}
  }
  const metricLabels={initialCount:'Effectifs',winRate:'Victoires',drawRate:'Nuls',rawLossRatio:'Morts bruts',rawWoundedRatio:'Blessés bruts',appliedLossRatio:'Morts après projection',woundedRatio:'Blessés après projection',captureRatio:'Prisonniers après projection'};
  function table(side,keys) {
    const data=comparison.sides[side];
    return '<table class="bench-table"><thead><tr><th scope="col">'+(side==='attacker'?'Attaquant':'Défenseur')+'</th><th scope="col">Référence</th><th scope="col">Maintenant</th><th scope="col">Écart</th></tr></thead><tbody>'+keys.map(metric=>{
      const count=metric==='initialCount',format=count?number:percent,delta=data.deltas[metric];
      return '<tr><th scope="row">'+metricLabels[metric]+'</th><td>'+html(format(data.before[metric]))+'</td><td>'+html(format(data.after[metric]))+'</td><td>'+(delta>0?'+':'')+html(difference(delta*(count?1:100)))+(count?'':' pt')+'</td></tr>';
    }).join('')+'</tbody></table>';
  }
  function mechanisms(current,before=null) {
    if(!current)return '';
    const hits=n=>n===null?'Aucun dégât':number(n)+' impact'+(n===1?'':'s');
    return '<details class="bench-mechanics"><summary>Comprendre les mécanismes de cette confrontation</summary><p class="muted">Valeurs après météo. Le seuil suppose des impacts réussis sur la même cible intacte ; il ne prédit pas l’issue de la bataille.</p><div class="bench-sides">'+['attacker','defender'].map(side=>{
      const m=current[side],b=before?.[side],value=(field,format=decimal)=>b?format(b[field])+' → '+format(m[field]):format(m[field]);
      return '<section><h4>'+types[m.unitType]+' · '+(side==='attacker'?'en attaque':'en défense')+'</h4><dl>'+[
        ['Dégâts par impact réussi',value('damagePerHit')],['Précision centrale',value('baseAccuracy',percent)],
        ['Plage de précision',percent(m.accuracyLower)+' à '+percent(m.accuracyUpper)],['Frappes par combattant',value('strikesPerAttack')],
        ['Structure de la cible',value('targetStructure')],['Pour tuer une cible intacte',value('hitsToKillIntact',hits)],
      ].map(([label,v])=>'<div><dt>'+label+'</dt><dd>'+v+'</dd></div>').join('')+'</dl><p>Dégâts dans ce rôle : attaque répartie '+number(m.attackPerStrike)+' × contre '+number(m.attackFactor)+(side==='defender'?' × coefficient défensif '+number(m.defendingEfficiency):'')+'.</p><p>Pour cette unité contre cette cible : '+number(m.damageWhenAttacking)+' dégâts en attaque ('+hits(m.hitsWhenAttacking)+'), '+number(m.damageWhenDefending)+' en défense ('+hits(m.hitsWhenDefending)+').</p></section>';
    }).join('')+'</div></details>';
  }
  function render() {
    const current=cached(getProfile(),getWeather()),ready=reference&&referenceMeasurement&&reference.weather===getWeather()&&comparisonKey===refKey()&&comparison;
    const unchangedReference=reference&&reference.scenarioId===selected&&M.profileKey(reference.profile)===M.profileKey(getProfile())&&reference.weather===getWeather();
    let content='<div class="bench-heading"><div><strong>Votre expérience</strong><p>'+html(title(reference?.scenarioId||selected))+(reference?' · confrontation épinglée':' · case sélectionnée')+'</p></div><button type="button" data-pin '+(!current||working||unchangedReference?'disabled':'')+'>'+(reference?'Remplacer la référence par les réglages actuels':'Fixer cette référence, puis modifier les réglages')+'</button></div>';
    if(!reference)content+='<p class="muted">Choisissez une case de la matrice, puis fixez votre référence avant de modifier les réglages.</p>'+mechanisms(current?.mechanisms?.[selected]);
    else {
      content+='<p class="muted">Référence : '+html(reference.profile.label)+' · '+html(new Date(reference.date).toLocaleString('fr-FR'))+' · budget 400 400 par camp · '+html(weatherLabels[getWeather()])+' · 50 simulations par profil.</p>';
      content+='<p class="bench-instruction">Modifiez les réglages dans les sections ci-dessous. La référence reste fixe et la comparaison s’actualise automatiquement. Le bouton ci-dessus remplace votre point de départ.</p>';
      if(!referenceMeasurement||reference.weather!==getWeather())content+='<p class="bench-status">'+(!referenceMeasurement?'Référence conservée ; ses mesures sont à renouveler après le rechargement.':'Météo modifiée : la référence doit être remesurée dans ce contexte.')+'</p><button data-remesure type="button" '+(working?'disabled':'')+'>Remesurer la référence · '+html(weatherLabels[getWeather()])+'</button>';
      else if(!ready)content+='<p class="bench-status">Comparaison en attente des mesures du profil courant.</p>';
      if(ready) {
        const changes=comparison.settingsChanges||[];
        if(changes.length)content+='<section class="bench-changes" aria-label="Réglages pris en compte"><h4>'+changes.length+' réglage'+(changes.length>1?'s':'')+' modifié'+(changes.length>1?'s':'')+' depuis la référence · comparaison actualisée à '+html(comparedAt.toLocaleTimeString('fr-FR'))+'</h4><ul>'+changes.map(c=>'<li>'+html(c.explanation)+'</li>').join('')+'</ul>'+((comparison.mechanicalChanges||[]).length?'<div class="bench-mechanical-changes">'+comparison.mechanicalChanges.map(text=>'<p>'+html(text)+'</p>').join('')+'<p class="muted">Ces seuils décrivent des impacts réussis sur une cible intacte, pas le résultat de toute la bataille.</p></div>':'')+'</section>';
        content+='<p class="bench-observation" role="status">'+html(comparison.summary)+'</p><div class="bench-sides">'+['attacker','defender'].map(side=>'<div class="table-scroll">'+table(side,['initialCount','winRate','drawRate','rawLossRatio','rawWoundedRatio'])+'</div>').join('')+'</div><p class="muted">Morts et blessés : part de l’effectif initial de chaque camp. Les écarts de taux sont en points de pourcentage.</p><details><summary>Conséquences après compression et capture</summary><div class="bench-sides">'+['attacker','defender'].map(side=>'<div class="table-scroll">'+table(side,['appliedLossRatio','woundedRatio','captureRatio'])+'</div>').join('')+'</div></details>'+mechanisms(comparison.mechanisms.after,comparison.mechanisms.before);
      }
    }
    if(working)content+='<p role="status">Mesures en cours…</p>';
    if(error)content+='<p role="alert">'+html(error)+'</p>';
    // Last gesture is secondary to the fixed witness, and never silently presented as current.
    content+='<details class="feedback-history"><summary>Dernier geste et historique · '+history.length+' changements</summary>';
    if(feedbackPending)content+='<p>Réglages modifiés ; explication en attente de validation.</p>';
    else if(feedback)content+=feedback.changes.map(change=>'<p>'+html(change.explanation)+'</p>').join('');
    if(limited)content+='<p>Fenêtre limitée aux 20 derniers changements.</p>';
    content+=history.slice().reverse().map(entry=>'<details><summary>'+html(new Date(entry.date).toLocaleTimeString('fr-FR'))+' · '+html(entry.changes.map(c=>c.label).join(', '))+'</summary>'+entry.changes.map(c=>'<p>'+html(c.label)+' : '+html(c.before)+' → '+html(c.after)+'</p>').join('')+'</details>').join('')+'</details>';
    if(!feedbackPending&&feedback?.interactions?.length)content+='<details><summary>Approfondir : interactions possibles</summary>'+feedback.interactions.map(x=>'<p><strong>'+html(x.unitLabel)+'</strong> — '+html(x.message)+'</p><p>A : '+html(x.labelA)+' '+html(x.beforeA)+' → '+html(x.afterA)+' ; B : '+html(x.labelB)+' '+html(x.beforeB)+' → '+html(x.afterB)+'.</p><button type="button" data-examine="'+html(x.id)+'" '+(working?'disabled':'')+'>Examiner ces deux réglages</button>').join('')+'</details>';
    if(exam) {
      const scenario=reference?.scenarioId||selected;
      content+='<details class="interaction-results"><summary>Dernier examen · '+html(title(scenario))+(exam.signature!==key()?' · Profil ou météo modifiés depuis cet examen':'')+'</summary><p>Météo : '+html(weatherLabels[exam.weather])+'. '+html(exam.result.comparisons[scenario].message)+'</p><table><thead><tr><th>Camp</th><th>Avant</th><th>A seul</th><th>B seul</th><th>A + B</th></tr></thead><tbody>'+['attacker','defender'].map(side=>'<tr><th>'+(side==='attacker'?'Attaquant':'Défenseur')+'</th>'+Object.values(exam.result.variants).map(m=>{const row=m.rows.find(r=>r.id===scenario+'/'+side);return '<td>'+percent(row.winRate)+' victoires<br>'+percent(row.rawCasualtyRatio)+' hors-combat</td>'}).join('')+'</tr>').join('')+'</tbody></table></details>';
    }
    // Keep opened details during asynchronous refreshes and retain keyboard focus where possible.
    const opened=[...root.querySelectorAll('details[open]')].map(e=>e.querySelector('summary')?.textContent);
    root.innerHTML=content;
    root.querySelectorAll('details').forEach(e=>{if(opened.includes(e.querySelector('summary')?.textContent))e.open=true});
    root.querySelector('[data-pin]').onclick=pin;
    const remesureButton=root.querySelector('[data-remesure]');if(remesureButton)remesureButton.onclick=remesure;
    root.querySelectorAll('[data-examine]').forEach(b=>b.onclick=()=>examine(b.dataset.examine));
  }
  return {restore,reset,changed,gesture,select,render,pin,remesure,updated(){render();return refreshComparison()},selected:()=>selected};
}
globalThis.WaarBench={create};
})();
