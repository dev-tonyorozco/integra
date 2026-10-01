<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,Password,RateLimiter,Hash};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Models\User;
class AuthController extends Controller {
 public function form(){return view('auth.login');}
 public function login(Request $r){$v=$r->validate(['email'=>'required|email','password'=>'required|string']);$v['email']=Str::lower(trim($v['email']));$key=$v['email'].'|'.$r->ip();if(RateLimiter::tooManyAttempts($key,8))throw ValidationException::withMessages(['email'=>'Espera unos minutos antes de reintentar.']);if(!Auth::attempt($v,$r->boolean('remember'))){RateLimiter::hit($key,900);throw ValidationException::withMessages(['email'=>'Datos de acceso incorrectos.']);}$user=$r->user();if(!$user->active||!$user->memberships()->where('active',true)->where('login_enabled',true)->whereHas('role',fn($q)=>$q->where('active',true))->whereHas('organization',fn($q)=>$q->where('active',true))->exists()){Auth::logout();throw ValidationException::withMessages(['email'=>'No tienes acceso activo.']);}RateLimiter::clear($key);$r->session()->regenerate();return redirect()->route('dashboard');}
 public function logout(Request $r){Auth::logout();$r->session()->invalidate();$r->session()->regenerateToken();return redirect()->route('login');}
 public function switch(Request $r){$m=$r->user()->memberships()->where('active',true)->where('login_enabled',true)->whereHas('organization',fn($q)=>$q->where('active',true))->findOrFail($r->integer('membership_id'));$r->session()->put('membership_id',$m->id);$r->session()->regenerate();return redirect()->route('dashboard');}
 public function forgot(){return view('auth.forgot');}
 public function email(Request $r){$r->validate(['email'=>'required|email']);$user=User::where('email',Str::lower(trim($r->email)))->whereHas('memberships',fn($q)=>$q->where('login_enabled',true)->where('active',true)->whereHas('role',fn($q)=>$q->where('active',true)))->first();if($user)Password::sendResetLink(['email'=>$user->email]);return back()->with('status','Si el correo tiene acceso, recibirás un enlace de recuperación.');}
 public function resetForm(Request $r,string $token){return view('auth.reset',['token'=>$token,'email'=>$r->email]);}
 public function reset(Request $r){$r->validate(['token'=>'required','email'=>'required|email','password'=>['required','confirmed',\Illuminate\Validation\Rules\Password::min(12)->mixedCase()->numbers()]]);$status=Password::reset($r->only('email','password','password_confirmation','token'),function(User $u,string $p){$u->password=$p;$u->remember_token=Str::random(60);$u->save();});if($status!==Password::PASSWORD_RESET)throw ValidationException::withMessages(['email'=>__($status)]);return redirect()->route('login')->with('status','Contraseña actualizada.');}
}
