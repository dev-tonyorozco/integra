(()=>{
 const root=document.getElementById('builder');if(!root)return;
 const workflow=root.dataset.kind==='workflows';const source=document.getElementById(workflow?'id_definition':'id_questions');
 let data;try{data=JSON.parse(source.value);}catch{return;}
 source.closest('.field').classList.add('enhanced');
 const list=document.getElementById('builder-items');
 function el(tag,text,attrs={}){const node=document.createElement(tag);if(text)node.textContent=text;Object.entries(attrs).forEach(([k,v])=>node.setAttribute(k,v));return node;}
 function sync(){source.value=JSON.stringify(data);source.dispatchEvent(new Event('input',{bubbles:true}));}
 function field(parent,title,value,update,type='text'){
  const label=el('label',title);const input=type==='textarea'?el('textarea'):el('input',null,{type});
  if(type==='checkbox')input.checked=!!value;else input.value=value??'';
  input.addEventListener('input',()=>{update(type==='checkbox'?input.checked:type==='number'?Number(input.value):input.value);sync();});label.append(input);parent.append(label);return input;
 }
 function select(parent,title,value,choices,update){const label=el('label',title);const input=el('select');choices.forEach(([key,name])=>{const option=el('option',name,{value:key});option.selected=key===value;input.append(option);});input.addEventListener('change',()=>{update(input.value);sync();});label.append(input);parent.append(label);return input;}
 function render(){list.replaceChildren();const items=workflow?data.states:data;
  items.forEach((item,index)=>{const card=el('section',null,{class:'builder-item'});card.append(el('h3',(workflow?'Estado ':'Pregunta ')+(index+1)));
   field(card,'Nombre',item.label,v=>item.label=v);
   if(workflow){field(card,'Próxima acción',item.next_action||'',v=>item.next_action=v);field(card,'Plazo en horas',item.sla_hours||24,v=>item.sla_hours=v,'number');field(card,'Contar días hábiles (cada 24 horas = un día)',item.business_days,v=>item.business_days=v,'checkbox');field(card,'Estado terminal',item.terminal,v=>item.terminal=v,'checkbox');select(card,'Resultado',item.outcome||'',[['','Sin resolución'],['integrated','Integración'],['reorient','Reorientación'],['closed','Cierre']],v=>item.outcome=v);const initial=el('button',data.initial===item.id?'✓ Estado inicial':'Usar como inicial',{type:'button',class:'secondary small'});initial.addEventListener('click',()=>{data.initial=item.id;sync();render();});card.append(initial);
   }else{select(card,'Tipo de respuesta',item.type||'text',[['text','Texto corto'],['textarea','Texto largo'],['email','Correo'],['number','Número'],['date','Fecha'],['select','Una opción'],['multiselect','Varias opciones'],['boolean','Confirmación']],v=>{item.type=v;if(['select','multiselect'].includes(v)&&!item.options)item.options=['Opción 1'];render();});field(card,'Obligatoria',item.required,v=>item.required=v,'checkbox');field(card,'Información sensible: excluir de ficha y consulta',item.sensitive,v=>item.sensitive=v,'checkbox');field(card,'Ayuda para responder',item.help||'',v=>item.help=v);
    if(['select','multiselect'].includes(item.type)){field(card,'Opciones (una por línea)',(item.options||[]).join('\n'),v=>item.options=v.split('\n').filter(Boolean),'textarea');if(root.dataset.kind==='tests')field(card,'Ponderación: opción = puntos (una por línea)',Object.entries(item.scores||{}).map(([k,v])=>k+' = '+v).join('\n'),v=>{item.scores={};v.split('\n').forEach(line=>{const pos=line.lastIndexOf('=');if(pos>0){const score=Number(line.slice(pos+1));if(Number.isFinite(score))item.scores[line.slice(0,pos).trim()]=score;}});},'textarea');}
   }
   const remove=el('button','Eliminar',{type:'button',class:'quiet remove small'});remove.addEventListener('click',()=>{if(!confirm('¿Eliminar este elemento del borrador?'))return;items.splice(index,1);if(workflow){data.transitions=data.transitions.filter(t=>t.from!==item.id&&t.to!==item.id);if(data.initial===item.id)data.initial=items[0]?.id;}sync();render();});card.append(remove);list.append(card);
  });if(workflow)renderTransitions();
 }
 function renderTransitions(){const container=document.getElementById('builder-transitions');container.replaceChildren();data.transitions.forEach((edge,index)=>{const card=el('div',null,{class:'builder-item'});const choices=data.states.map(s=>[s.id,s.label]);select(card,'Desde',edge.from,choices,v=>edge.from=v);select(card,'Hacia',edge.to,choices,v=>edge.to=v);edge.roles=edge.roles||['admin','coordinator','advisor'];['admin','coordinator','advisor'].forEach(role=>field(card,{admin:'Administrador',coordinator:'Coordinación',advisor:'Asesor'}[role],edge.roles.includes(role),v=>{edge.roles=v?[...edge.roles.filter(x=>x!==role),role]:edge.roles.filter(x=>x!==role);},'checkbox'));const remove=el('button','Eliminar transición',{type:'button',class:'quiet small'});remove.addEventListener('click',()=>{data.transitions.splice(index,1);sync();renderTransitions();});card.append(remove);container.append(card);});}
 document.getElementById('builder-add').addEventListener('click',()=>{const id='item_'+crypto.randomUUID().slice(0,8);if(workflow)data.states.push({id,label:'Nuevo estado',sla_hours:24,next_action:'Dar seguimiento'});else data.push({id,label:'Nueva pregunta',type:'text',required:false});sync();render();});
 document.getElementById('transition-add')?.addEventListener('click',()=>{data.transitions.push({from:data.states[0]?.id,to:data.states[1]?.id,roles:['admin','coordinator','advisor']});sync();renderTransitions();});
 render();
})();
