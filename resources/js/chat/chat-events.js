// Echo/Pusher realtime event wiring extracted from chat-new.js

export function bindEchoConnectionHandlers(ctx) {
  try {
    const pusher = window.Echo?.connector?.pusher;
    if (!pusher || ctx._echoHandlersBound) return;
    ctx._echoHandlersBound = true;
    const conn = pusher.connection;
    if (window.Echo && window.Echo.connector && window.Echo.connector.pusher) {
      const pusherReal = window.Echo.connector.pusher;
      pusherReal.connection.bind('connected', () => {
        // Re-subscribe active conversation channel
        if (ctx.selectedConversation) {
          ctx.subscribeToConversationChannel();
        }
        // Ensure sidebar channels are active after reconnect
        ctx.subscribeSidebarChannels();
      });
      pusherReal.connection.bind('disconnected', () => {});
      pusherReal.connection.bind('error', () => {});
    }
    conn.bind('unavailable', () => {});
    conn.bind('failed', () => {});
    pusher.bind('error', () => {});
  } catch (_) {}
}

export function ensureEchoReady(ctx) {
  try {
    const ready = !!(window.Echo?.connector?.pusher);
    if (ready) {
      bindEchoConnectionHandlers(ctx);
      ctx.subscribeSidebarChannels();
      return;
    }
  } catch (_) {}
  clearTimeout(ctx._echoReadyTimer);
  ctx._echoReadyTimer = setTimeout(() => ensureEchoReady(ctx), 1000);
}

export function startSidebarWatchdog(ctx) {
  if (ctx._sidebarWatchdog) return;
  ctx._sidebarWatchdog = setInterval(() => {
    try {
      const echoReady = !!(window.Echo?.connector?.pusher);
      if (!echoReady) return;
      const need = (ctx.conversations || [])
        .map((c) => Number(c.id))
        .filter(Number.isFinite);
      const have = Object.keys(ctx.convChannelRefs || {})
        .map((k) => Number(k));
      const missing = need.filter((id) => !have.includes(id));
      if (missing.length > 0) {
        ctx.subscribeSidebarChannels();
      }
    } catch (_) {}
  }, 3000);
}

export function subscribeSidebarChannels(ctx) {
  if (!window.Echo) return;
  const currentIds = new Set(ctx.conversations.map(c => Number(c.id)));
  Object.keys(ctx.convChannelRefs).forEach(key => {
    const cid = Number(key);
    if (!currentIds.has(cid)) {
      try {
        ctx.convChannelRefs[cid]?.stopListening('.message.sent');
        ctx.convChannelRefs[cid]?.stopListening('message.sent');
        ctx.convChannelRefs[cid]?.stopListening('.message.edited');
        ctx.convChannelRefs[cid]?.stopListening('message.edited');
        ctx.convChannelRefs[cid]?.stopListening('.message.deleted');
        ctx.convChannelRefs[cid]?.stopListening('message.deleted');
        window.Echo.leave(`conversations.${cid}`);
      } catch (_) {}
      delete ctx.convChannelRefs[cid];
    }
  });

  ctx.conversations.forEach(conv => {
    const cid = Number(conv.id);
    if (!Number.isFinite(cid)) return;
    if (ctx.convChannelRefs[cid]) return;
    try {
      const handler = (eventName, payload) => {
        const msgId = Number(payload?.id);
        if (!Number.isFinite(msgId)) return;
        const ownerId = Number(payload?.user?.id ?? payload?.user_id);
        if (!ctx.selectedConversation || Number(ctx.selectedConversation.id) !== cid) {
          const idx = ctx.conversations.findIndex(c => Number(c.id) === cid);
          if (idx !== -1) {
            const item = { ...ctx.conversations[idx] };
            const now = new Date().toISOString();
            const hasAtt = !!(payload?.has_attachments || (Array.isArray(payload?.attachments) && payload.attachments.length > 0));
            const body = eventName.includes('deleted')
              ? 'Message deleted'
              : (payload?.body || (hasAtt ? 'Attachment' : '') || item.last_message?.body || '');
            const createdAt = payload?.created_at || item.last_message?.created_at || now;
            item.last_message = { body, created_at: createdAt };
            if (Number.isFinite(ownerId) && ownerId !== ctx.myId) {
              item.unread_count = Number(item.unread_count || 0) + 1;
            }
            ctx.conversations.splice(idx, 1, item);
            const sorted = [...ctx.conversations].sort(
              (a, b) => new Date(b.last_message?.created_at || 0) - new Date(a.last_message?.created_at || 0)
            );
            ctx.conversations = sorted;
          }
        }
      };
      const ch = window.Echo.private(`conversations.${cid}`)
        .listen('.message.sent', (payload) => handler('.message.sent', payload))
        .listen('message.sent', (payload) => handler('message.sent', payload))
        .listen('.message.edited', (payload) => handler('.message.edited', payload))
        .listen('message.edited', (payload) => handler('message.edited', payload))
        .listen('.message.deleted', (payload) => handler('.message.deleted', payload))
        .listen('message.deleted', (payload) => handler('message.deleted', payload))
        .listenForWhisper('typing', (e) => {
          const fromId = Number(e?.user_id);
          const sameUser = Number.isFinite(fromId) && fromId === ctx.myId;
          const otherInstance = e?.instance_id && e.instance_id !== ctx.clientInstanceId;
          const show = !!e?.typing && (!sameUser || otherInstance);
          if (ctx.selectedConversation && Number(ctx.selectedConversation.id) === cid) {
            if (show) {
              ctx.otherTyping = true;
              clearTimeout(ctx._typingStopTimer);
              ctx._typingStopTimer = setTimeout(() => { ctx.otherTyping = false; }, 3000);
            }
            if (e && e.typing === false && (!sameUser || otherInstance)) {
              ctx.otherTyping = false;
              clearTimeout(ctx._typingStopTimer);
            }
          }
        })
        .error(() => {});
      ctx.convChannelRefs[cid] = ch;
    } catch (_) {}
  });
}

// Typing helpers
export function sendTypingWhisper(ctx, typing) {
  try {
    if (!ctx.selectedConversation || !window.Echo) return;
    const now = Date.now();
    if (typing && now - ctx._lastTypingSentAt < 150) return;
    ctx._lastTypingSentAt = now;
    const payload = { user_id: ctx.myId, typing: !!typing, at: now, instance_id: ctx.clientInstanceId };
    const presence = ctx.presenceRef;
    if (presence && typeof presence.whisper === 'function') {
      presence.whisper('typing', payload);
    }
    const priv = ctx.channelRef || window.Echo.private(`conversations.${ctx.selectedConversation.id}`);
    if (priv && typeof priv.whisper === 'function') {
      priv.whisper('typing', payload);
    }
    if (!typing) ctx.isTyping = false;
  } catch (_) {}
}

export function handleTypingInput(ctx) {
  ctx.isTyping = !!ctx.messageText.trim();
  sendTypingWhisper(ctx, true);
  clearTimeout(ctx._typingDebounce);
  ctx._typingDebounce = setTimeout(() => {
    sendTypingWhisper(ctx, false);
  }, 2000);
}

export function stopTyping(ctx) {
  clearTimeout(ctx._typingDebounce);
  sendTypingWhisper(ctx, false);
}

// Conversation lifecycle helpers
export function leaveActiveConversation(ctx) {
  try {
    if (ctx.channelRef && ctx.channelName) {
      try {
        ctx.channelRef.stopListening('.message.sent');
        ctx.channelRef.stopListening('message.sent');
        ctx.channelRef.stopListening('.read.updated');
        ctx.channelRef.stopListening('read.updated');
        ctx.channelRef.stopListening('.message.edited');
        ctx.channelRef.stopListening('message.edited');
        ctx.channelRef.stopListening('.message.deleted');
        ctx.channelRef.stopListening('message.deleted');
      } catch (_) {}
      try { window.Echo.leave(ctx.channelName); } catch (_) {}
    }
  } catch (_) {}
  ctx.channelRef = null;
  ctx.channelName = null;
  try {
    if (ctx.presenceRef && ctx.presenceChannelName) {
      window.Echo.leave(ctx.presenceChannelName);
    }
  } catch (_) {}
  ctx.presenceRef = null;
  ctx.presenceChannelName = null;
}

export function startPresenceWatchdog(ctx) {
  if (ctx._presenceWatchdog) return;
  ctx._presenceWatchdog = setInterval(() => ensurePresenceBound(ctx), 1000);
}

export function ensurePresenceBound(ctx) {
  try {
    if (!ctx.selectedConversation || !window.Echo) return;
    const base = `conversations.${ctx.selectedConversation.id}`;
    const full = `presence-${base}`;
    const pusher = window.Echo?.connector?.pusher;
    if (!ctx.presenceRef) {
      try {
        ctx.presenceRef = window.Echo.join(base);
        ctx.presenceChannelName = full;
      } catch(_) {}
    }
    const raw = pusher?.channel(full);
    if (raw && !ctx._rawPresenceBound) {
      ctx._rawPresenceBound = true;
      raw.bind('client-typing', () => {});
    }
  } catch(_) {}
}

export function subscribeToConversationChannel(ctx) {
  if (!ctx.selectedConversation || !window.Echo) return;
  const name = `conversations.${ctx.selectedConversation.id}`;
  ctx.channelName = name;
  try {
    ctx.channelRef = window.Echo.private(name)
      .subscribed(() => {})
      .error(() => {});
    ctx.channelRef
      .listen('.message.sent', (payload) => {
        const msg = payload || {};
        const msgId = Number(msg.id);
        if (!Number.isFinite(msgId)) return;
        const ownerId = Number(msg.user?.id ?? msg.user_id);
        const isMine = Number.isFinite(ownerId) && ownerId === ctx.myId;
        if (!ctx.selectedConversation || ctx.channelName !== `conversations.${ctx.selectedConversation.id}`) return;
        if (ctx.messages.some(m => Number(m.id) === msgId)) return;

        const attachments = Array.isArray(msg.attachments) ? msg.attachments.map(a => ({
          id: Number(a.id),
          url: a.url,
          download_url: a.download_url || a.downloadUrl || a.url,
          mime_type: a.mime_type || a.mimeType || '',
          original_name: a.original_name || a.originalName || '',
          size_bytes: Number(a.size_bytes ?? a.sizeBytes ?? 0) || 0,
          is_image: !!a.is_image || ((a.mime_type || '').startsWith('image/')),
        })) : [];
        const has_attachments = !!(msg.has_attachments || attachments.length > 0);

        const newMessage = {
          id: msgId,
          body: msg.body || '',
          has_attachments,
          attachments,
          user_id: ownerId,
          created_at: msg.created_at,
          user: msg.user || { id: ownerId },
          is_mine: isMine,
          delivered_at: null,
          read_at: null,
          status: isMine ? 'Sent' : '',
        };

        ctx.messages.push(newMessage);
        ctx.messages.sort((a, b) => {
          const da = a.created_at ? new Date(a.created_at).getTime() : 0;
          const db = b.created_at ? new Date(b.created_at).getTime() : 0;
          return da - db;
        });
        ctx.dedupeMessages();
        ctx.messages = [...ctx.messages];
        ctx.scrollToBottom();

        if (!isMine && ctx.selectedConversation) {
          window.axios.post(`/conversations/${ctx.selectedConversation.id}/read`, { message_id: msg.id })
            .catch(() => {});
        }
      })
      .listen('.message.edited', (payload) => {
        const msg = payload || {};
        const msgId = Number(msg.id);
        if (!Number.isFinite(msgId)) return;
        ctx.applyEditedMessage({ id: msgId, body: msg.body, edited_at: msg.edited_at || new Date().toISOString() });
      })
      .listen('.message.deleted', (payload) => {
        const msg = payload || {};
        const msgId = Number(msg.id);
        if (!Number.isFinite(msgId)) return;
        ctx.applyDeletedMessage(msgId, msg.deleted_at || new Date().toISOString());
      })
      .listenForWhisper('typing', (e) => {
        const fromId = Number(e?.user_id);
        const sameUser = Number.isFinite(fromId) && fromId === ctx.myId;
        const otherInstance = e?.instance_id && e.instance_id !== ctx.clientInstanceId;
        const show = !!e?.typing && (!sameUser || otherInstance);
        if (show) {
          ctx.otherTyping = true;
          clearTimeout(ctx._typingStopTimer);
          ctx._typingStopTimer = setTimeout(() => { ctx.otherTyping = false; }, 3000);
        }
        if (e && e.typing === false && (!sameUser || otherInstance)) {
          ctx.otherTyping = false;
          clearTimeout(ctx._typingStopTimer);
        }
      })
      .listen('.read.updated', (e) => {
        const readerId = Number(e?.user_id);
        const maxId = Number(e?.message_id);
        if (!Number.isFinite(readerId) || !Number.isFinite(maxId)) return;
        if (readerId === ctx.myId) return;
        let changed = false;
        const nowIso = new Date().toISOString();
        ctx.messages.forEach(m => {
          if (m.is_mine && Number(m.id) <= maxId && !m.read_at) {
            m.read_at = nowIso;
            if (!m.delivered_at) m.delivered_at = m.created_at || nowIso;
            m.status = 'Read';
            changed = true;
          }
        });
        if (changed) {
          ctx.messages = [...ctx.messages];
        }
      });
    try {
      const pusher = window.Echo?.connector?.pusher;
      const rawPriv = pusher?.channel(`private-${name}`);
      rawPriv?.bind('client-typing', () => {});
    } catch(_) {}
  } catch (_) {}
}

export function subscribeToPresenceChannel(ctx) {
  if (!ctx.selectedConversation || !window.Echo) return;
  try {
    const base = `conversations.${ctx.selectedConversation.id}`;
    const full = `presence-${base}`;
    ctx.presenceChannelName = full;
    ctx.presenceRef = window.Echo.join(base)
      .here(() => {})
      .joining(() => {})
      .leaving(() => {})
      .listenForWhisper('typing', (e) => {
        const fromId = Number(e?.user_id);
        const sameUser = Number.isFinite(fromId) && fromId === ctx.myId;
        const otherInstance = e?.instance_id && e.instance_id !== ctx.clientInstanceId;
        const show = !!e?.typing && (!sameUser || otherInstance);
        if (show) {
          ctx.otherTyping = true;
          clearTimeout(ctx._typingStopTimer);
          ctx._typingStopTimer = setTimeout(() => { ctx.otherTyping = false; }, 3000);
        }
        if (e && e.typing === false && (!sameUser || otherInstance)) {
          ctx.otherTyping = false;
          clearTimeout(ctx._typingStopTimer);
        }
      });
    try {
      const pusher = window.Echo?.connector?.pusher;
      const rawPresence = pusher?.channel(full);
      rawPresence?.bind('client-typing', () => {});
    } catch(_) {}
  } catch(_) {}
}
