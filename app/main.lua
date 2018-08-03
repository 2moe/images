local weserv = require "weserv"
local weserv_client = require "weserv.client"
local weserv_api = require "weserv.api"
local weserv_server = require "weserv.server"
local weserv_throttler = require "weserv.throttler"
local weserv_policy = require "weserv.policy"
local config = require "config"
local template = require "resty.template"
local redis = require "resty.redis"
local ngx = ngx
local type = type
local pairs = pairs
local string = string

local ip_address = ngx.var.http_x_forwarded_for or ngx.var.remote_addr

local is_exceeded = false

-- If config throttler is set and IP isn't on the throttler whitelist
if config.throttler ~= nil and config.throttler.whitelist[ip_address] == nil then
    local throttling_policy = weserv_policy.new(config.throttler.policy)

    local red = redis:new()
    red:set_timeout(config.throttler.redis.timeout)

    local redis_ip, redis_port = config.throttler.redis.host, config.throttler.redis.port

    local ok, redis_err = red:connect(redis_ip, redis_port)
    if not ok then
        ngx.log(ngx.ERR, string.format("Failed to connect to redis (for %s:%d): ", redis_ip, redis_port), redis_err)
    else
        is_exceeded = weserv_throttler.new(red, throttling_policy, config.throttler):is_exceeded(ip_address)

        -- Put it into the connection pool
        ok, redis_err = red:set_keepalive(config.throttler.redis.max_idle_timeout, config.throttler.redis.pool_size)
        if not ok then
            ngx.log(ngx.ERR, "Failed to set keepalive: ", redis_err)
        end
    end
end

-- Be careful: keys and values are unescaped according to URI escaping rules.
local args, args_err = ngx.req.get_uri_args()
if args.api == "3" then
    -- Internal redirection to the old api, if requested
    ngx.exec("/index.php", args)
    return;
end

if is_exceeded then
    ngx.status = ngx.HTTP_TOO_MANY_REQUESTS
    ngx.header["Content-Type"] = "text/plain"
    ngx.say("429 Too Many Requests - There are an unusual number of requests coming from this IP address.")
elseif args_err == "truncated" then
    ngx.status = ngx.HTTP_BAD_REQUEST
    ngx.header["Content-Type"] = "text/plain"
    ngx.say("400 Bad Request - Request arguments limit is exceeded. A maximum of 100 request arguments are parsed.")
elseif args.url ~= nil and args.url ~= "" then
    for key, val in pairs(args) do
        if type(val) == "table" then
            -- Use the first value if multiple occurrences of an argument key are given.
            local value = val[1]
            args[key] = type(value) == "boolean" and "" or value
        elseif type(val) == "boolean" then
            -- Treat boolean arguments as empty string values.
            args[key] = ""
        end
    end

    local api = weserv_api.new()
    api:add_manipulators({
        require "weserv.manipulators.trim",
        require "weserv.manipulators.thumbnail",
        require "weserv.manipulators.orientation",
        require "weserv.manipulators.crop",
        require "weserv.manipulators.letterbox",
        require "weserv.manipulators.brightness",
        require "weserv.manipulators.contrast",
        require "weserv.manipulators.gamma",
        require "weserv.manipulators.sharpen",
        require "weserv.manipulators.filter",
        require "weserv.manipulators.blur",
        require "weserv.manipulators.background",
        require "weserv.manipulators.mask"
    })

    local client = weserv_client.new(config.client)
    local app = weserv.new(client, api, weserv_server)
    app:run(args)
else
    ngx.status = ngx.HTTP_OK
    ngx.header["Content-Type"] = "text/html"

    template.render("index.html", config.template)
end