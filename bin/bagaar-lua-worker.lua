local json = require('cjson.safe')
local function reply(value)
    io.write(json.encode(value), '\n')
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
        min = math.min, sqrt = math.sqrt},
    string = {find = string.find, format = string.format, len = string.len,
        lower = string.lower, match = string.match, sub = string.sub, upper = string.upper},
    table = {concat = table.concat, insert = table.insert, remove = table.remove, sort = table.sort},
}
local initial = io.read('*l')
if not initial then os.exit(1) end
local config, decode_error = json.decode(initial)
if not config then reply({ok = false, error = decode_error}); os.exit(1) end
local env = setmetatable({}, {__index = safe})
local chunk, load_error = load(config.source, 'joueur', 't', env)
if not chunk then reply({ok = false, error = load_error}); os.exit(1) end
local function bounded(fn, ...)
    local remaining = 100000
    debug.sethook(function()
        remaining = remaining - 1000
        if remaining <= 0 then error('limite d’instructions dépassée') end
    end, '', 1000)
    local ok, result = pcall(fn, ...)
    debug.sethook()
    if collectgarbage('count') > 16384 then error('limite mémoire dépassée') end
    collectgarbage('collect')
    return ok, result
end
local ok, script_error = bounded(chunk)
if not ok then reply({ok = false, error = tostring(script_error)}); os.exit(1) end
if type(rawget(env, 'next')) ~= 'function' then
    reply({ok = false, error = 'Le script doit définir function next(observation).'}); os.exit(1)
end
local goal = type(env.goal) == 'string' and env.goal:sub(1, 120) or 'Tenir les comptes'
local method = type(env.method) == 'string' and env.method:sub(1, 240) or 'Décider à partir des informations obtenues en jeu.'
reply({ok = true, goal = goal, method = method})
for line in io.lines() do
    local request, request_error = json.decode(line)
    if not request then reply({ok = false, error = request_error})
    else
        local fn = env[request.method]
        if request.method ~= 'next' and request.method ~= 'after_combat' then
            reply({ok = false, error = 'Méthode inconnue.'})
        elseif type(fn) ~= 'function' then reply({ok = true})
        else
            local call_ok, action = bounded(fn, clean(request.observation))
            if not call_ok then reply({ok = false, error = tostring(action)})
            elseif action ~= nil and type(action) ~= 'table' then reply({ok = false, error = 'Action invalide.'})
            else reply({ok = true, action = action}) end
        end
    end
end
