import axios from 'axios';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.Pusher = Pusher;

// Build a Pusher client with userAuthentication for Watchlist
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
  // Keep channel auth for private/presence channels
  authEndpoint: '/broadcasting/auth',
  // withCredentials: true, // if using cookie auth
});

// Pusher User Authentication (Watchlist)
try {
  window.OnlineUsers = window.OnlineUsers || new Set();
  const p = pusherClient;
  if (typeof p.signin === 'function') {
    console.debug('[Pusher] calling signin()');
    p.signin();
  } else {
    console.warn('[Pusher] signin() not available on client');
  }
  try {
    p.user?.bind && p.user.bind('signed_in', () => {
      console.debug('[Pusher] user signed_in event fired');
    });
  } catch (_) {}
  try {
    p.connection?.bind && p.connection.bind('error', (err) => {
      console.error('[Pusher] connection error', err);
    });
  } catch (_) {}
  const applyOnline = (ids = []) => {
    ids.forEach((id) => window.OnlineUsers.add(String(id)));
    window.dispatchEvent(new CustomEvent('online:update'));
  };
  const applyOffline = (ids = []) => {
    ids.forEach((id) => window.OnlineUsers.delete(String(id)));
    window.dispatchEvent(new CustomEvent('online:update'));
  };
  try {
    p.user?.watchlist?.bind('online', (evt) => {
      const ids = Array.isArray(evt?.user_ids) ? evt.user_ids : [];
      console.debug('[Pusher] watchlist online', ids);
      applyOnline(ids);
    });
    p.user?.watchlist?.bind('offline', (evt) => {
      const ids = Array.isArray(evt?.user_ids) ? evt.user_ids : [];
      console.debug('[Pusher] watchlist offline', ids);
      applyOffline(ids);
    });
  } catch (_) {}
} catch (_) {}