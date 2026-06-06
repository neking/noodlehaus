// NoodleHaus Admin Modules — Phase 5+6
// CRM, Delivery, Branches, Reservations, Stock, Shift

/* ═══════════════════════════════════════
   CRM
═══════════════════════════════════════ */
let crmPage = 1;
let crmSearchTimer = null;

function crmSearchDebounce() {
  clearTimeout(crmSearchTimer);
  crmSearchTimer = setTimeout(() => { crmPage = 1; crmLoadCustomers(); }, 400);
}

async function crmLoadCustomers(page = null) {
  if (page) crmPage = page;
  const search = document.getElementById('crm-search')?.value.trim() || '';
  const tag    = document.getElementById('crm-tag-filter')?.value || '';
  const tbody  = document.getElementById('crm-tbody');
  tbody.innerHTML = '<tr><td colspan="7" style="padding:2rem;text-align:center;color:var(--text-muted)">Loading...</td></tr>';

  try {
    const params = new URLSearchParams({ action:'list', page: crmPage, per: 20 });
    if (search) params.set('search', search);
    if (tag)    params.set('tag', tag);
    const r = await fetch('crm_api.php?' + params);
    const d = await r.json();
    if (!d.ok) throw new Error(d.msg);

    document.getElementById('crm-count').textContent = `${d.total} customers`;
    crmRenderTable(d.customers);
    crmRenderPagination(d.page, d.pages);
  } catch(e) {
    tbody.innerHTML = `<tr><td colspan="7" style="padding:2rem;text-align:center;color:#e74c3c">${e.message}</td></tr>`;
  }
}

function crmRenderTable(customers) {
  const tbody = document.getElementById('crm-tbody');
  if (!customers.length) {
    tbody.innerHTML = '<tr><td colspan="7" style="padding:2rem;text-align:center;color:var(--text-muted)">No customers yet</td></tr>';
    return;
  }
  const tagBadge = { vip:'⭐', regular:'🔄', normal:'👤', blocked:'🚫' };
  const tagColor = { vip:'#f39c12', regular:'#27ae60', normal:'var(--text-muted)', blocked:'#e74c3c' };

  tbody.innerHTML = customers.map(c => `
    <tr style="border-bottom:1px solid var(--border);transition:background .15s" onmouseover="this.style.background='var(--surface2)'" onmouseout="this.style.background=''">
      <td style="padding:.75rem 1rem">
        <div style="font-weight:600">${escHtml(c.name || '—')}</div>
        <div style="font-size:.8rem;color:var(--text-muted)">${escHtml(c.phone)}</div>
      </td>
      <td style="padding:.75rem 1rem">
        <span style="color:${tagColor[c.tag]};font-size:.85rem">${tagBadge[c.tag]||''} ${c.tag}</span>
      </td>
      <td style="padding:.75rem 1rem;text-align:right;font-weight:600">${c.total_orders}</td>
      <td style="padding:.75rem 1rem;text-align:right">${Number(c.total_spent).toLocaleString()} MMK</td>
      <td style="padding:.75rem 1rem;font-size:.82rem;color:var(--text-muted)">${c.last_order_at ? c.last_order_at.slice(0,10) : '—'}</td>
      <td style="padding:.75rem 1rem;font-size:.85rem">
        ${c.stamps > 0 ? `🎟 ${c.stamps} stamps` : '<span style="color:var(--text-muted)">—</span>'}
      </td>
      <td style="padding:.75rem 1rem;text-align:center">
        <button class="btn btn-ghost btn-sm" onclick="crmOpenProfile('${escHtml(c.phone)}')">View</button>
      </td>
    </tr>
  `).join('');
}

function crmRenderPagination(current, total) {
  const el = document.getElementById('crm-pagination');
  if (total <= 1) { el.innerHTML = ''; return; }
  let html = '';
  for (let i = 1; i <= total; i++) {
    html += `<button class="btn btn-sm ${i===current?'btn-primary':'btn-ghost'}" onclick="crmLoadCustomers(${i})">${i}</button>`;
  }
  el.innerHTML = html;
}

async function crmOpenProfile(phone) {
  const modal = document.getElementById('crm-modal');
  const body  = document.getElementById('crm-modal-body');
  modal.style.display = 'block';
  body.innerHTML = '<div style="text-align:center;padding:2rem;color:var(--text-muted)">Loading...</div>';

  try {
    const r = await fetch(`crm_api.php?action=profile&phone=${encodeURIComponent(phone)}`);
    const d = await r.json();
    if (!d.ok) throw new Error(d.msg);

    const p   = d.profile || {};
    const loy = d.loyalty;
    const tagColor = { vip:'#f39c12', regular:'#27ae60', normal:'var(--text-muted)', blocked:'#e74c3c' };
    const tagBadge = { vip:'⭐ VIP', regular:'🔄 Regular', normal:'👤 Normal', blocked:'🚫 Blocked' };
    const tag = p.tag || 'normal';

    body.innerHTML = `
      <!-- Header -->
      <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem">
        <div style="width:56px;height:56px;border-radius:50%;background:var(--accent2);display:flex;align-items:center;justify-content:center;font-size:1.5rem">
          ${tag==='vip'?'⭐':'👤'}
        </div>
        <div>
          <div style="font-size:1.2rem;font-weight:700">${escHtml(p.name || phone)}</div>
          <div style="color:var(--text-muted);font-size:.9rem">${escHtml(phone)}</div>
        </div>
        <span style="margin-left:auto;color:${tagColor[tag]};font-weight:600">${tagBadge[tag]}</span>
      </div>

      <!-- Stats row -->
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.5rem">
        <div style="background:var(--surface2);border-radius:10px;padding:1rem;text-align:center">
          <div style="font-size:1.4rem;font-weight:700">${p.total_orders||0}</div>
          <div style="font-size:.78rem;color:var(--text-muted)">Total Orders</div>
        </div>
        <div style="background:var(--surface2);border-radius:10px;padding:1rem;text-align:center">
          <div style="font-size:1.1rem;font-weight:700">${Number(p.total_spent||0).toLocaleString()}</div>
          <div style="font-size:.78rem;color:var(--text-muted)">MMK Spent</div>
        </div>
        <div style="background:var(--surface2);border-radius:10px;padding:1rem;text-align:center">
          <div style="font-size:1.4rem;font-weight:700">${loy.stamps}</div>
          <div style="font-size:.78rem;color:var(--text-muted)">Loyalty Stamps</div>
        </div>
      </div>

      <!-- Favourites -->
      ${d.favourites.length ? `
      <div style="margin-bottom:1.5rem">
        <div style="font-weight:600;margin-bottom:.6rem">🍜 Favourite Items</div>
        <div style="display:flex;flex-wrap:wrap;gap:.5rem">
          ${d.favourites.map(f=>`
            <span style="background:var(--surface2);border-radius:20px;padding:.3rem .8rem;font-size:.82rem">
              ${escHtml(f.emoji||'🍽️')} ${escHtml(f.item_name)} <span style="color:var(--text-muted)">×${f.order_count}</span>
            </span>
          `).join('')}
        </div>
      </div>` : ''}

      <!-- Recent orders -->
      ${d.recent_orders.length ? `
      <div style="margin-bottom:1.5rem">
        <div style="font-weight:600;margin-bottom:.6rem">📋 Recent Orders</div>
        ${d.recent_orders.map(o=>`
          <div style="border-bottom:1px solid var(--border);padding:.6rem 0;font-size:.85rem">
            <div style="display:flex;justify-content:space-between">
              <span style="font-weight:600">NH-${String(o.id).padStart(6,'0')}</span>
              <span>${Number(o.total_amount).toLocaleString()} MMK</span>
            </div>
            <div style="color:var(--text-muted);font-size:.8rem;margin-top:.2rem">${escHtml(o.items_summary||'')}</div>
            <div style="color:var(--text-muted);font-size:.78rem">${(o.created_at||'').slice(0,16)}</div>
          </div>
        `).join('')}
      </div>` : ''}

      <!-- Tag editor -->
      <div style="background:var(--surface2);border-radius:10px;padding:1rem">
        <div style="font-weight:600;margin-bottom:.75rem">✏️ Update Tag / Notes</div>
        <div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end">
          <select id="crm-edit-tag" style="flex:1;min-width:140px;padding:.5rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
            <option value="normal"  ${tag==='normal' ?'selected':''}>👤 Normal</option>
            <option value="regular" ${tag==='regular'?'selected':''}>🔄 Regular</option>
            <option value="vip"     ${tag==='vip'    ?'selected':''}>⭐ VIP</option>
            <option value="blocked" ${tag==='blocked'?'selected':''}>🚫 Blocked</option>
          </select>
          <input id="crm-edit-notes" type="text" placeholder="Staff notes..."
            value="${escHtml(p.notes||'')}"
            style="flex:2;min-width:200px;padding:.5rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
          <button class="btn btn-primary btn-sm" onclick="crmSaveTag('${escHtml(phone)}')">Save</button>
        </div>
      </div>
    `;
  } catch(e) {
    body.innerHTML = `<div style="color:#e74c3c;padding:2rem">${e.message}</div>`;
  }
}

async function crmSaveTag(phone) {
  const tag   = document.getElementById('crm-edit-tag').value;
  const notes = document.getElementById('crm-edit-notes').value.trim();
  try {
    const r = await fetch('crm_api.php?action=update_tag', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ phone, tag, notes })
    });
    const d = await r.json();
    if (!d.ok) throw new Error(d.msg);
    showToast('✅ Updated');
    document.getElementById('crm-modal').style.display = 'none';
    crmLoadCustomers();
  } catch(e) { showToast('❌ ' + e.message, true); }
}

/* ═══════════════════════════════════════
   DELIVERY
═══════════════════════════════════════ */
let _dlvDrivers = [];
let _delDrivers = [];

async function delLoad() {
  await Promise.all([delLoadStats(), delLoadActive(), delLoadDrivers(), delLoadZones()]);
}

async function delLoadStats() {
  try {
    const r = await fetch('delivery_api.php?action=stats');
    const d = await r.json();
    if (!d.ok) return;
    const s = d.stats;
    document.getElementById('del-stats').innerHTML = `
      <div class="card" style="padding:1rem;text-align:center">
        <div style="font-size:1.5rem;font-weight:700;color:${s.pending_assign>0?'#f39c12':'var(--text)'}">${s.pending_assign}</div>
        <div style="font-size:.78rem;color:var(--text-muted)">⏳ Pending Assign</div>
      </div>
      <div class="card" style="padding:1rem;text-align:center">
        <div style="font-size:1.5rem;font-weight:700">${s.active}</div>
        <div style="font-size:.78rem;color:var(--text-muted)">🛵 Active</div>
      </div>
      <div class="card" style="padding:1rem;text-align:center">
        <div style="font-size:1.5rem;font-weight:700;color:#27ae60">${s.today_delivered}</div>
        <div style="font-size:.78rem;color:var(--text-muted)">✅ Today Delivered</div>
      </div>
      <div class="card" style="padding:1rem;text-align:center">
        <div style="font-size:1.5rem;font-weight:700">${s.drivers_available}</div>
        <div style="font-size:.78rem;color:var(--text-muted)">🟢 Drivers Available</div>
      </div>`;
  } catch(e) {}
}

async function delLoadActive() {
  const tbody = document.getElementById('del-active-tbody');
  try {
    const r = await fetch('delivery_api.php?action=active');
    const d = await r.json();
    if (!d.ok) throw new Error(d.msg);
    if (!d.deliveries.length) {
      tbody.innerHTML = '<tr><td colspan="7" style="padding:2rem;text-align:center;color:var(--text-muted)">No active deliveries</td></tr>';
      return;
    }
    const statusBadge = {pending:'⏳',assigned:'🔄',picked_up:'📦',delivering:'🛵'};
    const statusColor = {pending:'#f39c12',assigned:'#3498db',picked_up:'#e84c2b',delivering:'#27ae60'};
    tbody.innerHTML = d.deliveries.map(dl => `
      <tr style="border-bottom:1px solid var(--border)">
        <td style="padding:.6rem 1rem;font-weight:700">NH-${String(dl.order_id).padStart(6,'0')}</td>
        <td style="padding:.6rem 1rem"><div style="font-weight:600">${escHtml(dl.customer_name)}</div><div style="font-size:.78rem;color:var(--text-muted)">${escHtml(dl.customer_phone||'')}</div></td>
        <td style="padding:.6rem 1rem;font-size:.82rem;color:var(--text-muted);max-width:200px;overflow:hidden;text-overflow:ellipsis">${escHtml(dl.items||'')}</td>
        <td style="padding:.6rem 1rem;text-align:right;font-weight:600">${Number(dl.total_amount).toLocaleString()}</td>
        <td style="padding:.6rem 1rem;text-align:center"><span style="color:${statusColor[dl.status]};font-weight:600">${statusBadge[dl.status]||''} ${dl.status}</span></td>
        <td style="padding:.6rem 1rem">${dl.driver_name ? escHtml(dl.driver_name) : '<span style="color:var(--text-muted)">—</span>'}</td>
        <td style="padding:.6rem 1rem;text-align:center">
          ${dl.status==='pending' ? `
            <select onchange="delAssign(${dl.id},this.value)" style="padding:.3rem;border-radius:6px;border:1px solid var(--border);background:var(--surface);color:var(--text);font-size:.8rem">
              <option value="">Assign driver</option>
              ${_delDrivers.filter(d=>d.status==='available').map(d=>`<option value="${d.id}">${escHtml(d.name)}</option>`).join('')}
            </select>` : `<span style="font-size:.8rem;color:var(--text-muted)">${dl.status}</span>`}
        </td>
      </tr>
    `).join('');
  } catch(e) {
    tbody.innerHTML = `<tr><td colspan="7" style="padding:2rem;text-align:center;color:#e74c3c">${e.message}</td></tr>`;
  }
}

async function delLoadDrivers() {
  try {
    const r = await fetch('delivery_api.php?action=drivers');
    const d = await r.json();
    if (!d.ok) return;
    _delDrivers = d.drivers;
    const el = document.getElementById('del-drivers');
    const statusIcon = {available:'🟢',busy:'🔴',offline:'⚫'};
    const vehicleIcon = {motorbike:'🛵',bicycle:'🚲',car:'🚗',walk:'🚶'};
    el.innerHTML = d.drivers.map(dr => `
      <div style="display:flex;align-items:center;gap:.8rem;padding:.6rem .8rem;border-bottom:1px solid var(--border)">
        <span style="font-size:1.2rem">${vehicleIcon[dr.vehicle_type]||'🛵'}</span>
        <div style="flex:1">
          <div style="font-weight:600;font-size:.88rem">${escHtml(dr.name)}</div>
          <div style="font-size:.78rem;color:var(--text-muted)">${escHtml(dr.phone)} · ${dr.active_orders||0} active</div>
        </div>
        <span style="font-size:.85rem">${statusIcon[dr.status]||'⚫'} ${dr.status}</span>
      </div>
    `).join('') || '<div style="padding:1rem;text-align:center;color:var(--text-muted)">No drivers</div>';
  } catch(e) {}
}

async function delLoadZones() {
  try {
    const r = await fetch('delivery_api.php?action=zones');
    const d = await r.json();
    if (!d.ok) return;
    document.getElementById('del-zones').innerHTML = d.zones.map(z => `
      <div style="display:flex;align-items:center;gap:.8rem;padding:.6rem .8rem;border-bottom:1px solid var(--border)">
        <div style="flex:1">
          <div style="font-weight:600;font-size:.88rem">${escHtml(z.zone_name)}</div>
          <div style="font-size:.78rem;color:var(--text-muted)">${escHtml(z.township||'')} · ~${z.estimated_min}min</div>
        </div>
        <span style="font-weight:700;font-size:.9rem">${Number(z.fee).toLocaleString()} MMK</span>
      </div>
    `).join('') || '<div style="padding:1rem;text-align:center;color:var(--text-muted)">No zones</div>';
  } catch(e) {}
}

async function delAssign(trackingId, driverId) {
  if (!driverId) return;
  try {
    const r = await fetch('delivery_api.php?action=assign', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({tracking_id: trackingId, driver_id: parseInt(driverId)})
    });
    const d = await r.json();
    if (!d.ok) throw new Error(d.msg);
    toast('✅ Driver assigned');
    delLoad();
  } catch(e) { toast('❌ '+e.message,'err'); }
}

function delAddDriver() {
  const name = prompt('Driver name:');
  if (!name) return;
  const phone = prompt('Phone:');
  if (!phone) return;
  const pin = prompt('PIN (4 digits):');
  fetch('delivery_api.php?action=driver_create', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({name, phone, vehicle_type:'motorbike', pin: pin||null})
  }).then(r=>r.json()).then(d=>{
    if (d.ok) { toast('✅ Driver added'); delLoad(); }
    else toast('❌ '+d.msg,'err');
  });
}


async function dlvLoad() {
  try {
    const [dr, pe, ac] = await Promise.all([
      fetch('delivery_api.php?action=drivers').then(r=>r.json()),
      fetch('delivery_api.php?action=pending_orders').then(r=>r.json()),
      fetch('delivery_api.php?action=active').then(r=>r.json()),
    ]);
    if (dr.ok) { _dlvDrivers = dr.drivers; dlvRenderDrivers(dr.drivers); }
    if (pe.ok) dlvRenderPending(pe.orders);
    if (ac.ok) dlvRenderActive(ac.deliveries);
  } catch(e) {}
}

function dlvRenderDrivers(drivers) {
  const el = document.getElementById('dlv-drivers');
  const statusColor = {available:'#27ae60',busy:'#e84c2b',offline:'#666'};
  const vehicleIcon = {motorbike:'🛵',bicycle:'🚲',car:'🚗',foot:'🚶'};
  el.innerHTML = drivers.map(d => `
    <div style="background:var(--surface2);border-radius:10px;padding:.6rem 1rem;display:flex;align-items:center;gap:.5rem;min-width:140px">
      <span style="width:8px;height:8px;border-radius:50%;background:${statusColor[d.status]}"></span>
      <span>${vehicleIcon[d.vehicle]||'🛵'}</span>
      <span style="font-weight:600;font-size:.85rem">${escHtml(d.name)}</span>
      <span style="font-size:.72rem;color:var(--text-muted)">${d.active_orders>0?'('+d.active_orders+')':''}</span>
    </div>
  `).join('');
}

function dlvRenderPending(orders) {
  const el = document.getElementById('dlv-pending');
  if (!orders.length) { el.innerHTML = '<div style="padding:2rem;text-align:center;color:var(--text-muted)">No pending deliveries</div>'; return; }
  const availDrivers = _dlvDrivers.filter(d => d.status === 'available' || d.status === 'busy');
  const driverOpts = availDrivers.map(d => `<option value="${d.id}">${escHtml(d.name)}</option>`).join('');
  el.innerHTML = orders.map(o => `
    <div style="background:var(--surface2);border-radius:10px;padding:.8rem;margin-bottom:.6rem">
      <div style="display:flex;justify-content:space-between;margin-bottom:.4rem">
        <span style="font-weight:700">NH-${String(o.id).padStart(6,'0')}</span>
        <span style="font-weight:600;color:var(--green)">${Number(o.total_amount).toLocaleString()} MMK</span>
      </div>
      <div style="font-size:.85rem;font-weight:600">${escHtml(o.customer_name)}</div>
      <div style="font-size:.8rem;color:var(--text-muted)">${escHtml(o.customer_phone)} · ${o.payment_method}</div>
      <div style="display:flex;gap:.5rem;margin-top:.6rem;align-items:center">
        <select id="dlv-assign-${o.id}" style="flex:1;padding:.4rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text);font-size:.82rem">
          <option value="">Select driver</option>${driverOpts}
        </select>
        <button class="btn btn-primary btn-sm" onclick="dlvAssign(${o.id})" style="padding:.4rem .8rem;font-size:.82rem">Assign</button>
      </div>
    </div>
  `).join('');
}

function dlvRenderActive(deliveries) {
  const el = document.getElementById('dlv-active');
  if (!deliveries.length) { el.innerHTML = '<div style="padding:2rem;text-align:center;color:var(--text-muted)">No active deliveries</div>'; return; }
  const statusBadge = {assigned:'📋 Assigned',picked_up:'📦 Picked Up',on_the_way:'🛵 On The Way'};
  const statusColor = {assigned:'#f39c12',picked_up:'#e84c2b',on_the_way:'#3498db'};
  el.innerHTML = deliveries.map(d => `
    <div style="background:var(--surface2);border-radius:10px;padding:.8rem;margin-bottom:.6rem;border-left:3px solid ${statusColor[d.status]||'var(--border)'}">
      <div style="display:flex;justify-content:space-between;margin-bottom:.3rem">
        <span style="font-weight:700">NH-${String(d.order_id).padStart(6,'0')}</span>
        <span style="font-size:.78rem;color:${statusColor[d.status]};font-weight:600">${statusBadge[d.status]||d.status}</span>
      </div>
      <div style="font-size:.85rem">${escHtml(d.customer_name)} → <strong>${escHtml(d.driver_name)}</strong> ${d.vehicle==='motorbike'?'🛵':'🚲'}</div>
      <div style="font-size:.78rem;color:var(--text-muted)">${Number(d.total_amount).toLocaleString()} MMK · ${d.payment_method}</div>
    </div>
  `).join('');
}

async function dlvAssign(orderId) {
  const sel = document.getElementById('dlv-assign-' + orderId);
  const driverId = parseInt(sel?.value);
  if (!driverId) { toast('Driver ရွေးပါ','err'); return; }
  try {
    const r = await fetch('delivery_api.php?action=assign', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({order_id: orderId, driver_id: driverId})
    });
    const d = await r.json();
    if (!d.ok) throw new Error(d.msg);
    toast(`✅ Assigned to ${d.driver_name}`);
    dlvLoad();
  } catch(e) { toast('❌ '+e.message,'err'); }
}

/* ═══════════════════════════════════════
   BRANCHES
═══════════════════════════════════════ */
async function branchLoad() {
  try {
    const r = await fetch('branch_api.php?action=dashboard');
    const d = await r.json();
    if (!d.ok) throw new Error(d.msg);
    branchRenderDashboard(d);
    branchRenderList(d.branches);
  } catch(e) {
    document.getElementById('branch-dashboard').innerHTML =
      `<div class="card" style="padding:2rem;color:#e74c3c;text-align:center">${e.message}</div>`;
  }
}

function branchRenderDashboard(d) {
  const g = d.grand;
  document.getElementById('branch-dashboard').innerHTML = `
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem">
      <div class="card" style="padding:1rem;text-align:center">
        <div style="font-size:1.5rem;font-weight:700">${d.branches.length}</div>
        <div style="font-size:.78rem;color:var(--text-muted)">🏢 Total Branches</div>
      </div>
      <div class="card" style="padding:1rem;text-align:center">
        <div style="font-size:1.3rem;font-weight:700">${Number(g.total_orders).toLocaleString()}</div>
        <div style="font-size:.78rem;color:var(--text-muted)">📋 All-time Orders</div>
      </div>
      <div class="card" style="padding:1rem;text-align:center">
        <div style="font-size:1.3rem;font-weight:700">${Number(g.today_orders).toLocaleString()}</div>
        <div style="font-size:.78rem;color:var(--text-muted)">📋 Today Orders</div>
      </div>
      <div class="card" style="padding:1rem;text-align:center">
        <div style="font-size:1.1rem;font-weight:700">${Number(g.today_revenue).toLocaleString()}</div>
        <div style="font-size:.78rem;color:var(--text-muted)">💰 Today Revenue</div>
      </div>
    </div>`;
}

function branchRenderList(branches) {
  const el = document.getElementById('branch-list');
  el.innerHTML = branches.map(b => {
    const active = parseInt(b.is_active);
    return `
    <div class="card" style="padding:1.2rem;${!active?'opacity:.6':''}">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
        <div>
          <div style="font-weight:700;font-size:1.05rem">${escHtml(b.name)}</div>
          <div style="font-size:.82rem;color:var(--text-muted)">📍 ${escHtml(b.code)} ${b.address?'· '+escHtml(b.address):''}</div>
        </div>
        <span style="background:${active?'#27ae60':'#e74c3c'};color:#fff;padding:.2rem .6rem;border-radius:12px;font-size:.72rem;font-weight:700">
          ${active?'Active':'Inactive'}
        </span>
      </div>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.5rem;margin-bottom:1rem">
        <div style="background:var(--surface2);border-radius:8px;padding:.6rem;text-align:center">
          <div style="font-weight:700">${b.today_orders || 0}</div>
          <div style="font-size:.72rem;color:var(--text-muted)">Today</div>
        </div>
        <div style="background:var(--surface2);border-radius:8px;padding:.6rem;text-align:center">
          <div style="font-weight:700">${Number(b.today_revenue || 0).toLocaleString()}</div>
          <div style="font-size:.72rem;color:var(--text-muted)">Revenue</div>
        </div>
        <div style="background:var(--surface2);border-radius:8px;padding:.6rem;text-align:center">
          <div style="font-weight:700">${b.total_staff || 0} / ${b.total_menu || 0}</div>
          <div style="font-size:.72rem;color:var(--text-muted)">Staff/Menu</div>
        </div>
      </div>
      <div style="display:flex;gap:.5rem">
        <button class="btn btn-ghost btn-sm" onclick="branchEdit(${b.id})">✏️ Edit</button>
        ${b.id > 1 ? `<button class="btn btn-ghost btn-sm" onclick="branchToggle(${b.id})">${active?'🚫 Deactivate':'✅ Activate'}</button>` : ''}
      </div>
    </div>`;
  }).join('');
}

function branchOpenNew() {
  document.getElementById('branch-modal').style.display = 'block';
  document.getElementById('branch-modal-title').textContent = '🏢 New Branch';
  document.getElementById('branch-edit-id').value = '';
  document.getElementById('branch-name').value = '';
  document.getElementById('branch-code').value = '';
  document.getElementById('branch-address').value = '';
  document.getElementById('branch-phone').value = '';
  document.getElementById('branch-open').value = '10:00';
  document.getElementById('branch-close').value = '23:00';
}

async function branchEdit(id) {
  try {
    const r = await fetch(`branch_api.php?action=detail&id=${id}`);
    const d = await r.json();
    if (!d.ok) throw new Error(d.msg);
    const b = d.branch;
    document.getElementById('branch-modal').style.display = 'block';
    document.getElementById('branch-modal-title').textContent = '✏️ Edit Branch';
    document.getElementById('branch-edit-id').value = b.id;
    document.getElementById('branch-name').value = b.name;
    document.getElementById('branch-code').value = b.code;
    document.getElementById('branch-code').disabled = true;
    document.getElementById('branch-address').value = b.address || '';
    document.getElementById('branch-phone').value = b.phone || '';
    document.getElementById('branch-open').value = (b.opening_time || '10:00').slice(0,5);
    document.getElementById('branch-close').value = (b.closing_time || '23:00').slice(0,5);
  } catch(e) { toast('❌ '+e.message,'err'); }
}

async function branchSave() {
  const editId = document.getElementById('branch-edit-id').value;
  const data = {
    name: document.getElementById('branch-name').value.trim(),
    code: document.getElementById('branch-code').value.trim().toUpperCase(),
    address: document.getElementById('branch-address').value.trim(),
    phone: document.getElementById('branch-phone').value.trim(),
    opening_time: document.getElementById('branch-open').value,
    closing_time: document.getElementById('branch-close').value,
  };
  if (!data.name || !data.code) { toast('Name and code required','err'); return; }

  try {
    const action = editId ? 'update' : 'create';
    if (editId) data.id = parseInt(editId);
    const r = await fetch(`branch_api.php?action=${action}`, {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify(data)
    });
    const d = await r.json();
    if (!d.ok) throw new Error(d.msg);
    toast('✅ Branch saved');
    document.getElementById('branch-modal').style.display = 'none';
    document.getElementById('branch-code').disabled = false;
    branchLoad();
  } catch(e) { toast('❌ '+e.message,'err'); }
}

async function branchToggle(id) {
  if (!confirm('Branch status ပြောင်းမည် — သေချာပါသလား?')) return;
  try {
    const r = await fetch('branch_api.php?action=toggle', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({id})
    });
    const d = await r.json();
    if (!d.ok) throw new Error(d.msg);
    toast('✅ Updated');
    branchLoad();
  } catch(e) { toast('❌ '+e.message,'err'); }
}

/* ═══════════════════════════════════════
   RESERVATIONS
═══════════════════════════════════════ */
let _resTables = [];

function resToday() { return new Date().toISOString().slice(0,10); }

async function resLoad() {
  const dateEl = document.getElementById('res-date');
  if (!dateEl.value) dateEl.value = resToday();
  const date   = dateEl.value;
  const status = document.getElementById('res-status-filter')?.value || '';

  const tbody = document.getElementById('res-tbody');
  tbody.innerHTML = '<tr><td colspan="7" style="padding:2rem;text-align:center;color:var(--text-muted)">Loading...</td></tr>';

  try {
    const params = new URLSearchParams({action:'list', date, per:50});
    if (status) params.set('status', status);
    const r = await fetch('reservation_api.php?' + params);
    const d = await r.json();
    if (!d.ok) throw new Error(d.msg);

    document.getElementById('res-count').textContent = `${d.total} reservations`;
    resRenderTable(d.reservations);

    // Load tables for modal
    const t = await fetch('reservation_api.php?action=today');
    const td = await t.json();
    if (td.ok) _resTables = td.tables || [];
  } catch(e) {
    tbody.innerHTML = `<tr><td colspan="7" style="padding:2rem;text-align:center;color:#e74c3c">${e.message}</td></tr>`;
  }
}

function resRenderTable(rows) {
  const tbody = document.getElementById('res-tbody');
  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="7" style="padding:2rem;text-align:center;color:var(--text-muted)">No reservations</td></tr>';
    return;
  }
  const badge = {
    pending:'⏳ Pending', confirmed:'✅ Confirmed', seated:'🪑 Seated',
    completed:'✔️ Done', cancelled:'❌ Cancelled', no_show:'👻 No Show'
  };
  const badgeColor = {
    pending:'#f39c12', confirmed:'#27ae60', seated:'#3498db',
    completed:'var(--text-muted)', cancelled:'#e74c3c', no_show:'#95a5a6'
  };

  tbody.innerHTML = rows.map(r => `
    <tr style="border-bottom:1px solid var(--border)">
      <td style="padding:.7rem 1rem;font-weight:700;font-size:.95rem">${r.reservation_time.slice(0,5)}</td>
      <td style="padding:.7rem 1rem">
        <div style="font-weight:600">${escHtml(r.customer_name)}</div>
        <div style="font-size:.78rem;color:var(--text-muted)">${escHtml(r.customer_phone)}</div>
      </td>
      <td style="padding:.7rem 1rem;text-align:center;font-weight:600">${r.party_size}👤</td>
      <td style="padding:.7rem 1rem">${r.table_code ? '🪑 '+escHtml(r.table_code) : '<span style="color:var(--text-muted)">Auto</span>'}</td>
      <td style="padding:.7rem 1rem;text-align:center">
        <span style="color:${badgeColor[r.status]};font-size:.82rem;font-weight:600">${badge[r.status]||r.status}</span>
      </td>
      <td style="padding:.7rem 1rem;font-size:.82rem;color:var(--text-muted)">${escHtml(r.notes||'')}</td>
      <td style="padding:.7rem 1rem;text-align:center">
        <select onchange="resUpdateStatus(${r.id},this.value)" style="padding:.3rem .5rem;border-radius:6px;border:1px solid var(--border);background:var(--surface);color:var(--text);font-size:.8rem">
          ${['pending','confirmed','seated','completed','cancelled','no_show'].map(s =>
            `<option value="${s}" ${s===r.status?'selected':''}>${s}</option>`
          ).join('')}
        </select>
      </td>
    </tr>
  `).join('');
}

function resOpenNew() {
  document.getElementById('res-modal').style.display = 'block';
  document.getElementById('res-modal-title').textContent = '📅 New Reservation';
  document.getElementById('res-name').value = '';
  document.getElementById('res-phone').value = '';
  document.getElementById('res-date-input').value = document.getElementById('res-date').value || resToday();
  document.getElementById('res-time').value = '';
  document.getElementById('res-party').value = '2';
  document.getElementById('res-duration').value = '90';
  document.getElementById('res-notes').value = '';

  // Populate tables dropdown
  const sel = document.getElementById('res-table');
  sel.innerHTML = '<option value="">Auto-assign</option>' +
    _resTables.map(t => `<option value="${escHtml(t.table_code)}">🪑 ${escHtml(t.table_code)} (${t.seats} seats)</option>`).join('');
}

async function resCreate() {
  const name  = document.getElementById('res-name').value.trim();
  const phone = document.getElementById('res-phone').value.trim();
  const date  = document.getElementById('res-date-input').value;
  const time  = document.getElementById('res-time').value;
  const party = parseInt(document.getElementById('res-party').value) || 2;
  const table = document.getElementById('res-table').value;
  const dur   = parseInt(document.getElementById('res-duration').value) || 90;
  const notes = document.getElementById('res-notes').value.trim();

  if (!name || !phone) { toast('Name and phone required','err'); return; }
  if (!date || !time) { toast('Date and time required','err'); return; }

  try {
    const r = await fetch('reservation_api.php?action=create', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({
        customer_name:name, customer_phone:phone, party_size:party,
        table_code:table||null, reservation_date:date, reservation_time:time,
        duration_min:dur, notes:notes||null
      })
    });
    const d = await r.json();
    if (!d.ok) throw new Error(d.msg);
    toast('✅ Reservation created');
    document.getElementById('res-modal').style.display = 'none';
    resLoad();
  } catch(e) { toast('❌ '+e.message,'err'); }
}

async function resUpdateStatus(id, status) {
  try {
    const r = await fetch('reservation_api.php?action=update_status', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({id, status})
    });
    const d = await r.json();
    if (!d.ok) throw new Error(d.msg);
    toast('✅ Status updated');
  } catch(e) { toast('❌ '+e.message,'err'); resLoad(); }
}

/* ═══════════════════════════════════════
   STOCK MANAGEMENT
═══════════════════════════════════════ */
let _stockItems = [];

async function stockLoad() {
  try {
    const r = await fetch('stock_api.php?action=overview');
    const d = await r.json();
    if (!d.ok) throw new Error(d.msg);
    _stockItems = d.items;
    stockRenderSummary(d.summary);
    stockRenderTable(d.items);
    stockLoadLog();
  } catch(e) {
    document.getElementById('stock-tbody').innerHTML =
      `<tr><td colspan="5" style="padding:2rem;text-align:center;color:#e74c3c">${e.message}</td></tr>`;
  }
}

function stockRenderSummary(s) {
  document.getElementById('stock-summary').innerHTML = `
    <div class="card" style="padding:1rem;text-align:center">
      <div style="font-size:1.5rem;font-weight:700">${s.total_items}</div>
      <div style="font-size:.78rem;color:var(--text-muted)">Total Items</div>
    </div>
    <div class="card" style="padding:1rem;text-align:center">
      <div style="font-size:1.5rem;font-weight:700">${Number(s.total_stock).toLocaleString()}</div>
      <div style="font-size:.78rem;color:var(--text-muted)">Total Stock</div>
    </div>
    <div class="card" style="padding:1rem;text-align:center">
      <div style="font-size:1.5rem;font-weight:700;color:${s.low_count?'#f39c12':'var(--text)'}">${s.low_count}</div>
      <div style="font-size:.78rem;color:var(--text-muted)">⚠️ Low Stock</div>
    </div>
    <div class="card" style="padding:1rem;text-align:center">
      <div style="font-size:1.5rem;font-weight:700;color:${s.out_count?'#e74c3c':'var(--text)'}">${s.out_count}</div>
      <div style="font-size:.78rem;color:var(--text-muted)">🚫 Out of Stock</div>
    </div>`;
}

function stockRenderTable(items) {
  const tbody = document.getElementById('stock-tbody');
  if (!items.length) {
    tbody.innerHTML = '<tr><td colspan="5" style="padding:2rem;text-align:center;color:var(--text-muted)">No items</td></tr>';
    return;
  }
  tbody.innerHTML = items.map(i => {
    const qty = parseInt(i.stock_qty);
    let badge, color;
    if (qty <= 0) { badge = '🚫 Out'; color = '#e74c3c'; }
    else if (qty <= 10) { badge = '⚠️ Low'; color = '#f39c12'; }
    else { badge = '✅ OK'; color = '#27ae60'; }
    return `<tr style="border-bottom:1px solid var(--border)" data-name="${escHtml(i.name).toLowerCase()}">
      <td style="padding:.7rem 1rem"><span style="margin-right:.4rem">${i.emoji||'🍽️'}</span><strong>${escHtml(i.name)}</strong></td>
      <td style="padding:.7rem 1rem;color:var(--text-muted);font-size:.82rem">${escHtml(i.category||'')}</td>
      <td style="padding:.7rem 1rem;text-align:right;font-weight:700;font-size:1rem">${qty}</td>
      <td style="padding:.7rem 1rem;text-align:center"><span style="color:${color};font-size:.82rem;font-weight:600">${badge}</span></td>
      <td style="padding:.7rem 1rem;text-align:center">
        <button class="btn btn-ghost btn-sm" onclick="stockOpenAdj(${i.id},'${escHtml(i.name)}',${qty})">Adjust</button>
      </td>
    </tr>`;
  }).join('');
}

function stockFilter() {
  const q = document.getElementById('stock-search').value.toLowerCase();
  document.querySelectorAll('#stock-tbody tr[data-name]').forEach(tr => {
    tr.style.display = tr.dataset.name.includes(q) ? '' : 'none';
  });
}

function stockOpenAdj(id, name, currentQty) {
  document.getElementById('stock-modal').style.display = 'block';
  document.getElementById('stock-modal-title').textContent = `📦 ${name} (current: ${currentQty})`;
  document.getElementById('stock-adj-id').value = id;
  document.getElementById('stock-adj-qty').value = '';
  document.getElementById('stock-adj-note').value = '';
  document.getElementById('stock-adj-reason').value = 'restock';
}

async function stockDoAdjust() {
  const itemId = parseInt(document.getElementById('stock-adj-id').value);
  const qty    = parseInt(document.getElementById('stock-adj-qty').value);
  const reason = document.getElementById('stock-adj-reason').value;
  const note   = document.getElementById('stock-adj-note').value.trim();
  if (!itemId || !qty || qty === 0) { toast('Quantity ထည့်ပါ','err'); return; }
  try {
    const r = await fetch('stock_api.php?action=adjust', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({item_id: itemId, change_qty: qty, reason, note, staff_name:'Admin'})
    });
    const d = await r.json();
    if (!d.ok) throw new Error(d.msg);
    toast(`✅ ${d.item_name}: ${d.old_qty} → ${d.new_qty}`);
    document.getElementById('stock-modal').style.display = 'none';
    stockLoad();
  } catch(e) { toast('❌ '+e.message,'err'); }
}

async function stockLoadLog() {
  const tbody = document.getElementById('stock-log-tbody');
  try {
    const r = await fetch('stock_api.php?action=log&per=15');
    const d = await r.json();
    if (!d.ok) throw new Error(d.msg);
    if (!d.logs.length) {
      tbody.innerHTML = '<tr><td colspan="6" style="padding:1.5rem;text-align:center;color:var(--text-muted)">No changes yet</td></tr>';
      return;
    }
    const reasonLabel = {restock:'📥 Restock',manual_adjust:'✏️ Adjust',order_deduct:'🛒 Order',waste:'🗑 Waste',correction:'🔧 Fix',returned:'↩ Return'};
    tbody.innerHTML = d.logs.map(l => {
      const isNeg = l.change_qty < 0;
      return `<tr style="border-bottom:1px solid var(--border)">
        <td style="padding:.5rem 1rem;font-size:.8rem;color:var(--text-muted)">${l.created_at.slice(5,16)}</td>
        <td style="padding:.5rem 1rem">${l.emoji||'🍽️'} ${escHtml(l.item_name)}</td>
        <td style="padding:.5rem 1rem;text-align:right;font-weight:700;color:${isNeg?'#e74c3c':'#27ae60'}">${isNeg?'':'+'}<span>${l.change_qty}</span></td>
        <td style="padding:.5rem 1rem;text-align:right">${l.new_qty}</td>
        <td style="padding:.5rem 1rem;font-size:.82rem">${reasonLabel[l.reason]||l.reason}</td>
        <td style="padding:.5rem 1rem;font-size:.8rem;color:var(--text-muted)">${escHtml(l.note ? l.note : (l.order_id ? 'Order #'+l.order_id : ''))}</td>
      </tr>`;
    }).join('');
  } catch(e) {}
}

/* ═══════════════════════════════════════
   SHIFT MANAGEMENT
═══════════════════════════════════════ */
let _currentShiftId = null;

async function shiftLoad() {
  await shiftLoadStatus();
  await shiftLoadHistory();
}

async function shiftLoadStatus() {
  const body = document.getElementById('shift-status-body');
  try {
    const r = await fetch('shift_api.php?action=current');
    const d = await r.json();
    if (!d.ok) throw new Error(d.msg);

    if (!d.is_open) {
      _currentShiftId = null;
      document.getElementById('shift-open-form').style.display  = '';
      document.getElementById('shift-close-form').style.display = 'none';
      body.innerHTML = `
        <div style="text-align:center;padding:1.5rem">
          <div style="font-size:2rem;margin-bottom:.5rem">🔴</div>
          <div style="font-size:1.1rem;font-weight:700;color:var(--text-muted)">No Active Shift</div>
          <div style="font-size:.85rem;color:var(--text-muted);margin-top:.3rem">Open a shift to start tracking orders</div>
        </div>`;
      return;
    }

    _currentShiftId = d.shift.id;
    document.getElementById('shift-open-form').style.display  = 'none';
    document.getElementById('shift-close-form').style.display = '';

    const s   = d.stats;
    const sh  = d.shift;
    const dur = shiftDuration(sh.opened_at);

    body.innerHTML = `
      <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.2rem;flex-wrap:wrap">
        <div style="width:48px;height:48px;border-radius:50%;background:#27ae60;display:flex;align-items:center;justify-content:center;font-size:1.3rem">🟢</div>
        <div>
          <div style="font-size:1.1rem;font-weight:700">Shift #${sh.id} — ${escHtml(sh.staff_name)}</div>
          <div style="color:var(--text-muted);font-size:.85rem">Opened ${sh.opened_at.slice(0,16)} · ${dur}</div>
        </div>
        <div style="margin-left:auto;font-size:.85rem;color:var(--text-muted)">
          Opening cash: <strong>${Number(sh.opening_cash).toLocaleString()} MMK</strong>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem">
        <div style="background:var(--surface2);border-radius:10px;padding:1rem;text-align:center">
          <div style="font-size:1.5rem;font-weight:700">${s.total_orders}</div>
          <div style="font-size:.76rem;color:var(--text-muted)">Orders</div>
        </div>
        <div style="background:var(--surface2);border-radius:10px;padding:1rem;text-align:center">
          <div style="font-size:1.1rem;font-weight:700">${Number(s.total_revenue).toLocaleString()}</div>
          <div style="font-size:.76rem;color:var(--text-muted)">Total MMK</div>
        </div>
        <div style="background:var(--surface2);border-radius:10px;padding:1rem;text-align:center">
          <div style="font-size:1.1rem;font-weight:700">${Number(s.cash_revenue).toLocaleString()}</div>
          <div style="font-size:.76rem;color:var(--text-muted)">Cash</div>
        </div>
        <div style="background:var(--surface2);border-radius:10px;padding:1rem;text-align:center">
          <div style="font-size:1.1rem;font-weight:700">${Number(s.digital_revenue).toLocaleString()}</div>
          <div style="font-size:.76rem;color:var(--text-muted)">Digital</div>
        </div>
      </div>`;
  } catch(e) {
    body.innerHTML = `<div style="color:#e74c3c;padding:1rem">${e.message}</div>`;
  }
}

async function shiftLoadHistory() {
  const tbody = document.getElementById('shift-history-tbody');
  try {
    const r = await fetch('shift_api.php?action=history&per=20');
    const d = await r.json();
    if (!d.ok) throw new Error(d.msg);
    if (!d.shifts.length) {
      tbody.innerHTML = '<tr><td colspan="8" style="padding:2rem;text-align:center;color:var(--text-muted)">No shifts yet</td></tr>';
      return;
    }
    tbody.innerHTML = d.shifts.map(s => {
      const dur   = s.duration_min ? shiftFmtDur(s.duration_min) : '—';
      const diff  = s.cash_difference !== null
        ? `<span style="color:${s.cash_difference>=0?'#27ae60':'#e74c3c'}">${s.cash_difference>=0?'+':''}${Number(s.cash_difference).toLocaleString()}</span>`
        : '—';
      const badge = s.status==='open'
        ? '<span style="background:#27ae60;color:#fff;padding:.2rem .6rem;border-radius:20px;font-size:.75rem">🟢 Open</span>'
        : '<span style="background:var(--surface2);color:var(--text-muted);padding:.2rem .6rem;border-radius:20px;font-size:.75rem">Closed</span>';
      return `<tr style="border-bottom:1px solid var(--border)">
        <td style="padding:.7rem 1rem;font-weight:600">${escHtml(s.staff_name)}</td>
        <td style="padding:.7rem 1rem;font-size:.82rem">${s.opened_at.slice(0,16)}</td>
        <td style="padding:.7rem 1rem;font-size:.82rem">${dur}</td>
        <td style="padding:.7rem 1rem;text-align:right">${s.total_orders}</td>
        <td style="padding:.7rem 1rem;text-align:right">${Number(s.total_revenue).toLocaleString()}</td>
        <td style="padding:.7rem 1rem;text-align:right">${diff}</td>
        <td style="padding:.7rem 1rem;text-align:center">${badge}</td>
        <td style="padding:.7rem 1rem;text-align:center">
          <button class="btn btn-ghost btn-sm" onclick="shiftDetail(${s.id})">View</button>
        </td>
      </tr>`;
    }).join('');
  } catch(e) {
    tbody.innerHTML = `<tr><td colspan="8" style="padding:2rem;text-align:center;color:#e74c3c">${e.message}</td></tr>`;
  }
}

async function shiftOpen() {
  const pin  = document.getElementById('shift-pin').value.trim();
  const cash = parseInt(document.getElementById('shift-opening-cash').value) || 0;
  if (!pin) { toast('PIN ထည့်ပါ','err'); return; }
  try {
    const r = await fetch('shift_api.php?action=open', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({pin, opening_cash: cash})
    });
    const d = await r.json();
    if (!d.ok) throw new Error(d.msg);
    document.getElementById('shift-pin').value = '';
    document.getElementById('shift-opening-cash').value = '';
    toast(`✅ Shift opened — ${d.staff_name}`);
    shiftLoad();
  } catch(e) { toast('❌ '+e.message,'err'); }
}

async function shiftClose() {
  if (!_currentShiftId) { toast('No open shift','err'); return; }
  const cash  = parseInt(document.getElementById('shift-closing-cash').value) || 0;
  const notes = document.getElementById('shift-close-notes').value.trim();
  if (!confirm('Shift ပိတ်မည် — သေချာပါသလား?')) return;
  try {
    const r = await fetch('shift_api.php?action=close', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({shift_id: _currentShiftId, closing_cash: cash, notes})
    });
    const d = await r.json();
    if (!d.ok) throw new Error(d.msg);
    const diff = d.cash_diff;
    toast(`✅ Shift closed · Cash diff: ${diff>=0?'+':''}${Number(diff).toLocaleString()} MMK`);
    document.getElementById('shift-closing-cash').value = '';
    document.getElementById('shift-close-notes').value  = '';
    shiftLoad();
  } catch(e) { toast('❌ '+e.message,'err'); }
}

async function shiftDetail(id) {
  const modal = document.getElementById('shift-modal');
  const body  = document.getElementById('shift-modal-body');
  modal.style.display = 'block';
  body.innerHTML = '<div style="text-align:center;padding:2rem;color:var(--text-muted)">Loading...</div>';
  try {
    const r = await fetch(`shift_api.php?action=detail&shift_id=${id}`);
    const d = await r.json();
    if (!d.ok) throw new Error(d.msg);
    const sh = d.shift;
    const s  = d.stats;
    const diff = sh.cash_difference !== null
      ? `<span style="color:${sh.cash_difference>=0?'#27ae60':'#e74c3c'};font-weight:700">${sh.cash_difference>=0?'+':''}${Number(sh.cash_difference).toLocaleString()} MMK</span>`
      : '—';
    body.innerHTML = `
      <div style="font-size:1.2rem;font-weight:700;margin-bottom:1.2rem">
        Shift #${sh.id} — ${escHtml(sh.staff_name)}
        <span style="font-size:.85rem;font-weight:400;color:var(--text-muted);margin-left:.5rem">${sh.status}</span>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:.8rem;margin-bottom:1.2rem">
        <div style="background:var(--surface2);border-radius:8px;padding:.8rem">
          <div style="font-size:.76rem;color:var(--text-muted)">Opened</div>
          <div style="font-weight:600">${sh.opened_at.slice(0,16)}</div>
        </div>
        <div style="background:var(--surface2);border-radius:8px;padding:.8rem">
          <div style="font-size:.76rem;color:var(--text-muted)">Closed</div>
          <div style="font-weight:600">${sh.closed_at ? sh.closed_at.slice(0,16) : '—'}</div>
        </div>
        <div style="background:var(--surface2);border-radius:8px;padding:.8rem">
          <div style="font-size:.76rem;color:var(--text-muted)">Opening Cash</div>
          <div style="font-weight:600">${Number(sh.opening_cash).toLocaleString()} MMK</div>
        </div>
        <div style="background:var(--surface2);border-radius:8px;padding:.8rem">
          <div style="font-size:.76rem;color:var(--text-muted)">Closing Cash</div>
          <div style="font-weight:600">${sh.closing_cash !== null ? Number(sh.closing_cash).toLocaleString()+' MMK' : '—'}</div>
        </div>
        <div style="background:var(--surface2);border-radius:8px;padding:.8rem">
          <div style="font-size:.76rem;color:var(--text-muted)">Total Revenue</div>
          <div style="font-weight:600">${Number(s.total_revenue).toLocaleString()} MMK</div>
        </div>
        <div style="background:var(--surface2);border-radius:8px;padding:.8rem">
          <div style="font-size:.76rem;color:var(--text-muted)">Cash Difference</div>
          <div>${diff}</div>
        </div>
      </div>
      ${d.orders.length ? `
      <div style="font-weight:600;margin-bottom:.6rem">📋 Orders (${d.orders.length})</div>
      <div style="max-height:260px;overflow-y:auto">
        ${d.orders.map(o=>`
          <div style="border-bottom:1px solid var(--border);padding:.5rem 0;font-size:.83rem;display:flex;justify-content:space-between;gap:.5rem">
            <div>
              <span style="font-weight:600">NH-${String(o.id).padStart(6,'0')}</span>
              <span style="color:var(--text-muted);margin-left:.5rem">${escHtml(o.items||'')}</span>
            </div>
            <div style="white-space:nowrap">${Number(o.total_amount).toLocaleString()} MMK · ${escHtml(o.payment_method)}</div>
          </div>`).join('')}
      </div>` : '<div style="color:var(--text-muted);font-size:.85rem">No orders in this shift</div>'}
      ${sh.notes ? `<div style="margin-top:1rem;padding:.8rem;background:var(--surface2);border-radius:8px;font-size:.85rem"><strong>Notes:</strong> ${escHtml(sh.notes)}</div>` : ''}
    `;
  } catch(e) {
    body.innerHTML = `<div style="color:#e74c3c;padding:1rem">${e.message}</div>`;
  }
}

function shiftDuration(openedAt) {
  const mins = Math.floor((Date.now() - new Date(openedAt)) / 60000);
  return shiftFmtDur(mins);
}
function shiftFmtDur(mins) {
  if (mins < 60) return `${mins}m`;
  return `${Math.floor(mins/60)}h ${mins%60}m`;
}

