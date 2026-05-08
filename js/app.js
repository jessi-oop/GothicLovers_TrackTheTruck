/* FleetControl Pro — Application Logic */

// ── Constants ────────────────────────────────────────────────────────────────

const STATUS_BADGE = {
  available:      'bg-emerald-100 text-emerald-800 border border-emerald-200',
  checked_out:    'bg-orange-100 text-orange-800 border border-orange-200',
  maintenance:    'bg-yellow-100 text-yellow-800 border border-yellow-200',
  decommissioned: 'bg-gray-100 text-gray-600 border border-gray-200',
};

const STATUS_LABEL = {
  available: 'Available', checked_out: 'Checked Out',
  maintenance: 'Maintenance', decommissioned: 'Decommissioned',
};

const ACTION_BADGE = {
  checkout:      'bg-orange-100 text-orange-800 border border-orange-200',
  return:        'bg-emerald-100 text-emerald-800 border border-emerald-200',
  created:       'bg-blue-100 text-blue-800 border border-blue-200',
  updated:       'bg-yellow-100 text-yellow-800 border border-yellow-200',
  deleted:       'bg-red-100 text-red-800 border border-red-200',
  status_change: 'bg-purple-100 text-purple-800 border border-purple-200',
};

const MAP_COLORS = {
  available: '#10B981', checked_out: '#f97316',
  maintenance: '#EAB308', decommissioned: '#9CA3AF',
};

// ── State ─────────────────────────────────────────────────────────────────────

const State = {
  leafletMap: null,
  mapMarkers: [],
  sites: [],
  auditOffset: 0,
  auditLimit: 20,
  auditTotal: 0,
  auditFilters: {},
  currentSection: 'dashboard',
};

// ── Toast ────────────────────────────────────────────────────────────────────

function toast(msg, type = 'success') {
  const icon = type === 'success' ? 'check_circle' : type === 'error' ? 'error' : 'info';
  const bg   = type === 'success' ? 'bg-emerald-700' : type === 'error' ? 'bg-red-700' : 'bg-secondary';
  const el   = document.createElement('div');
  el.className = `pointer-events-auto flex items-center gap-sm px-md py-sm rounded-lg shadow-xl text-white text-body-sm font-bold ${bg} animate-[fadeIn_0.2s_ease]`;
  el.innerHTML = `<span class="material-symbols-outlined" style="font-size:18px">${icon}</span>${msg}`;
  document.getElementById('toasts').appendChild(el);
  setTimeout(() => el.remove(), 4000);
}

// ── Confirm dialog ───────────────────────────────────────────────────────────

function confirmAction(msg, onYes) {
  document.getElementById('confirm-msg').textContent = msg;
  document.getElementById('modal-confirm').classList.remove('hidden');
  document.getElementById('confirm-yes').onclick = () => {
    document.getElementById('modal-confirm').classList.add('hidden');
    onYes();
  };
  document.getElementById('confirm-no').onclick = () => {
    document.getElementById('modal-confirm').classList.add('hidden');
  };
}

// ── Modal helpers ─────────────────────────────────────────────────────────────

function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

// ── Role-based UI ─────────────────────────────────────────────────────────────

function applyRoleUI() {
  const isAdmin = Api.user()?.role === 'admin';
  document.querySelectorAll('[data-admin]').forEach(el => {
    el.classList.toggle('hidden', !isAdmin);
  });
  if (!isAdmin) {
    document.getElementById('nav-sites')?.classList.add('hidden');
  }
}

// ── Navigation / Router ───────────────────────────────────────────────────────

const sectionLoaders = {
  dashboard: renderDashboard,
  equipment: renderEquipment,
  sites:     renderSites,
  audit:     renderAudit,
  settings:  renderSettings,
  support:   () => {},
};

function navigate(section) {
  if (section === 'sites' && Api.user()?.role !== 'admin') {
    toast('Sites management is restricted to administrators.', 'error');
    return;
  }

  State.currentSection = section;
  document.querySelectorAll('[data-section]').forEach(s => s.classList.add('hidden'));
  const el = document.querySelector(`[data-section="${section}"]`);
  if (el) el.classList.remove('hidden');

  document.querySelectorAll('[data-nav]').forEach(a => {
    const active = a.dataset.nav === section;
    a.classList.toggle('bg-primary',         active);
    a.classList.toggle('text-on-primary',    active);
    a.classList.toggle('font-bold',          active);
    a.classList.toggle('text-surface-variant', !active);
  });

  window.location.hash = section;
  if (sectionLoaders[section]) sectionLoaders[section]();
}

// ── Dashboard (Map) ───────────────────────────────────────────────────────────

async function renderDashboard() {
  // Init Leaflet map once
  if (!State.leafletMap) {
    State.leafletMap = L.map('leaflet-map', {
      zoomControl: false,
      attributionControl: true,
    }).setView([7.0707, 125.6087], 11);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
      maxZoom: 19,
    }).addTo(State.leafletMap);

    document.getElementById('map-zoom-in').onclick  = () => State.leafletMap.zoomIn();
    document.getElementById('map-zoom-out').onclick = () => State.leafletMap.zoomOut();
    document.getElementById('map-locate').onclick   = () => {
      navigator.geolocation?.getCurrentPosition(p => {
        State.leafletMap.setView([p.coords.latitude, p.coords.longitude], 13);
      });
    };
  } else {
    setTimeout(() => State.leafletMap.invalidateSize(), 100);
  }

  // Close panel button
  document.getElementById('map-panel-close').onclick = () => {
    document.getElementById('map-panel-detail').classList.add('hidden');
    document.getElementById('map-panel-detail').classList.remove('flex');
    document.getElementById('map-panel-empty').classList.remove('hidden');
  };

  try {
    const data = await Api.mapPins();
    const pins = data?.pins ?? [];

    // Clear old markers
    State.mapMarkers.forEach(m => m.remove());
    State.mapMarkers = [];

    // Update fleet overview pill
    const counts = { available: 0, checked_out: 0, maintenance: 0, decommissioned: 0 };
    pins.forEach(p => { if (counts[p.status] !== undefined) counts[p.status]++; });
    document.getElementById('fleet-available').textContent    = counts.available;
    document.getElementById('fleet-checked-out').textContent  = counts.checked_out;
    document.getElementById('fleet-maintenance').textContent  = counts.maintenance;
    document.getElementById('fleet-total').textContent        = pins.length + ' units';

    pins.forEach(pin => {
      const color = MAP_COLORS[pin.status] || '#6B7280';
      const icon = L.divIcon({
        className: '',
        html: `<div style="width:16px;height:16px;background:${color};border:2px solid white;border-radius:50%;box-shadow:0 2px 6px rgba(0,0,0,.35)"></div>`,
        iconSize: [16, 16],
        iconAnchor: [8, 8],
      });
      const marker = L.marker([parseFloat(pin.latitude), parseFloat(pin.longitude)], { icon });
      marker.on('click', () => showMapPanel(pin));
      marker.addTo(State.leafletMap);
      State.mapMarkers.push(marker);
    });

    if (pins.length) State.leafletMap.fitBounds(State.mapMarkers.map(m => m.getLatLng()), { padding: [40, 40] });

  } catch (e) {
    toast('Map data unavailable: ' + e.message, 'error');
  }
}

function showMapPanel(pin) {
  document.getElementById('map-panel-empty').classList.add('hidden');
  const detail = document.getElementById('map-panel-detail');
  detail.classList.remove('hidden');
  detail.classList.add('flex');

  const badgeCls = STATUS_BADGE[pin.status] || '';
  document.getElementById('dp-status-badge').className = `px-sm py-xs rounded text-xs font-bold uppercase tracking-wider mb-xs block ${badgeCls}`;
  document.getElementById('dp-status-badge').textContent = STATUS_LABEL[pin.status] || pin.status;
  document.getElementById('dp-name').textContent       = pin.name;
  document.getElementById('dp-serial').textContent     = 'UNIT ID: #' + pin.serial_number;
  document.getElementById('dp-make-model').textContent = pin.make + ' ' + pin.model;
  document.getElementById('dp-serial2').textContent    = pin.serial_number;
  document.getElementById('dp-site').textContent       = pin.site_name || '—';
  document.getElementById('dp-coords').textContent     = `${parseFloat(pin.latitude).toFixed(4)}, ${parseFloat(pin.longitude).toFixed(4)}`;

  const coRow = document.getElementById('dp-checkout-row');
  if (pin.active_checkout) {
    coRow.classList.remove('hidden');
    document.getElementById('dp-operator').textContent = `${pin.active_checkout.employee_name} (${pin.active_checkout.employee_id})`;
  } else {
    coRow.classList.add('hidden');
  }

  document.getElementById('dp-checkout-btn').onclick = () => {
    closeModal('modal-checkout'); // reset
    openCheckoutModal(pin.equipment_id);
  };
}

// ── Equipment ─────────────────────────────────────────────────────────────────

async function renderEquipment() {
  const status  = document.getElementById('eq-status-filter')?.value || '';
  const search  = document.getElementById('eq-search')?.value.toLowerCase() || '';
  const tbody   = document.getElementById('eq-tbody');
  const isAdmin = Api.user()?.role === 'admin';

  tbody.innerHTML = `<tr><td colspan="5" class="text-center py-xl"><span class="material-symbols-outlined animate-spin text-on-surface-variant" style="font-size:24px">refresh</span></td></tr>`;

  try {
    const list = await Api.listEquipment(status);
    const filtered = search
      ? list.filter(e => (e.name + e.make + e.model + e.serial_number).toLowerCase().includes(search))
      : list;

    document.getElementById('eq-count').textContent = `${filtered.length} unit${filtered.length !== 1 ? 's' : ''}`;

    if (!filtered.length) {
      tbody.innerHTML = `<tr><td colspan="5" class="text-center py-xl text-on-surface-variant text-body-sm">No equipment found.</td></tr>`;
      return;
    }

    tbody.innerHTML = filtered.map(e => `
      <tr class="border-b border-outline-variant hover:bg-surface-container-low transition-colors">
        <td class="px-md py-sm">
          <p class="font-bold text-body-sm text-on-background">${esc(e.name)}</p>
          <p class="text-xs text-on-surface-variant">${esc(e.make)} &bull; ${esc(e.model)}</p>
        </td>
        <td class="px-md py-sm font-data-mono text-data-mono text-on-surface-variant">${esc(e.serial_number)}</td>
        <td class="px-md py-sm">
          <span class="px-sm py-xs rounded text-xs font-bold ${STATUS_BADGE[e.status] || ''}">${STATUS_LABEL[e.status] || e.status}</span>
        </td>
        <td class="px-md py-sm text-body-sm text-on-surface-variant">${esc(e.site_name || '—')}</td>
        ${isAdmin ? `
        <td class="px-md py-sm text-right">
          <button onclick="openEquipmentModal(${e.id})" class="text-secondary hover:text-on-background p-xs rounded transition-colors" title="Edit">
            <span class="material-symbols-outlined" style="font-size:18px">edit</span>
          </button>
          <button onclick="deleteEquipment(${e.id},'${esc(e.name)}')" class="text-error hover:text-red-700 p-xs rounded transition-colors" title="Delete">
            <span class="material-symbols-outlined" style="font-size:18px">delete</span>
          </button>
        </td>` : '<td></td>'}
      </tr>`).join('');
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="5" class="text-center py-xl text-error text-body-sm">${e.message}</td></tr>`;
  }
}

async function openEquipmentModal(id = null) {
  // Load sites for the dropdown
  try {
    State.sites = await Api.listSites();
  } catch { State.sites = []; }

  const siteSelect = document.getElementById('eq-site');
  siteSelect.innerHTML = '<option value="">— No site assigned —</option>' +
    State.sites.map(s => `<option value="${s.id}">${esc(s.name)}</option>`).join('');

  document.getElementById('eq-id').value    = '';
  document.getElementById('eq-name').value  = '';
  document.getElementById('eq-make').value  = '';
  document.getElementById('eq-model').value = '';
  document.getElementById('eq-serial').value = '';
  document.getElementById('eq-status').value = 'available';
  document.getElementById('eq-site').value   = '';
  document.getElementById('eq-modal-title').textContent = id ? 'Edit Equipment' : 'Add Equipment';

  if (id) {
    try {
      const eq = await Api.getEquipment(id);
      document.getElementById('eq-id').value     = eq.id;
      document.getElementById('eq-name').value   = eq.name;
      document.getElementById('eq-make').value   = eq.make;
      document.getElementById('eq-model').value  = eq.model;
      document.getElementById('eq-serial').value = eq.serial_number;
      document.getElementById('eq-status').value = eq.status;
      document.getElementById('eq-site').value   = eq.site_id || '';
    } catch (e) { toast(e.message, 'error'); return; }
  }

  openModal('modal-equipment');
}

async function submitEquipmentForm() {
  const id = document.getElementById('eq-id').value;
  const payload = {
    name:          document.getElementById('eq-name').value.trim(),
    make:          document.getElementById('eq-make').value.trim(),
    model:         document.getElementById('eq-model').value.trim(),
    serial_number: document.getElementById('eq-serial').value.trim(),
    status:        document.getElementById('eq-status').value,
    site_id:       document.getElementById('eq-site').value || null,
  };

  const btn = document.getElementById('eq-save');
  btn.disabled = true;

  try {
    if (id) {
      await Api.updateEquipment(id, payload);
      toast('Equipment updated.');
    } else {
      await Api.createEquipment(payload);
      toast('Equipment created.');
    }
    closeModal('modal-equipment');
    renderEquipment();
  } catch (e) {
    toast(e.message, 'error');
  } finally {
    btn.disabled = false;
  }
}

async function deleteEquipment(id, name) {
  confirmAction(`Delete "${name}"? This action cannot be undone.`, async () => {
    try {
      await Api.deleteEquipment(id);
      toast('Equipment deleted.');
      renderEquipment();
    } catch (e) { toast(e.message, 'error'); }
  });
}

// ── Sites ─────────────────────────────────────────────────────────────────────

async function renderSites() {
  const grid = document.getElementById('sites-grid');
  grid.innerHTML = `<div class="col-span-3 text-center py-xl"><span class="material-symbols-outlined animate-spin text-on-surface-variant" style="font-size:24px">refresh</span></div>`;

  try {
    const sites = await Api.listSites();
    document.getElementById('sites-count').textContent = `${sites.length} site${sites.length !== 1 ? 's' : ''}`;

    if (!sites.length) {
      grid.innerHTML = `<div class="col-span-3 text-center py-xl text-on-surface-variant text-body-sm">No sites found.</div>`;
      return;
    }

    grid.innerHTML = sites.map(s => `
      <div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-lg hover:shadow-md transition-shadow">
        <div class="flex items-start justify-between mb-md">
          <div class="w-10 h-10 rounded-lg bg-primary/10 flex items-center justify-center text-primary flex-shrink-0">
            <span class="material-symbols-outlined" style="font-size:20px">location_on</span>
          </div>
          <div class="flex gap-xs">
            <button onclick="openSiteModal(${s.id})" class="text-secondary hover:text-on-background p-xs rounded transition-colors" title="Edit">
              <span class="material-symbols-outlined" style="font-size:16px">edit</span>
            </button>
            <button onclick="deleteSite(${s.id},'${esc(s.name)}')" class="text-error hover:text-red-700 p-xs rounded transition-colors" title="Delete">
              <span class="material-symbols-outlined" style="font-size:16px">delete</span>
            </button>
          </div>
        </div>
        <h3 class="font-bold text-on-background mb-xs">${esc(s.name)}</h3>
        <p class="text-body-sm text-on-surface-variant mb-md">${esc(s.address)}</p>
        <div class="flex items-center gap-lg text-xs text-on-surface-variant border-t border-outline-variant pt-sm">
          <span class="font-data-mono text-data-mono">${parseFloat(s.latitude).toFixed(4)}, ${parseFloat(s.longitude).toFixed(4)}</span>
          <span class="ml-auto font-bold text-on-background">${s.equipment_count || 0} units</span>
        </div>
      </div>`).join('');
  } catch (e) {
    grid.innerHTML = `<div class="col-span-3 text-center py-xl text-error text-body-sm">${e.message}</div>`;
  }
}

async function openSiteModal(id = null) {
  document.getElementById('site-id').value      = '';
  document.getElementById('site-name').value    = '';
  document.getElementById('site-address').value = '';
  document.getElementById('site-lat').value     = '';
  document.getElementById('site-lng').value     = '';
  document.getElementById('site-modal-title').textContent = id ? 'Edit Site' : 'Add Site';

  if (id) {
    try {
      const site = await Api.getSite(id);
      document.getElementById('site-id').value      = site.id;
      document.getElementById('site-name').value    = site.name;
      document.getElementById('site-address').value = site.address;
      document.getElementById('site-lat').value     = site.latitude;
      document.getElementById('site-lng').value     = site.longitude;
    } catch (e) { toast(e.message, 'error'); return; }
  }

  openModal('modal-site');
}

async function submitSiteForm() {
  const id = document.getElementById('site-id').value;
  const payload = {
    name:      document.getElementById('site-name').value.trim(),
    address:   document.getElementById('site-address').value.trim(),
    latitude:  parseFloat(document.getElementById('site-lat').value),
    longitude: parseFloat(document.getElementById('site-lng').value),
  };

  const btn = document.getElementById('site-save');
  btn.disabled = true;

  try {
    if (id) { await Api.updateSite(id, payload); toast('Site updated.'); }
    else    { await Api.createSite(payload);      toast('Site created.'); }
    closeModal('modal-site');
    renderSites();
  } catch (e) {
    toast(e.message, 'error');
  } finally {
    btn.disabled = false;
  }
}

async function deleteSite(id, name) {
  confirmAction(`Delete site "${name}"? Equipment must be reassigned first.`, async () => {
    try {
      await Api.deleteSite(id);
      toast('Site deleted.');
      renderSites();
    } catch (e) { toast(e.message, 'error'); }
  });
}

// ── Audit Log ────────────────────────────────────────────────────────────────

async function renderAudit(reset = false) {
  if (reset) State.auditOffset = 0;

  const tbody = document.getElementById('audit-tbody');
  tbody.innerHTML = `<tr><td colspan="5" class="text-center py-xl"><span class="material-symbols-outlined animate-spin text-on-surface-variant" style="font-size:24px">refresh</span></td></tr>`;

  const params = {
    limit:  State.auditLimit,
    offset: State.auditOffset,
    ...State.auditFilters,
  };
  Object.keys(params).forEach(k => params[k] === '' && delete params[k]);

  try {
    const res = await Api.listAudit(params);
    State.auditTotal = res.total;
    const rows = res.data || [];

    const from = State.auditOffset + 1;
    const to   = Math.min(State.auditOffset + rows.length, res.total);
    document.getElementById('audit-info').textContent      = `${res.total} entries total`;
    document.getElementById('audit-page-info').textContent = rows.length ? `${from}–${to} of ${res.total}` : 'No results';

    document.getElementById('audit-prev').disabled = State.auditOffset === 0;
    document.getElementById('audit-next').disabled = State.auditOffset + State.auditLimit >= res.total;

    if (!rows.length) {
      tbody.innerHTML = `<tr><td colspan="5" class="text-center py-xl text-on-surface-variant text-body-sm">No audit entries found.</td></tr>`;
      return;
    }

    tbody.innerHTML = rows.map(r => `
      <tr class="border-b border-outline-variant hover:bg-surface-container-low transition-colors">
        <td class="px-md py-sm font-data-mono text-data-mono text-on-surface-variant whitespace-nowrap">${fmtDate(r.timestamp)}</td>
        <td class="px-md py-sm">
          <p class="font-bold text-body-sm text-on-background">${esc(r.equipment_name)}</p>
          <p class="text-xs text-on-surface-variant font-data-mono">${esc(r.serial_number)}</p>
        </td>
        <td class="px-md py-sm">
          <p class="text-body-sm text-on-background">${esc(r.employee_name)}</p>
          <p class="text-xs text-on-surface-variant">${esc(r.employee_id)}</p>
        </td>
        <td class="px-md py-sm">
          <span class="px-sm py-xs rounded text-xs font-bold ${ACTION_BADGE[r.action] || ''}">${r.action.replace('_', ' ')}</span>
        </td>
        <td class="px-md py-sm text-body-sm text-on-surface-variant max-w-xs truncate" title="${esc(r.details || '')}">${esc(r.details || '—')}</td>
      </tr>`).join('');
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="5" class="text-center py-xl text-error text-body-sm">${e.message}</td></tr>`;
  }
}

// ── Checkout Modal ────────────────────────────────────────────────────────────

async function openCheckoutModal(preselectedEquipId = null) {
  const isAdmin = Api.user()?.role === 'admin';
  switchCoTab(isAdmin ? 'checkout' : 'return');

  // Load available equipment into select
  try {
    const list = await Api.listEquipment('available');
    const sel  = document.getElementById('co-equipment');
    sel.innerHTML = list.length
      ? list.map(e => `<option value="${e.id}" ${e.id == preselectedEquipId ? 'selected' : ''}>${esc(e.name)} — ${esc(e.serial_number)}</option>`).join('')
      : '<option value="">No available equipment</option>';
  } catch { }

  document.getElementById('checkout-form').reset();
  openModal('modal-checkout');
}

function switchCoTab(tab) {
  const isCheckout = tab === 'checkout';
  document.getElementById('tab-co').classList.toggle('hidden', !isCheckout);
  document.getElementById('tab-ret').classList.toggle('hidden', isCheckout);
  document.getElementById('co-footer').classList.toggle('hidden', !isCheckout);
  document.getElementById('tab-co-btn').classList.toggle('border-primary', isCheckout);
  document.getElementById('tab-co-btn').classList.toggle('text-primary', isCheckout);
  document.getElementById('tab-co-btn').classList.toggle('border-transparent', !isCheckout);
  document.getElementById('tab-ret-btn').classList.toggle('border-primary', !isCheckout);
  document.getElementById('tab-ret-btn').classList.toggle('text-primary', !isCheckout);
  document.getElementById('tab-ret-btn').classList.toggle('border-transparent', isCheckout);

  if (!isCheckout) loadActiveCheckouts();
}

async function loadActiveCheckouts() {
  const container = document.getElementById('active-checkouts-list');
  container.innerHTML = `<p class="text-center py-lg text-on-surface-variant text-body-sm"><span class="material-symbols-outlined animate-spin" style="font-size:20px">refresh</span></p>`;

  try {
    const list = await Api.listCheckouts();
    if (!list.length) {
      container.innerHTML = `<p class="text-center py-lg text-on-surface-variant text-body-sm">No active checkouts.</p>`;
      return;
    }
    container.innerHTML = list.map(c => `
      <div class="flex items-center justify-between p-md bg-surface-container-low border border-outline-variant rounded-lg">
        <div class="min-w-0">
          <p class="font-bold text-body-sm text-on-background truncate">${esc(c.equipment_name)}</p>
          <p class="text-xs text-on-surface-variant">${esc(c.employee_name)} &bull; ${esc(c.employee_id)}</p>
          <p class="text-xs font-data-mono text-on-surface-variant mt-xs">Out: ${fmtDate(c.checked_out_at)}</p>
        </div>
        <button onclick="returnKey(${c.id},'${esc(c.equipment_name)}')"
          class="ml-md flex-shrink-0 bg-primary text-on-primary font-bold px-md py-xs rounded-lg hover:brightness-110 text-xs flex items-center gap-xs">
          <span class="material-symbols-outlined" style="font-size:14px">keyboard_return</span>Return
        </button>
      </div>`).join('');
  } catch (e) {
    container.innerHTML = `<p class="text-center py-lg text-error text-body-sm">${e.message}</p>`;
  }
}

async function submitCheckout() {
  const equip  = document.getElementById('co-equipment').value;
  const name   = document.getElementById('co-emp-name').value.trim();
  const empId  = document.getElementById('co-emp-id').value.trim();
  const ret    = document.getElementById('co-return').value;
  const notes  = document.getElementById('co-notes').value.trim();

  if (!equip || !name || !empId) { toast('Equipment, employee name, and ID are required.', 'error'); return; }

  const btn = document.getElementById('co-submit');
  btn.disabled = true;

  try {
    await Api.checkout({
      equipment_id: parseInt(equip),
      employee_name: name,
      employee_id: empId,
      expected_return_at: ret || null,
      notes: notes || null,
    });
    toast('Key checked out successfully.');
    closeModal('modal-checkout');
    if (State.currentSection === 'equipment') renderEquipment();
    if (State.currentSection === 'dashboard') renderDashboard();
  } catch (e) {
    toast(e.message, 'error');
  } finally {
    btn.disabled = false;
  }
}

async function returnKey(checkoutId, name) {
  try {
    await Api.returnKey(checkoutId, '');
    toast(`Key returned: ${name}`);
    loadActiveCheckouts();
    if (State.currentSection === 'equipment') renderEquipment();
    if (State.currentSection === 'dashboard') renderDashboard();
  } catch (e) {
    toast(e.message, 'error');
  }
}

// ── Settings ──────────────────────────────────────────────────────────────────

function renderSettings() {
  const user = Api.user();
  if (!user) return;
  document.getElementById('s-full-name').textContent = user.full_name || '—';
  document.getElementById('s-username').textContent  = user.username  || '—';
  document.getElementById('s-role').textContent      = user.role      || '—';
}

// ── Utilities ─────────────────────────────────────────────────────────────────

function esc(str) {
  return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function fmtDate(str) {
  if (!str) return '—';
  const d = new Date(str.replace(' ', 'T'));
  return isNaN(d) ? str : d.toLocaleString('en-PH', { dateStyle:'medium', timeStyle:'short' });
}

// ── Init ──────────────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', () => {
  if (!Api.token()) { window.location.href = 'login.html'; return; }

  // Populate sidebar user block
  const user = Api.user();
  if (user) {
    document.getElementById('user-name').textContent          = user.full_name || user.username;
    document.getElementById('user-role').textContent          = user.role || '';
    document.getElementById('user-avatar-initial').textContent = (user.full_name || user.username || 'U')[0].toUpperCase();
  }

  applyRoleUI();

  // Nav links
  document.querySelectorAll('[data-nav]').forEach(link => {
    link.addEventListener('click', e => { e.preventDefault(); navigate(link.dataset.nav); });
  });

  // Sidebar "Add Equipment" shortcut
  document.getElementById('btn-add-equipment')?.addEventListener('click', () => {
    navigate('equipment');
    setTimeout(() => openEquipmentModal(), 50);
  });

  // Top bar "Add Equipment" button (equipment section)
  document.getElementById('btn-add-eq')?.addEventListener('click', () => openEquipmentModal());

  // Add site button
  document.getElementById('btn-add-site')?.addEventListener('click', () => openSiteModal());

  // Check In/Out button
  document.getElementById('btn-checkout').addEventListener('click', () => openCheckoutModal());

  // Equipment filters
  document.getElementById('eq-status-filter').addEventListener('change', renderEquipment);
  document.getElementById('eq-search').addEventListener('input', renderEquipment);

  // Equipment form submit
  document.getElementById('equipment-form').addEventListener('submit', e => { e.preventDefault(); submitEquipmentForm(); });

  // Site form submit
  document.getElementById('site-form').addEventListener('submit', e => { e.preventDefault(); submitSiteForm(); });

  // Checkout form submit
  document.getElementById('checkout-form').addEventListener('submit', e => { e.preventDefault(); submitCheckout(); });

  // Checkout tabs
  document.getElementById('tab-co-btn').addEventListener('click', () => switchCoTab('checkout'));
  document.getElementById('tab-ret-btn').addEventListener('click', () => switchCoTab('return'));

  // Audit filters
  document.getElementById('audit-apply').addEventListener('click', () => {
    State.auditFilters = {
      action:    document.getElementById('audit-action').value,
      date_from: document.getElementById('audit-from').value,
      date_to:   document.getElementById('audit-to').value,
    };
    renderAudit(true);
  });
  document.getElementById('audit-reset').addEventListener('click', () => {
    document.getElementById('audit-action').value = '';
    document.getElementById('audit-from').value   = '';
    document.getElementById('audit-to').value     = '';
    State.auditFilters = {};
    renderAudit(true);
  });
  document.getElementById('audit-prev').addEventListener('click', () => {
    State.auditOffset = Math.max(0, State.auditOffset - State.auditLimit);
    renderAudit();
  });
  document.getElementById('audit-next').addEventListener('click', () => {
    State.auditOffset += State.auditLimit;
    renderAudit();
  });

  // Modal close buttons (generic)
  document.querySelectorAll('.modal-close').forEach(btn => {
    btn.addEventListener('click', () => closeModal(btn.dataset.modal));
  });

  // Settings logout
  document.getElementById('settings-logout').addEventListener('click', async () => {
    await Api.logout().catch(() => {});
    sessionStorage.clear();
    window.location.href = 'login.html';
  });

  // Sidebar logout
  document.getElementById('btn-logout').addEventListener('click', async () => {
    await Api.logout().catch(() => {});
    sessionStorage.clear();
    window.location.href = 'login.html';
  });

  // Initial navigation
  const hash = window.location.hash.replace('#', '') || 'dashboard';
  navigate(hash);
});
