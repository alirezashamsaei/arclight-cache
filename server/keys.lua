-- Arclight Cache — key count by prefix (original, written from Redis docs).
-- Usage: EVAL script 0 <prefix> <count>
-- Iterates SCAN once (bounded) and returns {scanned, matched}.
local match = (ARGV[1] or "*") .. "*"
local count = tonumber(ARGV[2]) or 100
if count < 1 then count = 1 end
if count > 1000 then count = 1000 end
local res = redis.call("SCAN", "0", "MATCH", match, "COUNT", count)
local keys = res[2]
local matched = 0
for _i, _k in ipairs(keys) do
  matched = matched + 1
end
return {count, matched}
