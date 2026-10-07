(function () {
    'use strict';
    const csrf = document.body.dataset.csrf;

    async function post(action, formData) {
        const res = await fetch('api.php?action=' + encodeURIComponent(action), {
            method: 'POST', body: formData, headers: { 'X-CSRF-Token': csrf }, credentials: 'same-origin'
        });
        let json;
        try { json = await res.json(); } catch (e) { json = { ok: false, error: 'Ongeldig antwoord van de server.' }; }
        return json;
    }

    document.querySelectorAll('form[data-action]').forEach(function (form) {
        form.addEventListener('submit', async function (ev) {
            ev.preventDefault();
            if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) { return; }
            const data = new FormData(form);
            // velden die via form="…" aan dit formulier hangen zitten al in FormData
            const json = await post(form.dataset.action, data);
            if (!json.ok) { window.alert(json.error || 'Opslaan mislukt.'); return; }
            if (form.dataset.redirect) { window.location.href = form.dataset.redirect; return; }
            if (form.dataset.action === 'save_group' && json.id) { window.location.href = 'index.php?groep=' + encodeURIComponent(json.id); return; }
            window.location.reload();
        });
    });

    const html = document.getElementById('html');
    const frame = document.getElementById('preview');
    const groupForm = document.getElementById('group-form');
    if (html && frame && groupForm) {
        let timer = null;
        const refresh = async function () {
            const data = new FormData(groupForm);
            data.set('sample', document.getElementById('sample').value);
            const json = await post('preview', data);
            if (!json.ok) { return; }
            frame.srcdoc = '<!DOCTYPE html><html><body style="margin:12px;background:#fff">' + json.html + '</body></html>';
            const problems = document.getElementById('preview-problems');
            problems.innerHTML = '';
            (json.problems || []).forEach(function (p) {
                const el = document.createElement('p'); el.className = 'notice warn'; el.textContent = p; problems.appendChild(el);
            });
            const len = document.getElementById('preview-length');
            len.textContent = 'Lengte: ' + json.length + ' van max. ' + json.max_length + ' tekens (Exchange-limiet voor de disclaimer).';
            len.className = json.length > json.max_length ? 'notice warn' : 'muted';
        };
        const schedule = function () { clearTimeout(timer); timer = setTimeout(refresh, 300); };
        groupForm.addEventListener('input', schedule);
        groupForm.addEventListener('change', schedule);
        document.getElementById('sample').addEventListener('change', refresh);
        document.querySelectorAll('button.insert').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const text = btn.dataset.insert.replace('…', '');
                const start = html.selectionStart, end = html.selectionEnd;
                html.setRangeText(text, start, end, 'end');
                html.focus();
                schedule();
            });
        });
        refresh();
    }

    const dry = document.getElementById('dry-run');
    if (dry) {
        dry.addEventListener('click', async function () {
            dry.disabled = true;
            const out = document.getElementById('dry-run-result');
            out.textContent = 'Bezig…';
            const json = await post('dry_run', new FormData());
            dry.disabled = false;
            if (!json.ok) { out.textContent = json.error || 'Mislukt.'; return; }
            const r = json.result;
            const ul = document.createElement('ul');
            (r.problems || []).concat(r.errors || []).forEach(function (p) {
                const li = document.createElement('li'); li.className = 'warn'; li.textContent = p; ul.appendChild(li);
            });
            (r.actions || []).forEach(function (a) {
                const li = document.createElement('li');
                li.textContent = a.action + ': ' + a.target + (a.detail ? ' (' + a.detail + ')' : '');
                ul.appendChild(li);
            });
            out.innerHTML = '';
            const p = document.createElement('p');
            p.textContent = 'Proefrun ' + json.at_text + ' (' + r.mode + '): ' + (r.actions || []).length + ' wijzigingen.';
            out.appendChild(p); out.appendChild(ul);
        });
    }
})();
