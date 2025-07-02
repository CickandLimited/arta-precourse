(function(){
  function init(){
    if(!window.ayotteCustomFormData) return;
    const data = window.ayotteCustomFormData;
    const form = document.getElementById('ayotteCustomForm'+data.formId);
    if(!form) return;

    const pages = form.querySelectorAll('.ayotte-form-page');
    let page = parseInt(data.currentPage, 10) || 0;
    const dashboardUrl = data.dashboardUrl;

    function checkConditions(){
      form.querySelectorAll('[data-conditions]').forEach(el=>{
        const str = el.getAttribute('data-conditions');
        if(!str) return;
        try{
          const cond = JSON.parse(str);
          const name = 'field_'+cond.target_field;
          let val = '';
          if(form.querySelectorAll('[name="'+name+'[]"]').length){
            val = Array.from(form.querySelectorAll('input[name="'+name+'[]"]:checked')).map(i=>i.value).join(',');
          }else if(form.querySelectorAll('input[name="'+name+'"]').length){
            const chk = form.querySelector('input[name="'+name+'"]:checked');
            val = chk ? chk.value : '';
          }else{
            const f = form.querySelector('[name="'+name+'"]');
            if(f) val = f.value;
          }
          let pass = false;
          if(cond.operator === 'not_equals') pass = val!=cond.value;
          else pass = val==cond.value;
          if(cond.action === 'hide')
            el.style.display = pass ? 'none' : '';
          else
            el.style.display = pass ? '' : 'none';
        }catch(e){
          el.style.display='';
        }
      });
    }

    function show(i){
      pages.forEach((p,idx)=>{p.style.display = idx===i ? '' : 'none';});
      page = i;
      form.querySelector('[name="current_page"]').value = i;
      checkConditions();
    }

    form.addEventListener('click',e=>{
      if(e.target.classList.contains('next-page')){e.preventDefault(); if(page < pages.length-1) show(page+1);}
      if(e.target.classList.contains('prev-page')){e.preventDefault(); if(page>0) show(page-1);}
    });
    form.addEventListener('change',e=>{
      if(e.target.matches('select, input[type=checkbox], input[type=radio]')){
        checkConditions();
      }
    });

    function parseRules(str){
      const out={};
      str.split(';').forEach(p=>{
        p=p.trim();
        if(!p) return;
        if(p==='text-only') out.textOnly=true;
        else if(p==='numbers-only') out.numbersOnly=true;
        else if(p.startsWith('min:')) out.min=parseInt(p.slice(4));
        else if(p.startsWith('max:')) out.max=parseInt(p.slice(4));
        else if(p.startsWith('ext:')) out.ext=p.slice(4).split(',').map(s=>s.trim().toLowerCase());
        else if(p.startsWith('minage:')) out.minage=parseInt(p.slice(7));
      });
      return out;
    }

    function validateField(el){
      if(!el.dataset.validate) return '';
      const r=parseRules(el.dataset.validate);
      let val=el.value||'';
      if(el.type==='file'){
        if(el.files.length && r.ext){
          const ext=el.files[0].name.split('.').pop().toLowerCase();
          if(!r.ext.includes(ext)) return 'Invalid file type';
        }
        return '';
      }
      if(r.textOnly && /[^a-zA-Z\s]/.test(val)) return 'Text only';
      if(r.numbersOnly && /[^0-9]/.test(val)) return 'Numbers only';
      if(r.min && val.length<r.min) return 'Min length '+r.min;
      if(r.max && val.length>r.max) return 'Max length '+r.max;
      if(r.minage){
        const age=Math.floor((Date.now()-new Date(val).getTime())/31557600000);
        if(!isNaN(age) && age<r.minage) return 'Must be at least '+r.minage;
      }
      return '';
    }

    function runValidation(){
      let ok=true;
      form.querySelectorAll('.field-error').forEach(e=>e.textContent='');
      form.querySelectorAll('[data-validate]').forEach(el=>{
        const msg=validateField(el);
        if(msg){
          ok=false;
          let err=el.closest('p').querySelector('.field-error');
          if(err) err.textContent=msg;
        }
      });
      form.querySelectorAll('p[data-min-check]').forEach(p=>{
        const min=parseInt(p.dataset.minCheck,10)||0;
        if(min>0){
          const cnt=p.querySelectorAll('input[type=checkbox]:checked').length;
          if(cnt<min){
            ok=false;
            const err=p.querySelector('.field-error');
            if(err) err.textContent='Select at least '+min;
          }
        }
      });
      return ok;
    }

    form.onsubmit = function(e){
      e.preventDefault();
      if(!runValidation()) return;
      const dataF = new FormData(form);
      fetch(ajaxurl+"?action=ayotte_custom_form_submit",{method:'POST',body:dataF})
        .then(r=>r.json()).then(res=>{
          const msg = res.data && res.data.message ? res.data.message : (res.success ? 'Saved' : 'Error');
          form.querySelector('.ayotte-custom-result').textContent = msg;
          if(res.success) window.location.href = dashboardUrl;
        });
    };

    form.querySelectorAll('.ayotte-save-draft').forEach(btn=>btn.addEventListener('click',function(e){
      e.preventDefault();
      const dataF = new FormData(form);
      fetch(ajaxurl+"?action=ayotte_custom_form_save_draft",{method:'POST',body:dataF})
        .then(r=>r.json()).then(res=>{
          const msg = res.data && res.data.message ? res.data.message : (res.success ? 'Draft saved' : 'Error');
          form.querySelector('.ayotte-custom-result').textContent = msg;
          if(res.success) window.location.href = dashboardUrl;
        });
    }));

    show(page);
    checkConditions();
  }

  if(document.readyState!=='loading') init();
  else document.addEventListener('DOMContentLoaded', init);
})();
