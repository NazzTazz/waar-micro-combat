(() => {
  'use strict';
  const diagnostics = globalThis.__waarT33Diagnostics = {errors: [], viewChanges: 0, simulations: 0, exports: [], objectiveCount: 0, echartsVersion: null};
  try {
    const data = JSON.parse(document.querySelector('#comparison-data').textContent);
    const model = globalThis.WaarFinalistComparisonModel;
    if (!model) throw new Error('Modèle de présentation T33 absent.');
    model.validate(data);
    const state = model.createState(data);
    const $ = selector => document.querySelector(selector);
    const candidateSelect = $('#candidate'), scenarioSelect = $('#scenario'), axisSelect = $('#axis'), neutralCheck = $('#neutral');
    const sideLabel = {attacker: 'Attaquant', defender: 'Défenseur'};
    const colors = {attacker: '#55cfbf', defender: '#f08178'};
    const symbols = {attacker: 'circle', defender: 'diamond'};
    const nf = new Intl.NumberFormat('fr-FR', {maximumFractionDigits: 6});
    const pct = value => `${nf.format(value * 100)} %`;
    const number = value => value == null ? '—' : nf.format(value);
    const escapeHtml = value => String(value).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
    const statusLabels = {'stable-sur-les-lots':'Stable sur les lots','variable-selon-le-lot':'Variable selon le lot','objectifs-non-atteints':'Objectifs non atteints','invariant-nuls-en-echec':'Invariant nuls en échec'};
    let decision = null;

    document.title = data.title;
    $('#experiment-label').textContent = `${data.experiment.label} · ${data.experiment.iterations.toLocaleString('fr-FR')} simulations par camp`;
    $('#run-status-label').textContent = data.run.statusLabel;
    $('#run-status-detail').textContent = data.run.statusDetail;
    $('#run-status').classList.toggle('partial', !data.run.complete);
    $('#objective-instruction').textContent = data.objectiveDocument.changeInstruction;
    data.finalists.forEach(candidate => candidateSelect.add(new Option(`Rang ${candidate.rank} · ${candidate.label}`, candidate.id)));
    data.scenarios.forEach(scenario => scenarioSelect.add(new Option(scenario.label, scenario.id)));
    data.axes.y.forEach(axis => axisSelect.add(new Option(axis.label + (axis.objectiveMode === 'same-as-survivors' ? ' — mêmes objectifs' : axis.objectiveMode === 'diagnostic-only' ? ' — diagnostic' : ''), axis.id)));
    candidateSelect.value = state.candidateId;

    let chart = null;
    if (typeof globalThis.echarts !== 'undefined') {
      diagnostics.echartsVersion = echarts.version;
      chart = echarts.init($('#chart'), null, {renderer: 'canvas'});
      chart.on('click', params => { if (params.data?.scenarioId) { state.selectScenario(params.data.scenarioId); syncControls(); render(); } });
      addEventListener('resize', () => chart.resize());
    } else {
      $('#chart').hidden = true;
      $('#chart-error').style.display = 'block';
      diagnostics.errors.push('echarts-unavailable');
    }

    function syncControls() {
      candidateSelect.value = state.candidateId;
      scenarioSelect.value = state.scenarioId;
      axisSelect.value = state.axis;
      neutralCheck.checked = state.showNeutral;
    }

    function objectiveText(objective) {
      if (!objective) return {state: 'Diagnostic sans objectif', excess: '—', loss: '—'};
      return {state: objective.state === 'inside' ? 'Dans la zone' : 'Hors zone', excess: number(objective.excess), loss: number(objective.lossContribution)};
    }

    function renderIdentity(view) {
      const summary = view.candidate.summary;
      $('#candidate-label').textContent = view.candidate.label;
      $('#candidate-id').textContent = `${view.candidate.id} @ ${view.candidate.version}`;
      $('#candidate-rank').textContent = `Rang ${view.candidate.rank} · empreinte ${view.candidate.parameterFingerprint}`;
      $('#summary').innerHTML = [
        `<div class="metric"><small>Perte continue</small><strong>${number(summary.continuousLoss)}</strong></div>`,
        `<div class="metric"><small>Objectifs atteints</small><strong>${summary.objectivesSatisfied}/${summary.objectiveCount}</strong></div>`,
        `<div class="metric"><small>Nuls</small><strong>${summary.drawCount}</strong></div>`,
        `<div class="metric"><small>Pire excès</small><strong>${number(summary.worstExcess)}</strong></div>`,
        `<div class="metric wide"><small>Pire objectif</small><strong>${escapeHtml(summary.worstObjectiveId)}</strong></div>`,
      ].join('');
      $('#strict').classList.toggle('pass', summary.strictControls.passed);
      $('#strict-label').textContent = summary.classificationLabel;
      $('#strict-detail').textContent = `${summary.strictControls.allObjectivesSatisfied ? '32 objectifs atteints' : 'Tous les objectifs ne sont pas atteints'} · ${summary.strictControls.noDraws ? 'aucun nul' : 'nuls présents'}. Aucun verdict d’acceptation n’est déduit d’une moyenne.`;
      $('#stability-label').textContent = statusLabels[view.candidate.stabilityStatus] || view.candidate.stabilityStatus;
      $('#stability-detail').textContent = 'Statut descriptif des cinq lots réservés et de leur agrégat. Les étendues ci-dessous sont une variation entre lots, pas un intervalle de confiance.';
      $('#batch-summary').innerHTML = view.candidate.batchSummaries.map(batch => `<p><span class="mono">Lot ${batch.batch} · seed ${batch.baseSeed}</span><br>${batch.objectivesSatisfied}/${batch.objectiveCount} objectifs · perte ${number(batch.continuousLoss)} · ${batch.drawCount} nul(s)</p>`).join('');
    }

    function ellipseSeries(row, objective) {
      if (!objective) return null;
      return {
        name: `Ellipse PO · ${sideLabel[row.side]}`, type: 'custom', silent: false, z: 1,
        data: [{value: [objective.target.center.x, objective.target.center.y, objective.target.radii.x, objective.target.radii.y], side: row.side, objective, scenarioId: row.scenarioId}],
        renderItem(params, api) {
          const center = api.coord([api.value(0), api.value(1)]);
          const size = api.size([api.value(2) * 2, api.value(3) * 2]);
          return {type:'ellipse', shape:{cx:center[0],cy:center[1],rx:Math.abs(size[0]/2),ry:Math.abs(size[1]/2)}, style:{fill:colors[row.side]+'13',stroke:colors[row.side],lineWidth:1.5,lineDash:[5,4]}};
        },
      };
    }

    function point(row, sample, label, objective) {
      return {value:[sample.winRate,sample[state.axis]], scenarioId:row.scenarioId, side:row.side, sample, label, objective, symbol:symbols[row.side]};
    }

    function renderChart(view) {
      if (!chart) return;
      const targets = view.objectiveMode === 'diagnostic-only' ? [] : view.rows.map(row => ellipseSeries(row, row.objectives[state.axis]));
      diagnostics.targetEllipseCount = targets.length;
      diagnostics.axis = state.axis;
      diagnostics.candidateId = state.candidateId;
      diagnostics.scenarioId = state.scenarioId;
      const series = targets.filter(Boolean);
      for (const row of view.rows) {
        const objective = row.objectives[state.axis];
        series.push({
          name:`Initial · ${sideLabel[row.side]}`, type:'scatter', z:4, symbol:symbols[row.side], symbolSize:16,
          itemStyle:{color:'transparent',borderColor:colors[row.side],borderWidth:3}, data:[point(row,row.initial,'Initial',row.initialObjectives[state.axis])],
        });
        series.push({
          name:`Initial → candidat · ${sideLabel[row.side]}`, type:'lines', coordinateSystem:'cartesian2d', z:3, silent:true,
          symbol:['none','arrow'],symbolSize:9,lineStyle:{color:colors[row.side],width:2,opacity:.8}, data:[{coords:[[row.initial.winRate,row.initial[state.axis]],[row.observation.winRate,row.observation[state.axis]]]}],
        });
        series.push({
          name:`Candidat · ${sideLabel[row.side]}`, type:'scatter', z:5, symbol:symbols[row.side], symbolSize:17,
          itemStyle:{color:colors[row.side],borderColor:'#0b1114',borderWidth:1}, data:[point(row,row.observation,'Candidat',objective)],
        });
        if (view.showNeutral) series.push({
          name:`Témoin neutre T28 · ${sideLabel[row.side]}`, type:'scatter', z:2, symbol:'cross', symbolSize:14,
          itemStyle:{color:'#c9c2b5'}, data:[point(row,row.neutral,'Témoin neutre T28',null)],
        });
      }
      const axisLabel = data.axes.y.find(axis => axis.id === state.axis).label;
      chart.setOption({
        animationDuration:180, backgroundColor:'transparent', grid:{left:65,right:28,top:28,bottom:58},
        xAxis:{type:'value',name:'Taux de victoire',nameLocation:'middle',nameGap:35,min:0,max:1,axisLabel:{formatter:value=>`${Math.round(value*100)} %`,color:'#aeb8b9'},nameTextStyle:{color:'#d7d3ca'},splitLine:{lineStyle:{color:'#2a373d'}}},
        yAxis:{type:'value',name:axisLabel,nameLocation:'middle',nameGap:47,min:0,max:1,axisLabel:{formatter:value=>`${Math.round(value*100)} %`,color:'#aeb8b9'},nameTextStyle:{color:'#d7d3ca'},splitLine:{lineStyle:{color:'#2a373d'}}},
        tooltip:{trigger:'item',confine:true,formatter(params){const d=params.data;if(!d?.sample)return escapeHtml(params.seriesName);const detail=objectiveText(d.objective);return `<strong>${escapeHtml(d.label)} · ${escapeHtml(sideLabel[d.side])}</strong><br>${escapeHtml(view.scenario.label)}<br>Victoire : ${pct(d.sample.winRate)}<br>${escapeHtml(axisLabel)} : ${pct(d.sample[state.axis])}<br>Simulations : ${d.sample.iterations}<br>État : ${detail.state}<br>Excès : ${detail.excess}<br>Contribution à la perte : ${detail.loss}`;}},
        series,
      }, true);
    }

    function renderTable(view) {
      const rows = [];
      for (const row of view.rows) {
        const values = [
          ['Initial', row.initial, row.initialObjectives[state.axis]],
          ['Candidat', row.observation, row.objectives[state.axis]],
        ];
        if (view.showNeutral) values.push(['Témoin neutre T28', row.neutral, null]);
        for (const [label, sample, objective] of values) {
          const detail = objectiveText(objective);
          const accessible = `${sideLabel[row.side]}, ${label}, victoire ${pct(sample.winRate)}, valeur ${pct(sample[state.axis])}, ${sample.iterations} simulations, ${detail.state}, excès ${detail.excess}, contribution ${detail.loss}`;
          rows.push(`<tr tabindex="0" data-state="${objective?.state || 'diagnostic'}" aria-label="${escapeHtml(accessible)}"><td>${sideLabel[row.side]}</td><td>${label}</td><td>${pct(sample.winRate)}</td><td>${pct(sample[state.axis])}</td><td>${sample.iterations.toLocaleString('fr-FR')}</td><td>${detail.state}</td><td>${detail.excess}</td><td>${detail.loss}</td></tr>`);
        }
      }
      $('#table-body').innerHTML = rows.join('');
      $('#y-heading').textContent = data.axes.y.find(axis => axis.id === state.axis).label;
      const variation = view.rows.map(row => row.objectives.survivors.variationBetweenBatches);
      $('#provenance').innerHTML = `Plan <span class="mono">${escapeHtml(data.run.planId)}</span> · candidat <span class="mono">${escapeHtml(view.candidate.id)}</span> · variante <span class="mono">${escapeHtml(view.candidate.source.variant.sha256)}</span> · évaluation <span class="mono">${escapeHtml(view.candidate.source.evaluation.sha256)}</span> · objectifs <span class="mono">${escapeHtml(data.objectiveDocument.sourceSha256)}</span><br>Variation entre lots : attaquant ${pct(variation[0].winRate.minimum)}–${pct(variation[0].winRate.maximum)}, ${variation[0].batchesSatisfied}/${variation[0].batchCount} lots dans la zone · défenseur ${pct(variation[1].winRate.minimum)}–${pct(variation[1].winRate.maximum)}, ${variation[1].batchesSatisfied}/${variation[1].batchCount} lots dans la zone.`;
    }

    function renderScenarios() {
      $('#scenario-buttons').innerHTML = data.scenarios.map((scenario,index) => `<button type="button" data-scenario="${escapeHtml(scenario.id)}" aria-current="${scenario.id===state.scenarioId}" title="${escapeHtml(scenario.label)}">${index+1}. ${escapeHtml(scenario.label)}</button>`).join('');
      $('#scenario-buttons').querySelectorAll('button').forEach(button => button.addEventListener('click', () => { state.selectScenario(button.dataset.scenario); syncControls(); render(); }));
    }

    function renderObjectives(view) {
      const objectives = model.globalObjectives(data,state);
      diagnostics.objectiveCount = objectives.length;
      const inside = objectives.filter(objective => objective.state === 'inside').length;
      $('#global-summary').textContent = `${inside}/${objectives.length} dans la zone · sélectionner un objectif ouvre sa confrontation`;
      $('#objective-grid').innerHTML = objectives.map((objective,index) => `<button type="button" class="objective ${objective.state}" data-scenario="${escapeHtml(objective.scenarioId)}" aria-current="${objective.scenarioId===state.scenarioId}" aria-label="Objectif ${index+1}, ${escapeHtml(objective.scenarioLabel)}, ${escapeHtml(sideLabel[objective.side])}, ${objective.state==='inside'?'dans la zone':'hors zone'}, excès ${number(objective.excess)}, contribution ${number(objective.lossContribution)}"><b>${index+1}. ${sideLabel[objective.side]}</b><small>${escapeHtml(objective.scenarioLabel)}</small><small>${objective.state==='inside'?'Dans la zone':'Hors zone'} · +${number(objective.excess)}</small></button>`).join('');
      $('#objective-grid').querySelectorAll('button').forEach(button => button.addEventListener('click', () => { state.selectScenario(button.dataset.scenario); syncControls(); render(); scenarioSelect.focus(); }));
    }

    function render() {
      diagnostics.viewChanges++;
      const view = model.view(data,state);
      renderIdentity(view); renderChart(view); renderTable(view); renderScenarios(); renderObjectives(view);
      $('#neutral-legend').hidden = !state.showNeutral;
      $('#live').textContent = `${view.candidate.label}, ${view.scenario.label}, ${data.axes.y.find(axis=>axis.id===state.axis).label}`;
    }

    candidateSelect.addEventListener('change', () => { state.selectCandidate(candidateSelect.value); render(); });
    scenarioSelect.addEventListener('change', () => { state.selectScenario(scenarioSelect.value); render(); });
    axisSelect.addEventListener('change', () => { state.selectAxis(axisSelect.value); render(); });
    neutralCheck.addEventListener('change', () => { state.toggleNeutral(neutralCheck.checked); render(); });
    function download(kind) {
      const artifact = model.selectedDownload(data,state,kind);
      const blob = new Blob([artifact.json],{type:'application/json'});
      const url = URL.createObjectURL(blob); const anchor=document.createElement('a'); anchor.href=url;anchor.download=artifact.filename;document.body.append(anchor);anchor.click();setTimeout(()=>{URL.revokeObjectURL(url);anchor.remove();},5000);
      diagnostics.exports.push({kind,candidateId:state.candidateId,filename:artifact.filename,bytes:artifact.json.length});
      $('#live').textContent = `${kind === 'variant' ? 'Variante' : 'Évaluation'} exportée pour ${state.candidateId}`;
    }
    $('#download-variant').addEventListener('click',()=>download('variant'));
    $('#download-evaluation').addEventListener('click',()=>download('evaluation'));
    function chooseDecision(kind) {
      decision = {schemaVersion:'waar-t33-po-decision/0.1',planId:data.run.planId,planSha256:data.run.source.planSha256,decision:kind,candidateId:kind==='retain-candidate'?state.candidateId:null,candidateStatus:kind==='retain-candidate'?model.view(data,state).candidate.stabilityStatus:null};
      $('#decision-state').textContent = kind === 'retain-candidate' ? `Candidat retenu localement : ${state.candidateId}.` : 'Nouvelle expérience demandée localement.';
      $('#download-decision').disabled = false;
    }
    $('#retain-candidate').addEventListener('click',()=>chooseDecision('retain-candidate'));
    $('#request-experiment').addEventListener('click',()=>chooseDecision('request-new-experiment'));
    $('#download-decision').addEventListener('click',()=>{
      if (!decision) return;
      const json=JSON.stringify(decision,null,2)+'\n',blob=new Blob([json],{type:'application/json'}),url=URL.createObjectURL(blob),anchor=document.createElement('a');
      anchor.href=url;anchor.download='t33-po-decision.json';document.body.append(anchor);anchor.click();setTimeout(()=>{URL.revokeObjectURL(url);anchor.remove();},5000);
      diagnostics.exports.push({kind:'po-decision',candidateId:decision.candidateId,filename:anchor.download,bytes:json.length});
    });
    render();
  } catch (error) {
    diagnostics.errors.push(String(error?.message || error));
    const node = document.querySelector('#chart-error'); if(node){node.style.display='block';node.textContent=`Rapport T33 invalide : ${error.message}`;}
  }
})();
