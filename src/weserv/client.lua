local http = require "resty.http"
local utils = require "weserv.helpers.utils"
local ngx = ngx
local os = os
local io = io
local next = next
local pairs = pairs
local table = table
local string = string
local assert = assert
local unpack = unpack
local tonumber = tonumber
local setmetatable = setmetatable
local error_template = 'Error 410: Server couldn\'t parse the ?url= that you were looking for, ';

local client = {}
client.__index = client

-- Instantiate a client object.
--
-- @param config Client config.
local function new(config)
    local self = {
        config = config,
    }
    return setmetatable(self, client)
end

-- Remap errors.
--
-- @param err Error to remap.
-- @return New error
function client:remap_error(err)
    local error_remap = {
        ['(3: Host not found)'] = 'because the hostname of the origin is unresolvable (DNS) or blocked by policy.',
        ['(110: Operation timed out)'] = 'error it got: The requested URL returned error: Operation timed out.',
        ['timeout'] = 'error it got: The requested URL returned error: Operation timed out.',
    }
    local new_err = err ~= nil and 'error it got: ' .. err or 'error it got: The requested URL returned an error'

    for k, v in pairs(error_remap) do
        if err:sub(-#k) == k then
            new_err = v
            break
        end
    end

    return error_template .. new_err
end

-- Check if the response is valid
--
-- @param res The response.
-- @return true if valid, otherwise false with error
function client:is_valid_response(res)
    if next(self.config.allowed_mime_types) ~= nil and self.config.allowed_mime_types[res.headers['Content-Type']] == nil then
        local supported_images = {}
        for k, _ in pairs(self.config.allowed_mime_types) do
            supported_images[#supported_images + 1] = k
        end

        return nil, string.format("The request image is not a valid (supported) image.\nAllowed mime types: %s", table.concat(supported_images, ", "))
    end

    if self.config.max_image_size ~= 0 then
        local length = tonumber(res.headers['Content-Length'])

        if length ~= nil and length > self.config.max_image_size then
            return nil, string.format("The image is too big to be downloaded.\nImage size: %s\nMax image size: %s", utils.format_bytes(length), utils.format_bytes(self.config.max_image_size))
        end
    end

    if res.status ~= ngx.HTTP_OK then
        return nil, error_template .. 'error it got: The requested URL returned error:' .. res.status
    end

    return true
end

-- Download content to a file.
--
-- @param uri The URI.
-- @param addl_headers Additional headers to add.
-- @param redirect_nr Count how many redirects we've followed.
-- @return File name.
function client:request_uri(uri, addl_headers, redirect_nr)
    addl_headers = addl_headers or {}
    redirect_nr = redirect_nr or 1

    if redirect_nr >= self.config.max_redirects then
        return nil, string.format("Will not follow more than %d redirects", self.config.max_redirects)
    end

    local params = {
        headers = {
            ['Accept-Encoding'] = 'gzip',
            ['User-Agent'] = self.config.user_agent
        },
        path = '',
        query = '',
        ssl_verify = false,
    }

    for k, v in pairs(addl_headers) do
        params.headers[k] = v
    end

    local parsed_uri, err = utils.parse_uri(uri)
    if not parsed_uri then
        return nil, err
    end

    local scheme, host, port, path, query = unpack(parsed_uri)
    params.path = path
    params.query = query

    local httpc = http.new()
    httpc:set_timeouts(self.config.timeouts.connect, self.config.timeouts.send, self.config.timeouts.read)

    local c, err = httpc:connect(host, port)

    if not c then
        return nil, self:remap_error(err)
    end

    if scheme == 'https' then
        local verify = true
        if params.ssl_verify == false then
            verify = false
        end
        local ok, err = httpc:ssl_handshake(nil, host, verify)
        if not ok then
            return nil, error_template .. 'error it got: Failed to do SSL handshake.'
        end
    end

    local res, err = httpc:request(params)
    if not res then
        return nil, self:remap_error(err)
    end

    if res.status >= 300 and res.status <= 308 and res.headers['Location'] ~= nil then
        local referer = {
            ['Referer'] = uri
        }

        -- recursive call
        return self:request_uri(res.headers['Location'], referer, redirect_nr + 1)
    end

    local valid, err = self:is_valid_response(res)
    if not valid then
        return nil, err
    end

    -- Now we can use the body_reader iterator,
    -- to stream the body according to our desired chunk size.
    local reader = res.body_reader

    if not reader then
        -- Most likely HEAD or 304 etc.
        return nil, error_template .. 'error it got: No body to be read'
    end

    -- Create a unique file (starting with 'imo_') in our shared memory.
    res.tmpfile = utils.tempname('/dev/shm', 'imo_')
    if res.tmpfile == nil then
        ngx.log(ngx.ERR, 'Unable to generate a unique file.')

        return nil, error_template .. 'error it got: Unable to generate a unique file.'
    end

    local f = assert(io.open(res.tmpfile, 'wb'))

    repeat
        local chunk, err = reader(8192)
        if err then
            ngx.log(ngx.ERR, err)
            break
        end

        if chunk then
            f:write(chunk)
        end
    until not chunk
    f:close()

    local ok, err = httpc:set_keepalive()
    if not ok then
        ngx.log(ngx.ERR, 'Failed to set keepalive', err)
        os.remove(res.tmpfile)

        return nil, error_template .. 'error it got: Failed to set keepalive.'
    end

    return res, nil
end

return {
    new = new,
    __object = client
}