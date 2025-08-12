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



  <div class="min-h-screen bg-gray-50" x-data="chatApp()" x-init="init()">
    <div class="flex h-screen">
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
                <p class="text-sm text-gray-600 truncate mt-1" x-text="conversation.last_message?.body || 'No messages yet'"></p>
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
      <div class="flex-1 flex flex-col">
        <!-- Chat Header -->
        <div x-show="selectedConversation" class="bg-white border-b border-gray-200 p-4">
          <div class="flex items-center">
            <img
              :src="selectedConversation?.user?.avatar_url || '/images/default-avatar.svg'"
              :alt="selectedConversation?.user?.name || 'User'"
              class="w-10 h-10 rounded-full mr-3"
            />
            <div>
              <h2 class="font-semibold text-gray-900" x-text="selectedConversation?.user?.name || selectedConversation?.name || 'Unknown User'"></h2>
              <p class="text-sm text-gray-500">
                <span x-show="otherTyping">Typing…</span>
                <template x-if="!otherTyping">
                  <span :key="'header-status-' + onlineUsersVersion" :class="(onlineUsersVersion, isOnline(selectedConversation?.user?.id)) ? 'text-green-600' : 'text-gray-500'" x-text="(onlineUsersVersion, isOnline(selectedConversation?.user?.id)) ? 'Online' : 'Offline'"></span>
                </template>
              </p>
            </div>
          </div>
        </div>

        <!-- Messages Area -->
        <div x-ref="messagesContainer" class="flex-1 overflow-y-auto bg-gray-50 p-4">
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
            <template x-for="message in messages" :key="message.id">
              <div :class="{'flex justify-end': message.is_mine, 'flex justify-start': !message.is_mine}">
                <div class="flex items-start gap-2.5">
                  <div class="flex flex-col gap-1 w-full max-w-[420px]">
                    <div class="flex flex-col leading-1.5 p-3 border border-transparent" :class="{
                      'rounded-s-xl rounded-ee-xl bg-gray-100': !message.is_mine,
                      'rounded-e-xl rounded-es-xl bg-purple-200': message.is_mine
                    }">
                      <p class="text-sm text-gray-900" x-text="message.body"></p>
                      <div class="mt-1 flex items-center gap-2 text-xs text-gray-500" :class="{'justify-end': message.is_mine, 'justify-start': !message.is_mine}">
                        <span x-text="formatTime(message.created_at)"></span>
                        <template x-if="message.is_mine">
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
        <div x-show="selectedConversation" class="bg-white border-t border-gray-200 p-4">
          <form @submit.prevent="sendMessage" class="flex items-end space-x-3">
            <div class="flex-1">
              <textarea
                x-model="messageText"
                @keydown.enter.prevent="sendMessage"
                @blur="stopTyping()"
                @input.debounce.150ms="handleTypingInput()"
                placeholder="Type a message..."
                rows="1"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 resize-none"
              ></textarea>
            </div>
            <button
              type="submit"
              :disabled="!messageText.trim()"
              class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-lg text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path>
              </svg>
            </button>
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
