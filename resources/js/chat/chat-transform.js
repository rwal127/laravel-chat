// Message and conversation transforms/sorting

export function toUiMessage(msg, meId, me) {
  const userObj = msg.user || { id: msg.user_id, name: 'Unknown User' };
  const ownerId = Number(userObj.id ?? msg.user_id);
  const deliveredAt = msg.delivered_at ?? msg.deliveredAt ?? null;
  const readAt = msg.read_at ?? msg.readAt ?? null;
  const isMine = Number.isFinite(ownerId) && ownerId === Number(meId);
  const status = isMine
    ? (readAt ? 'Read' : (deliveredAt ? 'Delivered' : 'Sent'))
    : '';
  return {
    id: Number(msg.id),
    body: msg.body || '',
    has_attachments: !!(msg.has_attachments || (Array.isArray(msg.attachments) && msg.attachments.length > 0)),
    attachments: Array.isArray(msg.attachments) ? msg.attachments.map(a => ({
      id: Number(a.id),
      url: a.url,
      download_url: a.download_url || a.downloadUrl || a.url,
      mime_type: a.mime_type || a.mimeType || '',
      original_name: a.original_name || a.originalName || '',
      size_bytes: Number(a.size_bytes ?? a.sizeBytes ?? 0) || 0,
      is_image: !!a.is_image || ((a.mime_type || '').startsWith('image/')),
    })) : [],
    user_id: Number(msg.user_id ?? userObj.id),
    created_at: msg.created_at,
    edited_at: msg.edited_at ?? null,
    deleted_at: msg.deleted_at ?? null,
    user: userObj,
    is_mine: isMine,
    delivered_at: deliveredAt,
    read_at: readAt,
    status,
  };
}

export function sortMessagesAsc(messages) {
  return [...messages].sort((a, b) => new Date(a.created_at) - new Date(b.created_at));
}

export function compareByLastActivity(a, b) {
  const aTime = a.last_message?.created_at || a.updated_at || a.created_at;
  const bTime = b.last_message?.created_at || b.updated_at || b.created_at;
  return new Date(bTime) - new Date(aTime);
}
