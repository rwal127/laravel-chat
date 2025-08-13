// Notification logic for chat (browser notifications, sounds, badges)

export function canNotify() {
  return 'Notification' in window;
}

export async function ensurePermission() {
  if (!canNotify()) return false;
  if (Notification.permission === 'granted') return true;
  if (Notification.permission !== 'denied') {
    const p = await Notification.requestPermission();
    return p === 'granted';
  }
  return false;
}

export async function notifyNewMessage({ title = 'New message', body = '', icon = undefined } = {}) {
  try {
    const ok = await ensurePermission();
    if (!ok) return;
    new Notification(title, { body, icon });
  } catch (_) {}
}
