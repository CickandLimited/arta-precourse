(function(){
    function apply(img, t){
        img.style.transform = `translate(${t.x}px,${t.y}px) rotate(${t.rotate}deg) scale(${t.scale})`;
    }
    function initImage(img){
        const t = {x:0,y:0,scale:1,rotate:0};
        img._ayotteTransform = t;
        interact(img).draggable({
            listeners:{ move: ev=>{ t.x += ev.dx; t.y += ev.dy; apply(img,t); } }
        }).resizable({
            preserveAspectRatio:true,
            edges:{left:true,right:true,top:true,bottom:true},
            listeners:{ move: ev=>{ t.scale *= (1 + ev.deltaRect.width/img.offsetWidth); apply(img,t); } }
        });
        const slider = document.createElement('input');
        slider.type='range';
        slider.min=-180; slider.max=180; slider.value=0;
        slider.addEventListener('input',()=>{ t.rotate = parseInt(slider.value,10); apply(img,t); });
        img.parentNode.insertBefore(slider, img.nextSibling);
    }
    async function openModal(user){
        const modal = document.createElement('div');
        modal.className='ayotte-pdf-modal';
        modal.innerHTML='<div class="ayotte-pdf-dialog"><div class="pdf-msg"></div><div class="ayotte-pdf-preview">Loading...</div><p><button class="button button-primary ayotte-pdf-save">Generate PDF</button> <button class="button ayotte-pdf-close">Close</button></p></div>';
        document.body.appendChild(modal);
        const interactLoaded = typeof window.interact === 'function';
        const res = await fetch(ajaxurl+'?action=ayotte_prepare_pdf&interact_loaded='+(interactLoaded?1:0)+'&user_id='+user);
        const data = await res.json();
        const msgBox = modal.querySelector('.pdf-msg');
        if(data.success){
            modal.querySelector('.ayotte-pdf-preview').innerHTML = data.data.html;
            modal.querySelectorAll('img[data-img-index]').forEach(initImage);
        } else {
            msgBox.textContent = data.data.message || 'Error';
        }
        modal.querySelector('.ayotte-pdf-close').onclick = ()=>{ document.body.removeChild(modal); };
        modal.querySelector('.ayotte-pdf-save').onclick = async ()=>{
            const transforms={};
            modal.querySelectorAll('img[data-img-index]').forEach(img=>{
                transforms[img.dataset.imgIndex]=img._ayotteTransform;
            });
            modal.querySelector('.ayotte-pdf-save').disabled=true;
            const resp = await fetch(ajaxurl+'?action=ayotte_generate_pdf',{
                method:'POST',
                headers:{'Content-Type':'application/json'},
                body: JSON.stringify({user_id:user,transforms,interact_loaded:interactLoaded})
            });
            const d = await resp.json();
            modal.querySelector('.ayotte-pdf-save').disabled=false;
            if(d.success){
                window.open(d.data.url,'_blank');
                document.body.removeChild(modal);
            } else {
                msgBox.textContent = d.data.message || 'Error';
            }
        };
    }
    document.addEventListener('click',e=>{
        if(e.target.classList.contains('ayotte-generate-pdf')){
            e.preventDefault();
            openModal(e.target.dataset.user);
        }
    });
})();
