<!DOCTYPE html>
<html lang="zh-CN" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>订阅守卫 - SubscribeGuard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        border: 'hsl(240 3.7% 15.9%)',
                        background: 'hsl(240 10% 3.9%)',
                        foreground: 'hsl(0 0% 98%)',
                        muted: 'hsl(240 3.7% 15.9%)',
                        'muted-foreground': 'hsl(240 5% 64.9%)',
                    }
                }
            }
        }
    </script>
    <style>
        body { background: hsl(240 10% 3.9%); color: hsl(0 0% 98%); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Microsoft YaHei', sans-serif; }
        .card { background: hsl(240 10% 3.9%); border: 1px solid hsl(240 3.7% 15.9%); border-radius: 0.75rem; }
        .btn { padding: 0.5rem 1rem; border-radius: 0.375rem; font-size: 0.875rem; font-weight: 500; cursor: pointer; transition: all 0.15s; }
        .btn-primary { background: hsl(0 0% 98%); color: hsl(240 10% 3.9%); }
        .btn-primary:hover { opacity: 0.9; }
        .btn-destructive { background: hsl(0 62.8% 30.6%); color: hsl(0 0% 98%); }
        .btn-destructive:hover { opacity: 0.9; }
        .btn-outline { border: 1px solid hsl(240 3.7% 15.9%); color: hsl(0 0% 98%); background: transparent; }
        .btn-outline:hover { background: hsl(240 3.7% 15.9%); }
        .btn-ghost { color: hsl(240 5% 64.9%); background: transparent; }
        .btn-ghost:hover { color: hsl(0 0% 98%); }
        .badge { display: inline-flex; align-items: center; padding: 0.125rem 0.625rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 500; }
        .badge-red { background: hsl(0 62.8% 30.6% / 0.3); color: hsl(0 90% 70%); }
        .badge-blue { background: hsl(217 91% 60% / 0.2); color: hsl(217 91% 70%); }
        .badge-yellow { background: hsl(48 96% 53% / 0.2); color: hsl(48 96% 63%); }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 0.75rem 1rem; font-size: 0.75rem; font-weight: 500; color: hsl(240 5% 64.9%); text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid hsl(240 3.7% 15.9%); white-space: nowrap; }
        td { padding: 0.75rem 1rem; font-size: 0.875rem; border-bottom: 1px solid hsl(240 3.7% 15.9% / 0.5); vertical-align: top; }
        tr:hover td { background: hsl(240 3.7% 15.9% / 0.5); }
        input, select { background: hsl(240 10% 3.9%); border: 1px solid hsl(240 3.7% 15.9%); color: hsl(0 0% 98%); padding: 0.5rem 0.75rem; border-radius: 0.375rem; font-size: 0.875rem; }
        input:focus, select:focus { outline: none; border-color: hsl(240 5% 64.9%); }
        code { color: hsl(0 0% 98%); word-break: break-all; }
        .spinner { border: 2px solid hsl(240 3.7% 15.9%); border-top: 2px solid hsl(0 0% 98%); border-radius: 50%; width: 1rem; height: 1rem; animation: spin 0.6s linear infinite; display: inline-block; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .toast { position: fixed; top: 1rem; right: 1rem; padding: 0.75rem 1.25rem; border-radius: 0.5rem; font-size: 0.875rem; z-index: 9999; animation: slideIn 0.3s; }
        .toast-success { background: hsl(142 76% 36%); color: white; }
        .toast-error { background: hsl(0 84% 60%); color: white; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
    </style>
</head>
<body class="min-h-screen p-4 md:p-8">
<div class="max-w-7xl mx-auto">
    <div class="flex items-center justify-between mb-8">
        <div>
            <h1 class="text-2xl font-bold">订阅守卫</h1>
            <p class="text-sm text-muted-foreground mt-1">SubscribeGuard - 拦截浏览器、爬虫和异常 UA 访问订阅链接</p>
        </div>
        <div class="flex gap-2">
            <button class="btn btn-outline" onclick="loadLogs()">↻ 刷新</button>
            <a href="javascript:history.back()" class="btn btn-ghost">← 返回</a>
        </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="card p-5">
            <div class="text-sm text-muted-foreground">当前展示</div>
            <div class="text-3xl font-bold mt-1" id="statShown">-</div>
        </div>
        <div class="card p-5">
            <div class="text-sm text-muted-foreground">唯一 IP</div>
            <div class="text-3xl font-bold text-blue-400 mt-1" id="statIps">-</div>
        </div>
        <div class="card p-5">
            <div class="text-sm text-muted-foreground">浏览器/爬虫</div>
            <div class="text-3xl font-bold text-red-400 mt-1" id="statBlockedUa">-</div>
        </div>
        <div class="card p-5">
            <div class="text-sm text-muted-foreground">未知 UA</div>
            <div class="text-3xl font-bold text-yellow-400 mt-1" id="statUnknownUa">-</div>
        </div>
    </div>

    <div class="card">
        <div class="flex flex-wrap items-center gap-3 p-4 border-b border-border">
            <input type="text" id="filterIp" placeholder="搜索 IP..." class="w-40">
            <input type="text" id="filterUa" placeholder="搜索 UA..." class="w-56">
            <input type="text" id="filterReason" placeholder="搜索原因..." class="w-44">
            <select id="limit" class="w-32">
                <option value="50">50 条</option>
                <option value="100" selected>100 条</option>
                <option value="200">200 条</option>
                <option value="500">500 条</option>
                <option value="1000">1000 条</option>
            </select>
            <button class="btn btn-primary" onclick="loadLogs()">搜索</button>
            <button class="btn btn-outline" onclick="clearFilters()">清空</button>
            <div class="flex-1"></div>
            <button class="btn btn-destructive text-xs" onclick="clearLogs()">清空日志</button>
        </div>

        <div class="overflow-x-auto">
            <table>
                <thead>
                <tr>
                    <th>时间</th>
                    <th>IP</th>
                    <th>用户</th>
                    <th>IP 来源</th>
                    <th>命中原因</th>
                    <th>请求路径</th>
                    <th>方法</th>
                    <th>User-Agent</th>
                </tr>
                </thead>
                <tbody id="logsBody">
                <tr>
                    <td colspan="8" class="text-center text-muted-foreground py-8">加载中...</td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    const BASE = '/api/v2/{{ $secure_path }}/subscribe-guard';
    let allLogs = [];

    function getAuthToken() {
        try {
            const raw = localStorage.getItem('XBOARD_ACCESS_TOKEN');
            if (raw) {
                const parsed = JSON.parse(raw);
                if (parsed && parsed.value) return parsed.value;
            }
        } catch (e) {}

        return '';
    }

    function getHeaders() {
        return {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': getAuthToken(),
        };
    }

    async function api(path, opts = {}) {
        const url = new URL(BASE + path, window.location.origin);
        if (opts.params) {
            Object.entries(opts.params).forEach(([key, value]) => {
                if (value !== '' && value !== null && value !== undefined) {
                    url.searchParams.set(key, value);
                }
            });
        }

        const res = await fetch(url.toString(), {
            method: opts.method || 'GET',
            headers: getHeaders(),
            body: opts.body ? JSON.stringify(opts.body) : undefined,
            credentials: 'same-origin',
        });

        if (!res.ok) {
            const err = await res.json().catch(() => ({}));
            throw new Error(err.message || `HTTP ${res.status}`);
        }

        return res.json();
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, function (char) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;',
            }[char];
        });
    }

    function toast(message, type = 'success') {
        const el = document.createElement('div');
        el.className = `toast toast-${type}`;
        el.textContent = message;
        document.body.appendChild(el);
        setTimeout(() => el.remove(), 3000);
    }

    function formatTime(value) {
        if (!value) return '-';
        const date = new Date(value.replace(' ', 'T'));
        if (isNaN(date)) return value;
        return date.toLocaleString('zh-CN', {
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        });
    }

    function truncate(value, length = 120) {
        value = String(value || '');
        return value.length > length ? value.substring(0, length) + '...' : value;
    }

    function filteredLogs() {
        const ip = document.getElementById('filterIp').value.trim().toLowerCase();
        const ua = document.getElementById('filterUa').value.trim().toLowerCase();
        const reason = document.getElementById('filterReason').value.trim().toLowerCase();

        return allLogs.filter(item => {
            if (ip && !String(item.ip || '').toLowerCase().includes(ip)) return false;
            if (ua && !String(item.ua || '').toLowerCase().includes(ua)) return false;
            if (reason && !String(item.reason || '').toLowerCase().includes(reason)) return false;
            return true;
        });
    }

    function renderStats(logs) {
        const ips = new Set(logs.map(item => item.ip).filter(Boolean));
        document.getElementById('statShown').textContent = logs.length.toLocaleString();
        document.getElementById('statIps').textContent = ips.size.toLocaleString();
        document.getElementById('statBlockedUa').textContent = logs.filter(item => String(item.reason || '').startsWith('blocked_user_agent')).length.toLocaleString();
        document.getElementById('statUnknownUa').textContent = logs.filter(item => String(item.reason || '').includes('unknown_user_agent')).length.toLocaleString();
    }

    function renderLogs() {
        const logs = filteredLogs();
        const body = document.getElementById('logsBody');

        renderStats(logs);

        if (!logs.length) {
            body.innerHTML = '<tr><td colspan="8" class="text-center text-muted-foreground py-8">暂无日志</td></tr>';
            return;
        }

        body.innerHTML = logs.map(item => {
            const query = item.query && Object.keys(item.query).length ? '?' + new URLSearchParams(item.query).toString() : '';
            const path = `${item.path || ''}${query}`;
            const reasonClass = String(item.reason || '').includes('unknown') ? 'badge-yellow' : 'badge-red';

            const sourceTitle = `直连IP: ${item.remote_ip || '-'} / Request IP: ${item.request_ip || '-'}`;
            const userText = item.user_id ? `#${item.user_id} ${item.user_email || ''}` : '-';

            return `<tr>
                <td class="text-muted-foreground whitespace-nowrap">${formatTime(item.time)}</td>
                <td><span class="badge badge-blue">${escapeHtml(item.ip || '-')}</span></td>
                <td title="${escapeHtml(userText)}"><code>${escapeHtml(userText)}</code></td>
                <td title="${escapeHtml(sourceTitle)}"><code>${escapeHtml(item.ip_source || '-')}</code></td>
                <td><span class="badge ${reasonClass}">${escapeHtml(item.reason || '-')}</span></td>
                <td><code>${escapeHtml(path || '-')}</code></td>
                <td>${escapeHtml(item.method || 'GET')}</td>
                <td title="${escapeHtml(item.ua || '')}"><code>${escapeHtml(truncate(item.ua || '-', 120))}</code></td>
            </tr>`;
        }).join('');
    }

    async function loadLogs() {
        const body = document.getElementById('logsBody');
        body.innerHTML = '<tr><td colspan="8" class="text-center py-8"><span class="spinner"></span></td></tr>';

        try {
            const limit = document.getElementById('limit').value || 100;
            const res = await api('/logs', { params: { limit } });
            allLogs = res.data?.logs || res.logs || [];
            renderLogs();
        } catch (e) {
            body.innerHTML = `<tr><td colspan="8" class="text-center text-red-400 py-8">${escapeHtml(e.message)}</td></tr>`;
            toast(e.message, 'error');
        }
    }

    async function clearLogs() {
        if (!confirm('确认清空订阅守卫日志？')) return;

        try {
            await api('/clean-logs', { method: 'POST' });
            allLogs = [];
            renderLogs();
            toast('日志已清空');
        } catch (e) {
            toast(e.message, 'error');
        }
    }

    function clearFilters() {
        document.getElementById('filterIp').value = '';
        document.getElementById('filterUa').value = '';
        document.getElementById('filterReason').value = '';
        renderLogs();
    }

    document.getElementById('filterIp').addEventListener('input', renderLogs);
    document.getElementById('filterUa').addEventListener('input', renderLogs);
    document.getElementById('filterReason').addEventListener('input', renderLogs);
    document.getElementById('limit').addEventListener('change', loadLogs);

    document.addEventListener('DOMContentLoaded', () => {
        if (!getAuthToken()) {
            document.body.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100vh;color:#999;">请先登录 Xboard 管理后台后再访问此页面</div>';
            return;
        }

        loadLogs();
    });
</script>
</body>
</html>