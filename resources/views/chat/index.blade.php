<x-app-layout>
<x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Chat') }}
        </h2>
    </x-slot>

  <script>
    window.Me = @json(Auth::user()?->only(['id','name','avatar']));
  </script>

  <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="text-gray-900">


<!-- Chat App -->
  <div x-ref="chatRoot" class="bg-gray-50" x-data="chatApp()" x-init="init()">
    <div class="flex h-full">
      <!-- Sidebar -->
      <div class="w-80 bg-white border-r border-gray-200 flex flex-col">
        <!-- Search Header -->
        <div class="p-4 border-b border-gray-200">
          <div class="relative">
            <input
              x-model="searchQuery"
              @input.debounce.300ms="searchUsers()"
              type="text"
              placeholder="Search for users..."
              class="w-full pl-4 pr-10 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
            />
          </div>

          <!-- User Search Results -->
          <div x-show="searchQuery.length > 0 && searchResults.length > 0" class="mt-2 max-h-60 overflow-y-auto bg-white border border-gray-200 rounded-lg shadow-lg">
            <template x-for="user in searchResults" :key="user.id">
              <div class="flex items-center p-3 hover:bg-gray-50 cursor-pointer" @click="addUser(user)">
                <img
                  :src="user.avatar_url || '/images/default-avatar.svg'"
                  :alt="user.name"
                  class="w-8 h-8 rounded-full mr-3"
                />
                <div class="flex-1">
                  <div class="font-medium text-gray-900" x-text="user.name"></div>
                  <div class="text-sm text-gray-500" x-text="user.email"></div>
                </div>
                <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
              </div>
            </template>
          </div>
        </div>

        <!-- Conversations List -->
        <div class="flex-1 overflow-y-auto">
          <template x-for="conversation in conversations" :key="conversation.id + '-' + onlineUsersVersion">
            <div
              @click="selectConversation(conversation)"
              :class="[
                'flex items-center p-4 hover:bg-gray-50 cursor-pointer border-b border-gray-100',
                selectedConversation?.id === conversation.id ? 'bg-indigo-50 border-indigo-200' : ''
              ]"
            >
              <!-- Avatar -->
              <div class="relative mr-3 flex-shrink-0">
                <img
                  :src="conversation.user?.avatar_url || '/images/default-avatar.svg'"
                  :alt="conversation.user?.name || 'User'"
                  class="w-12 h-12 rounded-full"
                />
                <span
                  x-show="isOnline(conversation.user?.id)"
                  class="absolute -bottom-0.5 -right-0.5 block h-3 w-3 rounded-full ring-2 ring-white bg-green-500"
                  :title="'Online'"
                ></span>
              </div>

              <!-- Conversation Info -->
              <div class="flex-1 min-w-0">
                <div class="flex items-center justify-between">
                  <p class="text-sm font-semibold text-gray-900 truncate" x-text="conversation.user?.name || conversation.name || 'Unknown User'"></p>
                  <span class="text-xs text-gray-500" x-text="formatTime(conversation.last_message?.created_at)"></span>
                </div>
                <p class="text-sm text-gray-600 truncate mt-1" x-text="conversation.last_message?.body || (conversation.last_message?.has_attachments ? 'Attachment' : 'No messages yet')"></p>
              </div>

              <!-- Unread Badge -->
              <div x-show="conversation.unread_count > 0" class="ml-2">
                <span class="inline-flex items-center justify-center w-5 h-5 text-xs font-medium text-white bg-indigo-600 rounded-full" x-text="conversation.unread_count"></span>
              </div>
            </div>
          </template>

          <!-- Empty State -->
          <div x-show="conversations.length === 0" class="p-8 text-center text-gray-500">
            <svg class="w-12 h-12 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
            </svg>
            <p class="text-sm">No conversations yet</p>
            <p class="text-xs mt-1">Search for users above to start chatting</p>
          </div>
        </div>
      </div>

      <!-- Chat Area -->
      <div class="flex-1 flex flex-col min-h-0">
        <!-- Chat Header -->
        <div x-show="selectedConversation" class="bg-white border-b border-gray-200 p-4">
          <div class="flex items-center justify-between">
            <img
              :src="selectedConversation?.user?.avatar_url || '/images/default-avatar.svg'"
              :alt="selectedConversation?.user?.name || 'User'"
              class="w-10 h-10 rounded-full mr-3"
            />
            <div class="flex-1">
              <h2 class="font-semibold text-gray-900" x-text="selectedConversation?.user?.name || selectedConversation?.name || 'Unknown User'"></h2>
              <p class="text-sm text-gray-500">
                <span x-show="otherTyping">Typing…</span>
                <template x-if="!otherTyping">
                  <span :key="'header-status-' + onlineUsersVersion" :class="(onlineUsersVersion, isOnline(selectedConversation?.user?.id)) ? 'text-green-600' : 'text-gray-500'" x-text="(onlineUsersVersion, isOnline(selectedConversation?.user?.id)) ? 'Online' : 'Offline'"></span>
                </template>
              </p>
            </div>
            <!-- Search button -->
            <div class="ml-3">
              <button type="button" @click="searchOpen ? closeSearch() : openSearch()" class="inline-flex items-center justify-center w-10 h-10 rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-gray-700" title="Search in conversation">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <circle cx="11" cy="11" r="8"></circle>
                  <path d="M21 21l-4.35-4.35"></path>
                </svg>
              </button>
            </div>
          </div>
          <!-- Search bar -->
          <div x-show="searchOpen" class="mt-3 flex items-center gap-2">
            <input type="text" x-model="messageSearchQuery" @input.debounce.300ms="performSearch()" placeholder="Search messages..." class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />
            <span class="text-xs text-gray-500 min-w-[60px] text-center" x-text="searchMatches.length ? (searchCursor + 1) + ' / ' + searchMatches.length : '0 / 0'"></span>
            <button type="button" @click="prevMatch()" class="px-2 py-2 rounded-lg border border-gray-300 bg-white hover:bg-gray-50" title="Previous (Up)">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5l-7 7h14z"/></svg>
            </button>
            <button type="button" @click="nextMatch()" class="px-2 py-2 rounded-lg border border-gray-300 bg-white hover:bg-gray-50" title="Next (Down)">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19l7-7H5z"/></svg>
            </button>
            <button type="button" @click="closeSearch()" class="px-2 py-2 rounded-lg border border-gray-300 bg-white hover:bg-gray-50" title="Close">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg>
            </button>
          </div>
        </div>

        <!-- Messages Area -->
        <div x-ref="messagesContainer" @scroll.passive="onMessagesScroll($event)" class="flex-1 overflow-y-auto bg-gray-50 p-4">
          <!-- No Conversation Selected -->
          <div x-show="!selectedConversation" class="h-full flex items-center justify-center">
            <div class="text-center text-gray-500">
              <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
              </svg>
              <h3 class="text-lg font-medium mb-2">Select a conversation</h3>
              <p class="text-sm">Choose a conversation from the sidebar to start messaging</p>
            </div>
          </div>

          <!-- Messages -->
          <div x-show="selectedConversation" class="space-y-4">
            <!-- Top loading indicator when fetching older messages -->
            <div x-show="loadingOlder" class="flex justify-center text-xs text-gray-500 py-1">Loading…</div>
            <template x-for="message in messages" :key="message.id">
              <div :id="'msg-' + message.id" :class="{'flex justify-end': message.is_mine, 'flex justify-start': !message.is_mine}">
                <div class="flex items-start gap-2.5">
                  <div class="flex flex-col gap-1 w-full max-w-[420px]">
                    <div @click="toggleActions(message)" class="group relative flex flex-col leading-1.5 p-3 border border-transparent cursor-pointer select-text" :class="{
                      'rounded-e-xl rounded-es-xl bg-gray-100': !message.is_mine,
                      'rounded-s-xl rounded-ee-xl bg-purple-200': message.is_mine,
                      'ring-2 ring-amber-300': Array.isArray(searchMatches) && searchMatches.includes(Number(message.id)),
                      'ring-2 ring-indigo-400': Array.isArray(searchMatches) && Number(searchMatches[searchCursor] || -1) === Number(message.id)
                    }">
                      <!-- Actions (hover or click to show) -->
                      <div x-show="message.is_mine && canEdit(message)"
                           class="absolute -top-2 right-1 transition-opacity duration-150"
                           :class="{
                             'opacity-100': actionsForMessageId === message.id,
                             'opacity-0 group-hover:opacity-100': actionsForMessageId !== message.id
                           }"
                      >
                        <div class="flex items-center gap-1 text-xs text-gray-700">
                          <!-- Edit (pencil) -->
                          <button type="button" title="Edit" @click.stop="startEditMessage(message)"
                                  class="p-1 rounded bg-white/80 hover:bg-white shadow border border-gray-200">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                              <path d="M12 20h9" />
                              <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z" />
                            </svg>
                          </button>
                          <!-- Delete (x) -->
                          <button type="button" title="Delete" @click.stop="deleteMessage(message)"
                                  class="p-1 rounded bg-white/80 hover:bg-white shadow border border-gray-200">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                              <path d="M18 6L6 18" />
                              <path d="M6 6l12 12" />
                            </svg>
                          </button>
                        </div>
                      </div>

                      <!-- Body / Deleted -->
                      <template x-if="!message.deleted_at">
                        <div class="space-y-2">
                          <p x-show="message.body" class="text-sm text-gray-900 whitespace-pre-line" x-text="message.body"></p>
                          <!-- Attachments -->
                          <template x-if="message.has_attachments && Array.isArray(message.attachments) && message.attachments.length">
                            <div class="flex flex-col gap-2">
                              <template x-for="att in message.attachments" :key="att.id">
                                <div>
                                  <!-- Image preview -->
                                  <template x-if="att.is_image">
                                    <a :href="att.download_url || att.url" target="_blank" rel="noopener" class="block">
                                      <img :src="att.url" :alt="att.original_name || 'image'" class="max-h-72 rounded-lg border border-gray-200" />
                                    </a>
                                  </template>
                                  <!-- File link -->
                                  <template x-if="!att.is_image">
                                    <a :href="att.download_url || att.url" target="_blank" rel="noopener" class="inline-flex items-center gap-2 px-3 py-2 rounded border border-gray-200 bg-white hover:bg-gray-50 text-sm text-gray-700">
                                      <svg class="w-4 h-4 text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 1 1 5.66 5.66L9.88 17.75a2 2 0 1 1-2.83-2.83l8.49-8.49" />
                                      </svg>
                                      <span x-text="att.original_name || 'attachment'"></span>
                                      <span class="text-xs text-gray-500" x-text="att.size_bytes ? '(' + (Math.round(att.size_bytes/1024) + ' KB') + ')' : ''"></span>
                                    </a>
                                  </template>
                                </div>
                              </template>
                            </div>
                          </template>
                        </div>
                      </template>
                      <template x-if="message.deleted_at">
                        <p class="text-sm italic text-gray-500">Message deleted</p>
                      </template>

                      <!-- Footer: time, edited, ticks -->
                      <div class="mt-1 flex items-center gap-2 text-xs text-gray-600" :class="{'justify-end': message.is_mine, 'justify-start': !message.is_mine}">
                        <span x-text="formatTime(message.created_at)"></span>
                        <span x-show="message.edited_at && !message.deleted_at" class="italic">(edited)</span>
                        <template x-if="message.is_mine && !message.deleted_at">
                          <span class="inline-flex items-center">
                            <template x-if="message.read_at">
                              <svg class="w-4 h-4 text-blue-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M17 6l-8 8-4-4"></path>
                                <path d="M21 6l-8 8"></path>
                              </svg>
                            </template>
                            <template x-if="!message.read_at">
                              <svg class="w-4 h-4 text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M20 6L9 17l-5-5"></path>
                              </svg>
                            </template>
                          </span>
                        </template>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </template>
          </div>
        </div>

        <!-- Message Input -->
        <div x-show="selectedConversation" class="bg-white border-t border-gray-200 px-4 pt-4 pb-[calc(env(safe-area-inset-bottom)_+_1rem)]">
          <!-- Normal send mode -->
          <form x-show="!editingMessageId" @submit.prevent="sendMessage" class="flex items-center space-x-3">
            <!-- Paperclip -->
            <div class="relative group">
              <input x-ref="fileInput" type="file" class="hidden" multiple @change="onFileSelected($event)" accept="image/jpeg,image/png,image/webp,image/gif,application/pdf,text/plain,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/zip,.zip,.doc,.docx,.pdf,.jpg,.jpeg,.png,.webp,.gif,.txt" />
              <button type="button" @click="openFilePicker()" title="Attach file — Allowed: jpg, jpeg, png, webp, gif, pdf, txt, doc, docx, zip. Max 10 MB" class="inline-flex items-center justify-center w-10 h-10 rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 focus:outline-none">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 1 1 5.66 5.66L9.88 17.75a2 2 0 1 1-2.83-2.83l8.49-8.49" />
                </svg>
              </button>
              <!-- Tooltip: allowed types and size -->
              <div class="absolute left-0 -top-2 -translate-y-full transform z-10 hidden group-hover:block group-focus-within:block">
                <div class="max-w-lg text-xs bg-gray-900 text-white px-3 py-2 rounded shadow whitespace-normal break-words">
                  Allowed: JPG, JPEG, PNG, WEBP, GIF, PDF, TXT, DOC, DOCX, ZIP. Max 10 MB
                </div>
              </div>
            </div>
            <div class="flex-1">
              <!-- Pending attachments preview -->
              <div x-show="pendingAttachments.length" class="mb-2 space-y-2">
                <div class="flex flex-wrap gap-2">
                  <template x-for="(att, idx) in pendingAttachments" :key="idx">
                    <div class="group relative border border-gray-200 rounded-md p-1 bg-white shadow-sm">
                      <button type="button" @click="removePendingAttachment(idx)" title="Remove" class="absolute -top-2 -right-2 hidden group-hover:block bg-white border border-gray-300 rounded-full p-0.5 shadow">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg>
                      </button>
                      <template x-if="att.is_image && att.url">
                        <img :src="att.url" :alt="att.name" class="h-16 w-16 object-cover rounded" />
                      </template>
                      <template x-if="!att.is_image || !att.url">
                        <div class="flex items-center gap-2 px-2 py-1">
                          <svg class="w-4 h-4 text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 1 1 5.66 5.66L9.88 17.75a2 2 0 1 1-2.83-2.83l8.49-8.49" /></svg>
                          <span class="text-xs text-gray-700" x-text="att.name"></span>
                          <span class="text-[10px] text-gray-500" x-text="att.size ? Math.round(att.size/1024) + ' KB' : ''"></span>
                        </div>
                      </template>
                    </div>
                  </template>
                </div>
              </div>
              <textarea
                x-model="messageText"
                @keydown.enter.prevent="sendMessage"
                @blur="stopTyping()"
                @input.debounce.150ms="handleTypingInput()"
                placeholder="Type a message..."
                rows="1"
                class="w-full px-3 py-2 text-sm min-h-[2.75rem] border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 resize-none box-border"
              ></textarea>
            </div>
            <button
              type="submit"
              :disabled="!messageText.trim() && !pendingAttachments.length"
              class="inline-flex items-center justify-center w-10 h-10 border border-transparent text-sm font-medium rounded-lg text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
              title="Send"
            >
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M5 12h14" />
                <path d="M12 5l7 7-7 7" />
              </svg>
            </button>
          </form>

          <!-- Edit mode -->
          <form x-show="editingMessageId" @submit.prevent="confirmEditMessage" class="flex items-end space-x-3">
            <div class="flex-1">
              <textarea
                x-model="editingText"
                placeholder="Edit your message..."
                rows="1"
                class="w-full px-3 py-2 border border-amber-400 rounded-lg focus:ring-2 focus:ring-amber-500 focus:border-amber-500 resize-none"
              ></textarea>
              <p class="text-xs text-amber-600 mt-1">Editing message (5-minute window). Press Save or Cancel.</p>
            </div>
            <div class="flex items-center gap-2">
              <button type="button" @click="cancelEdit" class="px-3 py-2 rounded-lg border text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
              <button
                type="submit"
                :disabled="!editingText.trim()"
                class="px-4 py-2 border border-transparent text-sm font-medium rounded-lg text-white bg-amber-600 hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-amber-500 disabled:opacity-50 disabled:cursor-not-allowed"
              >Save</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
  </div>
            </div>
        </div>
    </div>
</x-app-layout>
