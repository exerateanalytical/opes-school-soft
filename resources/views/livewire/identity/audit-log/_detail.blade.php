{{--
    One audit entry's before/after, as a change list rather than two JSON
    blobs. `$entry` is an AuditLog, `$diff` the list built by the component.
--}}
<div class="space-y-3">
    <dl class="grid grid-cols-1 gap-x-6 gap-y-1 text-xs text-charcoal/70 sm:grid-cols-2 lg:grid-cols-4">
        <div><dt class="inline font-medium">Actor ID:</dt> <dd class="inline">{{ $entry->actor_id ?? 'system' }}</dd></div>
        <div><dt class="inline font-medium">User agent:</dt> <dd class="inline">{{ $entry->user_agent ?? '-' }}</dd></div>
        <div class="min-w-0 break-all"><dt class="inline font-medium">Prev hash:</dt> <dd class="inline font-mono">{{ $entry->prev_hash }}</dd></div>
        <div class="min-w-0 break-all"><dt class="inline font-medium">Row hash:</dt> <dd class="inline font-mono">{{ $entry->row_hash }}</dd></div>
    </dl>

    @if ($diff === [])
        <p class="text-sm text-charcoal/70">
            This entry records no before/after payload — the action itself is the record.
        </p>
    @else
        <div class="min-w-0 overflow-x-auto rounded border border-border-primary bg-white">
            <table class="w-full min-w-[36rem] border-collapse text-sm">
                <thead class="border-b border-border-primary bg-sand/40 text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium text-charcoal/70">Field</th>
                        <th class="px-3 py-2 font-medium text-charcoal/70">Before</th>
                        <th class="px-3 py-2 font-medium text-charcoal/70">After</th>
                        <th class="px-3 py-2 font-medium text-charcoal/70">Change</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border-primary">
                    @foreach ($diff as $line)
                        <tr class="{{ $line['changed'] ? 'bg-heritage-yellow/10' : '' }}">
                            <td class="px-3 py-1.5 font-medium text-charcoal">{{ $line['field'] }}</td>
                            <td class="px-3 py-1.5 break-all text-charcoal/70">{{ $line['before'] }}</td>
                            <td class="px-3 py-1.5 break-all text-charcoal">{{ $line['after'] }}</td>
                            {{-- 09-ui 10: the WORD carries the meaning, the tint only reinforces it. --}}
                            <td class="px-3 py-1.5 text-xs">{{ $line['changed'] ? 'Changed' : 'Unchanged' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
