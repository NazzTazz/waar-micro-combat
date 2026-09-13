'use strict';

const assert = require('node:assert/strict');
const net = require('node:net');
const {spawn} = require('node:child_process');

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
  const server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', 'public/workshop', 'bin/workshop-router.php'], {cwd: require('node:path').join(__dirname, '..'), stdio: 'ignore'});
  try {
    await waitFor(origin + '/');
    const profileResponse = await fetch(origin + '/api/default-profile');
    const profilePayload = await profileResponse.json();
    assert.equal(profileResponse.status, 200);
    assert.equal(profilePayload.data.profile.combat.lossCompressionPercent, 8);

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
    console.log('workshop-http: ok');
  } finally {
    server.kill();
  }
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
