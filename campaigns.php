<?php
require_once __DIR__ . '/includes/auth_check.php';

$action      = $_GET['action'] ?? 'list';
$campaign_id = (int)($_GET['id'] ?? 0);
$user_role_key = strtolower(str_replace([' ', '-'], '_', trim((string)($user['role'] ?? ''))));
$can_manage_campaigns = in_array($user_role_key, ['super_admin', 'admin'], true);
$campaign_write_actions = [
    'new','save','edit','edit_save','add_question','edit_question','delete_question',
    'add_application_field','add_application_template','delete_application_field',
    'bulk_delete_application_fields','activate','deactivate','clone_campaign','delete_campaign',
    'bulk_delete_campaigns','save_apply_form_config'
];
if (!$can_manage_campaigns && in_array($action, $campaign_write_actions, true)) {
    header("Location: campaigns.php?msg=admin_campaigns_only");
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $post_only_targets = [
        'save' => "campaigns.php?action=new&msg=invalid_method",
        'edit_save' => $campaign_id ? "campaigns.php?action=edit&id=$campaign_id&msg=invalid_method" : "campaigns.php?msg=invalid_method",
        'add_question' => $campaign_id ? "campaigns.php?action=questions&id=$campaign_id" : "campaigns.php",
        'edit_question' => $campaign_id ? "campaigns.php?action=questions&id=$campaign_id" : "campaigns.php",
        'add_application_field' => $campaign_id ? "campaigns.php?action=apply_form&id=$campaign_id" : "campaigns.php",
        'add_application_template' => $campaign_id ? "campaigns.php?action=apply_form&id=$campaign_id" : "campaigns.php",
        'bulk_delete_application_fields' => $campaign_id ? "campaigns.php?action=apply_form&id=$campaign_id" : "campaigns.php",
        'activate' => $campaign_id ? "campaigns.php?action=questions&id=$campaign_id" : "campaigns.php",
        'bulk_delete_campaigns' => "campaigns.php?msg=invalid_method",
    ];
    if (isset($post_only_targets[$action])) {
        header("Location: " . $post_only_targets[$action]);
        exit;
    }
}

function normalize_json_text($value) {
    $value = trim((string)$value);
    if ($value === '') return null;
    $decoded = json_decode($value, true);
    return json_last_error() === JSON_ERROR_NONE ? json_encode($decoded) : null;
}

function options_to_json($value) {
    $items = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)$value))));
    return empty($items) ? null : json_encode($items);
}

function field_key_from_label($label) {
    $key = strtolower(trim((string)$label));
    $key = preg_replace('/[^a-z0-9]+/', '_', $key);
    $key = trim($key, '_');
    return $key ?: 'custom_field';
}

function question_label_from_text($question_text, $fallback = 'Interview Question') {
    $label = trim(preg_replace('/\s+/', ' ', strip_tags((string)$question_text)));
    $label = preg_replace('/\s*(choices?|options?)\s*:\s*.+$/i', '', $label);
    $label = trim($label, " \t\n\r\0\x0B?.");
    if (strlen($label) > 70) $label = substr($label, 0, 67) . '...';
    return $label !== '' ? $label : $fallback;
}

function question_topic_presets($job_role = '') {
    $role = strtolower((string)$job_role);
    $base = [
        ['communication', 'Communication Skills'],
        ['role_knowledge', 'Role Knowledge'],
        ['practical_experience', 'Practical Experience'],
        ['problem_solving', 'Problem Solving'],
        ['tools_software', 'Tools / Software Knowledge'],
        ['process_compliance', 'Process & Compliance'],
        ['customer_handling', 'Customer Handling'],
        ['confidence', 'Confidence Level'],
    ];
    if (str_contains($role, 'network')) {
        return [
            ['networking_basics', 'Networking Basics'],
            ['routing_switching', 'Routing & Switching'],
            ['firewall_security', 'Firewall & Security'],
            ['troubleshooting', 'Troubleshooting'],
            ['linux_windows_admin', 'Linux / Windows Admin'],
            ['communication', 'Communication Skills'],
            ['process_compliance', 'Process & Compliance'],
            ['custom', 'Custom Topic'],
        ];
    }
    if (str_contains($role, 'sales') || str_contains($role, 'business development')) {
        return [
            ['sales_pitch', 'Sales Pitch'],
            ['lead_qualification', 'Lead Qualification'],
            ['objection_handling', 'Objection Handling'],
            ['crm_followup', 'CRM & Follow-up'],
            ['negotiation', 'Negotiation'],
            ['communication', 'Communication Skills'],
            ['confidence', 'Confidence Level'],
            ['custom', 'Custom Topic'],
        ];
    }
    if (str_contains($role, 'support') || str_contains($role, 'customer')) {
        return [
            ['customer_handling', 'Customer Handling'],
            ['issue_diagnosis', 'Issue Diagnosis'],
            ['communication', 'Communication Skills'],
            ['ticketing_process', 'Ticketing Process'],
            ['product_knowledge', 'Product Knowledge'],
            ['escalation_handling', 'Escalation Handling'],
            ['confidence', 'Confidence Level'],
            ['custom', 'Custom Topic'],
        ];
    }
    if (str_contains($role, 'ai') || str_contains($role, 'developer') || str_contains($role, 'software')) {
        return [
            ['programming_basics', 'Programming Basics'],
            ['technical_knowledge', 'Technical Knowledge'],
            ['api_db_integration', 'API & DB Integration'],
            ['problem_solving', 'Problem Solving'],
            ['project_experience', 'Project Experience'],
            ['communication', 'Communication Skills'],
            ['confidence', 'Confidence Level'],
            ['custom', 'Custom Topic'],
        ];
    }
    $base[] = ['custom', 'Custom Topic'];
    return $base;
}

function campaign_apply_link($campaign) {
    $token = $campaign['share_token'] ?? '';
    return BASE_URL . '/apply.php?' . ($token ? 'c=' . urlencode($token) : 'campaign_id=' . (int)$campaign['id']);
}

function campaign_setup_state($campaign, $questions, $application_fields) {
    $has_details = !empty($campaign['name']) && !empty($campaign['job_role']);
    $has_agent = !empty($campaign['el_agent_id']) && $campaign['el_agent_id'] !== 'PASTE_YOUR_EL_AGENT_ID';
    // Standard fields (name, email, phone, etc.) are always present by default,
    // so the apply form is ready even without custom extra fields.
    $std_cfg = $campaign['apply_form_config'] ?? null;
    $has_apply = count($application_fields) > 0
        || $std_cfg === null
        || count((array)json_decode((string)$std_cfg, true)) > 0;
    $has_questions = count($questions) > 0;
    $weight = array_sum(array_map('intval', array_column($questions, 'weight')));
    $has_scoring = $has_questions && $weight === 100;
    $has_integration = true;
    $steps = [
        ['label' => 'Campaign details', 'done' => $has_details],
        ['label' => 'AI voice agent (optional)', 'done' => true],
        ['label' => 'Apply form', 'done' => $has_apply],
        ['label' => 'Interview questions', 'done' => $has_questions],
        ['label' => 'Scoring weight 100%', 'done' => $has_scoring],
        ['label' => 'Activate campaign', 'done' => ($campaign['status'] ?? '') === 'active'],
    ];
    $done = count(array_filter($steps, fn($s) => $s['done']));
    return [
        'steps' => $steps,
        'done' => $done,
        'total' => count($steps),
        'percent' => (int)round(($done / max(1, count($steps))) * 100),
        'weight' => $weight,
        'remaining_weight' => max(0, 100 - $weight),
        'ready_to_preview' => $has_apply,
        'ready_to_activate' => $has_details && $has_questions && $has_scoring,
        'integration_pending' => false,
    ];
}

function campaign_duplicate_exists($org_id, $name, $job_role, $exclude_id = 0) {
    $sql = "SELECT id FROM campaigns WHERE org_id=? AND LOWER(TRIM(name))=LOWER(TRIM(?)) AND LOWER(TRIM(COALESCE(job_role,'')))=LOWER(TRIM(?))";
    $params = [(int)$org_id, trim((string)$name), trim((string)$job_role)];
    $types = 'iss';
    if ($exclude_id) {
        $sql .= " AND id<>?";
        $params[] = (int)$exclude_id;
        $types .= 'i';
    }
    return (bool)db_fetch_one($sql . " LIMIT 1", $params, $types);
}

function campaign_table_columns() {
    static $columns = null;
    if ($columns !== null) return $columns;
    $columns = [];
    foreach (db_fetch_all("SHOW COLUMNS FROM campaigns") as $row) {
        if (!empty($row['Field'])) $columns[$row['Field']] = true;
    }
    return $columns;
}

function campaign_insert_safe(array $values) {
    $available = campaign_table_columns();
    $columns = [];
    $params = [];
    $types = '';
    foreach ($values as $column => [$value, $type]) {
        if (!isset($available[$column])) continue;
        $columns[] = $column;
        $params[] = $value;
        $types .= $type;
    }
    if (empty($columns)) return false;
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $sql = "INSERT INTO campaigns (" . implode(',', $columns) . ") VALUES ($placeholders)";
    $id = db_insert($sql, $params, $types);
    if (!$id) {
        error_log('[campaigns] Campaign insert failed: ' . get_db()->error);
    }
    return $id;
}

function legacy_application_template_fields() {
    return [
        ['Salutation','salutation','dropdown','Select salutation','Personal Information', "Mr.\nMs.\nMrs.\nDr."],
        ['First Name','first_name','text','Enter first name','Personal Information', ''],
        ['Last Name','last_name','text','Enter last name','Personal Information', ''],
        ['Date of Birth','dob','date','','Personal Information', ''],
        ['Current City','city','text','Enter your current city','Personal Information', ''],
        ['Comfortable to Relocate?','relocate','dropdown','Select Option','Personal Information', "Yes\nNo"],
        ['Relocation Time','relocate_time','dropdown','Select Option','Show when relocation is yes.', "Immediate\nWithin 15 days\nWithin 1 month\nWithin 3 months\nMore than 3 months"],
        ['Phone Code','phone_code','dropdown','Select phone code','Personal Information', "+91 (India)\nOther"],
        ['Country Code','other_country_code','dropdown','Select country','Use when phone code is Other.', "+1 (USA / Canada)\n+44 (United Kingdom)\n+61 (Australia)\n+49 (Germany)\n+33 (France)\n+971 (UAE)\n+966 (Saudi Arabia)\n+65 (Singapore)\n+81 (Japan)\n+86 (China)\n+7 (Russia)\n+55 (Brazil)\n+27 (South Africa)\n+92 (Pakistan)\n+880 (Bangladesh)\n+94 (Sri Lanka)\n+977 (Nepal)\n+60 (Malaysia)\n+62 (Indonesia)\n+234 (Nigeria)"],
        ['Phone Number','phone','phone','10-digit number','Required for WhatsApp and interview outreach.', ''],
        ['Email ID','email','email','you@example.com','Personal Information', ''],
        ['College / University','college','dropdown','Select your institution','Personal Information', "University of Rajasthan\nJECRC University\nManipal University Jaipur\nAmity University Jaipur\nPoornima University\nIIS University\nMNIT Jaipur\nJaipur National University\nNIMS University\nArya College\nOther - specify"],
        ['Specify College / University','college_other','text','Enter the full name of your college or university','Use when College / University is Other.', ''],
        ['How did you hear about us?','source','dropdown','Select source','Personal Information', "Direct Website\nLinkedIn\nInternshala\nNaukri.com\nMonster.com\nDice.com\nIndeed.com\nWorkIndia\nOther - specify"],
        ['Please specify source','source_other','text','Where did you hear about this opportunity?','Use when source is Other.', ''],
        ['Role','role_applied','dropdown','Select Role','Role Selection', "AI\nSales\nPHP & Developer Engineer\nSupport Engineer"],
        ['Engagement Type','engagement_type','dropdown','Select Engagement Type','Role Selection', "Paid Training\nUnpaid Internship\nPaid Internship\nEmployment"],
        ['English Communication','english_level','dropdown','Select Level','General Experience & Skills', "1 - Basic\n2 - Fair\n3 - Good\n4 - Very Good\n5 - Fluent / Native"],
        ['Years of Experience','years_exp','dropdown','Select Experience','General Experience & Skills', "Fresher\n0.5 Years\n1-2 Years\n2-5 Years\n5-7 Years\n7-10 Years\n10-15 Years\n15+ Years"],
        ['Industry Background','industry','dropdown','Select Industry','General Experience & Skills', "Fresher / None\nIT / Software\nTelecom\nSales / Marketing\nCustomer Support\nOther"],
        ['Specify Industry','industry_other','text','Please specify other industry','Use when industry is Other.', ''],
        ['Experience Type','exp_type','dropdown','Select experience type','General Experience & Skills', "Fresher / None\nInternship\nFull-time\nFreelance\nAcademic Project"],
        ['Describe Your Past Experience','exp_desc','textarea','Briefly describe any relevant internship or project experience','Max 50 words.', ''],
        ['Current Salary / Stipend (Per Month)','current_salary','text','e.g. Rs. 15,000/month or N/A','Compensation', ''],
        ['Expected Salary / Stipend (Per Month)','expected_salary','text','Mention realistic figures in Rs.','Compensation', ''],
        ['Internship Tenure','tenure','dropdown','Select Tenure','Internship & Availability', "6 months\n9 months\n12 months"],
        ['Preferred Joining Date','joining_date','date','','Internship & Availability', ''],
        ['Open to Flexible Hours?','flex_hours','dropdown','Select Option','Internship & Availability', "Yes\nNo"],
        ['Do you own a Laptop?','laptop','dropdown','Select Option','Work Readiness', "Yes\nNo"],
        ['Reliable Broadband / Wi-Fi at Home?','internet','dropdown','Select Option','Work Readiness', "Yes\nNo"],
        ['Candidate Location','location','text','Enter your area/city, e.g. Vaishali Nagar, Jaipur','Optional commute utility.', ''],
        ['Commute to Office','commute','dropdown','Select Option','Work Readiness', "Personal vehicle\nSelf-managed"],
        ['Resume / CV','resume','file','Upload PDF or DOCX CV','Documents & Portfolio', ''],
        ['Photo','photo','file','Upload recent photo','Documents & Portfolio', ''],
        ['Video Introduction Preference','video_option','dropdown','Select video option','Documents & Portfolio', "Skip (Optional)\nProvide a Video Link\nUpload a Video File"],
        ['Video Introduction Link','video_link','url','https://...','Use when candidate provides video link.', ''],
        ['Video Introduction File','video_file','file','Upload video file','MP4, MOV or AVI.', ''],
        ['Portfolio / Project Links','portfolio','url','GitHub, LinkedIn, or personal website URL','Separate multiple URLs with a comma.', ''],
        ['Willing to Take the AI Test?','ai_test_willing','dropdown','Select Option','AI Test Section', "Yes\nNo"],
        ['Declaration Confirmation','declaration_confirmation','checkbox','Confirm declaration','Candidate confirms information is true and accurate.', "I confirm that the information provided is true and accurate."],
    ];
}

// ─── POST HANDLERS ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_die();
    if ($action === 'save') {
        $today = date('Y-m-d');
        $share_token = bin2hex(random_bytes(12));
        $name = trim((string)($_POST['name'] ?? ''));
        $job_role = trim((string)($_POST['job_role'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $start_date = trim($_POST['start_date'] ?? '') ?: null;
        $end_date = trim($_POST['end_date'] ?? '') ?: null;
        $passing_score = max(0, min(100, (int)($_POST['passing_score'] ?? 70)));
        $num_questions = max(1, min(20, (int)($_POST['num_questions'] ?? 6)));
        $language = $_POST['language'] ?? 'english';
        if (!in_array($language, ['english','hinglish','hindi'], true)) $language = 'english';
        if ($start_date && $start_date < $today) {
            header("Location: campaigns.php?action=new&msg=start_date_past"); exit;
        }
        if ($start_date && $end_date && $end_date < $start_date) {
            header("Location: campaigns.php?action=new&msg=end_before_start"); exit;
        }
        if ($name === '' || $job_role === '') {
            header("Location: campaigns.php?action=new&msg=required_missing"); exit;
        }
        if (campaign_duplicate_exists($user['org_id'], $name, $job_role)) {
            header("Location: campaigns.php?action=new&msg=duplicate_campaign"); exit;
        }
        $integration_type = $_POST['integration_type'] ?? 'none';
        if (!in_array($integration_type, ['none','crm','google_sheet'], true)) $integration_type = 'none';
        $id = campaign_insert_safe([
            'org_id' => [(int)$user['org_id'], 'i'],
            'created_by' => [(int)$user['user_id'], 'i'],
            'name' => [$name, 's'],
            'job_role' => [$job_role, 's'],
            'description' => [$description, 's'],
            'share_token' => [$share_token, 's'],
            'start_date' => [$start_date, 's'],
            'end_date' => [$end_date, 's'],
            'integration_type' => [$integration_type, 's'],
            'integration_endpoint' => [trim($_POST['integration_endpoint'] ?? ''), 's'],
            'el_agent_id' => [trim($_POST['el_agent_id'] ?? ''), 's'],
            'passing_score' => [$passing_score, 'i'],
            'num_questions' => [$num_questions, 'i'],
            'language' => [$language, 's'],
            'status' => ['draft', 's'],
        ]);
        if (!$id) {
            header("Location: campaigns.php?action=new&msg=create_failed"); exit;
        }
        audit_log($user['org_id'], $user['user_id'] ?? null, 'campaign', $id, 'campaign_created');
        header("Location: campaigns.php?action=questions&id=$id&msg=created"); exit;
    }
    if ($action === 'edit_save') {
        $today = date('Y-m-d');
        $start_date = trim($_POST['start_date'] ?? '') ?: null;
        $end_date = trim($_POST['end_date'] ?? '') ?: null;
        if ($start_date && $start_date < $today) {
            header("Location: campaigns.php?action=edit&id=$campaign_id&msg=start_date_past"); exit;
        }
        if ($start_date && $end_date && $end_date < $start_date) {
            header("Location: campaigns.php?action=edit&id=$campaign_id&msg=end_before_start"); exit;
        }
        if (campaign_duplicate_exists($user['org_id'], $_POST['name'] ?? '', $_POST['job_role'] ?? '', $campaign_id)) {
            header("Location: campaigns.php?action=edit&id=$campaign_id&msg=duplicate_campaign"); exit;
        }
        $integration_type = $_POST['integration_type'] ?? 'none';
        if (!in_array($integration_type, ['none','crm','google_sheet'], true)) $integration_type = 'none';
        db_execute(
            "UPDATE campaigns SET name=?,job_role=?,description=?,start_date=?,end_date=?,integration_type=?,integration_endpoint=?,el_agent_id=?,passing_score=?,num_questions=?,language=?,share_token=COALESCE(share_token, ?) WHERE id=? AND org_id=?",
            [$_POST['name'],$_POST['job_role'],$_POST['description'],$start_date,$end_date,$integration_type,trim($_POST['integration_endpoint'] ?? ''),trim($_POST['el_agent_id'] ?? ''),(int)$_POST['passing_score'],(int)$_POST['num_questions'],$_POST['language'],bin2hex(random_bytes(12)),$campaign_id,$user['org_id']],
            'ssssssssiissii'
        );
        audit_log($user['org_id'], $user['user_id'] ?? null, 'campaign', $campaign_id, 'campaign_updated');
        header("Location: campaigns.php?action=questions&id=$campaign_id&msg=updated"); exit;
    }
    if ($action === 'add_question') {
        $question_type = $_POST['question_type'] ?? 'textarea';
        $options_json = options_to_json($_POST['options_text'] ?? '');
        if (in_array($question_type, ['dropdown','multi_select','rating'], true) && !$options_json) {
            header("Location: campaigns.php?action=questions&id=$campaign_id&msg=options_required"); exit;
        }
        $branch_rules_json = normalize_json_text($_POST['branch_rules_json'] ?? '');
        $is_required = isset($_POST['is_required']) ? 1 : 0;
        $question_text = trim($_POST['question_text'] ?? '');
        $parameter_label = question_label_from_text($question_text, 'Question ' . (int)($_POST['order_no'] ?? 0));
        $parameter = field_key_from_label($parameter_label);
        db_insert(
            "INSERT INTO questions (campaign_id,parameter,parameter_label,weight,max_marks,question_text,ideal_answer_hint,question_type,options_json,branch_rules_json,is_required,order_no) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
            [$campaign_id,$parameter,$parameter_label,(int)$_POST['weight'],(int)$_POST['max_marks'],$question_text,$_POST['ideal_answer_hint'],$question_type,$options_json,$branch_rules_json,$is_required,(int)$_POST['order_no']],
            'issiisssssii'
        );
        audit_log($user['org_id'], $user['user_id'] ?? null, 'campaign', $campaign_id, 'question_added', ['type' => $question_type]);
        header("Location: campaigns.php?action=questions&id=$campaign_id&msg=question_added"); exit;
    }
    if ($action === 'edit_question') {
        $qid = (int)($_POST['question_id'] ?? 0);
        $question_type = $_POST['question_type'] ?? 'textarea';
        $options_json = options_to_json($_POST['options_text'] ?? '');
        if (in_array($question_type, ['dropdown','multi_select','rating'], true) && !$options_json) {
            header("Location: campaigns.php?action=questions&id=$campaign_id&edit_qid=$qid&msg=options_required"); exit;
        }
        $branch_rules_json = normalize_json_text($_POST['branch_rules_json'] ?? '');
        $is_required = isset($_POST['is_required']) ? 1 : 0;
        $question_text = trim($_POST['question_text'] ?? '');
        $parameter_label = question_label_from_text($question_text, 'Question ' . (int)($_POST['order_no'] ?? 0));
        $parameter = field_key_from_label($parameter_label);
        $campaign_exists = db_fetch_one("SELECT id FROM campaigns WHERE id=? AND org_id=?", [$campaign_id,$user['org_id']], 'ii');
        if ($campaign_exists && $qid) {
            db_execute(
                "UPDATE questions SET parameter=?,parameter_label=?,weight=?,max_marks=?,question_text=?,ideal_answer_hint=?,question_type=?,options_json=?,branch_rules_json=?,is_required=?,order_no=? WHERE id=? AND campaign_id=?",
                [$parameter,$parameter_label,(int)$_POST['weight'],(int)$_POST['max_marks'],$question_text,$_POST['ideal_answer_hint'],$question_type,$options_json,$branch_rules_json,$is_required,(int)$_POST['order_no'],$qid,$campaign_id],
                'ssiisssssiiii'
            );
            audit_log($user['org_id'], $user['user_id'] ?? null, 'campaign', $campaign_id, 'question_updated', ['question_id' => $qid, 'type' => $question_type]);
        }
        header("Location: campaigns.php?action=questions&id=$campaign_id&msg=question_updated"); exit;
    }
    if ($action === 'add_application_field') {
        $field_type = $_POST['field_type'] ?? 'text';
        $allowed = ['text','textarea','number','decimal','date','dropdown','multi_select','checkbox','email','phone','url','file'];
        if (!in_array($field_type, $allowed, true)) $field_type = 'text';
        $field_label = trim($_POST['field_label'] ?? '');
        $field_key = field_key_from_label($_POST['field_key'] ?? $field_label);
        $options_json = options_to_json($_POST['options_text'] ?? '');
        $is_required = isset($_POST['is_required']) ? 1 : 0;
        $campaign_exists = db_fetch_one("SELECT id FROM campaigns WHERE id=? AND org_id=?", [$campaign_id,$user['org_id']], 'ii');
        if (!$campaign_exists || $field_label === '') {
            header("Location: campaigns.php?action=apply_form&id=$campaign_id&msg=field_error"); exit;
        }
        db_insert(
            "INSERT INTO application_fields (campaign_id,field_key,field_label,field_type,placeholder,help_text,options_json,is_required,order_no,is_active) VALUES (?,?,?,?,?,?,?,?,?,1)",
            [$campaign_id,$field_key,$field_label,$field_type,trim($_POST['placeholder'] ?? ''),trim($_POST['help_text'] ?? ''),$options_json,$is_required,(int)($_POST['order_no'] ?? 1)],
            'issssssii'
        );
        audit_log($user['org_id'], $user['user_id'] ?? null, 'campaign', $campaign_id, 'application_field_added', ['label' => $field_label, 'type' => $field_type]);
        header("Location: campaigns.php?action=apply_form&id=$campaign_id&msg=field_added"); exit;
    }
    if ($action === 'add_application_template') {
        $campaign_exists = db_fetch_one("SELECT id FROM campaigns WHERE id=? AND org_id=?", [$campaign_id,$user['org_id']], 'ii');
        if (!$campaign_exists) {
            header("Location: campaigns.php?action=apply_form&id=$campaign_id&msg=template_error"); exit;
        }
        $existing_count = (int)((db_fetch_one("SELECT COUNT(*) c FROM application_fields WHERE campaign_id=? AND is_active=1", [$campaign_id], 'i') ?: ['c'=>0])['c']);
        $added = 0;
        $optional_keys = ['relocate','relocate_time','other_country_code','college_other','source_other','industry_other','exp_desc','current_salary','location','video_option','video_link','video_file','portfolio'];
        foreach (legacy_application_template_fields() as $idx => $field) {
            [$label,$key,$type,$placeholder,$help,$options] = $field;
            $exists = db_fetch_one("SELECT id FROM application_fields WHERE campaign_id=? AND field_key=? AND is_active=1", [$campaign_id,$key], 'is');
            if ($exists) continue;
            $required = in_array($key, $optional_keys, true) ? 0 : 1;
            db_insert(
                "INSERT INTO application_fields (campaign_id,field_key,field_label,field_type,placeholder,help_text,options_json,is_required,order_no,is_active) VALUES (?,?,?,?,?,?,?,?,?,1)",
                [$campaign_id,$key,$label,$type,$placeholder,$help,options_to_json($options),$required,$existing_count + $idx + 1],
                'issssssii'
            );
            $added++;
        }
        audit_log($user['org_id'], $user['user_id'] ?? null, 'campaign', $campaign_id, 'application_template_added', ['added' => $added]);
        header("Location: campaigns.php?action=apply_form&id=$campaign_id&msg=template_added_$added"); exit;
    }
    if ($action === 'save_apply_form_config') {
        $campaign_exists = db_fetch_one("SELECT id FROM campaigns WHERE id=? AND org_id=?", [$campaign_id,$user['org_id']], 'ii');
        if (!$campaign_exists) { header("Location: campaigns.php?action=apply_form&id=$campaign_id&msg=config_error"); exit; }
        // Ensure column exists (safe to run multiple times on MySQL)
        @get_db()->query("ALTER TABLE campaigns ADD COLUMN IF NOT EXISTS apply_form_config JSON NULL DEFAULT NULL");
        $enabled_keys = array_map('strtolower', array_values(array_filter(array_map('trim', (array)($_POST['std_fields'] ?? [])))));
        // Save enabled keys as JSON in campaigns row — no application_fields rows for std fields
        db_execute("UPDATE campaigns SET apply_form_config=? WHERE id=?", [json_encode($enabled_keys), $campaign_id], 'si');
        // Clean up any standard fields previously added to application_fields by old approach
        foreach (array_column(legacy_application_template_fields(), 1) as $sk) {
            db_execute("DELETE FROM application_fields WHERE campaign_id=? AND field_key=?", [$campaign_id, $sk], 'is');
        }
        audit_log($user['org_id'], $user['user_id'] ?? null, 'campaign', $campaign_id, 'apply_form_config_saved', ['enabled'=>count($enabled_keys)]);
        header("Location: campaigns.php?action=apply_form&id=$campaign_id&msg=config_saved"); exit;
    }
    if ($action === 'bulk_delete_application_fields') {
        $field_ids = array_values(array_filter(array_map('intval', $_POST['field_ids'] ?? [])));
        $campaign_exists = db_fetch_one("SELECT id FROM campaigns WHERE id=? AND org_id=?", [$campaign_id,$user['org_id']], 'ii');
        if ($campaign_exists && $field_ids) {
            foreach ($field_ids as $fid) {
                db_execute("UPDATE application_fields SET is_active=0 WHERE id=? AND campaign_id=?", [$fid,$campaign_id], 'ii');
            }
            audit_log($user['org_id'], $user['user_id'] ?? null, 'campaign', $campaign_id, 'application_fields_bulk_deleted', ['field_ids' => $field_ids]);
        }
        header("Location: campaigns.php?action=apply_form&id=$campaign_id&msg=fields_deleted_" . count($field_ids)); exit;
    }
    if ($action === 'activate') {
        $camp = db_fetch_one("SELECT * FROM campaigns WHERE id=? AND org_id=?", [$campaign_id,$user['org_id']], 'ii');
        $qs = db_fetch_all("SELECT weight FROM questions WHERE campaign_id=?", [$campaign_id], 'i');
        $fields = db_fetch_all("SELECT id FROM application_fields WHERE campaign_id=? AND is_active=1", [$campaign_id], 'i');
        $state = campaign_setup_state($camp ?: [], $qs, $fields);
        if (!$state['ready_to_activate']) {
            header("Location: campaigns.php?action=questions&id=$campaign_id&msg=setup_incomplete"); exit;
        }
        db_execute("UPDATE campaigns SET status='active', share_token=COALESCE(share_token, ?) WHERE id=? AND org_id=?", [bin2hex(random_bytes(12)),$campaign_id,$user['org_id']], 'sii');
        audit_log($user['org_id'], $user['user_id'] ?? null, 'campaign', $campaign_id, 'campaign_activated');
        header("Location: campaigns.php?msg=activated"); exit;
    }

    if ($action === 'deactivate') {
        db_execute("UPDATE campaigns SET status='paused' WHERE id=? AND org_id=? AND status='active'", [$campaign_id,$user['org_id']], 'ii');
        audit_log($user['org_id'], $user['user_id'] ?? null, 'campaign', $campaign_id, 'campaign_deactivated');
        header("Location: campaigns.php?msg=deactivated"); exit;
    }
}

if ($action === 'delete_question' && $campaign_id) {
    $sent = $_GET['csrf_token'] ?? '';
    if (!$sent || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Invalid security token. Please refresh and try again.');
    }
    $qid = (int)$_GET['qid'];
    db_execute("DELETE FROM questions WHERE id=? AND campaign_id=?", [$qid,$campaign_id], 'ii');
    header("Location: campaigns.php?action=questions&id=$campaign_id"); exit;
}

if ($action === 'delete_application_field' && $campaign_id) {
    $sent = $_GET['csrf_token'] ?? '';
    if (!$sent || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Invalid security token. Please refresh and try again.');
    }
    $fid = (int)($_GET['fid'] ?? 0);
    $campaign_exists = db_fetch_one("SELECT id FROM campaigns WHERE id=? AND org_id=?", [$campaign_id,$user['org_id']], 'ii');
    if ($campaign_exists) {
        db_execute("UPDATE application_fields SET is_active=0 WHERE id=? AND campaign_id=?", [$fid,$campaign_id], 'ii');
        audit_log($user['org_id'], $user['user_id'] ?? null, 'campaign', $campaign_id, 'application_field_deleted', ['field_id' => $fid]);
    }
    header("Location: campaigns.php?action=apply_form&id=$campaign_id"); exit;
}

if ($action === 'clone_campaign' && $campaign_id) {
    $sent = $_GET['csrf_token'] ?? '';
    if (!$sent || !hash_equals(csrf_token(), $sent)) { http_response_code(419); exit('Invalid security token.'); }
    $src = db_fetch_one("SELECT * FROM campaigns WHERE id=? AND org_id=?", [$campaign_id, $user['org_id']], 'ii');
    if ($src) {
        $new_id = db_insert(
            "INSERT INTO campaigns (org_id,created_by,name,job_role,description,share_token,start_date,end_date,integration_type,integration_endpoint,el_agent_id,passing_score,num_questions,language,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'draft')",
            [$user['org_id'],$user['user_id'],'Copy of '.$src['name'],$src['job_role'],$src['description'],bin2hex(random_bytes(12)),null,null,$src['integration_type'],$src['integration_endpoint'],$src['el_agent_id'],(int)$src['passing_score'],(int)$src['num_questions'],$src['language']],
            'iisssssssssiis'
        );
        foreach (db_fetch_all("SELECT * FROM questions WHERE campaign_id=? ORDER BY order_no", [$campaign_id], 'i') as $q) {
            db_insert("INSERT INTO questions (campaign_id,parameter,parameter_label,weight,max_marks,question_text,ideal_answer_hint,question_type,options_json,branch_rules_json,is_required,order_no) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
                [$new_id,$q['parameter'],$q['parameter_label'],(int)$q['weight'],(int)$q['max_marks'],$q['question_text'],$q['ideal_answer_hint'],$q['question_type'],$q['options_json'],$q['branch_rules_json'],(int)$q['is_required'],(int)$q['order_no']],'issiisssssii');
        }
        foreach (db_fetch_all("SELECT * FROM application_fields WHERE campaign_id=? AND is_active=1 ORDER BY order_no", [$campaign_id], 'i') as $f) {
            db_insert("INSERT INTO application_fields (campaign_id,field_key,field_label,field_type,placeholder,help_text,options_json,is_required,order_no,is_active) VALUES (?,?,?,?,?,?,?,?,?,1)",
                [$new_id,$f['field_key'],$f['field_label'],$f['field_type'],$f['placeholder'],$f['help_text'],$f['options_json'],(int)$f['is_required'],(int)$f['order_no']],'issssssii');
        }
        audit_log($user['org_id'], $user['user_id'] ?? null, 'campaign', $new_id, 'campaign_cloned', ['source_id' => $campaign_id]);
        header("Location: campaigns.php?action=questions&id=$new_id&msg=cloned"); exit;
    }
    header("Location: campaigns.php?msg=clone_failed"); exit;
}

if ($action === 'delete_campaign' && $campaign_id) {
    $sent = $_GET['csrf_token'] ?? '';
    if (!$sent || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Invalid security token. Please refresh and try again.');
    }
    $campaign = db_fetch_one("SELECT id,name FROM campaigns WHERE id=? AND org_id=?", [$campaign_id,$user['org_id']], 'ii');
    if ($campaign) {
        $candidate_ids = array_map('intval', array_column(db_fetch_all("SELECT id FROM candidates WHERE campaign_id=? AND org_id=?", [$campaign_id,$user['org_id']], 'ii'), 'id'));
        foreach ($candidate_ids as $cid) {
            db_execute("DELETE FROM interview_answers WHERE candidate_id=?", [$cid], 'i');
            db_execute("DELETE FROM interview_sessions WHERE candidate_id=?", [$cid], 'i');
            db_execute("DELETE FROM interview_results WHERE candidate_id=?", [$cid], 'i');
            db_execute("DELETE FROM scores WHERE candidate_id=?", [$cid], 'i');
            db_execute("DELETE FROM outreach_log WHERE candidate_id=?", [$cid], 'i');
            db_execute("DELETE FROM reminder_jobs WHERE candidate_id=?", [$cid], 'i');
            db_execute("DELETE FROM recruiter_notes WHERE candidate_id=?", [$cid], 'i');
        }
        db_execute("DELETE FROM candidates WHERE campaign_id=? AND org_id=?", [$campaign_id,$user['org_id']], 'ii');
        db_execute("DELETE FROM application_fields WHERE campaign_id=?", [$campaign_id], 'i');
        db_execute("DELETE FROM questions WHERE campaign_id=?", [$campaign_id], 'i');
        audit_log($user['org_id'], $user['user_id'] ?? null, 'campaign', $campaign_id, 'campaign_deleted', ['name' => $campaign['name']]);
        db_execute("DELETE FROM campaigns WHERE id=? AND org_id=?", [$campaign_id,$user['org_id']], 'ii');
    }
    header("Location: campaigns.php?msg=deleted"); exit;
}

// ── BULK DELETE CAMPAIGNS ────────────────────────────────────────────────────
if ($action === 'bulk_delete_campaigns') {
    $sent = $_POST['csrf_token'] ?? '';
    if (!$sent || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419); exit('Invalid security token.');
    }
    $ids = array_map('intval', explode(',', $_POST['campaign_ids'] ?? ''));
    $ids = array_filter($ids);
    $deleted = 0;
    foreach ($ids as $cid) {
        $camp = db_fetch_one("SELECT id,name FROM campaigns WHERE id=? AND org_id=?", [$cid,$user['org_id']], 'ii');
        if (!$camp) continue;
        $candidate_ids = array_map('intval', array_column(db_fetch_all("SELECT id FROM candidates WHERE campaign_id=? AND org_id=?", [$cid,$user['org_id']], 'ii'), 'id'));
        foreach ($candidate_ids as $candid) {
            db_execute("DELETE FROM interview_answers WHERE candidate_id=?", [$candid], 'i');
            db_execute("DELETE FROM interview_sessions WHERE candidate_id=?", [$candid], 'i');
            db_execute("DELETE FROM interview_results WHERE candidate_id=?", [$candid], 'i');
            db_execute("DELETE FROM scores WHERE candidate_id=?", [$candid], 'i');
            db_execute("DELETE FROM outreach_log WHERE candidate_id=?", [$candid], 'i');
            db_execute("DELETE FROM reminder_jobs WHERE candidate_id=?", [$candid], 'i');
            db_execute("DELETE FROM recruiter_notes WHERE candidate_id=?", [$candid], 'i');
        }
        db_execute("DELETE FROM candidates WHERE campaign_id=? AND org_id=?", [$cid,$user['org_id']], 'ii');
        db_execute("DELETE FROM application_fields WHERE campaign_id=?", [$cid], 'i');
        db_execute("DELETE FROM questions WHERE campaign_id=?", [$cid], 'i');
        audit_log($user['org_id'], $user['user_id'] ?? null, 'campaign', $cid, 'campaign_deleted', ['name' => $camp['name']]);
        db_execute("DELETE FROM campaigns WHERE id=? AND org_id=?", [$cid,$user['org_id']], 'ii');
        $deleted++;
    }
    header("Location: campaigns.php?msg=bulk_deleted_$deleted"); exit;
}

// ─── DATA ────────────────────────────────────────────────────────
$campaign_list_page = pagination_page('campaign_page');
$campaign_list_per_page = pagination_per_page('campaign_per_page', 10);
$campaign_total_row = db_fetch_one("SELECT COUNT(*) cnt FROM campaigns WHERE org_id=?", [$user['org_id']], 'i');
$campaign_total = (int)($campaign_total_row['cnt'] ?? 0);
$campaign_total_pages = max(1, (int)ceil($campaign_total / $campaign_list_per_page));
$campaign_list_page = min($campaign_list_page, $campaign_total_pages);
$campaign_list_offset = ($campaign_list_page - 1) * $campaign_list_per_page;
$campaigns = db_fetch_all(
    "SELECT ca.*, u.name AS creator_name,
            COUNT(DISTINCT c.id) as total_cands,
            COUNT(DISTINCT af.id) as apply_field_count,
            COUNT(DISTINCT q.id) as question_count
     FROM campaigns ca
     LEFT JOIN users u ON ca.created_by=u.id
     LEFT JOIN candidates c ON ca.id=c.campaign_id
     LEFT JOIN application_fields af ON af.campaign_id=ca.id AND af.is_active=1
     LEFT JOIN questions q ON q.campaign_id=ca.id
     WHERE ca.org_id=?
     GROUP BY ca.id
     ORDER BY ca.created_at DESC
     LIMIT ? OFFSET ?",
    [$user['org_id'], $campaign_list_per_page, $campaign_list_offset], 'iii'
);
$campaign  = $campaign_id ? db_fetch_one("SELECT ca.*, u.name AS creator_name, u.email AS creator_email FROM campaigns ca LEFT JOIN users u ON ca.created_by=u.id WHERE ca.id=? AND ca.org_id=?", [$campaign_id,$user['org_id']], 'ii') : null;
$questions = $campaign_id ? db_fetch_all("SELECT * FROM questions WHERE campaign_id=? ORDER BY order_no", [$campaign_id], 'i') : [];
$application_fields = $campaign_id ? db_fetch_all("SELECT * FROM application_fields WHERE campaign_id=? AND is_active=1 ORDER BY order_no,id", [$campaign_id], 'i') : [];
$question_page = pagination_page('question_page');
$question_per_page = pagination_per_page('question_per_page', 10);
$question_total = count($questions);
$question_total_pages = max(1, (int)ceil($question_total / $question_per_page));
$question_page = min($question_page, $question_total_pages);
$question_page_rows = array_slice($questions, ($question_page - 1) * $question_per_page, $question_per_page);
$field_page = pagination_page('field_page');
$field_per_page = pagination_per_page('field_per_page', 10);
$field_total = count($application_fields);
$field_total_pages = max(1, (int)ceil($field_total / $field_per_page));
$field_page = min($field_page, $field_total_pages);
$application_field_page_rows = array_slice($application_fields, ($field_page - 1) * $field_per_page, $field_per_page);
$setup_state = $campaign ? campaign_setup_state($campaign, $questions, $application_fields) : null;
$edit_qid = (int)($_GET['edit_qid'] ?? 0);
$editing_question = $edit_qid ? db_fetch_one("SELECT * FROM questions WHERE id=? AND campaign_id=?", [$edit_qid,$campaign_id], 'ii') : null;
$editing_options_text = '';
if ($editing_question && !empty($editing_question['options_json'])) {
    $decoded_options = json_decode($editing_question['options_json'], true);
    if (is_array($decoded_options)) $editing_options_text = implode("\n", $decoded_options);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Campaigns — HireAI</title>
<?php include __DIR__ . '/includes/head.php'; ?>
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>
<div class="main-content">

<?php if ($action === 'list'): ?>
<style>
/* ── Campaign List — Unicorn Edition ─────────────────────── */
:root{--cl-border:#E8ECF0;--cl-bg:#F8FAFC;--cl-txt:#0F172A;--cl-muted:#64748B;--cl-hover:#F1F5F9}
.cl-wrap{max-width:1100px}
/* Top bar */
.cl-topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px;flex-wrap:wrap}
.cl-topbar-left h2{font-size:21px;font-weight:800;letter-spacing:-.4px;color:var(--cl-txt);margin:0;display:flex;align-items:center;gap:8px}
.cl-topbar-left h2 .cl-total{font-size:12px;font-weight:700;background:var(--cl-border);color:var(--cl-muted);border-radius:99px;padding:2px 9px;letter-spacing:0}
.cl-topbar-right{display:flex;gap:8px}
.cl-btn-jd{display:inline-flex;align-items:center;gap:7px;padding:8px 15px;background:linear-gradient(135deg,#6D28D9,#2563EB);color:#fff;border:none;border-radius:9px;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;transition:opacity .15s;letter-spacing:-.1px}
.cl-btn-jd:hover{opacity:.88}
.cl-btn-new{display:inline-flex;align-items:center;gap:7px;padding:8px 15px;background:#0F172A;color:#fff;border:none;border-radius:9px;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;transition:background .15s}
.cl-btn-new:hover{background:#1E293B}
/* Kpi row */
.cl-kpi{display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap}
.cl-kpi-item{background:#fff;border:1px solid var(--cl-border);border-radius:10px;padding:10px 16px;display:flex;align-items:center;gap:9px;min-width:100px}
.cl-kpi-icon{width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0}
.cl-kpi-val{font-size:17px;font-weight:800;color:var(--cl-txt);line-height:1;letter-spacing:-.3px}
.cl-kpi-lbl{font-size:10px;color:var(--cl-muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-top:1px}
/* Toolbar */
.cl-toolbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;gap:8px}
.cl-toolbar-left{display:flex;align-items:center;gap:10px;font-size:12px;color:var(--cl-muted)}
.cl-sel-all{display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:600;user-select:none}
.cl-sel-all input{width:14px;height:14px;accent-color:#7C3AED;cursor:pointer}
/* List container */
.cl-list{background:#fff;border:1px solid var(--cl-border);border-radius:12px;overflow:hidden}
/* Row */
.cl-row{display:grid;grid-template-columns:36px 1fr auto;align-items:center;border-bottom:1px solid var(--cl-border);transition:background .12s;position:relative}
.cl-row:last-child{border-bottom:none}
.cl-row:hover{background:var(--cl-hover)}
.cl-row-check{display:flex;align-items:center;justify-content:center;padding:0 0 0 14px}
.cl-row-check input{width:14px;height:14px;accent-color:#7C3AED;cursor:pointer}
/* Status stripe */
.cl-row::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;border-radius:0}
.cl-row[data-status="active"]::before{background:#10B981}
.cl-row[data-status="draft"]::before{background:#CBD5E1}
.cl-row[data-status="paused"]::before{background:#F59E0B}
.cl-row[data-status="completed"]::before{background:#6366F1}
/* Row body */
.cl-row-body{padding:11px 14px 11px 10px;min-width:0}
.cl-row-main{display:flex;align-items:center;gap:8px;margin-bottom:5px;flex-wrap:wrap}
.cl-s-pill{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:99px;font-size:10px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;flex-shrink:0;line-height:1.4}
.cl-s-pill.active{background:#DCFCE7;color:#15803D}
.cl-s-pill.draft{background:#F1F5F9;color:#64748B}
.cl-s-pill.paused{background:#FEF3C7;color:#B45309}
.cl-s-pill.completed{background:#EDE9FE;color:#6D28D9}
.cl-s-dot{width:5px;height:5px;border-radius:50%;background:currentColor;flex-shrink:0}
.cl-name{font-size:14px;font-weight:700;color:var(--cl-txt);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:420px;letter-spacing:-.2px}
.cl-row-sub{display:flex;align-items:center;gap:5px;font-size:11.5px;color:var(--cl-muted);flex-wrap:wrap}
.cl-row-sub .sep{color:#CBD5E1}
/* Inline stats */
.cl-row-stats{display:flex;align-items:center;gap:0;margin-top:7px;border:1px solid var(--cl-border);border-radius:7px;width:fit-content;overflow:hidden}
.cl-rs{padding:4px 12px;border-right:1px solid var(--cl-border);text-align:center}
.cl-rs:last-child{border-right:none}
.cl-rs-v{font-size:13px;font-weight:800;color:var(--cl-txt);letter-spacing:-.3px;display:block}
.cl-rs-l{font-size:9px;font-weight:700;color:#94A3B8;text-transform:uppercase;letter-spacing:.5px;display:block}
/* Agent chip */
.cl-agent{display:inline-flex;align-items:center;gap:5px;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:6px;padding:3px 8px;font-size:10.5px;color:#1D4ED8;font-weight:700;font-family:ui-monospace,monospace;margin-top:6px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cl-agent-none{display:inline-flex;align-items:center;gap:5px;background:#FFF1F2;border:1px solid #FECDD3;border-radius:6px;padding:3px 8px;font-size:10.5px;color:#BE123C;font-weight:700;margin-top:6px}
/* Action panel */
.cl-row-actions{display:flex;flex-direction:column;gap:5px;padding:10px 14px 10px 8px;align-items:flex-end;min-width:260px}
.cl-arow{display:flex;gap:4px;align-items:center}
.ca{display:inline-flex;align-items:center;gap:4px;padding:5px 9px;border-radius:7px;font-size:11.5px;font-weight:700;border:1px solid var(--cl-border);background:#fff;color:#374151;text-decoration:none;cursor:pointer;transition:all .12s;white-space:nowrap;line-height:1;letter-spacing:-.1px}
.ca:hover{border-color:#7C3AED;color:#6D28D9;background:#F5F3FF}
.ca.green{border-color:#BBF7D0;color:#15803D;background:#F0FDF4}.ca.green:hover{border-color:#16A34A;background:#DCFCE7}
.ca.amber{border-color:#FDE68A;color:#92400E;background:#FFFBEB}.ca.amber:hover{border-color:#F59E0B;background:#FEF3C7}
.ca.purple{border-color:#DDD6FE;color:#6D28D9;background:#F5F3FF}.ca.purple:hover{border-color:#7C3AED;background:#EDE9FE}
.ca.red{border-color:#FECACA;color:#DC2626;background:#FFF5F5}.ca.red:hover{border-color:#DC2626;background:#FEE2E2}
.ca.activate{border-color:#A7F3D0;color:#065F46;background:#ECFDF5}.ca.activate:hover{border-color:#10B981;background:#D1FAE5}
.ca.deactivate{border-color:#FDE68A;color:#92400E;background:#FFFBEB}.ca.deactivate:hover{border-color:#F59E0B;background:#FEF3C7}
.ca-badge{background:#7C3AED;color:#fff;border-radius:5px;padding:0px 5px;font-size:9px;font-weight:800;line-height:16px}
/* Empty */
.cl-empty{padding:48px 24px;text-align:center;color:#94A3B8}
/* Bulk bar */
.cl-bulk{display:none;position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#0F172A;color:#fff;padding:11px 20px;border-radius:12px;box-shadow:0 8px 28px rgba(0,0,0,.22);align-items:center;gap:12px;z-index:999;font-size:13px;font-weight:700}
@media(max-width:860px){.cl-name{max-width:240px}.cl-row-actions{display:none}.cl-kpi{gap:6px}.cl-kpi-item{min-width:80px;padding:8px 12px}}
</style>
<?php
  $stat_total  = $campaign_total;
  $stat_active = (int)(db_fetch_one("SELECT COUNT(*) cnt FROM campaigns WHERE org_id=? AND status='active'", [$user['org_id']], 'i')['cnt'] ?? 0);
  $stat_leads  = (int)(db_fetch_one("SELECT COUNT(*) cnt FROM candidates WHERE org_id=?", [$user['org_id']], 'i')['cnt'] ?? 0);
?>

<div class="cl-topbar">
  <div class="cl-topbar-left">
    <h2>Campaigns <span class="cl-total"><?= $stat_total ?></span></h2>
  </div>
  <?php if ($can_manage_campaigns): ?>
  <div class="cl-topbar-right">
    <a href="/jd_builder" class="cl-btn-jd"><i class="fa-solid fa-wand-magic-sparkles fa-xs"></i> Create from JD</a>
    <a href="campaigns.php?action=new" class="cl-btn-new"><i class="fa-solid fa-plus fa-xs"></i> New Campaign</a>
  </div>
  <?php endif; ?>
</div>

<?php if (($_GET['msg'] ?? '') === 'admin_campaigns_only'): ?>
  <div class="alert alert-info" style="margin-bottom:14px"><i class="fa-solid fa-circle-info"></i> Campaign creation and changes are available for Admin and Super Admin users.</div>
<?php elseif (!empty($_GET['msg'])): ?>
  <div class="alert alert-success" style="margin-bottom:14px">✅ <?= htmlspecialchars(str_replace('_',' ',$_GET['msg'])) ?>!</div>
<?php endif; ?>

<div class="cl-kpi">
  <div class="cl-kpi-item">
    <div class="cl-kpi-icon" style="background:#EFF6FF;color:#2563EB"><i class="fa-solid fa-bullhorn fa-xs"></i></div>
    <div><div class="cl-kpi-val"><?= $stat_total ?></div><div class="cl-kpi-lbl">Total</div></div>
  </div>
  <div class="cl-kpi-item">
    <div class="cl-kpi-icon" style="background:#F0FDF4;color:#16A34A"><i class="fa-solid fa-circle-play fa-xs"></i></div>
    <div><div class="cl-kpi-val"><?= $stat_active ?></div><div class="cl-kpi-lbl">Active</div></div>
  </div>
  <div class="cl-kpi-item">
    <div class="cl-kpi-icon" style="background:#F5F3FF;color:#7C3AED"><i class="fa-solid fa-users fa-xs"></i></div>
    <div><div class="cl-kpi-val"><?= $stat_leads ?></div><div class="cl-kpi-lbl">Leads</div></div>
  </div>
</div>

<div class="cl-toolbar">
  <div class="cl-toolbar-left">
    <?php if ($can_manage_campaigns): ?>
    <label class="cl-sel-all"><input type="checkbox" id="select-all-camps"> Select all</label>
    <?php endif; ?>
    <span>Show <?= pagination_per_page_select('campaign_per_page', 'campaign_page', $campaign_list_per_page) ?> campaigns</span>
  </div>
</div>

<div class="cl-list">
<?php if (empty($campaigns)): ?>
  <div class="cl-empty">
    <div style="font-size:32px;margin-bottom:10px;opacity:.35"><i class="fa-solid fa-folder-open"></i></div>
    <div style="font-size:15px;font-weight:700;color:#334155;margin-bottom:4px">No campaigns yet</div>
    <p style="font-size:12px;margin-bottom:14px">Create your first campaign to start hiring.</p>
    <?php if ($can_manage_campaigns): ?>
    <a href="campaigns.php?action=new" class="cl-btn-new" style="display:inline-flex"><i class="fa-solid fa-plus fa-xs"></i> New Campaign</a>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php foreach ($campaigns as $c):
  $applyLink  = campaign_apply_link($c);
  $hasForm    = (int)$c['apply_field_count'] > 0;
  $st         = $c['status'];
  $agentShort = $c['el_agent_id'] ? (strlen($c['el_agent_id']) > 24 ? substr($c['el_agent_id'],0,24).'…' : $c['el_agent_id']) : null;
  $cands      = (int)$c['total_cands'];
?>
<div class="cl-row" data-status="<?= htmlspecialchars($st) ?>">
  <?php if ($can_manage_campaigns): ?>
  <div class="cl-row-check"><input type="checkbox" class="camp-chk" value="<?= $c['id'] ?>"></div>
  <?php endif; ?>

  <div class="cl-row-body">
    <div class="cl-row-main">
      <span class="cl-s-pill <?= htmlspecialchars($st) ?>"><span class="cl-s-dot"></span><?= ucfirst($st) ?></span>
      <span class="cl-name"><?= htmlspecialchars($c['name']) ?></span>
    </div>
    <div class="cl-row-sub">
      <i class="fa-solid fa-briefcase fa-xs"></i><span><?= htmlspecialchars($c['job_role']) ?></span>
      <span class="sep">·</span>
      <i class="fa-solid fa-user fa-xs"></i><span><?= htmlspecialchars($c['creator_name'] ?: 'Unknown') ?></span>
      <span class="sep">·</span>
      <i class="fa-regular fa-calendar fa-xs"></i><span><?= date('d M Y', strtotime($c['created_at'])) ?></span>
      <?php if ($agentShort): ?>
      <span class="sep">·</span><span class="cl-agent"><i class="fa-solid fa-robot fa-xs"></i><?= htmlspecialchars($agentShort) ?></span>
      <?php else: ?><span class="sep">·</span><span class="cl-agent-none"><i class="fa-solid fa-robot fa-xs"></i>No agent</span><?php endif; ?>
    </div>
    <div class="cl-row-stats">
      <div class="cl-rs"><span class="cl-rs-v"><?= $cands ?></span><span class="cl-rs-l">Leads</span></div>
      <div class="cl-rs"><span class="cl-rs-v"><?= (int)$c['passing_score'] ?></span><span class="cl-rs-l">Pass</span></div>
      <div class="cl-rs"><span class="cl-rs-v"><?= (int)($c['question_count'] ?? 0) ?></span><span class="cl-rs-l">Questions</span></div>
    </div>
  </div>

  <div class="cl-row-actions">
    <div class="cl-arow">
      <?php if ($can_manage_campaigns): ?>
      <a href="campaigns.php?action=edit&id=<?= $c['id'] ?>" class="ca"><i class="fa-solid fa-pen-to-square fa-xs"></i> Edit</a>
      <?php endif; ?>
      <a href="campaigns.php?action=questions&id=<?= $c['id'] ?>" class="ca"><i class="fa-solid fa-microphone-lines fa-xs"></i> Questions</a>
      <a href="campaigns.php?action=apply_form&id=<?= $c['id'] ?>" class="ca <?= !$hasForm ? 'amber' : '' ?>">
        <i class="fa-solid fa-<?= $hasForm ? 'wpforms' : 'triangle-exclamation' ?> fa-xs"></i><?= $hasForm ? 'Form' : 'Form ⚠' ?>
      </a>
      <a href="candidates.php?campaign_id=<?= $c['id'] ?>" class="ca purple">
        <i class="fa-solid fa-users fa-xs"></i> Leads<?= $cands ? ' <span class="ca-badge">'.$cands.'</span>' : '' ?>
      </a>
    </div>
    <div class="cl-arow">
      <?php if ($hasForm): ?>
      <button type="button" class="ca green" onclick="copyCampaignLink(<?= htmlspecialchars(json_encode($applyLink),ENT_QUOTES,'UTF-8') ?>)"><i class="fa-solid fa-link fa-xs"></i> Copy Link</button>
      <a href="https://wa.me/?text=<?= urlencode('Apply here: '.$applyLink) ?>" target="_blank" rel="noopener" class="ca green"><i class="fa-brands fa-whatsapp fa-xs"></i> Share</a>
      <?php endif; ?>
      <?php if ($can_manage_campaigns && $st !== 'active'): ?>
      <form style="display:inline;margin:0" method="POST" action="/campaigns?action=activate&id=<?= $c['id'] ?>">
        <?= csrf_input() ?>
        <button type="submit" class="ca activate"><i class="fa-solid fa-play fa-xs"></i> Activate</button>
      </form>
      <?php endif; ?>
      <?php if ($can_manage_campaigns && $st === 'active'): ?>
      <form style="display:inline;margin:0" method="POST" action="/campaigns?action=deactivate&id=<?= $c['id'] ?>" onsubmit="return confirm('Deactivate this campaign? The apply link will stop accepting new applications.')">
        <?= csrf_input() ?>
        <button type="submit" class="ca deactivate"><i class="fa-solid fa-pause fa-xs"></i> Deactivate</button>
      </form>
      <?php endif; ?>
      <?php if ($can_manage_campaigns): ?>
      <a href="campaigns.php?action=clone_campaign&id=<?= $c['id'] ?>&csrf_token=<?= urlencode(csrf_token()) ?>" class="ca" onclick="return confirm('Clone this campaign?')"><i class="fa-regular fa-copy fa-xs"></i> Clone</a>
      <a href="campaigns.php?action=delete_campaign&id=<?= $c['id'] ?>&csrf_token=<?= urlencode(csrf_token()) ?>" class="ca red" onclick="return confirm('Delete this campaign and ALL data? Cannot be undone.')"><i class="fa-solid fa-trash-can fa-xs"></i> Delete</a>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>

<?= pagination_html('campaign_page', $campaign_list_page, $campaign_total_pages, $campaign_total, $campaign_list_per_page) ?>

<?php if ($can_manage_campaigns): ?>
<div class="cl-bulk" id="bulk-bar">
  <span id="bulk-count" style="font-weight:700">0 selected</span>
  <form id="bulk-delete-form" method="POST" action="/campaigns?action=bulk_delete_campaigns" style="display:inline">
    <?= csrf_input() ?>
    <input type="hidden" name="campaign_ids" id="bulk-ids">
    <button type="button" onclick="bulkDeleteCampaigns()" style="background:#EF4444;color:#fff;border:none;padding:7px 16px;border-radius:8px;font-weight:700;font-size:12px;cursor:pointer"><i class="fa-solid fa-trash-can fa-xs"></i> Delete Selected</button>
  </form>
  <button onclick="clearBulkSelection()" style="background:#334155;color:#fff;border:none;padding:7px 13px;border-radius:8px;font-size:12px;cursor:pointer">Cancel</button>
</div>
<?php endif; ?>

<script>
  const selectAll = document.getElementById('select-all-camps');
  const bulkBar   = document.getElementById('bulk-bar');
  const bulkCount = document.getElementById('bulk-count');
  const getChecked = () => [...document.querySelectorAll('.camp-chk:checked')];
  function updateBulkBar() {
    if (!bulkBar||!bulkCount) return;
    const n = getChecked().length;
    bulkBar.style.display = n ? 'flex' : 'none';
    bulkCount.textContent = n + ' selected';
  }
  selectAll?.addEventListener('change', function() {
    document.querySelectorAll('.camp-chk').forEach(c => c.checked = this.checked);
    updateBulkBar();
  });
  document.querySelectorAll('.camp-chk').forEach(c => {
    c.addEventListener('change', () => {
      if (selectAll) selectAll.checked = [...document.querySelectorAll('.camp-chk')].every(x => x.checked);
      updateBulkBar();
    });
  });
  function clearBulkSelection() {
    document.querySelectorAll('.camp-chk').forEach(c => c.checked = false);
    if (selectAll) selectAll.checked = false;
    if (bulkBar) bulkBar.style.display = 'none';
  }
  function bulkDeleteCampaigns() {
    const ids = getChecked().map(c => c.value).join(',');
    if (!ids || !confirm('Delete ' + getChecked().length + ' campaign(s) and ALL their data? CANNOT be undone.')) return;
    document.getElementById('bulk-ids').value = ids;
    document.getElementById('bulk-delete-form').submit();
  }
</script>

<?php elseif ($action === 'new' || ($action === 'edit' && $campaign)): ?>
<?php $is_edit = ($action === 'edit'); ?>
<?php
  $form_msg = (string)($_GET['msg'] ?? '');
  $form_errors = ['start_date_past','end_before_start','required_missing','create_failed','invalid_method'];
  $form_messages = [
    'start_date_past'   => 'Start date cannot be in the past.',
    'end_before_start'  => 'End date must be after the start date.',
    'duplicate_campaign'=> 'A campaign with the same name and job role already exists.',
    'required_missing'  => 'Campaign name and job role are required.',
    'create_failed'     => 'Campaign could not be saved. Please check server logs.',
    'invalid_method'    => 'Please use the form to save campaign details.',
  ];
  $creator_name = htmlspecialchars($is_edit ? ($campaign['creator_name'] ?: $user['name']) : $user['name']);
  $setup_steps = [
    ['Save campaign details', true],
    ['Configure apply form fields', $is_edit && !empty($setup_state['steps'][2]['done'])],
    ['Add interview questions', $is_edit && !empty($setup_state['steps'][3]['done'])],
    ['Score weight = 100%', $is_edit && !empty($setup_state['steps'][4]['done'])],
    ['Activate & share', $is_edit && ($campaign['status']??'')  === 'active'],
  ];
?>
<style>
.cf-wrap{display:grid;grid-template-columns:1fr 360px;gap:24px;align-items:start}
.cf-form{background:#fff;border:1px solid #E8ECF0;border-radius:16px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.05)}
.cf-form-header{padding:22px 28px 18px;border-bottom:1px solid #EEF2F7;display:flex;align-items:center;justify-content:space-between}
.cf-form-title{font-size:18px;font-weight:800;color:#0F172A;letter-spacing:-.3px}
.cf-form-subtitle{font-size:12px;color:#64748B;margin-top:2px}
.cf-form-body{padding:24px 28px 28px}
.cf-section{margin-bottom:26px}
.cf-section-label{font-size:10px;font-weight:800;color:#7C3AED;text-transform:uppercase;letter-spacing:.8px;margin-bottom:12px;display:flex;align-items:center;gap:7px}
.cf-section-label::after{content:'';flex:1;height:1px;background:#F1F5F9}
.cf-row{display:grid;gap:14px;margin-bottom:14px}
.cf-row-2{grid-template-columns:1fr 1fr}
.cf-row-3{grid-template-columns:1fr 1fr 1fr}
.cf-group{display:flex;flex-direction:column;gap:5px}
.cf-label{font-size:11px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px}
.cf-label span{color:#EF4444;margin-left:2px}
.cf-input,.cf-select,.cf-textarea{width:100%;padding:9px 13px;border:1px solid #E2E8F0;border-radius:9px;font-size:13px;font-family:inherit;color:#0F172A;background:#FAFBFC;transition:all .15s;outline:none}
.cf-textarea{resize:vertical;min-height:90px;line-height:1.55}
.cf-input:focus,.cf-select:focus,.cf-textarea:focus{border-color:#7C3AED;background:#fff;box-shadow:0 0 0 3px rgba(124,58,237,.1)}
.cf-hint{font-size:11px;color:#94A3B8;margin-top:3px;line-height:1.4}
.cf-agent-row{display:flex;gap:8px;margin-top:8px}
.cf-agent-btn{display:inline-flex;align-items:center;gap:5px;padding:5px 11px;border:1px solid #E2E8F0;border-radius:7px;font-size:11.5px;font-weight:600;color:#374151;text-decoration:none;background:#F8FAFC;transition:all .12s}
.cf-agent-btn:hover{border-color:#7C3AED;color:#6D28D9;background:#F5F3FF}
.cf-submit{display:inline-flex;align-items:center;gap:8px;padding:11px 24px;background:linear-gradient(135deg,#6D28D9,#2563EB);color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;transition:opacity .14s,transform .14s;letter-spacing:-.1px}
.cf-submit:hover{opacity:.9;transform:translateY(-1px)}
/* Sidebar */
.cf-sidebar{display:flex;flex-direction:column;gap:14px;position:sticky;top:88px}
.cf-card{background:#fff;border:1px solid #E8ECF0;border-radius:14px;padding:18px;box-shadow:0 1px 4px rgba(0,0,0,.04)}
.cf-card-title{font-size:12px;font-weight:800;color:#0F172A;text-transform:uppercase;letter-spacing:.4px;margin-bottom:12px;display:flex;align-items:center;gap:7px}
.cf-step{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #F8FAFC;font-size:13px}
.cf-step:last-child{border-bottom:none}
.cf-step-num{width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:800;flex-shrink:0}
.cf-step-num.done{background:#DCFCE7;color:#15803D}
.cf-step-num.pending{background:#F1F5F9;color:#94A3B8}
.cf-step-num.current{background:#EDE9FE;color:#6D28D9}
.cf-step-txt{font-size:12.5px;color:#374151;font-weight:600}
.cf-step-txt.done{color:#15803D}
.cf-step-txt.pending{color:#94A3B8}
.cf-creator-chip{display:flex;align-items:center;gap:10px;padding:11px 14px;background:#F8FAFC;border:1px solid #EEF2F7;border-radius:10px}
.cf-creator-av{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#6D28D9,#4F46E5);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;color:#fff;flex-shrink:0}
.cf-creator-name{font-size:13px;font-weight:700;color:#0F172A}
.cf-creator-role{font-size:11px;color:#94A3B8;margin-top:1px}
@media(max-width:1100px){.cf-wrap{grid-template-columns:1fr}.cf-sidebar{position:static}}
@media(max-width:640px){.cf-row-2,.cf-row-3{grid-template-columns:1fr}}
</style>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
  <div>
    <h2 style="font-size:20px;font-weight:800;color:#0F172A;letter-spacing:-.3px"><?= $is_edit ? 'Edit Campaign' : 'New Campaign' ?></h2>
    <p style="font-size:13px;color:#64748B;margin-top:2px"><?= $is_edit ? htmlspecialchars($campaign['name']) : 'Fill in the details to create a new hiring campaign' ?></p>
  </div>
  <a href="campaigns.php" style="display:inline-flex;align-items:center;gap:5px;padding:7px 14px;border:1px solid #E2E8F0;border-radius:8px;font-size:12.5px;font-weight:700;color:#374151;text-decoration:none;background:#fff;transition:all .12s" onmouseover="this.style.borderColor='#7C3AED';this.style.color='#6D28D9'" onmouseout="this.style.borderColor='#E2E8F0';this.style.color='#374151'">
    <i class="fa-solid fa-arrow-left fa-xs"></i> Back
  </a>
</div>

<?php if ($form_msg): ?>
<div class="alert <?= in_array($form_msg,$form_errors,true)?'alert-error':'alert-success' ?>" style="margin-bottom:16px">
  <?= htmlspecialchars($form_messages[$form_msg] ?? str_replace('_',' ',$form_msg)) ?>
</div>
<?php endif; ?>

<div class="cf-wrap">
  <!-- ── FORM ── -->
  <div class="cf-form">
    <div class="cf-form-header">
      <div>
        <div class="cf-form-title"><?= $is_edit ? 'Campaign Details' : 'Campaign Setup' ?></div>
        <div class="cf-form-subtitle">Fields marked <span style="color:#EF4444">*</span> are required</div>
      </div>
      <?php if ($is_edit && ($campaign['status']??'') === 'active'): ?>
      <span style="display:inline-flex;align-items:center;gap:5px;padding:4px 11px;background:#DCFCE7;border:1px solid #BBF7D0;border-radius:99px;font-size:11px;font-weight:800;color:#15803D"><span style="width:6px;height:6px;border-radius:50%;background:#16A34A;display:inline-block"></span>Active</span>
      <?php elseif ($is_edit): ?>
      <span style="display:inline-flex;align-items:center;gap:5px;padding:4px 11px;background:#F1F5F9;border:1px solid #E2E8F0;border-radius:99px;font-size:11px;font-weight:800;color:#64748B"><span style="width:6px;height:6px;border-radius:50%;background:#94A3B8;display:inline-block"></span><?= ucfirst($campaign['status']??'draft') ?></span>
      <?php endif; ?>
    </div>
    <div class="cf-form-body">
    <form method="POST" action="/campaigns?action=<?= $is_edit ? 'edit_save' : 'save' ?><?= $is_edit ? '&id='.$campaign_id : '' ?>">
      <?= csrf_input() ?>
      <input type="hidden" name="integration_type" value="none">
      <input type="hidden" name="integration_endpoint" value="">

      <!-- Basics -->
      <div class="cf-section">
        <div class="cf-section-label"><i class="fa-solid fa-circle-info fa-xs"></i> Basic Info</div>
        <div class="cf-row cf-row-2">
          <div class="cf-group">
            <label class="cf-label">Campaign Name <span>*</span></label>
            <input type="text" name="name" class="cf-input" value="<?= htmlspecialchars($campaign['name'] ?? '') ?>" placeholder="e.g. AI Developer – Batch 2026" required>
          </div>
          <div class="cf-group">
            <label class="cf-label">Job Role <span>*</span></label>
            <input type="text" name="job_role" class="cf-input" value="<?= htmlspecialchars($campaign['job_role'] ?? '') ?>" placeholder="e.g. AI Developer" required>
          </div>
        </div>
        <div class="cf-group">
          <label class="cf-label">Description <span style="color:#94A3B8;font-weight:500;text-transform:none;letter-spacing:0">(shown to candidates)</span></label>
          <textarea name="description" class="cf-textarea" placeholder="Briefly describe the role, team, and what you're looking for…"><?= htmlspecialchars($campaign['description'] ?? '') ?></textarea>
        </div>
      </div>

      <!-- Schedule -->
      <div class="cf-section">
        <div class="cf-section-label"><i class="fa-solid fa-calendar fa-xs"></i> Schedule</div>
        <div class="cf-row cf-row-2">
          <div class="cf-group">
            <label class="cf-label">Start Date</label>
            <input type="date" name="start_date" class="cf-input" min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($campaign['start_date'] ?? '') ?>">
            <div class="cf-hint">Today or future date only</div>
          </div>
          <div class="cf-group">
            <label class="cf-label">End Date</label>
            <input type="date" name="end_date" class="cf-input" min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($campaign['end_date'] ?? '') ?>">
            <div class="cf-hint">Leave blank for open-ended campaigns</div>
          </div>
        </div>
      </div>

      <!-- AI Voice -->
      <div class="cf-section">
        <div class="cf-section-label"><i class="fa-solid fa-robot fa-xs"></i> AI Voice Agent <span style="font-size:9px;font-weight:600;background:#FEF3C7;color:#92400E;border-radius:4px;padding:1px 5px;text-transform:none;letter-spacing:0">Optional</span></div>
        <div class="cf-group">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:5px">
            <label class="cf-label" style="margin-bottom:0">Your AI Agent</label>
            <span id="agent-loading" style="font-size:11px;color:#94A3B8">Loading agents…</span>
          </div>
          <select name="el_agent_id" id="agent-select" class="cf-select">
            <option value="">No AI voice agent for now</option>
            <?php if (!empty($campaign['el_agent_id'])): ?>
            <option value="<?= htmlspecialchars($campaign['el_agent_id']) ?>" selected><?= htmlspecialchars($campaign['el_agent_id']) ?></option>
            <?php endif; ?>
          </select>
          <div class="cf-hint">Select only when you need outbound AI voice calling for this campaign</div>
          <div class="cf-agent-row">
            <a class="cf-agent-btn" href="credits.php" target="_blank" rel="noopener"><i class="fa-solid fa-coins fa-xs"></i> Recharge / Balance</a>
            <a class="cf-agent-btn" href="credits.php#pricing" target="_blank" rel="noopener"><i class="fa-solid fa-tags fa-xs"></i> AI Pricing</a>
          </div>
        </div>
      </div>

      <!-- Scoring -->
      <div class="cf-section" style="margin-bottom:28px">
        <div class="cf-section-label"><i class="fa-solid fa-chart-bar fa-xs"></i> Scoring & Language</div>
        <div class="cf-row cf-row-3">
          <div class="cf-group">
            <label class="cf-label">Passing Score <span style="color:#94A3B8;font-weight:500;text-transform:none;letter-spacing:0">(/100)</span></label>
            <input type="number" name="passing_score" class="cf-input" value="<?= $campaign['passing_score'] ?? 70 ?>" min="0" max="100">
            <div class="cf-hint">Candidates above this score are shortlisted</div>
          </div>
          <div class="cf-group">
            <label class="cf-label">No. of Questions</label>
            <input type="number" name="num_questions" class="cf-input" value="<?= $campaign['num_questions'] ?? 6 ?>" min="1" max="20">
            <div class="cf-hint">Expected questions in this interview</div>
          </div>
          <div class="cf-group">
            <label class="cf-label">Language</label>
            <select name="language" class="cf-select">
              <option value="english" <?= ($campaign['language']??'english')==='english'?'selected':'' ?>>English</option>
              <option value="hinglish" <?= ($campaign['language']??'')==='hinglish'?'selected':'' ?>>Hinglish</option>
              <option value="hindi" <?= ($campaign['language']??'')==='hindi'?'selected':'' ?>>Hindi</option>
            </select>
          </div>
        </div>
      </div>

      <button type="submit" class="cf-submit">
        <?php if ($is_edit): ?>
        <i class="fa-solid fa-floppy-disk fa-xs"></i> Save Changes
        <?php else: ?>
        <i class="fa-solid fa-arrow-right fa-xs"></i> Save & Continue →
        <?php endif; ?>
      </button>
    </form>
    </div>
  </div>

  <!-- ── SIDEBAR ── -->
  <div class="cf-sidebar">
    <!-- Creator -->
    <div class="cf-card">
      <div class="cf-card-title"><i class="fa-solid fa-user fa-xs" style="color:#7C3AED"></i> Creator</div>
      <div class="cf-creator-chip">
        <div class="cf-creator-av"><?= strtoupper(substr($creator_name, 0, 1)) ?></div>
        <div>
          <div class="cf-creator-name"><?= $creator_name ?></div>
          <div class="cf-creator-role">Stored in audit logs</div>
        </div>
      </div>
    </div>

    <!-- Setup checklist -->
    <div class="cf-card">
      <div class="cf-card-title"><i class="fa-solid fa-list-check fa-xs" style="color:#7C3AED"></i> Setup Guide</div>
      <?php foreach ($setup_steps as $idx => [$step_label, $step_done]):
        $is_current = $idx === 0 && !$is_edit;
        $cls = $step_done ? 'done' : ($is_current ? 'current' : 'pending');
        $icon = $step_done ? '✓' : ($idx + 1);
      ?>
      <div class="cf-step">
        <span class="cf-step-num <?= $cls ?>"><?= $icon ?></span>
        <span class="cf-step-txt <?= $cls ?>"><?= htmlspecialchars($step_label) ?></span>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if ($is_edit && !empty($setup_state['steps'])): ?>
    <!-- Quick actions -->
    <div class="cf-card">
      <div class="cf-card-title"><i class="fa-solid fa-bolt fa-xs" style="color:#7C3AED"></i> Quick Actions</div>
      <div style="display:flex;flex-direction:column;gap:6px">
        <a href="campaigns.php?action=apply_form&id=<?= $campaign_id ?>" style="display:flex;align-items:center;justify-content:space-between;padding:9px 12px;border:1px solid #E2E8F0;border-radius:9px;font-size:12.5px;font-weight:600;color:#374151;text-decoration:none;background:#FAFBFC;transition:all .12s" onmouseover="this.style.borderColor='#7C3AED';this.style.color='#6D28D9'" onmouseout="this.style.borderColor='#E2E8F0';this.style.color='#374151'">
          <span><i class="fa-solid fa-wpforms fa-xs" style="margin-right:6px"></i>Apply Form</span>
          <i class="fa-solid fa-chevron-right fa-xs" style="color:#CBD5E1"></i>
        </a>
        <a href="campaigns.php?action=questions&id=<?= $campaign_id ?>" style="display:flex;align-items:center;justify-content:space-between;padding:9px 12px;border:1px solid #E2E8F0;border-radius:9px;font-size:12.5px;font-weight:600;color:#374151;text-decoration:none;background:#FAFBFC;transition:all .12s" onmouseover="this.style.borderColor='#7C3AED';this.style.color='#6D28D9'" onmouseout="this.style.borderColor='#E2E8F0';this.style.color='#374151'">
          <span><i class="fa-solid fa-microphone-lines fa-xs" style="margin-right:6px"></i>Interview Questions</span>
          <i class="fa-solid fa-chevron-right fa-xs" style="color:#CBD5E1"></i>
        </a>
        <a href="candidates.php?campaign_id=<?= $campaign_id ?>" style="display:flex;align-items:center;justify-content:space-between;padding:9px 12px;border:1px solid #E2E8F0;border-radius:9px;font-size:12.5px;font-weight:600;color:#374151;text-decoration:none;background:#FAFBFC;transition:all .12s" onmouseover="this.style.borderColor='#7C3AED';this.style.color='#6D28D9'" onmouseout="this.style.borderColor='#E2E8F0';this.style.color='#374151'">
          <span><i class="fa-solid fa-users fa-xs" style="margin-right:6px"></i>View Candidates</span>
          <i class="fa-solid fa-chevron-right fa-xs" style="color:#CBD5E1"></i>
        </a>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

  <script>
  const currentAgentId = '<?= htmlspecialchars($campaign['el_agent_id'] ?? '') ?>';
  async function loadAgents() {
      try {
          const r = await fetch('api/interview.php?action=get_agents');
          const d = await r.json();
          const sel = document.getElementById('agent-select');
          document.getElementById('agent-loading').textContent = '';
          if (d.error) { document.getElementById('agent-loading').textContent = '❌ ' + d.error; return; }
          if (d.warning) { document.getElementById('agent-loading').textContent = '⚠️ ' + d.warning; return; }
          // Clear and rebuild
          sel.innerHTML = '<option value="">No AI voice agent for now</option>';
          (d.agents || []).forEach(a => {
              const opt = document.createElement('option');
              opt.value = a.agent_id;
              opt.textContent = a.name + ' (' + a.agent_id + ')';
              if (a.agent_id === currentAgentId) opt.selected = true;
              sel.appendChild(opt);
          });
          document.getElementById('agent-loading').textContent = (d.agents || []).length + ' AI agents loaded';
      } catch(e) {
          document.getElementById('agent-loading').textContent = '❌ Failed to load agents';
      }
  }
  loadAgents();
  </script>

<?php elseif ($action === 'questions' && $campaign): ?>
  <?php $applyLink = campaign_apply_link($campaign); ?>
  <?php $total_weight = $setup_state['weight'] ?? array_sum(array_column($questions, 'weight')); ?>
  <?php $canPreview = !empty($setup_state['ready_to_preview']); ?>
  <?php $topic_presets = question_topic_presets($campaign['job_role'] ?? ''); ?>
<style>
/* ── Questions page ───────────────────────────────────── */
.qp-header{background:#fff;border:1px solid #E8ECF0;border-radius:16px;padding:20px 24px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;box-shadow:0 1px 4px rgba(0,0,0,.05)}
.qp-title{font-size:18px;font-weight:800;color:#0F172A;letter-spacing:-.3px;margin-bottom:3px}
.qp-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.qp-chip{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700}
.qp-chip-role{background:#EFF6FF;color:#1D4ED8}
.qp-chip-pass{background:#F0FDF4;color:#15803D}
.qp-chip-agent{background:#F5F3FF;color:#6D28D9;font-family:ui-monospace,monospace;font-size:10.5px}
.qp-chip-noagent{background:#FFF1F2;color:#BE123C}
.qp-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap;flex-shrink:0}
.qp-btn{display:inline-flex;align-items:center;gap:5px;padding:7px 13px;border-radius:8px;font-size:12px;font-weight:700;border:1px solid #E2E8F0;background:#fff;color:#374151;text-decoration:none;cursor:pointer;transition:all .12s;white-space:nowrap}
.qp-btn:hover{border-color:#7C3AED;color:#6D28D9;background:#F5F3FF}
.qp-btn-primary{background:linear-gradient(135deg,#16A34A,#15803D);color:#fff;border-color:transparent}
.qp-btn-primary:hover{opacity:.9;color:#fff;background:linear-gradient(135deg,#16A34A,#15803D)}
.qp-btn-purple{border-color:#DDD6FE;color:#6D28D9;background:#F5F3FF}
.qp-btn-purple:hover{border-color:#7C3AED;background:#EDE9FE;color:#5B21B6}
.qp-btn-wa{border-color:#BBF7D0;color:#15803D;background:#F0FDF4}
.qp-btn-wa:hover{border-color:#16A34A;background:#DCFCE7}
.qp-link-bar{display:flex;align-items:center;gap:10px;background:#fff;border:1px solid #E8ECF0;border-radius:12px;padding:11px 16px;margin-bottom:16px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
.qp-link-url{flex:1;font-size:12px;color:#2563EB;font-family:ui-monospace,monospace;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;background:#F0F6FF;border:1px solid #BFDBFE;border-radius:8px;padding:6px 11px}
/* Question type badges */
.qt-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700;white-space:nowrap}
.qt-audio{background:#ECFDF5;color:#065F46}
.qt-video{background:#EDE9FE;color:#5B21B6}
.qt-textarea{background:#EFF6FF;color:#1E40AF}
.qt-text{background:#F0F9FF;color:#0369A1}
.qt-dropdown{background:#FDF4FF;color:#86198F}
.qt-multi_select{background:#FFF7ED;color:#C2410C}
.qt-rating{background:#FFFBEB;color:#B45309}
.qt-file{background:#F8FAFC;color:#475569}
.qt-other{background:#F1F5F9;color:#475569}
/* Questions table */
.q-table{width:100%;border-collapse:separate;border-spacing:0}
.q-table th{padding:8px 14px;text-align:left;font-size:10px;font-weight:800;color:#94A3B8;text-transform:uppercase;letter-spacing:.7px;background:#F8FAFC;border-bottom:1px solid #EEF2F7}
.q-table td{padding:11px 14px;border-bottom:1px solid #F3F6FA;vertical-align:middle;font-size:13px}
.q-table tr:last-child td{border-bottom:none}
.q-table tbody tr:hover td{background:#F8FAFC}
.q-num{width:30px;height:30px;border-radius:8px;background:#F1F5F9;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#475569;flex-shrink:0}
.q-text-main{font-size:13px;font-weight:600;color:#0F172A;line-height:1.4;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:280px;display:block}
.q-weight-bar{display:flex;flex-direction:column;gap:3px}
.q-weight-val{font-size:13px;font-weight:800;color:#0F172A}
.q-weight-track{width:44px;height:4px;background:#E2E8F0;border-radius:2px}
.q-weight-fill{height:100%;background:linear-gradient(90deg,#7C3AED,#2563EB);border-radius:2px}
.q-act-btn{width:28px;height:28px;border-radius:7px;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:11px;transition:all .12s;text-decoration:none}
.q-act-edit{background:#EFF6FF;color:#1E40AF}.q-act-edit:hover{background:#DBEAFE}
.q-act-del{background:#FFF1F2;color:#BE123C}.q-act-del:hover{background:#FECDD3}
/* Add question form */
.qf-card{background:#fff;border:1px solid #E8ECF0;border-radius:16px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.05)}
.qf-head{padding:14px 20px;border-bottom:1px solid #EEF2F7;display:flex;align-items:center;justify-content:space-between;background:#F8FAFC}
.qf-head-title{font-size:14px;font-weight:800;color:#0F172A;display:flex;align-items:center;gap:8px}
.qf-hint-bar{padding:10px 20px;background:linear-gradient(135deg,#F5F3FF,#EFF6FF);border-bottom:1px solid #EEF2F7;font-size:12px;color:#4B5563;line-height:1.45}
.qf-body{padding:16px 20px}
.qf-row{display:grid;gap:12px;margin-bottom:12px}
.qf-row-2{grid-template-columns:1fr 1fr}
.qf-row-3{grid-template-columns:1fr 1fr 1fr}
.qf-group{display:flex;flex-direction:column;gap:5px}
.qf-label{font-size:11px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px;display:flex;align-items:center;justify-content:space-between}
.qf-input,.qf-select,.qf-textarea{width:100%;padding:8px 12px;border:1px solid #E2E8F0;border-radius:8px;font-size:13px;font-family:inherit;color:#0F172A;background:#FAFBFC;outline:none;transition:all .14s}
.qf-textarea{resize:vertical;line-height:1.5}
.qf-input:focus,.qf-select:focus,.qf-textarea:focus{border-color:#7C3AED;background:#fff;box-shadow:0 0 0 3px rgba(124,58,237,.1)}
.qf-hint{font-size:11px;color:#94A3B8;margin-top:3px}
.qf-mcq{display:none;background:#FFFBEB;border:1px solid #FDE68A;border-radius:10px;padding:14px;margin-bottom:14px}
.qf-mcq.active{display:block}
.qf-mcq-presets{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}
.qf-mcq-presets button{border:1px solid #BFDBFE;background:#EFF6FF;color:#1D4ED8;border-radius:99px;padding:4px 10px;font-size:11px;font-weight:700;cursor:pointer}
.qf-mcq-presets button:hover{background:#DBEAFE}
.qf-req-toggle{display:flex;align-items:center;gap:7px;font-size:13px;color:#374151;cursor:pointer;padding:8px 0}
.qf-req-toggle input{width:15px;height:15px;accent-color:#7C3AED}
.qf-submit{display:inline-flex;align-items:center;gap:7px;padding:10px 22px;background:linear-gradient(135deg,#6D28D9,#4F46E5);color:#fff;border:none;border-radius:9px;font-size:13px;font-weight:700;cursor:pointer;transition:opacity .13s,transform .13s}
.qf-submit:hover{opacity:.9;transform:translateY(-1px)}
/* Journey sidebar */
.journey-grid{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:18px;align-items:start}
.journey-card{background:#fff;border:1px solid #E8ECF0;border-radius:14px;padding:18px;position:sticky;top:88px;box-shadow:0 1px 4px rgba(0,0,0,.04)}
.journey-title{font-size:14px;font-weight:800;color:#0F172A;margin-bottom:2px}
.journey-sub{font-size:11.5px;color:#94A3B8;margin-bottom:10px}
.journey-ring{height:6px;background:#EEF2F7;border-radius:999px;overflow:hidden;margin-bottom:5px}
.journey-ring span{display:block;height:100%;background:linear-gradient(90deg,#7C3AED,#2563EB);border-radius:999px}
.journey-pct{font-size:12px;font-weight:800;color:#374151;margin-bottom:12px}
.journey-step{display:flex;align-items:center;gap:8px;font-size:12.5px;color:#64748B;padding:7px 0;border-bottom:1px solid #F8FAFC}
.journey-step:last-child{border-bottom:none}
.journey-step i{font-size:12px;color:#CBD5E1;width:14px;text-align:center}
.journey-step.done{color:#15803D;font-weight:700}
.journey-step.done i{color:#16A34A}
.j-stats{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:14px 0}
.j-stat{background:#F8FAFC;border:1px solid #EEF2F7;border-radius:9px;padding:10px 12px}
.j-stat strong{display:block;font-size:17px;font-weight:800;color:#0F172A;letter-spacing:-.3px}
.j-stat span{font-size:10px;font-weight:600;color:#94A3B8;text-transform:uppercase;letter-spacing:.4px}
.j-note{background:#EFF6FF;border:1px solid #BFDBFE;border-radius:9px;padding:10px 12px;font-size:11.5px;color:#1E40AF;line-height:1.5}
.helper-text{display:block;color:#94A3B8;font-size:11px;margin-top:3px}
.mcq-box{display:none}
.mcq-box.active{display:block}
.mcq-presets{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}
.mcq-presets button{border:1px solid #BFDBFE;background:#EFF6FF;color:#1D4ED8;border-radius:99px;padding:4px 10px;font-size:11px;font-weight:700;cursor:pointer}
.mcq-presets button:hover{background:#DBEAFE}
.advanced-question-block{display:none}
/* Add Question modal */
.aq-overlay{position:fixed;inset:0;background:rgba(10,16,32,.72);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);z-index:9000;display:none;align-items:center;justify-content:center;padding:24px;animation:aqFadeIn .18s}
.aq-overlay.open{display:flex}
@keyframes aqFadeIn{from{opacity:0}to{opacity:1}}
.aq-modal{background:#fff;border-radius:22px;width:100%;max-width:720px;max-height:92vh;display:flex;flex-direction:column;box-shadow:0 32px 100px rgba(0,0,0,.35);animation:aqSlideUp .26s cubic-bezier(.4,0,.2,1)}
@keyframes aqSlideUp{from{opacity:0;transform:translateY(26px) scale(.97)}to{opacity:1;transform:none}}
.aq-head{display:flex;align-items:center;justify-content:space-between;padding:22px 28px 18px;border-bottom:1px solid #EEF2F7;flex-shrink:0}
.aq-head-left{display:flex;align-items:center;gap:11px}
.aq-head-icon{width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,#EDE9FE,#DBEAFE);display:flex;align-items:center;justify-content:center;font-size:18px;color:#6D28D9}
.aq-head-title{font-size:17px;font-weight:800;color:#0F172A;letter-spacing:-.25px}
.aq-head-sub{font-size:11.5px;color:#94A3B8;margin-top:2px}
.aq-close{width:34px;height:34px;border:none;background:#F1F5F9;border-radius:8px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#64748B;font-size:15px;transition:all .14s;flex-shrink:0}
.aq-close:hover{background:#E2E8F0;color:#0F172A}
.aq-hint-bar{padding:10px 28px;background:linear-gradient(135deg,#F5F3FF,#EFF6FF);border-bottom:1px solid #EEF2F7;font-size:12px;color:#4B5563;line-height:1.45;flex-shrink:0}
.aq-body{flex:1;overflow-y:auto;padding:22px 28px}
.aq-footer{padding:18px 28px;border-top:1px solid #EEF2F7;display:flex;align-items:center;gap:10px;flex-shrink:0;background:#FAFBFC;border-radius:0 0 22px 22px}
/* Big add button */
.aq-open-btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;background:linear-gradient(135deg,#6D28D9,#4F46E5);color:#fff;border:none;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;transition:opacity .13s,transform .13s;white-space:nowrap}
.aq-open-btn:hover{opacity:.88;transform:translateY(-1px)}
/* Empty state */
.aq-empty{text-align:center;padding:52px 24px;color:#94A3B8}
.aq-empty i{font-size:44px;margin-bottom:14px;display:block;color:#CBD5E1}
.aq-empty p{font-size:15px;font-weight:700;color:#374151;margin-bottom:6px}
.aq-empty span{font-size:13px;color:#94A3B8;display:block;margin-bottom:22px}
@media(max-width:1100px){.journey-grid{grid-template-columns:1fr}.journey-card{position:static}}
@media(max-width:640px){.qf-row-2,.qf-row-3{grid-template-columns:1fr}}
</style>

<?php
  $question_errors = ['options_required' => 'Add choices for MCQ/rating questions before saving.'];
  $qp_msg = (string)($_GET['msg'] ?? '');
?>

<div class="qp-header">
  <div>
    <div class="qp-title"><?= htmlspecialchars($campaign['name']) ?></div>
    <div class="qp-meta">
      <span class="qp-chip qp-chip-role"><i class="fa-solid fa-briefcase fa-xs"></i> <?= htmlspecialchars($campaign['job_role']) ?></span>
      <span class="qp-chip qp-chip-pass"><i class="fa-solid fa-bullseye fa-xs"></i> Pass <?= (int)$campaign['passing_score'] ?>/100</span>
      <?php if ($campaign['el_agent_id']): ?>
      <span class="qp-chip qp-chip-agent"><i class="fa-solid fa-robot fa-xs"></i> <?= htmlspecialchars(substr($campaign['el_agent_id'],0,24)) ?></span>
      <?php else: ?><span class="qp-chip qp-chip-noagent"><i class="fa-solid fa-robot fa-xs"></i> No Agent</span><?php endif; ?>
    </div>
  </div>
  <div class="qp-actions">
    <?php if ($canPreview): ?>
    <button type="button" class="qp-btn qp-btn-primary" onclick="copyCampaignLink(<?= htmlspecialchars(json_encode($applyLink),ENT_QUOTES,'UTF-8') ?>)"><i class="fa-solid fa-copy fa-xs"></i> Copy Link</button>
    <a href="<?= htmlspecialchars($applyLink) ?>" target="_blank" rel="noopener" class="qp-btn qp-btn-purple"><i class="fa-solid fa-flask fa-xs"></i> Test Run</a>
    <a href="https://wa.me/?text=<?= urlencode('Apply here: '.$applyLink) ?>" target="_blank" rel="noopener" class="qp-btn qp-btn-wa"><i class="fa-brands fa-whatsapp fa-xs"></i> Share WA</a>
    <?php else: ?>
    <a href="campaigns.php?action=apply_form&id=<?= $campaign_id ?>" class="qp-btn" style="border-color:#FDE68A;color:#92400E;background:#FFFBEB"><i class="fa-solid fa-triangle-exclamation fa-xs"></i> Setup Apply Form</a>
    <?php endif; ?>
    <a href="campaigns.php?action=apply_form&id=<?= $campaign_id ?>" class="qp-btn"><i class="fa-solid fa-wpforms fa-xs"></i> Apply Form</a>
    <a href="campaigns.php?action=edit&id=<?= $campaign_id ?>" class="qp-btn"><i class="fa-solid fa-pen fa-xs"></i> Edit</a>
    <a href="campaigns.php" class="qp-btn"><i class="fa-solid fa-arrow-left fa-xs"></i> Back</a>
  </div>
</div>

<div class="qp-link-bar">
  <span style="font-size:10px;font-weight:800;color:#94A3B8;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap">Apply Link</span>
  <span class="qp-link-url"><?= htmlspecialchars($applyLink) ?></span>
  <?php if ($canPreview): ?>
  <a href="<?= htmlspecialchars($applyLink) ?>" target="_blank" rel="noopener" class="qp-btn" style="flex-shrink:0"><i class="fa-solid fa-eye fa-xs"></i> Preview</a>
  <?php else: ?>
  <a href="campaigns.php?action=apply_form&id=<?= $campaign_id ?>" class="qp-btn" style="flex-shrink:0;border-color:#FDE68A;color:#92400E;background:#FFFBEB">Add fields first</a>
  <?php endif; ?>
</div>

<?php if ($qp_msg && $qp_msg !== 'setup_incomplete'): ?>
<div class="alert <?= isset($question_errors[$qp_msg])?'alert-error':'alert-success' ?>" style="margin-bottom:14px">
  <?= isset($question_errors[$qp_msg])?'⚠️ '.$question_errors[$qp_msg]:'✅ '.htmlspecialchars(str_replace('_',' ',$qp_msg)).'!' ?>
</div>
<?php endif; ?>
<?php if ($qp_msg === 'setup_incomplete'): ?>
<div class="alert alert-error" style="margin-bottom:14px">⚠️ Campaign cannot be activated yet — complete all setup steps first.</div>
<?php endif; ?>

<div class="journey-grid">
<div>

<?php if (!empty($questions)):
  $qt_colors=['audio'=>'qt-audio','video'=>'qt-video','textarea'=>'qt-textarea','text'=>'qt-text','dropdown'=>'qt-dropdown','multi_select'=>'qt-multi_select','rating'=>'qt-rating','file'=>'qt-file'];
  $qt_icons=['audio'=>'fa-microphone','video'=>'fa-video','textarea'=>'fa-align-left','text'=>'fa-font','dropdown'=>'fa-list','multi_select'=>'fa-check-square','rating'=>'fa-star','file'=>'fa-file'];
?>
<div style="background:#fff;border:1px solid #E8ECF0;border-radius:16px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.05);margin-bottom:18px">
  <div style="padding:14px 18px;border-bottom:1px solid #EEF2F7;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <div style="font-size:14px;font-weight:800;color:#0F172A;display:flex;align-items:center;gap:8px">
      <i class="fa-solid fa-comments" style="color:#7C3AED;font-size:13px"></i>
      Interview Questions
      <span style="background:#EDE9FE;color:#6D28D9;border-radius:99px;padding:1px 9px;font-size:11px;font-weight:800"><?= count($questions) ?></span>
    </div>
    <div style="display:flex;align-items:center;gap:12px">
      <span style="font-size:11px;color:<?= $total_weight==100?'#15803D':'#DC2626' ?>;font-weight:700">
        Total Weight: <strong><?= $total_weight ?>%</strong>
        <?= $total_weight==100?'<i class="fa-solid fa-circle-check fa-xs"></i>':'<i class="fa-solid fa-triangle-exclamation fa-xs"></i> Must be 100%' ?>
      </span>
      <?= pagination_per_page_select('question_per_page','question_page',$question_per_page) ?>
      <button type="button" class="aq-open-btn" onclick="openAddModal()"><i class="fa-solid fa-plus fa-xs"></i> Add Question</button>
    </div>
  </div>
  <table class="q-table">
    <thead><tr>
      <th style="width:44px">#</th><th>Type</th><th>Weight</th><th>Max</th><th>Question</th><th>Logic</th><th style="width:70px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($question_page_rows as $q):
      $qt=$q['question_type']??'textarea';
      $qtc=$qt_colors[$qt]??'qt-other';
      $qti=$qt_icons[$qt]??'fa-question';
    ?>
    <tr>
      <td><div class="q-num"><?= (int)$q['order_no'] ?></div></td>
      <td><span class="qt-badge <?= $qtc ?>"><i class="fa-solid <?= $qti ?> fa-xs"></i> <?= htmlspecialchars(str_replace('_',' ',$qt)) ?></span></td>
      <td><div class="q-weight-bar"><span class="q-weight-val"><?= (int)$q['weight'] ?>%</span><div class="q-weight-track"><div class="q-weight-fill" style="width:<?= min(100,(int)$q['weight']) ?>%"></div></div></div></td>
      <td style="font-size:12px;color:#64748B;font-weight:600"><?= (int)$q['max_marks'] ?></td>
      <td><span class="q-text-main" title="<?= htmlspecialchars($q['question_text']) ?>"><?= htmlspecialchars($q['question_text']) ?></span></td>
      <td><span style="font-size:11px;font-weight:600;color:<?= !empty($q['branch_rules_json'])?'#D97706':'#94A3B8' ?>"><?= !empty($q['branch_rules_json'])?'Branch':'Linear' ?></span></td>
      <td><div style="display:flex;gap:5px">
        <a href="campaigns.php?action=questions&id=<?= $campaign_id ?>&edit_qid=<?= $q['id'] ?>" class="q-act-btn q-act-edit" title="Edit"><i class="fa-solid fa-pen fa-xs"></i></a>
        <a href="campaigns.php?action=delete_question&id=<?= $campaign_id ?>&qid=<?= $q['id'] ?>&csrf_token=<?= urlencode(csrf_token()) ?>" class="q-act-btn q-act-del" title="Delete" onclick="return confirm('Delete this question?')"><i class="fa-solid fa-trash-can fa-xs"></i></a>
      </div></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?= pagination_html('question_page',$question_page,$question_total_pages,$question_total,$question_per_page) ?>
</div>
<?php else: ?>
<div class="aq-empty">
  <i class="fa-solid fa-comments"></i>
  <p>No interview questions yet</p>
  <span>Add questions to evaluate candidates during the application process.</span>
  <button type="button" class="aq-open-btn" onclick="openAddModal()"><i class="fa-solid fa-plus fa-xs"></i> Add First Question</button>
</div>
<?php endif; ?>

</div>

<!-- ══ ADD QUESTION MODAL ══════════════════════════════ -->
<div class="aq-overlay" id="aqOverlay" onclick="if(event.target===this)closeAddModal()">
  <div class="aq-modal">
    <div class="aq-head">
      <div class="aq-head-left">
        <div class="aq-head-icon"><i class="fa-solid fa-plus fa-sm"></i></div>
        <div>
          <div class="aq-head-title">Add Interview Question</div>
          <div class="aq-head-sub">Fill in the details below and save</div>
        </div>
      </div>
      <button type="button" class="aq-close" onclick="closeAddModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="aq-hint-bar"><strong>Simple mode:</strong> write the question, pick an answer type, set marks, and add choices only for MCQ/rating.</div>
    <div class="aq-body">
      <form id="aqForm" method="POST" action="/campaigns?action=add_question&id=<?= $campaign_id ?>" onsubmit="return validateQuestionForm(this)">
        <?= csrf_input() ?>
        <div class="qf-row qf-row-2" style="margin-bottom:14px">
          <div class="qf-group">
            <label class="qf-label">Answer Type</label>
            <select name="question_type" class="qf-select" onchange="syncQuestionTypeUI()">
              <option value="textarea">Long Text / Interview Answer</option>
              <option value="text">Short Text</option>
              <option value="number">Numeric</option>
              <option value="decimal">Decimal</option>
              <option value="date">Date</option>
              <option value="dropdown">MCQ — Single Choice</option>
              <option value="multi_select">MCQ — Multiple Choice</option>
              <option value="rating">Rating Scale</option>
              <option value="file">File Upload</option>
              <option value="audio">Record Audio</option>
              <option value="video">Record Video</option>
              <option value="hyperlink">Hyperlink</option>
            </select>
          </div>
          <div class="qf-group">
            <label class="qf-label">Required</label>
            <label class="qf-req-toggle"><input type="checkbox" name="is_required" checked> Candidate must answer this question</label>
          </div>
        </div>
        <div class="qf-row qf-row-3" style="margin-bottom:14px">
          <div class="qf-group">
            <label class="qf-label">Weight (%)</label>
            <input type="number" name="weight" class="qf-input" value="15" min="1" max="100" required>
            <div class="qf-hint">Score contribution</div>
          </div>
          <div class="qf-group">
            <label class="qf-label">Max Marks</label>
            <input type="number" name="max_marks" class="qf-input" value="15" min="1" required>
          </div>
          <div class="qf-group">
            <label class="qf-label">Order</label>
            <input type="number" name="order_no" class="qf-input" value="<?= count($questions)+1 ?>">
          </div>
        </div>
        <div class="qf-group" style="margin-bottom:14px">
          <label class="qf-label">Question Text <span style="color:#EF4444">*</span></label>
          <textarea name="question_text" class="qf-textarea" rows="3" placeholder="Write the question here." required onblur="autoFillInlineChoices()"></textarea>
        </div>
        <div class="qf-group" style="margin-bottom:14px">
          <label class="qf-label">
            AI Scoring Criteria
            <button type="button" class="qp-btn qp-btn-purple" style="font-size:11px;padding:4px 10px" onclick="assistIdealAnswer()"><i class="fa-solid fa-wand-magic-sparkles fa-xs"></i> AI Assist</button>
          </label>
          <textarea name="ideal_answer_hint" class="qf-textarea" rows="3" placeholder="Keywords or concepts AI should look for…"></textarea>
        </div>
        <div class="qf-mcq mcq-box" id="mcqBox" style="margin-bottom:14px">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
            <label class="qf-label" style="margin:0">MCQ Choices <span style="color:#EF4444">*</span></label>
            <button type="button" class="qp-btn" style="font-size:11px;padding:4px 10px" onclick="fillOptionSuggestion()">Guide me</button>
          </div>
          <div class="qf-mcq-presets mcq-presets">
            <button type="button" onclick="setQuestionOptions('Yes\nNo')">Yes / No</button>
            <button type="button" onclick="setQuestionOptions('Beginner\nIntermediate\nAdvanced')">Skill Level</button>
            <button type="button" onclick="setQuestionOptions('1 - Poor\n2 - Fair\n3 - Good\n4 - Very Good\n5 - Excellent')">5-point Rating</button>
          </div>
          <textarea name="options_text" class="qf-textarea" rows="4" placeholder="One option per line"></textarea>
          <div class="qf-hint">Shown as radio buttons / checkboxes to the candidate</div>
        </div>
        <div class="advanced-question-block" style="margin-bottom:14px">
          <details>
            <summary style="cursor:pointer;font-size:12px;font-weight:700;color:#94A3B8">Advanced — Branching Rules</summary>
            <div class="qf-group" style="margin-top:10px">
              <textarea name="branch_rules_json" class="qf-textarea" rows="3" placeholder='[{"when":"yes","jump_to_order":5}]'></textarea>
            </div>
          </details>
        </div>
      </form>
    </div>
    <div class="aq-footer">
      <button type="submit" form="aqForm" class="qf-submit"><i class="fa-solid fa-plus fa-xs"></i> Add Question</button>
      <button type="button" onclick="closeAddModal()" style="font-size:13px;color:#94A3B8;background:none;border:none;cursor:pointer;font-weight:600;padding:10px 6px">Cancel</button>
    </div>
  </div>
</div>

<!-- ══ EDIT QUESTION MODAL ══════════════════════════════ -->
<?php if ($editing_question): ?>
<style>
.eq-overlay{position:fixed;inset:0;background:rgba(10,16,32,.7);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);z-index:9000;display:flex;align-items:center;justify-content:center;padding:24px;animation:fadeIn .2s}
.eq-modal{background:#fff;border-radius:22px;width:100%;max-width:700px;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 32px 100px rgba(0,0,0,.35);animation:eqSlideUp .28s cubic-bezier(.4,0,.2,1)}
@keyframes eqSlideUp{from{opacity:0;transform:translateY(28px) scale(.97)}to{opacity:1;transform:none}}
.eq-head{display:flex;align-items:center;justify-content:space-between;padding:22px 28px 18px;border-bottom:1px solid #EEF2F7;flex-shrink:0}
.eq-head-left{display:flex;align-items:center;gap:10px}
.eq-head-icon{width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,#EDE9FE,#DBEAFE);display:flex;align-items:center;justify-content:center;font-size:16px;color:#6D28D9}
.eq-head-title{font-size:16px;font-weight:800;color:#0F172A;letter-spacing:-.2px}
.eq-head-sub{font-size:11px;color:#94A3B8;margin-top:1px}
.eq-close{width:32px;height:32px;border:none;background:#F1F5F9;border-radius:8px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#64748B;font-size:15px;text-decoration:none;transition:all .14s;flex-shrink:0}
.eq-close:hover{background:#E2E8F0;color:#0F172A}
.eq-body{flex:1;overflow-y:auto;padding:24px 28px}
.eq-footer{padding:18px 28px;border-top:1px solid #EEF2F7;display:flex;align-items:center;gap:10px;flex-shrink:0;background:#FAFBFC;border-radius:0 0 22px 22px}
</style>
<div class="eq-overlay" id="eqOverlay" onclick="if(event.target===this)closeEqModal()">
  <div class="eq-modal">
    <div class="eq-head">
      <div class="eq-head-left">
        <div class="eq-head-icon"><i class="fa-solid fa-pen-to-square fa-sm"></i></div>
        <div>
          <div class="eq-head-title">Edit Question</div>
          <div class="eq-head-sub">Q<?= (int)($editing_question['order_no']??'') ?> &mdash; <?= htmlspecialchars(ucfirst(str_replace('_',' ',$editing_question['question_type']??'textarea'))) ?></div>
        </div>
      </div>
      <a href="campaigns.php?action=questions&id=<?= $campaign_id ?>" class="eq-close" id="eqClose"><i class="fa-solid fa-xmark"></i></a>
    </div>

    <div class="eq-body">
    <form id="eqForm" method="POST" action="/campaigns?action=edit_question&id=<?= $campaign_id ?>" onsubmit="return validateQuestionForm(this)">
      <?= csrf_input() ?>
      <input type="hidden" name="question_id" value="<?= (int)$editing_question['id'] ?>">

      <div class="qf-row qf-row-2" style="margin-bottom:14px">
        <div class="qf-group">
          <label class="qf-label">Answer Type</label>
          <?php $selected_qtype=$editing_question['question_type']??'textarea'; ?>
          <select name="question_type" class="qf-select" onchange="syncQuestionTypeUI()">
            <option value="textarea" <?= $selected_qtype==='textarea'?'selected':'' ?>>Long Text / Interview Answer</option>
            <option value="text" <?= $selected_qtype==='text'?'selected':'' ?>>Short Text</option>
            <option value="number" <?= $selected_qtype==='number'?'selected':'' ?>>Numeric</option>
            <option value="decimal" <?= $selected_qtype==='decimal'?'selected':'' ?>>Decimal</option>
            <option value="date" <?= $selected_qtype==='date'?'selected':'' ?>>Date</option>
            <option value="dropdown" <?= $selected_qtype==='dropdown'?'selected':'' ?>>MCQ — Single Choice</option>
            <option value="multi_select" <?= $selected_qtype==='multi_select'?'selected':'' ?>>MCQ — Multiple Choice</option>
            <option value="rating" <?= $selected_qtype==='rating'?'selected':'' ?>>Rating Scale</option>
            <option value="file" <?= $selected_qtype==='file'?'selected':'' ?>>File Upload</option>
            <option value="audio" <?= $selected_qtype==='audio'?'selected':'' ?>>Record Audio</option>
            <option value="video" <?= $selected_qtype==='video'?'selected':'' ?>>Record Video</option>
            <option value="hyperlink" <?= $selected_qtype==='hyperlink'?'selected':'' ?>>Hyperlink</option>
          </select>
        </div>
        <div class="qf-group">
          <label class="qf-label">Required</label>
          <label class="qf-req-toggle">
            <input type="checkbox" name="is_required" <?= !empty($editing_question['is_required'])?'checked':'' ?>>
            Candidate must answer this question
          </label>
        </div>
      </div>

      <div class="qf-row qf-row-3" style="margin-bottom:14px">
        <div class="qf-group">
          <label class="qf-label">Weight (%)</label>
          <input type="number" name="weight" class="qf-input" value="<?= htmlspecialchars($editing_question['weight']??15) ?>" min="1" max="100" required>
        </div>
        <div class="qf-group">
          <label class="qf-label">Max Marks</label>
          <input type="number" name="max_marks" class="qf-input" value="<?= htmlspecialchars($editing_question['max_marks']??15) ?>" min="1" required>
        </div>
        <div class="qf-group">
          <label class="qf-label">Order</label>
          <input type="number" name="order_no" class="qf-input" value="<?= htmlspecialchars($editing_question['order_no']??(count($questions)+1)) ?>">
        </div>
      </div>

      <div class="qf-group" style="margin-bottom:14px">
        <label class="qf-label">Question Text <span style="color:#EF4444">*</span></label>
        <textarea name="question_text" class="qf-textarea" rows="4" required onblur="autoFillInlineChoices()"><?= htmlspecialchars($editing_question['question_text']??'') ?></textarea>
      </div>

      <div class="qf-group" style="margin-bottom:14px">
        <label class="qf-label">
          AI Scoring Criteria
          <button type="button" class="qp-btn qp-btn-purple" style="font-size:11px;padding:4px 10px" onclick="assistIdealAnswer()"><i class="fa-solid fa-wand-magic-sparkles fa-xs"></i> AI Assist</button>
        </label>
        <textarea name="ideal_answer_hint" class="qf-textarea" rows="2" placeholder="Keywords AI should look for in a strong answer…"><?= htmlspecialchars($editing_question['ideal_answer_hint']??'') ?></textarea>
      </div>

      <div class="qf-mcq mcq-box" id="mcqBox" style="margin-bottom:14px">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
          <label class="qf-label" style="margin:0">MCQ Choices <span style="color:#EF4444">*</span></label>
          <button type="button" class="qp-btn" style="font-size:11px;padding:4px 10px" onclick="fillOptionSuggestion()">Guide me</button>
        </div>
        <div class="qf-mcq-presets mcq-presets">
          <button type="button" onclick="setQuestionOptions('Yes\nNo')">Yes / No</button>
          <button type="button" onclick="setQuestionOptions('Beginner\nIntermediate\nAdvanced')">Skill Level</button>
          <button type="button" onclick="setQuestionOptions('1 - Poor\n2 - Fair\n3 - Good\n4 - Very Good\n5 - Excellent')">5-point Rating</button>
        </div>
        <textarea name="options_text" class="qf-textarea" rows="4" placeholder="One option per line"><?= htmlspecialchars($editing_options_text) ?></textarea>
        <div class="qf-hint">Shown as radio buttons / checkboxes to the candidate</div>
      </div>

      <div class="advanced-question-block">
        <details>
          <summary style="cursor:pointer;font-size:12px;font-weight:700;color:#94A3B8">Advanced — Branching Rules</summary>
          <div class="qf-group" style="margin-top:10px">
            <textarea name="branch_rules_json" class="qf-textarea" rows="3" placeholder='[{"when":"yes","jump_to_order":5}]'><?= htmlspecialchars($editing_question['branch_rules_json']??'') ?></textarea>
            <div class="qf-hint">Optional. Leave blank for linear flow.</div>
          </div>
        </details>
      </div>
    </form>
    </div>

    <div class="eq-footer">
      <button type="submit" form="eqForm" class="qf-submit"><i class="fa-solid fa-floppy-disk fa-xs"></i> Save Changes</button>
      <a href="campaigns.php?action=questions&id=<?= $campaign_id ?>" style="font-size:13px;color:#94A3B8;text-decoration:none;font-weight:600;padding:10px 6px">Cancel</a>
      <span style="flex:1"></span>
      <a href="campaigns.php?action=delete_question&id=<?= $campaign_id ?>&qid=<?= (int)$editing_question['id'] ?>&csrf_token=<?= urlencode(csrf_token()) ?>" style="display:inline-flex;align-items:center;gap:5px;padding:8px 13px;border:1px solid #FECACA;border-radius:8px;font-size:12px;font-weight:700;color:#DC2626;background:#FFF5F5;text-decoration:none;transition:all .12s" onclick="return confirm('Delete this question?')" onmouseover="this.style.background='#FEE2E2'" onmouseout="this.style.background='#FFF5F5'">
        <i class="fa-solid fa-trash-can fa-xs"></i> Delete
      </a>
    </div>
  </div>
</div>
<script>
document.body.style.overflow='hidden';
function closeEqModal(){document.body.style.overflow='';window.location.href='campaigns.php?action=questions&id=<?= $campaign_id ?>';}
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeEqModal();});
</script>
<?php endif; ?>

<aside class="journey-card">
  <div class="journey-title">Campaign Journey</div>
  <div class="journey-sub">Complete each step to publish.</div>
  <div class="journey-ring"><span style="width:<?= (int)$setup_state['percent'] ?>%"></span></div>
  <div class="journey-pct"><?= (int)$setup_state['percent'] ?>% complete</div>
  <?php foreach ($setup_state['steps'] as $step): ?>
  <div class="journey-step <?= $step['done']?'done':'' ?>">
    <i class="fa-solid <?= $step['done']?'fa-circle-check':'fa-circle' ?>"></i>
    <?= htmlspecialchars($step['label']) ?>
  </div>
  <?php endforeach; ?>
  <div class="j-stats">
    <div class="j-stat"><strong><?= count($questions) ?></strong><span>Questions</span></div>
    <div class="j-stat"><strong><?= count($application_fields) ?></strong><span>Apply fields</span></div>
    <div class="j-stat"><strong><?= (int)$setup_state['weight'] ?>%</strong><span>Weight used</span></div>
    <div class="j-stat"><strong style="color:<?= $setup_state['remaining_weight']>0?'#DC2626':'#15803D' ?>"><?= (int)$setup_state['remaining_weight'] ?>%</strong><span>Remaining</span></div>
  </div>
  <div class="j-note"><i class="fa-solid fa-circle-info fa-xs" style="margin-right:5px"></i>Audio/video answers are stored as recordings/transcripts and summarized by AI during scoring.</div>
</aside>
</div>
<?php elseif ($action === 'apply_form' && $campaign): ?>
  <?php $applyLink = campaign_apply_link($campaign); ?>
  <style>
    .builder-shell{display:grid;grid-template-columns:minmax(0,1fr) 420px;gap:20px;align-items:start}
    .builder-hero{background:linear-gradient(135deg,#101827,#1D2A44 58%,#3A1C63);border-radius:18px;padding:26px 28px;color:#fff;box-shadow:0 20px 70px rgba(15,23,42,.22);margin-bottom:20px;overflow:hidden;position:relative}
    .builder-hero::after{content:'';position:absolute;inset:0;background:linear-gradient(90deg,rgba(255,255,255,.08) 1px,transparent 1px),linear-gradient(rgba(255,255,255,.06) 1px,transparent 1px);background-size:34px 34px;mask-image:linear-gradient(90deg,transparent,black 25%,black 75%,transparent);pointer-events:none}
    .builder-hero>*{position:relative}
    .builder-title{font-size:28px;font-weight:900;letter-spacing:-.7px;margin-bottom:6px}
    .builder-sub{font-size:14px;color:rgba(255,255,255,.72)}
    .builder-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}
    .builder-actions a,.builder-actions button{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.18);color:#fff;border-radius:10px;padding:9px 14px;font-size:13px;font-weight:700;display:inline-flex;align-items:center;gap:7px;cursor:pointer}
    .builder-actions a:hover,.builder-actions button:hover{background:#fff;color:#111827}
    .builder-link-card{background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:14px;display:flex;align-items:center;gap:10px;box-shadow:var(--card-shadow);margin-bottom:18px}
    .builder-link-card code{flex:1;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:10px 12px;color:#2563EB;word-break:break-all;font-size:13px}
    .builder-note{background:#EFF6FF;border:1px solid #BFDBFE;color:#1E3A8A;border-radius:14px;padding:14px 16px;margin-bottom:18px;display:flex;gap:12px;font-size:13px;line-height:1.55}
    .builder-note code{background:#DBEAFE;border-radius:6px;padding:2px 6px;color:#1D4ED8}
    .canvas-card,.builder-panel{background:#fff;border:1px solid rgba(15,23,42,.08);border-radius:16px;box-shadow:var(--card-shadow);overflow:hidden}
    .builder-panel{position:sticky;top:88px}
    .canvas-head,.panel-head{padding:18px 20px;border-bottom:1px solid #EEF2F7;display:flex;align-items:center;justify-content:space-between;gap:12px}
    .canvas-head h3,.panel-head h3{font-size:15px;font-weight:900;color:#0F172A;display:flex;align-items:center;gap:8px}
    .canvas-meta{font-size:12px;color:#64748B;font-weight:700}
    .field-list{padding:14px}
    .field-tile{border:1px solid #E5E7EB;background:#FFFFFF;border-radius:13px;padding:14px 14px;display:grid;grid-template-columns:24px 34px minmax(0,1fr) auto;gap:12px;align-items:center;margin-bottom:10px;transition:all .18s}
    .field-tile:hover{border-color:#A78BFA;box-shadow:0 10px 30px rgba(124,58,237,.12);transform:translateY(-1px)}
    .field-select{width:18px;height:18px;accent-color:#7C3AED}
    .bulk-field-actions{display:none;align-items:center;justify-content:space-between;gap:10px;margin:0 14px 12px;padding:11px 12px;background:#FEF2F2;border:1px solid #FECACA;border-radius:12px;color:#991B1B;font-size:13px;font-weight:800}
    .bulk-field-actions.active{display:flex}
    .field-order{width:34px;height:34px;border-radius:10px;background:#F3E8FF;color:#6B21A8;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:900}
    .field-name{font-size:14px;font-weight:850;color:#111827;margin-bottom:3px}
    .field-detail{font-size:12px;color:#64748B;display:flex;align-items:center;gap:7px;flex-wrap:wrap}
    .field-key{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:999px;padding:2px 7px;color:#2563EB}
    .type-pill{background:#F1F5F9;color:#475569;border-radius:999px;padding:2px 8px;font-weight:800;text-transform:capitalize}
    .req-pill{background:#DCFCE7;color:#166534;border-radius:999px;padding:2px 8px;font-weight:800}
    .empty-canvas{padding:42px 24px;text-align:center;color:#64748B}
    .empty-canvas i{font-size:34px;color:#A78BFA;margin-bottom:12px}
    .default-template-cta{padding:18px 20px 16px;border-bottom:1px solid #EEF2F7;background:linear-gradient(135deg,#FAF5FF,#EFF6FF)}
    .default-template-title{font-size:14px;font-weight:950;color:#111827;margin-bottom:5px;display:flex;align-items:center;gap:8px}
    .default-template-copy{font-size:12px;color:#475569;line-height:1.5;margin-bottom:12px}
    .quick-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;padding:16px 20px 4px}
    .quick-chip{border:1px solid #E5E7EB;background:#F8FAFC;color:#334155;border-radius:10px;padding:9px 10px;font-size:12px;font-weight:800;cursor:pointer;display:flex;align-items:center;gap:8px;text-align:left}
    .quick-chip:hover{border-color:#7C3AED;background:#F5F3FF;color:#6B21A8}
    .template-btn{width:100%;border:0;background:linear-gradient(135deg,#6D28D9,#2563EB);color:#fff;border-radius:14px;padding:15px 16px;font-size:15px;font-weight:950;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:9px;box-shadow:0 14px 32px rgba(37,99,235,.28)}
    .template-btn:hover{transform:translateY(-1px);box-shadow:0 18px 38px rgba(109,40,217,.32)}
    .panel-body{padding:18px 20px 20px}
    .panel-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .panel-grid-3{display:grid;grid-template-columns:1fr 110px 130px;gap:12px;align-items:end}
    .builder-panel .form-control{border-radius:11px;padding:11px 13px}
    .builder-panel .form-label{font-size:12px;text-transform:uppercase;letter-spacing:.35px;color:#475569}
    .required-toggle{height:44px;display:flex;align-items:center;gap:9px;border:1.5px solid var(--light);border-radius:11px;padding:0 12px;font-size:13px;font-weight:700;color:#334155;background:#fff}
    .builder-submit{width:100%;justify-content:center;padding:12px 18px;margin-top:4px}
    /* Standard fields toggle */
    .std-config-card{background:#fff;border:1px solid rgba(15,23,42,.08);border-radius:16px;box-shadow:var(--card-shadow);overflow:hidden;margin-bottom:20px}
    .std-config-head{padding:16px 20px;border-bottom:1px solid #EEF2F7;display:flex;align-items:center;justify-content:space-between;gap:12px;background:linear-gradient(135deg,#F0FDF4,#EFF6FF)}
    .std-config-head h3{font-size:15px;font-weight:900;color:#0F172A;display:flex;align-items:center;gap:8px;margin:0}
    .std-section{padding:14px 20px 2px}
    .std-section-label{font-size:11px;font-weight:800;color:#7C3AED;text-transform:uppercase;letter-spacing:.6px;margin-bottom:8px;display:flex;align-items:center;gap:6px}
    .std-fields-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px;margin-bottom:14px}
    .std-toggle{display:flex;align-items:flex-start;gap:0;cursor:pointer}
    .std-toggle input[type=checkbox]{display:none}
    .std-toggle-card{flex:1;border:1.5px solid #E2E8F0;border-radius:11px;padding:10px 12px;transition:all .15s;background:#FAFAFA;user-select:none}
    .std-toggle input:checked~.std-toggle-card{border-color:#7C3AED;background:#F5F3FF;box-shadow:0 0 0 3px rgba(124,58,237,.1)}
    .std-toggle-card:hover{border-color:#A78BFA;background:#FAF5FF}
    .std-toggle-name{font-size:13px;font-weight:700;color:#111827;display:flex;align-items:center;gap:6px;margin-bottom:3px}
    .std-toggle-name .tgl-check{width:16px;height:16px;border-radius:4px;border:2px solid #CBD5E1;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;font-size:10px;transition:all .15s}
    .std-toggle input:checked~.std-toggle-card .tgl-check{background:#7C3AED;border-color:#7C3AED;color:#fff}
    .std-toggle-meta{font-size:11px;color:#94A3B8;display:flex;align-items:center;gap:5px}
    .std-toggle-meta code{font-size:10px;background:#F1F5F9;border-radius:4px;padding:1px 5px;color:#2563EB}
    .std-save-bar{padding:14px 20px;border-top:1px solid #EEF2F7;display:flex;align-items:center;justify-content:space-between;gap:12px;background:#F8FAFC}
    .std-select-all{font-size:12px;font-weight:700;color:#7C3AED;cursor:pointer;background:none;border:none;padding:0}
    @media(max-width:1100px){.builder-shell{grid-template-columns:1fr}.builder-panel{position:static}.panel-grid-3{grid-template-columns:1fr}.quick-grid{grid-template-columns:1fr 1fr}}
    @media(max-width:620px){.builder-hero{padding:20px}.builder-title{font-size:22px}.builder-link-card{display:block}.builder-link-card code{display:block;margin-bottom:10px}.field-tile{grid-template-columns:24px 30px minmax(0,1fr)}.field-tile>a{grid-column:1/-1;justify-content:center}.quick-grid,.panel-grid-2{grid-template-columns:1fr}.std-fields-grid{grid-template-columns:1fr 1fr}}
  </style>

  <div class="builder-hero">
    <div class="builder-title">Apply Form Builder</div>
    <div class="builder-sub"><?= htmlspecialchars($campaign['name']) ?> · Design the candidate-facing application flow for this campaign.</div>
    <div class="builder-actions">
      <?php if (!empty($setup_state['ready_to_preview'])): ?>
        <a href="<?= htmlspecialchars($applyLink) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-eye"></i> Preview Apply Form</a>
      <?php else: ?>
        <button type="button" onclick="alert('Add at least one field before previewing the public apply form.')"><i class="fa-solid fa-eye-slash"></i> Preview Locked</button>
      <?php endif; ?>
      <a href="campaigns.php?action=questions&id=<?= $campaign_id ?>"><i class="fa-solid fa-microphone-lines"></i> Interview Questions</a>
      <a href="campaigns.php"><i class="fa-solid fa-arrow-left"></i> Back</a>
    </div>
  </div>

  <?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-success">✅ <?= htmlspecialchars(str_replace('_',' ',$_GET['msg'])) ?>!</div>
  <?php endif; ?>

  <div class="builder-link-card">
    <code><?= htmlspecialchars($applyLink) ?></code>
    <button type="button" onclick="copyCampaignLink(<?= htmlspecialchars(json_encode($applyLink), ENT_QUOTES, 'UTF-8') ?>)" class="btn-green"><i class="fa-solid fa-copy"></i> Copy Link</button>
  </div>

  <div class="builder-note">
    <i class="fa-solid fa-circle-info" style="margin-top:3px"></i>
    <div>Use keys like <code>name</code>, <code>phone</code>, <code>email</code>, <code>city</code>, <code>resume</code>, <code>photo</code>, <code>current_ctc</code>, <code>expected_ctc</code>, <code>linkedin</code>. A <code>phone</code> field is required for WhatsApp/interview outreach.</div>
  </div>

  <div class="builder-note" style="<?= !empty($setup_state['ready_to_preview']) ? 'border-color:#86EFAC;background:#F0FDF4;color:#14532D' : 'border-color:#FCD34D;background:#FFFBEB;color:#92400E' ?>">
    <i class="fa-solid <?= !empty($setup_state['ready_to_preview']) ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>" style="margin-top:3px"></i>
    <div>
      Journey: <strong><?= (int)$setup_state['percent'] ?>% complete</strong>.
      <?= !empty($setup_state['ready_to_preview']) ? 'Public preview is ready.' : 'Preview/share unlocks after this form has at least one active field.' ?>
    </div>
  </div>

  <?php
    // Build active-key lookup from current application_fields
    $_camp_cfg_raw   = $campaign['apply_form_config'] ?? null;
    $_camp_cfg       = $_camp_cfg_raw ? (json_decode($_camp_cfg_raw, true) ?: null) : null;
    $std_never_saved = ($_camp_cfg === null); // never saved via toggle = all ON by default
    $active_std_keys = $_camp_cfg ?? [];
    // Organize template fields into sections
    // Fields that are always shown regardless of config (required for core flow)
    $std_always_on = ['phone','email','role_applied','engagement_type','declaration_confirmation'];
    $std_sections = [
        'Personal Info'      => ['salutation','first_name','last_name','dob','city','relocate','relocate_time'],
        'Contact'            => ['phone','email'],           // phone_code/other_country_code are sub-fields, always shown
        'Education & Source' => ['college','source','role_applied'],  // *_other sub-fields shown conditionally
        'Work Experience'    => ['engagement_type','english_level','years_exp','industry','exp_type','exp_desc'],
        'Compensation'       => ['current_salary','expected_salary'],
        'Availability'       => ['tenure','joining_date','flex_hours'],
        'Work Readiness'     => ['laptop','internet','location','commute'],
        'Documents'          => ['resume','video_option','portfolio'], // video_link/video_file are sub-fields of video_option
        'Consent'            => ['ai_test_willing','declaration_confirmation'],
    ];
    // Build key→label/type map from legacy template
    $std_field_meta = [];
    foreach (legacy_application_template_fields() as $tf) {
        $std_field_meta[$tf[1]] = ['label'=>$tf[0],'type'=>$tf[2]];
    }
    $required_always = ['phone','email','first_name','last_name','salutation','dob','city','years_exp','exp_type','english_level','engagement_type','laptop','internet','commute','flex_hours','tenure','joining_date','resume','ai_test_willing','declaration_confirmation','college','source','role_applied','industry','phone_code','expected_salary'];
  ?>
  <div class="std-config-card">
    <div class="std-config-head">
      <h3><i class="fa-solid fa-sliders" style="color:#7C3AED"></i> Standard Fields Configuration</h3>
      <span style="font-size:12px;color:#64748B">Toggle which default fields appear on the public apply form</span>
    </div>
    <form method="POST" action="/campaigns?action=save_apply_form_config&id=<?= $campaign_id ?>">
      <?= csrf_input() ?>
      <?php foreach ($std_sections as $section_name => $section_keys): ?>
      <div class="std-section">
        <div class="std-section-label"><i class="fa-solid fa-circle-dot fa-xs"></i> <?= htmlspecialchars($section_name) ?></div>
        <div class="std-fields-grid">
          <?php foreach ($section_keys as $sk):
            $meta = $std_field_meta[$sk] ?? ['label'=>$sk,'type'=>'text'];
            $is_checked = $std_never_saved ? true : in_array($sk, $active_std_keys, true);
          ?>
          <?php $is_always = in_array($sk, $std_always_on, true); ?>
          <label class="std-toggle" <?= $is_always ? 'style="opacity:.65;cursor:not-allowed"' : '' ?>>
            <input type="checkbox" name="std_fields[]" value="<?= htmlspecialchars($sk) ?>"<?= ($is_checked || $is_always) ? ' checked' : '' ?><?= $is_always ? ' disabled' : '' ?>>
            <?php if ($is_always): ?><input type="hidden" name="std_fields[]" value="<?= htmlspecialchars($sk) ?>"><?php endif; ?>
            <div class="std-toggle-card">
              <div class="std-toggle-name">
                <span class="tgl-check">✓</span>
                <?= htmlspecialchars($meta['label']) ?>
                <?php if ($is_always): ?><span style="font-size:9px;background:#FEF3C7;color:#92400E;border-radius:4px;padding:1px 5px;font-weight:800;letter-spacing:.2px">ALWAYS ON</span><?php endif; ?>
              </div>
              <div class="std-toggle-meta">
                <code><?= htmlspecialchars($sk) ?></code>
                <span class="type-pill"><?= htmlspecialchars($meta['type']) ?></span>
              </div>
            </div>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <div class="std-save-bar">
        <div style="display:flex;gap:8px;align-items:center">
          <button type="button" class="std-select-all" onclick="stdToggleAll(true)">Select All</button>
          <span style="color:#CBD5E1">|</span>
          <button type="button" class="std-select-all" onclick="stdToggleAll(false)">Deselect All</button>
        </div>
        <button type="submit" class="btn-primary" style="padding:10px 22px"><i class="fa-solid fa-floppy-disk"></i> Save Field Configuration</button>
      </div>
    </form>
  </div>

  <div class="builder-shell">
    <div class="canvas-card">
      <div class="canvas-head">
        <h3><i class="fa-solid fa-diagram-project" style="color:#7C3AED"></i> Form Flow</h3>
        <div style="display:flex;align-items:center;gap:10px">
          <?php if (!empty($application_fields)): ?>
          <label class="canvas-meta" style="display:flex;align-items:center;gap:6px;cursor:pointer"><input type="checkbox" id="selectAllFields" onchange="toggleAllApplyFields(this)"> Select all</label>
          <?php endif; ?>
          <div class="canvas-meta"><?= count($application_fields) ?> fields</div>
        </div>
      </div>
      <?php if (!empty($application_fields)): ?>
      <div class="pager-top" style="padding:12px 18px 0">Show <?= pagination_per_page_select('field_per_page', 'field_page', $field_per_page) ?> fields</div>
      <?php endif; ?>
      <form method="POST" action="/campaigns?action=bulk_delete_application_fields&id=<?= $campaign_id ?>" onsubmit="return confirmBulkFieldDelete()">
        <?= csrf_input() ?>
        <div class="bulk-field-actions" id="bulkFieldActions">
          <span id="selectedFieldCount">0 fields selected</span>
          <button type="submit" class="btn-danger" style="font-size:12px;padding:7px 12px"><i class="fa-solid fa-trash-can"></i> Delete Selected</button>
        </div>
      <div class="field-list">
      <?php if (!empty($application_fields)): ?>
        <?php foreach ($application_field_page_rows as $f): $opts = json_decode($f['options_json'] ?? '[]', true) ?: []; ?>
        <div class="field-tile">
          <input type="checkbox" class="field-select" name="field_ids[]" value="<?= (int)$f['id'] ?>" onchange="updateBulkFieldActions()">
          <div class="field-order"><?= (int)$f['order_no'] ?></div>
          <div>
            <div class="field-name"><?= htmlspecialchars($f['field_label']) ?></div>
            <div class="field-detail">
              <span class="field-key"><?= htmlspecialchars($f['field_key']) ?></span>
              <span class="type-pill"><?= htmlspecialchars(str_replace('_', ' ', $f['field_type'])) ?></span>
              <?php if (!empty($f['is_required'])): ?><span class="req-pill">Required</span><?php endif; ?>
              <?php if (!empty($opts)): ?><span><?= htmlspecialchars(implode(', ', array_slice($opts, 0, 3))) ?><?= count($opts) > 3 ? '...' : '' ?></span><?php endif; ?>
            </div>
          </div>
          <a href="campaigns.php?action=delete_application_field&id=<?= $campaign_id ?>&fid=<?= $f['id'] ?>&csrf_token=<?= urlencode(csrf_token()) ?>" class="btn-danger" style="font-size:12px;padding:7px 12px" onclick="return confirm('Remove this application field?')"><i class="fa-solid fa-trash-can"></i></a>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="empty-canvas">
          <i class="fa-solid fa-layer-group"></i>
          <h3 style="font-size:17px;color:#0F172A;margin-bottom:4px">Start with a candidate detail</h3>
          <p>Add fields from the right panel. Name, phone, CV, photo and client-specific questions can all be configured per campaign.</p>
        </div>
      <?php endif; ?>
      </div>
      </form>
      <?php if (!empty($application_fields)): ?>
      <?= pagination_html('field_page', $field_page, $field_total_pages, $field_total, $field_per_page) ?>
      <?php endif; ?>
    </div>

    <div class="builder-panel">
      <div class="panel-head">
        <h3><i class="fa-solid fa-plus" style="color:#10B981"></i> Build Form</h3>
        <span class="canvas-meta">Default first</span>
      </div>
      <div class="default-template-cta">
        <div class="default-template-title"><i class="fa-solid fa-plus-circle" style="color:#10B981"></i> Add Campaign-Specific Custom Field</div>
        <div class="default-template-copy">Use the form below to add role-specific fields not in the standard set above (e.g. GitHub URL, certifications, target company).</div>
      </div>
      <div style="padding:14px 20px 0;font-size:12px;font-weight:900;color:#64748B;text-transform:uppercase;letter-spacing:.5px">Quick-add custom fields</div>
      <div class="quick-grid">
        <button type="button" class="quick-chip" onclick="presetField('Full Name','name','text','Candidate full name')"><i class="fa-solid fa-user"></i> Name</button>
        <button type="button" class="quick-chip" onclick="presetField('Phone','phone','phone','WhatsApp number')"><i class="fa-brands fa-whatsapp"></i> Phone</button>
        <button type="button" class="quick-chip" onclick="presetField('Email','email','email','Candidate email')"><i class="fa-solid fa-envelope"></i> Email</button>
        <button type="button" class="quick-chip" onclick="presetField('Upload CV','resume','file','Upload PDF or DOCX CV')"><i class="fa-solid fa-file-arrow-up"></i> CV</button>
        <button type="button" class="quick-chip" onclick="presetField('Photo','photo','file','Upload a recent photo')"><i class="fa-solid fa-image"></i> Photo</button>
        <button type="button" class="quick-chip" onclick="presetField('LinkedIn Profile','linkedin','url','https://linkedin.com/in/...')"><i class="fa-brands fa-linkedin"></i> LinkedIn</button>
      </div>
      <div class="panel-body">
        <form method="POST" action="/campaigns?action=add_application_field&id=<?= $campaign_id ?>">
          <?= csrf_input() ?>
          <div class="panel-grid-2">
            <div class="form-group">
              <label class="form-label">Field Label *</label>
              <input type="text" name="field_label" id="fieldLabel" class="form-control" placeholder="LinkedIn Profile" required>
            </div>
            <div class="form-group">
              <label class="form-label">Field Key</label>
              <input type="text" name="field_key" id="fieldKey" class="form-control" placeholder="Auto generated if blank">
            </div>
          </div>
          <div class="panel-grid-3">
            <div class="form-group">
              <label class="form-label">Field Type</label>
              <select name="field_type" id="fieldType" class="form-control">
                <option value="text">Short Text</option>
                <option value="textarea">Long Text</option>
                <option value="number">Numeric</option>
                <option value="decimal">Decimal</option>
                <option value="date">Date</option>
                <option value="dropdown">Dropdown</option>
                <option value="multi_select">Multi-select</option>
                <option value="checkbox">Checkbox</option>
                <option value="email">Email</option>
                <option value="phone">Phone</option>
                <option value="url">Hyperlink</option>
                <option value="file">File Upload</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Order</label>
              <input type="number" name="order_no" class="form-control" value="<?= count($application_fields)+1 ?>" min="1">
            </div>
            <div class="form-group">
              <label class="form-label">Required</label>
              <label class="required-toggle"><input type="checkbox" name="is_required" checked> Required</label>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Placeholder</label>
            <input type="text" name="placeholder" id="fieldPlaceholder" class="form-control" placeholder="What should candidate enter?">
          </div>
          <div class="form-group">
            <label class="form-label">Help Text</label>
            <input type="text" name="help_text" id="fieldHelp" class="form-control" placeholder="Small instruction shown below field">
          </div>
          <div class="form-group">
            <label class="form-label">Options</label>
            <textarea name="options_text" id="fieldOptions" class="form-control" rows="3" placeholder="One option per line for dropdown, multi-select, or checkbox"></textarea>
          </div>
          <button type="submit" class="btn-primary builder-submit"><i class="fa-solid fa-circle-plus"></i> Add Field to Flow</button>
        </form>
      </div>
    </div>
  </div>

<?php endif; ?>
</div>
<script>
async function copyCampaignLink(link) {
  try {
    await navigator.clipboard.writeText(link);
    alert('Campaign apply link copied');
  } catch (e) {
    prompt('Copy campaign apply link', link);
  }
}
function selectedApplyFieldCheckboxes() {
  return Array.from(document.querySelectorAll('.field-select:checked'));
}
function updateBulkFieldActions() {
  const selected = selectedApplyFieldCheckboxes();
  const bar = document.getElementById('bulkFieldActions');
  const count = document.getElementById('selectedFieldCount');
  const all = document.getElementById('selectAllFields');
  if (bar) bar.classList.toggle('active', selected.length > 0);
  if (count) count.textContent = selected.length + (selected.length === 1 ? ' field selected' : ' fields selected');
  if (all) {
    const boxes = document.querySelectorAll('.field-select');
    all.checked = boxes.length > 0 && selected.length === boxes.length;
    all.indeterminate = selected.length > 0 && selected.length < boxes.length;
  }
}
function toggleAllApplyFields(source) {
  document.querySelectorAll('.field-select').forEach(box => { box.checked = source.checked; });
  updateBulkFieldActions();
}
function confirmBulkFieldDelete() {
  const selected = selectedApplyFieldCheckboxes();
  if (!selected.length) {
    alert('Please select at least one apply form field to delete.');
    return false;
  }
  return confirm('Delete ' + selected.length + ' selected apply form field(s)?');
}
function questionTypeNeedsChoices(type) {
  return ['dropdown', 'multi_select', 'rating'].includes(type);
}
function syncQuestionTypeUI() {
  const type = document.querySelector('select[name="question_type"]')?.value || 'textarea';
  const box = document.getElementById('mcqBox');
  const options = document.querySelector('textarea[name="options_text"]');
  if (!box || !options) return;
  const showChoices = questionTypeNeedsChoices(type);
  box.classList.toggle('active', showChoices);
  options.required = showChoices;
  if (!showChoices) options.value = '';
}
function setQuestionOptions(value) {
  const options = document.querySelector('textarea[name="options_text"]');
  if (!options) return;
  options.value = value;
  options.focus();
}
function extractInlineChoices(text) {
  const idx = String(text || '').search(/choices\s*:/i);
  if (idx < 0) return [];
  const raw = String(text).slice(idx).replace(/^choices\s*:\s*/i, '').trim();
  const found = [];
  const re = /(?:^|\s)(?:[A-Z]|\d+)[).]\s*(.*?)(?=\s+(?:[A-Z]|\d+)[).]\s*|$)/g;
  let m;
  while ((m = re.exec(raw)) !== null) {
    const option = (m[1] || '').trim();
    if (option) found.push(option);
  }
  return found;
}
function autoFillInlineChoices() {
  const type = document.querySelector('select[name="question_type"]')?.value || 'textarea';
  if (!questionTypeNeedsChoices(type)) return;
  const q = document.querySelector('textarea[name="question_text"]');
  const options = document.querySelector('textarea[name="options_text"]');
  if (!q || !options || options.value.trim()) return;
  const found = extractInlineChoices(q.value);
  if (found.length) options.value = found.join('\n');
}
function validateQuestionForm(form) {
  const type = form.querySelector('select[name="question_type"]')?.value || 'textarea';
  const options = form.querySelector('textarea[name="options_text"]');
  if (questionTypeNeedsChoices(type) && !options.value.trim()) {
    alert('Please add MCQ/rating choices. Add one option per line.');
    options.focus();
    return false;
  }
  return true;
}
function presetField(label, key, type, placeholder) {
  const labelEl = document.getElementById('fieldLabel');
  const keyEl = document.getElementById('fieldKey');
  const typeEl = document.getElementById('fieldType');
  const placeholderEl = document.getElementById('fieldPlaceholder');
  const helpEl = document.getElementById('fieldHelp');
  if (!labelEl) return;
  labelEl.value = label;
  keyEl.value = key;
  typeEl.value = type;
  placeholderEl.value = placeholder || '';
  helpEl.value = type === 'file' ? 'Candidate can upload this file during application.' : '';
  labelEl.focus();
}
function assistIdealAnswer() {
  const q = document.querySelector('textarea[name="question_text"]');
  const hint = document.querySelector('textarea[name="ideal_answer_hint"]');
  const type = document.querySelector('select[name="question_type"]')?.value || 'textarea';
  if (!hint) return;
  const question = (q?.value || '').trim();
  const base = question
    ? `Strong answer should directly address: "${question}". Look for clarity, specific examples, structured reasoning, role relevance, and measurable outcomes.`
    : 'Strong answer should include clear reasoning, specific examples, relevant experience, confidence, and measurable outcomes.';
  if (type === 'audio' || type === 'video') {
    hint.value = base + ' Also assess communication clarity, confidence, and whether the answer can be summarized from the transcript.';
  } else if (type === 'file') {
    hint.value = 'Review uploaded file for relevance, completeness, authenticity, and alignment with the campaign requirements.';
  } else {
    hint.value = base;
  }
  hint.focus();
}
function fillOptionSuggestion() {
  const type = document.querySelector('select[name="question_type"]')?.value || '';
  const options = document.querySelector('textarea[name="options_text"]');
  if (!options) return;
  if (type === 'rating') {
    options.value = '1 - Poor\n2 - Fair\n3 - Good\n4 - Very Good\n5 - Excellent';
  } else if (type === 'dropdown' || type === 'multi_select') {
    options.value = 'Yes\nNo\nMaybe';
  } else {
    options.value = 'Options are only needed for dropdown, multi-select, rating, or checkbox questions.';
  }
  options.focus();
  options.select();
}
function openAddModal(){
  const ov=document.getElementById('aqOverlay');
  if(!ov)return;
  ov.classList.add('open');
  document.body.style.overflow='hidden';
  setTimeout(()=>ov.querySelector('textarea[name="question_text"]')?.focus(),120);
}
function closeAddModal(){
  const ov=document.getElementById('aqOverlay');
  if(ov)ov.classList.remove('open');
  document.body.style.overflow='';
}
document.addEventListener('DOMContentLoaded', () => {
  syncQuestionTypeUI();
  document.addEventListener('keydown',function(e){if(e.key==='Escape')closeAddModal();});
});
function stdToggleAll(state) {
  document.querySelectorAll('.std-toggle input[type=checkbox]').forEach(cb => { cb.checked = state; });
}
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
