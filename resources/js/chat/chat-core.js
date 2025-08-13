import {
  dedupeMessages as uiDedupeMessages,
  scrollToBottom as uiScrollToBottom,
  compareByLastActivity as uiCompareByLastActivity,
  resetUnreadAndResort as uiResetUnreadAndResort,
  insertLocalSentMessage as uiInsertLocalSentMessage,
  updateConversationLastMessage as uiUpdateConversationLastMessage,
  canEditMessage as uiCanEditMessage,
  applyEditedMessage as uiApplyEditedMessage,
  applyDeletedMessage as uiApplyDeletedMessage,
} from './chat-ui.js';
import { startConversationsPoller as pollStart } from './chat-poller.js';
import { toUiMessage, sortMessagesAsc } from './chat-transform.js';
import {
  fetchConversations,
  fetchMessagesPaged as apiFetchMessagesPaged,
  sendMessage as apiSendMessage,
  sendMessageGlobal,
  markRead as apiMarkRead,
  searchUsers as apiSearchUsers,
  addContact,
  editMessage as apiEditMessage,
  deleteMessage as apiDeleteMessage,
  uploadAttachment as apiUploadAttachment,
  sendMessageWithAttachments as apiSendMessageWithAttachments,
} from './chat-api.js';
import {
  bindEchoConnectionHandlers as evBindEcho,
  ensureEchoReady as evEnsureEcho,
  startSidebarWatchdog as evStartSidebar,
  subscribeSidebarChannels as evSubscribeSidebar,
  startPresenceWatchdog as evStartPresence,
  ensurePresenceBound as evEnsurePresence,
  subscribeToPresenceChannel as evSubscribePresence,
  subscribeToConversationChannel as evSubscribeConv,
  subscribeUserChannel as evSubscribeUser,
  handleTypingInput as evHandleTyping,
  sendTypingWhisper as evSendTyping,
  stopTyping as evStopTyping,
  leaveActiveConversation as evLeaveActive,
} from './chat-events.js';

export default function chatApp() {
  // Disable all logging within this module
  const console = { log: () => {}, warn: () => {}, error: () => {} };
  return {
    // User data
    me: null,
    myId: null,
    clientInstanceId: null,
    channelName: null,
    channelRef: null,
    
    // Search and user management
    searchQuery: '',
    showAddUser: false,
    searchResults: [],
    
    // Conversations
    conversations: [],
    convChannelRefs: {}, // { [conversationId]: channelRef }
    selectedConversation: null,
    
    // Messages
    messages: [],
    messageText: '',
    editingMessageId: null,
    editingText: '',
    actionsForMessageId: null,
    maxUploadBytes: 10 * 1024 * 1024,
    // Pending attachments queued until user presses Send
    pendingAttachments: [], // [{ file, name, size, type, is_image, url }]
    // Paging state
    pageSize: 15,
    hasMore: true,
    nextBeforeId: null,
    loadingOlder: false,
    // Typing state
    isTyping: false,
    otherTyping: false,
    _typingDebounce: null,
    _typingStopTimer: null,
    presenceRef: null,
    presenceChannelName: null,
    _lastTypingSentAt: 0,
    // Online status snapshot (for reactivity)
    onlineUsersVersion: 0,
    isOnline(userId) {
      try {
        const id = String(userId ?? '');
        return !!(window.OnlineUsers && typeof window.OnlineUsers.has === 'function' && window.OnlineUsers.has(id));
      } catch(_) { return false; }
    },
    updateChatHeight() {
      try {
        const root = this.$refs.chatRoot;
        if (!root) return;
        // Find wrapper with py-12 to get bottom padding (48px)
        let wrapper = root.closest('.py-12');
        let bottomPadding = 0;
        if (wrapper) {
          const cs = getComputedStyle(wrapper);
          bottomPadding = parseFloat(cs.paddingBottom || '0') || 0;
        }
        // Offset of root from viewport top
        const top = root.getBoundingClientRect().top;
        const safeArea = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('padding-bottom')) || 0;
        const innerH = window.innerHeight;
        // Leave space for wrapper bottom padding (py-12) and safe area
        const target = Math.max(320, innerH - top - bottomPadding - safeArea);
        root.style.height = `${Math.round(target)}px`;
      } catch (_) { /* noop */ }
    },
    destroy() {
      if (this._onResize) {
        window.removeEventListener('resize', this._onResize);
        window.removeEventListener('orientationchange', this._onResize);
      }
    },

    // Search state (messages, not user search)
    searchOpen: false,
    messageSearchQuery: '',
    searchMatches: [], // array of message ids
    searchCursor: -1,
    searchLoading: false,

    openSearch() {
      this.searchOpen = true;
      this.$nextTick(() => {
        try { this.$root.querySelector('input[x-model="messageSearchQuery"]').focus(); } catch(_) {}
      });
    },
    closeSearch() {
      this.searchOpen = false;
      this.messageSearchQuery = '';
      this.searchMatches = [];
      this.searchCursor = -1;
    },
    async performSearch() {
      const q = (this.messageSearchQuery || '').trim();
      if (!this.selectedConversation) return;
      if (q.length === 0) {
        this.searchMatches = [];
        this.searchCursor = -1;
        return;
      }
      this.searchLoading = true;
      try {
        const { data } = await apiFetchMessagesPaged(this.selectedConversation.id, { per_page: 50, search: q });
        this.searchMatches = (data || []).map(m => Number(m.id)).filter(Number.isFinite);
        this.searchCursor = this.searchMatches.length ? 0 : -1;
        if (this.searchCursor >= 0) {
          await this.jumpToMatch(this.searchCursor);
        }
      } catch (e) {
        this.searchMatches = [];
        this.searchCursor = -1;
      } finally {
        this.searchLoading = false;
      }
    },
    async nextMatch() {
      if (!this.searchMatches.length) return;
      this.searchCursor = (this.searchCursor + 1) % this.searchMatches.length;
      await this.jumpToMatch(this.searchCursor);
    },
    async prevMatch() {
      if (!this.searchMatches.length) return;
      this.searchCursor = (this.searchCursor - 1 + this.searchMatches.length) % this.searchMatches.length;
      await this.jumpToMatch(this.searchCursor);
    },
    async jumpToMatch(index) {
      const id = Number(this.searchMatches[index]);
      if (!Number.isFinite(id)) return;
      await this.ensureMessageVisible(id);
      this.scrollToMessage(id);
    },
    async ensureMessageVisible(targetId) {
      // If message already loaded, nothing more to do
      if (this.messages.some(m => Number(m.id) === Number(targetId))) return;
      // Start loading older pages beginning just after the target id
      this.hasMore = true;
      this.nextBeforeId = Number(targetId) + 1;
      // Safety cap to avoid endless loops
      let guard = 0;
      while (guard < 50 && this.hasMore && this.nextBeforeId && !this.messages.some(m => Number(m.id) === Number(targetId))) {
        await this.loadOlderFrom(this.nextBeforeId);
        guard++;
      }
    },
    scrollToMessage(id) {
      this.$nextTick(() => {
        try {
          const el = document.getElementById('msg-' + id);
          if (el && this.$refs.messagesContainer) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
          }
        } catch(_) {}
      });
    },

    // Attachment: open file picker
    openFilePicker() {
      try { this.$refs.fileInput?.click(); } catch (_) {}
    },

    // Attachment: handle selection (queue, do NOT upload yet)
    async onFileSelected(e) {
      const files = Array.from(e?.target?.files || []);
      if (!files.length) return;
      // reset input so selecting the same file again still triggers change
      try { e.target.value = ''; } catch(_) {}
      const items = [];
      for (const file of files) {
        if (!file) continue;
        if (file.size > this.maxUploadBytes) {
          alert('File is too large. Maximum size is 10MB.');
          continue;
        }
        const isImage = String(file.type || '').startsWith('image/');
        items.push({
          file,
          name: file.name,
          size: file.size,
          type: file.type,
          is_image: isImage,
          url: (isImage && window.URL && URL.createObjectURL) ? URL.createObjectURL(file) : null,
        });
      }
      if (items.length) {
        this.pendingAttachments = [...this.pendingAttachments, ...items];
      }
    },

    async loadOlderFrom(beforeId) {
      if (!this.selectedConversation || this.loadingOlder || !beforeId) return;
      this.loadingOlder = true;
      const container = this.$refs.messagesContainer;
      const prevScrollHeight = container ? container.scrollHeight : 0;
      const prevScrollTop = container ? container.scrollTop : 0;
      try {
        const { data, meta } = await apiFetchMessagesPaged(this.selectedConversation.id, {
          per_page: this.pageSize,
          before_id: beforeId,
        });
        this.hasMore = !!meta?.has_more;
        this.nextBeforeId = meta?.next_before_id ?? null;
        const older = sortMessagesAsc(data.map(m => toUiMessage(m, this.myId, this.me)));
        const current = this.messages;
        this.messages = [...older, ...current];
        this.dedupeMessages();
        this.messages = [...this.messages];
        this.$nextTick(() => {
          if (container) {
            const newScrollHeight = container.scrollHeight;
            container.scrollTop = newScrollHeight - (prevScrollHeight - prevScrollTop);
          }
        });
      } catch (e) {
      } finally {
        this.loadingOlder = false;
      }
    },

    removePendingAttachment(idx) {
      const list = [...this.pendingAttachments];
      const [removed] = list.splice(idx, 1);
      try { if (removed?.url) URL.revokeObjectURL(removed.url); } catch(_) {}
      this.pendingAttachments = list;
    },

    clearPendingAttachments() {
      try { this.pendingAttachments.forEach(it => it?.url && URL.revokeObjectURL(it.url)); } catch(_) {}
      this.pendingAttachments = [];
    },

    async loadMessagesInitial() {
      if (!this.selectedConversation) return;
      try {
        const { data, meta } = await apiFetchMessagesPaged(this.selectedConversation.id, { per_page: this.pageSize });
        this.hasMore = !!meta?.has_more;
        this.nextBeforeId = meta?.next_before_id ?? null;
        this.messages = sortMessagesAsc(data.map(m => toUiMessage(m, this.myId, this.me)));
        this.dedupeMessages();
        this.messages = [...this.messages];
        this.$nextTick(async () => {
          this.scrollToBottom();
          try {
            const last = this.messages[this.messages.length - 1];
            if (last && this.selectedConversation) {
              await apiMarkRead(this.selectedConversation.id, last.id);
            }
          } catch (_) {}
        });
      } catch (e) {
        this.messages = [];
        this.hasMore = false;
        this.nextBeforeId = null;
      }
    },

    // Loading states
    loading: false,
    
    init() {
      this.me = window.Me || null;
      this.myId = Number(this.me?.id);
      // Attach dynamic height listeners and set initial height
      try {
        this._onResize = () => { this.updateChatHeight(); this.scrollToBottom('auto'); };
        window.addEventListener('resize', this._onResize, { passive: true });
        window.addEventListener('orientationchange', this._onResize, { passive: true });
        this.$nextTick(() => this.updateChatHeight());
      } catch(_) {}
      // Unique per-tab instance id for typing debug across same user sessions
      try {
        this.clientInstanceId = (window.crypto && typeof window.crypto.randomUUID === 'function')
          ? window.crypto.randomUUID()
          : `inst-${Math.random().toString(36).slice(2)}-${Date.now()}`;
      } catch(_) {
        this.clientInstanceId = `inst-${Math.random().toString(36).slice(2)}-${Date.now()}`;
      }
      if (this.me) {
        try { window.__chatApp = this; } catch(_) {}
        // Try to bind Echo connection handlers (may not be ready yet)
        this.bindEchoConnectionHandlers();
        this.loadConversations();
        // Start poller and ensure Echo readiness for sidebar subscriptions
        this.startConversationsPoller();
        this.startSidebarWatchdog();
        this.ensureEchoReady();
        // Ensure personal user channel is bound ASAP
        try { evSubscribeUser(this); } catch (_) {}
        // Start presence watchdog to guarantee presence bindings
        this.startPresenceWatchdog();
        // React to online/offline updates
        try {
          window.addEventListener('online:update', () => {
            this.onlineUsersVersion++;
            // Force header to re-evaluate bindings by cloning selectedConversation
            if (this.selectedConversation) {
              this.selectedConversation = { ...this.selectedConversation };
            }
          });
        } catch(_) {}
      } else { /* not initialized */ }
    },

    startPresenceWatchdog() { evStartPresence(this); },

    ensurePresenceBound() { evEnsurePresence(this); },

    subscribeToPresenceChannel() { evSubscribePresence(this); },

    // Handle local user typing in the input
    handleTypingInput() { evHandleTyping(this); },

    sendTypingWhisper(typing) { evSendTyping(this, typing); },

    // Explicitly stop typing on blur and after sending
    stopTyping() { evStopTyping(this); },

    ensureEchoReady() { evEnsureEcho(this); },

    startSidebarWatchdog() { evStartSidebar(this); },

    startConversationsPoller() { pollStart(this); },

    subscribeSidebarChannels() { evSubscribeSidebar(this); },

    // Ensure messages array has unique ids
    dedupeMessages() { uiDedupeMessages(this); },

    // Smoothly scroll messages container to the bottom
    scrollToBottom() { uiScrollToBottom(this); },

    // Edit/Delete helpers exposed for events module and UI
    applyEditedMessage(payload) { uiApplyEditedMessage(this, payload || {}); },
    applyDeletedMessage(id, deleted_at = null) { uiApplyDeletedMessage(this, id, deleted_at); },
    canEdit(message) { return uiCanEditMessage(this, message); },
    startEditMessage(message) {
      if (!this.canEdit(message)) return;
      this.editingMessageId = Number(message.id);
      this.editingText = message.body || '';
    },
    cancelEdit() {
      this.editingMessageId = null;
      this.editingText = '';
    },
    async confirmEditMessage() {
      const mid = Number(this.editingMessageId);
      const text = (this.editingText || '').trim();
      if (!Number.isFinite(mid) || text.length === 0) return;
      try {
        const resp = await apiEditMessage(mid, text);
        this.applyEditedMessage({ id: mid, body: text, edited_at: resp?.edited_at });
        this.cancelEdit();
      } catch (e) {
        alert(e?.response?.data?.message || e.message || 'Failed to edit message');
      }
    },
    async deleteMessage(message) {
      const mid = Number(message?.id);
      if (!Number.isFinite(mid)) return;
      try {
        await apiDeleteMessage(mid);
        this.applyDeletedMessage(mid);
      } catch (e) {
        alert(e?.response?.data?.message || e.message || 'Failed to delete message');
      }
    },

    // Toggle inline actions visibility for a given message (for click/touch)
    toggleActions(message) {
      const mid = Number(message?.id);
      if (!Number.isFinite(mid)) return;
      this.actionsForMessageId = this.actionsForMessageId === mid ? null : mid;
    },

    // isMine method removed; we now compute `is_mine` on each message object

    async loadConversations() {
      try {
        this.loading = true;
        const list = await fetchConversations();
        this.conversations = list
          .map(c => ({ ...c, unread_count: Number(c.unread_count || 0) }))
          .sort(uiCompareByLastActivity);
        this.subscribeSidebarChannels();
      } catch (error) {
        this.conversations = [];
      } finally {
        this.loading = false;
      }
    },

    async searchUsers() {
      if (!this.searchQuery.trim()) {
        this.searchResults = [];
        this.showAddUser = false;
        return;
      }

      try {
        const users = await apiSearchUsers(this.searchQuery);
        
        // Filter out current user and existing conversations
        const existingUserIds = new Set([
          this.me?.id,
          ...this.conversations.map(c => c.other_user?.id).filter(Boolean)
        ]);
        
        this.searchResults = users.filter(user => !existingUserIds.has(user.id));
        this.showAddUser = this.searchResults.length > 0;
      } catch (error) {
        this.searchResults = [];
        this.showAddUser = false;
      }
    },

    async addUser(user) {
      try {
        await addContact(user.id);
        
        // Reload conversations to get the new one
        await this.loadConversations();
        // Refresh presence watchlist to include this new contact so online status is accurate
        try { window.refreshWatchlist && window.refreshWatchlist(); } catch (_) {}
        // Start polling fallback for unread/preview refresh
        this.startConversationsPoller();
        
        // Find and select the new conversation
        const newConversation = this.conversations.find(c => 
          c.other_user?.id === user.id
        );
        
        if (newConversation) {
          this.selectConversation(newConversation);
        }
        
        // Clear search
        this.searchQuery = '';
        this.searchResults = [];
        this.showAddUser = false;
        
      } catch (error) {
        const message = error.response?.data?.message || error.message || 'Failed to add user';
        alert(`Error: ${message}`);
      }
    },

    async selectConversation(conversation) {
      if (this.selectedConversation?.id === conversation.id) {
        return;
      }
      
      // Zero unread count for this conversation and resort sidebar
      uiResetUnreadAndResort(this, conversation.id);
      // Leave/unsubscribe from previous conversation and presence
      evLeaveActive(this);
      this.selectedConversation = conversation;
      try { window.__chatApp = this; } catch(_) {}
      try { /* typing state debug removed */ } catch(_) {}
      this.messages = [];
      this.otherTyping = false;
      // presence cleared by evLeaveActive
      // Reset paging state
      this.hasMore = true;
      this.nextBeforeId = null;
      this.loadingOlder = false;
      
      // Load latest page of messages for this conversation
      await this.loadMessagesInitial();

      // Subscribe to Echo for real-time receipts on this conversation
      this.subscribeToConversationChannel();
      this.subscribeToPresenceChannel();

      // Auto scroll on open
      this.scrollToBottom();

      // Ensure sidebar subscriptions are active after switching
      this.subscribeSidebarChannels();
    },

    subscribeToConversationChannel() { evSubscribeConv(this); },

    bindEchoConnectionHandlers() { evBindEcho(this); },

    async loadMessages() {
      if (!this.selectedConversation) {
        return;
      }
      
      try {
        // Back-compat: delegate to paged initial loader with default page size
        const { data, meta } = await apiFetchMessagesPaged(this.selectedConversation.id, { per_page: this.pageSize });
        this.hasMore = !!meta?.has_more;
        this.nextBeforeId = meta?.next_before_id ?? null;
        this.messages = sortMessagesAsc(data.map(m => toUiMessage(m, this.myId, this.me)));
        // Remove duplicates and force Alpine to refresh
        this.dedupeMessages();
        this.messages = [...this.messages];
        
        // Scroll to bottom
        this.$nextTick(async () => {
          this.scrollToBottom();

          // Mark messages as read up to the last message id
          try {
            const last = this.messages[this.messages.length - 1];
            if (last && this.selectedConversation) {
              await apiMarkRead(this.selectedConversation.id, last.id);
            }
          } catch (e) {
            /* silent */
          }
        });
        
      } catch (error) {
        this.messages = [];
        this.hasMore = false;
        this.nextBeforeId = null;
      }
    },

    async loadOlderMessages() {
      if (!this.selectedConversation || this.loadingOlder || !this.hasMore || !this.nextBeforeId) return;
      this.loadingOlder = true;
      const container = this.$refs.messagesContainer;
      const prevScrollHeight = container ? container.scrollHeight : 0;
      const prevScrollTop = container ? container.scrollTop : 0;
      try {
        const { data, meta } = await apiFetchMessagesPaged(this.selectedConversation.id, {
          per_page: this.pageSize,
          before_id: this.nextBeforeId,
        });
        this.hasMore = !!meta?.has_more;
        this.nextBeforeId = meta?.next_before_id ?? null;
        const older = sortMessagesAsc(data.map(m => toUiMessage(m, this.myId, this.me)));
        // Prepend while preserving scroll position
        const current = this.messages;
        this.messages = [...older, ...current];
        this.dedupeMessages();
        this.messages = [...this.messages];
        this.$nextTick(() => {
          if (container) {
            const newScrollHeight = container.scrollHeight;
            container.scrollTop = newScrollHeight - (prevScrollHeight - prevScrollTop);
          }
        });
      } catch (e) {
        // ignore
      } finally {
        this.loadingOlder = false;
      }
    },

    onMessagesScroll(e) {
      const el = e?.target || this.$refs.messagesContainer;
      if (!el) return;
      if (el.scrollTop <= 32) {
        this.loadOlderMessages();
      }
    },

    async sendMessage() {
      if (!this.selectedConversation) { this.stopTyping(); return; }
      const hasText = !!this.messageText.trim();
      const hasFiles = this.pendingAttachments.length > 0;
      if (!hasText && !hasFiles) { this.stopTyping(); return; }

      const messageBody = this.messageText.trim();
      this.messageText = '';
      const files = this.pendingAttachments.map(it => it.file).filter(Boolean);
      
      try {
        const response = await apiSendMessageWithAttachments(this.selectedConversation.id, messageBody, files);
        const respId = Number(response?.id);
        const respCreated = response?.created_at || new Date().toISOString();
        if (!Number.isFinite(respId)) {
          /* no valid id; skip local push */
        } else {
          // If Echo already delivered the message, skip local insert
          if (!this.messages.some(m => Number(m.id) === respId)) {
            const newMessage = {
              id: respId,
              body: messageBody || null,
              user_id: this.myId,
              created_at: respCreated,
              user: this.me,
              is_mine: true,
              delivered_at: null,
              read_at: null,
              status: 'Sent',
              has_attachments: files.length > 0,
              attachments: [],
            };
            uiInsertLocalSentMessage(this, newMessage);
          }
        }
        
        // Update conversation's last message (use server timestamp)
        if (this.selectedConversation) {
          uiUpdateConversationLastMessage(this, messageBody, respCreated);
        }
        
        // Scroll to bottom
        this.scrollToBottom();
        // Stop typing after send
        this.stopTyping();
        // Clear pending attachments
        this.clearPendingAttachments();
        
      } catch (error) {
        const message = error.response?.data?.message || error.message || 'Failed to send message';
        alert(`Error: ${message}`);
        
        // Restore message text on error
        this.messageText = messageBody;
        this.stopTyping();
      }
    },

    formatTime(timestamp) {
      if (!timestamp) return '';
      
      const date = new Date(timestamp);
      const now = new Date();
      const diffInHours = (now - date) / (1000 * 60 * 60);
      
      if (diffInHours < 24) {
        return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
      } else if (diffInHours < 24 * 7) {
        return date.toLocaleDateString([], { weekday: 'short' });
      } else {
        return date.toLocaleDateString([], { month: 'short', day: 'numeric' });
      }
    }
  };
}
