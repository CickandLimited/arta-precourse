<?php
class Ayotte_PDF_Generator {

    public static function build_user_html($user_id, $transforms = [], $add_index = false) {
        $html = '<h1>User Submissions</h1>';

        $phone  = get_user_meta($user_id, 'ayotte_phone', true);
        $reason = get_user_meta($user_id, 'ayotte_reason', true);
        $id_file = get_user_meta($user_id, 'ayotte_id_file', true);

        $html .= '<h2>Precourse Form</h2><table border="1" cellpadding="5" cellspacing="0">';
        $html .= '<tr><th>Phone</th><td>' . esc_html($phone) . '</td></tr>';
        $html .= '<tr><th>Reason</th><td>' . esc_html($reason) . '</td></tr>';
        $html .= '</table>';

        $idx = 0;
        if ($id_file) {
            $style = 'width:100%;max-width:400px;';
            if (!empty($transforms[$idx])) $style .= self::transform_css($transforms[$idx]);
            $attr  = $add_index ? ' data-img-index="' . $idx . '"' : '';
            $html .= '<p>Photo ID:</p><img src="' . esc_url($id_file) . '"' . $attr . ' style="' . $style . '" />';
            $idx++;
        }

        $assigned = (array) get_user_meta($user_id, 'ayotte_assigned_forms', true);
        $db = Custom_DB::get_instance()->get_connection();
        if (!$db instanceof WP_Error) {
            foreach ($assigned as $fid) {
                $fid = intval($fid);
                if (!$fid) continue;

                $name = Ayotte_Progress_Tracker::get_form_name($fid);
                $fields = [];
                $types  = [];
                $res = $db->query("SELECT id,label,type FROM custom_form_fields WHERE form_id=$fid ORDER BY id ASC");
                while ($res && ($row = $res->fetch_assoc())) {
                    $fields['field_' . intval($row['id'])] = $row['label'];
                    $types['field_' . intval($row['id'])]  = $row['type'];
                }
                $sub = $db->query("SELECT data FROM custom_form_submissions WHERE form_id=$fid AND user_id=$user_id ORDER BY submitted_at DESC LIMIT 1");
                if ($sub && $sub->num_rows) {
                    $row  = $sub->fetch_assoc();
                    $data = json_decode($row['data'], true);
                    if (is_array($data)) {
                        $html .= '<h2>' . esc_html($name) . '</h2><table border="1" cellpadding="5" cellspacing="0">';
                        foreach ($data as $key => $val) {
                            $label = $fields[$key] ?? $key;
                            $type  = $types[$key] ?? '';
                            if ($type === 'file' && $val) {
                                $path = esc_url_raw($val);
                                $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                                if ($ext === 'pdf') {
                                    $html .= '<tr><th>' . esc_html($label) . '</th><td><em>PDF file</em></td></tr>';
                                } else {
                                    $style = 'width:100%;max-width:400px;';
                                    if (!empty($transforms[$idx])) $style .= self::transform_css($transforms[$idx]);
                                    $attr  = $add_index ? ' data-img-index="' . $idx . '"' : '';
                                    $html .= '<tr><th>' . esc_html($label) . '</th><td><img src="' . esc_url($path) . '"' . $attr . ' style="' . $style . '" /></td></tr>';
                                    $idx++;
                                }
                            } else {
                                $html .= '<tr><th>' . esc_html($label) . '</th><td>' . esc_html($val) . '</td></tr>';
                            }
                        }
                        $html .= '</table>';
                    }
                }
            }
        }

        return $html;
    }

    /**
     * Create a consolidated PDF for all submissions by a user.
     *
     * @param int $user_id
     * @param array $transforms Optional image transformations.
     * @return string|WP_Error URL to the generated PDF or error.
     */
    public static function create_user_pdf($user_id, $transforms = []) {
        // Support both the old global mPDF class as well as the newer
        // namespaced Mpdf\Mpdf class.
        if (!class_exists('mPDF') && !class_exists('\\Mpdf\\Mpdf')) {
            return new WP_Error('missing_library', 'mPDF library is not available');
        }

        $mpdf = class_exists('\\Mpdf\\Mpdf') ? new \Mpdf\Mpdf() : new \mPDF();

        $idx = 0;
        $phone  = get_user_meta($user_id, 'ayotte_phone', true);
        $reason = get_user_meta($user_id, 'ayotte_reason', true);
        $id_file = get_user_meta($user_id, 'ayotte_id_file', true);

        $html = '<h2>Precourse Form</h2><table border="1" cellpadding="5" cellspacing="0">';
        $html .= '<tr><th>Phone</th><td>' . esc_html($phone) . '</td></tr>';
        $html .= '<tr><th>Reason</th><td>' . esc_html($reason) . '</td></tr>';
        $html .= '</table>';
        if ($id_file) {
            $style = 'width:100%;max-width:400px;';
            if (!empty($transforms[$idx])) $style .= self::transform_css($transforms[$idx]);
            $html .= '<p>Photo ID:</p><img src="' . esc_url($id_file) . '" style="' . $style . '" />';
            $idx++;
        }

        $assigned = (array) get_user_meta($user_id, 'ayotte_assigned_forms', true);
        $db = Custom_DB::get_instance()->get_connection();
        if (!$db instanceof WP_Error) {
            foreach ($assigned as $fid) {
                $fid = intval($fid);
                if (!$fid) continue;

                $name = Ayotte_Progress_Tracker::get_form_name($fid);
                $fields = [];
                $types  = [];
                $res = $db->query("SELECT id,label,type FROM custom_form_fields WHERE form_id=$fid ORDER BY id ASC");
                while ($res && ($row = $res->fetch_assoc())) {
                    $fields['field_' . intval($row['id'])] = $row['label'];
                    $types['field_' . intval($row['id'])]  = $row['type'];
                }
                $sub = $db->query("SELECT data FROM custom_form_submissions WHERE form_id=$fid AND user_id=$user_id ORDER BY submitted_at DESC LIMIT 1");
                if ($sub && $sub->num_rows) {
                    $row  = $sub->fetch_assoc();
                    $data = json_decode($row['data'], true);
                    if (is_array($data)) {
                        $html .= '<h2>' . esc_html($name) . '</h2><table border="1" cellpadding="5" cellspacing="0">';
                        foreach ($data as $key => $val) {
                            $label = $fields[$key] ?? $key;
                            $type  = $types[$key] ?? '';
                            if ($type === 'file' && $val) {
                                $path = esc_url_raw($val);
                                $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                                if ($ext === 'pdf' && method_exists($mpdf, 'SetSourceFile')) {
                                    $mpdf->WriteHTML($html);
                                    $html = '';
                                    try {
                                        $pagecount = $mpdf->SetSourceFile($path);
                                        for ($p=1; $p <= $pagecount; $p++) {
                                            $tpl = $mpdf->ImportPage($p);
                                            $mpdf->AddPage();
                                            $mpdf->UseTemplate($tpl);
                                        }
                                    } catch (\Throwable $e) {
                                        // ignore
                                    }
                                } else {
                                    $style = 'width:100%;max-width:400px;';
                                    if (!empty($transforms[$idx])) $style .= self::transform_css($transforms[$idx]);
                                    $html .= '<tr><th>' . esc_html($label) . '</th><td><img src="' . esc_url($path) . '" style="' . $style . '" /></td></tr>';
                                    $idx++;
                                }
                            } else {
                                $html .= '<tr><th>' . esc_html($label) . '</th><td>' . esc_html($val) . '</td></tr>';
                            }
                        }
                        $html .= '</table>';
                    }
                }
            }
        }

        if ($html !== '') {
            $mpdf->WriteHTML($html);
        }

        $upload = wp_upload_dir();
        $folder = trailingslashit($upload['basedir']) . 'ayotte_pdfs';
        if (!file_exists($folder)) {
            wp_mkdir_p($folder);
        }
        $file = $folder . '/user_' . $user_id . '_' . time() . '.pdf';
        $mpdf->Output($file, 'F');
        return trailingslashit($upload['baseurl']) . 'ayotte_pdfs/' . basename($file);
    }

    private static function transform_css($t) {
        $x = intval($t['x'] ?? 0);
        $y = intval($t['y'] ?? 0);
        $r = floatval($t['rotate'] ?? 0);
        $s = floatval($t['scale'] ?? 1);
        return " transform: translate({$x}px,{$y}px) rotate({$r}deg) scale({$s});";
    }
}

