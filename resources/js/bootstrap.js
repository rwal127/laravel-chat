import axios from 'axios';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
// Only initialize Pusher/Echo on the /chat route
const isChatRoute = (() => {
  try {
    const path = window.location?.pathname || '';
    return /^\/(chat)(\/.*)?$/.test(path);
  } catch (_) { return false; }
})();

if (isChatRoute) {
  window.Pusher = Pusher;
  const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  const pusherClient = new Pusher(import.meta.env.VITE_PUSHER_APP_KEY, {
    cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER ?? 'mt1',
    forceTLS: (import.meta.env.VITE_PUSHER_SCHEME ?? 'https') === 'https',
    channelAuthorization: {
      endpoint: '/broadcasting/auth',
      transport: 'ajax',
      params: {},
      headers: { 'X-CSRF-TOKEN': csrf },
    },
    userAuthentication: {
      endpoint: '/pusher/user-auth',
      transport: 'ajax',
      params: {},
      headers: { 'X-CSRF-TOKEN': csrf },
    },
  });

  window.Echo = new Echo({
    broadcaster: 'pusher',
    client: pusherClient,
    authEndpoint: '/broadcasting/auth',
  });

  try {
    window.OnlineUsers = window.OnlineUsers || new Set();
    const p = pusherClient;
    if (typeof p.signin === 'function') { p.signin(); }
    try { p.user?.bind && p.user.bind('signed_in', () => {}); } catch (_) {}
    try {
      p.connection?.bind && p.connection.bind('error', () => {});
      p.connection?.bind && p.connection.bind('connected', () => {
        try { window.refreshWatchlist && window.refreshWatchlist(); } catch (_) {}
      });
      p.connection?.bind && p.connection.bind('state_change', (states) => {
        const { current } = states || {};
        if (current === 'connected') {
          try { window.refreshWatchlist && window.refreshWatchlist(); } catch (_) {}
        }
      });
    } catch (_) {}
    const normalizeIds = (evt) => {
      try {
        if (Array.isArray(evt?.user_ids)) return evt.user_ids.map((v) => String(v));
        if (Array.isArray(evt?.users)) return evt.users.map((u) => String(u?.id)).filter(Boolean);
        if (Array.isArray(evt)) return evt.map((v) => String(v));
        return [];
      } catch (_) { return []; }
    };
    const applyOnline = (evt) => {
      const ids = normalizeIds(evt);
      ids.forEach((id) => window.OnlineUsers.add(String(id)));
      window.dispatchEvent(new CustomEvent('online:update'));
    };
    const applyOffline = (evt) => {
      const ids = normalizeIds(evt);
      ids.forEach((id) => window.OnlineUsers.delete(String(id)));
      window.dispatchEvent(new CustomEvent('online:update'));
    };
    try {
      p.user?.watchlist?.bind('online', (evt) => { applyOnline(evt); });
      p.user?.watchlist?.bind('offline', (evt) => { applyOffline(evt); });
    } catch (_) {}
    try {
      window.refreshWatchlist = () => { try { p.signin && p.signin(); } catch (_) {} };
    } catch (_) {}
  } catch (_) {}
} else {
  // Safe no-ops outside chat so other modules won't break if loaded globally
  try { window.Echo = null; } catch (_) {}
  try { window.OnlineUsers = window.OnlineUsers || new Set(); } catch (_) {}
  try { window.refreshWatchlist = () => {}; } catch (_) {}
}