/* Arclight Cache — dashboard graph (vanilla canvas, no deps, original code). */
(function () {
    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }
    ready(function () {
        var canvas = document.getElementById('arclight-graph');
        if (!canvas || typeof ArclightData === 'undefined' || !ArclightData.series) { return; }
        var series = ArclightData.series;
        if (!series.length) { return; }
        var ctx = canvas.getContext('2d');
        var W = canvas.width, H = canvas.height, pad = 28;
        var max = 1;
        series.forEach(function (p) {
            if (p.requests > max) { max = p.requests; }
        });
        function x(i) { return pad + (W - pad * 2) * (series.length === 1 ? 0.5 : i / (series.length - 1)); }
        function y(v) { return H - pad - (H - pad * 2) * (v / max); }
        ctx.clearRect(0, 0, W, H);
        ctx.strokeStyle = '#e2e2e2';
        ctx.beginPath(); ctx.moveTo(pad, pad); ctx.lineTo(pad, H - pad); ctx.lineTo(W - pad, H - pad); ctx.stroke();
        function line(key, color) {
            ctx.strokeStyle = color; ctx.lineWidth = 2; ctx.beginPath();
            series.forEach(function (p, i) {
                var px = x(i), py = y(p[key] || 0);
                if (i === 0) { ctx.moveTo(px, py); } else { ctx.lineTo(px, py); }
            });
            ctx.stroke(); ctx.lineWidth = 1;
        }
        // Area under requests for readability.
        ctx.fillStyle = 'rgba(34,113,177,0.08)';
        ctx.beginPath();
        series.forEach(function (p, i) {
            var px = x(i), py = y(p.requests || 0);
            if (i === 0) { ctx.moveTo(px, H - pad); ctx.lineTo(px, py); }
            else { ctx.lineTo(px, py); }
        });
        ctx.lineTo(x(series.length - 1), H - pad); ctx.closePath(); ctx.fill();
        line('requests', '#2271b1');
        line('hits', '#00a32a');
        line('misses', '#d63638');
        // Time labels: first, middle, last.
        ctx.fillStyle = '#666'; ctx.font = '11px sans-serif';
        function label(i) {
            var d = new Date(series[i].t * 1000);
            var s = d.getHours() + ':' + ('0' + d.getMinutes()).slice(-2);
            ctx.fillText(s, x(i) - 12, H - 8);
        }
        label(0);
        if (series.length > 2) { label(Math.floor(series.length / 2)); }
        if (series.length > 1) { label(series.length - 1); }
    });
})();
