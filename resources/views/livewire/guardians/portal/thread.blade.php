<div class="min-w-0 space-y-5">
    <div class="min-w-0">
        <a href="{{ route('portal.messages') }}"
           class="inline-flex items-center gap-1 text-xs font-medium text-charcoal/60 hover:text-primary">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
            </svg>
            {{ __('opes.guardian_portal.messages_title') }}
        </a>

        <h1 class="mt-1 text-2xl font-bold text-charcoal">{{ $title }}</h1>
    </div>

    @if (session('portal-status'))
        <p class="rounded-xl border border-success/30 bg-portal-chip px-4 py-3 text-sm font-medium text-portal-success">{{ session('portal-status') }}</p>
    @endif

    <section aria-label="{{ __('opes.guardian_portal.messages_title') }}" class="space-y-3">
        @if ($messages->isEmpty())
            <x-empty-state :message="__('opes.guardian_portal.messages_thread_empty')"/>
        @else
            @foreach ($messages as $message)
                @php $mine = (int) $message->sender_id === $meId; @endphp
                <div wire:key="msg-{{ $message->id }}" @class(['flex', 'justify-end' => $mine])>
                    <div @class([
                        'max-w-[85%] rounded-lg px-3 py-2 text-sm',
                        'bg-portal-green text-white' => $mine,
                        'border border-border-primary bg-white text-charcoal' => ! $mine,
                    ])>
                        @unless ($mine)
                            @if ($message->sender_name)
                                <p class="flex items-center gap-1 text-xs font-semibold text-primary">
                                    <span>{{ $message->sender_name }}</span>
                                    <x-verified-badge :official="(bool) ($message->sender_is_official ?? false)"/>
                                </p>
                            @endif
                        @endunless
                        <p class="whitespace-pre-line">{{ $message->body }}</p>
                        <p @class(['mt-1 text-[11px]', 'text-white/60' => $mine, 'text-charcoal/50' => ! $mine])>
                            {{ $message->created_at }}
                        </p>
                    </div>
                </div>
            @endforeach
        @endif
    </section>

    <form wire:submit="send" class="rounded border border-border-primary bg-white p-3 shadow-sm">
        <label for="portal-reply" class="sr-only">{{ __('opes.guardian_portal.messages_reply_placeholder') }}</label>
        <textarea id="portal-reply" wire:model="body" rows="3" maxlength="4000"
                  placeholder="{{ __('opes.guardian_portal.messages_reply_placeholder') }}"
                  class="w-full rounded border border-border-primary px-3 py-2 text-sm text-charcoal focus:border-primary focus:outline-none"></textarea>

        @error('body')
            <p class="mt-1 text-xs text-portal-danger">{{ $message }}</p>
        @enderror

        <div class="mt-2 flex justify-end">
            <button type="submit"
                    class="rounded-xl bg-primary px-4 py-2.5 text-sm font-semibold text-white hover:bg-portal-green-soft">
                {{ __('opes.guardian_portal.messages_send') }}
            </button>
        </div>
    </form>
</div>
