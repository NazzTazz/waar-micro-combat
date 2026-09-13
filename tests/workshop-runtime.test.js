'use strict';
const assert=require('node:assert/strict');
const net=require('node:net');
const {spawn}=require('node:child_process');
const root=require('node:path').join(__dirname,'..');
async function freePort(){return new Promise((resolve,reject)=>{const s=net.createServer();s.once('error',reject);s.listen(0,'127.0.0.1',()=>{const port=s.address().port;s.close(()=>resolve(port))})})}
async function ready(url){for(let i=0;i<100;i++){try{if((await fetch(url)).ok)return}catch{}await new Promise(r=>setTimeout(r,50))}throw new Error('Test server did not start')}
(async()=>{
  const port=await freePort(),origin=`http://127.0.0.1:${port}`;
  const server=spawn('php',['-d','max_execution_time=1','-S',`127.0.0.1:${port}`,'tests/fixtures/workshop-fatal-router.php'],{cwd:root,stdio:'ignore'});
  try{
    await ready(origin+'/api/probe');
    assert.equal((await (await fetch(origin+'/api/probe')).json()).limit,1,'short routes retain the host limit');
    assert.equal((await (await fetch(origin+'/api/measure')).json()).limit,600);
    assert.equal((await (await fetch(origin+'/api/search')).json()).limit,4800);
    assert.equal((await (await fetch(origin+'/api/optimize')).json()).limit,4800);
    const fatal=await fetch(origin+'/api/fatal');
    assert.equal(fatal.status,500);
    assert.match(fatal.headers.get('content-type'),/application\/json/);
    const body=await fatal.text();
    assert.doesNotMatch(body,/<br|<b>|partial|Intentional fatal/);
    const error=JSON.parse(body);
    assert.equal(error.ok,false);assert.equal(error.errors[0].code,'calculation_interrupted');
    const timeout=await fetch(origin+'/api/timeout',{signal:AbortSignal.timeout(10000)});
    assert.equal(timeout.status,500);
    const timeoutError=await timeout.json();
    assert.equal(timeoutError.errors[0].code,'calculation_interrupted');
    assert.match(timeoutError.errors[0].message,/durée maximale/);
    assert.equal((await (await fetch(origin+'/api/probe')).json()).ok,true,'server survives a failed request');
  }finally{server.kill()}
  console.log('workshop-runtime: ok (bounded timeouts, fatal JSON, no partial result)');
})().catch(error=>{console.error(error);process.exitCode=1});
