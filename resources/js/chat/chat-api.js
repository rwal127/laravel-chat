// API layer: HTTP requests for conversations and messages
import axios from 'axios';

export async function fetchConversations() {
  const resp = await axios.get('/conversations');
  const data = resp.data?.data ?? resp.data ?? [];
  return Array.isArray(data) ? data : Object.values(data);
}

export async function fetchMessages(conversationId, params = {}) {
  const resp = await axios.get(`/conversations/${conversationId}/messages`, { params });
  const data = resp.data?.data ?? resp.data ?? [];
  return Array.isArray(data) ? data : Object.values(data);
}

// Paged messages fetch (returns both data and meta)
export async function fetchMessagesPaged(conversationId, params = {}) {
  const resp = await axios.get(`/conversations/${conversationId}/messages`, { params });
  const data = resp.data?.data ?? [];
  const meta = resp.data?.meta ?? {};
  return { data: Array.isArray(data) ? data : Object.values(data), meta };
}

export async function sendMessage(conversationId, payload) {
  const resp = await axios.post(`/conversations/${conversationId}/messages`, payload);
  return resp.data;
}

export async function markRead(conversationId, messageId = null) {
  try {
    const payload = messageId ? { message_id: messageId } : {};
    await axios.post(`/conversations/${conversationId}/read`, payload);
  } catch (_) {}
}

// Search users by query string
export async function searchUsers(q) {
  const resp = await axios.get('/users/search', { params: { q } });
  const data = resp.data?.data ?? resp.data ?? [];
  return Array.isArray(data) ? data : Object.values(data);
}

// Add contact for the current user
export async function addContact(contact_user_id) {
  const resp = await axios.post('/contacts', { contact_user_id });
  return resp.data;
}

// Some flows post to /messages directly
export async function sendMessageGlobal({ conversation_id, body }) {
  const resp = await axios.post('/messages', { conversation_id, body });
  return resp.data;
}

// Send a message with optional attachments using multipart
export async function sendMessageWithAttachments(conversationId, body, files = []) {
  const form = new FormData();
  form.append('conversation_id', conversationId);
  if (typeof body === 'string' && body.length > 0) {
    form.append('body', body);
  }
  for (const f of files) {
    if (f) form.append('attachments[]', f);
  }
  const resp = await axios.post('/messages', form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
  return resp.data;
}

// Edit an existing message
export async function editMessage(messageId, body) {
  const resp = await axios.patch(`/messages/${messageId}`, { body });
  return resp.data;
}

// Delete an existing message
export async function deleteMessage(messageId) {
  const resp = await axios.delete(`/messages/${messageId}`);
  return resp.data;
}

// Upload a single attachment to a conversation
export async function uploadAttachment(conversationId, file) {
  const form = new FormData();
  form.append('file', file);
  const resp = await axios.post(`/conversations/${conversationId}/attachments`, form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
  return resp.data;
}
