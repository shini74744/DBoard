(() => {
  window.DBoardUserNotice = {
    async open({ panel, shell, request, el, isCurrent }) {
      shell('用户须知', '设置登录、注册和找回密码页面的提示弹窗。保存后，用户刷新页面时读取最新配置。');
      const status = el('output', 'dboard-marketing-status', '正在加载…');
      status.setAttribute('aria-live', 'polite');
      panel.append(status);
      try {
        const data = await request('user-notice/fetch');
        if (!isCurrent()) return;
        const form = el('form', 'dboard-marketing-form');
        const enabled = el('input'); enabled.type = 'checkbox'; enabled.name = 'enabled'; enabled.checked = !!data.enabled;
        const check = el('label', 'dboard-marketing-check');
        check.append(enabled, document.createTextNode('启用用户须知弹窗')); form.append(check);
        const field = (name, label, type, value) => {
          const wrap = el('label', 'dboard-marketing-field'); wrap.append(el('span', '', label));
          const input = el(type === 'textarea' ? 'textarea' : 'input');
          if (type !== 'textarea') input.type = type;
          input.name = name; input.value = value ?? ''; input.required = true;
          wrap.append(input); return { wrap, input };
        };
        const title = field('title', '弹窗标题', 'text', data.title); title.input.maxLength = 100;
        const content = field('content', '正文（支持段落、加粗、列表和链接等简单 HTML）', 'textarea', data.content);
        content.input.rows = 8; content.input.maxLength = 20000;
        form.append(title.wrap, content.wrap);
        const grid = el('div', 'dboard-marketing-grid');
        for (const [key, label, max] of [
          ['cooldown_hours', '再次提示间隔（小时）', 720],
          ['close_wait_seconds', '关闭倒计时（秒）', 60],
        ]) {
          const item = field(key, label, 'number', data[key] ?? 0);
          item.input.min = '0'; item.input.max = String(max); item.input.step = '1'; grid.append(item.wrap);
        }
        form.append(grid, el('p', '', '提示间隔为 0 时，每次刷新后首次进入上述页面会提示；同一次页面打开期间不重复弹出。关闭倒计时为 0 时可立即关闭。'));
        form.append(el('h2', 'dboard-marketing-section-title', '内容预览'));
        const preview = el('iframe'); preview.title = '用户须知内容预览'; preview.setAttribute('sandbox', '');
        preview.style.cssText = 'width:100%;height:280px;border:1px solid #b9d0da;border-radius:12px;background:white;';
        const escape = value => { const node = el('span', '', value); return node.innerHTML; };
        const render = () => {
          // No scripts, remote content or navigation in an isolated preview.
          const doc = new DOMParser().parseFromString(content.input.value, 'text/html');
          doc.querySelectorAll('script,style,iframe,object,embed,svg,math,form,base,meta,link').forEach(node => node.remove());
          doc.body.querySelectorAll('*').forEach(node => {
            if (!['P','BR','STRONG','B','EM','I','U','UL','OL','LI','H2','H3','H4','BLOCKQUOTE','A'].includes(node.tagName)) { node.replaceWith(...node.childNodes); return; }
            for (const attr of [...node.attributes]) node.removeAttribute(attr.name);
          });
          preview.srcdoc = '<!doctype html><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'"><style>body{font:16px/1.6 system-ui;color:#334155;padding:16px;overflow-wrap:anywhere}h2{font-size:20px}strong{color:#176c87}</style><h2>' + escape(title.input.value) + '</h2>' + doc.body.innerHTML;
        };
        title.input.addEventListener('input', render); content.input.addEventListener('input', render); render();
        form.append(preview);
        const save = el('button', 'dboard-marketing-primary', '保存用户须知'); save.type = 'submit'; form.append(save);
        form.addEventListener('submit', async event => {
          event.preventDefault(); save.disabled = true; status.textContent = '正在保存…';
          try {
            const result = await request('user-notice/save', {
              enabled: enabled.checked, title: title.input.value.trim(), content: content.input.value.trim(),
              cooldown_hours: Number(form.elements.cooldown_hours.value),
              close_wait_seconds: Number(form.elements.close_wait_seconds.value),
            });
            if (!isCurrent()) return;
            title.input.value = result.title; content.input.value = result.content; render();
            status.textContent = '已保存，用户刷新登录、注册或找回密码页面后生效。';
          } catch (error) { status.textContent = error.message; }
          finally { save.disabled = false; }
        });
        panel.append(form); status.textContent = '';
      } catch (error) { status.textContent = error.message; }
    },
  };
})();
