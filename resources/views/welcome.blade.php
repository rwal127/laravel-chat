<x-guest-layout>
    <h1 class="text-2xl font-bold text-center my-12">
        Welcome to
        <span class="text-[#1b1b18] relative inline-block" id="seek-text">Test Chat</span>
    </h1>
    <footer class="w-full lg:max-w-4xl max-w-[335px] text-sm mb-6 not-has-[nav]:hidden">
        @if (Route::has('login'))
            <nav class="flex items-center justify-end gap-4">
                @auth
                    <a
                        href="{{ url('/chat') }}"
                        class="inline-block px-5 py-1.5 border-[#19140035] hover:border-[#1915014a] border text-[#1b1b18] rounded-sm text-sm leading-normal"
                    >
                        Chat
                    </a>
                @else
                    <a
                        href="/login"
                        class="inline-block px-5 py-1.5 text-[#1b1b18] border border-transparent hover:border-[#19140035]  rounded-sm text-sm leading-normal"
                    >
                        Log in
                    </a>

                    @if (Route::has('register'))
                        <a
                            href="/register"
                            class="inline-block px-5 py-1.5  border-[#19140035] hover:border-[#1915014a] border text-[#1b1b18] rounded-sm text-sm leading-normal">
                            Register
                        </a>
                    @endif
                @endauth
            </nav>
        @endif
    </footer>

</x-guest-layout>
