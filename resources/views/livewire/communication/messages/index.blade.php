<div class="grid grid-cols-1 gap-4 lg:grid-cols-3" style="min-height: 32rem;">
    <header class="lg:col-span-3">
        <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.messages_screen.title') }}</h1>
        <p class="mt-1 text-sm text-charcoal/70">{{ __('opes.messages_screen.intro') }}</p>
    </header>

    @if ($error !== '')
        <p class="lg:col-span-3 rounded border border-heritage-red/40 bg-heritage-red/10 p-3 text-sm text-heritage-red" role="alert">{{ $error }}</p>
    @endif

    <aside class="rounded-lg border border-border-primary bg-white shadow-sm">
        <div class="border-b border-border-primary p-3">
            <button type="button" wire:click="$set('showCompose', true)"
                    class="w-full rounded bg-primary px-3 py-2 text-sm font-semibold text-white">
                {{ __('opes.messages_screen.new_conversation') }}
            </button>
        </div>

        <ul class="max-h-[28rem] overflow-y-auto divide-y divide-border-primary">
            @forelse ($threads as $thread)
                <li>
                    <button type="button" wire:click="selectThread({{ $thread['id'] }})"
                            class="flex w-full items-start justify-between gap-2 p-3 text-left text-sm hover:bg-sand/30 {{ $activeThread?->id === $thread['id'] ? 'bg-sand/40' : '' }}">
                        <span class="min-w-0">
                            <span class="block truncate font-medium text-charcoal">{{ $thread['title'] }}</span>
                            <span class="block text-xs text-charcoal/60">{{ $thread['last_message_at'] }}</span>
                        </span>
                        @if ($thread['unread_count'] > 0)
                            <span class="shrink-0 rounded-full bg-heritage-red px-2 py-0.5 text-xs font-semibold text-white">
                                {{ $thread['unread_count'] }}
                            </span>
                        @endif
                    </button>
                </li>
            @empty
                <li class="p-4 text-center text-sm text-charcoal/60">{{ __('opes.messages_screen.empty') }}</li>
            @endforelse
        </ul>
    </aside>

    <section class="lg:col-span-2 flex flex-col rounded-lg border border-border-primary bg-white shadow-sm">
        @if ($activeThread === null)
            <div class="flex flex-1 items-center justify-center p-8 text-sm text-charcoal/60">
                {{ __('opes.messages_screen.select_a_conversation') }}
            </div>
        @else
            <div class="border-b border-border-primary p-3">
                <h2 class="text-sm font-semibold text-charcoal">{{ $activeThread->title }}</h2>
            </div>

            <div class="flex-1 space-y-3 overflow-y-auto p-3" style="max-height: 24rem;">
                @foreach ($activeMessages as $message)
                    <div class="{{ $message->sender_id === auth()->id() ? 'ml-auto max-w-[80%] rounded-lg bg-primary/10 p-2' : 'mr-auto max-w-[80%] rounded-lg bg-sand/30 p-2' }}">
                        <p class="flex items-center gap-1 text-xs font-semibold text-charcoal">
                            <span>{{ $message->sender_name }}</span>
                            <x-verified-badge :official="(bool) $message->sender_is_official"/>
                            @if ($message->sender_username)
                                <span class="font-normal text-charcoal/60">&commat;{{ $message->sender_username }}</span>
                            @endif
                        </p>
                        <p class="mt-0.5 text-sm text-charcoal whitespace-pre-wrap">{{ $message->body }}</p>
                        <p class="mt-0.5 text-[10px] text-charcoal/60">{{ $message->created_at }}</p>
                    </div>
                @endforeach
            </div>

            <form wire:submit.prevent="send" class="flex gap-2 border-t border-border-primary p-3">
                <input type="text" wire:model="reply" placeholder="{{ __('opes.messages_screen.write_a_reply') }}"
                       class="flex-1 rounded border border-border-primary p-2 text-sm">
                <button type="submit" class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white">
                    {{ __('opes.messages_screen.send') }}
                </button>
            </form>
        @endif
    </section>

    @if ($showCompose)
        <form wire:submit.prevent="startThread" class="lg:col-span-3 rounded-lg border border-primary/40 bg-primary/5 p-4">
            <h2 class="text-sm font-semibold text-charcoal">{{ __('opes.messages_screen.new_conversation') }}</h2>

            <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                <label class="text-sm">
                    <span class="block text-charcoal/70">{{ __('opes.messages_screen.title_label') }}</span>
                    <input type="text" wire:model="newTitle" class="mt-1 w-full rounded border border-border-primary p-2">
                </label>
                <label class="text-sm">
                    <span class="block text-charcoal/70">{{ __('opes.messages_screen.recipient') }}</span>
                    <input type="text" wire:model="newRecipient" autocomplete="off"
                           placeholder="{{ __('opes.messages_screen.recipient_placeholder') }}"
                           class="mt-1 w-full rounded border border-border-primary p-2">
                    <span class="mt-1 block text-xs text-charcoal/60">{{ __('opes.messages_screen.recipient_hint') }}</span>
                </label>
            </div>

            <label class="mt-2 block text-sm">
                <span class="block text-charcoal/70">{{ __('opes.messages_screen.message') }}</span>
                <textarea wire:model="newBody" rows="3" class="mt-1 w-full rounded border border-border-primary p-2"></textarea>
            </label>

            <div class="mt-2 flex gap-2">
                <button type="submit" class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white">
                    {{ __('opes.messages_screen.send') }}
                </button>
                <button type="button" wire:click="$set('showCompose', false)" class="rounded border border-border-primary px-4 py-2 text-sm">
                    {{ __('opes.messages_screen.cancel') }}
                </button>
            </div>
        </form>
    @endif
</div>
