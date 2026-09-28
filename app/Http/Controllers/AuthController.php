<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    //Registration
    public function registration()
    {
        return view('auth.registration');
    }
    public function registerUser(Request $request)
    {
        $request->validate([
            'name'=>'required',
            'email'=>'required|email:users',
            'password'=>'required|min:8|max:12'
        ]);

        $user = new User();
        $user->name = $request->name;
        $user->email = $request->email;
        $user->password = $request->password;

        $result = $user->save();
        if($result){
            return back()->with('success', __('auth.registered_successfully'));
        } else {
            return back()->with('fail', __('auth.something_wrong'));
        }
    }
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
    
        return back()->with('fail', __('auth.invalid_credentials'));
    }
    ///Logout
    public function logout(Request $request)
    {
        Auth::logout();
        
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        
        return redirect('login')->with('success', __('auth.logged_out_successfully'));
    }
}