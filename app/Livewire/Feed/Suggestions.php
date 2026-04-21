<?php

namespace App\Livewire\Feed;

use App\Models\User;
use Livewire\Component;

class Suggestions extends Component
{
    public function render()
    {
        $currentUser = auth()->user();

        $suggestions = User::query()
            ->when(auth()->check(), fn ($q) => $q->where('id', '!=', auth()->id()))
            ->inRandomOrder()
            ->limit(5)
            ->get();

        return view('livewire.feed.suggestions', [
            'currentUser' => $currentUser,
            'suggestions' => $suggestions,
        ]);
    }
}
