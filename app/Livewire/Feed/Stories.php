<?php

namespace App\Livewire\Feed;

use App\Models\User;
use Livewire\Component;

class Stories extends Component
{
    public function render()
    {
        $users = User::query()
            ->when(auth()->check(), fn ($q) => $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [auth()->id()]))
            ->orderBy('name')
            ->limit(15)
            ->get();

        return view('livewire.feed.stories', [
            'users' => $users,
        ]);
    }
}
