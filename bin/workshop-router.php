<?php

use Waar\MicroCombat\Workshop\BoundedProfileSearch;
use Waar\MicroCombat\Workshop\DuelService;
use Waar\MicroCombat\Workshop\EngineProfile;
use Waar\MicroCombat\Workshop\EngineProfileMigrator;
use Waar\MicroCombat\Workshop\EvolutionaryProfileOptimizer;
use Waar\MicroCombat\Workshop\MonotypeMeasurementService;
use Waar\MicroCombat\Workshop\ProfileValidationException;
use Waar\MicroCombat\Workshop\ConsequenceObjectives;
use Waar\MicroCombat\Workshop\T27Editor;

require dirname(__DIR__).'/autoload.php';
$public = dirname(__DIR__).'/public/workshop';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$editorAssets = ['/editor/echarts.js' => 'vendor/echarts-5.6.0.min.js', '/editor/model.js' => 'acceptance-zones-model.js', '/editor/app.js' => 'acceptance-overlay-app.js'];
if (isset($editorAssets[$path])) {
    header('Content-Type: application/javascript; charset=utf-8');
    readfile(dirname(__DIR__).'/resources/'.$editorAssets[$path]);
    return;
}
if (str_starts_with($path, '/api/')) {
    $b1Diagnostic = getenv('WAAR_B1_DIAGNOSTIC') === '1' && in_array($path, ['/api/duel-summary', '/api/duel', '/api/measure'], true);
    if ($b1Diagnostic) {
        $b1Received = hrtime(true);
        $b1LockNanoseconds = 0;
    }
    \Waar\MicroCombat\Workshop\JsonApiRuntime::begin($path);
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
            $host = $_SERVER['HTTP_HOST'] ?? '';
            if ($origin !== '' && !in_array($origin, ['http://'.$host, 'https://'.$host], true)) {
                throw new RuntimeException('Origine refusée.', 403);
            }
            if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 2_000_000) {
                throw new RuntimeException('Requête trop volumineuse.', 413);
            }
        }
        $raw = file_get_contents('php://input', false, null, 0, 2_000_001);
        if ($raw !== false && strlen($raw) > 2_000_000) {
            throw new RuntimeException('Requête trop volumineuse.', 413);
        }
        $request = ($raw === false || trim($raw) === '') ? [] : json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($request) || ($request !== [] && array_is_list($request))) {
            throw new InvalidArgumentException('Objet JSON attendu.');
        }
        if ($b1Diagnostic) {
            $b1Parsed = hrtime(true);
            $_SERVER['WAAR_B1_REQUEST_ID'] = (string)($request['requestId'] ?? '');
        }
        // A private shared demo has a single compute slot; never queue costly jobs.
        // The OS releases the lock even if PHP exits unexpectedly.
        if (getenv('WAAR_DEMO_SERIALIZE') === '1' && in_array($path, ['/api/duel', '/api/duel-summary', '/api/measure', '/api/search', '/api/optimize'], true)) {
            if ($b1Diagnostic) {
                $b1LockStarted = hrtime(true);
            }
            $computeLock = fopen(sys_get_temp_dir().'/waar-demo-compute.lock', 'c');
            if ($computeLock === false) {
                throw new RuntimeException('Calcul temporairement indisponible.', 503);
            }
            if (!flock($computeLock, LOCK_EX | LOCK_NB)) {
                header('Retry-After: 10');
                throw new RuntimeException('Un calcul est déjà en cours dans la démonstration. Réessayez après sa fin.', 429);
            }
            if ($b1Diagnostic) {
                $b1LockNanoseconds = hrtime(true) - $b1LockStarted;
            }
        }
        if ($b1Diagnostic) {
            $b1ServiceStarted = hrtime(true);
        }
        $result = match([$path, $_SERVER['REQUEST_METHOD'] ?? 'GET']) {
            ['/api/profiles', 'GET'] => (new \Waar\MicroCombat\Workshop\SharedProfiles())->listing(),
            ['/api/save-profile', 'POST'] => (new \Waar\MicroCombat\Workshop\SharedProfiles())->save(is_string($request['name'] ?? null) ? $request['name'] : '', is_array($request['profile'] ?? null) ? $request['profile'] : []),
            ['/api/load-profile', 'POST'] => (new \Waar\MicroCombat\Workshop\SharedProfiles())->load(is_string($request['id'] ?? null) ? $request['id'] : ''),
            ['/api/default-profile', 'GET'] => ['profile' => json_decode(file_get_contents(dirname(__DIR__).'/resources/workshop-default-profile.json'), true, 128, JSON_THROW_ON_ERROR)],
            ['/api/migrate-profile', 'POST'] => (new EngineProfileMigrator())->migrate(is_array($request['profile'] ?? null) ? $request['profile'] : []),
            ['/api/editor', 'POST'] => (new T27Editor())->render($request['profile'] ?? [], $request['measurement'] ?? [], $request['zones'] ?? []),
            ['/api/validate', 'POST'] => ['errors' => EngineProfile::validate($request['profile'] ?? [], match($request['mode'] ?? 'complete') {
                'draft' => [],'complete' => null,default => throw new InvalidArgumentException('Mode de validation inconnu.')
            })],
            ['/api/validate-zones', 'POST'] => ['zones' => (new ConsequenceObjectives())->validate($request['profile'] ?? [], $request['zones'] ?? [], (string)($request['weather'] ?? 'neutral'), $request['measurementBaseSeed'] ?? 42, $request['iterations'] ?? 100)],
            ['/api/duel-summary', 'POST'] => (new DuelService())->simulate($request, true),
            ['/api/duel', 'POST'] => (new DuelService())->simulate($request),
            ['/api/measure', 'POST'] => (new MonotypeMeasurementService())->measure($request['profile'] ?? [], (string)($request['weather'] ?? 'neutral'), $request['seed'] ?? 42, $request['iterations'] ?? 100),
            ['/api/search', 'POST'] => (new BoundedProfileSearch())->search($request['profile'] ?? [], $request['zones'] ?? [], (string)($request['weather'] ?? 'neutral'), $request['seed'] ?? 314159, $request['budget'] ?? 8, $request['iterations'] ?? 100, $request['bounds'] ?? [], $request['measurementBaseSeed'] ?? 42),
            ['/api/optimize', 'POST'] => (new EvolutionaryProfileOptimizer())->optimize($request['profile'] ?? [], $request['zones'] ?? [], (string)($request['weather'] ?? 'neutral'), $request['seed'] ?? 314159, $request['budget'] ?? 32, $request['iterations'] ?? 100, $request['bounds'] ?? [], $request['measurementBaseSeed'] ?? 42),
            default => throw new RuntimeException('Route API inconnue.', 404),
        };
        if ($b1Diagnostic) {
            $b1ServiceEnded = hrtime(true);
        }
        $responseBody = json_encode(['ok' => true, 'data' => $result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($b1Diagnostic) {
            $b1Encoded = hrtime(true);
            $milliseconds = static fn (int $nanos): string => number_format($nanos / 1_000_000, 3, '.', '');
            header('Server-Timing: parse;dur='.$milliseconds($b1Parsed - $b1Received).', lock;dur='.$milliseconds($b1LockNanoseconds).', service;dur='.$milliseconds($b1ServiceEnded - $b1ServiceStarted).', encode;dur='.$milliseconds($b1Encoded - $b1ServiceEnded));
            header('X-Waar-B1-Request-Id: '.preg_replace('/[^A-Za-z0-9_-]/', '', substr((string)($request['requestId'] ?? ''), 0, 64)));
        }
        echo $responseBody;
    } catch (ProfileValidationException $e) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'errors' => $e->errors], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(\Waar\MicroCombat\Workshop\JsonApiRuntime::statusFor($e));
        echo json_encode(['ok' => false, 'errors' => [['code' => 'request_error', 'path' => '', 'message' => $e->getMessage()]]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return;
}
if ($path === '/') {
    $path = '/index.html';
}
$file = realpath($public.$path);
if ($file === false || !str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', $public).'/') || !is_file($file)) {
    http_response_code(404);
    echo 'Not found';
    return;
}
$types = ['html' => 'text/html; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8'];
header('Content-Type: '.($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
readfile($file);
