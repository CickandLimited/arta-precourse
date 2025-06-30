<?php
class Custom_Form_Manager {
    public function init() {
        Custom_DB::get_instance()->ensure_schema();
        add_action('admin_menu', [$this, 'add_menu']);
        add_shortcode('ayotte_custom_form', [$this, 'render_form_shortcode']);
        add_action('wp_ajax_ayotte_custom_form_submit', [$this, 'handle_submission']);
        add_action('wp_ajax_nopriv_ayotte_custom_form_submit', [$this, 'handle_submission']);
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

            $labels = $_POST['field_label'] ?? [];
            $types  = $_POST['field_type'] ?? [];
            $texts  = $_POST['field_text'] ?? [];
            $required = $_POST['field_required'] ?? [];
            foreach ($labels as $idx => $label) {
                $label = sanitize_text_field($label);
                $type  = sanitize_text_field($types[$idx] ?? 'text');
                $text  = sanitize_text_field($texts[$idx] ?? '');
                $req   = in_array($idx, $required) ? 1 : 0;
                $db->query(
                    "INSERT INTO custom_form_fields (form_id,label,type,options,required) VALUES (".
                    "$id, '".$db->real_escape_string($label)."', '".$db->real_escape_string($type)."', '".$db->real_escape_string($text)."', $req)"
                );
            }
            echo '<div class="updated"><p>Form saved.</p></div>';
        }

        ?>
        <div class="wrap">
            <h1><?php echo $id ? 'Edit Form' : 'Add New Form'; ?></h1>
            <form method="post">
                <?php wp_nonce_field('ayotte_custom_form', 'ayotte_custom_form_nonce'); ?>
                <p><label>Name<br><input type="text" name="form_name" value="<?php echo esc_attr($name); ?>" class="regular-text"></label></p>
                <table class="widefat" id="ayotte-form-fields">
                    <thead><tr><th>Label</th><th>Type</th><th>Text</th><th>Required</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($fields as $idx => $f) : ?>
                        <tr>
                            <td><input type="text" name="field_label[]" value="<?php echo esc_attr($f['label']); ?>"></td>
                            <td>
                                <select name="field_type[]">
                                    <?php $types = ['text'=>'Text','textarea'=>'Textarea','date'=>'Date','static'=>'Static Text','file'=>'File'];
                                    foreach ($types as $k=>$v) {
                                        $sel = $f['type']==$k ? 'selected' : '';
                                        echo '<option value="'.$k.'" '.$sel.'>'.$v.'</option>';
                                    } ?>
                                </select>
                            </td>
                            <td><input type="text" name="field_text[]" value="<?php echo esc_attr($f['options']); ?>"></td>
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
                row.innerHTML = `<td><input type="text" name="field_label[]"></td>`+
                    `<td><select name="field_type[]">`+
                    `<option value="text">Text</option>`+
                    `<option value="textarea">Textarea</option>`+
                    `<option value="date">Date</option>`+
                    `<option value="static">Static Text</option>`+
                    `<option value="file">File</option>`+
                    `</select></td>`+
                    `<td><input type="text" name="field_text[]"></td>`+
                    `<td><input type="checkbox" name="field_required[]" value=""></td>`+
                    `<td><button type="button" class="remove button">Remove</button></td>`;
                table.appendChild(row);
                updateIndexes();
            };
            table.addEventListener('click', e=>{
                if(e.target.classList.contains('remove')){
                    e.target.closest('tr').remove();
                    updateIndexes();
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
        ob_start();
        ?>
        <form id="ayotteCustomForm<?php echo $id; ?>" enctype="multipart/form-data">
            <?php wp_nonce_field('ayotte_custom_form_submit','ayotte_custom_form_nonce'); ?>
            <input type="hidden" name="form_id" value="<?php echo $id; ?>">
            <?php foreach ($fields as $f): ?>
                <?php $name = 'field_'.$f['id']; ?>
                <p>
                    <label><?php echo esc_html($f['label']); ?><br>
                    <?php switch($f['type']) {
                        case 'textarea':
                            echo '<textarea name="'.$name.'"></textarea>'; break;
                        case 'date':
                            echo '<input type="date" name="'.$name.'">'; break;
                        case 'static':
                            echo '<span>'.esc_html($f['options']).'</span>'; break;
                        case 'file':
                            echo '<input type="file" name="'.$name.'">'; break;
                        default:
                            echo '<input type="text" name="'.$name.'">';
                    } ?>
                    </label>
                </p>
            <?php endforeach; ?>
            <button type="submit">Submit</button>
            <span class="ayotte-custom-result"></span>
        </form>
        <script>
        document.getElementById('ayotteCustomForm<?php echo $id; ?>').onsubmit = function(e){
            e.preventDefault();
            const form=this;
            const data=new FormData(form);
            fetch(ajaxurl+"?action=ayotte_custom_form_submit",{method:'POST',body:data})
                .then(r=>r.json()).then(res=>{
                    form.querySelector('.ayotte-custom-result').textContent=res.success?'Saved':'Error';
                    if(res.success) form.reset();
                });
        };
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Handle form submissions via AJAX.
     */
    public function handle_submission() {
        check_ajax_referer('ayotte_custom_form_submit','ayotte_custom_form_nonce');
        $id = intval($_POST['form_id'] ?? 0);
        $db = Custom_DB::get_instance()->get_connection();
        if ($db instanceof WP_Error) wp_send_json_error();
        $user_id = get_current_user_id();
        $res = $db->query("SELECT * FROM custom_form_fields WHERE form_id=$id ORDER BY id ASC");
        if (!$res) wp_send_json_error();
        $fields = [];
        while ($row=$res->fetch_assoc()) $fields[]=$row;
        $data = [];
        foreach ($fields as $f){
            $key='field_'.$f['id'];
            if($f['type']=='file'){
                if(!empty($_FILES[$key]['name'])){
                    $upload=wp_handle_upload($_FILES[$key], ['test_form'=>false]);
                    if(!isset($upload['error'])) $data[$key]=$upload['url'];
                }
            }else{
                $data[$key]=sanitize_text_field($_POST[$key]??'');
            }
        }
        $json=$db->real_escape_string(json_encode($data));
        $db->query("INSERT INTO custom_form_submissions (form_id,user_id,submitted_at,data) VALUES ($id,$user_id,NOW(),'$json')");
        do_action('ayotte_custom_form_submitted', $id, $user_id, $db->insert_id);
        wp_send_json_success();
    }
}
?>
