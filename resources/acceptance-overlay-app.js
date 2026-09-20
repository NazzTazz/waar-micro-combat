(() => {
  'use strict';
  const started = performance.now();
  const diagnostics = window.__waarOverlayDiagnostics = {echartsVersion:null,initialRenderMs:null,viewChanges:[],lastVisibleRows:0,errors:[],imports:[],exports:0,mutations:0};
  const data = JSON.parse(document.querySelector('#overlay-data').textContent);
  const model = globalThis.WaarAcceptanceZonesModel;
  const ui = data.ui || {};
  const referenceAvailable = ui.referenceAvailable !== false && data.legacyReference?.available !== false;
  const chartNode = document.querySelector('#chart');
  const errorNode = document.querySelector('#chart-error');
  if (typeof window.echarts === 'undefined' || !model) {
    chartNode.hidden=true; errorNode.style.display='block'; diagnostics.errors.push('editor_dependency_unavailable'); return;
  }
  diagnostics.echartsVersion=echarts.version;

  const $ = selector => document.querySelector(selector);
  const axis=$('#axis'), side=$('#side'), scenario=$('#scenario'), zoneSide=$('#zone-side'), endpoint=$('#endpoint');
  const legacyLayer=$('#legacy-layer'), zonesLayer=$('#zones-layer'), allZones=$('#all-zones'), motion=$('#motion');
  const pairView=$('#pair-view');
  const campColors={attacker:'#63d6c5',defender:'#ed8078'};
  const editToggle=$('#edit-toggle'), circleMode=$('#circle-mode'), zoneForm=$('#zone-form');
  const centerX=$('#center-x'), centerY=$('#center-y'), radiusX=$('#radius-x'), radiusY=$('#radius-y');
  const confirmZone=$('#confirm-zone'), disableZone=$('#disable-zone'), deleteZone=$('#delete-zone');
  const confirmDrafts=$('#confirm-drafts'), undoButton=$('#undo'), redoButton=$('#redo'), reanchorButton=$('#reanchor');
  const linkPairButton=$('#link-pair');
  const importInput=$('#import-file'), exportButton=$('#export-zones'), saveState=$('#save-state'), importStatus=$('#import-status');
  const colors=['#63d6c5','#e8ad45','#ed8078','#a98cf5','#78aaf2','#df86be'];
  const unitLabels={soldier:'Soldats',spearman:'Lanciers',archer:'Archers',knight:'Chevaliers'};
  const axisLabels=Object.fromEntries(data.axes.y.map(item=>[item.id,item.label]));
  const scenarioRows=new Map();
  data.rows.forEach(row=>{if(!scenarioRows.has(row.scenarioId))scenarioRows.set(row.scenarioId,row);});
  data.axes.y.forEach(item=>axis.add(new Option(ui.canonicalMonotypeObjectives?({survivors:'Effectifs survivants — objectif',economicValue:'Valeur économique — même objectif',structure:'Structure restante — diagnostic seul'}[item.id]||item.label):item.label,item.id)));
  scenarioRows.forEach(row=>scenario.add(new Option(row.scenarioLabel,row.scenarioId)));
  document.title=ui.title||'Soufflerie Waar — calque Legacy';
  $('#context').textContent=referenceAvailable?`${data.experiment.id} · ${data.legacyReference.rulesetVersion} · ECharts 5.6.0`:`${data.experiment.id} · budget ${data.comparisonProfile.commonBudget?.toLocaleString('fr-FR')||'n/a'} · ECharts 5.6.0`;
  legacyLayer.checked=referenceAvailable;$('#legacy-control').hidden=!referenceAvailable;$('#legacy-legend').hidden=!referenceAvailable;
  $('#zones-control-label').textContent=ui.zoneLabel||'Zones proposées';$('#zone-legend-label').textContent=ui.zoneLabel||'Tolérance proposée';
  $('#color-legend').hidden=!ui.colorLabel;$('#color-legend').textContent=ui.colorLabel||'';
  $('#pair-view-control').hidden=!ui.pairView;$('#pair-legend').hidden=!ui.pairView;
  pairView.checked=!!ui.pairView;
  if(ui.pairView){side.value='both';$('#all-zones-label').textContent='Afficher les zones des autres paires';}
  endpoint.value=ui.defaultEndpoint||'base';
  if(Array.isArray(ui.editableEndpoints))for(const option of endpoint.options)option.disabled=!ui.editableEndpoints.includes(option.value);
  if(ui.noReferenceMessage)$('#legacy-unavailable').textContent=ui.noReferenceMessage;

  const context={
    editorSchema:ui.workshop?'waar-consequence-editor-zones/0.1':undefined,
    experimentId:data.experiment.id,
    corpusFingerprint:data.legacyReference.corpusFingerprint,
    comparisonProfileId:data.comparisonProfile.id,
    valuationId:data.comparisonProfile.valuationId,
    referenceId:data.objectiveReference?.id||data.legacyReference.id,
    initialZones:model.clone(data.zonesDocument.zones)
  };
  const expectedById=new Map(context.initialZones.map(zone=>[zone.id,zone]));
  let classified=model.validateAndClassify(data.zonesDocument,context);
  let zonesDocument=classified.document;
  let staleZoneIds=classified.staleZoneIds;
  let lastExported=model.serialize(zonesDocument);
  let workshopComparison=false;
  let editMode=false, pendingRender=started, dragChanged=false;
  const editorState=model.createEditorInteractionState();
  const undoStack=[], redoStack=[];
  const reducedMotion=matchMedia('(prefers-reduced-motion: reduce)');
  const chart=echarts.init(chartNode,null,{renderer:'canvas'});
  Object.defineProperty(diagnostics,'chart',{value:chart,enumerable:false});

  function pct(value,digits=1){return value==null?'n/a':`${(value*100).toFixed(digits)} %`;}
  function esc(value){return String(value).replace(/[&<>'"]/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));}
  function role(row){return row.side==='attacker'?'Attaquant':'Défenseur';}
  function rowKey(row){return `${row.scenarioId}:${row.side}`;}
  function shortLabel(label,maximum=28){return label.length>maximum?`${label.slice(0,Math.max(1,maximum-1))}…`:label;}
  function clamp(value,min,max){return Math.min(max,Math.max(min,value));}
  function visibleRows(){return data.rows.filter(row=>(!pairView.checked||row.scenarioId===scenario.value)&&(side.value==='focus'?row.focus:side.value==='both'||row.side===side.value));}
  function selectedRow(){
    const rows=data.rows.filter(row=>row.scenarioId===scenario.value);
    if(side.value==='attacker'||side.value==='defender')return rows.find(row=>row.side===side.value)||rows[0];
    if(side.value==='focus')return rows.find(row=>row.focus)||rows[0];
    return rows.find(row=>row.side===zoneSide.value)||rows[0];
  }
  function colorOf(row){if(ui.pairView&&row.scenarioId===scenario.value)return campColors[row.side];const colorKey=ui.colorBy==='attackerType'?row.scenarioId.split('-vs-')[0]:row.scenarioId;const keys=ui.colorBy==='attackerType'?(data.designSurface?.unitOrder||[]):[...scenarioRows.keys()];return colors[Math.max(0,keys.indexOf(colorKey))%colors.length];}
  function armyLabel(row){return Object.entries(row.army).filter(([,count])=>count>0).map(([unit,count])=>`${Number(count).toLocaleString('fr-FR')} ${unitLabels[unit]||unit}`).join(', ');}
  function armyBudget(row){return Object.entries(row.army).reduce((total,[unit,count])=>total+count*(data.comparisonProfile.valuation?.[unit]||0),0);}
  function objectiveMetric(metric=axis.value){return ui.canonicalMonotypeObjectives?(metric==='structure'?null:'survivors'):metric;}
  function findZone(row,which=endpoint.value){return row?zonesDocument.zones.find(zone=>zone.scenarioId===row.scenarioId&&zone.side===row.side&&zone.yMetric===objectiveMetric()&&zone.endpoint===which):null;}
  function zoneState(zone){return zone?(ui.workshop?(zone.enabled?'pending':'disabled'):model.state(zone,data.rows,staleZoneIds)):'missing';}
  function stateLabel(state){return ({pending:'À évaluer en PHP',inside:'Dedans',outside:'Dehors','not-applicable':'Non applicable',disabled:'Désactivée',stale:'Provenance incompatible',missing:'Zone absente'})[state]||state;}
  function approvalLabel(zone){return zone?.approval==='confirmed'?'Confirmée':'Brouillon';}
  function recomputeCompatibility(){classified=model.validateAndClassify(zonesDocument,context);zonesDocument=classified.document;staleZoneIds=classified.staleZoneIds;}
  function markGeometryChanged(zone){zone.source.modifiedManually=true;}
  function linkHorizontalChange(zone,previousX,previousRadius){if(ui.complementaryWinRates&&(zone.center.x!==previousX||zone.radii.x!==previousRadius))model.linkWinRate(zonesDocument,zone.id);}
  function currentSerialized(){return model.serialize(zonesDocument);}
  function updateDirty(){const dirty=currentSerialized()!==lastExported;saveState.textContent=dirty?'Modifications non exportées':'Document exporté';saveState.className=dirty?'dirty':'saved';}
  function pushMutation(before,label){const after=currentSerialized();if(before===after)return;undoStack.push({document:JSON.parse(before),label});redoStack.length=0;diagnostics.mutations++;updateDirty();updateHistoryButtons();notifyWorkshop();}
  function mutate(label,operation){const before=currentSerialized();operation();recomputeCompatibility();pushMutation(before,label);renderChart(label,false);}
  function updateHistoryButtons(){undoButton.disabled=undoStack.length===0;redoButton.disabled=redoStack.length===0;}
  function restore(snapshot){zonesDocument=model.clone(snapshot);editorState.reset();recomputeCompatibility();updateDirty();updateHistoryButtons();renderChart('history',false);notifyWorkshop();}
  function undo(){const action=undoStack.pop();if(!action)return;redoStack.push({document:model.clone(zonesDocument),label:action.label});restore(action.document);}
  function redo(){const action=redoStack.pop();if(!action)return;undoStack.push({document:model.clone(zonesDocument),label:action.label});restore(action.document);}

  function tooltip(params){
    const point=params.data||{};if(!point.row)return '';
    const row=point.row,metric=axis.value;
    const y=point.kind==='legacy'?row.legacy.coordinates.y[metric]:point.value[1];
    const pointEndpoint=ui.workshop?(point.kind==='base'?'Profil mesuré':point.kind==='tip'?'Candidat comparé':workshopComparison?'Mesure et candidat identiques':'Profil mesuré'):point.kind==='base'?'Base témoin':point.kind==='tip'?'Pointe candidate':point.kind==='equal'?'Témoin = candidat':'Référence Legacy';
    const zone=point.kind==='base'||point.kind==='tip'?findZone(row,point.kind):null;
    const status=zone?`<br>Contrainte : ${esc(approvalLabel(zone))}, ${esc(stateLabel(zoneState(zone)))}`:'';
    return `<strong>${esc(row.scenarioLabel)}</strong><br>${esc(role(row))} · ${esc(pointEndpoint)}<br>Victoires : ${esc(pct(point.value[0]))}<br>${esc(axisLabels[metric])} : ${esc(pct(y))}<br>Unités : ${esc(Object.entries(row.army).filter(([,n])=>n>0).map(([u,n])=>`${u} ${n}`).join(', '))}<br>n = ${esc(point.kind==='legacy'?row.legacy.repetitions:row.micro.baseline.iterations)}${point.kind==='legacy'?`<br>${esc(data.legacyReference.id)}`:''}${status}`;
  }
  function point(row,kind,x,y){const color=colorOf(row),open=kind==='base',pairPosition=ui.pairView&&row.scenarioId===scenario.value?(row.side==='attacker'?'top':'bottom'):null;return{name:row.scenarioLabel,value:[x,y],row,kind,scenarioId:row.scenarioId,symbol:ui.pairView&&row.side==='defender'?'diamond':'circle',itemStyle:{color:open?'#171d20':color,borderColor:color,borderWidth:open?2:1},label:{position:pairPosition||(x>0.72?'left':'right')}};}
  function visibleZones(rows,metric,selectedId){
    if(!zonesLayer.checked)return [];
    const visibleKeys=new Set(rows.map(rowKey));
    return zonesDocument.zones.filter(zone=>zone.yMetric===objectiveMetric(metric)&&visibleKeys.has(`${zone.scenarioId}:${zone.side}`)&&(!ui.pairView||allZones.checked||zone.scenarioId===selectedId));
  }
  function zoneSeries(rows,metric,selectedId){
    const zones=visibleZones(rows,metric,selectedId);
    if(!zones.length)return [];
    return [{id:'acceptance-zones',name:'Zones d’acceptation',type:'custom',coordinateSystem:'cartesian2d',silent:false,clip:true,z:1,tooltip:{show:false,trigger:'none'},data:zones.map(zone=>({value:[zone.center.x,zone.center.y,zone.radii.x,zone.radii.y],zone})),renderItem:(params,api)=>{
      const item=zones[params.dataIndex],center=api.coord([item.center.x,item.center.y]),size=api.size([item.radii.x*2,item.radii.y*2]);
      const selected=item.scenarioId===selectedId&&item.side===zoneSide.value&&item.endpoint===endpoint.value,emphasized=selected||allZones.checked;
      const state=zoneState(item),disabled=state==='disabled',stale=state==='stale';
      const stroke=stale?'169,140,245':disabled?'172,168,159':ui.pairView&&item.scenarioId===selectedId?(item.side==='attacker'?'99,214,197':'237,128,120'):'232,173,69';
      return{type:'ellipse',cursor:'pointer',shape:{cx:center[0],cy:center[1],rx:Math.abs(size[0]/2),ry:Math.abs(size[1]/2)},style:{fill:`rgba(${stroke},${disabled ? .018 : selected ? .10 : emphasized ? .045 : .015})`,stroke:`rgba(${stroke},${selected ? .95 : ui.pairView&&item.scenarioId===selectedId ? .7 : emphasized ? .42 : .18})`,lineWidth:selected?2:1,lineDash:item.endpoint==='base'||disabled?[6,5]:null},emphasis:{style:{fill:`rgba(${stroke},.22)`,stroke:`rgba(${stroke},1)`,lineWidth:2}}};
    }}];
  }
  function zoneCenterSeries(rows,metric,selectedId){
    const zones=visibleZones(rows,metric,selectedId);
    if(!zones.length)return [];
    return [{id:'zone-centers',name:'Centres des objectifs',type:'scatter',z:8,symbolSize:20,cursor:'pointer',emphasis:{scale:1.25},tooltip:{formatter:params=>`Objectif ${params.data.zone.side==='attacker'?'attaquant':'défenseur'} · cliquer pour sélectionner`},data:zones.map(zone=>({value:[zone.center.x,zone.center.y],zone,symbol:zone.side==='defender'?'diamond':'circle',itemStyle:{color:'#171d20',borderColor:colorOf({scenarioId:zone.scenarioId,side:zone.side}),borderWidth:2}}))}];
  }
  function selectObjective(zone){
    if(editorState.hasActiveDrag())return;
    scenario.value=zone.scenarioId;zoneSide.value=zone.side;endpoint.value=zone.endpoint;
    if(side.value!=='both'&&side.value!==zone.side)side.value='both';
    renderChart('zone-selection',false);
  }
  function selectCenterObjective(zone){
    const position=chart.convertToPixel({xAxisIndex:0,yAxisIndex:0},[zone.center.x,zone.center.y]);
    const overlapping=visibleZones(visibleRows(),axis.value,scenario.value).filter(item=>{
      const other=chart.convertToPixel({xAxisIndex:0,yAxisIndex:0},[item.center.x,item.center.y]);
      return Math.hypot(other[0]-position[0],other[1]-position[1])<=2;
    });
    const selected=findZone(selectedRow());
    const current=overlapping.findIndex(item=>item.id===selected?.id);
    selectObjective(overlapping.length>1&&current>=0?overlapping[(current+1)%overlapping.length]:zone);
  }
  function highlightObjective(zone,highlight){
    if(!zone||editorState.hasActiveDrag())return;
    const index=visibleZones(visibleRows(),axis.value,scenario.value).findIndex(item=>item.id===zone.id);
    if(index>=0)chart.dispatchAction({type:highlight?'highlight':'downplay',seriesId:'acceptance-zones',dataIndex:index});
  }
  function pairLinkSeries(rows,metric){
    if(ui.workshop||!ui.pairView||!zonesLayer.checked)return [];
    const pair=rows.filter(row=>row.scenarioId===scenario.value);
    const attacker=pair.find(row=>row.side==='attacker'),defender=pair.find(row=>row.side==='defender');
    const attackZone=findZone(attacker,'tip'),defenseZone=findZone(defender,'tip');
    if(!attackZone||!defenseZone||!attackZone.enabled||!defenseZone.enabled)return [];
    return [{name:'Lien entre objectifs attaquant / défenseur',type:'lines',coordinateSystem:'cartesian2d',silent:true,z:2,symbol:['none','none'],lineStyle:{color:'#c9c4b9',width:2,type:'dotted',opacity:.85},data:[{coords:[[attackZone.center.x,attackZone.center.y],[defenseZone.center.x,defenseZone.center.y]]}]}];
  }
  function buildSeries(rows){
    const metric=axis.value,selectedId=scenario.value,lines=[],bases=[],tips=[],equals=[],legacy=[];
    rows.forEach(row=>{const x1=row.micro.vector.x.from,x2=row.micro.vector.x.to,y1=row.micro.vector.y[metric].from,y2=row.micro.vector.y[metric].to;if(x1==null||x2==null||y1==null||y2==null)return;
      const same=Math.abs(x1-x2)<1e-12&&Math.abs(y1-y2)<1e-12,color=colorOf(row),selected=row.scenarioId===selectedId;
      if(same)equals.push(point(row,'equal',x1,y1));else{lines.push({coords:[[x1,y1],[x2,y2]],row,lineStyle:{color,width:selected?4:2.5,type:row.side==='defender'?'dashed':'solid',opacity:selected?1:.62}});bases.push(point(row,'base',x1,y1));tips.push(point(row,'tip',x2,y2));}
      const legacyY=row.legacy.coordinates.y[metric];if(referenceAvailable&&legacyLayer.checked&&legacyY!=null)legacy.push(point(row,'legacy',row.legacy.coordinates.x,legacyY));});
    const label={show:true,position:'right',distance:8,color:'#f1eadf',fontSize:12,textBorderColor:'#0f1416',textBorderWidth:3,formatter:p=>{if(p.data.scenarioId!==selectedId)return '';const compact=chartNode.clientWidth<520;return compact?`${shortLabel(p.data.row.scenarioLabel,9)} · ${p.data.row.side==='attacker'?'A':'D'}`:`${shortLabel(p.data.row.scenarioLabel)} · ${role(p.data.row).slice(0,4)}.`;}};
    return[...zoneSeries(rows,metric,selectedId),...pairLinkSeries(rows,metric),...zoneCenterSeries(rows,metric,selectedId),{name:'Vecteur témoin → candidat',type:'lines',coordinateSystem:'cartesian2d',z:4,symbol:['none','arrow'],symbolSize:[0,11],data:lines,animationDurationUpdate:180},{name:'Base témoin',type:'scatter',z:5,symbol:'circle',symbolSize:10,data:bases,label,tooltip:{formatter:tooltip}},{name:'Pointe candidate',type:'scatter',z:6,symbol:'circle',symbolSize:10,data:tips,label,tooltip:{formatter:tooltip}},{name:'Égalité micro',type:'scatter',z:6,symbol:'circle',symbolSize:10,data:equals,label,tooltip:{formatter:tooltip}},{name:'Référence Legacy',type:'scatter',z:3,symbol:'diamond',symbolSize:11,data:legacy,itemStyle:{color:'#d9d3c7',borderColor:'#0f1416',borderWidth:1.5},tooltip:{formatter:tooltip}}];
  }

  function beginDrag(){editorState.beginDrag(currentSerialized());dragChanged=false;$('#zone-status').setAttribute('aria-live','off');diagnostics.dragStarts=(diagnostics.dragStarts||0)+1;}
  function dragZone(kind,pixel){
    if(!editorState.hasActiveDrag())return;
    const row=selectedRow(),zone=findZone(row);if(!zone||!Array.isArray(pixel))return;
    const previousX=zone.center.x,previousRadius=zone.radii.x;
    const coordinate=chart.convertFromPixel({xAxisIndex:0,yAxisIndex:0},pixel);if(!coordinate||!coordinate.every(Number.isFinite))return;
    if(kind==='center'){zone.center.x=clamp(coordinate[0],0,1);zone.center.y=clamp(coordinate[1],0,1);}
    else if(kind==='left'||kind==='right'){const radius=clamp(Math.abs(coordinate[0]-zone.center.x),model.MIN_RADIUS,model.MAX_RADIUS);zone.radii.x=radius;if(editorState.isCircle())zone.radii.y=radius;}
    else{const radius=clamp(Math.abs(coordinate[1]-zone.center.y),model.MIN_RADIUS,model.MAX_RADIUS);zone.radii.y=radius;if(editorState.isCircle())zone.radii.x=radius;}
    markGeometryChanged(zone);linkHorizontalChange(zone,previousX,previousRadius);dragChanged=true;syncEditorValues(zone);chart.setOption({series:buildSeries(visibleRows())},{replaceMerge:['series'],lazyUpdate:true});renderZoneStatus(row,zone);
  }
  function endDrag(){const dragBefore=editorState.finishDrag();if(!dragBefore)return;recomputeCompatibility();if(dragChanged)pushMutation(dragBefore,'Déplacement de zone');dragChanged=false;$('#zone-status').setAttribute('aria-live','polite');diagnostics.dragEnds=(diagnostics.dragEnds||0)+1;renderChart('zone-drag-end',false);}
  function cancelDrag(){const dragBefore=editorState.cancelDrag();if(!dragBefore)return;zonesDocument=JSON.parse(dragBefore);recomputeCompatibility();dragChanged=false;$('#zone-status').setAttribute('aria-live','polite');renderChart('zone-drag-cancel',false);}
  function editGraphics(){
    const row=selectedRow(),zone=findZone(row);if(!editMode||!zone||!zonesLayer.checked){diagnostics.handlePositions={};return [];}
    const positions={center:[zone.center.x,zone.center.y],left:[zone.center.x-zone.radii.x,zone.center.y],right:[zone.center.x+zone.radii.x,zone.center.y],top:[zone.center.x,zone.center.y+zone.radii.y],bottom:[zone.center.x,zone.center.y-zone.radii.y]};
    diagnostics.handlePositions=Object.fromEntries(Object.entries(positions).map(([kind,coordinate])=>[kind,chart.convertToPixel({xAxisIndex:0,yAxisIndex:0},coordinate)]));
    return Object.entries(positions).map(([kind,coordinate])=>{
      let moved=false;
      return {id:`zone-handle-${kind}`,type:'circle',position:chart.convertToPixel({xAxisIndex:0,yAxisIndex:0},coordinate),shape:{r:kind==='center'?8:6},draggable:true,z:100,cursor:kind==='center'?'move':kind==='left'||kind==='right'?'ew-resize':'ns-resize',style:{fill:kind==='center'?(ui.pairView?campColors[row.side]:'#e8ad45'):'#f1eadf',stroke:'#0f1416',lineWidth:2,shadowBlur:4,shadowColor:'#0008'},onclick:()=>{if(kind==='center'&&!moved)selectCenterObjective(zone);},ondragstart:()=>{moved=false;beginDrag();},ondrag:function(){moved=true;dragZone(kind,this.position);},ondragend:endDrag};
    });
  }
  function renderHandles(){chart.setOption({graphic:editGraphics()},{replaceMerge:['graphic'],lazyUpdate:false});}
  function renderChart(reason='initial',animateOverride=null){
    pendingRender=performance.now();const rows=visibleRows();diagnostics.lastVisibleRows=rows.length;
    const animate=animateOverride??(motion.checked&&!reducedMotion.matches&&!editMode);diagnostics.viewChanges.push({reason,requestedAt:pendingRender,visibleRows:rows.length});
    chart.setOption({animation:animate,animationDuration:animate?180:0,animationDurationUpdate:animate?180:0,animationEasing:'cubicOut',animationEasingUpdate:'cubicOut',aria:{enabled:true,decal:{show:false}},grid:{left:66,right:28,top:30,bottom:58,containLabel:false},tooltip:{show:!editMode,trigger:'item',triggerOn:editMode?'none':'mousemove|click',confine:true,backgroundColor:'#11181a',borderColor:'#526068',textStyle:{color:'#f1eadf',fontSize:12}},xAxis:{type:'value',min:0,max:1,interval:.25,name:'Taux de victoire',nameLocation:'middle',nameGap:36,axisLabel:{formatter:v=>`${v*100} %`,color:'#aca89f',fontSize:12},nameTextStyle:{color:'#f1eadf',fontWeight:600},splitLine:{lineStyle:{color:'#303a3e'}}},yAxis:{type:'value',min:0,max:1,interval:.25,name:axisLabels[axis.value],nameLocation:'middle',nameGap:48,axisLabel:{formatter:v=>`${v*100} %`,color:'#aca89f',fontSize:12},nameTextStyle:{color:'#f1eadf',fontWeight:600},splitLine:{lineStyle:{color:'#303a3e'}}},series:buildSeries(rows)},{notMerge:true,lazyUpdate:false});
    renderText(rows);requestAnimationFrame(renderHandles);
  }

  function syncEditorValues(zone){for(const [input,value] of [[centerX,zone?.center.x],[centerY,zone?.center.y],[radiusX,zone?.radii.x],[radiusY,zone?.radii.y]])input.value=value==null?'':Number((value*(ui.workshop?100:1)).toFixed(6));}
  function renderZoneStatus(row,zone){
    const status=$('#zone-status');status.replaceChildren();
    if(!zone){status.textContent=ui.canonicalMonotypeObjectives&&axis.value==='structure'?'Structure : diagnostic des dégâts partiels, sans objectif supplémentaire. Retrouvez votre zone dans les vues survivants ou valeur économique.':ui.editableEndpoints&&!ui.editableEndpoints.includes(endpoint.value)?'Cette extrémité sert de témoin et ne porte pas de contrainte.':'Cette zone a été supprimée.';return;}
    const current=zoneState(zone),badge=document.createElement('span');badge.className=`state ${current}`;badge.textContent=`${approvalLabel(zone)} · ${stateLabel(current)}`;status.append(badge);
    const detail=document.createElement('div');detail.className='values';detail.textContent=`Centre ${pct(zone.center.x,2)} / ${pct(zone.center.y,2)} · rayons ±${pct(zone.radii.x,2)} / ±${pct(zone.radii.y,2)}`;status.append(detail);
    if(zone.source.modifiedManually){const modified=document.createElement('small');modified.textContent=referenceAvailable?'Modifiée manuellement · centre Legacy original conservé':'Modifiée manuellement · centre initial conservé';status.append(modified);}
  }
  function renderEditor(row){
    zoneSide.value=row.side;const zone=findZone(row);const unavailable=!zone;
    $('#linked-win-rate').hidden=!ui.complementaryWinRates;
    const linked=!ui.complementaryWinRates||model.hasLinkedWinRate(zonesDocument,row.scenarioId);
    if(ui.complementaryWinRates)$('#linked-win-rate').textContent=linked?'Taux de victoire et rayon X liés entre les camps. Le Y attaquant reste indépendant du Y défenseur.':'Ces objectifs ont encore des X indépendants. Une modification horizontale ou le bouton ci-dessous lie la paire depuis la zone sélectionnée ; les valeurs Y sont conservées.';
    linkPairButton.hidden=linked||!zone;linkPairButton.disabled=!editMode;
    circleMode.checked=editorState.selectZone(zone?.id||null,!!zone&&Math.abs(zone.radii.x-zone.radii.y)<=model.BOUNDARY_TOLERANCE);
    zoneForm.hidden=unavailable||!zone;$('#zone-empty').hidden=!unavailable&&!!zone;syncEditorValues(zone);renderZoneStatus(row,zone);
    [centerX,centerY,radiusX,radiusY,circleMode,confirmZone,disableZone,deleteZone].forEach(control=>control.disabled=!editMode||!zone||unavailable);
    confirmDrafts.disabled=!editMode||(ui.canonicalMonotypeObjectives&&axis.value==='structure');
    if(ui.canonicalMonotypeObjectives)confirmDrafts.textContent='Confirmer les objectifs des 16 paires (32 maximum)';
    if(ui.canonicalMonotypeObjectives)$('#zone-empty').textContent=axis.value==='structure'?'Vue diagnostic : aucun objectif à saisir ici.':'Cette zone a été supprimée.';
    confirmZone.textContent=zone?.approval==='confirmed'?'Repasser en brouillon':'Confirmer la contrainte';
    disableZone.textContent=zone?.enabled===false?'Réactiver':'Désactiver';
    editToggle.textContent=editMode?'Quitter l’édition':'Modifier la zone';editToggle.setAttribute('aria-pressed',String(editMode));
    $('#edit-mode-label').textContent=editMode?'Édition':'Lecture';
    reanchorButton.hidden=staleZoneIds.size===0;reanchorButton.disabled=!editMode;
  }
  function renderText(rows){
    const row=selectedRow();if(!row)return;
    if(ui.pairView){$('#color-legend').hidden=pairView.checked;allZones.disabled=pairView.checked;}
    $('#selection-title').textContent=row.scenarioLabel;$('#selection-role').textContent=`${role(row)} · ${armyLabel(row)} · budget ${armyBudget(row).toLocaleString('fr-FR')} · ${row.micro.baseline.iterations} simulations micro`;
    const unavailable=!referenceAvailable||axis.value==='structure';$('#legacy-unavailable').style.display=unavailable?'block':'none';
    const cards=$('#metric-cards');cards.replaceChildren();
    [['Base témoin',row.micro.vector.x.from,row.micro.vector.y[axis.value].from,'base'],['Pointe candidate',row.micro.vector.x.to,row.micro.vector.y[axis.value].to,'tip'],['Référence Legacy',row.legacy.coordinates.x,row.legacy.coordinates.y[axis.value],'legacy']].forEach(([label,x,y,which])=>{if(which==='legacy'&&!referenceAvailable||ui.workshop&&which==='tip'&&!workshopComparison)return;if(ui.workshop)label=which==='base'?'Profil mesuré':'Candidat comparé';const card=document.createElement('div');card.className='metric-card';const strong=document.createElement('strong');strong.textContent=label;card.append(strong);const values=document.createElement('div');values.className='values';values.textContent=`X ${pct(x)} · Y ${pct(y)}`;card.append(values);if(which!=='legacy'){const zone=findZone(row,which);if(zone){const current=zoneState(zone),status=document.createElement('span');status.className=`state ${current}`;status.textContent=`${approvalLabel(zone)} · ${stateLabel(current)} · ±${pct(zone.radii.x,0)} / ±${pct(zone.radii.y,0)}`;card.append(status);}}cards.append(card);});
    const list=$('#scenario-list');list.replaceChildren();scenarioRows.forEach(item=>{const button=document.createElement('button');button.type='button';button.textContent=item.scenarioLabel;button.title=item.scenarioLabel;button.setAttribute('aria-current',item.scenarioId===scenario.value?'true':'false');button.addEventListener('click',()=>{scenario.value=item.scenarioId;renderChart('scenario-list');});list.append(button);});
    if(ui.workshop)renderMonotypes();
    renderEditor(row);renderGlobalSummary();renderTable(rows);renderProvenance();updateDirty();updateHistoryButtons();
  }
  function renderGlobalSummary(){if(ui.workshop){$('#summary').textContent='Les objectifs sont évalués côté PHP lors de la recherche.';return;}const totals=model.summary(zonesDocument,data.rows,staleZoneIds,context.initialZones.length);$('#summary').textContent=`Contraintes confirmées : ${totals.satisfied} / ${totals.confirmed} satisfaites · ${totals.drafts} brouillons · ${totals.disabled} désactivées · ${totals.stale} incompatibles · ${totals.notApplicable} non applicables · ${totals.missing} absentes. Estimations courantes, sans garantie statistique.`;}
  function renderTable(rows){const host=$('#table');host.replaceChildren();const table=document.createElement('table'),head=document.createElement('thead'),hr=document.createElement('tr');const headers=referenceAvailable?['Scénario','Camp','Armée','Base X/Y','Pointe X/Y','Legacy X/Y','Contrainte base','Contrainte pointe']:['Scénario','Camp','Armée','Base X/Y','Pointe X/Y','Budget exact','Base figée','Objectif pointe'];headers.forEach(label=>{const th=document.createElement('th');th.textContent=label;hr.append(th);});head.append(hr);table.append(head);const body=document.createElement('tbody');rows.forEach(row=>{const tr=document.createElement('tr'),base=findZone(row,'base'),tip=findZone(row,'tip'),ly=row.legacy.coordinates.y[axis.value];const zoneText=zone=>zone?`${approvalLabel(zone)} · ${stateLabel(zoneState(zone))}`:'Absente';const reference=referenceAvailable?(ly==null?'Indisponible':`${pct(row.legacy.coordinates.x)} / ${pct(ly)}`):armyBudget(row).toLocaleString('fr-FR');const values=[row.scenarioLabel,role(row),armyLabel(row),`${pct(row.micro.vector.x.from)} / ${pct(row.micro.vector.y[axis.value].from)}`,`${pct(row.micro.vector.x.to)} / ${pct(row.micro.vector.y[axis.value].to)}`,reference,referenceAvailable?zoneText(base):'Sans contrainte',zoneText(tip)];values.forEach(value=>{const td=document.createElement('td');td.textContent=value;tr.append(td);});body.append(tr);});table.append(body);host.append(table);}
  function renderProvenance(){const host=$('#provenance');host.replaceChildren();const title=document.createElement('strong');title.textContent='Provenance';const reference=referenceAvailable?`Référence ${data.legacyReference.id} · ruleset ${data.legacyReference.rulesetVersion}`:`Point de départ ${data.objectiveReference.label} · les centres initiaux ne sont pas des objectifs confirmés`;host.append(title,document.createElement('br'),document.createTextNode(reference),document.createElement('br'),document.createTextNode(`Sources ${data.legacyReference.sourceFingerprint}`),document.createElement('br'),document.createTextNode(`Corpus ${data.legacyReference.corpusFingerprint}`),document.createElement('br'),document.createTextNode(`Mesure ${data.comparisonProfile.id} / ${data.comparisonProfile.valuationId} · 80/110/130/350.`));}

  function applyNumericInput(event){const row=selectedRow(),zone=findZone(row);if(!zone)return;const input=event.target,value=Number(input.value)/(ui.workshop?100:1);if(input.value.trim()===''||!Number.isFinite(value)){importStatus.textContent='Valeur numérique invalide.';syncEditorValues(zone);return;}mutate('Saisie numérique',()=>{const previousX=zone.center.x,previousRadius=zone.radii.x;if(input===centerX)zone.center.x=clamp(value,0,1);else if(input===centerY)zone.center.y=clamp(value,0,1);else if(input===radiusX){zone.radii.x=clamp(value,model.MIN_RADIUS,model.MAX_RADIUS);if(editorState.isCircle())zone.radii.y=zone.radii.x;}else if(input===radiusY){zone.radii.y=clamp(value,model.MIN_RADIUS,model.MAX_RADIUS);if(editorState.isCircle())zone.radii.x=zone.radii.y;}markGeometryChanged(zone);linkHorizontalChange(zone,previousX,previousRadius);});}
  function reanchor(){mutate('Réancrage de provenance',()=>{zonesDocument.experimentId=context.experimentId;zonesDocument.corpusFingerprint=context.corpusFingerprint;zonesDocument.comparisonProfileId=context.comparisonProfileId;zonesDocument.valuationId=context.valuationId;zonesDocument.zones.forEach(zone=>{const expected=expectedById.get(zone.id);zone.source.referenceId=context.referenceId;zone.source.referencePointId=expected.source.referencePointId;zone.source.originalCenter=model.clone(expected.source.originalCenter);zone.source.modifiedManually=true;});});importStatus.textContent='Provenance réancrée explicitement sur l’expérience courante.';}
  function exportZones(){const contents=currentSerialized(),blob=new Blob([contents],{type:'application/json'}),url=URL.createObjectURL(blob),link=document.createElement('a'),safeId=zonesDocument.experimentId.replace(/[^a-z0-9._-]+/gi,'-').slice(0,100)||'waar';link.href=url;link.download=`${safeId}-acceptance-zones.json`;document.body.append(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),0);lastExported=contents;diagnostics.exports++;updateDirty();importStatus.textContent='Cahier des charges exporté.';}
  async function importZones(file){
    if(!file)return;if(file.size>model.MAX_IMPORT_BYTES){importStatus.textContent=`Import refusé : taille maximale ${model.MAX_IMPORT_BYTES/1024/1024} Mio.`;diagnostics.imports.push('too_large');return;}
    try{
      const text=await file.text();
      if(new TextEncoder().encode(text).length>model.MAX_IMPORT_BYTES)throw new Error(`taille maximale ${model.MAX_IMPORT_BYTES/1024/1024} Mio dépassée`);
      const raw=JSON.parse(text),migrated=raw?.schemaVersion!==model.SCHEMA_VERSION;
      const candidate=ui.canonicalMonotypeObjectives?model.importMonotypeObjectives(raw,context):model.validateAndClassify(raw,context);
      const converted=!!candidate.removedCount;
      zonesDocument=candidate.document;classified=candidate;staleZoneIds=candidate.staleZoneIds;
      editorState.reset();undoStack.length=0;redoStack.length=0;
      lastExported=migrated||converted?model.serialize(raw):model.serialize(zonesDocument);
      diagnostics.imports.push(staleZoneIds.size?'stale':converted?'canonical':migrated?'migrated':'valid');
      importStatus.textContent=staleZoneIds.size?`Import réussi : ${staleZoneIds.size} zones incompatibles, réancrage explicite requis.`:converted?`Import converti : ${zonesDocument.zones.length} objectifs survivants conservés ; ${candidate.removedCount} anciennes zones des autres axes retirées. Exportez le document corrigé.`:migrated?'Import T25A2 migré vers 0.2 : export requis pour enregistrer cette version.':'Import réussi, document compatible.';
      renderChart('import',false);notifyWorkshop();
    }catch(error){diagnostics.imports.push('invalid');diagnostics.errors.push(`import:${error.message}`);importStatus.textContent=`Import refusé : ${error.message} L’état courant est conservé.`;}finally{importInput.value='';}
  }

  editToggle.addEventListener('click',()=>{editMode=!editMode;renderChart('edit-mode',false);});
  endpoint.addEventListener('change',()=>renderChart('endpoint',false));zoneSide.addEventListener('change',()=>{if(side.value!=='both')side.value=zoneSide.value;renderChart('zone-side',false);});
  [centerX,centerY,radiusX,radiusY].forEach(input=>input.addEventListener('change',applyNumericInput));
  circleMode.addEventListener('change',()=>{editorState.setCircle(circleMode.checked);if(circleMode.checked&&findZone(selectedRow()))mutate('Mode cercle',()=>{const zone=findZone(selectedRow());zone.radii.y=zone.radii.x;markGeometryChanged(zone);});else renderChart('ellipse-mode',false);});
  confirmZone.addEventListener('click',()=>mutate('Confirmation de zone',()=>{const zone=findZone(selectedRow());if(zone)zone.approval=zone.approval==='confirmed'?'draft':'confirmed';}));
  disableZone.addEventListener('click',()=>mutate('Activation de zone',()=>{const zone=findZone(selectedRow());if(zone)zone.enabled=!zone.enabled;}));
  deleteZone.addEventListener('click',()=>mutate('Suppression de zone',()=>{const zone=findZone(selectedRow());if(zone)zonesDocument.zones=zonesDocument.zones.filter(item=>item.id!==zone.id);}));
  confirmDrafts.addEventListener('click',()=>mutate('Confirmation groupée',()=>zonesDocument.zones.forEach(zone=>{if(zone.enabled&&zone.approval==='draft'&&!staleZoneIds.has(zone.id))zone.approval='confirmed';})));
  undoButton.addEventListener('click',undo);redoButton.addEventListener('click',redo);reanchorButton.addEventListener('click',reanchor);
  linkPairButton.addEventListener('click',()=>mutate('Liaison horizontale de la paire',()=>{const zone=findZone(selectedRow());if(zone)model.linkWinRate(zonesDocument,zone.id);}));
  exportButton.addEventListener('click',exportZones);importInput.addEventListener('change',()=>importZones(importInput.files?.[0]));
  chart.on('click',params=>{if(params.data?.zone){if(params.seriesId==='zone-centers')selectCenterObjective(params.data.zone);else selectObjective(params.data.zone);return;}if(params.data?.scenarioId){scenario.value=params.data.scenarioId;if(params.data.row)zoneSide.value=params.data.row.side;if((params.data.kind==='base'||params.data.kind==='tip')&&(!ui.editableEndpoints||ui.editableEndpoints.includes(params.data.kind)))endpoint.value=params.data.kind;renderChart('chart-selection',false);}});
  // The custom ellipse already receives its native emphasis on hover. Asking
  // ECharts to highlight that same custom item recursively makes its tooltip
  // controller look up a stale item model while the drag handles are redrawn.
  // Only proxy the hover from the separate centre marker to the ellipse.
  chart.on('mouseover',params=>{if(params.seriesId!=='acceptance-zones')highlightObjective(params.data?.zone,true);});
  chart.on('mouseout',params=>{if(params.seriesId!=='acceptance-zones')highlightObjective(params.data?.zone,false);});
  chart.on('finished',()=>{const elapsed=performance.now()-pendingRender,last=diagnostics.viewChanges.at(-1);if(last&&last.finishedMs==null)last.finishedMs=elapsed;if(diagnostics.initialRenderMs==null)diagnostics.initialRenderMs=performance.now()-started;});
  [axis,side,legacyLayer,zonesLayer,allZones,motion,pairView].forEach(control=>control.addEventListener('change',()=>renderChart(control.id)));
  scenario.addEventListener('change',()=>renderChart('scenario'));reducedMotion.addEventListener?.('change',()=>renderChart('reduced-motion'));
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&editorState.hasActiveDrag()){event.preventDefault();cancelDrag();}else if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='z'){event.preventDefault();event.shiftKey?redo():undo();}else if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='y'){event.preventDefault();redo();}});
  const resizeObserver=new ResizeObserver(()=>{chart.resize({animation:{duration:0}});requestAnimationFrame(renderHandles);});resizeObserver.observe(chartNode);
  function setupMonotypes(){
    const overview=document.createElement('section');overview.className='panel monotype-overview';
    overview.innerHTML='<h2>Choisir une confrontation</h2><p>Chaque case donne le taux de victoire du camp attaquant mesuré sur le profil de départ. Les lignes attaquent les colonnes, à budget de construction égal (effectifs arrondis).</p><div class="table-scroll" id="monotype-grid"></div><div id="monotype-reading" aria-live="polite"></div><p class="chart-guide"><strong>Lire le graphique :</strong> vers la droite, le camp gagne plus souvent ; vers le haut, il subit davantage de blessés et morts avant compression. Les points pleins sont des résultats mesurés. Les ellipses sont vos objectifs, pas une incertitude statistique. Une flèche apparaît uniquement pour comparer un candidat au profil de départ.</p>';
    $('.workspace').before(overview);
    new ResizeObserver(entries=>parent.postMessage({type:'waar-workshop-height',height:Math.ceil(entries[0].contentRect.height)+32},parent.location.origin)).observe($('main'));
    $('#chart').setAttribute('aria-label','Taux de victoire et pertes des deux camps ; ellipses des objectifs de gameplay');
    const legend=$('.semantic-legend');legend.innerHTML='<span>● / ◆ Résultats mesurés</span><span>Contour vide : départ d’une comparaison</span><span>Ellipse : objectif souhaité</span><span id="color-legend" hidden></span>';
    $('#pair-link-label').parentElement.hidden=true;
    endpoint.closest('label').hidden=true;
    const names=['Victoires visées (%)','Blessés + morts visés (%)','Tolérance victoires (± points)','Tolérance pertes (± points)'];
    [centerX,centerY,radiusX,radiusY].forEach((input,index)=>{input.parentElement.firstChild.textContent=names[index];input.min=index<2?'0':'0.5';input.max='100';input.step='0.1';});
  }
  function renderMonotypes(){
    const grid=$('#monotype-grid');if(!grid)return;
    if(!grid.children.length){
      const order=data.designSurface.unitOrder;
      grid.innerHTML='<table><caption>Victoires de l’attaquant · profil mesuré</caption><thead><tr><th scope="col">Attaque ↓ / Défense →</th>'+order.map(type=>'<th scope="col">'+esc(unitLabels[type])+'</th>').join('')+'</tr></thead><tbody>'+order.map(acting=>'<tr><th scope="row">'+esc(unitLabels[acting])+'</th>'+order.map(target=>{const row=data.rows.find(item=>item.scenarioId===acting+'-vs-'+target&&item.side==='attacker');return '<td>'+(row?'<button type="button" data-match="'+esc(row.scenarioId)+'" aria-label="'+esc(unitLabels[acting]+' attaquent '+unitLabels[target]+', '+pct(row.micro.vector.x.from)+' de victoires')+'">'+pct(row.micro.vector.x.from,0)+'</button>':'—')+'</td>';}).join('')+'</tr>').join('')+'</tbody></table>';
      grid.querySelectorAll('[data-match]').forEach(button=>button.addEventListener('click',()=>{scenario.value=button.dataset.match;pairView.checked=true;side.value='both';zoneSide.value='attacker';renderChart('monotype-grid',false);}));
    }
    grid.querySelectorAll('[data-match]').forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.match===scenario.value)));
    const pair=data.rows.filter(row=>row.scenarioId===scenario.value);
    $('#monotype-reading').innerHTML=pair.map(row=>'<article class="reading-'+row.side+'"><strong>'+esc(role(row)+' : '+armyLabel(row))+'</strong><span>Profil mesuré : '+pct(row.micro.vector.x.from)+' de victoires · '+pct(row.micro.vector.y[axis.value].from)+' de blessés + morts</span>'+(workshopComparison?'<span>Candidat : '+pct(row.micro.vector.x.to)+' de victoires · '+pct(row.micro.vector.y[axis.value].to)+' de blessés + morts</span>':'')+'</article>').join('');
  }
  function notifyWorkshop(){
    if(ui.workshop)parent.postMessage({type:'waar-t27-zones',fingerprint:context.corpusFingerprint,document:zonesDocument},parent.location.origin);
  }
  if(ui.workshop){
    editMode=true;
    document.body.classList.add('workshop-editor');
    setupMonotypes();
    window.addEventListener('message',event=>{
      if(event.source!==parent||event.origin!==parent.location.origin||event.data?.type!=='waar-t27-comparison')return;
      workshopComparison=Array.isArray(event.data.rows);
      for(const row of data.rows){
        const candidate=event.data.rows?.find(item=>item.scenarioId===row.scenarioId&&item.side===row.side);
        row.micro.vector.x.to=candidate?.winRate??row.micro.vector.x.from;
        row.micro.vector.y.rawCasualtyRatio.to=candidate?.rawCasualtyRatio??row.micro.vector.y.rawCasualtyRatio.from;
      }
      renderChart('comparison',false);
    });
    parent.postMessage({type:'waar-t27-ready',fingerprint:context.corpusFingerprint},parent.location.origin);
  }
  renderChart();
})();
