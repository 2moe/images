local utils = require "weserv.helpers.utils"
local ngx = ngx
local os = os
local unpack = unpack
local setmetatable = setmetatable
local error_template = "Error %d: Server couldn't parse the ?url= that you were looking for, %s"

--- Weserv module.
-- @module weserv
local weserv = {}
weserv.__index = weserv

--- Instantiate a Weserv object:
-- The client is responsible for downloading an image from the http(s)
-- origin.
-- @see weserv.client.request
-- The api is responsible for processing the downloaded image.
-- @see weserv.api.api.process
-- The server is responsible for outputting the processed image towards
-- the user/browser.
-- @see weserv.server.output
-- @param client client object
-- @param client The api
-- @param client The server
local function new(client, api, server)
    local self = {
        client = client,
        api = api,
        server = server,
    }
    return setmetatable(self, weserv)
end

--- Start the app.
-- @param args The URL query arguments.
function weserv:run(args)
    local res, client_err = self.client:request(args.url)

    if not res then
        local parsed_redirect_uri = args.errorredirect ~= nil and utils.parse_uri(args.errorredirect) or false

        -- Don't redirect if it's a DNS error.
        if client_err.status ~= ngx.HTTP_GONE and parsed_redirect_uri then
            local scheme, host, _, path, query = unpack(parsed_redirect_uri)
            if query and query ~= "" then
                path = path .. "?" .. query
            end

            ngx.redirect(scheme .. '://' .. host .. path)
        else
            ngx.header['Content-Type'] = 'text/plain'
            if client_err.status == ngx.HTTP_GONE then
                ngx.status = ngx.HTTP_GONE
                ngx.say(error_template:format(client_err.status,
                    'because the hostname of the origin is unresolvable (DNS) or blocked by policy.'))
            elseif client_err.status == ngx.HTTP_REQUEST_TIMEOUT then
                -- Don't send 408, otherwise the client may repeat that request.
                ngx.status = ngx.HTTP_NOT_FOUND
                ngx.say(error_template:format(ngx.HTTP_NOT_FOUND,
                    'error it got: The requested URL returned error: Operation timed out.'))
            else
                ngx.status = client_err.status
                ngx.say(error_template:format(client_err.status, 'error it got: ' .. client_err.message))
            end
        end
    else
        local image, api_err = self.api:process(res.tmpfile, args)

        if image ~= nil then
            -- Output the image.
            self.server.output(image, args)
        else
            ngx.status = api_err.status
            ngx.header['Content-Type'] = 'text/plain'
            ngx.say(error_template:format(api_err.status, 'error it got: ' .. api_err.message))
        end

        -- Remove the temporary file.
        os.remove(res.tmpfile)
    end
end

return {
    new = new,
    __object = weserv
}