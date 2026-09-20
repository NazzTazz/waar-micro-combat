'use strict';

const assert = require('node:assert/strict');
const net = require('node:net');
const {spawn} = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {once} = require('node:events');

function freePort() {
  return new Promise((resolve, reject) => {
    const server = net.createServer();
    server.once('error', reject);
    server.listen(0, '127.0.0.1', () => {
      const {port} = server.address();
      server.close(error => error ? reject(error) : resolve(port));
    });
  });
}

async function waitFor(url) {
  for (let attempt = 0; attempt < 50; attempt++) {
    try {
      const response = await fetch(url);
      if (response.ok) return;
    } catch (_) {}
    await new Promise(resolve => setTimeout(resolve, 50));
  }
  throw new Error('Le serveur de test ne répond pas.');
}

(async () => {
  const port = await freePort();
  const origin = `http://127.0.0.1:${port}`;
  const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'waar-demo-http-'));
  const env = {...process.env, WAAR_DEMO_SERIALIZE:'1', TMP:temporary, TEMP:temporary, TMPDIR:temporary};
  const server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', 'public/workshop', 'bin/workshop-router.php'], {cwd: path.join(__dirname, '..'), env, stdio: 'ignore'});
  try {
    await waitFor(origin + '/');
    const profileResponse = await fetch(origin + '/api/default-profile');
    const profilePayload = await profileResponse.json();
    assert.equal(profileResponse.status, 200);
    assert.equal(profilePayload.data.profile.combat.lossCompressionPercent, 5);
    assert.equal(profilePayload.data.profile.schemaVersion, 'waar-engine-profile/0.2');
    assert.equal(profilePayload.data.profile.id, 'test-2');
    assert.deepEqual(Object.values(profilePayload.data.profile.units).map(unit=>[unit.cost,unit.attack,unit.structure,unit.baseAccuracy,unit.strikesPerAttack,unit.defendingEfficiency,unit.capturable]),[
      [10,'9','25','0.11',1,'1',true],
      [70,'12','120','0.6',1,'2',false],
      [70,'70','50','0.35',5,'1',false],
      [550,'350','250','0.8',15,'1',false],
    ]);
    assert.deepEqual(profilePayload.data.profile.relations,[]);
    assert.deepEqual(profilePayload.data.profile.combat,{maxRounds:20,surrenderEnabled:true,surrenderDeadPercent:50,tieBreakCriterion:'structure',equalityPolicy:'defender',lossCompressionPercent:5,capturePercent:10});

    assert.equal(profilePayload.data.profile.weather.neutral.soldier.attack,'1');
    assert.equal(profilePayload.data.profile.weather.blizzard.soldier.attack,'0.875');
    assert.equal(profilePayload.data.profile.weather.snow.soldier.attack,'0.75');
    assert.equal(profilePayload.data.profile.weather.storm.archer.attack,'0.75');
    assert.equal(profilePayload.data.profile.weather.storm.archer.baseAccuracy,'1');
    const traversal = await fetch(origin + '/..%2Fcomposer.json');
    assert.equal(traversal.status, 404);

    const foreignOrigin = await fetch(origin + '/api/validate', {
      method: 'POST',
      headers: {'Content-Type': 'application/json', Origin: 'https://example.invalid'},
      body: JSON.stringify({profile: profilePayload.data.profile}),
    });
    assert.equal(foreignOrigin.status, 403);
    const draft = structuredClone(profilePayload.data.profile);
    draft.units.archer = null;
    const validate = async mode => (await (await fetch(origin + '/api/validate', {
      method: 'POST', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({profile: draft, mode}),
    })).json()).data;
    assert.deepEqual((await validate('draft')).errors, []);
    assert.equal((await validate('complete')).errors[0].code, 'missing_unit');
    draft.units.soldier.attack = 'invalid';
    assert.equal((await validate('draft')).errors[0].code, 'invalid_decimal');
    const profile=profilePayload.data.profile;
    const locker=spawn('php',['-r',"$f=fopen(sys_get_temp_dir().'/waar-demo-compute.lock','c');flock($f,LOCK_EX);echo 'ready';fflush(STDOUT);sleep(30);"],{env});
    try {
      await once(locker.stdout,'data');
      const busy=await fetch(origin+'/api/measure',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({profile,iterations:1})});
      assert.equal(busy.status,429);
      assert.equal(busy.headers.get('retry-after'),'10');
      assert.match((await busy.json()).errors[0].message,/déjà en cours/);
      assert.equal((await fetch(origin+'/api/default-profile')).status,200);
    } finally { const exited=once(locker,'exit');locker.kill();await exited; }
    const post=async(path,body)=>{const response=await fetch(origin+'/api/'+path,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});const payload=await response.json();assert.equal(response.status,200,JSON.stringify(payload));return payload.data};
    const duel=await post('duel',{requestId:'http',profile,armies:{A:{soldier:100},B:{archer:50}},weather:{A:'neutral',B:'wind'},seed:42});
    assert.equal(duel.modelVersion,'waar-cohort-v2');assert.equal(duel.runtime.kind,'rust');assert.equal(duel.directions.length,2);assert.equal(duel.directions[0].result.schemaVersion,'waar-combat-result/2');
    const measure=await post('measure',{profile,weather:'neutral',seed:42,iterations:1});
    assert.equal(measure.modelVersion,'waar-cohort-v2');assert.equal(measure.context.runtime.kind,'rust');assert.equal(measure.batch.totalCombats,16);assert.equal(measure.rows.length,32);
    const zones=measure.rows.map(row=>({id:row.id,center:{x:row.winRate,y:row.rawCasualtyRatio},radii:{x:.05,y:.1},sourceFingerprint:measure.profileFingerprint,modelVersion:measure.modelVersion,context:measure.context}));
    const optimized=await post('optimize',{profile,zones,weather:'neutral',seed:314159,measurementBaseSeed:42,budget:8,iterations:1});
    assert.equal(optimized.schemaVersion,'waar-optimizer-report/1');assert.equal(optimized.algorithm,'waar-profile-evolution/1');assert.equal(optimized.evaluated,8);assert.equal(optimized.selectionPerformed,false);assert.equal(optimized.stopReason,'objectives_satisfied');
    const legacy=structuredClone(profile);legacy.schemaVersion='waar-engine-profile/0.1';for(const unit of Object.values(legacy.units)){delete unit.baseAccuracy;delete unit.accuracySpread;delete unit.strikesPerAttack}legacy.weather=Object.fromEntries(['neutral','rain','snow','heat'].map(id=>[id,Object.fromEntries(Object.entries(legacy.weather[id]).map(([type,value])=>[type,value.attack]))]));legacy.combat={maxRounds:3,randomSpread:'0.1',tieBreakPolicy:'defender',lossCompressionPercent:8,capturePercent:0};
    const migrated=await post('migrate-profile',{profile:legacy});assert.equal(migrated.migration.performed,true);assert.equal(migrated.migration.measurementsObsolete,true);assert.equal(migrated.profile.units.archer.baseAccuracy,'0.15');
    console.log('workshop-http: ok (native duel, one-call batch, evolutionary optimizer, explicit migration)');
  } finally {
    const exited=once(server,'exit');server.kill();await exited;
    fs.rmSync(temporary,{recursive:true,force:true});
  }
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
