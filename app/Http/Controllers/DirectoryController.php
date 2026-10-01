<?php
namespace App\Http\Controllers;
use App\Models\{Membership,Role};
use App\Services\{Access,Directory};
use Illuminate\Http\Request;
use Illuminate\Support\Str;
class DirectoryController extends Controller {
 public function __construct(public Access $access){}
 private function query(Request $r){$this->access->require('directory.write');$q=$this->access->query(Membership::class)->with('user','role');if($r->filled('q')){$term='%'.Str::lower(Str::ascii($r->q)).'%';$q->whereHas('user',fn($q)=>$q->where('search_text','like',$term));}if(in_array($r->access,['yes','no']))$q->where('login_enabled',$r->access==='yes');return $q;}
 public function index(Request $r){return view('directory.index',['records'=>$this->query($r)->paginate(20)->withQueryString()]);}
 public function edit(?int $id=null){$this->access->require('directory.write');$m=$id?$this->access->query(Membership::class)->findOrFail($id):null;return view('directory.edit',['m'=>$m,'roles'=>$this->access->query(Role::class)->get(),'areas'=>$this->access->areas()->get()]);}
 public function save(Request $r,?int $id=null){$this->access->require('directory.write');app(Directory::class)->save($r->all(),$id?$this->access->query(Membership::class)->findOrFail($id):null,$this->access);return redirect()->route('directory')->with('status','Persona guardada.');}
 public function export(Request $r){$rows=$this->query($r)->get();return CaseController::csv('directorio.csv',['Nombre','Correo','Teléfono','Rol','Acceso','Activo'],$rows->map(fn($m)=>[$m->user->name,$m->user->email,$m->user->phone,$m->role?->name,$m->login_enabled?'Sí':'No',$m->active?'Sí':'No'])->all());}
}
