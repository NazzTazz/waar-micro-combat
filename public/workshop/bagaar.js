(() => {
'use strict';
const $=selector=>document.querySelector(selector);
const svgNS='http://www.w3.org/2000/svg';
const colors={rageux:'#f56767',grenouille:'#83cd79',ascenseur:'#e7b56b',fermier:'#78bbec',scripteur:'#c4a1ef',village:'#f5d477'};
const names={rageux:'Le Rageux',grenouille:'La Grenouille',ascenseur:"L'Ascenseur",fermier:'Le Fermier',scripteur:'Le Scripteur'};
const number=new Intl.NumberFormat('fr-FR');
let runId=null,frames=[],events=[],played=0,computed=0,total=0,combatCount=0,computing=false,playing=false,selected=null,profile=null;
async function api(path,body){const response=await fetch('/api/'+path,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});const json=await response.json();if(!response.ok||!json.ok)throw new Error(json.errors?.[0]?.message||`HTTP ${response.status}`);return json.data}
const svg=(tag,attributes={})=>{const node=document.createElementNS(svgNS,tag);for(const [key,value] of Object.entries(attributes))node.setAttribute(key,String(value));return node};
const setStatus=message=>{$('#run-status').textContent=message};
function refreshProgress(){
  $('#compute-progress').textContent=`Calcul : ${number.format(computed)} / ${number.format(total)} ticks`;
  $('#play-progress').textContent=`Lecture : ${number.format(played)} / ${number.format(computed)} ticks`;
  $('#combat-count').textContent=`${number.format(combatCount)} combats`;
  $('#frame-seek').disabled=frames.length===0;
  $('#frame-seek').max=String(Math.max(1,frames.length));
  $('#frame-seek').value=String(Math.max(1,played));
}
function writeSvgText(root,x,y,value,attributes={}){const node=svg('text',{x,y,...attributes});node.textContent=value;root.append(node)}
function renderChart(){
  const chart=$('#era-chart');chart.replaceChildren();
  if(played<1||!frames[played-1])return;
  const current=frames[played-1],left=70,right=755,top=35,bottom=435;
  const historical=frames.slice(0,played).flatMap(frame=>[...frame.points,...(frame.villages||[])]);
  const maxX=Math.max(1,...historical.map(point=>point.armyGold))*1.1;
  const maxY=Math.max(1,...historical.map(point=>point.glory))*1.1;
  const x=value=>left+(right-left)*value/maxX;
  const y=value=>bottom-(bottom-top)*value/maxY;
  for(let i=0;i<=4;i++){
    const gy=bottom-(bottom-top)*i/4,gx=left+(right-left)*i/4;
    chart.append(svg('line',{class:'grid',x1:left,y1:gy,x2:right,y2:gy}));
    chart.append(svg('line',{class:'grid',x1:gx,y1:top,x2:gx,y2:bottom}));
    writeSvgText(chart,left-12,gy+4,number.format(Math.round(maxY*i/4)),{'text-anchor':'end'});
    writeSvgText(chart,gx,bottom+22,number.format(Math.round(maxX*i/4)),{'text-anchor':'middle'});
  }
  chart.append(svg('line',{class:'axis',x1:left,y1:bottom,x2:right,y2:bottom}));
  chart.append(svg('line',{class:'axis',x1:left,y1:bottom,x2:left,y2:top}));
  writeSvgText(chart,(left+right)/2,487,"Or investi dans l'armée actuelle",{'text-anchor':'middle'});
  writeSvgText(chart,22,18,'Glwaare');
  for(const point of current.points){
    const trail=frames.slice(Math.max(0,played-12),played).map(frame=>frame.points.find(candidate=>candidate.id===point.id)).filter(Boolean);
    if(trail.length>1)chart.append(svg('polyline',{points:trail.map(item=>`${x(item.armyGold)},${y(item.glory)}`).join(' '),fill:'none',stroke:colors[point.policy],opacity:'.55','stroke-width':2}));
    const circle=svg('circle',{class:'point',cx:x(point.armyGold),cy:y(point.glory),r:selected===point.id?8:6,fill:colors[point.policy],'aria-selected':selected===point.id,tabindex:0,role:'button','aria-label':`${point.id} : ${number.format(point.armyGold)} Or investis, ${point.glory} Glwaare`});
    circle.addEventListener('click',()=>{selected=point.id;render()});
    circle.addEventListener('keydown',event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();selected=point.id;render()}});
    chart.append(circle);
    writeSvgText(chart,x(point.armyGold)+10,y(point.glory)-9,point.id,{fill:colors[point.policy]});
  }
  for(const village of current.villages||[]){
    const cx=x(village.armyGold),cy=y(village.glory),size=selected===village.id?10:8;
    const marker=svg('path',{class:'point village-point',d:`M ${cx} ${cy-size} L ${cx+size} ${cy} L ${cx} ${cy+size} L ${cx-size} ${cy} Z`,fill:colors.village,'aria-selected':selected===village.id,tabindex:0,role:'button','aria-label':`${village.id} : ${number.format(village.armyGold)} Or de garnison, ${village.glory} Glwaare`});
    marker.addEventListener('click',()=>{selected=village.id;render()});
    marker.addEventListener('keydown',event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();selected=village.id;render()}});
    chart.append(marker);
    writeSvgText(chart,cx+12,cy-10,village.id,{fill:colors.village});
  }
  $('#frame-weather').textContent=`Tick ${current.tick} · météo : ${current.weather}`;
}
function renderPlayer(){
  const frame=frames[played-1];
  const point=[...(frame?.points||[]),...(frame?.villages||[])].find(candidate=>candidate.id===selected)||frame?.points[0];
  if(!point)return;
  selected=point.id;
  $('#player-title').textContent=point.kind==='village'?`${point.id} · Village palier ${point.glory}`:`${point.id} · ${names[point.policy]||point.policy}`;
  const detail=$('#player-detail');detail.replaceChildren();
  for(const [label,value] of [['Glwaare',point.glory],[point.kind==='village'?"Or de garnison":"Or investi dans l'armée",number.format(point.armyGold)],["Or disponible",number.format(point.gold)]]){
    const dt=document.createElement('dt'),dd=document.createElement('dd');dt.textContent=label;dd.textContent=String(value);detail.append(dt,dd);
  }
  const note=document.createElement('p');note.className='hint';note.textContent=frame.rwaa===point.id?`Rwaa · ${frame.rwaaPv} PV`:frame.candidate===point.id?`Prétendant · ${frame.candidateHours}/24 ticks`:'';detail.append(note);
}
function renderRanking(){
  const list=$('#era-ranking');list.replaceChildren();
  const points=[...(frames[played-1]?.points||[])].sort((a,b)=>b.glory-a.glory||a.id.localeCompare(b.id));
  points.forEach((point,index)=>{
    const row=document.createElement('li'),button=document.createElement('button'),swatch=document.createElement('span'),score=document.createElement('strong');
    button.type='button';button.setAttribute('aria-current',String(point.id===selected));
    swatch.className='swatch';swatch.style.background=colors[point.policy];score.textContent=String(point.glory);
    button.append(`${index+1}. `,swatch,document.createTextNode(point.id),score);
    button.addEventListener('click',()=>{selected=point.id;render()});row.append(button);list.append(row);
  });
}
function eventLabel(event){
  if(event.type==='combat')return `${event.attacker} → ${event.defender} · ${event.winner==='attacker'?'victoire attaquante':event.winner==='defender'?'victoire défensive':'nul'}${event.surrender?' · reddition':''}`;
  if(event.type==='rwaa')return `${event.actor} devient Rwaa`;
  if(event.type==='rwaa-ended')return `Fin du règne de ${event.actor}`;
  if(event.type==='candidate')return `${event.actor} devient prétendant`;
  if(event.type==='village')return `${event.id} apparaît`;
  if(event.type==='rejected')return `${event.actor} · ${event.action} refusé`;
  return `${event.actor||'Jeu'} · ${event.type}`;
}
function renderEvents(){
  const list=$('#era-events');list.replaceChildren();
  const visible=events.filter(event=>event.tick<=played).slice(-18).reverse();
  for(const event of visible){
    const item=document.createElement('li');item.textContent=eventLabel(event);
    const small=document.createElement('small');small.textContent=`Tick ${event.tick}`;item.append(small);
    list.append(item);
  }
}
function render(){refreshProgress();renderPlayer();renderChart();renderRanking();renderEvents()}
async function compute(){
  if(computing||!runId)return;
  computing=true;
  const activeRun=runId;
  try{
    while(computed<total&&runId===activeRun){
      const steps=computed===0?1:Math.min(8,total-computed);
      const result=await api('bagaar-step',{runId:activeRun,steps});
      if(runId!==activeRun)return;
      frames.push(...result.frames);events.push(...result.events);computed=result.tick;combatCount=result.combatCount;
      if(played===0&&frames.length){played=1;render()}
      refreshProgress();
      if(result.done){setStatus('Ère calculée');break}
      await new Promise(resolve=>setTimeout(resolve,0));
    }
  }catch(error){$('#bagaar-error').textContent=error.message;setStatus('Calcul interrompu')}
  finally{computing=false;if(runId!==activeRun&&runId)compute()}
}
function play(){
  if(!playing||frames.length===0)return;
  if(played<frames.length){played++;render()}
  if(played>=total&&computed>=total){playing=false;$('#toggle-play').textContent='Revoir'}
}
let playbackTimer=null;
function schedulePlayback(){clearInterval(playbackTimer);const speed=Number($('#play-speed').value);playbackTimer=setInterval(play,600/speed)}
async function loadProfile(){
  let stored=null;try{stored=sessionStorage.getItem('waar-bagaar-profile-v1')}catch{}
  profile=stored?JSON.parse(stored):(await fetch('/api/default-profile').then(response=>response.json())).data.profile;
  $('#preset-name').textContent=profile.label||profile.id;
}
async function start(){
  $('#bagaar-error').textContent='';
  const seed=Number($('#era-seed').value),days=Number($('#era-days').value);
  const accounts=['rageux','grenouille','ascenseur','fermier','scripteur'].map(policy=>({id:policy,policy,...(policy==='grenouille'?{soldierParadigm:$('#soldier-frog').checked}:{})}));
  const result=await api('bagaar-start',{profile,seed,totalTicks:days*24,accounts});
  runId=result.runId;frames=[];events=[];played=0;computed=0;total=result.totalTicks;combatCount=0;selected=null;playing=true;
  try{localStorage.setItem('waar-bagaar-run-v1',runId)}catch{}
  $('#toggle-play').disabled=false;$('#toggle-play').textContent='Pause';
  $('#export-era').hidden=false;$('#export-era').href=`/bagaar-export/${runId}.json`;render();setStatus('Calcul en cours');compute();
}
async function resume(){
  let previous=null;try{previous=localStorage.getItem('waar-bagaar-run-v1')}catch{}
  if(!previous)return;
  try{
    const result=await api('bagaar-resume',{runId:previous});
    runId=previous;frames=result.frames;events=result.events;computed=result.tick;total=result.totalTicks;combatCount=result.combatCount;played=frames.length?1:0;playing=true;
    $('#toggle-play').disabled=false;$('#export-era').hidden=false;$('#export-era').href=`/bagaar-export/${runId}.json`;
    render();setStatus(result.done?'Ère calculée':'Calcul repris');if(!result.done)compute();
  }catch{try{localStorage.removeItem('waar-bagaar-run-v1')}catch{}}
}
$('#start-era').addEventListener('click',()=>start().catch(error=>{$('#bagaar-error').textContent=error.message;setStatus('Erreur')}));
$('#toggle-play').addEventListener('click',()=>{if(played>=frames.length&&computed>=total)played=0;playing=!playing;$('#toggle-play').textContent=playing?'Pause':'Lecture';render()});
$('#play-speed').addEventListener('change',schedulePlayback);
$('#frame-seek').addEventListener('input',event=>{played=Number(event.target.value);playing=false;$('#toggle-play').textContent='Lecture';render()});
schedulePlayback();loadProfile().then(resume).catch(error=>{$('#bagaar-error').textContent=error.message});
})();
