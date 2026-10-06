-- Arclight Cache — bounded SCAN helper (original, written from Redis docs).
-- Usage: EVAL script 0 <match> <count>
-- Returns up to COUNT matching keys per call plus next cursor.
local cursor = "0"
local match = ARGV[1] or "*"
local count = tonumber(ARGV[2]) or 100
if count < 1 then count = 1 end
if count > 1000 then count = 1000 end
local res = redis.call("SCAN", cursor, "MATCH", match, "COUNT", count)
return res
