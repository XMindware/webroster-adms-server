<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    ////Login
    public function login()
    {
        return view('auth.login');
    }
    public function loginUser(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|min:8|max:12',
        ]);
    
        $credentials = $request->only('email', 'password');
    
        $remember = $request->has('remember'); // if you're using a "Remember me" checkbox
    
        if (Auth::attempt($credentials, $remember)) {
            $request->session()->regenerate(); // prevent session fixation
            return redirect()->intended('devices'); // redirect to intended page
        }
    
        return back()->with('fail', 'Email or password is incorrect.');
    }
    ///Logout
    public function logout(Request $request)
    {
        Auth::logout();
        
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        
        return redirect('login')->with('success', 'You have been logged out successfully.');
    }
}