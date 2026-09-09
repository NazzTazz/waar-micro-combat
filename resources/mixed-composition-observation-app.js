(function () {
  const data = window.__WAAR_T34_DATA__;
  const model = window.WaarMixedCompositionModel;
  let state = model.initialState(data);
  const byId = id => document.getElementById(id);
  const fmt = value => Number(value).toLocaleString('fr-FR', {minimumFractionDigits: 4, maximumFractionDigits: 4});
  const pct = value => `${(100 * Number(value)).toLocaleString('fr-FR', {minimumFractionDigits: 2, maximumFractionDigits: 2})} %`;
  const unitLabels = {soldier: 'Soldats', spearman: 'Lanciers', archer: 'Archers', knight: 'Chevaliers'};
  const sideLabels = {attacker: 'Attaquant', defender: 'Défenseur'};

  data.finalists.forEach(candidate => byId('candidate').add(new Option(`T31 #${candidate.t31Order} · ${candidate.id}`, candidate.id)));
  data.scenarios.forEach(scenario => byId('scenario').add(new Option(scenario.label, scenario.id)));
  data.axes.y.forEach(axis => byId('axis').add(new Option(axis.label, axis.id)));
  const chart = echarts.init(byId('chart'), null, {renderer: 'svg'});

  function chartOption(selected) {
    const colors = {attacker: '#ef8354', defender: '#49a7a2'};
    const series = selected.rows.map(pair => ({
      name: sideLabels[pair.side],
      type: 'line',
      data: [
        {value: [pair.initial.winRate, pair.initial.metrics[selected.state.axis]], name: `Initial · ${sideLabels[pair.side]}`, symbol: 'emptyCircle', symbolSize: 14},
        {value: [pair.observed.winRate, pair.observed.metrics[selected.state.axis]], name: `Finaliste · ${sideLabels[pair.side]}`, symbol: 'diamond', symbolSize: 14}
      ],
      lineStyle: {width: 3, color: colors[pair.side]},
      itemStyle: {color: colors[pair.side]},
      emphasis: {focus: 'series'},
      markLine: {
        silent: true,
        animation: false,
        symbol: ['none', 'arrow'],
        symbolSize: [0, 13],
        label: {show: false},
        lineStyle: {width: 3, color: colors[pair.side]},
        data: [[
          {coord: [pair.initial.winRate, pair.initial.metrics[selected.state.axis]]},
          {coord: [pair.observed.winRate, pair.observed.metrics[selected.state.axis]]}
        ]]
      }
    }));
    return {
      animation: false,
      tooltip: {trigger: 'item', formatter: p => `${p.data.name}<br>Victoires : ${pct(p.value[0])}<br>${selected.axis.label} : ${pct(p.value[1])}`},
      legend: {top: 0},
      grid: {left: 68, right: 30, top: 52, bottom: 58},
      xAxis: {type: 'value', min: 0, max: 1, name: 'Taux de victoire', nameLocation: 'middle', nameGap: 36, axisLabel: {formatter: v => `${Math.round(v * 100)} %`}},
      yAxis: {type: 'value', min: 0, max: 1, name: selected.axis.label, nameGap: 48, axisLabel: {formatter: v => `${Math.round(v * 100)} %`}},
      series
    };
  }

  function render() {
    state = model.normalize(data, state);
    const selected = model.view(data, state);
    byId('candidate').value = state.candidateId;
    byId('scenario').value = state.scenarioId;
    byId('axis').value = state.axis;
    byId('status').textContent = selected.candidate.t33Status;
    byId('budget').textContent = `Budgets figés · attaquant ${selected.scenario.budgets.attacker.toLocaleString('fr-FR')} · défenseur ${selected.scenario.budgets.defender.toLocaleString('fr-FR')}`;
    chart.setOption(chartOption(selected), true);
    byId('overlap').hidden = selected.overlapPairCount === 0;
    byId('overlap').textContent = `${selected.overlapPairCount} trajectoire(s) exactement superposée(s) : les valeurs des deux variantes restent séparées dans les tableaux et les exports.`;
    byId('measurements').innerHTML = selected.rows.map(pair => `
      <tr><th>${sideLabels[pair.side]}</th><td>Initial</td><td>${pair.initial.wins}</td><td>${pair.initial.losses}</td><td>${pair.initial.draws}</td><td>${pct(pair.initial.metrics.survivors)}</td><td>${pct(pair.initial.metrics.structure)}</td><td>${pct(pair.initial.metrics.economicValue)}</td><td>${fmt(pair.initial.meanRounds)}</td></tr>
      <tr><th>${sideLabels[pair.side]}</th><td>Finaliste</td><td>${pair.observed.wins}</td><td>${pair.observed.losses}</td><td>${pair.observed.draws}</td><td>${pct(pair.observed.metrics.survivors)}</td><td>${pct(pair.observed.metrics.structure)}</td><td>${pct(pair.observed.metrics.economicValue)}</td><td>${fmt(pair.observed.meanRounds)}</td></tr>
    `).join('');
    byId('units').innerHTML = selected.rows.flatMap(pair => Object.keys(unitLabels).map(type => `
      <tr><th>${sideLabels[pair.side]}</th><td>${unitLabels[type]}</td><td>${fmt(pair.initial.units[type].meanSurvivors)}</td><td>${fmt(pair.observed.units[type].meanSurvivors)}</td><td>${fmt(pair.initial.units[type].meanLosses)}</td><td>${fmt(pair.observed.units[type].meanLosses)}</td></tr>
    `)).join('');
    window.__waarT34Diagnostics = {simulationCount: 0, acceptanceInferenceCount: 0, candidateMutationCount: 0, selectedCandidateId: state.candidateId, selectedScenarioId: state.scenarioId, selectedAxis: state.axis, overlapPairCount: selected.overlapPairCount};
    Object.assign(document.documentElement.dataset, {
      t34SimulationCount: '0',
      t34AcceptanceInferenceCount: '0',
      t34CandidateMutationCount: '0',
      t34SelectedCandidateId: state.candidateId,
      t34SelectedScenarioId: state.scenarioId,
      t34SelectedAxis: state.axis,
      t34OverlapPairCount: String(selected.overlapPairCount)
    });
  }

  ['candidate', 'scenario', 'axis'].forEach(id => byId(id).addEventListener('change', event => { state = {...state, [id === 'candidate' ? 'candidateId' : id === 'scenario' ? 'scenarioId' : 'axis']: event.target.value}; render(); }));
  byId('export-json').addEventListener('click', () => download('t34-selection.json', JSON.stringify({selection: state, rows: model.exportRows(data, state)}, null, 2), 'application/json'));
  byId('export-csv').addEventListener('click', () => {
    const rows = model.exportRows(data, state); const headers = Object.keys(rows[0]);
    download('t34-selection.csv', [headers.join(','), ...rows.map(row => headers.map(key => row[key]).join(','))].join('\n') + '\n', 'text/csv');
  });
  function download(name, contents, type) { const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([contents], {type})); a.download = name; a.click(); URL.revokeObjectURL(a.href); }
  addEventListener('resize', () => chart.resize());
  render();
})();
