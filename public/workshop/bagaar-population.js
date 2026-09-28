/* Population composition and shared Lua library. No game decisions live here. */
(() => {
  'use strict';
  const $=selector=>document.querySelector(selector);
  const native=['rageux','grenouille','ascenseur','fermier','scripteur','casual'];
  const labels={rageux:'Le Rageux',grenouille:'La Grenouille',ascenseur:"L’Ascenseur",fermier:'Le Fermier',scripteur:'Le Scripteur',casual:'Le Casual'};
  const schedules=['all-day','office','evening','early','casual-morning','casual-noon','casual-evening','casual-night'];
  const defaults={rageux:['Axel','Bruno','Chloé','Dorian'],grenouille:['Éloïse','Farid','Gaëlle','Hugo'],ascenseur:['Iris','Jules','Kamel','Léa'],fermier:['Malo','Nina','Oscar','Pauline'],scripteur:['Quentin','Romane','Sami','Talia'],casual:['Ugo','Victoire','William','Zoé']};
  let api,onTest,library={scripts:[],populations:[]},players=[],spares=[],sequence=0,editing=null,draftSource='',draftSchema=[];
  const notice=message=>{$('#population-message').textContent=message};
  const editorNotice=message=>{$('#lua-editor-message').textContent=message};
  const nextId=()=>`player-${++sequence}`;
  const make=(policy,name,spare=false)=>({id:nextId(),name:name||`Joueur ${sequence}`,policy,activity:policy==='casual'?'casual-morning':'all-day',aggressionPercent:100,parameters:{},...(spare?{weight:1}:{})});
  function initial(){for(const key of native)for(const name of defaults[key])players.push(make(key,name))}
  const script=id=>library.scripts.find(item=>item.id===id);
  const sourceLabel=item=>`${item.metadata.profile} · ${item.metadata.author} · v${item.metadata.version}`;
  function choices(select,value){
    select.replaceChildren();
    for(const key of native){const option=new Option(labels[key],`native:${key}`);select.add(option)}
    for(const item of library.scripts){const option=new Option(sourceLabel(item),`lua:${item.id}`);select.add(option)}
    if(draftSource)select.add(new Option('Brouillon Lua','lua:draft'));
    select.value=value;
  }
  function choice(row){return row.policy==='lua'?`lua:${row.scriptKey}`:`native:${row.policy}`}
  function applyChoice(row,value){
    const [kind,key]=value.split(':');row.policy=kind==='lua'?'lua':key;
    if(kind==='lua')row.scriptKey=key;else delete row.scriptKey;
    row.parameters={};render();
  }
  function field(label,input){const root=document.createElement('label');root.textContent=label;root.append(input);return root}
  function parameterFields(root,row){
    if(row.policy!=='lua')return;
    const schema=row.scriptKey==='draft'?draftSchema:(script(row.scriptKey)?.parameters||[]);
    for(const entry of schema){
      const input=document.createElement('input');input.type=entry.type==='toggle'?'checkbox':'range';
      if(entry.type==='toggle')input.checked=row.parameters[entry.name]??entry.default;
      else {input.min=entry.min;input.max=entry.max;input.step=entry.step;input.value=row.parameters[entry.name]??entry.default}
      const output=document.createElement('output');output.textContent=entry.type==='toggle'?(input.checked?'Oui':'Non'):input.value;
      input.title=entry.description||entry.name;
      input.oninput=()=>{row.parameters[entry.name]=entry.type==='toggle'?input.checked:Number(input.value);output.textContent=entry.type==='toggle'?(input.checked?'Oui':'Non'):input.value};
      const control=field(entry.description||entry.name,input);control.prepend(document.createTextNode(`${entry.name} · `));control.append(output);root.append(control);
    }
  }
  function rowElement(row,spare){
    const root=document.createElement('article');root.className='bagaar-population-row';
    const select=document.createElement('select');choices(select,choice(row));select.setAttribute('aria-label','Profil du joueur');select.onchange=()=>applyChoice(row,select.value);root.append(field('Profil',select));
    if(!spare){const name=document.createElement('input');name.value=row.name;name.maxLength=64;name.oninput=()=>{row.name=name.value};root.append(field('Nom',name))}
    const activity=document.createElement('select');for(const value of schedules)activity.add(new Option(value,value));activity.value=row.activity;activity.onchange=()=>{row.activity=activity.value};root.append(field('Activité',activity));
    const aggression=document.createElement('input');aggression.type='number';aggression.min=1;aggression.max=200;aggression.step=1;aggression.value=row.aggressionPercent;aggression.onchange=()=>{row.aggressionPercent=Number(aggression.value)};root.append(field('Agressivité',aggression));
    if(spare){const weight=document.createElement('input');weight.type='number';weight.min=.01;weight.max=100;weight.step=.01;weight.value=row.weight;weight.onchange=()=>{row.weight=Number(weight.value)};root.append(field('Poids',weight))}
    parameterFields(root,row);
    if(row.policy==='lua'){const edit=document.createElement('button');edit.type='button';edit.textContent='Éditer le script';edit.onclick=()=>openEditor(row);root.append(edit)}
    const remove=document.createElement('button');remove.type='button';remove.textContent='✕';remove.title='Supprimer ce joueur';remove.setAttribute('aria-label','Supprimer ce joueur');remove.onclick=()=>{const list=spare?spares:players;list.splice(list.indexOf(row),1);render()};root.append(remove);
    return root;
  }
  function render(){
    choices($('#population-choice'),$('#population-choice').value||'native:rageux');
    $('#population-players').replaceChildren(...players.map(row=>rowElement(row,false)));
    $('#population-spares').replaceChildren(...spares.map(row=>rowElement(row,true)));
    $('#population-panel-count').textContent=`${players.length} joueurs · ${spares.length} spares`;
    $('#population-player-summary').textContent=`Joueurs sélectionnés (${players.length})`;
    $('#add-player').disabled=players.length>=32;
    const saved=$('#saved-populations'),selected=saved.value;saved.replaceChildren(new Option('Choisir une population',''));
    for(const item of library.populations)saved.add(new Option(item.name,item.id));saved.value=selected;
  }
  async function refresh(){library=await api('bagaar-library');render()}
  function escaped(text){return text.replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;')}
  function highlight(source){
    const expression=/(--[^\n]*|"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|\b(?:local|function|return|if|then|else|elseif|end|for|while|do|and|or|not|nil|true|false)\b)/g;
    let result='',position=0,match;
    while((match=expression.exec(source))){result+=escaped(source.slice(position,match.index));result+=`<span class="${match[0].startsWith('--')?'lua-comment':/["']/.test(match[0][0])?'lua-string':'lua-keyword'}">${escaped(match[0])}</span>`;position=expression.lastIndex}
    return result+escaped(source.slice(position))+'\n';
  }
  function updateHighlight(){const source=$('#lua-editor-source');$('#lua-highlight').innerHTML=highlight(source.value)}
  function placeEditor(){
    const editor=$('#lua-editor');if(!editor.open)return;
    let top=12,height=window.innerHeight-24;
    if(window.frameElement){
      const frame=window.frameElement.getBoundingClientRect();
      const header=window.parent.document.querySelector('.app-header')?.getBoundingClientRect().bottom||0;
      const visibleTop=Math.max(frame.top+12,header+12);
      const visibleBottom=Math.min(frame.bottom-12,window.parent.innerHeight-12);
      top=visibleTop-frame.top;height=Math.max(280,visibleBottom-visibleTop);
    }
    editor.style.setProperty('--lua-editor-top',`${Math.round(top)}px`);
    editor.style.setProperty('--lua-editor-height',`${Math.round(height)}px`);
  }
  async function openEditor(row=null){
    editing=row;editorNotice('');
    if(row?.policy==='lua'&&row.scriptKey!=='draft'){
      const loaded=await api('bagaar-script-load',{id:row.scriptKey});$('#lua-editor-source').value=loaded.source;
    }else $('#lua-editor-source').value=draftSource;
    updateHighlight();$('#lua-editor').showModal();placeEditor();
  }
  async function check(){
    const source=$('#lua-editor-source').value,checked=await api('bagaar-script-check',{source});
    draftSource=source;draftSchema=checked.parameters;
    editorNotice(`Script valide · ${checked.metadata.profile} par ${checked.metadata.author} · ${checked.parameters.length} paramètre(s)`);
    return checked;
  }
  async function prepare(){
    if(players.length<2||players.length>32)throw new Error('Choisissez entre 2 et 32 joueurs.');
    const invalid=[...players,...spares].find(row=>!Number.isInteger(row.aggressionPercent)||row.aggressionPercent<1||row.aggressionPercent>200);
    if(invalid)throw new Error(`Agressivité invalide pour ${invalid.name||invalid.policy} : choisissez de 1 à 200 %.`);
    const luaScripts={};
    for(const row of [...players,...spares])if(row.policy==='lua'&&!luaScripts[row.scriptKey]){
      luaScripts[row.scriptKey]=row.scriptKey==='draft'?draftSource:(await api('bagaar-script-load',{id:row.scriptKey})).source;
      if(!luaScripts[row.scriptKey])throw new Error('Script Lua manquant.');
    }
    const clean=(row,spare)=>({...(spare?{}:{id:row.id,name:row.name}),policy:row.policy,
      activity:row.activity,aggressionPercent:row.aggressionPercent,
      ...(row.policy==='lua'?{scriptKey:row.scriptKey,parameters:row.parameters}:{}),
      ...(spare?{weight:row.weight}:{})});
    return {accounts:players.map(row=>clean(row,false)),spares:spares.map(row=>clean(row,true)),
      ...(Object.keys(luaScripts).length?{luaScripts}:{})};
  }
  async function init(apiCall,testCallback){
    api=apiCall;onTest=testCallback;initial();
    $('#add-player').onclick=()=>{const [kind,key]=$('#population-choice').value.split(':');const row=make(kind==='lua'?'lua':key);if(kind==='lua')row.scriptKey=key;players.push(row);render()};
    $('#add-spare').onclick=()=>{const [kind,key]=$('#population-choice').value.split(':');const row=make(kind==='lua'?'lua':key,'',true);if(kind==='lua')row.scriptKey=key;spares.push(row);render()};
    $('#save-population').onclick=async()=>{try{if([...players,...spares].some(row=>row.scriptKey==='draft'))throw new Error('Publiez le brouillon avant de partager cette population.');const data=await prepare();await api('bagaar-population-save',{name:$('#population-name').value,accounts:data.accounts,spares:data.spares});await refresh();notice('Population partagée sauvegardée.')}catch(error){notice(error.message)}};
    $('#load-population').onclick=async()=>{try{const saved=await api('bagaar-population-load',{id:$('#saved-populations').value});players=saved.accounts;spares=saved.spares;sequence=Math.max(sequence,...players.map(row=>Number(row.id?.match(/\d+$/)?.[0]||0)));render();notice(`Population chargée : ${saved.name}`)}catch(error){notice(error.message)}};
    $('#new-lua').onclick=()=>{draftSource='';openEditor()};
    $('#upload-lua').onclick=()=>$('#lua-file').click();
    $('#lua-file').onchange=async event=>{const file=event.target.files[0];if(!file)return;draftSource=await file.text();openEditor();event.target.value=''};
    $('#lua-close').onclick=()=>$('#lua-editor').close();
    window.addEventListener('resize',placeEditor);
    if(window.frameElement){window.parent.addEventListener('scroll',placeEditor,{passive:true});window.parent.addEventListener('resize',placeEditor)}
    $('#lua-editor-source').oninput=updateHighlight;
    $('#lua-editor-source').onscroll=()=>{$('#lua-highlight').scrollTop=$('#lua-editor-source').scrollTop;$('#lua-highlight').scrollLeft=$('#lua-editor-source').scrollLeft};
    $('#lua-tutorial').onclick=async()=>{try{$('#lua-editor-source').value=await (await fetch('/bagaar-tutoriel.lua')).text();updateHighlight();editorNotice('Tutoriel chargé comme brouillon.')}catch(error){editorNotice(error.message)}};
    $('#lua-check').onclick=()=>check().catch(error=>editorNotice(error.message));
    $('#lua-test').onclick=async()=>{try{await check();if(!editing){if(players.length>=32)throw new Error('Population initiale pleine.');editing=make('lua','Joueur tutoriel');players.push(editing)}editing.policy='lua';editing.scriptKey='draft';editing.parameters={};render();$('#lua-editor').close();await onTest()}catch(error){editorNotice(error.message)}};
    $('#lua-publish').onclick=async()=>{try{await check();const saved=await api('bagaar-script-publish',{source:draftSource});await refresh();if(editing){editing.policy='lua';editing.scriptKey=saved.id;editing.parameters={}}render();editorNotice(`Version publiée : ${sourceLabel(saved)}`)}catch(error){editorNotice(error.message)}};
    await refresh();
  }
  window.BagaarPopulation={init,prepare};
})();
