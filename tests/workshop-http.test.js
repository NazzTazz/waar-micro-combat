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
  const env = {...process.env, WAAR_DEMO_SERIALIZE:'1', WAAR_PROFILE_DIRECTORY:path.join(temporary,'profiles'), TMP:temporary, TEMP:temporary, TMPDIR:temporary};
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
    assert.deepEqual(profilePayload.data.profile.combat,{maxRounds:20,surrenderEnabled:true,surrenderDeadPercent:50,tieBreakCriterion:'structure',equalityPolicy:'defender',lossCompressionPercent:5,capturePercent:10,woundDamageThreshold:'0.2'});

    assert.equal(profilePayload.data.profile.weather.neutral.soldier.attack,'1');
    assert.equal(profilePayload.data.profile.weather.blizzard.soldier.attack,'0.875');
    assert.equal(profilePayload.data.profile.weather.snow.soldier.attack,'0.75');
    assert.equal(profilePayload.data.profile.weather.storm.archer.attack,'0.75');
    assert.equal(profilePayload.data.profile.weather.storm.archer.baseAccuracy,'1');
    const summaryResponse=await fetch(origin+'/api/duel-summary',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({profile:profilePayload.data.profile,armies:{A:{soldier:100},B:{soldier:100}},weather:'neutral',seed:42,requestId:'live-test'})});
    assert.equal(summaryResponse.status,200);
    const summary=(await summaryResponse.json()).data;
    assert.equal(summary.requestId,'live-test');assert.equal(summary.totalCombats,100);assert.equal(summary.iterations,50);
    assert.equal(summary.consequenceProvenance.policyVersion,'wounded-capture-then-compress/4');
    assert.equal(summary.consequenceProvenance.samplingProtocol,'sha256-binomial-tree/1');
    // Scope filter for issue #12: default CI still exercises the complete historical flow.
    if(process.env.WAAR_TEST_SCOPE==='consequences'){
      const post=async(route,body)=>{const response=await fetch(origin+'/api/'+route,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});assert.equal(response.status,200);return (await response.json()).data};
      const profile=profilePayload.data.profile,snapshot=JSON.stringify(profile);
      const duel=await post('duel',{profile,armies:{A:{knight:10},B:{soldier:1000}},weather:'neutral',seed:42});
      for(const direction of duel.directions){assert.equal(direction.consequences.policyVersion,'wounded-capture-then-compress/4');assert.equal(direction.consequences.samplingProtocol,'sha256-binomial-tree/1')}
      const measurement=await post('measure',{profile,weather:'neutral',seed:42,iterations:1});
      assert.equal(measurement.context.consequences.policyVersion,'wounded-capture-then-compress/4');
      assert.equal(JSON.stringify(profile),snapshot);
      console.log('workshop-http: consequences scope ok (118 Rust combats, no search)');return;
    }
    assert.deepEqual(summary.rows.map(row=>[row.attacker,row.defender]),[['A','B'],['B','A']]);
    for(const row of summary.rows){assert.equal(row.camps.A.winRate+row.camps.B.winRate+row.drawRate,1);for(const camp of ['A','B'])assert.ok(row.camps[camp].valueLossRate>=0&&row.camps[camp].valueLossRate<=1)}
    for(const row of summary.rows)for(const camp of ['A','B']){const c=row.camps[camp];assert.deepEqual(Object.keys(c.losses),['soldier','spearman','archer','knight']);for(const type of ['spearman','archer','knight'])assert.deepEqual(c.losses[type],{initial:0,dead:0,wounded:0});assert.equal(c.losses.soldier.initial,100);assert.ok(c.losses.soldier.dead>=0&&c.losses.soldier.wounded>=0&&c.prisoners>=0);assert.ok(Math.abs((c.losses.soldier.dead+c.losses.soldier.wounded)/100-c.valueLossRate)<1e-9)}
    const saveBody={name:'Testeur-Proposition-1',profile:profilePayload.data.profile};
    const savedResponse=await fetch(origin+'/api/save-profile',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(saveBody)});
    assert.equal(savedResponse.status,200);const saved=(await savedResponse.json()).data;
    assert.equal(saved.profile.label,saveBody.name);
    assert.equal((await (await fetch(origin+'/api/profiles')).json()).data.profiles[0].id,saved.id);
    const loaded=await fetch(origin+'/api/load-profile',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:saved.id})});
    assert.deepEqual((await loaded.json()).data.profile,saved.profile);
    assert.equal((await fetch(origin+'/api/save-profile',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(saveBody)})).status,409);
    const secondResponse=await fetch(origin+'/api/save-profile',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({...saveBody,name:'Testeur-Proposition-2'})});
    assert.equal(secondResponse.status,200,'a second distinct profile replaces the JSON store on every supported OS');
    assert.equal((await (await fetch(origin+'/api/profiles')).json()).data.profiles.length,2);
    const storedBeforeLock=fs.readFileSync(path.join(temporary,'profiles','profiles.json'),'utf8');
    const profileLocker=spawn('php',['-r',"$f=fopen(getenv('WAAR_PROFILE_DIRECTORY').'/profiles.lock','c');flock($f,LOCK_EX);echo 'ready';fflush(STDOUT);fgets(STDIN);flock($f,LOCK_UN);"],{env});
    await once(profileLocker.stdout,'data');
    const retrySave={...saveBody,name:'Testeur-Apres-Deploiement'};
    try {
      const blocked=await fetch(origin+'/api/save-profile',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(retrySave),signal:AbortSignal.timeout(2000)});
      assert.equal(blocked.status,503);
      assert.match((await blocked.json()).errors[0].message,/Réessayez/);
      assert.equal(fs.readFileSync(path.join(temporary,'profiles','profiles.json'),'utf8'),storedBeforeLock);
      assert.equal((await fetch(origin+'/api/profiles',{signal:AbortSignal.timeout(2000)})).status,200,'reads remain available while deployment holds the lock');
      assert.equal((await fetch(origin+'/api/load-profile',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:saved.id}),signal:AbortSignal.timeout(2000)})).status,200);
    } finally {
      profileLocker.stdin.end('\n');
      await once(profileLocker,'exit');
    }
    assert.equal((await fetch(origin+'/api/save-profile',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(retrySave)})).status,200);
    assert.ok(fs.existsSync(path.join(temporary,'profiles','profiles.json')));
    const invalid=await fetch(origin+'/api/save-profile',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name:'Invalid',profile:{}})});assert.equal(invalid.status,422);
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
      assert.equal((await fetch(origin+'/api/duel-summary',{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'})).status,429);
      assert.equal((await fetch(origin+'/api/default-profile')).status,200);
    } finally { const exited=once(locker,'exit');locker.kill();await exited; }
    const post=async(path,body)=>{const response=await fetch(origin+'/api/'+path,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});const payload=await response.json();assert.equal(response.status,200,JSON.stringify(payload));return payload.data};
    const duel=await post('duel',{requestId:'http',profile,armies:{A:{soldier:100},B:{archer:50}},weather:{A:'neutral',B:'wind'},seed:42});
    assert.equal(duel.modelVersion,'waar-cohort-v2');assert.equal(duel.runtime.kind,'rust');assert.equal(duel.directions.length,2);assert.equal(duel.directions[0].result.schemaVersion,'waar-combat-result/2');
    const measure=await post('measure',{profile,weather:'neutral',seed:42,iterations:1});
    assert.equal(measure.modelVersion,'waar-cohort-v2');assert.equal(measure.context.runtime.kind,'rust');assert.equal(measure.batch.totalCombats,16);assert.equal(measure.rows.length,32);
    const reference=await post('measure',{profile,weather:'neutral',seed:42,iterations:50});
    assert.equal(Object.keys(reference.mechanisms).length,16);
    const changed=structuredClone(profile);changed.combat.capturePercent=0;
    const current=await post('measure',{profile:changed,weather:'neutral',seed:42,iterations:50});
    const compared=await post('compare-monotypes',{beforeProfile:profile,afterProfile:changed,before:reference,after:current,scenarioId:'soldier-vs-soldier'});
    assert.match(compared.summary,/conséquences après compression et capture ont changé/);
    assert.equal(compared.sides.attacker.deltas.winRate,0);
    assert.notEqual(compared.sides.attacker.deltas.captureRatio,0);
    assert.deepEqual(compared.mechanisms.before,compared.mechanisms.after);
    const incompatible=await fetch(origin+'/api/compare-monotypes',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({beforeProfile:profile,afterProfile:changed,before:measure,after:current,scenarioId:'soldier-vs-soldier'})});
    assert.equal(incompatible.status,422,'partial measurements cannot be compared as the fixed reference');
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
