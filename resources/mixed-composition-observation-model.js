(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.WaarMixedCompositionModel = api;
})(typeof self !== 'undefined' ? self : this, function () {
  const AXES = ['survivors', 'structure', 'economicValue'];

  function initialState(data) {
    return {
      candidateId: data.finalists[0].id,
      scenarioId: data.scenarios[0].id,
      axis: 'survivors'
    };
  }

  function normalize(data, state) {
    const candidateId = data.finalists.some(c => c.id === state.candidateId) ? state.candidateId : data.finalists[0].id;
    const scenarioId = data.scenarios.some(s => s.id === state.scenarioId) ? state.scenarioId : data.scenarios[0].id;
    return {candidateId, scenarioId, axis: AXES.includes(state.axis) ? state.axis : 'survivors'};
  }

  function view(data, requested) {
    const state = normalize(data, requested);
    const candidate = data.finalists.find(c => c.id === state.candidateId);
    const scenario = data.scenarios.find(s => s.id === state.scenarioId);
    const rows = ['attacker', 'defender'].map(side => {
      const initial = data.initial.rows.find(r => r.scenarioId === state.scenarioId && r.side === side);
      const observed = candidate.rows.find(r => r.scenarioId === state.scenarioId && r.side === side);
      return {side, initial, observed};
    });
    const axis = data.axes.y.find(a => a.id === state.axis);
    const points = rows.flatMap(row => [
      {candidate: 'initial', side: row.side, x: row.initial.winRate, y: row.initial.metrics[state.axis]},
      {candidate: 'finalist', side: row.side, x: row.observed.winRate, y: row.observed.metrics[state.axis]}
    ]);
    const overlapPairCount = rows.filter(row => row.initial.winRate === row.observed.winRate && row.initial.metrics[state.axis] === row.observed.metrics[state.axis]).length;
    return {state, candidate, scenario, axis, rows, points, overlapPairCount};
  }

  function exportRows(data, requested) {
    const selected = view(data, requested);
    return selected.rows.flatMap(pair => ['initial', 'observed'].map(kind => {
      const row = pair[kind];
      return {
        variant: kind === 'initial' ? data.initial.id : selected.candidate.id,
        scenarioId: selected.scenario.id,
        side: pair.side,
        wins: row.wins,
        losses: row.losses,
        draws: row.draws,
        iterations: row.iterations,
        winRate: row.winRate,
        survivors: row.metrics.survivors,
        structure: row.metrics.structure,
        economicValue: row.metrics.economicValue,
        meanRounds: row.meanRounds
      };
    }));
  }

  return {AXES, initialState, normalize, view, exportRows};
});
