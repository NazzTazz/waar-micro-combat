(() => {
'use strict';
const $=selector=>document.querySelector(selector);
const svgNS='http://www.w3.org/2000/svg';
const colors={rageux:'#f56767',grenouille:'#83cd79',ascenseur:'#e7b56b',fermier:'#78bbec',scripteur:'#c4a1ef',casual:'#b6d781',lua:'#72d6c0',village:'#f5d477'};
const names={rageux:'Le Rageux',grenouille:'La Grenouille',ascenseur:"L'Ascenseur",fermier:'Le Fermier',scripteur:'Le Scripteur',casual:'Le Casual',lua:'Joueur Lua'};
const scriptProfiles=['rageux','grenouille','ascenseur','fermier','scripteur','casual'];
const profileKind=point=>point.policy==='lua'&&colors[point.scriptKey]?point.scriptKey:point.policy;
const sampleLua=`goal = "Monter ma mine suivante"
method = "Épargner pour la mine, puis investir dans une armée rentable."

local function tried(observation, kind, target)
  for _, action in ipairs(observation.attempts) do
    if action.type == kind and (target == nil or action.target == target) then return true end
  end
  return false
end

function next(observation)
  local me = observation.self
  local memory = observation.memory
  if memory.first_tick == nil then memory.first_tick = observation.tick end
  goal = "Monter la mine niveau " .. (me.mineLevel + 1)
  method = "Épargner, espionner et choisir des attaques rentables depuis le tick " .. memory.first_tick .. "."
  local level = me.mineLevel + 1
  local price = math.floor(level ^ 2.5 * 8)
  local glory = 20 * math.max(level - 8, 0)
  if me.gold >= price and me.glory >= glory and not tried(observation, "mine") then
    return {type = "mine"}
  end
  if me.hospitalLevel > 0 and not tried(observation, "heal") then
    local wounded = 0
    for _, amount in pairs(me.hospital) do wounded = wounded + amount end
    if wounded > 0 then return {type = "heal"} end
  end
  if not tried(observation, "recruit") and me.gold > price + observation.costs.soldier * 5 then
    local count = math.floor((me.gold - price) / observation.costs.soldier / 2)
    if count > 0 then return {type = "recruit", units = {soldier = count}} end
  end
  if me.attacks < 1 then return nil end
  for _, target in ipairs(observation.targets) do
    if math.abs(target.glory - me.glory) <= 20 then
      local report = observation.reports[target.id]
      if (not report or report.tick < observation.tick - 6) and
          not tried(observation, "spy") and
          me.gold >= math.floor(me.glory / 2.5 + 0.5) then
        return {type = "spy", target = target.id}
      end
      if report and report.tick >= observation.tick - 6 and
          report.armyTotal < me.army.soldier and not tried(observation, "attack", target.id) then
        return {type = "attack", target = target.id}
      end
    end
  end
  return nil
end`;
const activityNames={'all-day':'Toute la journée',office:'9 h–17 h',evening:'17 h–24 h',early:'6 h–14 h','casual-morning':'1 tick par jour · matin','casual-noon':'2 ticks par jour · midi','casual-evening':'1 tick par jour · soir','casual-night':'2 ticks par jour · soir'};
const statusNames={active:'Joueur actif',pause:'Le joueur fait une pause',abandoned:'Jeu abandonné'};
const number=new Intl.NumberFormat('fr-FR');
const mobileWebKit=/iP(hone|ad|od)/.test(navigator.userAgent);
const stepBatch=mobileWebKit?4:8,replayPage=mobileWebKit?10:50;
let runId=null,frames=[],events=[],played=0,computed=0,total=0,combatCount=0,computing=false,playing=false,selected=null,profile=null,seekTarget=null,playbackSpeed=4;
const pinnedIds=new Set();
let lastFlashedFrame=0;
let lastGoldFlowTick=null,lastGoldFlowPlaying=false;
const goldFlowWidths=new Map();
async function api(path,body){
  let response;
  try{response=await fetch('/api/'+path,{method:body===undefined?'GET':'POST',headers:body===undefined?{}:{'Content-Type':'application/json'},body:body===undefined?undefined:JSON.stringify(body)})}
  catch(error){throw new Error(`Réseau (${path}) : ${error.message}`)}
  let json;
  if(mobileWebKit){
    let payload;
    try{payload=await response.text()}
    catch(error){throw new Error(`Lecture de ${path} (HTTP ${response.status}) : ${error.message}`)}
    try{json=JSON.parse(payload)}
    catch{throw new Error(`Réponse illisible de ${path} (HTTP ${response.status}).`)}
  }else{
    try{json=await response.json()}
    catch{throw new Error(`Réponse illisible de ${path} (HTTP ${response.status}).`)}
  }
  if(!response.ok||!json.ok)throw new Error(json.errors?.[0]?.message||`${path} : HTTP ${response.status}`);
  return json.data;
}
const svg=(tag,attributes={})=>{const node=document.createElementNS(svgNS,tag);for(const [key,value] of Object.entries(attributes))node.setAttribute(key,String(value));return node};
const setStatus=message=>{$('#run-status').textContent=message};
function refreshProgress(){
  $('#compute-progress').textContent=`Calcul : ${number.format(computed)} / ${number.format(total)} ticks`;
  $('#play-progress').textContent=`Lecture : ${number.format(played)} / ${number.format(computed)} ticks`;
  $('#combat-count').textContent=`${number.format(combatCount)} combats`;
  const seek=$('#frame-seek');
  seek.disabled=frames.length===0;
  const max=String(Math.max(1,total)),value=String(Math.max(1,seekTarget??played));
  if(seek.max!==max)seek.max=max;
  if(seek.value!==value)seek.value=value;
  $('#seek-buffered').style.width=`${total?100*Math.min(frames.length,total)/total:0}%`;
  $('#seek-played').style.width=`${total?100*Math.min(played,total)/total:0}%`;
  $('#frame-buffer-status').textContent=seekTarget===null
    ?`Tampon : ${number.format(frames.length)} / ${number.format(total)} ticks`
    :`Mise en tampon vers ${number.format(seekTarget)} · ${number.format(frames.length)} disponibles`;
}
function writeSvgText(root,x,y,value,attributes={}){const node=svg('text',{x,y,...attributes});node.textContent=value;root.append(node)}
function frameFlashes(tick){
  const byId=new Map();
  const add=(id,color,repeat=false)=>{if(!id)return;const list=byId.get(id)||[];if(repeat||!list.includes(color))list.push(color);byId.set(id,list)};
  for(const event of events){
    if(event.tick!==tick)continue;
    if(event.type==='combat'){
      const attacker=event.winner==='attacker'?'#71db86':event.winner==='defender'?'#ff6868':'#b8c2ce';
      const defender=event.winner==='defender'?'#71db86':event.winner==='attacker'?'#ff6868':'#b8c2ce';
      add(event.attacker,attacker);add(event.defender,defender);
      if(event.surrender)add(event.defender,'#ffffff');
    }else if(event.type==='rwaa')add(event.actor,'#ffe45f');
    else if(event.type==='rwaa-ended')add(event.actor,'#ff9c4a');
    else if(event.type==='reset')add(event.actor,'#caa7ff');
    else if(event.type==='recruit')add(event.actor,'#61aaff');
    else if(event.type==='heal')add(event.actor,'#fa80c7');
    else if(event.type==='surrender')add(event.actor,'#ffffff');
  }
  return byId;
}
function renderChart(){
  const chart=$('#era-chart');chart.replaceChildren();
  if(played<1||!frames[played-1])return;
  const current=frames[played-1],left=70,right=755,top=35,bottom=435;
  let observedX=0,observedY=0;
  for(let frameIndex=0;frameIndex<played;frameIndex++){
    for(const point of [...frames[frameIndex].points,...(frames[frameIndex].villages||[])]){
      observedX=Math.max(observedX,point.armyGold);observedY=Math.max(observedY,point.glory);
    }
  }
  const compressed=observedX>200000,split=100000;
  const niceStep=value=>{const power=10**Math.floor(Math.log10(value));return [1,2,5,10].map(multiplier=>multiplier*power).find(step=>step>=value)};
  const highStep=compressed?niceStep((observedX-split)/4):20000;
  const maxX=compressed?Math.ceil(observedX/highStep)*highStep:(Math.floor(observedX/20000)+1)*20000;
  const maxY=(Math.floor(observedY/10)+1)*10;
  const breakX=left+(right-left)*0.53;
  const x=value=>compressed?(value<=split?left+(breakX-left)*value/split:breakX+(right-breakX)*(value-split)/(maxX-split)):left+(right-left)*value/maxX;
  const y=value=>bottom-(bottom-top)*value/maxY;
  const yGridEvery=Math.max(1,Math.ceil(8/((bottom-top)*10/maxY)));
  const yLabelEvery=Math.ceil(Math.max(yGridEvery,30/((bottom-top)*10/maxY))/yGridEvery)*yGridEvery;
  const axisLabel=value=>value>=1000000?`${number.format(value/1000000)} M`:value>=1000?`${number.format(value/1000)} k`:String(value);
  for(let i=0;i<=maxY/10;i++){
    if(i%yGridEvery!==0)continue;
    const gy=y(i*10);
    chart.append(svg('line',{class:i%yLabelEvery===0?'grid major-grid':'grid',x1:left,y1:gy,x2:right,y2:gy}));
    if(i%yLabelEvery===0)writeSvgText(chart,left-12,gy+4,number.format(i*10),{'text-anchor':'end'});
  }
  const xTicks=[];
  for(let value=0;value<=(compressed?split:maxX);value+=20000)xTicks.push(value);
  if(compressed)for(let value=Math.ceil((split+1)/highStep)*highStep;value<=maxX;value+=highStep)xTicks.push(value);
  for(const value of xTicks){
    const gx=x(value);
    chart.append(svg('line',{class:'grid major-grid',x1:gx,y1:top,x2:gx,y2:bottom}));
    writeSvgText(chart,gx,bottom+22,axisLabel(value),{'text-anchor':'middle'});
  }
  $('#chart-grid-hint').textContent=compressed
    ? "Abscisse : Or investi dans l'armée au prix du preset. Pas de 20 000 Or jusqu'à 100 000 Or ; au-delà, l'échelle est comprimée pour garder visibles les armées extrêmes. Ordonnée : Glwaare."
    : "Abscisse : Or investi dans l'armée au prix du preset. Ordonnée : Glwaare. Une case vaut 20 000 Or × 10 Glwaare. Les traînées montrent les 12 derniers ticks des joueurs.";
  chart.append(svg('line',{class:'axis',x1:left,y1:bottom,x2:right,y2:bottom}));
  chart.append(svg('line',{class:'axis',x1:left,y1:bottom,x2:left,y2:top}));
  writeSvgText(chart,(left+right)/2,487,compressed?"Or investi dans l'armée · échelle comprimée après 100 k":"Or investi dans l'armée actuelle",{'text-anchor':'middle'});
  writeSvgText(chart,22,18,'Glwaare');
  const rwaa=current.points.find(point=>point.id===current.rwaa);
  if(rwaa)chart.append(svg('circle',{class:'rwaa-halo',cx:x(rwaa.armyGold),cy:y(rwaa.glory),r:14}));
  for(const point of current.points){
    const trail=frames.slice(Math.max(0,played-12),played).map(frame=>frame.points.find(candidate=>candidate.id===point.id)).filter(Boolean);
    if(trail.length>1)chart.append(svg('polyline',{points:trail.map(item=>`${x(item.armyGold)},${y(item.glory)}`).join(' '),fill:'none',stroke:colors[profileKind(point)],opacity:'.55','stroke-width':2}));
    const abandoned=point.status==='abandoned';
    const circle=svg('circle',{class:'point',cx:x(point.armyGold),cy:y(point.glory),r:selected===point.id?8:6,fill:abandoned?'#eef3fa':colors[profileKind(point)],stroke:abandoned?'#4d596d':'none','stroke-width':abandoned?2:0,'aria-selected':selected===point.id,tabindex:0,role:'button','aria-label':`${point.name||point.id} : ${number.format(point.armyGold)} Or investis, ${point.glory} Glwaare${abandoned?' ; jeu abandonné':''}`});
    circle.addEventListener('click',()=>{selected=point.id;render()});
    circle.addEventListener('keydown',event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();selected=point.id;render()}});
    chart.append(circle);
    if(selected===point.id)writeSvgText(chart,x(point.armyGold)+10,y(point.glory)-9,point.name||point.id,{fill:colors[profileKind(point)]});
  }
  for(const village of current.villages||[]){
    const cx=x(village.armyGold),cy=y(village.glory),size=selected===village.id?10:8;
    const marker=svg('path',{class:'point village-point',d:`M ${cx} ${cy-size} L ${cx+size} ${cy} L ${cx} ${cy+size} L ${cx-size} ${cy} Z`,fill:colors.village,'aria-selected':selected===village.id,tabindex:0,role:'button','aria-label':`${village.id} : ${number.format(village.armyGold)} Or de garnison, ${village.glory} Glwaare`});
    marker.addEventListener('click',()=>{selected=village.id;render()});
    marker.addEventListener('keydown',event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();selected=village.id;render()}});
    chart.append(marker);
    writeSvgText(chart,cx+12,cy-10,village.id,{fill:colors.village});
  }
  if(played!==lastFlashedFrame){
    const overlay=$('#era-flashes');
    if(played<lastFlashedFrame)overlay.replaceChildren();
    const markerById=new Map([...current.points,...(current.villages||[])].map(point=>[point.id,point]));
    const speed=playbackSpeed;
    for(const [id,flashes] of frameFlashes(current.tick)){
      const point=markerById.get(id);
      if(!point)continue;
      flashes.forEach((color,index)=>{
        if(color==='#61aaff'){
          const plus=svg('text',{class:'recruit-flash',x:x(point.armyGold)+11,y:y(point.glory)+5,style:`animation-duration:${1.15/speed}s;animation-delay:${index*0.32/speed}s`});
          plus.textContent='+';
          plus.addEventListener('animationend',()=>plus.remove());overlay.append(plus);
          return;
        }
        const major=['#ffffff','#ffe45f','#ff9c4a','#caa7ff'].includes(color);
        const width={'#71db86':3,'#ff6868':3,'#b8c2ce':3,'#fa80c7':1}[color]||(major?8:6);
        const duration=(major?2.4:1.15)/speed,delay=index*0.32/speed;
        const halo=svg('circle',{class:major?'event-flash major-event-flash':'event-flash',cx:x(point.armyGold),cy:y(point.glory),r:major?16:13,fill:'none',stroke:color,'stroke-width':width,style:`animation-duration:${duration}s;animation-delay:${delay}s`});
        halo.addEventListener('animationend',()=>halo.remove());overlay.append(halo);
        if(color==='#ffe45f'){
          const label=svg('text',{class:'rwaa-flash-label',x:x(point.armyGold),y:y(point.glory)-23,'text-anchor':'middle',style:`animation-duration:${duration}s;animation-delay:${delay}s`});
          label.textContent='RWAA';
          label.addEventListener('animationend',()=>label.remove());overlay.append(label);
        }
      });
    }
    lastFlashedFrame=played;
  }
  $('#frame-weather').textContent=`Tick ${current.tick} · météo : ${current.weather}`;
}
function inspectionTitle(point){
  if(point.kind==='village')return `${point.id} · Village`;
  const kind=point.policy==='lua'?(names[profileKind(point)]||point.scriptKey||'Joueur')+' [Lua]':names[profileKind(point)]||point.policy;
  return `${point.name||point.id} · ${kind}`;
}
function renderGoldFlow(point,detail,motion){
  if(!point.goldFlow)return;
  const flow=point.goldFlow,income=flow.income||0,invested=flow.invested||0,pillaged=flow.pillaged||0;
  const net=income-invested-pillaged,scale=Math.max(1,point.goldFlowScale||1);
  const row=document.createElement('div'),caption=document.createElement('div'),label=document.createElement('span'),value=document.createElement('strong');
  row.className='bagaar-gold-flow';caption.className='bagaar-gold-flow-caption';label.textContent='Δ Or / tick';
  value.textContent=flow.reset?'Remise à zéro':`${net>=0?'+':'−'}${number.format(Math.abs(net))} Or`;
  caption.append(label,value);row.append(caption);
  const track=document.createElement('div'),left=document.createElement('div'),right=document.createElement('div');
  track.className='bagaar-gold-flow-track';left.className='bagaar-gold-flow-left';right.className='bagaar-gold-flow-right';
  const widths={income:income?Math.max(1,100*income/scale):0,invested:invested?Math.max(1,100*invested/scale):0,pillaged:pillaged?Math.max(1,100*pillaged/scale):0};
  const previous=goldFlowWidths.get(point.id),animations=[];
  const bar=kind=>{
    const element=document.createElement('span'),target=widths[kind],from=previous?.[kind]??target;
    element.className=`bagaar-gold-flow-${kind}`;
    element.style.width=`${motion==='paused'||motion==='fold'?0:target}%`;
    if(motion==='tick'&&previous&&from!==target)animations.push([element,from,target,600/playbackSpeed,'ease-out']);
    if(motion==='fold'&&from>0)animations.push([element,from,0,220,'ease-in']);
    return element;
  };
  if(!flow.reset){left.append(bar('invested'),bar('pillaged'));right.append(bar('income'))}
  goldFlowWidths.set(point.id,flow.reset?{income:0,invested:0,pillaged:0}:widths);
  track.append(left,right);row.append(track);
  row.title=flow.reset?'Compte remis à zéro pendant ce tick.':`Rentrées : ${number.format(income)} Or · investissements et dépenses : ${number.format(invested)} Or · pillage subi : ${number.format(pillaged)} Or · solde : ${net>=0?'+':''}${number.format(net)} Or`;
  row.setAttribute('role','img');row.setAttribute('aria-label',row.title);detail.append(row);
  for(const [element,from,to,duration,easing] of animations)element.animate([{width:`${from}%`},{width:`${to}%`}],{duration,easing});
}
const SIGNALS_VERSION='bagaar-signals/1';
// Frames that already store signals keep those numbers. This fallback is bagaar-signals/1 only.
function playerSignals(point,frame){
  if(point.signals)return point.signals;
  const points=frame?.points||[];
  const rank=activeOnly=>{
    const cohort=points.filter(item=>!activeOnly||(item.status||'active')==='active')
      .sort((a,b)=>b.glory-a.glory||String(a.id).localeCompare(String(b.id)));
    const place=cohort.findIndex(item=>item.id===point.id);
    return place<0?null:place+1;
  };
  const wins=point.record?.wins??0,draws=point.record?.draws??0,losses=point.record?.losses??0;
  const fights=wins+draws+losses;
  const winRate=fights>0?wins/fights:null,lossRate=fights>0?losses/fights:null;
  const destroyed=point.powerDestroyed??0,lost=point.powerLost??0,looted=point.goldLooted??0;
  const army=point.armyGold??0,mine=point.mineProduction??0;
  const glory=point.glory??0,mineLevel=point.mineLevel??0;
  const finite=value=>Number.isFinite(value)?value:null;
  const strike=finite(destroyed/(lost+1)),hurt=finite(lost/(destroyed+1));
  const picsou=mine>0&&strike!=null?finite(strike*army/mine):null;
  const equilibrium=winRate==null?null:finite(((mineLevel+1)/(glory/20+1))*winRate*((destroyed+looted)/(lost+1)));
  const rwaa=winRate==null?null:finite(glory*winRate);
  const joy=winRate==null||strike==null?null:finite(winRate*strike*(looted/(lost+1)));
  const rage=lossRate==null||hurt==null?null:finite(lossRate*hurt/(looted+1)*(lost/Math.max(glory,10)));
  const offense=winRate==null||strike==null?null:finite(winRate*strike);
  const defense=lossRate==null||mine<=0?null:finite(lossRate*army/mine);
  const defined={
    R_picsou:picsou!=null,R_joy:joy!=null,R_rage:rage!=null,R_eq:equilibrium!=null,
    R_rwaa:rwaa!=null,R_offense:offense!=null,R_defense:defense!=null,
  };
  return {
    signalsVersion:SIGNALS_VERSION,derived:true,
    gloryRank:rank(false),activeGloryRank:rank(true),
    R_picsou:picsou,R_joy:joy,R_rage:rage,R_eq:equilibrium,R_rwaa:rwaa,R_offense:offense,R_defense:defense,
    defined,
  };
}
const signalNumber=new Intl.NumberFormat('fr-FR',{maximumFractionDigits:2});
function renderInspection(point,frame,title,detail,motion){
  title.textContent=inspectionTitle(point);
  detail.replaceChildren();detail.classList.add('bagaar-player-detail');
  const meta=document.createElement('div');meta.className='bagaar-player-meta';
  const rank=frame.rwaa===point.id?` · Rwaa (${frame.rwaaPv} PV)`:frame.candidate===point.id?` · Prétendant ${frame.candidateHours}/24`:'';
  meta.textContent=point.kind==='village'?`${number.format(point.glory)} Glwaare`:`${number.format(point.glory)} Glwaare · ${statusNames[point.status]||statusNames.active}${rank}`;
  detail.append(meta);
  const army=document.createElement('div');army.className='bagaar-player-army';
  for(const [short,name,key] of [['S','Soldats','soldier'],['L','Lanciers','spearman'],['A','Archers','archer'],['C','Chevaliers','knight']]){
    const unit=document.createElement('span'),label=document.createElement('abbr'),count=document.createElement('strong');
    label.title=name;label.textContent=`${short} : `;count.textContent=number.format(point.army?.[key]??0);
    unit.append(label,count);army.append(unit);
  }
  detail.append(army);
  const line=(label,value,hint='')=>{const row=document.createElement('div'),name=document.createElement('span'),text=document.createElement('strong');row.className='bagaar-player-line';if(hint)row.title=hint;name.textContent=label;text.textContent=String(value);row.append(name,text);detail.append(row)};
  line('Or investi',`${number.format(point.armyGold)} Or`);
  renderGoldFlow(point,detail,motion);
  if(point.kind==='village'){
    line('Or disponible',`${number.format(point.gold)} / ${number.format(point.goldMax??point.gold)} Or`);
    line('Abondement',`+${number.format(point.goldRefill??0)} Or/tick`);
    line('Combats V / N / D',point.record?`${point.record.wins} / ${point.record.draws} / ${point.record.losses}`:'—');
    line('Or distribué',point.goldDistributed==null?'—':`${number.format(point.goldDistributed)} Or`);
    return;
  }
  line('Mine',`${point.mineLevel??0} (${number.format(point.mineProduction??0)} Or/heure)`);
  line('Infirmerie',point.hospitalLevel==null?'—':`${point.hospitalLevel} (${number.format(point.hospitalOccupied??0)} lits occupés)`);
  line('Combats V / N / D',`${point.record?.wins??0} / ${point.record?.draws??0} / ${point.record?.losses??0}`);
  const powerHint='Morts, blessés et prisonniers sortis de l’armée active, valorisés aux prix d’achat du preset.';
  line('Puissance détruite',point.powerDestroyed==null?'—':`${number.format(point.powerDestroyed)} Or`,powerHint);
  line('Puissance perdue',point.powerLost==null?'—':`${number.format(point.powerLost)} Or`,powerHint);
  line('Or pillé',point.goldLooted==null?'—':`${number.format(point.goldLooted)} Or`);
  const signals=playerSignals(point,frame);
  const current=signals.signalsVersion===SIGNALS_VERSION;
  const kept='Valeur enregistrée avec une autre formule. Le frontend ne la recalcule pas.';
  const text=value=>value==null?'—':signalNumber.format(value);
  line('Formule',signals.signalsVersion||'non versionnée',current
    ?'Mesures brutes. Une case vide signifie que le rapport n’est pas défini.'
    :'Ces nombres restent ceux enregistrés avec la frame.');
  for(const [key,label,hint] of [
    ['gloryRank','Rang Glwaare','Place dans la cohorte affichée, comptes abandonnés compris. 1 = plus haute Glwaare. Ce rang ne désigne pas le candidat Rwaa.'],
    ['activeGloryRank','Rang actif','Place parmi les comptes au statut actif. Vide si ce compte n’est pas actif. Être premier ne suffit pas pour devenir Rwaa.'],
    ['R_picsou','R picsou',current?'(destructions / (pertes + 1)) × (armée / mine). Vide si la mine ne produit pas. Repère descriptif.':kept],
    ['R_rwaa','R Rwaa',current?'Glwaare × taux de victoire. Vide sans combat. Repère descriptif.':kept],
    ['R_eq','R équilibre',current?'Niveau de mine, Glwaare, victoires et (destructions + butin) / (pertes + 1). Vide sans combat. Repère descriptif.':kept],
    ['R_joy','R joie',current?'Taux de victoire × frappe × butin / (pertes + 1). Vide sans combat. Repère descriptif.':kept],
    ['R_rage','R rage',current?'Taux de défaite × pertes subies, rapportées au butin et à la Glwaare. Vide sans combat. Repère descriptif.':kept],
    ['R_offense','R offense',current?'Taux de victoire × destructions / (pertes + 1). Vide sans combat. Repère descriptif.':kept],
    ['R_defense','R défense',current?'Taux de défaite × armée / mine. Vide sans combat ou sans production. Repère descriptif.':kept],
  ])line(label,key==='gloryRank'||key==='activeGloryRank'?(signals[key]==null?'—':String(signals[key])):text(signals.defined?.[key]===false?null:signals[key]),hint);
  const intent=document.createElement('div');intent.className='bagaar-player-intent';detail.append(intent);
  for(const [label,value] of [['Objectif',point.goal||'—'],['Moyen',point.method||'—']]){
    const row=document.createElement('p'),name=document.createElement('span');name.textContent=`${label} : `;row.append(name,document.createTextNode(value));intent.append(row);
  }
}
function savePinned(){if(!runId)return;try{localStorage.setItem(`waar-bagaar-pins-${runId}`,JSON.stringify([...pinnedIds]))}catch{}}
function renderPlayer(motion=playing?'static':'paused'){
  const frame=frames[played-1];
  const point=[...(frame?.points||[]),...(frame?.villages||[])].find(candidate=>candidate.id===selected)||frame?.points[0];
  const button=$('#pin-current');button.disabled=!point;
  if(!point){$('#current-inspection').hidden=false;$('#player-title').textContent='Compte observé';$('#player-detail').textContent='Lancez une ère pour afficher les comptes.';return}
  selected=point.id;
  $('#current-inspection').hidden=pinnedIds.has(point.id);
  button.textContent=pinnedIds.has(point.id)?'★ Épinglé':'☆ Épingler';
  button.setAttribute('aria-label',pinnedIds.has(point.id)?`Désépingler ${point.name||point.id}`:`Épingler ${point.name||point.id}`);
  if(pinnedIds.has(point.id))return;
  renderInspection(point,frame,$('#player-title'),$('#player-detail'),motion);
}
function renderPinned(motion=playing?'static':'paused'){
  const root=$('#pinned-inspections');root.replaceChildren();root.hidden=pinnedIds.size===0;
  if(root.hidden)return;
  const frame=frames[played-1];
  const points=new Map([...(frame?.points||[]),...(frame?.villages||[])].map(point=>[point.id,point]));
  for(const id of [...pinnedIds].reverse()){
    const card=document.createElement('section'),head=document.createElement('div'),title=document.createElement('h2'),remove=document.createElement('button'),detail=document.createElement('div');
    card.className='panel bagaar-pin';head.className='bagaar-pin-head';detail.className='bagaar-detail';
    remove.type='button';remove.textContent='×';remove.setAttribute('aria-label',`Désépingler ${id}`);
    remove.addEventListener('click',()=>{pinnedIds.delete(id);savePinned();renderPlayer();renderPinned()});
    head.append(title,remove);card.append(head,detail);root.append(card);
    const point=points.get(id);
    if(point)renderInspection(point,frame,title,detail,motion);
    else{title.textContent=id;detail.className='bagaar-pin-absent';detail.textContent='Pas encore apparu à ce tick.'}
  }
}
function renderRanking(){
  const list=$('#era-ranking');list.replaceChildren();
  const points=[...(frames[played-1]?.points||[])].sort((a,b)=>b.glory-a.glory||a.id.localeCompare(b.id));
  points.forEach((point,index)=>{
    const row=document.createElement('li'),button=document.createElement('button'),swatch=document.createElement('span'),score=document.createElement('strong');
    button.type='button';button.setAttribute('aria-current',String(point.id===selected));
    swatch.className='swatch';swatch.style.background=colors[profileKind(point)];score.textContent=String(point.glory);
    button.append(`${index+1}. `,swatch,document.createTextNode(point.name||point.id),score);
    button.addEventListener('click',()=>{selected=point.id;render()});row.append(button);list.append(row);
  });
}
function displayName(id){return frames[played-1]?.points.find(point=>point.id===id)?.name||id}
function eventLabel(event){
  if(event.type==='combat')return `${displayName(event.attacker)} → ${displayName(event.defender)} · ${event.winner==='attacker'?'victoire attaquante':event.winner==='defender'?'victoire défensive':'nul'}${event.surrender?' · reddition':''}`;
  if(event.type==='rwaa')return `${displayName(event.actor)} devient Rwaa`;
  if(event.type==='rwaa-ended')return `Fin du règne de ${displayName(event.actor)}`;
  if(event.type==='candidate')return `${displayName(event.actor)} devient prétendant`;
  if(event.type==='village')return `${event.id} apparaît`;
  if(event.type==='spy')return `${displayName(event.actor)} espionne ${displayName(event.target)}`;
  if(event.type==='recruit')return `${displayName(event.actor)} recrute ${number.format(Object.values(event.units||{}).reduce((total,count)=>total+count,0))} unité(s)`;
  if(event.type==='pause')return `${displayName(event.actor)} fait une pause`;
  if(event.type==='abandon')return `${displayName(event.actor)} abandonne le jeu`;
  if(event.type==='reset')return `${displayName(event.actor)} repart de zéro`;
  if(event.type==='arrival')return `${displayName(event.actor)} rejoint ${event.source==='spontaneous'?'spontanément ':''}l'ère`;
  if(event.type==='surrender')return `${displayName(event.actor)} se rend`;
  if(event.type==='return')return `${displayName(event.actor)} revient jouer`;
  if(event.type==='rejected')return `${displayName(event.actor)} · ${event.action} refusé`;
  return `${displayName(event.actor)||'Jeu'} · ${event.type}`;
}
function appendCombatReport(item,event){
  if(!event.report)return;
  const details=document.createElement('details'),summary=document.createElement('summary');
  details.className='bagaar-combat-report';summary.textContent='Rapport du combat';details.append(summary);
  const units={soldier:'Soldats',spearman:'Lanciers',archer:'Archers',knight:'Chevaliers'};
  for(const [side,label] of [['attacker','Attaquant'],['defender','Défenseur']]){
    const heading=document.createElement('strong');heading.textContent=label;details.append(heading);
    const table=document.createElement('table'),header=document.createElement('tr');
    for(const name of ['Unité','Morts','Blessés','Capturés']){
      const cell=document.createElement('th');cell.textContent=name;header.append(cell);
    }
    table.append(header);
    for(const [type,name] of Object.entries(units)){
      const row=document.createElement('tr'),loss=event.report[side]?.types?.[type]||{};
      for(const value of [name,loss.dead??0,loss.wounded??0,loss.prisoners??0]){
        const cell=document.createElement('td');cell.textContent=String(value);row.append(cell);
      }
      table.append(row);
    }
    details.append(table);
    const captured=document.createElement('p');captured.textContent=`Prisonniers gagnés : ${event.report[side]?.prisonersCaptured??0}`;details.append(captured);
  }
  item.append(details);
}
function renderEvents(){
  const list=$('#era-events');list.replaceChildren();
  const visible=events.filter(event=>event.tick<=played).slice(-18).reverse();
  for(const event of visible){
    const item=document.createElement('li');item.textContent=eventLabel(event);
    const small=document.createElement('small');small.textContent=`Tick ${event.tick}`;item.append(small);
    if(event.type==='combat')appendCombatReport(item,event);
    list.append(item);
  }
}
function render(){
  const motion=playing?(played!==lastGoldFlowTick?'tick':'static'):(lastGoldFlowPlaying?'fold':'paused');
  refreshProgress();renderPlayer(motion);renderPinned(motion);renderChart();renderRanking();renderEvents();
  lastGoldFlowTick=played;lastGoldFlowPlaying=playing;
}
function resolveSeekTarget(){
  if(seekTarget===null||frames.length<seekTarget)return false;
  played=seekTarget;seekTarget=null;render();setStatus(computed>=total?'Ère calculée':'Calcul en cours');return true;
}
async function compute(){
  if(computing||!runId)return;
  computing=true;
  if(window.parent!==window)window.parent.postMessage({type:'waar-bagaar-computing',value:true},location.origin);
  const activeRun=runId;
  let phase='calcul';
  try{
    while(computed<total&&runId===activeRun){
      const steps=computed===0?1:Math.min(stepBatch,total-computed);
      phase='requête bagaar-step';
      const result=await api('bagaar-step',{runId:activeRun,steps});
      if(runId!==activeRun)return;
      phase='rendu du tick';
      frames.push(...result.frames);events.push(...result.events);computed=result.tick;combatCount=result.combatCount;
      if(played===0&&frames.length){played=1;render()}
      resolveSeekTarget();
      refreshProgress();
      if(result.done){setStatus('Ère calculée');break}
      await new Promise(resolve=>setTimeout(resolve,0));
    }
  }catch(error){console.error('Bagaar : '+phase,error);$('#bagaar-error').textContent=`${phase} : ${error.message}`;setStatus('Calcul interrompu')}
  finally{computing=false;if(runId!==activeRun&&runId)compute();else if(window.parent!==window)window.parent.postMessage({type:'waar-bagaar-computing',value:false},location.origin)}
}
function play(){
  if(!playing||frames.length===0||seekTarget!==null)return;
  if(played<frames.length){played++;render()}
  if(played>=total&&computed>=total){playing=false;syncPlaybackControls();render()}
}
let playbackTimer=null;
function syncPlaybackControls(){
  $('#play-era').disabled=!runId||playing;
  $('#pause-era').disabled=!runId||!playing;
  for(const button of document.querySelectorAll('[data-play-speed]'))button.setAttribute('aria-pressed',String(Number(button.dataset.playSpeed)===playbackSpeed));
}
function schedulePlayback(){clearInterval(playbackTimer);playbackTimer=setInterval(play,600/playbackSpeed);syncPlaybackControls()}
const profileEditors=new Map();
function initProfileEditors(){
  const root=$('#lua-profiles');
  for(const key of scriptProfiles){
    const section=document.createElement('div'),toggle=document.createElement('label'),enabled=document.createElement('input');
    const label=document.createElement('label'),source=document.createElement('textarea');
    section.className='bagaar-script-profile';enabled.type='checkbox';
    toggle.className='bagaar-script-toggle';toggle.append(enabled,` Remplacer les quatre comptes « ${names[key]} » par ce script`);
    source.id=`lua-profile-${key}`;source.spellcheck=false;source.setAttribute('aria-label',`Script Lua : ${names[key]}`);
    label.htmlFor=source.id;label.textContent=`Script ${names[key]}`;
    try{source.value=localStorage.getItem(`waar-bagaar-script-${key}`)||'';enabled.checked=localStorage.getItem(`waar-bagaar-script-enabled-${key}`)==='1'}catch{}
    source.addEventListener('input',()=>{try{localStorage.setItem(`waar-bagaar-script-${key}`,source.value)}catch{}});
    enabled.addEventListener('change',()=>{try{localStorage.setItem(`waar-bagaar-script-enabled-${key}`,enabled.checked?'1':'0')}catch{}});
    section.append(toggle,label,source);root.append(section);profileEditors.set(key,{enabled,source});
  }
}
async function loadProfile(){
  let stored=null;try{stored=sessionStorage.getItem('waar-bagaar-profile-v1')}catch{}
  profile=stored?JSON.parse(stored):(await fetch('/api/default-profile').then(response=>response.json())).data.profile;
  $('#preset-name').textContent=profile.label||profile.id;
}
async function start(){
  $('#bagaar-error').textContent='';
  const seed=Number($('#era-seed').value),days=Number($('#era-days').value);
  const population=await BagaarPopulation.prepare();
  const result=await api('bagaar-start',{profile,seed,totalTicks:days*24,
    ...population});
  runId=result.runId;frames=[];events=[];played=0;computed=0;total=result.totalTicks;combatCount=0;selected=null;seekTarget=null;pinnedIds.clear();goldFlowWidths.clear();playing=true;lastFlashedFrame=0;$('#era-flashes').replaceChildren();
  try{localStorage.setItem('waar-bagaar-run-v2',runId)}catch{}
  syncPlaybackControls();
  $('#export-era').hidden=false;$('#export-era').href=`/bagaar-export/${runId}.json`;render();setStatus('Calcul en cours');compute();
}
async function resume(){
  let previous=null;try{previous=localStorage.getItem('waar-bagaar-run-v2')}catch{}
  if(!previous)return;
  let phase='reprise';
  try{
    runId=previous;frames=[];events=[];played=0;seekTarget=null;goldFlowWidths.clear();lastFlashedFrame=0;$('#era-flashes').replaceChildren();
    pinnedIds.clear();try{for(const id of JSON.parse(localStorage.getItem(`waar-bagaar-pins-${runId}`)||'[]'))if(typeof id==='string')pinnedIds.add(id)}catch{}
    let frameOffset=0,result;
    do{
      phase='requête bagaar-resume';
      result=await api('bagaar-resume',{runId:previous,frameOffset,limit:replayPage});
      if(runId!==previous)return;
      phase='rendu de la reprise';
      frames.push(...result.frames);events.push(...result.events);
      computed=result.tick;total=result.totalTicks;combatCount=result.combatCount;
      const duration=String(total/24);
      if([...$('#era-days').options].some(option=>option.value===duration))$('#era-days').value=duration;
      frameOffset=result.nextFrameOffset;
      if(played===0&&frames.length){played=1;playing=true;syncPlaybackControls();$('#export-era').hidden=false;$('#export-era').href=`/bagaar-export/${runId}.json`;render()}
      resolveSeekTarget();
      refreshProgress();setStatus(result.hasMoreFrames?`Chargement : ${number.format(frameOffset)} / ${number.format(result.tick)} trames`:result.done?'Ère calculée':'Calcul repris');
    }while(result.hasMoreFrames);
    if(!result.done)compute();
  }catch(error){console.error('Bagaar : '+phase,error);$('#bagaar-error').textContent=`${phase} : ${error.message}`;setStatus('Reprise interrompue')}
}
$('#start-era').addEventListener('click',()=>start().catch(error=>{console.error('Bagaar : démarrage',error);$('#bagaar-error').textContent=`démarrage : ${error.message}`;setStatus('Erreur')}));
$('#pin-current').addEventListener('click',()=>{if(!selected||!runId)return;if(pinnedIds.has(selected))pinnedIds.delete(selected);else pinnedIds.add(selected);savePinned();renderPlayer();renderPinned()});
$('#play-era').addEventListener('click',()=>{if(played>=total&&computed>=total){played=0;seekTarget=null}playing=true;syncPlaybackControls();render()});
$('#pause-era').addEventListener('click',()=>{playing=false;syncPlaybackControls();render()});
for(const button of document.querySelectorAll('[data-play-speed]'))button.addEventListener('click',()=>{playbackSpeed=Number(button.dataset.playSpeed);schedulePlayback()});
$('#frame-seek').addEventListener('input',event=>{
  const target=Number(event.target.value),wasPlaying=playing;
  playing=false;syncPlaybackControls();
  if(target<=frames.length){seekTarget=null;played=target;render();setStatus(computed>=total?'Ère calculée':'Calcul en cours');return}
  seekTarget=target;
  if(wasPlaying)render();else refreshProgress();
  setStatus(`En attente du tick ${number.format(target)}`);
});
if(new URLSearchParams(location.search).has('embedded'))document.body.classList.add('bagaar-embedded');
window.addEventListener('message',event=>{if(event.origin!==location.origin||event.data?.type!=='waar-bagaar-profile')return;
  profile=event.data.profile;$('#preset-name').textContent=profile.label||profile.id;
  try{sessionStorage.setItem('waar-bagaar-profile-v1',JSON.stringify(profile))}catch{}
});
if(window.parent!==window)new ResizeObserver(()=>window.parent.postMessage({type:'waar-bagaar-height',height:document.documentElement.scrollHeight},location.origin)).observe(document.body);
schedulePlayback();Promise.all([BagaarPopulation.init(api,start),loadProfile()]).then(resume).catch(error=>{$('#bagaar-error').textContent=error.message});
})();
