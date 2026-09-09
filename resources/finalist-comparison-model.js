(() => {
  'use strict';

  const AXES = new Set(['survivors', 'economicValue', 'structure']);
  const clone = value => JSON.parse(JSON.stringify(value));
  const candidateById = (data, id) => data.finalists.find(candidate => candidate.id === id);

  function validate(data) {
    if (!data || data.schemaVersion !== 'waar-monotype-finalist-comparison/0.1') throw new Error('Schéma de comparaison T32 incompatible.');
    if (!Array.isArray(data.finalists) || data.finalists.length < 1 || data.finalists.length > 3) throw new Error('Le rapport doit contenir de un à trois finalistes.');
    if (!Array.isArray(data.scenarios) || data.scenarios.length !== 16) throw new Error('Le rapport T32 doit contenir 16 confrontations.');
    if (data.browserContract?.simulationAllowed !== false || data.browserContract?.objectiveInferenceAllowed !== false) throw new Error('Le contrat de lecture seule T32 est absent.');
    const scenarioIds = new Set(data.scenarios.map(scenario => scenario.id));
    if (scenarioIds.size !== 16) throw new Error('Les confrontations doivent être uniques.');
    for (const candidate of [data.initial, ...data.finalists]) {
      if (!candidate || !Array.isArray(candidate.rows) || candidate.rows.length !== 32) throw new Error('Chaque candidat doit fournir 32 observations.');
      const objectiveIds = new Set(candidate.rows.map(row => row.objectiveId));
      if (objectiveIds.size !== 32 || candidate.rows.some(row => !scenarioIds.has(row.scenarioId))) throw new Error('Les 32 objectifs doivent être uniques et rattachés au corpus.');
    }
    if (!candidateById(data, data.defaultFinalistId)) throw new Error('Le finaliste initialement sélectionné est inconnu.');
    return data;
  }

  function createState(data) {
    validate(data);
    let candidateId = data.defaultFinalistId;
    let scenarioId = data.scenarios[0].id;
    let axis = 'survivors';
    let showNeutral = false;
    return {
      get candidateId() { return candidateId; },
      get scenarioId() { return scenarioId; },
      get axis() { return axis; },
      get showNeutral() { return showNeutral; },
      selectCandidate(id) { if (!candidateById(data, id)) throw new Error('Finaliste inconnu.'); candidateId = id; },
      selectScenario(id) { if (!data.scenarios.some(scenario => scenario.id === id)) throw new Error('Confrontation inconnue.'); scenarioId = id; },
      selectAxis(id) { if (!AXES.has(id)) throw new Error('Axe Y inconnu.'); axis = id; },
      toggleNeutral(value) { showNeutral = Boolean(value); },
    };
  }

  const rowsForScenario = (candidate, scenarioId) => candidate.rows.filter(row => row.scenarioId === scenarioId);

  function view(data, state) {
    const selected = candidateById(data, state.candidateId);
    if (!selected) throw new Error('Finaliste inconnu.');
    const initialRows = rowsForScenario(data.initial, state.scenarioId);
    const selectedRows = rowsForScenario(selected, state.scenarioId);
    if (initialRows.length !== 2 || selectedRows.length !== 2) throw new Error('La confrontation doit contenir les deux camps.');
    const initialBySide = Object.fromEntries(initialRows.map(row => [row.side, row]));
    return {
      candidate: selected,
      scenario: data.scenarios.find(scenario => scenario.id === state.scenarioId),
      axis: state.axis,
      rows: selectedRows.map(row => ({...row, initial: initialBySide[row.side].observation, initialObjectives: initialBySide[row.side].objectives})),
      showNeutral: state.showNeutral,
      objectiveMode: data.axes.y.find(axis => axis.id === state.axis).objectiveMode,
    };
  }

  function globalObjectives(data, state) {
    const selected = candidateById(data, state.candidateId);
    const values = selected.rows.map(row => ({
      id: row.objectiveId,
      scenarioId: row.scenarioId,
      scenarioLabel: row.scenarioLabel,
      side: row.side,
      ...row.objectives.survivors,
    }));
    if (new Set(values.map(value => value.id)).size !== data.browserContract.objectiveCount) throw new Error('Objectifs dupliqués dans la vue globale.');
    return values;
  }

  function selectedDownload(data, state, kind) {
    if (!['variant', 'evaluation'].includes(kind)) throw new Error('Export inconnu.');
    const candidate = candidateById(data, state.candidateId);
    return clone(candidate.downloads[kind]);
  }

  globalThis.WaarFinalistComparisonModel = {AXES, clone, validate, createState, view, globalObjectives, selectedDownload};
})();
