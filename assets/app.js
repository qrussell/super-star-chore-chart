/* Super Star Chore Chart – Frontend v2.0.0
 * Family-based, server-synced chore chart for WordPress
 * All data stored in the database; polls for changes every N seconds.
 */
(function () {
  'use strict';

  const cfg    = window.SSCC || {};
  const AJAX   = cfg.ajaxUrl || '/wp-admin/admin-ajax.php';
  const NONCE  = cfg.nonce   || '';
  const POLL   = cfg.pollInterval || 15000;
  const DAYS   = ['mon','tue','wed','thu','fri','sat','sun'];
  const DLBLS  = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];

  let state = { family: cfg.family || null, kids: [], weekOf: '', defaults: [], updatedAt: null,
                activeKidIdx: 0, editMode: false, saving: false, pollTimer: null };

  // ── Utilities ───────────────────────────────────────────────────────────────
  const el  = id => document.getElementById(id);
  const app = () => el('sscc-app');
  const esc = s  => String(s).replace(/[&<>"']/g, c =>
    ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  function post(action, data) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('nonce',  NONCE);
    for (const [k, v] of Object.entries(data || {})) fd.append(k, v);
    return fetch(AJAX, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(r => { if (!r.success) throw new Error(r.data?.message || 'Server error'); return r.data; });
  }

  function fmtWeek(w) {
    if (!w) return '';
    const d = new Date(w + 'T00:00:00');
    const e = new Date(d); e.setDate(d.getDate() + 6);
    return d.toLocaleDateString('en-US',{month:'short',day:'numeric'}) + ' – ' +
           e.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
  }

  function uid() { return 'id_' + Math.random().toString(36).slice(2,9); }

  function showMsg(text, isErr) {
    const m = el('sscc-msg');
    if (!m) return;
    m.textContent = text;
    m.className = 'sscc-msg ' + (isErr ? 'sscc-msg-err' : 'sscc-msg-ok');
    clearTimeout(m._t);
    m._t = setTimeout(() => { m.textContent = ''; m.className = 'sscc-msg'; }, 4000);
  }

  // ── Boot ────────────────────────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', () => {
    if (!app()) return;
    if (!cfg.loggedIn) return; // PHP already rendered the login gate
    if (!state.family) { renderFamilyGate(); return; }
    loadAndRender();
  });

  // ── Family Create / Join ────────────────────────────────────────────────────
  function renderFamilyGate() {
    app().innerHTML = `
    <div class="sscc-gate">
      <div class="sscc-gate-card">
        <div class="sscc-gate-icon">⭐</div>
        <h2>Welcome, ${esc(cfg.user?.name || 'there')}!</h2>
        <p>You're not in a family yet. Create a new one or join an existing family.</p>
        <div class="sscc-tabs">
          <button class="sscc-tab active" data-tab="create">Create Family</button>
          <button class="sscc-tab" data-tab="join">Join Family</button>
        </div>
        <div id="tab-create" class="sscc-tab-panel">
          <label>Family Name <input id="cf-name" type="text" placeholder="e.g. The Russells" maxlength="60"></label>
          <label>Family Password <input id="cf-pass" type="password" placeholder="Min 4 characters" minlength="4"></label>
          <label>Confirm Password <input id="cf-pass2" type="password" placeholder="Repeat password"></label>
          <button class="sscc-btn" id="btn-create">Create Family ⭐</button>
        </div>
        <div id="tab-join" class="sscc-tab-panel" hidden>
          <label>Family Name <input id="jf-name" type="text" placeholder="Exact family name"></label>
          <label>Family Password <input id="jf-pass" type="password" placeholder="Family password"></label>
          <button class="sscc-btn" id="btn-join">Join Family</button>
        </div>
        <div id="sscc-msg" class="sscc-msg"></div>
      </div>
    </div>`;

    app().querySelectorAll('.sscc-tab').forEach(tab => {
      tab.onclick = () => {
        app().querySelectorAll('.sscc-tab').forEach(t => t.classList.remove('active'));
        app().querySelectorAll('.sscc-tab-panel').forEach(p => p.hidden = true);
        tab.classList.add('active');
        el('tab-' + tab.dataset.tab).hidden = false;
      };
    });

    el('btn-create').onclick = () => {
      const name = el('cf-name').value.trim();
      const pass = el('cf-pass').value;
      const pass2 = el('cf-pass2').value;
      if (!name || pass.length < 4) return showMsg('Name required; password ≥ 4 chars.', true);
      if (pass !== pass2) return showMsg('Passwords do not match.', true);
      el('btn-create').disabled = true;
      post('sscc_create_family', { family_name: name, family_pass: pass })
        .then(d => { state.family = d.family; loadAndRender(); })
        .catch(e => { showMsg(e.message, true); el('btn-create').disabled = false; });
    };

    el('btn-join').onclick = () => {
      const name = el('jf-name').value.trim();
      const pass = el('jf-pass').value;
      if (!name || !pass) return showMsg('Enter family name and password.', true);
      el('btn-join').disabled = true;
      post('sscc_join_family', { family_name: name, family_pass: pass })
        .then(d => { state.family = d.family; loadAndRender(); })
        .catch(e => { showMsg(e.message, true); el('btn-join').disabled = false; });
    };
  }

  // ── Load Data & Render Chart ─────────────────────────────────────────────────
  function loadAndRender() {
    app().innerHTML = '<div class="sscc-loading"><span class="sscc-spinner">⭐</span> Loading chart…</div>';
    post('sscc_get_state', {})
      .then(d => {
        state.weekOf    = d.weekOf;
        state.kids      = d.kids || [];
        state.defaults  = d.defaults || [];
        state.updatedAt = d.updatedAt;
        state.family    = d.family || state.family;
        if (state.activeKidIdx >= state.kids.length) state.activeKidIdx = 0;
        renderChart();
        startPolling();
      })
      .catch(e => { app().innerHTML = `<div class="sscc-err">Error: ${esc(e.message)}</div>`; });
  }

  // ── Poll for changes ─────────────────────────────────────────────────────────
  function startPolling() {
    clearInterval(state.pollTimer);
    state.pollTimer = setInterval(() => {
      post('sscc_poll', { last_updated: state.updatedAt || '' })
        .then(d => { if (d.changed) { state.updatedAt = d.updatedAt; silentReload(); } })
        .catch(() => {});
    }, POLL);
  }

  function silentReload() {
    post('sscc_get_state', {})
      .then(d => {
        state.weekOf    = d.weekOf;
        state.kids      = d.kids || [];
        state.defaults  = d.defaults || [];
        state.updatedAt = d.updatedAt;
        if (state.activeKidIdx >= state.kids.length) state.activeKidIdx = 0;
        renderChart();
      }).catch(() => {});
  }

  // ── Save ─────────────────────────────────────────────────────────────────────
  let saveTimer = null;
  function scheduleSave() {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(saveChart, 800);
  }

  function saveChart() {
    post('sscc_save_chart', { kids: JSON.stringify(state.kids) })
      .then(d => { state.updatedAt = d.updatedAt; })
      .catch(e => showMsg('Save failed: ' + e.message, true));
  }

  // ── Main Chart Renderer ───────────────────────────────────────────────────────
  function renderChart() {
    const kid  = state.kids[state.activeKidIdx] || null;
    const fam  = state.family;
    app().innerHTML = `
    <div class="sscc-header">
      <div class="sscc-header-top">
        <h1>⭐ Super Star Chore Chart</h1>
        <div class="sscc-family-badge" title="Family: ${esc(fam?.name)}">${esc(fam?.name)}</div>
      </div>
      <div class="sscc-header-meta">
        Week of: <strong>${esc(fmtWeek(state.weekOf))}</strong>
        &nbsp;·&nbsp; Hi, ${esc(cfg.user?.name || '')}
        <a href="#" id="btn-leave" class="sscc-link-sm">Leave Family</a>
      </div>
    </div>

    <div class="sscc-toolbar">
      <button class="sscc-btn-sm" id="btn-archive">🗄 Archive & New Week</button>
      <button class="sscc-btn-sm" id="btn-defaults">⚙ Edit Defaults</button>
      <button class="sscc-btn-sm ${state.editMode?'active':''}" id="btn-edit">
        ${state.editMode ? '✅ Done Editing' : '✏ Edit Kid'}
      </button>
      <button class="sscc-btn-sm" id="btn-print">🖨 Print</button>
    </div>
    ${state.editMode ? '<div class="sscc-edit-banner">✏️ Edit Mode — changes save automatically</div>' : ''}

    <div class="sscc-kid-tabs">
      ${state.kids.map((k,i) => `
        <button class="sscc-kid-tab ${i===state.activeKidIdx?'active':''}" data-idx="${i}">
          ${esc(k.name)}
          ${state.kids.length > 1 && state.editMode ? `<span class="sscc-rm-kid" data-kidid="${esc(k.id)}">✕</span>` : ''}
        </button>`).join('')}
      ${state.editMode ? '<button class="sscc-kid-tab sscc-add-kid" id="btn-add-kid">+ Add Kid</button>' : ''}
    </div>

    <div id="sscc-msg" class="sscc-msg"></div>

    ${kid ? renderKidChart(kid) : '<div class="sscc-no-kids">No kids yet. Click Edit Kid → + Add Kid.</div>'}

    ${kid ? renderEarnings(kid) : ''}
    `;

    bindChartEvents();
  }

  function renderKidChart(kid) {
    return `
    <div class="sscc-chart-wrap">
    ${state.editMode && state.kids.length > 0 ? `
      <div class="sscc-edit-kid-name">
        Kid Name: <input class="sscc-kid-name-input" type="text" value="${esc(kid.name)}" maxlength="40">
      </div>` : ''}
    <table class="sscc-table">
      <thead>
        <tr>
          <th class="sscc-th-task">Task</th>
          ${DLBLS.map(d=>`<th>${d}</th>`).join('')}
          <th>Total</th>
        </tr>
      </thead>
      <tbody>
        ${(kid.categories||[]).map((cat,ci) => `
          <tr class="sscc-cat-row">
            <td colspan="9">
              ${state.editMode
                ? `<input class="sscc-cat-name-input" type="text" data-ci="${ci}" value="${esc(cat.name)}" maxlength="60">`
                : `<strong>${esc(cat.name)}</strong> ${cat.isPaidCat ? '<span class="sscc-paid-badge">💰 Paid</span>' : '<span class="sscc-unpaid-badge">Team Duty</span>'}`}
            </td>
          </tr>
          ${(cat.tasks||[]).map((task,ti) => renderTaskRow(task, ci, ti, cat.isPaidCat)).join('')}
          ${state.editMode ? `<tr class="sscc-add-task-row"><td colspan="9">
            <button class="sscc-add-task" data-ci="${ci}">+ Add Task</button>
          </td></tr>` : ''}
        `).join('')}
        ${state.editMode ? `<tr class="sscc-add-cat-row"><td colspan="9">
          <button id="btn-add-cat">+ Add Category</button>
        </td></tr>` : ''}
      </tbody>
    </table>
    </div>`;
  }

  function renderTaskRow(task, ci, ti, isCatPaid) {
    const paid   = task.isPaid || isCatPaid;
    const checks = task.checks || {};
    const done   = DAYS.filter(d => checks[d]).length;
    const earned = paid ? (task.unit === 'flat' ? (done > 0 ? task.amount : 0) : done * (task.amount||0)) : null;
    return `<tr class="sscc-task-row ${paid?'paid':'unpaid'}" data-ci="${ci}" data-ti="${ti}">
      <td class="sscc-task-name">
        ${state.editMode ? `
          <span class="sscc-rm-task" data-ci="${ci}" data-ti="${ti}">✕</span>
          <input class="sscc-task-input" type="text" data-ci="${ci}" data-ti="${ti}" value="${esc(task.name)}" maxlength="80">
          <label class="sscc-paid-toggle">
            <input type="checkbox" class="sscc-task-paid" data-ci="${ci}" data-ti="${ti}" ${task.isPaid?'checked':''}>
            Paid
          </label>
          ${task.isPaid ? `<input class="sscc-task-amount" type="number" step="0.01" min="0" data-ci="${ci}" data-ti="${ti}" value="${task.amount||0}" style="width:55px">
            <select class="sscc-task-unit" data-ci="${ci}" data-ti="${ti}">
              <option ${task.unit==='day'?'selected':''}>day</option>
              <option ${task.unit==='flat'?'selected':''}>flat</option>
            </select>` : ''}
        ` : `
          ${paid ? `<span class="sscc-paid-dot">$</span>` : ''}
          ${esc(task.name)}
          ${paid && task.unit === 'flat' ? ` <em class="sscc-flat">(flat $${Number(task.amount).toFixed(2)})</em>` : ''}
          ${paid && task.unit === 'day'  ? ` <em class="sscc-rate">$${Number(task.amount).toFixed(2)}/day</em>` : ''}
        `}
      </td>
      ${DAYS.map(d => `
        <td class="sscc-check-cell">
          <button class="sscc-check ${checks[d]?'checked':''}" data-ci="${ci}" data-ti="${ti}" data-day="${d}" ${state.editMode?'disabled':''}>
            ${checks[d]?'✓':''}
          </button>
        </td>`).join('')}
      <td class="sscc-total">${paid ? (earned > 0 ? '$' + earned.toFixed(2) : '—') : '—'}</td>
    </tr>`;
  }

  function renderEarnings(kid) {
    let total = 0;
    (kid.categories||[]).forEach(cat => {
      (cat.tasks||[]).forEach(t => {
        if (!t.isPaid && !cat.isPaidCat) return;
        const done = DAYS.filter(d => t.checks?.[d]).length;
        total += t.unit === 'flat' ? (done > 0 ? (t.amount||0) : 0) : done * (t.amount||0);
      });
    });
    return `<div class="sscc-earnings">
      <span>Weekly Earnings for <strong>${esc(kid.name)}</strong>:</span>
      <span class="sscc-earnings-total">$${total.toFixed(2)}</span>
    </div>`;
  }

  // ── Event Binding ─────────────────────────────────────────────────────────────
  function bindChartEvents() {
    // Kid tabs
    app().querySelectorAll('.sscc-kid-tab[data-idx]').forEach(btn => {
      btn.onclick = () => { state.activeKidIdx = parseInt(btn.dataset.idx); renderChart(); };
    });

    // Remove kid
    app().querySelectorAll('.sscc-rm-kid').forEach(btn => {
      btn.onclick = e => {
        e.stopPropagation();
        if (!confirm('Remove this kid and all their data?')) return;
        post('sscc_remove_kid', { kid_id: btn.dataset.kidid })
          .then(d => { state.kids = d.kids; state.activeKidIdx = 0; renderChart(); })
          .catch(e => showMsg(e.message, true));
      };
    });

    // Add kid
    const addKid = el('btn-add-kid');
    if (addKid) addKid.onclick = () => {
      const name = prompt('Kid name:', 'Kid ' + (state.kids.length + 1));
      if (!name) return;
      post('sscc_add_kid', { kid_name: name })
        .then(d => { state.kids = d.kids; state.activeKidIdx = state.kids.length - 1; renderChart(); })
        .catch(e => showMsg(e.message, true));
    };

    // Rename kid input
    const kidInput = app().querySelector('.sscc-kid-name-input');
    if (kidInput) kidInput.onchange = () => {
      const kid = state.kids[state.activeKidIdx];
      if (!kid || !kidInput.value.trim()) return;
      post('sscc_rename_kid', { kid_id: kid.id, kid_name: kidInput.value.trim() })
        .then(d => { state.kids = d.kids; renderChart(); })
        .catch(e => showMsg(e.message, true));
    };

    // Category name edits
    app().querySelectorAll('.sscc-cat-name-input').forEach(inp => {
      inp.onchange = () => {
        const ci = parseInt(inp.dataset.ci);
        state.kids[state.activeKidIdx].categories[ci].name = inp.value;
        scheduleSave();
      };
    });

    // Task name edits
    app().querySelectorAll('.sscc-task-input').forEach(inp => {
      inp.onchange = () => {
        const ci = parseInt(inp.dataset.ci), ti = parseInt(inp.dataset.ti);
        state.kids[state.activeKidIdx].categories[ci].tasks[ti].name = inp.value;
        scheduleSave();
      };
    });

    // Task paid toggle
    app().querySelectorAll('.sscc-task-paid').forEach(cb => {
      cb.onchange = () => {
        const ci = parseInt(cb.dataset.ci), ti = parseInt(cb.dataset.ti);
        state.kids[state.activeKidIdx].categories[ci].tasks[ti].isPaid = cb.checked;
        scheduleSave();
        renderChart(); // re-render to show/hide amount inputs
      };
    });

    // Task amount
    app().querySelectorAll('.sscc-task-amount').forEach(inp => {
      inp.onchange = () => {
        const ci = parseInt(inp.dataset.ci), ti = parseInt(inp.dataset.ti);
        state.kids[state.activeKidIdx].categories[ci].tasks[ti].amount = parseFloat(inp.value)||0;
        scheduleSave();
      };
    });

    // Task unit
    app().querySelectorAll('.sscc-task-unit').forEach(sel => {
      sel.onchange = () => {
        const ci = parseInt(sel.dataset.ci), ti = parseInt(sel.dataset.ti);
        state.kids[state.activeKidIdx].categories[ci].tasks[ti].unit = sel.value;
        scheduleSave();
      };
    });

    // Remove task
    app().querySelectorAll('.sscc-rm-task').forEach(btn => {
      btn.onclick = () => {
        const ci = parseInt(btn.dataset.ci), ti = parseInt(btn.dataset.ti);
        if (!confirm('Remove this task?')) return;
        state.kids[state.activeKidIdx].categories[ci].tasks.splice(ti, 1);
        scheduleSave(); renderChart();
      };
    });

    // Add task
    app().querySelectorAll('.sscc-add-task').forEach(btn => {
      btn.onclick = () => {
        const ci = parseInt(btn.dataset.ci);
        const cat = state.kids[state.activeKidIdx].categories[ci];
        cat.tasks.push({ id: uid(), name: 'New Task', isPaid: cat.isPaidCat, amount: 0, unit: 'day',
          checks: Object.fromEntries(DAYS.map(d=>[d,false])) });
        scheduleSave(); renderChart();
      };
    });

    // Add category
    const addCat = el('btn-add-cat');
    if (addCat) addCat.onclick = () => {
      state.kids[state.activeKidIdx].categories.push({
        id: uid(), name: 'New Category', isPaidCat: false, tasks: [] });
      scheduleSave(); renderChart();
    };

    // Checkboxes
    app().querySelectorAll('.sscc-check').forEach(btn => {
      btn.onclick = () => {
        const ci = parseInt(btn.dataset.ci), ti = parseInt(btn.dataset.ti), day = btn.dataset.day;
        const task = state.kids[state.activeKidIdx].categories[ci].tasks[ti];
        task.checks = task.checks || {};
        task.checks[day] = !task.checks[day];
        btn.classList.toggle('checked', task.checks[day]);
        btn.textContent = task.checks[day] ? '✓' : '';
        scheduleSave();
        const earn = app().querySelector('.sscc-earnings-total');
        if (earn) {
          let t = 0;
          (state.kids[state.activeKidIdx].categories||[]).forEach(cat => {
            (cat.tasks||[]).forEach(tk => {
              if (!tk.isPaid && !cat.isPaidCat) return;
              const done = DAYS.filter(d => tk.checks?.[d]).length;
              t += tk.unit==='flat' ? (done>0?tk.amount:0) : done*(tk.amount||0);
            });
          });
          earn.textContent = '$' + t.toFixed(2);
        }
        // Refresh total cell
        const totalCell = btn.closest('tr').querySelector('.sscc-total');
        if (totalCell) {
          const task2 = state.kids[state.activeKidIdx].categories[ci].tasks[ti];
          const paid  = task2.isPaid || state.kids[state.activeKidIdx].categories[ci].isPaidCat;
          const done  = DAYS.filter(d => task2.checks?.[d]).length;
          const earned = paid ? (task2.unit==='flat' ? (done>0?task2.amount:0) : done*(task2.amount||0)) : null;
          totalCell.textContent = paid ? (earned > 0 ? '$' + earned.toFixed(2) : '—') : '—';
        }
      };
    });

    // Toolbar buttons
    const btnEdit = el('btn-edit');
    if (btnEdit) btnEdit.onclick = () => { state.editMode = !state.editMode; renderChart(); };

    const btnPrint = el('btn-print');
    if (btnPrint) btnPrint.onclick = () => window.print();

    const btnArchive = el('btn-archive');
    if (btnArchive) btnArchive.onclick = handleArchive;

    const btnDefaults = el('btn-defaults');
    if (btnDefaults) btnDefaults.onclick = handleEditDefaults;

    const btnLeave = el('btn-leave');
    if (btnLeave) btnLeave.onclick = e => {
      e.preventDefault();
      if (!confirm('Leave the ' + (state.family?.name||'') + ' family? You will need the password to rejoin.')) return;
      post('sscc_leave_family', {})
        .then(() => { state.family = null; clearInterval(state.pollTimer); renderFamilyGate(); })
        .catch(e => showMsg(e.message, true));
    };
  }

  // ── Archive & New Week ────────────────────────────────────────────────────────
  function handleArchive() {
    const choice = confirm(
      'Archive this week and start a new one?\n\n' +
      'OK = Archive & reset to defaults\n' +
      'Cancel = stay on current week'
    );
    if (!choice) return;
    const useDefaults = confirm('Apply default task list to all kids for the new week?\n(Cancel = keep current tasks, just clear checkboxes)');
    post('sscc_archive_week', { use_defaults: useDefaults ? '1' : '0' })
      .then(d => {
        state.weekOf = d.newWeekOf;
        state.kids   = d.kids;
        state.activeKidIdx = 0;
        showMsg('Week archived! New week starting ' + fmtWeek(d.newWeekOf));
        renderChart();
      })
      .catch(e => showMsg(e.message, true));
  }

  // ── Edit Defaults Modal ───────────────────────────────────────────────────────
  function handleEditDefaults() {
    const overlay = document.createElement('div');
    overlay.className = 'sscc-modal-overlay';
    overlay.innerHTML = `
    <div class="sscc-modal">
      <div class="sscc-modal-hdr">
        <h2>⚙ Edit Default Task Template</h2>
        <button class="sscc-modal-close" id="def-close">✕</button>
      </div>
      <p class="sscc-modal-sub">Changes here apply when resetting to defaults.</p>
      <div id="def-cats">
        ${state.defaults.map((cat,ci)=>`
          <div class="sscc-def-cat" data-ci="${ci}">
            <div class="sscc-def-cat-hdr">
              <input class="sscc-def-catname" type="text" data-ci="${ci}" value="${esc(cat.name)}" maxlength="60">
              <label><input type="checkbox" class="sscc-def-catpaid" data-ci="${ci}" ${cat.isPaidCat?'checked':''}> Paid Category</label>
              <button class="sscc-rm-defcat" data-ci="${ci}">✕ Remove</button>
            </div>
            ${(cat.tasks||[]).map((t,ti)=>`
              <div class="sscc-def-task" data-ci="${ci}" data-ti="${ti}">
                <button class="sscc-rm-deftask" data-ci="${ci}" data-ti="${ti}">✕</button>
                <input class="sscc-def-taskname" type="text" data-ci="${ci}" data-ti="${ti}" value="${esc(t.name)}" maxlength="80">
                <label><input type="checkbox" class="sscc-def-taskpaid" data-ci="${ci}" data-ti="${ti}" ${t.isPaid?'checked':''}> $</label>
                <input class="sscc-def-amount" type="number" step="0.01" min="0" data-ci="${ci}" data-ti="${ti}" value="${t.amount||0}" style="width:55px">
                <select class="sscc-def-unit" data-ci="${ci}" data-ti="${ti}">
                  <option ${t.unit==='day'?'selected':''}>day</option>
                  <option ${t.unit==='flat'?'selected':''}>flat</option>
                </select>
              </div>`).join('')}
            <button class="sscc-add-deftask" data-ci="${ci}">+ Add Task</button>
          </div>`).join('')}
      </div>
      <button id="btn-add-defcat">+ Add Category</button>
      <div class="sscc-modal-footer">
        <button class="sscc-btn" id="def-save">💾 Save Defaults</button>
        <button class="sscc-btn sscc-btn-outline" id="def-cancel">Cancel</button>
      </div>
      <div id="sscc-msg" class="sscc-msg"></div>
    </div>`;
    document.body.appendChild(overlay);

    const defs = JSON.parse(JSON.stringify(state.defaults)); // deep clone

    const collect = () => {
      overlay.querySelectorAll('.sscc-def-catname').forEach(inp => { defs[inp.dataset.ci].name = inp.value; });
      overlay.querySelectorAll('.sscc-def-catpaid').forEach(cb  => { defs[cb.dataset.ci].isPaidCat = cb.checked; });
      overlay.querySelectorAll('.sscc-def-taskname').forEach(inp => { const t=defs[inp.dataset.ci].tasks[inp.dataset.ti]; if(t)t.name=inp.value; });
      overlay.querySelectorAll('.sscc-def-taskpaid').forEach(cb  => { const t=defs[cb.dataset.ci].tasks[cb.dataset.ti]; if(t)t.isPaid=cb.checked; });
      overlay.querySelectorAll('.sscc-def-amount').forEach(inp   => { const t=defs[inp.dataset.ci].tasks[inp.dataset.ti]; if(t)t.amount=parseFloat(inp.value)||0; });
      overlay.querySelectorAll('.sscc-def-unit').forEach(sel     => { const t=defs[sel.dataset.ci].tasks[sel.dataset.ti]; if(t)t.unit=sel.value; });
    };

    el('def-save').onclick = () => {
      collect();
      post('sscc_save_defaults', { defaults: JSON.stringify(defs) })
        .then(() => { state.defaults = defs; showMsg('Defaults saved!'); overlay.remove(); })
        .catch(e => showMsg(e.message, true));
    };
    el('def-cancel').onclick = () => overlay.remove();
    el('def-close').onclick  = () => overlay.remove();
  }

})();
