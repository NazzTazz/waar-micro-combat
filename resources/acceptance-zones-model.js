globalThis.WaarAcceptanceZonesModel = (() => {
  'use strict';

  const SCHEMA_VERSION = 'waar-acceptance-zones/0.2';
  const LEGACY_SCHEMA_VERSION = 'waar-acceptance-zones/0.1';
  const MAX_IMPORT_BYTES = 1024 * 1024;
  const MIN_RADIUS = 0.005;
  const MAX_RADIUS = 1;
  const BOUNDARY_TOLERANCE = 1e-12;
  const SIDES = new Set(['attacker', 'defender']);
  const ENDPOINTS = new Set(['base', 'tip']);
  const Y_METRICS = new Set(['survivors', 'structure', 'economicValue']);
  const APPROVALS = new Set(['draft', 'confirmed']);
  const SOURCE_KINDS = new Set(['legacy', 'observation']);

  function fail(message) { throw new Error(message); }
  function assert(condition, message) { if (!condition) fail(message); }
  function object(value) { return value !== null && typeof value === 'object' && !Array.isArray(value); }
  function finite(value) { return typeof value === 'number' && Number.isFinite(value); }
  function clone(value) { return JSON.parse(JSON.stringify(value)); }
  function same(value, expected) { return value === expected; }
  function keysExactly(value, keys, label) {
    const actual = Object.keys(value).sort();
    const wanted = [...keys].sort();
    assert(actual.length === wanted.length && actual.every((key, index) => key === wanted[index]), `${label} contient des champs inconnus ou manquants.`);
  }
  function boundedPoint(value, label) {
    assert(object(value), `${label} doit être un objet.`);
    keysExactly(value, ['x', 'y'], label);
    assert(finite(value.x) && value.x >= 0 && value.x <= 1, `${label}.x doit être fini et compris entre 0 et 1.`);
    assert(finite(value.y) && value.y >= 0 && value.y <= 1, `${label}.y doit être fini et compris entre 0 et 1.`);
  }
  function radii(value, label) {
    assert(object(value), `${label} doit être un objet.`);
    keysExactly(value, ['x', 'y'], label);
    for (const coordinate of ['x', 'y']) {
      assert(finite(value[coordinate]) && value[coordinate] >= MIN_RADIUS && value[coordinate] <= MAX_RADIUS, `${label}.${coordinate} doit être compris entre ${MIN_RADIUS} et ${MAX_RADIUS}.`);
    }
  }
  function zoneKey(zone) { return `${zone.scenarioId}\u0000${zone.side}\u0000${zone.endpoint}\u0000${zone.xMetric}\u0000${zone.yMetric}`; }
  function expectedZoneMap(context) { return new Map(context.initialZones.map(zone => [zone.id, zone])); }

  // Explicit T26 import conversion: survivors are the PO's canonical intentions.
  // Validate every legacy entry before dropping the two independent extra axes.
  function importMonotypeObjectives(raw, context) {
    assert(context.comparisonProfileId === 'monotype-equal-cost-v1', 'Conversion réservée aux objectifs monotypes.');
    const initialZones = context.initialZones.flatMap(zone => ['survivors', 'structure', 'economicValue'].map(metric => ({
      ...clone(zone), id: `${zone.scenarioId}-${zone.side}-${zone.endpoint}-${metric}`, yMetric: metric
    })));
    const checked = validateAndClassify(raw, {...context, initialZones});
    const removedCount = checked.document.zones.filter(zone => zone.yMetric !== 'survivors').length;
    checked.document.zones = checked.document.zones.filter(zone => zone.yMetric === 'survivors');
    checked.document.generation.id = 'canonical-monotype-survivors-v1';
    checked.document.generation.label = 'Objectifs monotypes uniques — survivants';
    return {...validateAndClassify(checked.document, context), removedCount};
  }

  // Editing contract for no-draw objectives; never changes measured battle results.
  function linkWinRate(document, zoneId) {
    const source = document.zones.find(zone => zone.id === zoneId);
    assert(source && source.endpoint === 'tip' && source.xMetric === 'winRate', 'Objectif de victoire introuvable.');
    const changed = [];
    for (const zone of document.zones) {
      if (zone.scenarioId !== source.scenarioId || zone.endpoint !== 'tip' || zone.xMetric !== 'winRate') continue;
      const x = zone.side === source.side ? source.center.x : 1 - source.center.x;
      if (zone.center.x === x && zone.radii.x === source.radii.x) continue;
      zone.center.x = x;
      zone.radii.x = source.radii.x;
      zone.source.modifiedManually = true;
      changed.push(zone.id);
    }
    return changed;
  }

  function hasLinkedWinRate(document, scenarioId) {
    const zones = document.zones.filter(zone => zone.scenarioId === scenarioId && zone.endpoint === 'tip' && zone.xMetric === 'winRate');
    const attacker = zones.find(zone => zone.side === 'attacker');
    if (!attacker) return false;
    return zones.every(zone => Math.abs(zone.center.x - (zone.side === 'attacker' ? attacker.center.x : 1 - attacker.center.x)) <= BOUNDARY_TOLERANCE
      && Math.abs(zone.radii.x - attacker.radii.x) <= BOUNDARY_TOLERANCE);
  }

  function createEditorInteractionState() {
    let selectedZoneId = null;
    let circle = false;
    let dragSnapshot = null;

    return {
      selectZone(zoneId, circleByGeometry = false) {
        if (zoneId !== selectedZoneId) {
          selectedZoneId = zoneId;
          circle = Boolean(circleByGeometry);
        }
        return circle;
      },
      setCircle(enabled) { circle = Boolean(enabled); return circle; },
      isCircle() { return circle; },
      beginDrag(snapshot) { dragSnapshot = snapshot; },
      hasActiveDrag() { return dragSnapshot !== null; },
      finishDrag() { const snapshot = dragSnapshot; dragSnapshot = null; return snapshot; },
      cancelDrag() { const snapshot = dragSnapshot; dragSnapshot = null; return snapshot; },
      reset() { selectedZoneId = null; circle = false; dragSnapshot = null; }
    };
  }

  function migrate(raw) {
    const document = clone(raw);
    if (document?.schemaVersion !== LEGACY_SCHEMA_VERSION) return document;
    document.schemaVersion = SCHEMA_VERSION;
    if (object(document.generation)) document.generation.readOnly = false;
    if (Array.isArray(document.zones)) {
      document.zones.forEach(zone => {
        if (object(zone.source) && zone.source.kind === 'legacy' && !Object.hasOwn(zone.source, 'modifiedManually')) zone.source.modifiedManually = false;
      });
    }
    return document;
  }

  function validateAndClassify(raw, context) {
    assert(object(raw), 'Le document de zones doit être un objet JSON.');
    const document = migrate(raw);
    keysExactly(document, ['schemaVersion', 'experimentId', 'corpusFingerprint', 'comparisonProfileId', 'valuationId', 'generation', 'zones'], 'Le document');
    assert(document.schemaVersion === SCHEMA_VERSION, `Version non prise en charge : ${String(document.schemaVersion)}.`);
    for (const field of ['experimentId', 'corpusFingerprint', 'comparisonProfileId', 'valuationId']) assert(typeof document[field] === 'string' && document[field].length > 0 && document[field].length <= 240, `${field} doit être une chaîne de 1 à 240 caractères.`);
    assert(/^[a-f0-9]{64}$/.test(document.corpusFingerprint), 'corpusFingerprint doit être une empreinte SHA-256 minuscule.');
    assert(object(document.generation), 'generation doit être un objet.');
    keysExactly(document.generation, ['id', 'label', 'radiusX', 'radiusY', 'readOnly'], 'generation');
    assert(typeof document.generation.id === 'string' && document.generation.id.length > 0, 'generation.id doit être une chaîne non vide.');
    assert(typeof document.generation.label === 'string' && document.generation.label.length > 0 && document.generation.label.length <= 200, 'generation.label doit contenir de 1 à 200 caractères.');
    assert(finite(document.generation.radiusX) && document.generation.radiusX >= MIN_RADIUS && document.generation.radiusX <= MAX_RADIUS && finite(document.generation.radiusY) && document.generation.radiusY >= MIN_RADIUS && document.generation.radiusY <= MAX_RADIUS, `Les rayons de génération doivent être compris entre ${MIN_RADIUS} et ${MAX_RADIUS}.`);
    assert(document.generation.readOnly === false, 'Ce document doit être éditable.');
    assert(Array.isArray(document.zones), 'zones doit être un tableau.');

    const expectedById = expectedZoneMap(context);
    const ids = new Set(), associations = new Set();
    for (const zone of document.zones) {
      assert(object(zone), 'Chaque zone doit être un objet.');
      keysExactly(zone, ['id', 'scenarioId', 'side', 'endpoint', 'xMetric', 'yMetric', 'shape', 'center', 'radii', 'enabled', 'approval', 'source'], `Zone ${String(zone.id ?? '?')}`);
      assert(typeof zone.id === 'string' && zone.id.length > 0 && zone.id.length <= 240, 'Chaque zone doit avoir un identifiant court non vide.');
      assert(!ids.has(zone.id), `Identifiant de zone dupliqué : ${zone.id}.`); ids.add(zone.id);
      const expected = expectedById.get(zone.id);
      assert(expected, `Identifiant de zone inconnu : ${zone.id}.`);
      assert(typeof zone.scenarioId === 'string' && zone.scenarioId.length > 0, `Scénario invalide pour ${zone.id}.`);
      assert(SIDES.has(zone.side) && ENDPOINTS.has(zone.endpoint), `Camp ou extrémité invalide pour ${zone.id}.`);
      assert(zone.xMetric === 'winRate' && Y_METRICS.has(zone.yMetric), `Paire de métriques invalide pour ${zone.id}.`);
      assert(zone.shape === 'ellipse', `Forme non prise en charge pour ${zone.id}.`);
      assert(zoneKey(zone) === zoneKey(expected), `Association modifiée ou inconnue pour ${zone.id}.`);
      assert(!associations.has(zoneKey(zone)), `Association de zone dupliquée pour ${zone.id}.`); associations.add(zoneKey(zone));
      boundedPoint(zone.center, `${zone.id}.center`); radii(zone.radii, `${zone.id}.radii`);
      assert(typeof zone.enabled === 'boolean', `enabled doit être booléen pour ${zone.id}.`);
      assert(APPROVALS.has(zone.approval), `Confirmation invalide pour ${zone.id}.`);
      assert(object(zone.source), `source doit être un objet pour ${zone.id}.`);
      keysExactly(zone.source, ['kind', 'referenceId', 'referencePointId', 'originalCenter', 'modifiedManually'], `${zone.id}.source`);
      assert(SOURCE_KINDS.has(zone.source.kind), `Provenance non prise en charge pour ${zone.id}.`);
      assert(typeof zone.source.referenceId === 'string' && zone.source.referenceId.length > 0 && zone.source.referenceId.length <= 240, `Référence vide ou trop longue pour ${zone.id}.`);
      assert(typeof zone.source.referencePointId === 'string' && zone.source.referencePointId.length > 0 && zone.source.referencePointId.length <= 240, `Point de référence vide ou trop long pour ${zone.id}.`);
      boundedPoint(zone.source.originalCenter, `${zone.id}.source.originalCenter`);
      assert(typeof zone.source.modifiedManually === 'boolean', `modifiedManually doit être booléen pour ${zone.id}.`);
    }

    const globalReasons = [];
    for (const [field, label] of [['experimentId','expérience'],['corpusFingerprint','corpus'],['comparisonProfileId','profil de comparaison'],['valuationId','barème']]) {
      if (!same(document[field], context[field])) globalReasons.push(label);
    }
    const staleZoneIds = new Set();
    for (const zone of document.zones) {
      const expected = expectedById.get(zone.id);
      if (globalReasons.length || zone.source.referenceId !== context.referenceId || zone.source.referencePointId !== expected.source.referencePointId) staleZoneIds.add(zone.id);
    }
    return {document, staleZoneIds, reasons: globalReasons};
  }

  function observation(zone, rows) {
    const row = rows.find(item => item.scenarioId === zone.scenarioId && item.side === zone.side);
    if (!row) return null;
    const coordinate = zone.endpoint === 'base' ? 'from' : 'to';
    return {x: row.micro.vector.x[coordinate], y: row.micro.vector.y[zone.yMetric][coordinate]};
  }

  function state(zone, rows, staleZoneIds) {
    if (!zone.enabled) return 'disabled';
    if (staleZoneIds.has(zone.id)) return 'stale';
    const point = observation(zone, rows);
    if (!point || !finite(point.x) || !finite(point.y)) return 'not-applicable';
    if (point.x < 0 || point.x > 1 || point.y < 0 || point.y > 1) return 'outside';
    const dx = (point.x - zone.center.x) / zone.radii.x;
    const dy = (point.y - zone.center.y) / zone.radii.y;
    return dx * dx + dy * dy <= 1 + BOUNDARY_TOLERANCE ? 'inside' : 'outside';
  }

  function summary(document, rows, staleZoneIds, expectedCount) {
    const result = {confirmed:0, satisfied:0, drafts:0, disabled:0, stale:0, notApplicable:0, missing:Math.max(0, expectedCount-document.zones.length)};
    for (const zone of document.zones) {
      const current = state(zone, rows, staleZoneIds);
      if (current === 'disabled') { result.disabled++; continue; }
      if (current === 'stale') { result.stale++; continue; }
      if (zone.approval === 'draft') { result.drafts++; continue; }
      result.confirmed++;
      if (current === 'inside') result.satisfied++;
      if (current === 'not-applicable') result.notApplicable++;
    }
    return result;
  }

  function serialize(document) { return `${JSON.stringify(document, null, 2)}\n`; }

  return {SCHEMA_VERSION, MAX_IMPORT_BYTES, MIN_RADIUS, MAX_RADIUS, BOUNDARY_TOLERANCE, clone, zoneKey, importMonotypeObjectives, linkWinRate, hasLinkedWinRate, createEditorInteractionState, migrate, validateAndClassify, observation, state, summary, serialize};
})();
