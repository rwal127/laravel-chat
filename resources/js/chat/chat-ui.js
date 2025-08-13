// UI helpers for chat: DOM updates, scrolling, message list maintenance
export function dedupeMessages(ctx) {
  if (!Array.isArray(ctx.messages)) return;
  const seen = new Set();
  const unique = [];
  for (const m of ctx.messages) {
    const idNum = Number(m?.id);
    if (!Number.isFinite(idNum)) continue;
    if (!seen.has(idNum)) {
      seen.add(idNum);
      unique.push(m);
    }
  }
  if (unique.length !== ctx.messages.length) {
    ctx.messages = unique;
    try { console.warn('[DEBUG] Duplicates removed from messages:', { before: ctx.messages.length, after: unique.length }); } catch (_) {}
  }
}

// Conversation sorting and sidebar helpers
export function compareByLastActivity(a, b) {
  const aTime = a.last_message?.created_at || a.updated_at || a.created_at;
  const bTime = b.last_message?.created_at || b.updated_at || b.created_at;
  return new Date(bTime) - new Date(aTime);
}

export function resetUnreadAndResort(ctx, openConversationId) {
  const openId = Number(openConversationId);
  const idx = ctx.conversations.findIndex(c => Number(c.id) === openId);
  if (idx !== -1) {
    const updated = { ...ctx.conversations[idx], unread_count: 0 };
    ctx.conversations.splice(idx, 1, updated);
    ctx.conversations = [...ctx.conversations].sort(compareByLastActivity);
  }
}

// Message helpers
export function insertLocalSentMessage(ctx, newMessage) {
  ctx.messages.push(newMessage);
  ctx.messages.sort((a, b) => {
    const da = a.created_at ? new Date(a.created_at).getTime() : 0;
    const db = b.created_at ? new Date(b.created_at).getTime() : 0;
    return da - db;
  });
  ctx.dedupeMessages();
  ctx.messages = [...ctx.messages];
}

export function updateConversationLastMessage(ctx, text, createdAt) {
  if (!ctx.selectedConversation) return;
  ctx.selectedConversation.last_message = {
    body: text,
    created_at: createdAt || new Date().toISOString(),
  };
  const openId = Number(ctx.selectedConversation.id);
  const idx = ctx.conversations.findIndex(c => Number(c.id) === openId);
  if (idx !== -1) {
    const updated = { ...ctx.conversations[idx], last_message: ctx.selectedConversation.last_message };
    ctx.conversations.splice(idx, 1, updated);
    ctx.conversations = [...ctx.conversations].sort(compareByLastActivity);
  }
}

export function scrollToBottom(ctx, behavior = 'smooth') {
  ctx.$nextTick(() => {
    const container = ctx.$refs?.messagesContainer || ctx.$el.querySelector('.overflow-y-auto.bg-gray-50');
    if (!container) return;

    const doScroll = (beh) => {
      try {
        const top = container.scrollHeight;
        if (typeof container.scrollTo === 'function') {
          container.scrollTo({ top, behavior: beh === 'smooth' ? 'smooth' : 'auto' });
        } else {
          container.scrollTop = top;
        }
      } catch (_) {
        try { container.scrollTop = container.scrollHeight; } catch(_) {}
      }
    };

    // First attempt next frame
    requestAnimationFrame(() => {
      doScroll(behavior);
      // Immediate follow-up to catch late layout changes
      setTimeout(() => doScroll('auto'), 0);
      // One more pass for images or fonts settling
      setTimeout(() => doScroll('auto'), 120);
    });
  });
}

// Edit/Delete helpers
export function isWithinFiveMinutes(timestamp) {
  if (!timestamp) return false;
  try {
    const created = new Date(timestamp).getTime();
    const now = Date.now();
    return now - created <= 5 * 60 * 1000;
  } catch (_) { return false; }
}

export function canEditMessage(ctx, message) {
  return !!(message?.is_mine && !message?.deleted_at && isWithinFiveMinutes(message?.created_at));
}

export function applyEditedMessage(ctx, { id, body, edited_at }) {
  const mid = Number(id);
  const idx = ctx.messages.findIndex(m => Number(m.id) === mid);
  if (idx !== -1) {
    const updated = { ...ctx.messages[idx], body: body ?? ctx.messages[idx].body, edited_at: edited_at ?? new Date().toISOString() };
    ctx.messages.splice(idx, 1, updated);
    ctx.messages = [...ctx.messages];
  }
}

export function applyDeletedMessage(ctx, id, deleted_at = null) {
  const mid = Number(id);
  const idx = ctx.messages.findIndex(m => Number(m.id) === mid);
  if (idx !== -1) {
    const updated = { ...ctx.messages[idx], deleted_at: deleted_at ?? new Date().toISOString(), body: '' };
    ctx.messages.splice(idx, 1, updated);
    ctx.messages = [...ctx.messages];
  }
}
