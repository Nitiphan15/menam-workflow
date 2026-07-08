<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    /**
     * Show login form
     */
    public function showLogin()
    {
        return view('auth.login');
    }

    /**
     * Handle login
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $credentials = $request->only('email', 'password');
        $credentials['is_active'] = 1;

        if (Auth::attempt($credentials)) {
            $request->session()->regenerate();
            auth()->user()->load('deptRoles');

            // อนุญาต redirect เฉพาะ path ภายในระบบ กัน open redirect ไปโดเมนอื่น
            // (ปฏิเสธ "//..." และ "/\..." ที่ browser ตีความเป็นลิงก์ข้ามโดเมน)
            $to = (string) $request->input('redirect_to', '');
            if ($to !== '' && $to[0] === '/' && !preg_match('#^/[/\\\\]#', $to)) {
                return redirect($to);
            }
            return redirect()->intended('/');
        }
        return back()->withErrors([
            'email' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง.',
        ])->withInput();
    }


    /**
     * Handle logout
     */
    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}
