#!/usr/bin/env bash
# Real Docker integration on a disposable Linux daemon (CI only by default).
set -Eeuo pipefail
script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
repo=$(CDPATH='' cd -- "$script_dir/../.." && pwd)
source "$script_dir/release-common.sh"
[[ ${EUID:-$(id -u)} == 0 && ${ENGINE_TEST_DISPOSABLE_DOCKER:-} == 1 ]] || fail 'Requires root and ENGINE_TEST_DISPOSABLE_DOCKER=1 on a disposable daemon'
[[ -z $(container_for) ]] || fail 'An engine already exists; refusing to touch it'
if docker volume inspect waar-engine-demo_profile-saves >/dev/null 2>&1; then fail 'Profile volume already exists; refusing to touch it'; fi
work=$(mktemp -d /tmp/waar-stack-test-XXXXXXXX)
upload=$(mktemp -d /tmp/waar-engine-upload.XXXXXXXXXX)
export ENGINE_DEPLOY_ROOT="$work/deploy" ENGINE_LOG_ROOT="$work/logs"
cleanup() {
    status=$?
    trap - EXIT
    if ((status)); then
        find "$work/logs" -name '*.log' -type f -exec tail -n 60 {} \; 2>/dev/null || true
        container=$(container_for)
        [[ -z $container ]] || docker logs --tail 60 "$container" || true
    fi
    # Only the container created by this disposable-daemon test. The volume
    # remains until the disposable runner disappears; never remove a volume.
    DEMO_IMAGE=waar-engine-demo:stack-test-baseline docker compose -f "$script_dir/compose.yaml" rm -sf engine || true
    rm -rf -- "$work" "$upload"
    exit "$status"
}
trap cleanup EXIT
docker network inspect wai-edge >/dev/null 2>&1 || docker network create wai-edge >/dev/null
docker build -f "$script_dir/Dockerfile" -t waar-engine-demo:stack-test-baseline "$repo"
DEMO_IMAGE=waar-engine-demo:stack-test-baseline docker compose -f "$script_dir/compose.yaml" up -d --no-build --pull never engine
baseline=$(container_for)
wait_healthy "$baseline"
docker exec -i "$baseline" php <<'PHP'
<?php
require '/app/autoload.php';
$base='http://127.0.0.1:8080';
$profile=json_decode(file_get_contents($base.'/api/default-profile'),true,128,JSON_THROW_ON_ERROR)['data']['profile'];
$context=stream_context_create(['http'=>['method'=>'POST','timeout'=>10,'header'=>'Content-Type: application/json','content'=>json_encode(['name'=>'Deployment preservation fixture','profile'=>$profile],JSON_THROW_ON_ERROR)]]);
$saved=json_decode(file_get_contents($base.'/api/save-profile',false,$context),true,128,JSON_THROW_ON_ERROR);
if(($saved['ok']??false)!==true)throw new RuntimeException('Fixture save failed');
$post=static function(string $path, array $body) use($base): array {
    $context=stream_context_create(['http'=>['method'=>'POST','timeout'=>10,'header'=>'Content-Type: application/json',
        'content'=>json_encode($body,JSON_THROW_ON_ERROR)]]);
    $response=json_decode(file_get_contents($base.$path,false,$context),true,128,JSON_THROW_ON_ERROR);
    if(($response['ok']??false)!==true)throw new RuntimeException('Bagaar Lua request failed: '.json_encode($response));
    return $response['data'];
};
$bagaarJs=file_get_contents('/app/public/workshop/bagaar.js');
if(!preg_match('/const sampleLua=`(.*?)`;/s',$bagaarJs,$matches))throw new RuntimeException('Bagaar Lua example not found');
$era=$post('/api/bagaar-start',['profile'=>$profile,'totalTicks'=>2,'luaScript'=>$matches[1],
    'accounts'=>[['id'=>'comptable','name'=>'Le comptable','policy'=>'lua'],['id'=>'casual','policy'=>'casual']]]);
$frame=$post('/api/bagaar-step',['runId'=>$era['runId'],'steps'=>2]);
if(($frame['tick']??null)!==2)throw new RuntimeException('Bagaar Lua did not advance');
$memoryScript=<<<'LUA'
goal = "Préparer un essai"
method = "Garder la première observation."
function next(observation)
    local memo = observation.memory
    if memo.first == nil then memo.first = {tick = observation.tick, unit = "soldier"} end
    if observation.self.resetCount == 0 and observation.tick > observation.self.lastResetTick + 24 then
        return {type = "reset"}
    end
    if observation.self.resetCount > 0 then
        assert(memo.first.tick == 1 and memo.first.unit == "soldier")
        goal = "Comparer un second essai"
        method = "Réutiliser l'observation du tick " .. string.format("%.0f", memo.first.tick)
    end
    return nil
end
LUA;
$era=$post('/api/bagaar-start',['profile'=>$profile,'totalTicks'=>26,'luaScript'=>$memoryScript,
    'accounts'=>[['id'=>'comptable','name'=>'Le comptable','policy'=>'lua'],
        ['id'=>'casual','policy'=>'casual','activity'=>'casual-night']]]);
$post('/api/bagaar-step',['runId'=>$era['runId'],'steps'=>24]);
$reset=$post('/api/bagaar-step',['runId'=>$era['runId'],'steps'=>1]);
$store=new \Waar\MicroCombat\Bagaar\RunStore();
$afterReset=$store->read($era['runId'])['state']['players']['comptable'];
if($afterReset['resetCount']!==1 || $afterReset['luaMemory']['first']['tick']!==1)
    throw new RuntimeException('Lua memory did not survive reset');
$post('/api/bagaar-step',['runId'=>$era['runId'],'steps'=>1]);
$afterResume=$store->read($era['runId'])['state']['players']['comptable'];
if($afterResume['luaMemory']['first']['unit']!=='soldier' || $afterResume['luaGoal']!=='Comparer un second essai'
    || $afterResume['luaMethod']!=="Réutiliser l'observation du tick 1")
    throw new RuntimeException('Lua memory or intention did not survive process restart: '.json_encode([
        'tick'=>$afterResume['lastResetTick'], 'status'=>$afterResume['status'],
        'memory'=>$afterResume['luaMemory'], 'goal'=>$afterResume['luaGoal'], 'method'=>$afterResume['luaMethod'],
    ], JSON_UNESCAPED_UNICODE));
$multiScript=<<<'LUA'
goal = "Observer mon compte"
method = "Mémoriser mes appels."
function next(observation)
    local memo = observation.memory
    if memo.owner == nil then memo.owner = observation.self.id end
    assert(memo.owner == observation.self.id)
    memo.calls = (memo.calls or 0) + 1
    goal = "Compte " .. observation.self.id
    method = "Appels " .. string.format("%.0f", memo.calls)
    return nil
end
LUA;
$scripts=array_fill_keys(['rageux','grenouille','ascenseur','fermier','scripteur','casual'],$multiScript);
$accounts=[];
foreach(array_keys($scripts) as $key){
    for($index=1;$index<=4;$index++){
        $accounts[]=['id'=>$key.$index,'policy'=>'lua','scriptKey'=>$key,'activity'=>'all-day'];
    }
}
$era=$post('/api/bagaar-start',['profile'=>$profile,'totalTicks'=>2,'luaScripts'=>$scripts,'accounts'=>$accounts]);
$post('/api/bagaar-step',['runId'=>$era['runId'],'steps'=>1]);
$post('/api/bagaar-step',['runId'=>$era['runId'],'steps'=>1]);
$players=$store->read($era['runId'])['state']['players'];
if(count($players)!==24)throw new RuntimeException('Multi Lua did not create 24 players');
foreach($players as $id=>$player){
    if($player['policy']!=='lua' || ($player['luaMemory']['owner']??null)!==$id
        || (int)($player['luaMemory']['calls']??0)!==2 || $player['luaGoal']!=="Compte $id"
        || $player['luaMethod']!=='Appels 2')
        throw new RuntimeException('Multi Lua state leaked or failed to resume for '.$id);
}
$entrantScript=<<<'LUA'
goal = "Essai de relève"
method = "Observer mon identité."
function next(observation)
    if observation.self.id == "axel" then return {type = "abandon"} end
    observation.memory.owner = observation.self.id
    return nil
end
LUA;
$era=$post('/api/bagaar-start',['profile'=>$profile,'totalTicks'=>2,'luaScripts'=>['rageux'=>$entrantScript]]);
$post('/api/bagaar-step',['runId'=>$era['runId'],'steps'=>1]);
$entrant=$store->read($era['runId'])['state']['players']['entrant-1']??null;
if(($entrant['policy']??null)!=='lua' || ($entrant['scriptKey']??null)!=='rageux')
    throw new RuntimeException('Scripted profile was not applied to replacement account');
$post('/api/bagaar-step',['runId'=>$era['runId'],'steps'=>1]);
$entrant=$store->read($era['runId'])['state']['players']['entrant-1'];
if(($entrant['luaMemory']['owner']??null)!=='entrant-1')
    throw new RuntimeException('Replacement Lua account did not receive its own memory');
PHP
volume=$(docker volume inspect --format '{{.Mountpoint}}' waar-engine-demo_profile-saves)
original=$(fingerprint "$volume/profiles.json")
mkdir "$work/source"
# Exact application source needed by Docker, including the uncommitted scripts
# when run locally. Test SHAs are synthetic and never published as releases.
tar -cf "$work/source.tar" -C "$repo" autoload.php src resources public/workshop bin/workshop-router.php bin/bagaar-lua-worker.lua bin/migrate-bagaar-trace.php ops/demo .dockerignore engines/waar-cohort/rust/Cargo.toml engines/waar-cohort/rust/Cargo.lock engines/waar-cohort/rust/src
tar -xf "$work/source.tar" -C "$work/source"
sha=$(printf '%040d' 41)
tar -cf "$upload/release.tar" -C "$work/source" .
checksum=$(fingerprint "$upload/release.tar")
bash "$script_dir/activate-release.sh" engine-v0.1.0-rc.1 "$sha" "$checksum" "$upload/release.tar"
active=$(docker inspect --format '{{.Image}}' "$(container_for)")
[[ $(fingerprint "$volume/profiles.json") == "$original" ]]
bash "$script_dir/activate-release.sh" engine-v0.1.0-rc.1 "$sha" "$checksum" "$upload/release.tar"
[[ $(docker inspect --format '{{.Image}}' "$(container_for)") == "$active" ]]
# Exercise a real post-activation verification failure with a second image.
# The fixture requires a deliberately impossible runtime. Production source
# is untouched, and rollback must restore both image and current pointer.
sed -i "s/!== 'rust'/!== 'impossible-runtime'/" "$work/source/ops/demo/verify-release.sh"
failed_sha=$(printf '%040d' 42)
tar -cf "$upload/release.tar" -C "$work/source" .
checksum=$(fingerprint "$upload/release.tar")
if bash "$script_dir/activate-release.sh" engine-v0.1.0-rc.2 "$failed_sha" "$checksum" "$upload/release.tar"; then fail 'Invalid runtime accepted'; fi
[[ $(docker inspect --format '{{.Image}}' "$(container_for)") == "$active" ]]
[[ $(readlink "$ENGINE_DEPLOY_ROOT/current") == "$ENGINE_DEPLOY_ROOT/releases/$sha" ]]
[[ $(fingerprint "$volume/profiles.json") == "$original" ]]
wait_healthy "$(container_for)"
echo 'PASS real Docker: adoption, Rust HTTP, profiles, same-SHA retry and rollback'
