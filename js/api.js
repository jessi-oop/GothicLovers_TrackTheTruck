/* API client — all backend calls go through here */

const BASE = window.location.pathname
  .replace(/\/(views|api)(\/.*)?$/, '')
  .replace(/\/$/, '');

const Api = {
  token: () => sessionStorage.getItem('fc_token'),
  user:  () => { try { return JSON.parse(sessionStorage.getItem('fc_user')); } catch { return null; } },

  async req(method, path, body = null) {
    const headers = { 'Content-Type': 'application/json' };
    if (this.token()) headers['Authorization'] = 'Bearer ' + this.token();
    const opts = { method, headers };
    if (body !== null) opts.body = JSON.stringify(body);

    let res;
    try { res = await fetch(BASE + path, opts); }
    catch { throw new Error('Network error. Is XAMPP running?'); }

    if (res.status === 401) {
      sessionStorage.clear();
      window.location.href = 'login.html';
      return null;
    }

    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw Object.assign(new Error(data.error || 'Request failed'), { status: res.status });
    return data;
  },

  // Auth
  login:  (u, p) => Api.req('POST', '/api/login',  { username: u, password: p }),
  logout: ()     => Api.req('POST', '/api/logout'),

  // Equipment
  listEquipment:   (status = '', site_id = '') => {
    const q = new URLSearchParams();
    if (status)  q.set('status',  status);
    if (site_id) q.set('site_id', site_id);
    const qs = q.toString();
    return Api.req('GET', '/api/equipment' + (qs ? '?' + qs : ''));
  },
  getEquipment:    id       => Api.req('GET',    `/api/equipment?id=${id}`),
  createEquipment: data     => Api.req('POST',   '/api/equipment', data),
  updateEquipment: (id, d)  => Api.req('PUT',    `/api/equipment?id=${id}`, d),
  deleteEquipment: id       => Api.req('DELETE', `/api/equipment?id=${id}`),

  // Sites
  listSites:   ()       => Api.req('GET',    '/api/sites'),
  getSite:     id       => Api.req('GET',    `/api/sites?id=${id}`),
  createSite:  data     => Api.req('POST',   '/api/sites', data),
  updateSite:  (id, d)  => Api.req('PUT',    `/api/sites?id=${id}`, d),
  deleteSite:  id       => Api.req('DELETE', `/api/sites?id=${id}`),

  // Checkout
  listCheckouts: (params = {}) => {
    const qs = new URLSearchParams(params).toString();
    return Api.req('GET', '/api/checkout' + (qs ? '?' + qs : ''));
  },
  checkout:  data         => Api.req('POST', '/api/checkout', data),
  returnKey: (id, notes)  => Api.req('PUT',  `/api/checkout?id=${id}`, { notes: notes || '' }),

  // Audit
  listAudit: (params = {}) => {
    const qs = new URLSearchParams(params).toString();
    return Api.req('GET', '/api/audit' + (qs ? '?' + qs : ''));
  },

  // Map
  mapPins: () => Api.req('GET', '/api/map?format=json'),
};
