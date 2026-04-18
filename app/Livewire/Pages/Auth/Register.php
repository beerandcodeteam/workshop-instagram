<?php

namespace App\Livewire\Pages\Auth;

use App\Livewire\Forms\RegisterForm;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.guest')]
#[Title('Criar conta')]
class Register extends Component
{
    public RegisterForm $form;

    public function register()
    {
        $this->form->validate();

        $user = User::create([
            'name' => $this->form->name,
            'email' => $this->form->email,
            'password' => $this->form->password,
        ]);

        auth()->login($user);

        session()->regenerate();

        return redirect('/');
    }

    public function render()
    {
        return view('pages.auth.register');
    }
}
