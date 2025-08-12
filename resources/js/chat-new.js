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

    // Loading states
    loading: false,
    
    init() {
      this.me = window.Me || null;
      this.myId = Number(this.me?.id);
      // Unique per-tab instance id for typing debug across same user sessions
      try {
        this.clientInstanceId = (window.crypto && typeof window.crypto.randomUUID === 'function')
          ? window.crypto.randomUUID()
          : `inst-${Math.random().toString(36).slice(2)}-${Date.now()}`;
      } catch(_) {
        this.clientInstanceId = `inst-${Math.random().toString(36).slice(2)}-${Date.now()}`;
      }
      if (this.me) {
        console.log('Chat app initialized for user:', this.me);
        try { window.__chatApp = this; } catch(_) {}
        // Try to bind Echo connection handlers (may not be ready yet)
        this.bindEchoConnectionHandlers();
        this.loadConversations();
        // Start poller and ensure Echo readiness for sidebar subscriptions
        this.startConversationsPoller();
        this.startSidebarWatchdog();
        this.ensureEchoReady();
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
      } else {
        console.log('Chat app not initialized; user not found.');
      }
    },

    startPresenceWatchdog() {
      if (this._presenceWatchdog) return;
      this._presenceWatchdog = setInterval(() => this.ensurePresenceBound(), 1000);
    },

    ensurePresenceBound() {
      try {
        if (!this.selectedConversation || !window.Echo) return;
        const base = `conversations.${this.selectedConversation.id}`;
        const full = `presence-${base}`;
        const pusher = window.Echo?.connector?.pusher;
        // If not joined or ref lost, attempt to join via Echo (idempotent)
        if (!this.presenceRef) {
          try {
            this.presenceRef = window.Echo.join(base);
            this.presenceChannelName = full;
          } catch(_) {}
        }
        // Bind raw client event for visibility
        const raw = pusher?.channel(full);
        if (raw && !this._rawPresenceBound) {
          this._rawPresenceBound = true;
          raw.bind('client-typing', (e) => {
            try { window.console && window.console.log('[Typing][RAW][Watchdog] recv client-typing (presence)', { name: full, e }); } catch(_) {}
          });
        }
      } catch(_) {}
    },

    subscribeToPresenceChannel() {
      if (!this.selectedConversation || !window.Echo) return;
      try {
        const base = `conversations.${this.selectedConversation.id}`; // Echo.join auto-prefixes presence-
        const full = `presence-${base}`;
        this.presenceChannelName = full;
        this.presenceRef = window.Echo.join(base)
          .here((users) => { try { window.console && window.console.log('[Typing] presence here', { name: full, users }); } catch(_) {} })
          .joining((user) => { try { window.console && window.console.log('[Typing] presence joining', { name: full, user }); } catch(_) {} })
          .leaving((user) => { try { window.console && window.console.log('[Typing] presence leaving', { name: full, user }); } catch(_) {} })
          .listenForWhisper('typing', (e) => {
            const fromId = Number(e?.user_id);
            const sameUser = Number.isFinite(fromId) && fromId === this.myId;
            const otherInstance = e?.instance_id && e.instance_id !== this.clientInstanceId;
            const show = !!e?.typing && (!sameUser || otherInstance);
            try { window.console && window.console.log('[Typing] whisper recv', { name: full, fromId, sameUser, otherInstance, show, e }); } catch(_) {}
            if (show) {
              try { window.console && window.console.log('[Typing] SHOW indicator (presence)', { fromId, e }); } catch(_) {}
              this.otherTyping = true;
              clearTimeout(this._typingStopTimer);
              this._typingStopTimer = setTimeout(() => { this.otherTyping = false; }, 3000);
            }
            if (e && e.typing === false && (!sameUser || otherInstance)) {
              try { window.console && window.console.log('[Typing] HIDE indicator (presence)', { fromId, e }); } catch(_) {}
              this.otherTyping = false;
              clearTimeout(this._typingStopTimer);
            }
          });
        // Also bind raw Pusher client event for extra visibility
        try {
          const pusher = window.Echo?.connector?.pusher;
          const rawPresence = pusher?.channel(full);
          rawPresence?.bind('client-typing', (e) => {
            try { window.console && window.console.log('[Typing][RAW] recv client-typing (presence)', { name: full, e }); } catch(_) {}
          });
        } catch(_) {}
      } catch(_) {}
    },

    // Handle local user typing in the input
    handleTypingInput() {
      this.isTyping = !!this.messageText.trim();
      try { window.console && window.console.log('[Typing] input changed, isTyping=', this.isTyping); } catch(_) {}
      this.sendTypingWhisper(true);
      clearTimeout(this._typingDebounce);
      this._typingDebounce = setTimeout(() => {
        // If user stopped typing for 2s, send stop
        try { window.console && window.console.log('[Typing] inactivity timeout -> stop'); } catch(_) {}
        this.sendTypingWhisper(false);
      }, 2000);
    },

    sendTypingWhisper(typing) {
      try {
        if (!this.selectedConversation || !window.Echo) return;
        const now = Date.now();
        // Throttle to 150ms between sends
        if (typing && now - this._lastTypingSentAt < 150) {
          return;
        }
        this._lastTypingSentAt = now;
        const payload = { user_id: this.myId, typing: !!typing, at: now, instance_id: this.clientInstanceId };
        try { window.console && window.console.log('[Typing] whisper send', { cid: this.selectedConversation.id, ...payload }); } catch(_) {}
        // Prefer presence channel (do not auto-join here to avoid duplicate subs)
        const presence = this.presenceRef;
        if (presence && typeof presence.whisper === 'function') {
          presence.whisper('typing', payload);
        }
        // Also send on private channel for compatibility
        const priv = this.channelRef || window.Echo.private(`conversations.${this.selectedConversation.id}`);
        if (priv && typeof priv.whisper === 'function') {
          priv.whisper('typing', payload);
        }
        if (!typing) this.isTyping = false;
      } catch (_) {}
    },

    // Explicitly stop typing on blur and after sending
    stopTyping() {
      clearTimeout(this._typingDebounce);
      try { window.console && window.console.log('[Typing] explicit stopTyping()'); } catch(_) {}
      this.sendTypingWhisper(false);
    },

    ensureEchoReady() {
      try {
        const ready = !!(window.Echo?.connector?.pusher);
        if (ready) {
          // Bind once and ensure sidebar channels
          this.bindEchoConnectionHandlers();
          this.subscribeSidebarChannels();
          return;
        }
      } catch (_) {}
      // Retry shortly if Echo not yet ready
      clearTimeout(this._echoReadyTimer);
      this._echoReadyTimer = setTimeout(() => this.ensureEchoReady(), 1000);
    },

    startSidebarWatchdog() {
      if (this._sidebarWatchdog) return;
      this._sidebarWatchdog = setInterval(() => {
        try {
          const echoReady = !!(window.Echo?.connector?.pusher);
          if (!echoReady) return;
          const need = (this.conversations || [])
            .map((c) => Number(c.id))
            .filter(Number.isFinite);
          const have = Object.keys(this.convChannelRefs || {})
            .map((k) => Number(k));
          const missing = need.filter((id) => !have.includes(id));
          if (missing.length > 0) {
            console.log('[Sidebar Watchdog] missing subscriptions for', missing);
            this.subscribeSidebarChannels();
          }
        } catch (e) {
          console.warn('[Sidebar Watchdog] error', e?.message || e);
        }
      }, 3000);
    },

    startConversationsPoller() {
      if (this._convPoller) return;
      this._convPoller = setInterval(async () => {
        try {
          const resp = await window.axios.get('/conversations');
          const data = resp.data?.data ?? resp.data ?? [];
          const incoming = (Array.isArray(data) ? data : Object.values(data));
          const map = new Map(incoming.map(c => [Number(c.id), c]));
          let changed = false;
          const updated = this.conversations.map(c => {
            const fresh = map.get(Number(c.id));
            if (!fresh) return c;
            // If not currently open, merge last_message and unread_count
            if (!this.selectedConversation || Number(this.selectedConversation.id) !== Number(c.id)) {
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
            this.conversations = [...updated].sort((a, b) => new Date(b.last_message?.created_at || 0) - new Date(a.last_message?.created_at || 0));
          }
        } catch (e) {
          console.warn('[Poller] conversations refresh failed', e?.message || e);
        }
      }, 10000);
    },

    subscribeSidebarChannels() {
      if (!window.Echo) return;
      const currentIds = new Set(this.conversations.map(c => Number(c.id)));
      // Unsubscribe channels that no longer exist
      Object.keys(this.convChannelRefs).forEach(key => {
        const cid = Number(key);
        if (!currentIds.has(cid)) {
          try {
            this.convChannelRefs[cid]?.stopListening('.message.sent');
            this.convChannelRefs[cid]?.stopListening('message.sent');
            window.Echo.leave(`conversations.${cid}`);
          } catch (_) {}
          delete this.convChannelRefs[cid];
        }
      });

      // Subscribe missing ones
      this.conversations.forEach(conv => {
        const cid = Number(conv.id);
        if (!Number.isFinite(cid)) return;
        if (this.convChannelRefs[cid]) return; // already subscribed
        try {
          console.log('[Sidebar] subscribing to', `conversations.${cid}`);
          const handler = (eventName, payload) => {
            const msgId = Number(payload?.id);
            if (!Number.isFinite(msgId)) return;
            const ownerId = Number(payload?.user?.id ?? payload?.user_id);
            console.log('[Sidebar Echo]', eventName, { cid, msgId, ownerId, selected: this.selectedConversation?.id });
            // If this conversation is not currently open, update sidebar info
            if (!this.selectedConversation || Number(this.selectedConversation.id) !== cid) {
              const idx = this.conversations.findIndex(c => Number(c.id) === cid);
              if (idx !== -1) {
                const item = { ...this.conversations[idx] };
                item.last_message = {
                  body: payload?.body || item.last_message?.body || '',
                  created_at: payload?.created_at || item.last_message?.created_at || new Date().toISOString(),
                };
                // Only increment unread if message is from other user
                if (Number.isFinite(ownerId) && ownerId !== this.myId) {
                  item.unread_count = Number(item.unread_count || 0) + 1;
                }
                // Replace element to trigger reactivity, then sort and reassign
                this.conversations.splice(idx, 1, item);
                const sorted = [...this.conversations].sort(
                  (a, b) => new Date(b.last_message?.created_at || 0) - new Date(a.last_message?.created_at || 0)
                );
                this.conversations = sorted;
                console.log('[Sidebar] updated unread/preview', { cid, unread: item.unread_count });
              }
            } else {
              console.log('[Sidebar] same conversation open, skip unread increment', { cid });
            }
          };
          const ch = window.Echo.private(`conversations.${cid}`)
            .listen('.message.sent', (payload) => handler('.message.sent', payload))
            .listen('message.sent', (payload) => handler('message.sent', payload))
            .listenForWhisper('typing', (e) => {
              const fromId = Number(e?.user_id);
              const sameUser = Number.isFinite(fromId) && fromId === this.myId;
              const otherInstance = e?.instance_id && e.instance_id !== this.clientInstanceId;
              const show = !!e?.typing && (!sameUser || otherInstance);
              try { window.console && window.console.log('[Typing][Sidebar] whisper recv', { cid, fromId, sameUser, otherInstance, show, e }); } catch(_) {}
              if (this.selectedConversation && Number(this.selectedConversation.id) === cid) {
                if (show) {
                  this.otherTyping = true;
                  clearTimeout(this._typingStopTimer);
                  this._typingStopTimer = setTimeout(() => { this.otherTyping = false; }, 3000);
                }
                if (e && e.typing === false && (!sameUser || otherInstance)) {
                  this.otherTyping = false;
                  clearTimeout(this._typingStopTimer);
                }
              }
            })
            .error((err) => console.warn('[Echo] Sidebar channel error', `conversations.${cid}`, err));
          console.log('[Sidebar] subscribed', `conversations.${cid}`);
          this.convChannelRefs[cid] = ch;
        } catch (e) {
          console.warn('[Echo] Failed to subscribe sidebar channel', cid, e?.message || e);
        }
      });
    },

    // Ensure messages array has unique ids
    dedupeMessages() {
      if (!Array.isArray(this.messages)) return;
      const seen = new Set();
      const unique = [];
      for (const m of this.messages) {
        const idNum = Number(m?.id);
        if (!Number.isFinite(idNum)) continue;
        if (!seen.has(idNum)) {
          seen.add(idNum);
          unique.push(m);
        }
      }
      if (unique.length !== this.messages.length) {
        this.messages = unique;
        console.warn('[DEBUG] Duplicates removed from messages:', { before: this.messages.length, after: unique.length });
      }
    },

    // Smoothly scroll messages container to the bottom
    scrollToBottom() {
      this.$nextTick(() => {
        const container = this.$refs?.messagesContainer || this.$el.querySelector('.overflow-y-auto.bg-gray-50');
        if (!container) return;
        // Use requestAnimationFrame to ensure DOM painted
        requestAnimationFrame(() => {
          try {
            container.scrollTo({ top: container.scrollHeight, behavior: 'smooth' });
          } catch (_) {
            container.scrollTop = container.scrollHeight;
          }
        });
      });
    },

    // isMine method removed; we now compute `is_mine` on each message object

    async loadConversations() {
      try {
        this.loading = true;
        const response = await window.axios.get('/conversations');
        
        // Handle different response formats
        const data = response.data?.data ?? response.data ?? [];
        this.conversations = (Array.isArray(data) ? data : Object.values(data)).map(c => ({
          ...c,
          unread_count: Number(c.unread_count || 0)
        }));
        
        this.conversations = this.conversations.sort((a, b) => {
          const aTime = a.last_message?.created_at || a.updated_at || a.created_at;
          const bTime = b.last_message?.created_at || b.updated_at || b.created_at;
          return new Date(bTime) - new Date(aTime);
        });
        
        console.log('Conversations loaded:', this.conversations);
        // After loading conversations, subscribe for sidebar updates
        this.subscribeSidebarChannels();
      } catch (error) {
        console.error('Failed to load conversations:', error);
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
        const response = await window.axios.get('/users/search', {
          params: { q: this.searchQuery }
        });
        
        const users = Array.isArray(response.data.data) 
          ? response.data.data 
          : (Array.isArray(response.data) ? response.data : []);
        
        // Filter out current user and existing conversations
        const existingUserIds = new Set([
          this.me?.id,
          ...this.conversations.map(c => c.other_user?.id).filter(Boolean)
        ]);
        
        this.searchResults = users.filter(user => !existingUserIds.has(user.id));
        this.showAddUser = this.searchResults.length > 0;
        
        console.log('Search results:', this.searchResults);
      } catch (error) {
        console.error('Failed to search users:', error);
        this.searchResults = [];
        this.showAddUser = false;
      }
    },

    async addUser(user) {
      try {
        console.log('Adding user:', user);
        
        // Add contact and create conversation
        const response = await window.axios.post('/contacts', {
          contact_user_id: user.id
        });
        
        console.log('Contact added:', response.data);
        
        // Reload conversations to get the new one
        await this.loadConversations();
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
        console.error('Failed to add user:', error);
        const message = error.response?.data?.message || error.message || 'Failed to add user';
        alert(`Error: ${message}`);
      }
    },

    async selectConversation(conversation) {
      if (this.selectedConversation?.id === conversation.id) {
        return;
      }
      
      console.log('Selecting conversation:', conversation);
      // Zero unread count for this conversation in sidebar immediately
      const openId = Number(conversation.id);
      const idx = this.conversations.findIndex(c => Number(c.id) === openId);
      if (idx !== -1) {
        const updated = { ...this.conversations[idx], unread_count: 0 };
        this.conversations.splice(idx, 1, updated);
        // Resort to keep order by last activity and force reactivity
        this.conversations = [...this.conversations].sort((a, b) => {
          const aTime = a.last_message?.created_at || a.updated_at || a.created_at;
          const bTime = b.last_message?.created_at || b.updated_at || b.created_at;
          return new Date(bTime) - new Date(aTime);
        });
      }
      // Unsubscribe from previous channel
      if (this.channelRef && this.channelName) {
        try {
          this.channelRef.stopListening('.message.sent');
          this.channelRef.stopListening('message.sent');
          this.channelRef.stopListening('.read.updated');
          this.channelRef.stopListening('read.updated');
          window.Echo.leave(this.channelName);
        } catch (e) { /* noop */ }
      }
      this.channelRef = null;
      this.channelName = null;
      this.selectedConversation = conversation;
      try { window.__chatApp = this; } catch(_) {}
      try { window.console && window.console.log('[Typing][State] selectedConversation', { id: this.selectedConversation?.id }); } catch(_) {}
      this.messages = [];
      this.otherTyping = false;
      // Leave previous presence channel
      try {
        if (this.presenceRef && this.presenceChannelName) {
          window.Echo.leave(this.presenceChannelName);
        }
      } catch(_) {}
      this.presenceRef = null;
      this.presenceChannelName = null;
      
      // Load messages for this conversation
      await this.loadMessages();

      // Subscribe to Echo for real-time receipts on this conversation
      this.subscribeToConversationChannel();
      this.subscribeToPresenceChannel();

      // Auto scroll on open
      this.scrollToBottom();

      // Ensure sidebar subscriptions are active after switching
      this.subscribeSidebarChannels();
    },

    subscribeToConversationChannel() {
      if (!this.selectedConversation || !window.Echo) return;
      const name = `conversations.${this.selectedConversation.id}`;
      this.channelName = name;
      try {
        this.channelRef = window.Echo.private(name)
          .subscribed(() => {})
          .error(() => {});
        this.channelRef
          .listen('.message.sent', (payload) => {
            // payload: { id, body, user: {id,...}, created_at }
            const msg = payload || {};
            const msgId = Number(msg.id);
            if (!Number.isFinite(msgId)) {
              console.warn('[Echo] message.sent skipped: invalid id', msg?.id);
              return;
            }
            const ownerId = Number(msg.user?.id ?? msg.user_id);
            const isMine = Number.isFinite(ownerId) && ownerId === this.myId;
            // Ignore if this message belongs to another conversation or already exists
            if (!this.selectedConversation || this.channelName !== `conversations.${this.selectedConversation.id}`) return;
            console.log('[Echo] message.sent received', { id: msgId, ownerId, isMine });
            if (this.messages.some(m => Number(m.id) === msgId)) return;

            const newMessage = {
              id: msgId,
              body: msg.body || '',
              user_id: ownerId,
              created_at: msg.created_at,
              user: msg.user || { id: ownerId },
              is_mine: isMine,
              delivered_at: null,
              read_at: null,
              status: isMine ? 'Sent' : '',
            };

            this.messages.push(newMessage);
            // Keep sorted (handle missing dates)
            this.messages.sort((a, b) => {
              const da = a.created_at ? new Date(a.created_at).getTime() : 0;
              const db = b.created_at ? new Date(b.created_at).getTime() : 0;
              return da - db;
            });
            // Remove any duplicates by id
            this.dedupeMessages();
            // Force Alpine to notice array change
            this.messages = [...this.messages];
            this.scrollToBottom();

            // If message from other user in active conversation, acknowledge read immediately
            if (!isMine && this.selectedConversation) {
              window.axios.post(`/conversations/${this.selectedConversation.id}/read`, { message_id: msg.id })
                .catch(() => {});
            }
          })
          .listenForWhisper('typing', (e) => {
            const fromId = Number(e?.user_id);
            const sameUser = Number.isFinite(fromId) && fromId === this.myId;
            const otherInstance = e?.instance_id && e.instance_id !== this.clientInstanceId;
            const show = !!e?.typing && (!sameUser || otherInstance);
            try { window.console && window.console.log('[Typing] whisper recv (private)', { name, fromId, sameUser, otherInstance, show, e }); } catch(_) {}
            if (show) {
              try { window.console && window.console.log('[Typing] SHOW indicator (private)', { fromId, e }); } catch(_) {}
              this.otherTyping = true;
              clearTimeout(this._typingStopTimer);
              this._typingStopTimer = setTimeout(() => { this.otherTyping = false; }, 3000);
            }
            if (e && e.typing === false && (!sameUser || otherInstance)) {
              try { window.console && window.console.log('[Typing] HIDE indicator (private)', { fromId, e }); } catch(_) {}
              this.otherTyping = false;
              clearTimeout(this._typingStopTimer);
            }
          })
          .listen('.read.updated', (e) => {
            // e: { user_id, message_id }
            const readerId = Number(e?.user_id);
            const maxId = Number(e?.message_id);
            if (!Number.isFinite(readerId) || !Number.isFinite(maxId)) return;
            // Only update if the other participant read our messages
            if (readerId === this.myId) return;
            let changed = false;
            const nowIso = new Date().toISOString();
            this.messages.forEach(m => {
              if (m.is_mine && Number(m.id) <= maxId && !m.read_at) {
                m.read_at = nowIso;
                // Keep delivered_at if already set; otherwise set to created
                if (!m.delivered_at) m.delivered_at = m.created_at || nowIso;
                m.status = 'Read';
                changed = true;
              }
            });
            if (changed) {
              // Force Alpine to re-render
              this.messages = [...this.messages];
            }
          });
        // Raw Pusher bind for private
        try {
          const pusher = window.Echo?.connector?.pusher;
          const rawPriv = pusher?.channel(`private-${name}`);
          rawPriv?.bind('client-typing', (e) => {
            try { window.console && window.console.log('[Typing][RAW] recv client-typing (private)', { name: `private-${name}`, e }); } catch(_) {}
          });
        } catch(_) {}
      } catch (e) {
        // noop
      }
    },

    bindEchoConnectionHandlers() {
      try {
        const pusher = window.Echo?.connector?.pusher;
        if (!pusher || this._echoHandlersBound) return;
        this._echoHandlersBound = true;
        const conn = pusher.connection;
        if (window.Echo && window.Echo.connector && window.Echo.connector.pusher) {
          const pusher = window.Echo.connector.pusher;
          pusher.connection.bind('connected', () => {
            console.log('[Echo] connected');
            // Re-subscribe active conversation channel
            if (this.selectedConversation) {
              this.subscribeToConversationChannel();
            }
            // Ensure sidebar channels are active after reconnect
            this.subscribeSidebarChannels();
          });
          pusher.connection.bind('disconnected', () => console.warn('[Echo] disconnected'));
          pusher.connection.bind('error', (err) => console.warn('[Echo] error', err));
        }
        conn.bind('unavailable', () => {
          console.warn('[Echo] unavailable');
        });
        conn.bind('failed', () => {
          console.error('[Echo] failed');
        });
        pusher.bind('error', (err) => {
          console.warn('[Echo] error', err);
        });
      } catch (e) {
        console.warn('[Echo] bind handlers failed', e?.message || e);
      }
    },

    async loadMessages() {
      if (!this.selectedConversation) {
        return;
      }
      
      try {
        console.log('[DEBUG] Loading messages for conversation:', this.selectedConversation.id);
        
        const response = await window.axios.get(`/conversations/${this.selectedConversation.id}/messages`);
        
        console.log('[DEBUG] Raw API response:', response);
        console.log('[DEBUG] Messages response data:', response.data);
        
        // Handle different response formats
        let messages = [];
        if (response.data && Array.isArray(response.data.data)) {
          console.log('[DEBUG] Parsing messages from response.data.data (array)');
          messages = response.data.data;
        } else if (response.data && typeof response.data.data === 'object' && response.data.data !== null) {
          console.log('[DEBUG] Parsing messages from response.data.data (object)');
          messages = Object.values(response.data.data);
        } else if (Array.isArray(response.data)) {
          console.log('[DEBUG] Parsing messages from response.data (direct array)');
          messages = response.data;
        }
        
        console.log(`[DEBUG] Found ${messages.length} messages from API.`);

        this.messages = messages.map(msg => {
          const userObj = msg.user || { id: msg.user_id, name: 'Unknown User' };
          const ownerId = Number(userObj.id ?? msg.user_id);
          const deliveredAt = msg.delivered_at ?? msg.deliveredAt ?? null;
          const readAt = msg.read_at ?? msg.readAt ?? null;
          const isMine = Number.isFinite(ownerId) && ownerId === this.myId;
          const status = isMine
            ? (readAt ? 'Read' : (deliveredAt ? 'Delivered' : 'Sent'))
            : '';
          return {
            id: Number(msg.id),
            body: msg.body || '',
            user_id: Number(msg.user_id ?? userObj.id),
            created_at: msg.created_at,
            user: userObj,
            is_mine: isMine,
            delivered_at: deliveredAt,
            read_at: readAt,
            status,
          };
        }).sort((a, b) => new Date(a.created_at) - new Date(b.created_at));
        // Remove duplicates and force Alpine to refresh
        this.dedupeMessages();
        this.messages = [...this.messages];
        
        console.log('[DEBUG] Final processed messages array:', JSON.parse(JSON.stringify(this.messages)));
        
        // Scroll to bottom
        this.$nextTick(async () => {
          this.scrollToBottom();

          // Mark messages as read up to the last message id
          try {
            const last = this.messages[this.messages.length - 1];
            if (last && this.selectedConversation) {
              await window.axios.post(`/conversations/${this.selectedConversation.id}/read`, {
                message_id: last.id
              });
              console.log('[DEBUG] Marked messages as read up to id:', last.id);
            }
          } catch (e) {
            console.warn('[DEBUG] Failed to mark messages as read:', e?.response?.data || e.message);
          }
        });
        
      } catch (error) {
        console.error('[DEBUG] Failed to load messages:', error);
        this.messages = [];
      }
    },

    async sendMessage() {
      if (!this.messageText.trim() || !this.selectedConversation) {
        this.stopTyping();
        return;
      }
      
      const messageBody = this.messageText.trim();
      this.messageText = '';
      
      try {
        console.log('Sending message:', messageBody);
        
        const response = await window.axios.post('/messages', {
          conversation_id: this.selectedConversation.id,
          body: messageBody
        });
        
        console.log('Message sent:', response.data);
        const respId = Number(response.data?.id);
        const respCreated = response.data?.created_at || new Date().toISOString();
        if (!Number.isFinite(respId)) {
          console.warn('[DEBUG] Response without valid id, skipping local push');
        } else {
          // If Echo already delivered the message, skip local insert
          if (!this.messages.some(m => Number(m.id) === respId)) {
            const newMessage = {
              id: respId,
              body: messageBody,
              user_id: this.myId,
              created_at: respCreated,
              user: this.me,
              is_mine: true,
              delivered_at: null,
              read_at: null,
              status: 'Sent'
            };
            this.messages.push(newMessage);
            // Keep sorted and dedupe
            this.messages.sort((a, b) => {
              const da = a.created_at ? new Date(a.created_at).getTime() : 0;
              const db = b.created_at ? new Date(b.created_at).getTime() : 0;
              return da - db;
            });
            this.dedupeMessages();
            this.messages = [...this.messages];
          }
        }
        
        // Update conversation's last message (use server timestamp)
        if (this.selectedConversation) {
          this.selectedConversation.last_message = {
            body: messageBody,
            created_at: respCreated
          };
        }
        
        // Scroll to bottom
        this.scrollToBottom();
        // Stop typing after send
        this.stopTyping();
        
      } catch (error) {
        console.error('Failed to send message:', error);
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
