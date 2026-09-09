'use strict';

const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');

vm.runInThisContext(fs.readFileSync(require('node:path').join(__dirname, '../resources/acceptance-zones-model.js'), 'utf8'));
const model = globalThis.WaarAcceptanceZonesModel;

function zone(id = 's1-attacker-base-survivors') {
  return {
    id, scenarioId: 's1', side: 'attacker', endpoint: 'base', xMetric: 'winRate', yMetric: 'survivors', shape: 'ellipse',
    center: {x: 0.5, y: 0.5}, radii: {x: 0.05, y: 0.1}, enabled: true, approval: 'draft',
    source: {kind: 'legacy', referenceId: 'legacy-1', referencePointId: 's1/attacker', originalCenter: {x: 0.5, y: 0.5}, modifiedManually: false}
  };
}
function document() {
  return {
    schemaVersion: model.SCHEMA_VERSION, experimentId: 'experiment-1', corpusFingerprint: 'a'.repeat(64),
    comparisonProfileId: 'profile-1', valuationId: 'valuation-1',
    generation: {id: 'generator-1', label: '<img src=x onerror=alert(1)>', radiusX: 0.05, radiusY: 0.1, readOnly: false},
    zones: [zone()]
  };
}
function context() {
  return {experimentId: 'experiment-1', corpusFingerprint: 'a'.repeat(64), comparisonProfileId: 'profile-1', valuationId: 'valuation-1', referenceId: 'legacy-1', initialZones: [zone()]};
}

const original = document();
const validated = model.validateAndClassify(original, context());
assert.deepEqual(JSON.parse(model.serialize(validated.document)), original, 'The JSON round trip must preserve the portable document exactly.');
assert.equal(validated.staleZoneIds.size, 0);
assert.equal(model.state(validated.document.zones[0], [{scenarioId:'s1',side:'attacker',micro:{vector:{x:{from:.55},y:{survivors:{from:.5}}}}}], validated.staleZoneIds), 'inside', 'The inclusive ellipse boundary must match the PHP evaluator.');

const mutated = document();
mutated.zones[0].center = {x:.25,y:.75};
mutated.zones[0].radii = {x:.005,y:1};
mutated.zones[0].approval = 'confirmed';
mutated.zones[0].source.modifiedManually = true;
assert.deepEqual(JSON.parse(model.serialize(model.validateAndClassify(mutated, context()).document)), mutated);

const old = document();
old.schemaVersion = 'waar-acceptance-zones/0.1';
old.generation.readOnly = true;
delete old.zones[0].source.modifiedManually;
const migrated = model.validateAndClassify(old, context()).document;
assert.equal(migrated.schemaVersion, model.SCHEMA_VERSION);
assert.equal(migrated.generation.readOnly, false);
assert.equal(migrated.zones[0].source.modifiedManually, false);

const stale = document();
stale.corpusFingerprint = 'b'.repeat(64);
assert.equal(model.validateAndClassify(stale, context()).staleZoneIds.has(stale.zones[0].id), true, 'A composition fingerprint change under the same IDs must make the zone stale.');

for (const invalid of [
  (() => { const value=document(); value.zones[0].id='unknown'; return value; })(),
  (() => { const value=document(); value.zones.push(JSON.parse(JSON.stringify(value.zones[0]))); value.zones[1].id='duplicate-association'; return value; })(),
  (() => { const value=document(); value.zones[0].shape='polygon'; return value; })(),
  (() => { const value=document(); value.zones[0].radii.x=0; return value; })(),
  (() => { const value=document(); value.zones[0].center.x=Number.POSITIVE_INFINITY; return value; })(),
]) assert.throws(() => model.validateAndClassify(invalid, context()));

const disabled = document();
disabled.zones[0].enabled = false;
const disabledResult = model.validateAndClassify(disabled, context());
assert.equal(model.state(disabled.zones[0], [], disabledResult.staleZoneIds), 'disabled');
assert.deepEqual(model.summary(disabled, [], disabledResult.staleZoneIds, 2), {confirmed:0,satisfied:0,drafts:0,disabled:1,stale:0,notApplicable:0,missing:1});

const observation = document();
observation.zones[0].yMetric = 'structure';
observation.zones[0].source.kind = 'observation';
const observationContext = context();
observationContext.initialZones[0].yMetric = 'structure';
observationContext.initialZones[0].source.kind = 'observation';
assert.equal(model.validateAndClassify(observation, observationContext).document.zones[0].source.kind, 'observation');

const editor = model.createEditorInteractionState();
assert.equal(editor.selectZone('spearman-vs-knight/attacker/survivors', false), false);
editor.setCircle(true);
assert.equal(editor.selectZone('spearman-vs-knight/attacker/survivors', true), true, 'Circle mode remains explicit on the current zone.');
assert.equal(editor.selectZone('soldier-vs-soldier/attacker/survivors', false), false, 'Changing scenario must not carry circle mode to another ellipse.');
editor.setCircle(true);
assert.equal(editor.selectZone('soldier-vs-soldier/defender/survivors', false), false, 'Changing side must reset circle mode.');
editor.setCircle(true);
assert.equal(editor.selectZone('soldier-vs-soldier/defender/structure', false), false, 'Changing axis must reset circle mode.');
assert.equal(editor.selectZone('archer-vs-archer/attacker/structure', true), true, 'Selecting a circular geometry must restore circle mode.');
editor.setCircle(false);
assert.equal(editor.selectZone('archer-vs-archer/attacker/structure', true), false, 'Explicitly disabling circle mode must remain effective on the current zone.');
editor.reset();
assert.equal(editor.selectZone('archer-vs-archer/attacker/structure', true), true, 'History restoration must resynchronize circle mode from restored geometry.');

editor.beginDrag('{"center":{"x":0.5,"y":0.5}}');
assert.equal(editor.hasActiveDrag(), true);
assert.equal(editor.cancelDrag(), '{"center":{"x":0.5,"y":0.5}}');
assert.equal(editor.hasActiveDrag(), false, 'Escape must invalidate the active drag before residual mouse movements.');
assert.equal(editor.finishDrag(), null, 'Releasing a cancelled drag must not create a completed gesture.');

const linkedDocument = document();
linkedDocument.zones = [];
for (const metric of ['survivors', 'structure', 'economicValue']) {
  for (const side of ['attacker', 'defender']) {
    const value = zone(`s1-${side}-tip-${metric}`);
    Object.assign(value, {side, endpoint:'tip', yMetric:metric});
    value.center = {x:0.3, y:side === 'attacker' ? 0.2 : 0.8};
    value.radii = {x:0.05, y:side === 'attacker' ? 0.1 : 0.2};
    value.approval = side === 'defender' ? 'confirmed' : 'draft';
    value.enabled = metric !== 'structure';
    linkedDocument.zones.push(value);
  }
}
const unrelated = zone('s2-attacker-tip-survivors');
Object.assign(unrelated, {scenarioId:'s2', endpoint:'tip'});
linkedDocument.zones.push(unrelated);
const beforeLink = model.clone(linkedDocument);
assert.equal(model.hasLinkedWinRate(linkedDocument, 's1'), false);
linkedDocument.zones[0].center.x = 0.75;
linkedDocument.zones[0].radii.x = 0.08;
model.linkWinRate(linkedDocument, linkedDocument.zones[0].id);
assert.equal(model.hasLinkedWinRate(linkedDocument, 's1'), true);
linkedDocument.zones.slice(0,6).forEach((value,index) => {
  assert.equal(value.center.x, value.side === 'attacker' ? 0.75 : 0.25, 'All axes must describe the same complementary win rates.');
  assert.equal(value.radii.x, 0.08, 'The horizontal tolerance must remain mirrored.');
  assert.equal(value.center.y, beforeLink.zones[index].center.y, 'Survival/structure goals stay independent.');
  assert.equal(value.radii.y, beforeLink.zones[index].radii.y);
  assert.equal(value.approval, beforeLink.zones[index].approval, 'Linking must not confirm a draft or change its approval.');
  assert.equal(value.enabled, beforeLink.zones[index].enabled);
  assert.deepEqual(value.source.originalCenter, beforeLink.zones[index].source.originalCenter);
});
assert.deepEqual(linkedDocument.zones[6], beforeLink.zones[6], 'Another scenario must remain untouched.');
linkedDocument.zones[1].center.x = 0;
model.linkWinRate(linkedDocument, linkedDocument.zones[1].id);
assert.equal(linkedDocument.zones[0].center.x, 1, 'The link also works from defender to attacker at the domain boundary.');
assert.equal(model.hasLinkedWinRate(linkedDocument, 's1'), true);
assert.deepEqual(model.linkWinRate(linkedDocument, linkedDocument.zones[1].id), [], 'Repeating the link must not introduce a change.');
assert.deepEqual(JSON.parse(model.serialize(linkedDocument)), linkedDocument, 'Both linked sides survive export/import.');
editor.beginDrag(model.serialize(beforeLink));
const restoredPair = JSON.parse(editor.cancelDrag());
assert.deepEqual(restoredPair, beforeLink, 'Cancelling a gesture restores both sides, all axes and approvals atomically.');
assert.equal(editor.finishDrag(), null);

const legacyMonotype = model.clone(linkedDocument);
legacyMonotype.comparisonProfileId = 'monotype-equal-cost-v1';
const canonicalContext = {...context(), comparisonProfileId:legacyMonotype.comparisonProfileId, initialZones:legacyMonotype.zones.filter(z=>z.yMetric==='survivors')};
const legacySnapshot = model.serialize(legacyMonotype);
const canonical = model.importMonotypeObjectives(legacyMonotype, canonicalContext);
assert.equal(canonical.removedCount, 4);
assert.deepEqual(canonical.document.zones, legacyMonotype.zones.filter(z=>z.yMetric==='survivors'), 'Conversion preserves every field of the intended zones, including geometry, approval and provenance.');
assert.equal(model.serialize(legacyMonotype), legacySnapshot, 'The original export is never mutated.');
assert.equal(canonical.document.zones.length, 3);
assert.deepEqual(model.importMonotypeObjectives(canonical.document, canonicalContext).document, canonical.document, 'A canonical export round trips without extra zones.');
const staleMonotype = model.clone(legacyMonotype);
staleMonotype.corpusFingerprint = 'f'.repeat(64);
assert.equal(model.importMonotypeObjectives(staleMonotype, canonicalContext).staleZoneIds.size, 3, 'Conversion must not reanchor incompatible provenance.');
const brokenExtra = model.clone(legacyMonotype);
brokenExtra.zones.find(z=>z.yMetric==='structure').radii.x = -1;
assert.throws(()=>model.importMonotypeObjectives(brokenExtra, canonicalContext), 'Even discarded legacy entries must be validated before conversion.');
const missingSurvivor = model.clone(legacyMonotype);
missingSurvivor.zones = missingSurvivor.zones.filter(z=>z.id!=='s1-attacker-tip-survivors');
assert.equal(model.importMonotypeObjectives(missingSurvivor, canonicalContext).document.zones.length, 2, 'Import never recreates a deleted objective from an unrelated metric.');

console.log('acceptance-zones-model: ok');
