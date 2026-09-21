<!DOCTYPE html>
<html lang="zh-CN" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>订阅防共享 - SubscriptionGuard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        border: 'hsl(240 3.7% 15.9%)',
                        background: 'hsl(240 10% 3.9%)',
                        foreground: 'hsl(0 0% 98%)',
                        card: 'hsl(240 10% 3.9%)',
                        'card-foreground': 'hsl(0 0% 98%)',
                        muted: 'hsl(240 3.7% 15.9%)',
                        'muted-foreground': 'hsl(240 5% 64.9%)',
                        accent: 'hsl(240 3.7% 15.9%)',
                        primary: 'hsl(0 0% 98%)',
                        destructive: 'hsl(0 62.8% 30.6%)',
                    }
                }
            }
        }
    </script>
    <style>
        body { background: hsl(240 10% 3.9%); color: hsl(0 0% 98%); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
        .card { background: hsl(240 10% 3.9%); border: 1px solid hsl(240 3.7% 15.9%); border-radius: 0.75rem; }
        .btn { padding: 0.5rem 1rem; border-radius: 0.375rem; font-size: 0.875rem; font-weight: 500; cursor: pointer; transition: all 0.15s; }
        .btn-primary { background: hsl(0 0% 98%); color: hsl(240 10% 3.9%); }
        .btn-primary:hover { opacity: 0.9; }
        .btn-destructive { background: hsl(0 62.8% 30.6%); color: hsl(0 0% 98%); }
        .btn-destructive:hover { opacity: 0.9; }
        .btn-outline { border: 1px solid hsl(240 3.7% 15.9%); color: hsl(0 0% 98%); background: transparent; }
        .btn-outline:hover { background: hsl(240 3.7% 15.9%); }
        .btn-ghost { color: hsl(240 5% 64.9%); background: transparent; }
        .btn-ghost:hover { background: hsl(240 3.7% 15.9%); color: hsl(0 0% 98%); }
        .badge { display: inline-flex; align-items: center; padding: 0.125rem 0.625rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 500; }
        .badge-red { background: hsl(0 62.8% 30.6% / 0.3); color: hsl(0 90% 70%); }
        .badge-green { background: hsl(142 76% 36% / 0.3); color: hsl(142 76% 56%); }
        .badge-yellow { background: hsl(48 96% 53% / 0.2); color: hsl(48 96% 63%); }
        .badge-blue { background: hsl(217 91% 60% / 0.2); color: hsl(217 91% 70%); }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 0.75rem 1rem; font-size: 0.75rem; font-weight: 500; color: hsl(240 5% 64.9%); text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid hsl(240 3.7% 15.9%); }
        td { padding: 0.75rem 1rem; font-size: 0.875rem; border-bottom: 1px solid hsl(240 3.7% 15.9% / 0.5); }
        tr:hover td { background: hsl(240 3.7% 15.9% / 0.5); }
        input, select { background: hsl(240 10% 3.9%); border: 1px solid hsl(240 3.7% 15.9%); color: hsl(0 0% 98%); padding: 0.5rem 0.75rem; border-radius: 0.375rem; font-size: 0.875rem; }
        input:focus, select:focus { outline: none; border-color: hsl(240 5% 64.9%); }
        .tab { padding: 0.5rem 1rem; font-size: 0.875rem; color: hsl(240 5% 64.9%); cursor: pointer; border-bottom: 2px solid transparent; }
        .tab.active { color: hsl(0 0% 98%); border-bottom-color: hsl(0 0% 98%); }
        .tab:hover { color: hsl(0 0% 98%); }
        .spinner { border: 2px solid hsl(240 3.7% 15.9%); border-top: 2px solid hsl(0 0% 98%); border-radius: 50%; width: 1rem; height: 1rem; animation: spin 0.6s linear infinite; display: inline-block; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .toast { position: fixed; top: 1rem; right: 1rem; padding: 0.75rem 1.25rem; border-radius: 0.5rem; font-size: 0.875rem; z-index: 9999; animation: slideIn 0.3s; }
        .toast-success { background: hsl(142 76% 36%); color: white; }
        .toast-error { background: hsl(0 84% 60%); color: white; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 50; display: flex; align-items: center; justify-content: center; }
        .modal { background: hsl(240 10% 6%); border: 1px solid hsl(240 3.7% 15.9%); border-radius: 0.75rem; max-width: 48rem; width: 90%; max-height: 80vh; overflow-y: auto; padding: 1.5rem; }
        .stat-number { font-size: 2rem; font-weight: 700; line-height: 1; }
        .stat-label { font-size: 0.875rem; color: hsl(240 5% 64.9%); margin-top: 0.25rem; }
    </style>
</head>
<body class="min-h-screen p-4 md:p-8">
    <div class="max-w-7xl mx-auto">
        <!-- Header -->
        <div class="flex items-center justify-between mb-8">
            <div>
                <h1 class="text-2xl font-bold">订阅防共享</h1>
                <p class="text-sm text-muted-foreground mt-1">SubscriptionGuard - 监控订阅拉取行为，防止多人共享</p>
            </div>
            <div class="flex gap-2">
                <button class="btn btn-outline" onclick="loadAll()">
                    <span id="refreshIcon">↻</span> 刷新
                </button>
                <a href="javascript:history.back()" class="btn btn-ghost">← 返回</a>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
            <div class="card p-5">
                <div class="stat-label">今日拉取</div>
                <div class="stat-number" id="statTodayPulls">-</div>
            </div>
            <div class="card p-5">
                <div class="stat-label">今日用户</div>
                <div class="stat-number" id="statTodayUsers">-</div>
            </div>
            <div class="card p-5">
                <div class="stat-label">可疑用户 (24h)</div>
                <div class="stat-number text-yellow-400" id="statSuspicious">-</div>
            </div>
            <div class="card p-5">
                <div class="stat-label">滥用封禁</div>
                <div class="stat-number text-red-400" id="statAbuse">-</div>
            </div>
            <div class="card p-5">
                <div class="stat-label">日志总量</div>
                <div class="stat-number text-blue-400" id="statLogs">-</div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="flex border-b border-border mb-6 gap-1 overflow-x-auto">
            <div class="tab active" data-tab="suspects" onclick="switchTab('suspects')">可疑用户</div>
            <div class="tab" data-tab="logs" onclick="switchTab('logs')">拉取日志</div>
            <div class="tab" data-tab="abuse" onclick="switchTab('abuse')">封禁记录</div>
        </div>

        <!-- Tab: Suspects -->
        <div id="tab-suspects">
            <div class="card">
                <div class="flex items-center justify-between p-4 border-b border-border">
                    <h2 class="font-semibold">多IP拉取用户排行</h2>
                    <div class="flex gap-2 items-center">
                        <select id="statsHours" onchange="loadStats()" class="text-sm">
                            <option value="6">6小时</option>
                            <option value="12">12小时</option>
                            <option value="24" selected>24小时</option>
                            <option value="48">48小时</option>
                            <option value="72">72小时</option>
                            <option value="168">7天</option>
                        </select>
                        <select id="statsMinIps" onchange="loadStats()" class="text-sm">
                            <option value="2" selected>≥2 IP</option>
                            <option value="3">≥3 IP</option>
                            <option value="5">≥5 IP</option>
                            <option value="10">≥10 IP</option>
                        </select>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table>
                        <thead>
                            <tr>
                                <th>用户ID</th>
                                <th>邮箱</th>
                                <th>独立IP数</th>
                                <th>拉取次数</th>
                                <th>最后拉取</th>
                                <th>状态</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody id="statsBody">
                            <tr><td colspan="7" class="text-center text-muted-foreground py-8">加载中...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tab: Logs -->
        <div id="tab-logs" class="hidden">
            <div class="card">
                <div class="flex flex-wrap items-center gap-3 p-4 border-b border-border">
                    <input type="text" id="logEmail" placeholder="搜索邮箱..." class="w-40">
                    <input type="text" id="logIp" placeholder="搜索IP..." class="w-36">
                    <input type="number" id="logUserId" placeholder="用户ID" class="w-24">
                    <button class="btn btn-primary" onclick="loadLogs(1)">搜索</button>
                    <button class="btn btn-outline" onclick="clearLogFilters()">清空</button>
                    <div class="flex-1"></div>
                    <button class="btn btn-destructive text-xs" onclick="cleanLogs()">清理旧日志</button>
                </div>
                <div class="overflow-x-auto">
                    <table>
                        <thead>
                            <tr>
                                <th>时间</th>
                                <th>邮箱</th>
                                <th>IP</th>
                                <th>归属地</th>
                                <th>客户端</th>
                            </tr>
                        </thead>
                        <tbody id="logsBody">
                            <tr><td colspan="5" class="text-center text-muted-foreground py-8">请搜索或切换到此标签</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="flex items-center justify-between p-4 border-t border-border">
                    <span class="text-sm text-muted-foreground" id="logsPagination"></span>
                    <div class="flex gap-2">
                        <button class="btn btn-outline text-sm" id="logsPrev" onclick="loadLogs(currentLogPage-1)" disabled>上一页</button>
                        <button class="btn btn-outline text-sm" id="logsNext" onclick="loadLogs(currentLogPage+1)" disabled>下一页</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab: Abuse -->
        <div id="tab-abuse" class="hidden">
            <div class="card">
                <div class="flex items-center justify-between p-4 border-b border-border">
                    <h2 class="font-semibold">滥用/封禁记录</h2>
                </div>
                <div class="overflow-x-auto">
                    <table>
                        <thead>
                            <tr>
                                <th>用户ID</th>
                                <th>邮箱</th>
                                <th>独立IP数</th>
                                <th>处理方式</th>
                                <th>时间</th>
                                <th>状态</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody id="abuseBody">
                            <tr><td colspan="7" class="text-center text-muted-foreground py-8">加载中...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- IP Detail Modal -->
    <div id="ipModal" class="modal-overlay hidden" onclick="if(event.target===this)closeModal()">
        <div class="modal">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-semibold text-lg" id="modalTitle">IP 详情</h3>
                <button class="btn btn-ghost text-lg" onclick="closeModal()">✕</button>
            </div>
            <div id="modalContent"></div>
        </div>
    </div>

    <script>
        const BASE = '/api/v2/{{ $secure_path }}/subscription-guard';
        let currentLogPage = 1;

        // 从 Xboard 管理后台 localStorage 读取 Sanctum token
        // 存储格式: key=XBOARD_ACCESS_TOKEN (大写), value=JSON{value:"Bearer xxx", time:..., expire:...}
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
                Object.entries(opts.params).forEach(([k, v]) => {
                    if (v !== '' && v !== null && v !== undefined) url.searchParams.set(k, v);
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

        function toast(msg, type = 'success') {
            const el = document.createElement('div');
            el.className = `toast toast-${type}`;
            el.textContent = msg;
            document.body.appendChild(el);
            setTimeout(() => el.remove(), 3000);
        }

        function formatTime(t) {
            if (!t) return '-';
            const d = new Date(t);
            if (isNaN(d)) return t;
            return d.toLocaleString('zh-CN', { month:'2-digit', day:'2-digit', hour:'2-digit', minute:'2-digit', second:'2-digit' });
        }

        function truncate(s, n = 40) {
            if (!s) return '-';
            return s.length > n ? s.substring(0, n) + '...' : s;
        }

        // ===== Dashboard Stats =====
        async function loadDashboard() {
            try {
                const res = await api('/dashboard');
                const d = res.data;
                document.getElementById('statTodayPulls').textContent = d.today_pulls.toLocaleString();
                document.getElementById('statTodayUsers').textContent = d.today_users.toLocaleString();
                document.getElementById('statSuspicious').textContent = d.suspicious_users.toLocaleString();
                document.getElementById('statAbuse').textContent = d.total_abuse.toLocaleString();
                document.getElementById('statLogs').textContent = d.total_logs.toLocaleString();
            } catch (e) {
                console.error('Dashboard load failed:', e);
            }
        }

        // ===== Stats (Suspects) =====
        async function loadStats() {
            const hours = document.getElementById('statsHours').value;
            const minIps = document.getElementById('statsMinIps').value;
            const body = document.getElementById('statsBody');
            body.innerHTML = '<tr><td colspan="7" class="text-center py-4"><span class="spinner"></span></td></tr>';
            try {
                const res = await api('/stats', { params: { hours, min_ips: minIps } });
                const data = res.data;
                if (!data.length) {
                    body.innerHTML = '<tr><td colspan="7" class="text-center text-muted-foreground py-8">暂无可疑用户</td></tr>';
                    return;
                }
                body.innerHTML = data.map(u => `
                    <tr>
                        <td class="font-mono text-sm">#${u.user_id}</td>
                        <td>${u.email || '-'}</td>
                        <td>
                            <span class="badge ${u.unique_ips >= 5 ? 'badge-red' : u.unique_ips >= 3 ? 'badge-yellow' : 'badge-blue'}">
                                ${u.unique_ips} 个IP
                            </span>
                        </td>
                        <td>${u.total_pulls}</td>
                        <td class="text-muted-foreground text-sm">${formatTime(u.last_pull)}</td>
                        <td>${u.banned ? '<span class="badge badge-red">已封禁</span>' : '<span class="badge badge-green">正常</span>'}</td>
                        <td class="flex gap-1">
                            <button class="btn btn-ghost text-xs" onclick="showIpDetail(${u.user_id}, '${u.email}')">详情</button>
                            ${!u.banned ? `<button class="btn btn-destructive text-xs" onclick="banUser(${u.user_id})">封禁</button>` : `<button class="btn btn-outline text-xs" onclick="unbanUser(${u.user_id})">解封</button>`}
                        </td>
                    </tr>
                `).join('');
            } catch (e) {
                body.innerHTML = `<tr><td colspan="7" class="text-center text-red-400 py-4">${e.message}</td></tr>`;
            }
        }

        // ===== Logs =====
        async function loadLogs(page = 1) {
            currentLogPage = page;
            const body = document.getElementById('logsBody');
            body.innerHTML = '<tr><td colspan="5" class="text-center py-4"><span class="spinner"></span></td></tr>';
            try {
                const res = await api('/logs', {
                    params: {
                        page,
                        page_size: 20,
                        email: document.getElementById('logEmail').value,
                        ip: document.getElementById('logIp').value,
                        user_id: document.getElementById('logUserId').value,
                    }
                });
                const pg = res.data;
                const data = pg.data;
                document.getElementById('logsPagination').textContent = `第 ${pg.current_page}/${pg.last_page} 页，共 ${pg.total} 条`;
                document.getElementById('logsPrev').disabled = pg.current_page <= 1;
                document.getElementById('logsNext').disabled = pg.current_page >= pg.last_page;

                if (!data.length) {
                    body.innerHTML = '<tr><td colspan="5" class="text-center text-muted-foreground py-8">暂无记录</td></tr>';
                    return;
                }
                body.innerHTML = data.map(l => `
                    <tr>
                        <td class="text-muted-foreground text-sm whitespace-nowrap">${formatTime(l.created_at)}</td>
                        <td>${l.email || '#' + l.user_id}</td>
                        <td class="font-mono text-sm">${l.ip}</td>
                        <td class="text-sm">${l.ip_region || '-'}</td>
                        <td class="text-sm text-muted-foreground" title="${l.user_agent || ''}">${truncate(l.user_agent, 30)}</td>
                    </tr>
                `).join('');
            } catch (e) {
                body.innerHTML = `<tr><td colspan="5" class="text-center text-red-400 py-4">${e.message}</td></tr>`;
            }
        }

        function clearLogFilters() {
            document.getElementById('logEmail').value = '';
            document.getElementById('logIp').value = '';
            document.getElementById('logUserId').value = '';
            loadLogs(1);
        }

        // ===== Abuse =====
        async function loadAbuse() {
            const body = document.getElementById('abuseBody');
            body.innerHTML = '<tr><td colspan="7" class="text-center py-4"><span class="spinner"></span></td></tr>';
            try {
                const res = await api('/abuse');
                const data = res.data.data;
                if (!data.length) {
                    body.innerHTML = '<tr><td colspan="7" class="text-center text-muted-foreground py-8">暂无封禁记录</td></tr>';
                    return;
                }
                const modeMap = { auto_ban: '自动封禁', manual_ban: '手动封禁', reset_only: '重置订阅', alert_only: '仅告警' };
                body.innerHTML = data.map(a => `
                    <tr>
                        <td class="font-mono text-sm">#${a.user_id}</td>
                        <td>${a.email || '-'}</td>
                        <td><span class="badge badge-red">${a.unique_ip_count} 个IP</span></td>
                        <td><span class="badge badge-yellow">${modeMap[a.action_taken] || a.action_taken}</span></td>
                        <td class="text-muted-foreground text-sm">${formatTime(a.created_at)}</td>
                        <td>${a.banned ? '<span class="badge badge-red">已封禁</span>' : '<span class="badge badge-green">正常</span>'}</td>
                        <td class="flex gap-1">
                            <button class="btn btn-ghost text-xs" onclick="showIpDetail(${a.user_id}, '${a.email}')">详情</button>
                            ${a.banned ? `<button class="btn btn-outline text-xs" onclick="unbanUser(${a.user_id})">解封</button>` : ''}
                        </td>
                    </tr>
                `).join('');
            } catch (e) {
                body.innerHTML = `<tr><td colspan="7" class="text-center text-red-400 py-4">${e.message}</td></tr>`;
            }
        }

        // ===== Actions =====
        async function banUser(userId) {
            if (!confirm(`确认封禁用户 #${userId} 并重置其订阅？`)) return;
            try {
                await api('/ban', { method: 'POST', body: { user_id: userId } });
                toast('用户已封禁');
                loadAll();
            } catch (e) { toast(e.message, 'error'); }
        }

        async function unbanUser(userId) {
            if (!confirm(`确认解封用户 #${userId}？`)) return;
            try {
                await api('/unban', { method: 'POST', body: { user_id: userId } });
                toast('用户已解封');
                loadAll();
            } catch (e) { toast(e.message, 'error'); }
        }

        async function cleanLogs() {
            const days = prompt('清理多少天前的日志？', '30');
            if (!days) return;
            try {
                const res = await api('/clean-logs', { method: 'POST', body: { days: parseInt(days) } });
                toast(res.message);
                loadDashboard();
            } catch (e) { toast(e.message, 'error'); }
        }

        // ===== IP Detail Modal =====
        async function showIpDetail(userId, email) {
            const hours = document.getElementById('statsHours')?.value || 24;
            document.getElementById('modalTitle').textContent = `IP 详情 - ${email || '#' + userId}`;
            document.getElementById('ipModal').classList.remove('hidden');
            document.getElementById('modalContent').innerHTML = '<div class="text-center py-8"><span class="spinner"></span> 加载中...</div>';
            try {
                const res = await api('/user-ip-detail', { params: { user_id: userId, hours } });
                const data = res.data;
                if (!data.length) {
                    document.getElementById('modalContent').innerHTML = '<p class="text-center text-muted-foreground py-4">暂无记录</p>';
                    return;
                }
                let html = `<p class="text-sm text-muted-foreground mb-4">最近 ${hours} 小时内共 ${data.length} 条记录</p>`;
                html += '<table><thead><tr><th>IP</th><th>归属地</th><th>拉取次数</th><th>首次</th><th>最后</th><th>客户端</th></tr></thead><tbody>';
                data.forEach(d => {
                    html += `<tr>
                        <td class="font-mono text-sm">${d.ip}</td>
                        <td class="text-sm">${d.ip_region || '-'}</td>
                        <td><span class="badge badge-blue">${d.pull_count}次</span></td>
                        <td class="text-sm text-muted-foreground">${formatTime(d.first_pull)}</td>
                        <td class="text-sm text-muted-foreground">${formatTime(d.last_pull)}</td>
                        <td class="text-sm text-muted-foreground" title="${d.user_agent || ''}">${truncate(d.user_agent, 25)}</td>
                    </tr>`;
                });
                html += '</tbody></table>';
                document.getElementById('modalContent').innerHTML = html;
            } catch (e) {
                document.getElementById('modalContent').innerHTML = `<p class="text-center text-red-400">${e.message}</p>`;
            }
        }

        function closeModal() {
            document.getElementById('ipModal').classList.add('hidden');
        }

        // ===== Tabs =====
        function switchTab(tab) {
            document.querySelectorAll('[data-tab]').forEach(el => el.classList.toggle('active', el.dataset.tab === tab));
            document.getElementById('tab-suspects').classList.toggle('hidden', tab !== 'suspects');
            document.getElementById('tab-logs').classList.toggle('hidden', tab !== 'logs');
            document.getElementById('tab-abuse').classList.toggle('hidden', tab !== 'abuse');
            if (tab === 'logs') loadLogs(1);
            if (tab === 'abuse') loadAbuse();
            if (tab === 'suspects') loadStats();
        }

        // ===== Init =====
        function getActiveTab() {
            const active = document.querySelector('[data-tab].active');
            return active ? active.dataset.tab : 'suspects';
        }

        function loadAll() {
            loadDashboard();

            const tab = getActiveTab();
            if (tab === 'logs') {
                loadLogs(currentLogPage || 1);
            } else if (tab === 'abuse') {
                loadAbuse();
            } else {
                loadStats();
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            if (!getAuthToken()) {
                document.body.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100vh;color:#999;">请先登录管理后台后再访问此页面</div>';
                return;
            }
            loadAll();
        });
        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
    </script>
</body>
</html>
