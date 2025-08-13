import chatApp from './chat-core.js';

// Re-export for consumers
export default chatApp;

// Expose to window for Alpine x-data
try { window.chatApp = chatApp; } catch (_) {}
