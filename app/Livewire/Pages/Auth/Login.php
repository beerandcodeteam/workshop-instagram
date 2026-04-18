<?php

namespace App\Livewire\Pages\Auth;

use App\Livewire\Forms\LoginForm;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.guest')]
#[Title('Entrar')]
class Login extends Component
{
    public LoginForm $form;

    public function login()
    {
        $this->form->validate();

        $credentials = [
            'email' => $this->form->email,
            'password' => $this->form->password,
        ];

        if (! auth()->attempt($credentials, $this->form->remember)) {
            throw ValidationException::withMessages([
                'form.email' => __('Credenciais inválidas.'),
            ]);
        }

        session()->regenerate();

        return redirect()->intended('/');
    }

    public function render()
    {
        return view('pages.auth.login');
    }
}
