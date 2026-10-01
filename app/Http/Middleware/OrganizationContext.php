<?php
namespace App\Http\Middleware;
use App\Services\Access;
use Illuminate\Http\Request;
use Closure;
class OrganizationContext {
 public function handle(Request $r,Closure $next){
  abort_unless($r->user()->active,403,'Tu identidad está inactiva.');
  $members=$r->user()->memberships()->with('role','organization')->where('active',true)->where('login_enabled',true)->whereHas('organization',fn($q)=>$q->where('active',true))->get();
  $m=$members->firstWhere('id',$r->session()->get('membership_id'))??$members->first();
  if(!$m||!$m->role?->active)return response()->view('inactive',['members'=>$members],403);
  $r->session()->put('membership_id',$m->id);app(Access::class)->member=$m;view()->share('access',app(Access::class));view()->share('members',$members);return $next($r);
 }
}
