{{-- Before the avatar: the approvals bell, then who is signed in and as what. --}}
@auth
    @livewire(\App\Livewire\ApprovalsWaiting::class)
    @php($user = auth()->user())
    <div class="ae-user-id">
        <span class="ae-user-name">{{ $user->name }}</span>
        <span class="ae-user-role">{{ $user->isAdministrator() ? __('Administrator') : ($user->accessGroups->pluck('name')->join(', ') ?: __('User')) }}</span>
    </div>
@endauth
