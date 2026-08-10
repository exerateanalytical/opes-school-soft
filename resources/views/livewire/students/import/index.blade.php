<div class="space-y-6">
    <header>
        <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.import_screen.title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-slate-600">{{ __('opes.import_screen.intro') }}</p>
    </header>

    @if ($message !== '')
        <p class="rounded border border-primary/40 bg-primary/10 p-3 text-sm text-primary" role="status">{{ $message }}</p>
    @endif

    @if ($error !== '')
        <p class="rounded border border-heritage-red/40 bg-heritage-red/10 p-3 text-sm text-heritage-red" role="alert">{{ $error }}</p>
    @endif

    <section class="rounded-lg border border-sand bg-white p-4 shadow-sm sm:p-5">
        <div class="flex flex-wrap items-end gap-3">
            <label class="text-sm">
                <span class="block text-slate-600">{{ __('opes.import_screen.kind') }}</span>
                <select wire:model="kind" class="mt-1 rounded border border-sand p-2">
                    @foreach ($kinds as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </select>
            </label>

            <label class="text-sm">
                <span class="block text-slate-600">{{ __('opes.import_screen.filename') }}</span>
                <input type="text" wire:model="filename" class="mt-1 rounded border border-sand p-2" placeholder="students.csv">
            </label>
        </div>

        <label class="mt-3 block text-sm">
            <span class="block text-slate-600">{{ __('opes.import_screen.csv') }}</span>
            <textarea wire:model="csv" rows="8"
                      class="mt-1 w-full rounded border border-sand p-2 font-mono text-xs"
                      placeholder="first_name,last_name,date_of_birth,gender"></textarea>
        </label>

        <div class="mt-3 flex flex-wrap gap-2">
            <button type="button" wire:click="stage"
                    class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white">
                {{ __('opes.import_screen.stage') }}
            </button>

            <button type="button" wire:click="validateBatch" @disabled($batch === null)
                    class="rounded border border-primary px-4 py-2 text-sm font-semibold text-primary disabled:opacity-50">
                {{ __('opes.import_screen.validate') }}
            </button>

            <button type="button" wire:click="commit"
                    @disabled($batch === null || $batch->valid_count < 1)
                    class="rounded bg-heritage-green px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
                {{ __('opes.import_screen.commit') }}
            </button>
        </div>
    </section>

    @if ($batch !== null)
        <section class="rounded-lg border border-sand bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">{{ __('opes.import_screen.report') }}</h2>

            <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div>
                    <dt class="text-xs text-slate-600">{{ __('opes.import_screen.rows') }}</dt>
                    <dd class="font-mono text-sm font-semibold">{{ number_format($batch->row_count) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-600">{{ __('opes.import_screen.valid') }}</dt>
                    <dd class="font-mono text-sm font-semibold text-primary">{{ number_format($batch->valid_count) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-600">{{ __('opes.import_screen.invalid') }}</dt>
                    <dd class="font-mono text-sm font-semibold text-heritage-red">{{ number_format($batch->invalid_count) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-600">{{ __('opes.import_screen.imported') }}</dt>
                    <dd class="font-mono text-sm font-semibold">{{ number_format($batch->imported_count) }}</dd>
                </div>
            </dl>
        </section>

        <section class="overflow-x-auto rounded-lg border border-sand bg-white shadow-sm">
            <table class="min-w-full text-sm">
                <thead class="bg-sand/40">
                <tr>
                    <th class="p-2 text-left font-semibold">#</th>
                    <th class="p-2 text-left font-semibold">{{ __('opes.import_screen.status') }}</th>
                    <th class="p-2 text-left font-semibold">{{ __('opes.import_screen.data') }}</th>
                    <th class="p-2 text-left font-semibold">{{ __('opes.import_screen.problems') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($rows as $row)
                    <tr class="border-t border-sand align-top">
                        <td class="p-2 font-mono">{{ $row->row_no }}</td>
                        <td class="p-2">{{ $row->status->value }}</td>
                        <td class="p-2 font-mono text-xs">{{ implode(' · ', array_filter(array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $row->payload))) }}</td>
                        <td class="p-2 text-xs text-heritage-red">
                            @if (is_array($row->errors))
                                @foreach ($row->errors as $field => $messages)
                                    <div><strong>{{ $field }}</strong>: {{ implode(' ', (array) $messages) }}</div>
                                @endforeach
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="p-4 text-center text-slate-500">{{ __('opes.import_screen.empty') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </section>
    @endif
</div>
