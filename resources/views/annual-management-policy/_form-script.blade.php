<template data-theme-template><fieldset class="amp-field-item" data-theme><legend>重点テーマ</legend><div class="amp-order-actions"><button class="secondary" type="button" data-move-up aria-label="重点テーマを上へ">↑</button><button class="secondary" type="button" data-move-down aria-label="重点テーマを下へ">↓</button></div><div class="field"><label>重点テーマ名</label><textarea name="themes[__T__][statement]" maxlength="50000"></textarea></div><div class="field"><label>背景・考え方（任意）</label><textarea name="themes[__T__][explanation]" maxlength="50000"></textarea></div><div class="amp-field-list" data-priorities></div><div class="actions"><button class="secondary" type="button" data-add-priority>優先方針を追加</button><button class="danger-outline" type="button" data-remove>重点テーマを削除</button></div></fieldset></template>
<template data-priority-template><div class="amp-field-item amp-field-item--nested" data-priority><div class="amp-item-title">優先方針</div><div class="amp-order-actions"><button class="secondary" type="button" data-move-up aria-label="優先方針を上へ">↑</button><button class="secondary" type="button" data-move-down aria-label="優先方針を下へ">↓</button></div><div class="field"><label>優先方針</label><textarea name="themes[__T__][priorities][__P__][statement]" maxlength="50000"></textarea></div><div class="field"><label>方針の考え方（任意）</label><textarea name="themes[__T__][priorities][__P__][explanation]" maxlength="50000"></textarea></div><button class="danger-outline" type="button" data-remove>削除</button></div></template>
<template data-department-template><fieldset class="amp-field-item" data-department><legend>部署方針</legend><div class="amp-order-actions"><button class="secondary" type="button" data-move-up aria-label="部署方針を上へ">↑</button><button class="secondary" type="button" data-move-down aria-label="部署方針を下へ">↓</button></div><div class="field"><label>対象部署</label><select name="departments[__D__][group_public_id]" required><option value="">部署を選択してください</option>@foreach($groups as $group)<option value="{{ $group->public_id }}">{{ $group->name }}</option>@endforeach</select></div><div class="field"><label>部署方針全体の背景・考え方（任意）</label><textarea name="departments[__D__][introduction]" maxlength="50000"></textarea></div><div class="amp-field-list" data-statements></div><div class="actions"><button class="secondary" type="button" data-add-statement>部署方針を追加</button><button class="danger-outline" type="button" data-remove>対象部署を削除</button></div></fieldset></template>
<template data-statement-template><div class="amp-field-item amp-field-item--nested" data-statement><div class="amp-item-title">部署方針</div><div class="amp-order-actions"><button class="secondary" type="button" data-move-up aria-label="部署方針項目を上へ">↑</button><button class="secondary" type="button" data-move-down aria-label="部署方針項目を下へ">↓</button></div><div class="field"><label>部署方針</label><textarea name="departments[__D__][statements][__S__][statement]" maxlength="50000"></textarea></div><div class="field"><label>方針の背景・考え方（任意）</label><textarea name="departments[__D__][statements][__S__][explanation]" maxlength="50000"></textarea></div><button class="danger-outline" type="button" data-remove>削除</button></div></template>
<script>
(() => {
 const form=document.querySelector('[data-amp-form]'); if(!form)return;
 const clone=name=>document.querySelector(`[data-${name}-template]`).content.cloneNode(true);
 const direct=(container,selector)=>Array.from(container.children).filter(item=>item.matches(selector));
 const rename=(root,pattern,replacement)=>root.querySelectorAll('[name]').forEach(field=>{field.name=field.name.replace(pattern,replacement);});
 const updateButtons=container=>{
  const items=Array.from(container.children);
  items.forEach((item,index)=>{
   const controls=Array.from(item.children).find(child=>child.classList?.contains('amp-order-actions'));
   if(!controls)return;
   controls.querySelector('[data-move-up]').disabled=index===0;
   controls.querySelector('[data-move-down]').disabled=index===items.length-1;
  });
 };
 const reindex=()=>{
  const themes=direct(form.querySelector('[data-themes]'),'[data-theme]');
  themes.forEach((theme,ti)=>{
   theme.querySelector(':scope > legend').textContent=`重点テーマ ${ti+1}`;
   rename(theme,/^themes\[(?:\d+|__T__)\]/,`themes[${ti}]`);
   const priorities=direct(theme.querySelector('[data-priorities]'),'[data-priority]');
   priorities.forEach((priority,pi)=>{
    priority.querySelector('.amp-item-title').textContent=`優先方針 ${pi+1}`;
    rename(priority,/^themes\[(?:\d+|__T__)\]\[priorities\]\[(?:\d+|__P__)\]/,`themes[${ti}][priorities][${pi}]`);
   });
   updateButtons(theme.querySelector('[data-priorities]'));
  });
  updateButtons(form.querySelector('[data-themes]'));
  const departments=direct(form.querySelector('[data-departments]'),'[data-department]');
  departments.forEach((department,di)=>{
   department.querySelector(':scope > legend').textContent=`部署方針 ${di+1}`;
   rename(department,/^departments\[(?:\d+|__D__)\]/,`departments[${di}]`);
   const statements=direct(department.querySelector('[data-statements]'),'[data-statement]');
   statements.forEach((statement,si)=>{
    statement.querySelector('.amp-item-title').textContent=`部署方針 ${si+1}`;
    rename(statement,/^departments\[(?:\d+|__D__)\]\[statements\]\[(?:\d+|__S__)\]/,`departments[${di}][statements][${si}]`);
   });
   updateButtons(department.querySelector('[data-statements]'));
  });
  updateButtons(form.querySelector('[data-departments]'));
 };
 form.querySelector('[data-add-theme]').addEventListener('click',()=>{form.querySelector('[data-themes]').append(clone('theme'));reindex();});
 form.querySelector('[data-add-department]')?.addEventListener('click',()=>{form.querySelector('[data-departments]').append(clone('department'));reindex();});
 form.addEventListener('click',event=>{
  const move=event.target.closest('[data-move-up],[data-move-down]');
  if(move){const item=move.closest('[data-theme],[data-priority],[data-department],[data-statement]');if(move.hasAttribute('data-move-up')&&item.previousElementSibling)item.parentElement.insertBefore(item,item.previousElementSibling);if(move.hasAttribute('data-move-down')&&item.nextElementSibling)item.parentElement.insertBefore(item.nextElementSibling,item);reindex();return;}
  const remove=event.target.closest('[data-remove]');if(remove){remove.closest('[data-theme],[data-priority],[data-department],[data-statement]').remove();reindex();return;}
  const priority=event.target.closest('[data-add-priority]');if(priority){priority.closest('[data-theme]').querySelector('[data-priorities]').append(clone('priority'));reindex();return;}
  const statement=event.target.closest('[data-add-statement]');if(statement){statement.closest('[data-department]').querySelector('[data-statements]').append(clone('statement'));reindex();}
 });
 form.addEventListener('submit',reindex);
 reindex();
})();
</script>
