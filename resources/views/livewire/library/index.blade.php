@php
    use App\Support\Money\Money;

    /**
     * Status -> pill tone. The WORD carries the meaning (09-ui 10); the
     * colour only reinforces it.
     */
    $memberTone = [
        'active' => 'ok',
        'suspended' => 'amber',
        'expired' => 'red',
        'closed' => 'red',
    ];

    $issueTone = [
        'open' => 'ok',
        'overdue' => 'red',
        'returned' => 'ok',
        'lost' => 'red',
        'written_off' => 'amber',
    ];

    $fineTone = [
        'assessed' => 'amber',
        'invoiced' => 'amber',
        'paid' => 'ok',
        'waived' => 'ok',
        'written_off' => 'red',
    ];

    $label = static fn (string $value): string => ucfirst(str_replace('_', ' ', $value));

    $tabs = [
        ['value' => 'books', 'label' => 'Books', 'count' => $tabCounts['books']],
        ['value' => 'members', 'label' => 'Members', 'count' => $tabCounts['members']],
        ['value' => 'circulation', 'label' => 'Circulation', 'count' => $tabCounts['circulation']],
        ['value' => 'fines', 'label' => 'Fines', 'count' => $tabCounts['fines']],
    ];
@endphp

<div class="min-w-0 space-y-4">
    @if (session('status'))
        <p class="rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
            {{ session('status') }}
        </p>
    @endif

    @foreach (['circulation', 'fines', 'books'] as $errorBag)
        @error($errorBag)
            <p class="rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm font-medium text-heritage-red" role="alert">
                {{ $message }}
            </p>
        @enderror
    @endforeach

    {{-- Inline return panel: ReturnBook takes a condition and a date, so a
         damaged return and a back-dated one are both reachable from here. --}}
    @if ($returningIssueId !== null)
        <section aria-label="Return copy" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">Return Copy (Issue #{{ $returningIssueId }})</h2>

            <form wire:submit="returnIssue" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <label for="return-condition" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Condition on return</span>
                        <select id="return-condition" wire:model="returnCondition"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="good">Good</option>
                            <option value="damaged">Damaged</option>
                        </select>
                        @error('returnCondition')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="return-on" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Returned on</span>
                        <input id="return-on" type="date" wire:model="returnedOn"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('returnedOn')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit"
                            class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                        Confirm return
                    </button>
                    <button type="button" wire:click="cancelReturn"
                            class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    @endif

    {{-- Inline waive panel: §10.6 requires a reason on every waiver. --}}
    @if ($waivingFineId !== null)
        <section aria-label="Waive fine" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">Waive Fine #{{ $waivingFineId }}</h2>

            <form wire:submit="waiveFine" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <label for="waive-reason" class="flex flex-col gap-1 sm:col-span-2">
                        <span class="text-xs font-medium text-charcoal/70">Reason for the waiver</span>
                        <input id="waive-reason" type="text" wire:model="waiveReason"
                               placeholder="Why is this balance being written off?"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('waiveReason')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="waived-on" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Waived on</span>
                        <input id="waived-on" type="date" wire:model="waivedOn"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('waivedOn')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit"
                            class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                        Confirm waiver
                    </button>
                    <button type="button" wire:click="cancelWaive"
                            class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    @endif

    {{-- Inline issue-book panel. --}}
    @if ($showIssueForm)
        <section aria-label="Issue book" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">Issue Book</h2>

            <form wire:submit="saveIssue" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <label for="issue-form-copy" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Book copy</span>
                        <select id="issue-form-copy" wire:model="issueBookCopyId"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">Select a copy...</option>
                            @foreach ($availableCopyOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                        @error('issueBookCopyId')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="issue-form-member" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Member</span>
                        <select id="issue-form-member" wire:model="issueLibraryMemberId"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">Select a member...</option>
                            @foreach ($activeMemberOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                        @error('issueLibraryMemberId')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="issue-form-date" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Issued on</span>
                        <input id="issue-form-date" type="date" wire:model="issuedOn"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('issuedOn')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit"
                            class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                        Issue book
                    </button>
                    <button type="button" wire:click="toggleIssueForm"
                            class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    @endif

    {{-- Inline enroll-member panel. --}}
    @if ($showEnrollForm)
        <section aria-label="Enroll library member" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">Enroll Member</h2>

            <form wire:submit="saveEnroll" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <label for="enroll-form-type" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Member type</span>
                        <select id="enroll-form-type" wire:model.live="enrollMemberType"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="student">Student</option>
                            <option value="staff">Staff</option>
                            <option value="external">External</option>
                        </select>
                        @error('enrollMemberType')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="enroll-form-class" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Membership class</span>
                        <select id="enroll-form-class" wire:model="enrollMembershipClassId"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">Select a class...</option>
                            @foreach ($membershipClassOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                        @error('enrollMembershipClassId')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="enroll-form-year" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Academic year</span>
                        <select id="enroll-form-year" wire:model="enrollAcademicYearId"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">Select a year...</option>
                            @foreach ($academicYearOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                        @error('enrollAcademicYearId')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    @if ($enrollMemberType === 'student')
                        <label for="enroll-form-enrollment" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Enrollment ID</span>
                            <input id="enroll-form-enrollment" type="text" wire:model="enrollEnrollmentId"
                                   placeholder="e.g. 1024"
                                   class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                            @error('enrollEnrollmentId')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>
                    @elseif ($enrollMemberType === 'staff')
                        <label for="enroll-form-staff" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Staff member ID</span>
                            <input id="enroll-form-staff" type="text" wire:model="enrollStaffMemberId"
                                   placeholder="e.g. 42"
                                   class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                            @error('enrollStaffMemberId')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>
                    @else
                        <label for="enroll-form-ext-name" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">External name</span>
                            <input id="enroll-form-ext-name" type="text" wire:model="enrollExternalName"
                                   class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                            @error('enrollExternalName')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>

                        <label for="enroll-form-ext-contact" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">External contact</span>
                            <input id="enroll-form-ext-contact" type="text" wire:model="enrollExternalContact"
                                   class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                            @error('enrollExternalContact')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>
                    @endif

                    <label for="enroll-form-joined" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Joined on</span>
                        <input id="enroll-form-joined" type="date" wire:model="enrollJoinedOn"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('enrollJoinedOn')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="enroll-form-expires" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Expires on (optional)</span>
                        <input id="enroll-form-expires" type="date" wire:model="enrollExpiresOn"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('enrollExpiresOn')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit"
                            class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                        Enroll member
                    </button>
                    <button type="button" wire:click="toggleEnrollForm"
                            class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    @endif

    {{-- Inline register-book panel. --}}
    @if ($showRegisterBookForm)
        <section aria-label="Register book" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">Register Book</h2>

            <form wire:submit="saveRegisterBook" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <label for="register-form-title" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Title</span>
                        <input id="register-form-title" type="text" wire:model="registerTitle"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('registerTitle')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="register-form-author" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Author</span>
                        <input id="register-form-author" type="text" wire:model="registerAuthor"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('registerAuthor')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="register-form-isbn" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">ISBN (optional)</span>
                        <input id="register-form-isbn" type="text" wire:model="registerIsbn"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('registerIsbn')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="register-form-category" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Category</span>
                        <select id="register-form-category" wire:model="registerBookCategoryId"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">Select a category...</option>
                            @foreach ($categoryOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                        @error('registerBookCategoryId')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="register-form-cost" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Replacement cost (optional)</span>
                        <input id="register-form-cost" type="number" min="0" wire:model="registerReplacementCost"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('registerReplacementCost')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="register-form-reference" class="flex items-center gap-2 self-end">
                        <input id="register-form-reference" type="checkbox" wire:model="registerIsReferenceOnly"
                               class="rounded border-border-primary text-primary focus:ring-primary/50"/>
                        <span class="text-sm text-charcoal/80">Reference only (never circulates)</span>
                    </label>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit"
                            class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                        Register book
                    </button>
                    <button type="button" wire:click="toggleRegisterBookForm"
                            class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    @endif

    {{-- Inline add-copies panel. --}}
    @if ($showAddCopiesForm)
        <section aria-label="Add book copies" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">Add Book Copies</h2>

            <form wire:submit="saveAddCopies" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <label for="copies-form-book" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Book title</span>
                        <select id="copies-form-book" wire:model="addCopiesBookId"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">Select a title...</option>
                            @foreach ($bookOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                        @error('addCopiesBookId')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="copies-form-shelf" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Shelf location</span>
                        <select id="copies-form-shelf" wire:model="addCopiesShelfLocationId"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">Select a shelf...</option>
                            @foreach ($shelfLocationOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                        @error('addCopiesShelfLocationId')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="copies-form-count" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Number of copies</span>
                        <input id="copies-form-count" type="number" min="1" wire:model="addCopiesCount"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('addCopiesCount')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="copies-form-cost" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Unit cost (optional)</span>
                        <input id="copies-form-cost" type="number" min="0" wire:model="addCopiesUnitCost"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('addCopiesUnitCost')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit"
                            class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                        Add copies
                    </button>
                    <button type="button" wire:click="toggleAddCopiesForm"
                            class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    @endif

<x-list-screen
    title="Library"
    :breadcrumb="['Dashboard', 'Library']"
    :paginator="$rows"
    empty-message="No library records match these filters yet. Books, members and issues appear here as they are set up."
>
    <x-slot:actions>
        @can(\App\Modules\Library\Domain\LibraryPermission::CIRCULATE)
            <button type="button" wire:click="toggleIssueForm"
                    class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                {{ $showIssueForm ? 'Hide form' : 'Issue book' }}
            </button>
        @endcan
        @can(\App\Modules\Library\Domain\LibraryPermission::MANAGE)
            <button type="button" wire:click="toggleEnrollForm"
                    class="rounded border border-primary px-4 py-2 text-sm font-semibold text-primary hover:bg-primary/10">
                {{ $showEnrollForm ? 'Hide form' : 'Enroll member' }}
            </button>
            <button type="button" wire:click="toggleRegisterBookForm"
                    class="rounded border border-primary px-4 py-2 text-sm font-semibold text-primary hover:bg-primary/10">
                {{ $showRegisterBookForm ? 'Hide form' : 'Register book' }}
            </button>
            <button type="button" wire:click="toggleAddCopiesForm"
                    class="rounded border border-primary px-4 py-2 text-sm font-semibold text-primary hover:bg-primary/10">
                {{ $showAddCopiesForm ? 'Hide form' : 'Add copies' }}
            </button>
        @endcan
    </x-slot:actions>

    {{-- Four KPI cards: titles, copies on loan, overdue, unpaid fines -
         dataset-wide numbers. --}}
    <x-slot:kpis>
        <x-kpi-card label="Total Titles" :value="$kpis['titles']" icon-bg="bg-primary">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 19.5A2.5 2.5 0 016.5 17H20V4H6.5A2.5 2.5 0 004 6.5v13z"/><path stroke-linecap="round" d="M4 19.5V6.5"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Copies On Loan" :value="$kpis['copies_on_loan']" icon-bg="bg-badge-blue">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path stroke-linecap="round" d="M4 19c0-2.8 2.2-5 5-5s5 2.2 5 5M15 4h4a1 1 0 011 1v14a1 1 0 01-1 1h-4"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('opes.library_screen.kpi_active_members')" :value="$kpis['active_members']" icon-bg="bg-badge-purple">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="8" r="3.2"/><path stroke-linecap="round" d="M2.8 19.5c0-3.4 2.8-6.2 6.2-6.2s6.2 2.8 6.2 6.2"/><path stroke-linecap="round" d="M15.5 8.3a2.8 2.8 0 110 5.6M20.5 19.5c0-2.6-1.9-4.8-4.4-5.5"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Overdue Items" :value="$kpis['overdue']" icon-bg="bg-heritage-red">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v5l3 3"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Unpaid Fines" :value="Money::of($kpis['unpaid_fines'])->format(false)" icon-bg="bg-badge-orange">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v10M9 9.5c0-1.4 1.3-2.5 3-2.5s3 1.1 3 2.5-1.3 2-3 2.5-3 1.1-3 2.5 1.3 2.5 3 2.5 3-1.1 3-2.5"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('opes.library_screen.kpi_new_titles')" :value="$kpis['new_titles']"
                    :sub="__('opes.library_screen.kpi_new_titles_sub')" icon-bg="bg-badge-teal">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 19.5A2.5 2.5 0 016.5 17H20V4H6.5A2.5 2.5 0 004 6.5v13z"/><path stroke-linecap="round" d="M12 8v6M9 11h6"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </x-slot:kpis>

    <x-slot:filters>
        @if ($tab === 'books')
            <label for="library-filter-category" class="flex min-w-[11rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Category</span>
                <select id="library-filter-category" wire:model.live="category"
                        class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="">All categories</option>
                    @foreach ($categoryOptions as $option)
                        <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        <label for="library-filter-status" class="flex min-w-[10rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Status</span>
            <select id="library-filter-status" wire:model.live="status"
                    class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">All statuses</option>
                @foreach ($statusOptions as $option)
                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                @endforeach
            </select>
        </label>

        <label for="library-filter-search" class="flex min-w-[12rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Search</span>
            <input id="library-filter-search" type="search" wire:model.live.debounce.400ms="search"
                   placeholder="Search title, author, member..."
                   class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
        </label>
    </x-slot:filters>

    <x-slot:tabs>
        @foreach ($tabs as $tabOption)
            <button type="button" wire:click="selectTab('{{ $tabOption['value'] }}')"
                    @if ($tab === $tabOption['value']) aria-current="page" @endif
                    class="flex items-center gap-1.5 whitespace-nowrap border-b-2 px-3 py-2 text-sm {{ $tab === $tabOption['value']
                        ? 'border-primary font-semibold text-primary'
                        : 'border-transparent text-charcoal/60 hover:text-charcoal' }}">
                {{ $tabOption['label'] }}
                <span class="rounded-full bg-sand px-1.5 text-xs text-charcoal/70">{{ $tabOption['count'] }}</span>
            </button>
        @endforeach
    </x-slot:tabs>

    <x-slot:head>
        <tr class="bg-chrome text-white">
            @if ($tab === 'books')
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Title</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Author</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Category</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Copies</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Available</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Actions</th>
            @elseif ($tab === 'members')
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Member No.</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Name</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Type</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Class</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Active Loans</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Actions</th>
            @elseif ($tab === 'circulation')
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Issue No.</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Book</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Member</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Issued</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Due</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Renewals</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                @can(\App\Modules\Library\Domain\LibraryPermission::CIRCULATE)
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Actions</th>
                @endcan
            @else
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Fine No.</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Member</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Type</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Assessed</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Amount</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Waived</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                @can(\App\Modules\Library\Domain\LibraryPermission::WAIVE_FINE)
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Actions</th>
                @endcan
            @endif
        </tr>
    </x-slot:head>

    @foreach ($rows as $row)
        <tr wire:key="library-{{ $tab }}-{{ $row->id }}" class="hover:bg-sand/30">
            @if ($tab === 'books')
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->title }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->author }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->category_name }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->copies_total }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->copies_available }}</td>
                <td class="px-4 py-2.5">
                    <x-status-pill :status="$row->is_archived ? 'red' : 'ok'" :label="$row->is_archived ? 'Archived' : 'Active'"/>
                </td>
                <td class="px-4 py-2.5">
                    <a href="{{ route('library.books.show', $row->id) }}" class="font-medium text-primary hover:underline">View</a>
                </td>
            @elseif ($tab === 'members')
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->member_no }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->external_name ?? '—' }}</td>
                <td class="px-4 py-2.5 capitalize text-charcoal/80">{{ $row->member_type }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->class_name }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->active_issues }}</td>
                <td class="px-4 py-2.5">
                    <x-status-pill :status="$memberTone[$row->status] ?? 'ok'" :label="$label($row->status)"/>
                </td>
                <td class="px-4 py-2.5">
                    <a href="{{ route('library.members.show', $row->id) }}" class="font-medium text-primary hover:underline">View</a>
                </td>
            @elseif ($tab === 'circulation')
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->issue_no }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->book_title }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->member_no }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->issued_on }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->due_on }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->renewal_count }}</td>
                <td class="px-4 py-2.5">
                    <x-status-pill :status="$issueTone[$row->status] ?? 'ok'" :label="$label($row->status)"/>
                </td>
                @can(\App\Modules\Library\Domain\LibraryPermission::CIRCULATE)
                    <td class="px-4 py-2.5">
                        @if (in_array($row->status, ['open', 'overdue'], true))
                            <div class="flex items-center gap-2">
                                <button type="button" wire:click="startReturn({{ $row->id }})"
                                        class="rounded border border-primary px-2.5 py-1 text-xs font-semibold text-primary hover:bg-primary/10">
                                    Return
                                </button>
                                <button type="button" wire:click="renewIssue({{ $row->id }})"
                                        class="rounded border border-border-primary px-2.5 py-1 text-xs font-medium text-charcoal/70 hover:text-charcoal">
                                    Renew
                                </button>
                            </div>
                        @endif
                    </td>
                @endcan
            @else
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->fine_no }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->member_no }}</td>
                <td class="px-4 py-2.5 capitalize text-charcoal/80">{{ $row->fine_type }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->assessed_on }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ Money::of((int) $row->amount)->format(false) }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ Money::of((int) $row->waived_amount)->format(false) }}</td>
                <td class="px-4 py-2.5">
                    <x-status-pill :status="$fineTone[$row->status] ?? 'amber'" :label="$label($row->status)"/>
                </td>
                @can(\App\Modules\Library\Domain\LibraryPermission::WAIVE_FINE)
                    <td class="px-4 py-2.5">
                        @if (in_array($row->status, ['assessed', 'invoiced'], true))
                            <button type="button" wire:click="startWaive({{ $row->id }})"
                                    class="rounded border border-primary px-2.5 py-1 text-xs font-semibold text-primary hover:bg-primary/10">
                                Waive
                            </button>
                        @endif
                    </td>
                @endcan
            @endif
        </tr>
    @endforeach

    {{-- Mobile cards: the two or three columns that matter on a handset. --}}
    <x-slot:cards>
        @foreach ($rows as $row)
            <article wire:key="library-card-{{ $tab }}-{{ $row->id }}"
                     class="rounded border border-border-primary bg-white p-3">
                @if ($tab === 'books')
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ $row->title }}</p>
                        <x-status-pill :status="$row->is_archived ? 'red' : 'ok'" :label="$row->is_archived ? 'Archived' : 'Active'"/>
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $row->author }} · {{ $row->category_name }} · {{ $row->copies_available }}/{{ $row->copies_total }} available</p>
                @elseif ($tab === 'members')
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ $row->member_no }}</p>
                        <x-status-pill :status="$memberTone[$row->status] ?? 'ok'" :label="$label($row->status)"/>
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $row->external_name ?? ucfirst($row->member_type) }} · {{ $row->active_issues }} active loans</p>
                @elseif ($tab === 'circulation')
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ $row->book_title }}</p>
                        <x-status-pill :status="$issueTone[$row->status] ?? 'ok'" :label="$label($row->status)"/>
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $row->member_no }} · due {{ $row->due_on }}</p>
                @else
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ $row->fine_no }}</p>
                        <x-status-pill :status="$fineTone[$row->status] ?? 'amber'" :label="$label($row->status)"/>
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $row->member_no }} · {{ Money::of((int) $row->amount)->format(false) }}</p>
                @endif
            </article>
        @endforeach
    </x-slot:cards>

    <x-slot:rail>
        <div class="space-y-4">
            {{-- The reference's rail: what is on the shelves, then what is out
                 on loan. Both read the same rows the tabs above already page
                 through, so the rail cannot disagree with the table. --}}
            <x-shell.panel :title="__('opes.library_screen.rail_by_category')">
                <x-shell.donut :slices="$categoryDistribution"
                               :centre-value="number_format(collect($categoryDistribution)->sum('value'))"
                               :centre-label="__('opes.library_screen.rail_copies')"
                               stacked
                               :size="132"
                               :thickness="22"
                               class="py-1"/>
            </x-shell.panel>

            <x-shell.panel :title="__('opes.library_screen.rail_recent_loans')">
                @if ($recentLoans === [])
                    <p class="py-3 text-[13px] text-charcoal/55">{{ __('opes.library_screen.rail_no_loans') }}</p>
                @else
                    <ul class="divide-y divide-shell-divider">
                        @foreach ($recentLoans as $loan)
                            <li class="py-2">
                                <p class="truncate text-[13px] font-medium text-charcoal">{{ $loan['borrower'] }}</p>
                                <p class="truncate text-[12px] text-charcoal/65">{{ $loan['title'] }}</p>
                                {{-- The due date carries the state: a loan
                                     past its date is the one fact a librarian
                                     is scanning this list for, so it is
                                     coloured as well as dated. --}}
                                <p class="mt-1 text-[11px] {{ $loan['overdue'] ? 'font-semibold text-danger-text' : 'text-charcoal/55' }}">
                                    {{ __('opes.library_screen.rail_due', [
                                        'date' => \Illuminate\Support\Carbon::parse($loan['due_on'])->translatedFormat('d M Y'),
                                    ]) }}
                                </p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-shell.panel>
        </div>
    </x-slot:rail>
</x-list-screen>
</div>
