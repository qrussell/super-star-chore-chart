/* Super Star Chore Chart – Frontend v2.5.0
 * Features: PWA, Light/Dark Mode, Magic Links, Editable Archives, Template Engine
 */

// ── PWA Installation Handling ───────────────────────────────────────────────
let deferredPrompt;
if ('serviceWorker' in navigator) {
    const swPath = (window.SSCC?.pluginUrl || '/') + 'assets/sw.js';
    navigator.serviceWorker.register(swPath).catch(() => {});
}

const isIos = () => /iphone|ipad|ipod/.test(window.navigator.userAgent.toLowerCase());
const isStandalone = () => ('standalone' in window.navigator) && (window.navigator.standalone);

window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    const btn = document.getElementById('btn-install-pwa');
    if (btn) btn.style.display = 'inline-block';
});

// ── Theme Handling ──────────────────────────────────────────────────────────
window.toggleTheme = () => {
    const current = document.documentElement.getAttribute('data-theme');
    const newTheme = current === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', newTheme);
    localStorage.setItem('sscc-theme', newTheme);
};

const initTheme = () => {
    const saved = localStorage.getItem('sscc-theme');
    const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    if (saved) document.documentElement.setAttribute('data-theme', saved);
    else if (systemDark) document.documentElement.setAttribute('data-theme', 'dark');
};
initTheme();

// ── Main Application ────────────────────────────────────────────────────────
(function () {
  'use strict';

  const cfg    = window.SSCC || {};
  const AJAX   = cfg.ajaxUrl || '/wp-admin/admin-ajax.php';
  const NONCE  = cfg.nonce   || '';
  const POLL   = cfg.pollInterval || 15000;
  const DAYS   = ['mon','tue','wed','thu','fri','sat','sun'];
  const DLBLS  = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];

  let state = { family: cfg.family || null, kids: [], weekOf: '', defaults: [], updatedAt: null,
                activeKidIdx: 0, editMode: false, saving: false, pollTimer: null, 
                archives: [], currentArchiveIndex: -1 };

  const el  = id => document.getElementById(id);
  const app = () => el('sscc-app');
  const esc = s  => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

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

  // ── Boot Sequence ─────────────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', () => {
    const container = app();
    if (!container) return;
    
    if (cfg.loggedIn) {
        if (state.family || cfg.family) {
            state.family = cfg.family;
            loadAndRender();
        } else {
            renderFamilyGate();
        }
    }
  });

  // ── Family Create / Join ──────────────────────────────────────────────────
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
      const name = el('cf-name').value.trim(), pass = el('cf-pass').value, pass2 = el('cf-pass2').value;
      if (!name || pass.length < 4) return showMsg('Name required; password ≥ 4 chars.', true);
      if (pass !== pass2) return showMsg('Passwords do not match.', true);
      el('btn-create').disabled = true;
      post('sscc_create_family', { family_name: name, family_pass: pass }).then(d => { state.family = d.family; loadAndRender(); }).catch(e => { showMsg(e.message, true); el('btn-create').disabled = false; });
    };

    el('btn-join').onclick = () => {
      const name = el('jf-name').value.trim(), pass = el('jf-pass').value;
      if (!name || !pass) return showMsg('Enter family name and password.', true);
      el('btn-join').disabled = true;
      post('sscc_join_family', { family_name: name, family_pass: pass }).then(d => { state.family = d.family; loadAndRender(); }).catch(e => { showMsg(e.message, true); el('btn-join').disabled = false; });
    };
  }

  // ── Load Data & Render Chart ──────────────────────────────────────────────
  function loadAndRender() {
    app().innerHTML = '<div class="sscc-loading"><span class="sscc-spinner">⭐</span> Loading chart…</div>';
    
    post('sscc_get_state', {})
      .then(d => {
        state.weekOf    = d.weekOf;
        state.kids      = d.kids || [];
        state.defaults  = d.defaults || [];
        state.updatedAt = d.updatedAt;
        state.family    = d.family || state.family;
        state.currentArchiveIndex = -1; // Force Live Mode
        if (state.activeKidIdx >= state.kids.length) state.activeKidIdx = 0;

        post('sscc_get_archives', {}).then(a => { state.archives = a.archives || []; renderChart(); }).catch(() => renderChart());
        startPolling();
      }).catch(e => { app().innerHTML = `<div class="sscc-err">Error: ${esc(e.message)}</div>`; });
  }

  function startPolling() {
    clearInterval(state.pollTimer);
    state.pollTimer = setInterval(() => {
      if (state.currentArchiveIndex !== -1) return; // Prevent live-sync overwriting an open archive
      post('sscc_poll', { last_updated: state.updatedAt || '' }).then(d => { if (d.changed) { state.updatedAt = d.updatedAt; silentReload(); } }).catch(() => {});
    }, POLL);
  }

  function silentReload() {
    if (state.currentArchiveIndex !== -1) return;
    post('sscc_get_state', {}).then(d => {
        state.weekOf = d.weekOf; state.kids = d.kids || []; state.defaults = d.defaults || []; state.updatedAt = d.updatedAt;
        if (state.activeKidIdx >= state.kids.length) state.activeKidIdx = 0;
        renderChart();
      }).catch(() => {});
  }

  // ── Smart Save (Context Aware) ────────────────────────────────────────────
  let saveTimer = null;
  function scheduleSave() {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(saveChart, 800);
  }

  function saveChart() {
    if (state.currentArchiveIndex !== -1) {
        // Save edits directly to the historical archive
        const archiveId = state.archives[state.currentArchiveIndex].id;
        post('sscc_update_archive', { archive_id: archiveId, kids: JSON.stringify(state.kids) }).catch(e => showMsg('Archive save failed: ' + e.message, true));
    } else {
        // Save to live chart
        post('sscc_save_chart', { kids: JSON.stringify(state.kids) }).then(d => { state.updatedAt = d.updatedAt; }).catch(e => showMsg('Save failed: ' + e.message, true));
    }
  }

  function loadArchive(index) {
      if (index < 0 || index >= state.archives.length) return;
      const archive = state.archives[index];
      state.currentArchiveIndex = index;
      state.kids = archive.kids;
      state.weekOf = archive.week_of;
      state.editMode = false; // Turn off edit mode to prevent accidental overrides initially
      renderChart();
  }

  // ── Main Chart Renderer ───────────────────────────────────────────────────
  function renderChart() {
    const kid  = state.kids[state.activeKidIdx] || null;
    const fam  = state.family;
    const isLive = state.currentArchiveIndex === -1;
    
    app().innerHTML = `
    <div class="sscc-header">
      <div class="sscc-header-top">
        <h1>⭐ Super Star Chore Chart</h1>
        <button onclick="toggleTheme()" class="sscc-btn-sm" style="margin-left:10px; cursor:pointer;" title="Toggle Light/Dark Mode">☀️/🌙</button>
        <div class="sscc-family-badge" title="Family: ${esc(fam?.name)}">${esc(fam?.name)}</div>
      </div>
      <div class="sscc-header-meta">
        Week of: <strong>${esc(fmtWeek(state.weekOf))}</strong>
        &nbsp;·&nbsp; Hi, ${esc(cfg.user?.name || '')}
        <a href="#" id="btn-leave" class="sscc-link-sm">Leave Family</a>
      </div>
    </div>

    <div class="sscc-toolbar">
      <button class="sscc-btn-sm" id="btn-install-pwa" style="display:${(deferredPrompt || (isIos() && !isStandalone())) ? 'inline-block' : 'none'}; background:#d97706; color:#fff; border-color:#b45309;">📱 Install App</button>
      <button class="sscc-btn-sm" id="btn-magic-link">🔗 Invite Link</button>
      
      ${isLive ? `
          <button class="sscc-btn-sm" id="btn-archive">🗄 Archive & New Week</button>
          <button class="sscc-btn-sm" id="btn-defaults">⚙ Edit Settings</button>
      ` : ''}
      <button class="sscc-btn-sm ${state.editMode?'active':''}" id="btn-edit">
        ${state.editMode ? '✅ Done Editing' : '✏ Edit Kid'}
      </button>
      <button class="sscc-btn-sm" id="btn-print">🖨 Print</button>
    </div>
    
    ${state.editMode ? '<div class="sscc-edit-banner">✏️ Edit Mode — changes save automatically</div>' : ''}

    ${!isLive ? `
      <div class="sscc-nav-bar" style="background:var(--bg-card, #f5f5f5); padding:10px; display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-radius:6px; border:1px solid #ccc;">
        <button id="btn-prev-week" class="sscc-btn-sm" ${state.currentArchiveIndex === state.archives.length - 1 ? 'disabled' : ''}>◀ Older Week</button>
        <span style="font-weight:bold; color:#e11d48;">Editing Past Week</span>
        <div>
          <button id="btn-make-current" class="sscc-btn-sm" style="background:#f59e0b; color:#fff; border-color:#d97706;">Restore as Live Week</button>
          <button id="btn-next-week" class="sscc-btn-sm">Newer Week ▶</button>
          <button id="btn-back-live" class="sscc-btn-sm" style="margin-left:10px; background:#10b981; color:#fff; border-color:#059669;">Back to Live</button>
        </div>
      </div>
    ` : (state.archives.length > 0 ? `
      <div class="sscc-nav-bar" style="margin-bottom:15px;">
        <button id="btn-view-archives" class="sscc-btn-sm sscc-btn-outline" style="width:100%; text-align:center;">🕒 View Past Weeks</button>
      </div>
    ` : '')}

    <div class="sscc-kid-tabs">
      ${state.kids.map((k,i) => `
        <button class="sscc-kid-tab ${i===state.activeKidIdx?'active':''}" data-idx="${i}">
          ${esc(k.name)}
          ${state.kids.length > 1 && state.editMode && isLive ? `<span class="sscc-rm-kid" data-kidid="${esc(k.id)}">✕</span>` : ''}
        </button>`).join('')}
      ${state.editMode && isLive ? '<button class="sscc-kid-tab sscc-add-kid" id="btn-add-kid">+ Add Kid</button>' : ''}
    </div>

    <div id="sscc-msg" class="sscc-msg"></div>

    ${kid ? renderKidChart(kid) : '<div class="sscc-no-kids">No kids yet. Click Edit Kid → + Add Kid.</div>'}
    ${kid ? renderEarnings(kid) : ''}
    `;

    bindChartEvents();
  }

  function renderKidChart(kid) {
    const isLive = state.currentArchiveIndex === -1;
    return `
    <div class="sscc-print-header sscc-print-only">
      <h2 style="text-align:center; font-size: 24px; margin: 0 0 15px 0;">Super Star Chore Chart</h2>
      <div style="display:flex; justify-content: space-between; font-size: 16px; margin-bottom: 10px;">
        <div><strong>Name:</strong> ${esc(kid.name)}</div>
        <div><strong>Week of:</strong> ${esc(fmtWeek(state.weekOf))}</div>
      </div>
      <div style="font-size: 14px; margin-bottom: 15px; border-bottom: 2px solid #000; padding-bottom: 5px;">
        Team Duty (unpaid) | <strong>$</strong> Paid Gig
      </div>
    </div>
    
    <div class="sscc-chart-wrap">
    ${state.editMode && state.kids.length > 0 && isLive ? `
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
              <strong>${ci + 1}. ${esc(cat.name).toUpperCase()}</strong> ${cat.isPaidCat ? '<span class="sscc-paid-badge sscc-no-print">💰 Paid</span>' : '<span class="sscc-unpaid-badge sscc-no-print">Team Duty</span>'}
            </td>
          </tr>
          ${(cat.tasks||[]).map((task,ti) => renderTaskRow(task, ci, ti, cat.isPaidCat)).join('')}
          ${state.editMode ? `<tr class="sscc-add-task-row"><td colspan="9">
            <button class="sscc-add-task sscc-btn-outline sscc-btn-sm" data-ci="${ci}">+ Add Personal Task</button>
          </td></tr>` : ''}
        `).join('')}
      </tbody>
    </table>
    </div>`;
  }

  function renderTaskRow(task, ci, ti, isCatPaid) {
    const paid   = task.isPaid || isCatPaid;
    const checks = task.checks || {};
    const done   = DAYS.filter(d => checks[d]).length;
    const earned = paid ? (task.unit === 'flat' ? (done > 0 ? task.amount : 0) : done * (task.amount||0)) : null;
    const isShared = task.scope !== 'personal'; // Default to shared if undefined
    
    let taskNameCell = '';
    if (state.editMode) {
        const tasksLen = state.kids[state.activeKidIdx].categories[ci].tasks.length;
        // Move controls for ALL tasks in edit mode
        const moveControls = `
          <span class="sscc-move-task-up" data-ci="${ci}" data-ti="${ti}" style="cursor:${ti === 0 ? 'default' : 'pointer'}; opacity:${ti === 0 ? '0.3' : '1'}; margin-right:2px;" title="Move Up">⬆️</span>
          <span class="sscc-move-task-down" data-ci="${ci}" data-ti="${ti}" style="cursor:${ti === tasksLen - 1 ? 'default' : 'pointer'}; opacity:${ti === tasksLen - 1 ? '0.3' : '1'}; margin-right:8px;" title="Move Down">⬇️</span>
        `;

        if (isShared) {
            // Locked Shared Task (Visually distinct in Edit Mode)
            taskNameCell = `
              <span title="Shared Task (Edit in Settings)">🔒</span>
              ${moveControls}
              ${paid ? `<span class="sscc-paid-dot">$</span>` : ''}
              <span style="opacity:0.7;">${esc(task.name)}</span>
              ${paid && task.unit === 'flat' ? ` <em class="sscc-flat" style="opacity:0.7;">- $${Number(task.amount).toFixed(2)}/flat</em>` : ''}
              ${paid && task.unit === 'day'  ? ` <em class="sscc-rate" style="opacity:0.7;">$${Number(task.amount).toFixed(2)}/day</em>` : ''}
            `;
        } else {
            // Fully Editable Personal Task
            taskNameCell = `
              <span class="sscc-rm-task" data-ci="${ci}" data-ti="${ti}" style="cursor:pointer; color:#e11d48; margin-right:4px;" title="Remove Task">✕</span>
              ${moveControls}
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
            `;
        }
    } else {
        // Standard View Mode
        taskNameCell = `
          ${paid ? `<span class="sscc-paid-dot">$</span>` : ''}
          ${esc(task.name)}
          ${paid && task.unit === 'flat' ? ` <em class="sscc-flat">- $${Number(task.amount).toFixed(2)}/flat</em>` : ''}
          ${paid && task.unit === 'day'  ? ` <em class="sscc-rate">$${Number(task.amount).toFixed(2)}/day</em>` : ''}
        `;
    }

    return `<tr class="sscc-task-row ${paid?'paid':'unpaid'} ${isShared && state.editMode ? 'sscc-shared-task' : ''}" data-ci="${ci}" data-ti="${ti}">
      <td class="sscc-task-name">${taskNameCell}</td>
      ${DAYS.map(d => `
        <td class="sscc-check-cell">
          <button class="sscc-check ${checks[d]?'checked':''}" data-ci="${ci}" data-ti="${ti}" data-day="${d}" ${state.editMode?'disabled':''}>
            ${checks[d]?'✓':''}
          </button>
        </td>`).join('')}
      <td class="sscc-total">${paid ? (earned > 0 ? '$' + earned.toFixed(2) : '$0.00') : '—'}</td>
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
      <div style="font-size:16px; font-weight:bold; margin-bottom:8px;" class="sscc-print-only">WEEKLY EARNINGS SUMMARY</div>
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span>Weekly Earnings for <strong>${esc(kid.name)}</strong>:</span>
        <span class="sscc-earnings-total">$${total.toFixed(2)}</span>
      </div>
    </div>`;
  }

  // ── Event Binding ─────────────────────────────────────────────────────────
  function bindChartEvents() {
    
    // Archive Navigation
    const btnViewArch = el('btn-view-archives');
    if (btnViewArch) btnViewArch.onclick = () => loadArchive(0);

    const btnPrev = el('btn-prev-week');
    if (btnPrev) btnPrev.onclick = () => loadArchive(state.currentArchiveIndex + 1);

    const btnNext = el('btn-next-week');
    if (btnNext) btnNext.onclick = () => {
        if (state.currentArchiveIndex === 0) {
            state.currentArchiveIndex = -1;
            loadAndRender(); // Back to live
        } else {
            loadArchive(state.currentArchiveIndex - 1);
        }
    };

    const btnBack = el('btn-back-live');
    if (btnBack) btnBack.onclick = () => {
        state.currentArchiveIndex = -1;
        loadAndRender();
    };

    const btnMakeCurrent = el('btn-make-current');
    if (btnMakeCurrent) {
        btnMakeCurrent.onclick = () => {
            if (!confirm('Make this archived week the active live chart? This will overwrite your current live week.')) return;
            const archiveId = state.archives[state.currentArchiveIndex].id;
            post('sscc_make_current', { archive_id: archiveId })
                .then(() => { state.currentArchiveIndex = -1; loadAndRender(); showMsg('Week restored successfully!'); })
                .catch(e => showMsg(e.message, true));
        };
    }

    const btnInstall = el('btn-install-pwa');
    if (btnInstall) {
        if (isIos() && !isStandalone()) {
            btnInstall.style.display = 'inline-block';
            btnInstall.onclick = () => alert('To install this app on your iPhone:\n\n1. Tap the Share button at the bottom of Safari.\n2. Scroll down and tap "Add to Home Screen".');
        } else {
            btnInstall.onclick = async () => {
                if (deferredPrompt) {
                    deferredPrompt.prompt();
                    const { outcome } = await deferredPrompt.userChoice;
                    if (outcome === 'accepted') btnInstall.style.display = 'none';
                    deferredPrompt = null;
                }
            };
        }
    }

    const btnMagic = el('btn-magic-link');
    if (btnMagic) {
        btnMagic.onclick = () => {
            post('sscc_get_magic_link', {}).then(d => { navigator.clipboard.writeText(d.url); showMsg('Invite Link copied to clipboard!'); }).catch(e => showMsg(e.message, true));
        };
    }

    app().querySelectorAll('.sscc-kid-tab[data-idx]').forEach(btn => {
      btn.onclick = () => { state.activeKidIdx = parseInt(btn.dataset.idx); renderChart(); };
    });

    app().querySelectorAll('.sscc-rm-kid').forEach(btn => {
      btn.onclick = e => {
        e.stopPropagation();
        if (!confirm('Remove this kid and all their data?')) return;
        post('sscc_remove_kid', { kid_id: btn.dataset.kidid }).then(d => { state.kids = d.kids; state.activeKidIdx = 0; renderChart(); }).catch(e => showMsg(e.message, true));
      };
    });

    const addKid = el('btn-add-kid');
    if (addKid) addKid.onclick = () => {
      const name = prompt('Kid name:', 'Kid ' + (state.kids.length + 1));
      if (!name) return;
      post('sscc_add_kid', { kid_name: name }).then(d => { state.kids = d.kids; state.activeKidIdx = state.kids.length - 1; renderChart(); }).catch(e => showMsg(e.message, true));
    };

    const kidInput = app().querySelector('.sscc-kid-name-input');
    if (kidInput) kidInput.onchange = () => {
      const kid = state.kids[state.activeKidIdx];
      if (!kid || !kidInput.value.trim()) return;
      post('sscc_rename_kid', { kid_id: kid.id, kid_name: kidInput.value.trim() }).then(d => { state.kids = d.kids; renderChart(); }).catch(e => showMsg(e.message, true));
    };

    // Task Editing (Personal Only)
    app().querySelectorAll('.sscc-task-input').forEach(inp => {
      inp.onchange = () => {
        const ci = parseInt(inp.dataset.ci), ti = parseInt(inp.dataset.ti);
        state.kids[state.activeKidIdx].categories[ci].tasks[ti].name = inp.value;
        scheduleSave();
      };
    });

    app().querySelectorAll('.sscc-task-paid').forEach(cb => {
      cb.onchange = () => {
        const ci = parseInt(cb.dataset.ci), ti = parseInt(cb.dataset.ti);
        state.kids[state.activeKidIdx].categories[ci].tasks[ti].isPaid = cb.checked;
        scheduleSave(); renderChart(); 
      };
    });

    app().querySelectorAll('.sscc-task-amount').forEach(inp => {
      inp.onchange = () => {
        const ci = parseInt(inp.dataset.ci), ti = parseInt(inp.dataset.ti);
        state.kids[state.activeKidIdx].categories[ci].tasks[ti].amount = parseFloat(inp.value)||0;
        scheduleSave();
      };
    });

    app().querySelectorAll('.sscc-task-unit').forEach(sel => {
      sel.onchange = () => {
        const ci = parseInt(sel.dataset.ci), ti = parseInt(sel.dataset.ti);
        state.kids[state.activeKidIdx].categories[ci].tasks[ti].unit = sel.value;
        scheduleSave();
      };
    });

    app().querySelectorAll('.sscc-rm-task').forEach(btn => {
      btn.onclick = () => {
        const ci = parseInt(btn.dataset.ci), ti = parseInt(btn.dataset.ti);
        if (!confirm('Remove this personal task?')) return;
        state.kids[state.activeKidIdx].categories[ci].tasks.splice(ti, 1);
        scheduleSave(); renderChart();
      };
    });
	
	// Move Tasks Up/Down
    app().querySelectorAll('.sscc-move-task-up').forEach(btn => {
      btn.onclick = () => {
        const ci = parseInt(btn.dataset.ci), ti = parseInt(btn.dataset.ti);
        if (ti > 0) {
          const tasks = state.kids[state.activeKidIdx].categories[ci].tasks;
          [tasks[ti - 1], tasks[ti]] = [tasks[ti], tasks[ti - 1]]; // Swap array items
          scheduleSave(); renderChart();
        }
      };
    });

    app().querySelectorAll('.sscc-move-task-down').forEach(btn => {
      btn.onclick = () => {
        const ci = parseInt(btn.dataset.ci), ti = parseInt(btn.dataset.ti);
        const tasks = state.kids[state.activeKidIdx].categories[ci].tasks;
        if (ti < tasks.length - 1) {
          [tasks[ti + 1], tasks[ti]] = [tasks[ti], tasks[ti + 1]]; // Swap array items
          scheduleSave(); renderChart();
        }
      };
    });

    // Add Personal Task
    app().querySelectorAll('.sscc-add-task').forEach(btn => {
      btn.onclick = () => {
        const ci = parseInt(btn.dataset.ci);
        const cat = state.kids[state.activeKidIdx].categories[ci];
        // Inject as 'personal' scope
        cat.tasks.push({ id: uid(), name: 'New Personal Task', isPaid: cat.isPaidCat, amount: 0, unit: 'day', scope: 'personal', checks: Object.fromEntries(DAYS.map(d=>[d,false])) });
        scheduleSave(); renderChart();
      };
    });

    // Checkboxes (Now allow saving to archives!)
    app().querySelectorAll('.sscc-check').forEach(btn => {
      btn.onclick = () => {
        const ci = parseInt(btn.dataset.ci), ti = parseInt(btn.dataset.ti), day = btn.dataset.day;
        const task = state.kids[state.activeKidIdx].categories[ci].tasks[ti];
        task.checks = task.checks || {};
        task.checks[day] = !task.checks[day];
        btn.classList.toggle('checked', task.checks[day]);
        btn.textContent = task.checks[day] ? '✓' : '';
        scheduleSave(); // Context-aware: saves to live OR archive
        
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
        
        const totalCell = btn.closest('tr').querySelector('.sscc-total');
        if (totalCell) {
          const task2 = state.kids[state.activeKidIdx].categories[ci].tasks[ti];
          const paid  = task2.isPaid || state.kids[state.activeKidIdx].categories[ci].isPaidCat;
          const done  = DAYS.filter(d => task2.checks?.[d]).length;
          const earned = paid ? (task2.unit==='flat' ? (done>0?task2.amount:0) : done*(task2.amount||0)) : null;
          totalCell.textContent = paid ? (earned > 0 ? '$' + earned.toFixed(2) : '$0.00') : '—';
        }
      };
    });

    const btnEdit = el('btn-edit');
    if (btnEdit) btnEdit.onclick = () => { state.editMode = !state.editMode; renderChart(); };

    const btnPrint = el('btn-print');
    if (btnPrint) {
      btnPrint.onclick = () => {
        const iframe = document.createElement('iframe');
        iframe.style.position = 'fixed';
        iframe.style.right = '0';
        iframe.style.bottom = '0';
        iframe.style.width = '0';
        iframe.style.height = '0';
        iframe.style.border = 'none';
        document.body.appendChild(iframe);

        const appHtml = document.getElementById('sscc-app').innerHTML;
        const doc = iframe.contentWindow.document;
        doc.open();
        doc.write(`
          <!DOCTYPE html>
          <html>
          <head>
            <title>Super Star Chore Chart</title>
            <link rel="stylesheet" href="${cfg.pluginUrl}assets/app.css" type="text/css" />
            <style>
              body { margin: 0; padding: 0; background: #fff; }
              .sscc-toolbar, .sscc-kid-tabs, .sscc-header, .sscc-nav-bar,
              .sscc-edit-banner, .sscc-msg, .sscc-add-task-row, 
              .sscc-add-cat-row, .sscc-btn, .sscc-btn-sm, 
              .sscc-rm-kid, .sscc-modal-overlay, .sscc-no-print { 
                display: none !important; 
              }
              .sscc-print-only { display: block !important; }
            </style>
          </head>
          <body>
            <div id="sscc-app">
              ${appHtml}
            </div>
            <script>
              window.onload = function() {
                setTimeout(function() { window.focus(); window.print(); }, 400); 
              };
            </script>
          </body>
          </html>
        `);
        doc.close();

        setTimeout(() => { if (document.body.contains(iframe)) document.body.removeChild(iframe); }, 10000);
      };
    }

    const btnArchive = el('btn-archive');
    if (btnArchive) btnArchive.onclick = handleArchive;

    const btnDefaults = el('btn-defaults');
    if (btnDefaults) btnDefaults.onclick = handleEditDefaults;

    const btnLeave = el('btn-leave');
    if (btnLeave) btnLeave.onclick = e => {
      e.preventDefault();
      if (!confirm('Leave the ' + (state.family?.name||'') + ' family? You will need the password to rejoin.')) return;
      post('sscc_leave_family', {}).then(() => { state.family = null; clearInterval(state.pollTimer); renderFamilyGate(); }).catch(e => showMsg(e.message, true));
    };
  }

  // ── Automatic Smart Archive ───────────────────────────────────────────────
  function handleArchive() {
    if (!confirm('Archive this week and start a new one?\n\nThe new week will apply your current Global Settings, but keep all custom personal tasks for each kid.')) return;
    
    post('sscc_archive_week', {}) // No longer needs useDefaults parameter, it's automatic
      .then(d => {
        state.weekOf = d.newWeekOf;
        state.kids   = d.kids;
        state.activeKidIdx = 0;
        showMsg('Week archived! New week starting ' + fmtWeek(d.newWeekOf));
        
        post('sscc_get_archives', {}).then(a => { state.archives = a.archives || []; renderChart(); }).catch(() => renderChart());
      })
      .catch(e => showMsg(e.message, true));
  }

  // ── Edit Settings / Defaults Modal (Global Shared Template) ─────────────
  function handleEditDefaults() {
      const overlay = document.createElement('div');
      overlay.className = 'sscc-modal-overlay';
      document.body.appendChild(overlay);

      const defs = JSON.parse(JSON.stringify(state.defaults));

      function renderModal() {
          overlay.innerHTML = `
          <div class="sscc-modal">
            <div class="sscc-modal-hdr">
              <h2>⚙ Edit Global Settings</h2>
              <button class="sscc-modal-close" id="def-close">✕</button>
            </div>
            <div style="margin-bottom: 20px; padding: 15px; background: rgba(0,0,0,0.05); border-radius: 8px;">
              <label style="font-weight:bold; display:block; margin-bottom:8px;">Change Family Password:</label>
              <input type="text" id="new-fam-pass" placeholder="Leave blank to keep current password" style="width:100%; padding:10px; border:1px solid var(--border-color, #ccc); border-radius:4px;">
            </div>
            <h3 style="margin-bottom:10px; font-size:16px;">Global Task Template (All Kids):</h3>
            <p style="margin-bottom:15px; font-size:14px; font-weight: bold; color: #d97706;">
              ⚠️ Saving changes here will instantly update the live chart for ALL kids. Existing checkmarks will be kept.
            </p>
            
            <div id="def-cats">
              ${defs.map((cat, ci) => `
                <div class="sscc-def-cat" data-ci="${ci}" style="margin-bottom:15px; padding:10px; border:1px solid var(--border-color, #ddd); border-radius:6px; background:var(--bg-card, #fff);">
                  <div class="sscc-def-cat-hdr" style="margin-bottom:10px; display:flex; gap:10px; align-items:center;">
                    <input class="sscc-def-catname" type="text" data-ci="${ci}" value="${esc(cat.name)}" maxlength="60" style="flex:1; padding:5px;">
                    <label style="white-space:nowrap;"><input type="checkbox" class="sscc-def-catpaid" data-ci="${ci}" ${cat.isPaidCat ? 'checked' : ''}> Paid</label>
                    <button class="sscc-rm-defcat sscc-btn-sm" data-ci="${ci}">✕ Remove</button>
                  </div>
                  ${(cat.tasks || []).map((t, ti) => `
                    <div class="sscc-def-task" data-ci="${ci}" data-ti="${ti}" style="display:flex; gap:10px; margin-bottom:5px; align-items:center; padding-left:20px;">
                      <button class="sscc-rm-deftask sscc-btn-sm" data-ci="${ci}" data-ti="${ti}" style="padding: 2px 6px;" title="Remove">✕</button>
					  <button class="sscc-move-deftask-up sscc-btn-sm" data-ci="${ci}" data-ti="${ti}" style="padding: 2px 6px;" title="Move Up" ${ti === 0 ? 'disabled' : ''}>⬆️</button>
					  <button class="sscc-move-deftask-down sscc-btn-sm" data-ci="${ci}" data-ti="${ti}" style="padding: 2px 6px;" title="Move Down" ${ti === cat.tasks.length - 1 ? 'disabled' : ''}>⬇️</button>
                      <input class="sscc-def-taskname" type="text" data-ci="${ci}" data-ti="${ti}" value="${esc(t.name)}" maxlength="80" style="flex:1; padding:5px;">
                      <label style="white-space:nowrap;"><input type="checkbox" class="sscc-def-taskpaid" data-ci="${ci}" data-ti="${ti}" ${t.isPaid ? 'checked' : ''}> $</label>
                      <input class="sscc-def-amount" type="number" step="0.01" min="0" data-ci="${ci}" data-ti="${ti}" value="${t.amount || 0}" style="width:60px; padding:5px;">
                      <select class="sscc-def-unit" data-ci="${ci}" data-ti="${ti}" style="padding:5px;">
                        <option ${t.unit === 'day' ? 'selected' : ''}>day</option>
                        <option ${t.unit === 'flat' ? 'selected' : ''}>flat</option>
                      </select>
                    </div>`).join('')}
                  <button class="sscc-add-deftask sscc-btn-sm" data-ci="${ci}" style="margin-left:20px; margin-top:5px;">+ Add Shared Task</button>
                </div>`).join('')}
            </div>
            <button id="btn-add-defcat" class="sscc-btn-sm" style="margin-bottom:20px;">+ Add Category</button>
            <div class="sscc-modal-footer">
              <button class="sscc-btn" id="def-save">💾 Save Settings</button>
              <button class="sscc-btn sscc-btn-outline" id="def-cancel">Cancel</button>
            </div>
            <div id="sscc-msg-modal" class="sscc-msg"></div>
          </div>`;
          bindModalEvents();
      }

      function bindModalEvents() {
          overlay.querySelector('#def-close').onclick = () => overlay.remove();
          overlay.querySelector('#def-cancel').onclick = () => overlay.remove();
          
          overlay.querySelectorAll('.sscc-add-deftask').forEach(btn => {
              btn.onclick = () => { collect(); defs[btn.dataset.ci].tasks.push({ id: uid(), name: 'New Shared Task', isPaid: false, amount: 0, unit: 'day', scope: 'shared' }); renderModal(); };
          });

          overlay.querySelectorAll('.sscc-rm-deftask').forEach(btn => {
              btn.onclick = () => { collect(); defs[btn.dataset.ci].tasks.splice(btn.dataset.ti, 1); renderModal(); };
          });
		  overlay.querySelectorAll('.sscc-move-deftask-up').forEach(btn => {
              btn.onclick = () => { 
                  collect(); 
                  const ci = parseInt(btn.dataset.ci), ti = parseInt(btn.dataset.ti); 
                  if (ti > 0) { 
                      [defs[ci].tasks[ti - 1], defs[ci].tasks[ti]] = [defs[ci].tasks[ti], defs[ci].tasks[ti - 1]]; 
                      renderModal(); 
                  } 
              };
          });

          overlay.querySelectorAll('.sscc-move-deftask-down').forEach(btn => {
              btn.onclick = () => { 
                  collect(); 
                  const ci = parseInt(btn.dataset.ci), ti = parseInt(btn.dataset.ti); 
                  if (ti < defs[ci].tasks.length - 1) { 
                      [defs[ci].tasks[ti + 1], defs[ci].tasks[ti]] = [defs[ci].tasks[ti], defs[ci].tasks[ti + 1]]; 
                      renderModal(); 
                  } 
              };
          });

          overlay.querySelectorAll('.sscc-rm-defcat').forEach(btn => {
              btn.onclick = () => { collect(); defs.splice(btn.dataset.ci, 1); renderModal(); };
          });

          overlay.querySelector('#btn-add-defcat').onclick = () => { 
              collect(); defs.push({ id: uid(), name: 'New Category', isPaidCat: false, tasks: [] }); renderModal(); 
          };

          overlay.querySelector('#def-save').onclick = () => {
              collect();
              const newPass = el('new-fam-pass').value.trim();
              const msgModal = el('sscc-msg-modal');
              const showModalMsg = (txt, err) => { msgModal.textContent = txt; msgModal.className = 'sscc-msg ' + (err ? 'sscc-msg-err' : 'sscc-msg-ok'); };

              const savePromises = [post('sscc_save_defaults', { defaults: JSON.stringify(defs) })];
              
              if (newPass.length > 0) {
                  if (newPass.length < 4) return showModalMsg('Family password must be at least 4 characters.', true);
                  savePromises.push(post('sscc_change_family_password', { new_password: newPass }));
              }

              Promise.all(savePromises).then(() => { 
                  showMsg('Global Settings Applied to Live Chart!'); 
                  overlay.remove(); 
                  silentReload(); // <--- ADD THIS LINE to instantly refresh the UI
              }).catch(e => showModalMsg(e.message, true));
          };
      }

      const collect = () => {
          overlay.querySelectorAll('.sscc-def-cat').forEach(catEl => {
              const ci = parseInt(catEl.dataset.ci);
              if(isNaN(ci)) return;
              defs[ci].name = catEl.querySelector('.sscc-def-catname').value;
              defs[ci].isPaidCat = catEl.querySelector('.sscc-def-catpaid').checked;
              
              catEl.querySelectorAll('.sscc-def-task').forEach(taskEl => {
                  const ti = parseInt(taskEl.dataset.ti);
                  if (defs[ci].tasks[ti]) {
                      defs[ci].tasks[ti].name = taskEl.querySelector('.sscc-def-taskname').value;
                      defs[ci].tasks[ti].isPaid = taskEl.querySelector('.sscc-def-taskpaid').checked;
                      defs[ci].tasks[ti].amount = parseFloat(taskEl.querySelector('.sscc-def-amount').value) || 0;
                      defs[ci].tasks[ti].unit = taskEl.querySelector('.sscc-def-unit').value;
                  }
              });
          });
      };

      renderModal();
  }

})();