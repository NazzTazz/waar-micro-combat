local json = require('cjson.safe')
local function reply(value)
    local encoded, err = json.encode(value)
    if not encoded then encoded = json.encode({ok = false, error = 'Donnée Lua non sérialisable : ' .. tostring(err)}) end
    io.write(encoded, '\n')
    io.flush()
end
local function clean(value)
    if value == json.null then return nil end
    if type(value) ~= 'table' then return value end
    for key, item in pairs(value) do value[key] = clean(item) end
    return value
end
local safe = {
    assert = assert, error = error, ipairs = ipairs, next = next, pairs = pairs,
    pcall = pcall, select = select, tonumber = tonumber, tostring = tostring, type = type,
    math = {abs = math.abs, ceil = math.ceil, floor = math.floor, max = math.max,
        min = math.min, sqrt = math.sqrt, exp = math.exp, log = math.log},
    string = {find = string.find, format = string.format, len = string.len,
        lower = string.lower, match = string.match, sub = string.sub, upper = string.upper},
    table = {concat = table.concat, insert = table.insert, move = table.move,
        pack = table.pack, remove = table.remove, sort = table.sort, unpack = table.unpack},
    ParamType = {toggle = 'toggle', slider = 'slider'},
}
local function environment()
    local library = {}
    for key, value in pairs(safe) do
        if type(value) == 'table' then
            local copy = {}
            for name, function_value in pairs(value) do copy[name] = function_value end
            library[key] = copy
        else library[key] = value end
    end
    return setmetatable({}, {__index = library})
end
local initial = io.read('*l')
if not initial then os.exit(1) end
local config, decode_error = json.decode(initial)
if not config then reply({ok = false, error = decode_error}); os.exit(1) end
local function bounded(fn, ...)
    local remaining = 100000
    debug.sethook(function()
        remaining = remaining - 1000
        if remaining <= 0 then error('limite d’instructions dépassée') end
    end, '', 1000)
    local ok, result = pcall(fn, ...)
    debug.sethook()
    if collectgarbage('count') > 32768 then error('limite mémoire dépassée') end
    collectgarbage('collect')
    return ok, result
end
local multi = type(config.sources) == 'table'
local compact = config.compact == true
local sources = multi and config.sources or {single = config.source}
local players, intentions = {}, {}
local function register(id, source)
    if type(id) ~= 'string' or type(source) ~= 'string' or players[id] then
        return nil, 'Compte ou source Lua invalide.'
    end
    local env = environment()
    local chunk, load_error = load(source, 'joueur:' .. id, 't', env)
    if not chunk then return nil, load_error end
    local ok, script_error = bounded(chunk)
    if not ok then return nil, tostring(script_error) end
    if type(rawget(env, 'next')) ~= 'function' then
        return nil, 'Le script doit définir function next(observation).'
    end
    local parameters = {}
    if rawget(env, 'parameters') ~= nil then
        if type(env.parameters) ~= 'function' then return nil, 'parameters doit être une fonction.' end
        local schema_ok, schema = bounded(env.parameters)
        if not schema_ok then return nil, tostring(schema) end
        if type(schema) ~= 'table' then return nil, 'parameters doit retourner une table.' end
        parameters = schema
    end
    local goal = type(env.goal) == 'string' and env.goal or 'Tenir les comptes'
    local method = type(env.method) == 'string' and env.method or 'Décider à partir des informations obtenues en jeu.'
    local restored = (config.states or {})[id] or {}
    if compact then
        goal = restored.goal or goal
        method = restored.method or method
    end
    players[id] = {env = env, goal = goal, method = method, memory = restored.memory or {}}
    return {goal = goal, method = method, parameters = parameters}
end
for id, source in pairs(sources) do
    local intention, script_error = register(id, source)
    if not intention then reply({ok = false, error = script_error}); os.exit(1) end
    intentions[id] = intention
end
if multi then reply({ok = true, intentions = intentions})
else reply({ok = true, goal = intentions.single.goal, method = intentions.single.method,
    parameters = intentions.single.parameters}) end
for line in io.lines() do
    local request, request_error = json.decode(line)
    if not request then reply({ok = false, error = request_error})
    else
        local player = players[multi and request.accountId or 'single']
        if multi and request.method == 'unregister' then
            players[request.accountId] = nil
            reply({ok = true})
        elseif multi and request.method == 'state' and player then
            reply({ok = true, memory = player.memory, goal = player.goal, method = player.method})
        elseif multi and request.method == 'register' then
            local intention, script_error = register(request.accountId, request.source)
            if not intention then reply({ok = false, error = script_error})
            else reply({ok = true, goal = intention.goal, method = intention.method,
                parameters = intention.parameters}) end
        elseif not player then reply({ok = false, error = 'Compte Lua inconnu.'})
        elseif request.method ~= 'next' and request.method ~= 'after_combat' then
            reply({ok = false, error = 'Méthode inconnue.'})
        else
            local env = player.env
            local observation = clean(request.observation)
            if compact then observation.memory = player.memory end
            observation.parameters = clean(request.parameters or {})
            env.goal = request.goal or player.goal
            env.method = request.methodText or player.method
            local fn = env[request.method]
            if type(fn) ~= 'function' then
                player.memory = observation.memory
                player.goal, player.method = env.goal, env.method
                local result = {ok = true, goal = env.goal, method = env.method}
                if not compact then result.memory = observation.memory end
                reply(result)
            else
                local call_ok, action = bounded(fn, observation)
                if not call_ok then reply({ok = false, error = tostring(action)})
                elseif action ~= nil and type(action) ~= 'table' then reply({ok = false, error = 'Action invalide.'})
                else
                    player.memory = observation.memory
                    player.goal, player.method = env.goal, env.method
                    local result = {ok = true, action = action, goal = env.goal, method = env.method}
                    if not compact then result.memory = observation.memory end
                    reply(result)
                end
            end
        end
    end
end
