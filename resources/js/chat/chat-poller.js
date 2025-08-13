// Conversations poller module
import { POLL_INTERVAL_MS } from './chat-constants.js';

export function startConversationsPoller(ctx) {
  if (ctx._convPoller) return;
  ctx._convPoller = setInterval(async () => {
    try {
      const resp = await window.axios.get('/conversations');
      const data = resp.data?.data ?? resp.data ?? [];
      const incoming = (Array.isArray(data) ? data : Object.values(data));
      const map = new Map(incoming.map(c => [Number(c.id), c]));
      let changed = false;
      const updated = ctx.conversations.map(c => {
        const fresh = map.get(Number(c.id));
        if (!fresh) return c;
        if (!ctx.selectedConversation || Number(ctx.selectedConversation.id) !== Number(c.id)) {
          const next = {
            ...c,
            last_message: fresh.last_message ?? c.last_message,
            unread_count: Number(fresh.unread_count || 0)
          };
          if (next.unread_count !== c.unread_count || (next.last_message?.created_at !== c.last_message?.created_at)) {
            changed = true;
            return next;
          }
          return c;
        }
        return c;
      });
      if (changed) {
        ctx.conversations = [...updated].sort((a, b) => new Date(b.last_message?.created_at || 0) - new Date(a.last_message?.created_at || 0));
      }
    } catch (e) {
      try { console && console.warn('[Poller] conversations refresh failed', e?.message || e); } catch(_) {}
    }
  }, POLL_INTERVAL_MS);
}
