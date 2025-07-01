<?php
class Custom_Form_Manager {
    public function init() {
        Custom_DB::get_instance()->ensure_schema();
        add_action('admin_menu', [$this, 'add_menu']);
        add_shortcode('ayotte_custom_form', [$this, 'render_form_shortcode']);
        add_action('wp_ajax_ayotte_custom_form_submit', [$this, 'ajax_final_submission']);
        add_action('wp_ajax_nopriv_ayotte_custom_form_submit', [$this, 'ajax_final_submission']);
        add_action('wp_ajax_ayotte_custom_form_save_draft', [$this, 'ajax_save_draft']);
        add_action('wp_ajax_nopriv_ayotte_custom_form_save_draft', [$this, 'ajax_save_draft']);
    }

    /**
     * Admin menu registration.
     */
    public function add_menu() {
        add_submenu_page(
            'ayotte-precourse',
            'Custom Forms',
            'Custom Forms',
            'manage_options',
            'ayotte-custom-forms',
            [$this, 'list_forms']
        );
    }

    /**
     * Display list of forms with options to edit or delete.
     */
    public function list_forms() {
        if (isset($_GET['action'])) {
            if ($_GET['action'] === 'edit') {
                $this->edit_form();
                return;
            }
            if ($_GET['action'] === 'preview') {
                $this->preview_form();
                return;
            }
        }

        $db = Custom_DB::get_instance()->get_connection();
        if ($db instanceof WP_Error) {
            echo '<div class="error"><p>Unable to connect to database.</p></div>';
            return;
        }

        if (isset($_GET['action'], $_GET['form_id']) && $_GET['action'] === 'delete') {
            $id = intval($_GET['form_id']);
            $db->query("DELETE FROM custom_forms WHERE id=$id");
            $db->query("DELETE FROM custom_form_fields WHERE form_id=$id");
            echo '<div class="updated"><p>Form deleted.</p></div>';
        }

        $forms = $db->query('SELECT id,name FROM custom_forms');
        echo '<div class="wrap"><h1>Custom Forms <a href="?page=ayotte-custom-forms&action=edit" class="page-title-action">Add New</a></h1>';
        if ($forms && $forms->num_rows) {
            echo '<table class="widefat"><thead><tr><th>ID</th><th>Name</th><th>Actions</th></tr></thead><tbody>';
            while ($row = $forms->fetch_assoc()) {
                $id = intval($row['id']);
                $edit    = esc_url(add_query_arg(['page'=>'ayotte-custom-forms','action'=>'edit','form_id'=>$id], admin_url('admin.php')));
                $del     = esc_url(add_query_arg(['page'=>'ayotte-custom-forms','action'=>'delete','form_id'=>$id], admin_url('admin.php')));
                $preview = esc_url(add_query_arg(['page'=>'ayotte-custom-forms','action'=>'preview','form_id'=>$id], admin_url('admin.php')));
                echo '<tr><td>' . $id . '</td><td>' . esc_html($row['name']) . '</td><td><a href="' . $edit . '">Edit</a> | <a href="' . $del . '">Delete</a> | <a href="' . $preview . '">Preview</a></td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>No forms found.</p>';
        }
        echo '</div>';
    }

    /**
     * Render form builder for creating or editing a form.
     */
    public function edit_form() {
        $db = Custom_DB::get_instance()->get_connection();
        if ($db instanceof WP_Error) {
            echo '<div class="error"><p>Unable to connect to database.</p></div>';
            return;
        }

        $id   = intval($_GET['form_id'] ?? 0);
        $name = '';
        $fields = [];
        if ($id) {
            $res = $db->query("SELECT name FROM custom_forms WHERE id=$id");
            if ($res && $row=$res->fetch_assoc()) {
                $name = $row['name'];
            }
            $res = $db->query("SELECT * FROM custom_form_fields WHERE form_id=$id ORDER BY id ASC");
            while ($res && $row = $res->fetch_assoc()) {
                $fields[] = $row;
            }
        }

        if (!empty($_POST['ayotte_custom_form_nonce']) && check_admin_referer('ayotte_custom_form', 'ayotte_custom_form_nonce')) {
            $name = sanitize_text_field($_POST['form_name'] ?? '');
            if ($id) {
                $db->query("UPDATE custom_forms SET name='".$db->real_escape_string($name)."' WHERE id=$id");
                $db->query("DELETE FROM custom_form_fields WHERE form_id=$id");
            } else {
                $db->query("INSERT INTO custom_forms (name) VALUES ('".$db->real_escape_string($name)."')");
                $id = $db->insert_id;
            }

            $labels     = $_POST['field_label'] ?? [];
            $types      = $_POST['field_type'] ?? [];
            $texts      = $_POST['field_text'] ?? [];
            $opts_in    = $_POST['field_options'] ?? [];
            $conds_in   = $_POST['field_conditions'] ?? [];
            $valid_in   = $_POST['field_validation'] ?? [];
            $required   = $_POST['field_required'] ?? [];
            foreach ($labels as $idx => $label) {
                $label = sanitize_text_field($label);
                $type  = sanitize_text_field($types[$idx] ?? 'text');
                $raw_text  = $texts[$idx] ?? '';
                $raw_opts  = $opts_in[$idx] ?? '';
                if ($type === 'static') {
                    $value = wp_kses_post($raw_text);
                } elseif (in_array($type, ['select', 'checkbox', 'radio'], true)) {
                    $value = sanitize_text_field($raw_opts);
                } else {
                    $value = '';
                }
                $cond  = sanitize_text_field($conds_in[$idx] ?? '');
                $valid = sanitize_text_field($valid_in[$idx] ?? '');
                $req   = in_array($idx, $required) ? 1 : 0;
                $db->query(
                    "INSERT INTO custom_form_fields (form_id,label,type,options,required,conditions,validation_rules) VALUES (".
                    "$id, '".$db->real_escape_string($label)."', '".$db->real_escape_string($type)."', '".$db->real_escape_string($value)."', $req, '".$db->real_escape_string($cond)."', '".$db->real_escape_string($valid)."')"
                );
            }
            echo '<div class="updated"><p>Form saved.</p></div>';
        }
        wp_enqueue_editor();

        ?>
        <div class="wrap">
            <h1><?php echo $id ? 'Edit Form' : 'Add New Form'; ?></h1>
            <form method="post">
                <?php wp_nonce_field('ayotte_custom_form', 'ayotte_custom_form_nonce'); ?>
                <p><label>Name<br><input type="text" name="form_name" value="<?php echo esc_attr($name); ?>" class="regular-text"></label></p>
                <table class="widefat" id="ayotte-form-fields">
                    <thead><tr><th>Label</th><th>Type</th><th>Text</th><th>Options</th><th>Conditions</th><th>Validation</th><th>Required</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($fields as $idx => $f) : ?>
                        <tr>
                            <td><input type="text" name="field_label[]" value="<?php echo esc_attr($f['label']); ?>"></td>
                            <td>
                                <select name="field_type[]">
                                    <?php $types = [
                                        'text'      => 'Text',
                                        'textarea'  => 'Textarea',
                                        'date'      => 'Date',
                                        'static'    => 'Static Text',
                                        'file'      => 'File',
                                        'checkbox'  => 'Checkbox',
                                        'select'    => 'Select',
                                        'radio'     => 'Radio',
                                        'pagebreak' => 'Page Break'
                                    ];
                                    foreach ($types as $k=>$v) {
                                        $sel = $f['type']==$k ? 'selected' : '';
                                        echo '<option value="'.$k.'" '.$sel.'>'.$v.'</option>';
                                    } ?>
                                </select>
                            </td>
                            <td class="text-cell">
                                <?php
                                if ($f['type'] === 'static') {
                                    wp_editor(
                                        $f['options'],
                                        'field_text_' . $idx,
                                        [
                                            'textarea_name' => 'field_text[]',
                                            'textarea_rows' => 4,
                                            'media_buttons' => false,
                                            'teeny'         => true,
                                        ]
                                    );
                                } else {
                                    echo '<input type="hidden" name="field_text[]" value="">';
                                }
                                ?>
                            </td>
                            <td class="options-cell">
                                <?php
                                if (in_array($f['type'], ['select', 'checkbox', 'radio'], true)) {
                                    echo '<input type="text" name="field_options[]" value="' . esc_attr($f['options']) . '">';
                                } else {
                                    echo '<input type="hidden" name="field_options[]" value="">';
                                }
                                ?>
                            </td>
                            <td class="conditions-cell"><input type="text" name="field_conditions[]" value="<?php echo esc_attr($f['conditions']); ?>"></td>
                            <td class="validation-cell"><input type="text" name="field_validation[]" value="<?php echo esc_attr($f['validation_rules']); ?>"></td>
                            <td><input type="checkbox" name="field_required[]" value="<?php echo $idx; ?>" <?php checked($f['required'],1); ?>></td>
                            <td><button type="button" class="remove button">Remove</button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p><button type="button" id="add-field" class="button">Add Field</button></p>
                <p><input type="submit" class="button button-primary" value="Save"></p>
            </form>
        </div>
        <script>
        (function(){
            const table = document.getElementById('ayotte-form-fields').querySelector('tbody');
            document.getElementById('add-field').onclick = () => {
                const row = document.createElement('tr');
                row.innerHTML =
                    `<td><input type="text" name="field_label[]"></td>`+
                    `<td><select name="field_type[]">`+
                    `<option value="text">Text</option>`+
                    `<option value="textarea">Textarea</option>`+
                    `<option value="date">Date</option>`+
                    `<option value="static">Static Text</option>`+
                    `<option value="file">File</option>`+
                    `<option value="checkbox">Checkbox</option>`+
                    `<option value="select">Select</option>`+
                    `<option value="radio">Radio</option>`+
                    `<option value="pagebreak">Page Break</option>`+
                    `</select></td>`+
                    `<td class="text-cell"><input type="hidden" name="field_text[]"></td>`+
                    `<td class="options-cell"><input type="hidden" name="field_options[]"></td>`+
                    `<td class="conditions-cell"><input type="text" name="field_conditions[]"></td>`+
                    `<td class="validation-cell"><input type="text" name="field_validation[]"></td>`+
                    `<td><input type="checkbox" name="field_required[]" value=""></td>`+
                    `<td><button type="button" class="remove button">Remove</button></td>`;
                table.appendChild(row);
                handleTypeChange(row.querySelector('select'));
                updateIndexes();
            };
            function handleTypeChange(sel){
                const row = sel.closest('tr');
                const textCell = row.querySelector('.text-cell');
                const optionsCell = row.querySelector('.options-cell');
                if(sel.value === 'static'){
                    textCell.innerHTML = '<textarea class="ayotte-static-editor" name="field_text[]"></textarea>';
                    optionsCell.innerHTML = '<input type="hidden" name="field_options[]">';
                    if(window.wp && wp.editor){
                        wp.editor.initialize(textCell.querySelector('textarea'));
                    }
                }else if(['select','checkbox','radio'].includes(sel.value)){
                    textCell.innerHTML = '<input type="hidden" name="field_text[]">';
                    optionsCell.innerHTML = '<input type="text" name="field_options[]">';
                }else{
                    textCell.innerHTML = '<input type="hidden" name="field_text[]">';
                    optionsCell.innerHTML = '<input type="hidden" name="field_options[]">';
                }
            }
           table.addEventListener('click', e=>{
               if(e.target.classList.contains('remove')){
                   e.target.closest('tr').remove();
                   updateIndexes();
               }
           });
            table.addEventListener('change', e=>{
                if(e.target.name === 'field_type[]'){
                    handleTypeChange(e.target);
                }
            });
            function updateIndexes(){
                const checks = table.querySelectorAll('input[type=checkbox]');
                checks.forEach((c,i)=>c.value=i);
            }
            updateIndexes();
        })();
        </script>
        <?php
    }

    /**
     * Render a preview of a form within the admin area.
     */
    public function preview_form() {
        $id = intval($_GET['form_id'] ?? 0);
        echo '<div class="wrap">';
        echo '<h1>Preview Form</h1>';
        echo $this->render_form_shortcode(['id' => $id]);
        echo '</div>';
    }

    /**
     * Shortcode handler to display a form on the frontend.
     */
    public function render_form_shortcode($atts) {
        $id = intval($atts['id'] ?? 0);
        if (!$id) return '';
        $db = Custom_DB::get_instance()->get_connection();
        if ($db instanceof WP_Error) return '<p>Database connection error.</p>';
        $res = $db->query("SELECT * FROM custom_form_fields WHERE form_id=$id ORDER BY id ASC");
        if (!$res || !$res->num_rows) return '<p>Form not found.</p>';
        $fields = [];
        while ($row = $res->fetch_assoc()) $fields[] = $row;

        // Pull latest draft data for this user
        $user_id      = get_current_user_id();
        $values       = [];
        $current_page = 0;
        if ($user_id) {
            $tracker  = new Ayotte_Progress_Tracker();
            $status   = $tracker->get_form_status($id, $user_id);
            $unlocked = get_user_meta($user_id, "ayotte_form_{$id}_unlocked", true);
            if (in_array($status, ['locked', 'completed'], true) && !$unlocked) {
                return (new Ayotte_Form_Manager())->render_readonly_submission($id, $user_id);
            }
            $dres = $db->query(
                "SELECT data FROM custom_form_submissions WHERE form_id=$id AND user_id=$user_id AND status='draft' ORDER BY submitted_at DESC LIMIT 1"
            );
            if ($dres && $dres->num_rows) {
                $row  = $dres->fetch_assoc();
                $data = json_decode($row['data'], true);
                if (is_array($data)) {
                    $values = $data;
                    if (isset($data['_current_page'])) {
                        $current_page = intval($data['_current_page']);
                    }
                }
            }
        }

        $pages = [];
        $page_fields = [];
        foreach ($fields as $f) {
            if ($f['type'] === 'pagebreak') {
                $pages[] = $page_fields;
                $page_fields = [];
                continue;
            }
            $page_fields[] = $f;
        }
        $pages[] = $page_fields;
        ob_start();
        ?>
        <div class="precourse-form">
        <form id="ayotteCustomForm<?php echo $id; ?>" enctype="multipart/form-data">
            <?php wp_nonce_field('ayotte_custom_form_submit','ayotte_custom_form_nonce'); ?>
            <input type="hidden" name="form_id" value="<?php echo $id; ?>">
            <input type="hidden" name="current_page" value="<?php echo $current_page; ?>">
            <?php foreach ($pages as $pi => $pfields): ?>
            <div class="ayotte-form-page" data-page="<?php echo $pi; ?>" style="<?php echo $pi==$current_page?'':'display:none'; ?>">
                <?php foreach ($pfields as $f): ?>
                    <?php $name = 'field_'.$f['id']; ?>
                    <p<?php echo $f['conditions'] ? ' data-conditions="'.esc_attr($f['conditions']).'"' : ''; ?>>
                        <label><?php echo esc_html($f['label']); ?><br>
                        <?php
                            $val  = $values[$name] ?? '';
                            $req  = $f['required'] ? ' required' : '';
                            $rules_attr = $f['validation_rules'] ? ' data-validate="' . esc_attr($f['validation_rules']) . '"' : '';
                            $accept = '';
                            if ($f['validation_rules'] && preg_match('/ext:([a-zA-Z0-9,]+)/', $f['validation_rules'], $m)) {
                                $exts = array_map('trim', explode(',', $m[1]));
                                $exts = array_map('sanitize_key', $exts);
                                $accept = ' accept=".' . implode(',.', $exts) . '"';
                            }
                            switch($f['type']) {
                                case 'textarea':
                                    echo '<textarea name="'.$name.'"'.$req.$rules_attr.'>'.esc_textarea($val).'</textarea>'; break;
                                case 'date':
                                    echo '<input type="date" name="'.$name.'" value="'.esc_attr($val).'"'.$req.$rules_attr.'>'; break;
                                case 'static':
                                    echo '<span>'.wp_kses_post($f['options']).'</span>'; break;
                                case 'file':
                                    echo '<input type="file" name="'.$name.'"'.$req.$rules_attr.$accept.'>';
                                    if (!empty($val)) echo '<br><em>Current: <a href="'.esc_url($val).'" target="_blank">View</a></em>';
                                    break;
                                case 'checkbox':
                                    $opts = array_map('trim', explode(',', $f['options']));
                                    $selected = $val ? explode(',', $val) : [];
                                    foreach ($opts as $o) {
                                        $checked = in_array($o, $selected) ? 'checked' : '';
                                        echo '<label><input type="checkbox" name="'.$name.'[]" value="'.esc_attr($o).'" '.$checked.$req.$rules_attr.'> '.esc_html($o).'</label> ';
                                    }
                                    break;
                                case 'select':
                                    $opts = array_map('trim', explode(',', $f['options']));
                                    echo '<select name="'.$name.'"'.$req.$rules_attr.'>';
                                    foreach ($opts as $o) {
                                        $sel = ($o === $val) ? 'selected' : '';
                                        echo '<option value="'.esc_attr($o).'" '.$sel.'>'.esc_html($o).'</option>';
                                    }
                                    echo '</select>';
                                    break;
                                case 'radio':
                                    $opts = array_map('trim', explode(',', $f['options']));
                                    foreach ($opts as $o) {
                                        $checked = ($o === $val) ? 'checked' : '';
                                        echo '<label><input type="radio" name="'.$name.'" value="'.esc_attr($o).'" '.$checked.$req.$rules_attr.'> '.esc_html($o).'</label> ';
                                    }
                                    break;
                                default:
                                    echo '<input type="text" name="'.$name.'" value="'.esc_attr($val).'"'.$req.$rules_attr.'>';
                            }
                        ?>
                        </label>
                        <span class="field-error" style="color:#a32c2e"></span>
                    </p>
                <?php endforeach; ?>
                <p class="ayotte-nav">
                    <?php if ($pi > 0): ?><button type="button" class="prev-page button">Previous</button><?php endif; ?>
                    <?php if ($pi < count($pages)-1): ?><button type="button" class="next-page button">Next</button><?php endif; ?>
                    <?php if ($pi == count($pages)-1): ?>
                        <button type="button" class="ayotte-save-draft button">Save Draft</button>
                        <button type="submit" class="button">Submit</button>
                    <?php endif; ?>
                </p>
            </div>
            <?php endforeach; ?>
            <span class="ayotte-custom-result"></span>
        </form>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function(){
            const form = document.getElementById('ayotteCustomForm<?php echo $id; ?>');
            if(!form) return;
            const pages = form.querySelectorAll('.ayotte-form-page');
            let page = <?php echo $current_page; ?>;
            const dashboardUrl = '<?php echo esc_js(site_url('/precourse-forms')); ?>';
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
                            val = chk?chk.value:'';
                        }else{
                            const f = form.querySelector('[name="'+name+'"]');
                            if(f) val = f.value;
                        }
                        el.style.display = (val==cond.value)?'':'none';
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
                return ok;
            }
            form.onsubmit = function(e){
                e.preventDefault();
                if(!runValidation()) return;
                const data = new FormData(form);
                fetch(ajaxurl+"?action=ayotte_custom_form_submit",{method:'POST',body:data})
                    .then(r=>r.json()).then(res=>{
                        const msg = res.data && res.data.message ? res.data.message : (res.success ? 'Saved' : 'Error');
                        form.querySelector('.ayotte-custom-result').textContent = msg;
                        if(res.success) window.location.href = dashboardUrl;
                    });
            };
            form.querySelectorAll('.ayotte-save-draft').forEach(btn=>btn.addEventListener('click',function(e){
                e.preventDefault();
                const data = new FormData(form);
                fetch(ajaxurl+"?action=ayotte_custom_form_save_draft",{method:'POST',body:data})
                    .then(r=>r.json()).then(res=>{
                        const msg = res.data && res.data.message ? res.data.message : (res.success ? 'Draft saved' : 'Error');
                        form.querySelector('.ayotte-custom-result').textContent = msg;
                        if(res.success) window.location.href = dashboardUrl;
                    });
            }));
            show(page);
            checkConditions();
        });
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Handle form submissions via AJAX.
     */
    public function ajax_final_submission() {
        $this->handle_submission('submitted');
    }

    public function ajax_save_draft() {
        $this->handle_submission('draft');
    }

    /**
     * Handle form submissions via AJAX.
     *
     * @param string $status draft|submitted
     */
    public function handle_submission($status = 'submitted') {
        check_ajax_referer('ayotte_custom_form_submit','ayotte_custom_form_nonce');
        $id = intval($_POST['form_id'] ?? 0);
        $db = Custom_DB::get_instance()->get_connection();
        if ($db instanceof WP_Error) wp_send_json_error();
        $user_id = get_current_user_id();
        $res = $db->query("SELECT * FROM custom_form_fields WHERE form_id=$id ORDER BY id ASC");
        if (!$res) wp_send_json_error();
        $fields = [];
        while ($row=$res->fetch_assoc()) $fields[]=$row;

        $unlocked_meta = get_user_meta($user_id, "ayotte_form_{$id}_unlocked", true);
        $last = $db->query("SELECT id, locked FROM custom_form_submissions WHERE form_id=$id AND user_id=$user_id ORDER BY submitted_at DESC LIMIT 1");
        $submission_id = 0;
        $previous_locked = 0;
        if ($last && $last->num_rows) {
            $row = $last->fetch_assoc();
            $submission_id = intval($row['id']);
            $previous_locked = intval($row['locked']);
            if ($previous_locked && !$unlocked_meta) {
                wp_send_json_error();
            }
        }
        $data = [];
        foreach ($fields as $f){
            if ($f['type'] === 'pagebreak') continue;
            $key='field_'.$f['id'];
            if($f['type']=='file'){
                if(!empty($_FILES[$key]['name'])){
                    $upload=wp_handle_upload($_FILES[$key], ['test_form'=>false]);
                    if(!isset($upload['error'])) $data[$key]=$upload['url'];
                }
            }elseif($f['type']=='checkbox'){
                $vals = $_POST[$key] ?? [];
                if(is_array($vals)) {
                    $vals = array_map('sanitize_text_field', $vals);
                    $data[$key] = implode(',', $vals);
                } else {
                    $data[$key] = sanitize_text_field($vals);
                }
            }else{
                $data[$key]=sanitize_text_field($_POST[$key]??'');
            }
        }
        if ($status === 'submitted') {
            foreach ($fields as $f) {
                if ($f['type'] === 'pagebreak' || !$f['required']) continue;
                $key = 'field_'.$f['id'];
                $val = $data[$key] ?? '';
                if ($val === '' && $val !== '0') {
                    wp_send_json_error(['message' => 'Please complete all required fields']);
                }
            }
            foreach ($fields as $f) {
                if ($f['type'] === 'pagebreak') continue;
                $rules = self::parse_validation_rules($f['validation_rules']);
                if (!$rules) continue;
                $key = 'field_'.$f['id'];
                $val = $data[$key] ?? '';
                if ($f['type'] === 'file') {
                    $val = $_FILES[$key]['name'] ?? '';
                }
                if (isset($rules['text_only']) && preg_match('/[^a-zA-Z\s]/', $val)) {
                    wp_send_json_error(['message' => 'Invalid characters in '.$f['label']]);
                }
                if (isset($rules['numbers_only']) && preg_match('/[^0-9]/', $val)) {
                    wp_send_json_error(['message' => 'Only numbers allowed in '.$f['label']]);
                }
                if (isset($rules['min_length']) && strlen($val) < $rules['min_length']) {
                    wp_send_json_error(['message' => 'Minimum length for '.$f['label'].' is '.$rules['min_length']]);
                }
                if (isset($rules['max_length']) && strlen($val) > $rules['max_length']) {
                    wp_send_json_error(['message' => 'Maximum length for '.$f['label'].' is '.$rules['max_length']]);
                }
                if ($f['type'] === 'file' && isset($rules['extensions']) && $val) {
                    $ext = strtolower(pathinfo($val, PATHINFO_EXTENSION));
                    if (!in_array($ext, $rules['extensions'], true)) {
                        wp_send_json_error(['message' => 'Invalid file type for '.$f['label']]);
                    }
                }
                if ($f['type'] === 'date' && isset($rules['min_age']) && $val) {
                    $age = floor((time() - strtotime($val)) / 31557600);
                    if ($age < $rules['min_age']) {
                        wp_send_json_error(['message' => 'You must be at least '.$rules['min_age'].' for '.$f['label']]);
                    }
                }
            }
        }
        if (isset($_POST['current_page'])) {
            $data['_current_page'] = intval($_POST['current_page']);
        }
        $json   = $db->real_escape_string(json_encode($data));
        $status = ($status === 'draft') ? 'draft' : 'submitted';
        $locked = ($status === 'draft') ? 0 : 1;

        if ($submission_id) {
            $db->query("UPDATE custom_form_submissions SET data='$json', status='$status', locked=$locked, submitted_at=NOW() WHERE id=$submission_id");
        } else {
            $db->query("INSERT INTO custom_form_submissions (form_id,user_id,submitted_at,data,status,locked) VALUES ($id,$user_id,NOW(),'$json','$status',$locked)");
            $submission_id = $db->insert_id;
        }

        if ($status === 'submitted') {
            delete_user_meta($user_id, "ayotte_form_{$id}_unlocked");
            do_action('ayotte_custom_form_submitted', $id, $submission_id);
        }

        $tracker = new Ayotte_Progress_Tracker();
        $state   = $locked ? 'locked' : (($status === 'draft') ? 'draft' : 'completed');
        update_user_meta($user_id, "ayotte_form_{$id}_status", $state);
        $tracker->recalculate_progress($user_id);

        wp_send_json_success();
    }

    private static function parse_validation_rules($str) {
        $rules = [];
        foreach (explode(';', (string) $str) as $r) {
            $r = trim($r);
            if ($r === '') continue;
            if ($r === 'text-only') {
                $rules['text_only'] = true;
            } elseif ($r === 'numbers-only') {
                $rules['numbers_only'] = true;
            } elseif (preg_match('/^min:(\d+)$/', $r, $m)) {
                $rules['min_length'] = (int) $m[1];
            } elseif (preg_match('/^max:(\d+)$/', $r, $m)) {
                $rules['max_length'] = (int) $m[1];
            } elseif (preg_match('/^ext:([a-zA-Z0-9,]+)$/', $r, $m)) {
                $rules['extensions'] = array_map('strtolower', array_map('trim', explode(',', $m[1])));
            } elseif (preg_match('/^minage:(\d+)$/', $r, $m)) {
                $rules['min_age'] = (int) $m[1];
            }
        }
        return $rules;
    }
}
?>
