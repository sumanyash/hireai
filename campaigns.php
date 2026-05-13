<?php
require_once __DIR__ . '/includes/auth_check.php';

$action      = $_GET['action'] ?? 'list';
$campaign_id = (int)($_GET['id'] ?? 0);

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
    $has_apply = count($application_fields) > 0;
    $has_questions = count($questions) > 0;
    $weight = array_sum(array_map('intval', array_column($questions, 'weight')));
    $has_scoring = $has_questions && $weight === 100;
    $has_integration = !empty($campaign['integration_type']) && $campaign['integration_type'] !== 'none' && !empty($campaign['integration_endpoint']);
    $steps = [
        ['label' => 'Campaign details', 'done' => $has_details],
        ['label' => 'AI voice agent (optional)', 'done' => true],
        ['label' => 'Apply form', 'done' => $has_apply],
        ['label' => 'Interview questions', 'done' => $has_questions],
        ['label' => 'Scoring weight 100%', 'done' => $has_scoring],
        ['label' => 'CRM / Sheet connection', 'done' => $has_integration],
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
        'ready_to_activate' => $has_details && $has_apply && $has_questions && $has_scoring,
        'integration_pending' => !$has_integration,
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
        $start_date = trim($_POST['start_date'] ?? '') ?: null;
        $end_date = trim($_POST['end_date'] ?? '') ?: null;
        if ($start_date && $start_date < $today) {
            header("Location: campaigns.php?action=new&msg=start_date_past"); exit;
        }
        if ($start_date && $end_date && $end_date < $start_date) {
            header("Location: campaigns.php?action=new&msg=end_before_start"); exit;
        }
        if (campaign_duplicate_exists($user['org_id'], $_POST['name'] ?? '', $_POST['job_role'] ?? '')) {
            header("Location: campaigns.php?action=new&msg=duplicate_campaign"); exit;
        }
        $integration_type = $_POST['integration_type'] ?? 'none';
        if (!in_array($integration_type, ['none','crm','google_sheet'], true)) $integration_type = 'none';
        $id = db_insert(
            "INSERT INTO campaigns (org_id,created_by,name,job_role,description,share_token,start_date,end_date,integration_type,integration_endpoint,el_agent_id,passing_score,num_questions,language,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'draft')",
            [$user['org_id'],$user['user_id'],$_POST['name'],$_POST['job_role'],$_POST['description'],$share_token,$start_date,$end_date,$integration_type,trim($_POST['integration_endpoint'] ?? ''),trim($_POST['el_agent_id'] ?? ''),(int)$_POST['passing_score'],(int)$_POST['num_questions'],$_POST['language']],
            'iisssssssssiis'
        );
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
        $parameter_label = trim($_POST['parameter_label'] ?? '');
        $parameter = trim($_POST['parameter'] ?? '');
        if ($parameter === '' || $parameter === 'custom') $parameter = field_key_from_label($parameter_label);
        db_insert(
            "INSERT INTO questions (campaign_id,parameter,parameter_label,weight,max_marks,question_text,ideal_answer_hint,question_type,options_json,branch_rules_json,is_required,order_no) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
            [$campaign_id,$parameter,$parameter_label,(int)$_POST['weight'],(int)$_POST['max_marks'],$_POST['question_text'],$_POST['ideal_answer_hint'],$question_type,$options_json,$branch_rules_json,$is_required,(int)$_POST['order_no']],
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
        $parameter_label = trim($_POST['parameter_label'] ?? '');
        $parameter = trim($_POST['parameter'] ?? '');
        if ($parameter === '' || $parameter === 'custom') $parameter = field_key_from_label($parameter_label);
        $campaign_exists = db_fetch_one("SELECT id FROM campaigns WHERE id=? AND org_id=?", [$campaign_id,$user['org_id']], 'ii');
        if ($campaign_exists && $qid) {
            db_execute(
                "UPDATE questions SET parameter=?,parameter_label=?,weight=?,max_marks=?,question_text=?,ideal_answer_hint=?,question_type=?,options_json=?,branch_rules_json=?,is_required=?,order_no=? WHERE id=? AND campaign_id=?",
                [$parameter,$parameter_label,(int)$_POST['weight'],(int)$_POST['max_marks'],$_POST['question_text'],$_POST['ideal_answer_hint'],$question_type,$options_json,$branch_rules_json,$is_required,(int)$_POST['order_no'],$qid,$campaign_id],
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

// ─── DATA ────────────────────────────────────────────────────────
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
     ORDER BY ca.created_at DESC",
    [$user['org_id']], 'i'
);
$campaign  = $campaign_id ? db_fetch_one("SELECT ca.*, u.name AS creator_name, u.email AS creator_email FROM campaigns ca LEFT JOIN users u ON ca.created_by=u.id WHERE ca.id=? AND ca.org_id=?", [$campaign_id,$user['org_id']], 'ii') : null;
$questions = $campaign_id ? db_fetch_all("SELECT * FROM questions WHERE campaign_id=? ORDER BY order_no", [$campaign_id], 'i') : [];
$application_fields = $campaign_id ? db_fetch_all("SELECT * FROM application_fields WHERE campaign_id=? AND is_active=1 ORDER BY order_no,id", [$campaign_id], 'i') : [];
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
    .campaign-actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
    .campaign-actions .btn-sm,.campaign-actions .btn-green{white-space:nowrap}
    @media(max-width:900px){.campaign-table-wrap{overflow-x:auto}.campaign-actions{min-width:520px}}
  </style>
  <div class="page-header" style="display:flex;justify-content:space-between;align-items:center">
    <div><h2>Campaigns</h2><p>Manage all hiring campaigns</p></div>
    <a href="campaigns.php?action=new" class="btn-primary">+ New Campaign</a>
  </div>
  <?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-success">✅ Campaign <?= htmlspecialchars(str_replace('_',' ',$_GET['msg'])) ?>!</div>
  <?php endif; ?>
  <div class="card campaign-table-wrap">
    <table class="table">
      <thead><tr><th>Campaign</th><th>Created By</th><th>Job Role</th><th>AI Agent</th><th>Candidates</th><th>Pass Score</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($campaigns as $c): ?>
        <tr>
          <?php $applyLink = campaign_apply_link($c); ?>
          <td><strong><?= htmlspecialchars($c['name']) ?></strong><br><small style="color:#8892A4"><?= date('d M Y', strtotime($c['created_at'])) ?></small></td>
          <td><strong><?= htmlspecialchars($c['creator_name'] ?: 'Unknown') ?></strong><br><small style="color:#8892A4">Audit enabled</small></td>
          <td><?= htmlspecialchars($c['job_role']) ?></td>
          <td><small style="font-family:monospace;color:#0066FF"><?= $c['el_agent_id'] ? substr($c['el_agent_id'],0,20).'...' : '<span style="color:#dc3545">Not set</span>' ?></small></td>
          <td><?= $c['total_cands'] ?></td>
          <td><?= $c['passing_score'] ?>/100</td>
          <td><span class="badge badge-<?= $c['status'] ?>"><?= ucfirst($c['status']) ?></span></td>
          <td class="campaign-actions">
            <a href="campaigns.php?action=edit&id=<?= $c['id'] ?>" class="btn-sm">✏️ Edit</a>
            <a href="campaigns.php?action=apply_form&id=<?= $c['id'] ?>" class="btn-sm">Apply Form</a>
            <a href="campaigns.php?action=questions&id=<?= $c['id'] ?>" class="btn-sm">Questions</a>
            <a href="candidates.php?campaign_id=<?= $c['id'] ?>" class="btn-sm">Leads</a>
            <?php if ((int)$c['apply_field_count'] > 0): ?>
            <button type="button" class="btn-sm" onclick="copyCampaignLink(<?= htmlspecialchars(json_encode($applyLink), ENT_QUOTES, 'UTF-8') ?>)">Copy Link</button>
            <a href="https://wa.me/?text=<?= urlencode('Apply here: ' . $applyLink) ?>" target="_blank" rel="noopener" class="btn-sm" style="color:#16A34A;border-color:#16A34A40;background:#16A34A10">WhatsApp</a>
            <?php else: ?>
            <a href="campaigns.php?action=apply_form&id=<?= $c['id'] ?>" class="btn-sm" style="color:#B45309;border-color:#F59E0B55;background:#FEF3C7">Form Pending</a>
            <?php endif; ?>
            <?php if ($c['status'] !== 'active'): ?>
              <form method="POST" action="campaigns.php?action=activate&id=<?= $c['id'] ?>" style="display:inline">
                <?= csrf_input() ?>
                <button type="submit" class="btn-green" style="padding:5px 12px;font-size:13px">▶ Activate</button>
              </form>
            <?php endif; ?>
            <a href="campaigns.php?action=delete_campaign&id=<?= $c['id'] ?>&csrf_token=<?= urlencode(csrf_token()) ?>" class="btn-danger" style="padding:5px 12px;font-size:13px;text-decoration:none" onclick="return confirm('Delete this campaign and all mapped candidates/interview data? This cannot be undone.')">Delete</a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($campaigns)): ?>
        <tr><td colspan="8" style="text-align:center;padding:32px;color:#8892A4">No campaigns yet. <a href="campaigns.php?action=new">Create your first →</a></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($action === 'new' || ($action === 'edit' && $campaign)): ?>
  <?php $is_edit = ($action === 'edit'); ?>
  <div class="page-header" style="display:flex;justify-content:space-between;align-items:center">
    <div><h2><?= $is_edit ? 'Edit Campaign' : 'New Campaign' ?></h2><p><?= $is_edit ? htmlspecialchars($campaign['name']) : 'Set up a new hiring campaign' ?></p></div>
    <a href="campaigns.php" class="btn-sm">← Back</a>
  </div>
  <?php if (!empty($_GET['msg'])): ?>
    <div class="alert <?= in_array($_GET['msg'], ['start_date_past','end_before_start'], true) ? 'alert-error' : 'alert-success' ?>">
      <?= $_GET['msg'] === 'start_date_past' ? 'Start date cannot be in the past. Please choose today or a future date.' : ($_GET['msg'] === 'end_before_start' ? 'End date must be after the start date.' : ($_GET['msg'] === 'duplicate_campaign' ? 'A campaign with the same name and job role already exists.' : htmlspecialchars(str_replace('_',' ',$_GET['msg'])))) ?>
    </div>
  <?php endif; ?>
  <div class="card" style="max-width:720px">
    <form method="POST" action="campaigns.php?action=<?= $is_edit ? 'edit_save' : 'save' ?><?= $is_edit ? '&id='.$campaign_id : '' ?>">
      <?= csrf_input() ?>
      <div class="alert alert-info" style="margin-bottom:18px">
        <i class="fa-solid fa-user-shield"></i>
        Creator: <strong><?= htmlspecialchars($is_edit ? ($campaign['creator_name'] ?: $user['name']) : $user['name']) ?></strong>. This name is stored for reporting and audit logs.
      </div>
      <div class="alert alert-info" style="margin-bottom:18px;align-items:flex-start">
        <i class="fa-solid fa-route" style="margin-top:2px"></i>
        <div>
          Guided setup: 1. Save campaign details, 2. Add apply form fields, 3. Add MCQ/video/audio questions, 4. Check 100% scoring weight, 5. Activate and share.
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="form-group">
          <label class="form-label">Campaign Name *</label>
          <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($campaign['name'] ?? '') ?>" placeholder="AI Developer Batch 1" required>
        </div>
        <div class="form-group">
          <label class="form-label">Job Role *</label>
          <input type="text" name="job_role" class="form-control" value="<?= htmlspecialchars($campaign['job_role'] ?? '') ?>" placeholder="AI Developer" required>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-control"><?= htmlspecialchars($campaign['description'] ?? '') ?></textarea>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="form-group">
          <label class="form-label">Start Date</label>
          <input type="date" name="start_date" class="form-control" min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($campaign['start_date'] ?? '') ?>">
          <small style="color:#64748B">Today or future date only.</small>
        </div>
        <div class="form-group">
          <label class="form-label">End Date</label>
          <input type="date" name="end_date" class="form-control" min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($campaign['end_date'] ?? '') ?>">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">AI Voice Agent
          <span id="agent-loading" style="color:#8892A4;font-size:12px;margin-left:8px">Loading agents...</span>
        </label>
        <select name="el_agent_id" id="agent-select" class="form-control">
          <option value="">No AI voice agent for now</option>
          <?php if (!empty($campaign['el_agent_id'])): ?>
            <option value="<?= htmlspecialchars($campaign['el_agent_id']) ?>" selected><?= htmlspecialchars($campaign['el_agent_id']) ?></option>
          <?php endif; ?>
        </select>
        <small style="color:#8892A4">Optional. Select this only when you want outbound AI voice calling for the campaign.</small>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
          <a class="btn-sm" href="credits.php" target="_blank" rel="noopener"><i class="fa-solid fa-coins"></i> Recharge / Balance</a>
          <a class="btn-sm" href="credits.php#pricing" target="_blank" rel="noopener"><i class="fa-solid fa-tags"></i> AI Pricing Portal</a>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Where should applications sync?</label>
        <select name="integration_type" class="form-control" id="integrationType" onchange="updateIntegrationHelp()">
          <option value="none" <?= ($campaign['integration_type'] ?? 'none') === 'none' ? 'selected' : '' ?>>Decide later</option>
          <option value="crm" <?= ($campaign['integration_type'] ?? '') === 'crm' ? 'selected' : '' ?>>CRM/API connection</option>
          <option value="google_sheet" <?= ($campaign['integration_type'] ?? '') === 'google_sheet' ? 'selected' : '' ?>>Google Sheet GID/Webhook</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Connection URL / Sheet GID</label>
        <input type="text" name="integration_endpoint" id="integrationEndpoint" class="form-control" value="<?= htmlspecialchars($campaign['integration_endpoint'] ?? '') ?>" placeholder="Paste CRM webhook URL or Google Sheet GID">
        <small id="integrationHelp" style="color:#64748B">This can be completed later, but the campaign journey will show it as pending.</small>
        <details style="margin-top:10px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:10px 12px">
          <summary style="cursor:pointer;font-weight:800;color:#334155">Google Sheet setup guide</summary>
          <ol style="margin:10px 0 0 18px;color:#64748B;font-size:13px;line-height:1.7">
            <li>Create a Google Sheet with columns like Name, Phone, Email, Campaign, Status.</li>
            <li>Use Apps Script or your CRM webhook to accept JSON submissions.</li>
            <li>Paste the Apps Script webhook URL here. A plain Sheet GID can be saved for tracking, but webhook URL is required for automatic sync.</li>
          </ol>
        </details>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px">
        <div class="form-group">
          <label class="form-label">Passing Score (/100)</label>
          <input type="number" name="passing_score" class="form-control" value="<?= $campaign['passing_score'] ?? 70 ?>" min="0" max="100">
        </div>
        <div class="form-group">
          <label class="form-label">No. of Questions</label>
          <input type="number" name="num_questions" class="form-control" value="<?= $campaign['num_questions'] ?? 6 ?>" min="1" max="20">
        </div>
        <div class="form-group">
          <label class="form-label">Language</label>
          <select name="language" class="form-control">
            <option value="english" <?= ($campaign['language']??'english')==='english'?'selected':'' ?>>English</option>
            <option value="hinglish" <?= ($campaign['language']??'')==='hinglish'?'selected':'' ?>>Hinglish</option>
            <option value="hindi" <?= ($campaign['language']??'')==='hindi'?'selected':'' ?>>Hindi</option>
          </select>
        </div>
      </div>
      <button type="submit" class="btn-primary"><?= $is_edit ? '💾 Save Changes' : 'Save & Add Questions →' ?></button>
    </form>
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
  function updateIntegrationHelp() {
      const type = document.getElementById('integrationType')?.value || 'none';
      const help = document.getElementById('integrationHelp');
      const endpoint = document.getElementById('integrationEndpoint');
      if (!help || !endpoint) return;
      if (type === 'crm') {
          help.textContent = 'Paste CRM webhook/API endpoint. Candidate applications can be pushed here after submit.';
          endpoint.placeholder = 'https://crm.example.com/webhook/hireai';
      } else if (type === 'google_sheet') {
          help.textContent = 'Paste Google Sheet GID or Apps Script webhook URL for sheet sync.';
          endpoint.placeholder = 'Sheet GID or Apps Script webhook URL';
      } else {
          help.textContent = 'This can be completed later, but the campaign journey will show it as pending.';
          endpoint.placeholder = 'Paste CRM webhook URL or Google Sheet GID';
      }
  }
  loadAgents();
  updateIntegrationHelp();
  </script>

<?php elseif ($action === 'questions' && $campaign): ?>
  <?php $applyLink = campaign_apply_link($campaign); ?>
  <?php $total_weight = $setup_state['weight'] ?? array_sum(array_column($questions, 'weight')); ?>
  <?php $canPreview = !empty($setup_state['ready_to_preview']); ?>
  <?php $topic_presets = question_topic_presets($campaign['job_role'] ?? ''); ?>
  <div class="page-header" style="display:flex;justify-content:space-between;align-items:center">
    <div>
      <h2><?= htmlspecialchars($campaign['name']) ?></h2>
      <p>
        Role: <strong><?= htmlspecialchars($campaign['job_role']) ?></strong> |
        Agent: <code style="font-size:12px;color:#0066FF"><?= htmlspecialchars($campaign['el_agent_id'] ?: 'Not set') ?></code> |
        Pass: <?= $campaign['passing_score'] ?>/100
      </p>
    </div>
    <div style="display:flex;gap:8px">
      <?php if ($canPreview): ?>
      <button type="button" onclick="copyCampaignLink(<?= htmlspecialchars(json_encode($applyLink), ENT_QUOTES, 'UTF-8') ?>)" class="btn-green">Copy Apply Link</button>
      <a href="https://wa.me/?text=<?= urlencode('Apply here: ' . $applyLink) ?>" target="_blank" rel="noopener" class="btn-sm" style="color:#16A34A;border-color:#16A34A40;background:#16A34A10">Share WA</a>
      <?php else: ?>
      <a href="campaigns.php?action=apply_form&id=<?= $campaign_id ?>" class="btn-sm" style="color:#B45309;border-color:#F59E0B55;background:#FEF3C7">Configure Apply Form</a>
      <?php endif; ?>
      <a href="campaigns.php?action=apply_form&id=<?= $campaign_id ?>" class="btn-sm">Apply Form</a>
      <a href="campaigns.php?action=edit&id=<?= $campaign_id ?>" class="btn-sm">✏️ Edit</a>
      <a href="campaigns.php" class="btn-sm">← Back</a>
    </div>
  </div>

  <div class="card" style="padding:16px 18px">
    <div style="font-size:12px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">Public Apply Link</div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <code style="flex:1;min-width:260px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;padding:9px 12px;color:#2563EB;word-break:break-all"><?= htmlspecialchars($applyLink) ?></code>
      <?php if ($canPreview): ?>
        <a class="btn-sm" href="<?= htmlspecialchars($applyLink) ?>" target="_blank" rel="noopener">Preview</a>
      <?php else: ?>
        <a class="btn-sm" href="campaigns.php?action=apply_form&id=<?= $campaign_id ?>">Add fields first</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($_GET['msg']) && $_GET['msg'] !== 'setup_incomplete'): ?>
    <?php $question_errors = ['options_required' => 'Add choices for MCQ/rating questions before saving.']; ?>
    <div class="alert <?= isset($question_errors[$_GET['msg']]) ? 'alert-error' : 'alert-success' ?>">
      <?= isset($question_errors[$_GET['msg']]) ? '⚠️ ' . $question_errors[$_GET['msg']] : '✅ ' . htmlspecialchars(str_replace('_',' ',$_GET['msg'])) . '!' ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($_GET['msg']) && $_GET['msg'] === 'setup_incomplete'): ?>
  <div class="alert alert-error">⚠️ Campaign cannot be activated yet. Complete details, apply form, questions, and total scoring weight 100%.</div>
  <?php endif; ?>

  <?php if (!$campaign['el_agent_id'] || $campaign['el_agent_id'] === 'PASTE_YOUR_EL_AGENT_ID'): ?>
  <div class="alert alert-info">AI voice agent is optional. Add one only if this campaign should trigger outbound AI calls.</div>
  <?php endif; ?>

  <style>
    .journey-grid{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:18px;align-items:start}
    .journey-card{background:#fff;border:1px solid #E5E7EB;border-radius:14px;box-shadow:var(--card-shadow);padding:18px;position:sticky;top:88px}
    .journey-ring{height:9px;background:#E5E7EB;border-radius:999px;overflow:hidden;margin:10px 0 16px}
    .journey-ring span{display:block;height:100%;background:linear-gradient(90deg,#6B21A8,#0EA5E9);border-radius:999px}
    .journey-step{display:flex;align-items:center;gap:9px;font-size:13px;color:#475569;padding:7px 0;border-bottom:1px solid #F1F5F9}
    .journey-step i{width:18px;text-align:center;color:#CBD5E1}
    .journey-step.done{color:#14532D;font-weight:700}
    .journey-step.done i{color:#16A34A}
    .summary-stat{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:14px 0}
    .summary-stat div{background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:10px}
    .summary-stat strong{display:block;font-size:18px;color:#0F172A}
    .simple-question-form{border:1px solid #E5E7EB;border-radius:16px;overflow:hidden}
    .simple-question-help{background:#F8FAFC;border-bottom:1px solid #E5E7EB;padding:14px 18px;color:#475569;font-size:13px;line-height:1.5}
    .simple-question-help strong{color:#0F172A}
    .helper-text{display:block;color:#64748B;font-size:12px;line-height:1.45;margin-top:6px}
    .mcq-box{display:none;background:#FFFBEB;border:1px solid #FCD34D;border-radius:12px;padding:14px;margin-bottom:18px}
    .mcq-box.active{display:block}
    .mcq-presets{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}
    .mcq-presets button{border:1px solid #BFDBFE;background:#EFF6FF;color:#1D4ED8;border-radius:999px;padding:6px 10px;font-size:12px;font-weight:800;cursor:pointer}
    .mcq-presets button:hover{background:#DBEAFE}
    .advanced-question-block{display:none}
    @media(max-width:980px){.journey-grid{grid-template-columns:1fr}.journey-card{position:static}}
  </style>
  <div class="journey-grid">
  <div>
  <!-- Existing Questions -->
  <?php if (!empty($questions)): ?>
  <div class="card">
    <div class="card-header">
      <h3>Interview Questions (<?= count($questions) ?>)</h3>
      <span style="font-size:13px;color:<?= $total_weight==100?'#00C896':'#dc3545' ?>">
        Total Weight: <strong><?= $total_weight ?>%</strong>
        <?= $total_weight==100 ? '✅' : '⚠️ Must be 100%' ?>
      </span>
    </div>
    <table class="table">
      <thead><tr><th>#</th><th>Parameter</th><th>Type</th><th>Weight</th><th>Max Marks</th><th>Question</th><th>Logic</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($questions as $q): ?>
        <tr>
          <td><?= $q['order_no'] ?></td>
          <td><strong><?= htmlspecialchars($q['parameter_label']) ?></strong><br><small style="color:#8892A4"><?= htmlspecialchars($q['parameter']) ?></small></td>
          <td><span class="badge badge-draft"><?= htmlspecialchars(str_replace('_', ' ', $q['question_type'] ?? 'textarea')) ?></span></td>
          <td><strong><?= $q['weight'] ?>%</strong></td>
          <td><?= $q['max_marks'] ?></td>
          <td style="max-width:280px;font-size:13px"><?= htmlspecialchars($q['question_text']) ?></td>
          <td style="font-size:12px;color:#64748B">
            <?= !empty($q['branch_rules_json']) ? 'Branching' : 'Linear' ?>
          </td>
          <td style="display:flex;gap:6px;align-items:center">
            <a href="campaigns.php?action=questions&id=<?= $campaign_id ?>&edit_qid=<?= $q['id'] ?>" class="btn-sm" style="font-size:12px;padding:6px 9px;text-decoration:none"><i class="fa-solid fa-pen"></i> Edit</a>
            <a href="campaigns.php?action=delete_question&id=<?= $campaign_id ?>&qid=<?= $q['id'] ?>&csrf_token=<?= urlencode(csrf_token()) ?>" class="btn-danger" style="font-size:12px" onclick="return confirm('Delete?')">🗑</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Add/Edit Question -->
  <div class="card simple-question-form">
    <div class="card-header">
      <h3><?= $editing_question ? 'Edit Interview Question' : 'Add Interview Question' ?></h3>
      <?php if ($editing_question): ?><a href="campaigns.php?action=questions&id=<?= $campaign_id ?>" class="btn-sm" style="text-decoration:none">Cancel edit</a><?php endif; ?>
    </div>
    <div class="simple-question-help">
      <strong>Admin simple mode:</strong> choose skill/topic, answer type, write question, add choices only for MCQ/rating, then save.
    </div>
    <form method="POST" action="campaigns.php?action=<?= $editing_question ? 'edit_question' : 'add_question' ?>&id=<?= $campaign_id ?>" onsubmit="return validateQuestionForm(this)">
      <?= csrf_input() ?>
      <?php if ($editing_question): ?><input type="hidden" name="question_id" value="<?= (int)$editing_question['id'] ?>"><?php endif; ?>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="form-group">
          <label class="form-label">Skill / Topic</label>
          <select name="parameter" class="form-control" onchange="setParameterLabel(this)">
            <?php
              $preset_keys = array_column($topic_presets, 0);
              if ($editing_question && !in_array($editing_question['parameter'], $preset_keys, true)):
            ?>
            <option value="<?= htmlspecialchars($editing_question['parameter']) ?>" data-label="<?= htmlspecialchars($editing_question['parameter_label']) ?>" selected><?= htmlspecialchars($editing_question['parameter_label']) ?></option>
            <?php endif; ?>
            <?php foreach ($topic_presets as [$topic_key, $topic_label]): ?>
            <option value="<?= htmlspecialchars($topic_key) ?>" data-label="<?= htmlspecialchars($topic_label) ?>" <?= $editing_question && $editing_question['parameter'] === $topic_key ? 'selected' : '' ?>><?= htmlspecialchars($topic_label) ?></option>
            <?php endforeach; ?>
          </select>
          <small class="helper-text">Suggestions change by campaign role. Choose Custom Topic for anything else.</small>
        </div>
        <div class="form-group">
          <label class="form-label">Report Label *</label>
          <input type="text" name="parameter_label" class="form-control" placeholder="English Communication Skills" value="<?= htmlspecialchars($editing_question['parameter_label'] ?? '') ?>" required>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="form-group">
          <label class="form-label">Answer Type</label>
          <?php $selected_qtype = $editing_question['question_type'] ?? 'textarea'; ?>
          <select name="question_type" class="form-control" onchange="syncQuestionTypeUI()">
            <option value="textarea" <?= $selected_qtype === 'textarea' ? 'selected' : '' ?>>Long Text / Interview Answer</option>
            <option value="text" <?= $selected_qtype === 'text' ? 'selected' : '' ?>>Short Text</option>
            <option value="number" <?= $selected_qtype === 'number' ? 'selected' : '' ?>>Numeric</option>
            <option value="decimal" <?= $selected_qtype === 'decimal' ? 'selected' : '' ?>>Decimal</option>
            <option value="date" <?= $selected_qtype === 'date' ? 'selected' : '' ?>>Date</option>
            <option value="dropdown" <?= $selected_qtype === 'dropdown' ? 'selected' : '' ?>>MCQ - Single Choice</option>
            <option value="multi_select" <?= $selected_qtype === 'multi_select' ? 'selected' : '' ?>>MCQ - Multiple Choice</option>
            <option value="rating" <?= $selected_qtype === 'rating' ? 'selected' : '' ?>>Rating</option>
            <option value="file" <?= $selected_qtype === 'file' ? 'selected' : '' ?>>Upload Section</option>
            <option value="audio" <?= $selected_qtype === 'audio' ? 'selected' : '' ?>>Record Audio</option>
            <option value="video" <?= $selected_qtype === 'video' ? 'selected' : '' ?>>Record Video</option>
            <option value="hyperlink" <?= $selected_qtype === 'hyperlink' ? 'selected' : '' ?>>Hyperlink</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Mandatory?</label>
          <label style="display:flex;align-items:center;gap:8px;padding:11px 0;font-size:14px">
            <input type="checkbox" name="is_required" <?= (!$editing_question || !empty($editing_question['is_required'])) ? 'checked' : '' ?>> Candidate must answer this question
          </label>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px">
        <div class="form-group">
          <label class="form-label">Weight (%)</label>
          <input type="number" name="weight" class="form-control" value="<?= htmlspecialchars($editing_question['weight'] ?? 15) ?>" min="1" max="100" required>
        </div>
        <div class="form-group">
          <label class="form-label">Max Marks</label>
          <input type="number" name="max_marks" class="form-control" value="<?= htmlspecialchars($editing_question['max_marks'] ?? 15) ?>" min="1" required>
        </div>
        <div class="form-group">
          <label class="form-label">Order</label>
          <input type="number" name="order_no" class="form-control" value="<?= htmlspecialchars($editing_question['order_no'] ?? (count($questions)+1)) ?>">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Question Text *</label>
        <textarea name="question_text" class="form-control" rows="3" placeholder="Write only the question here. Put MCQ choices in the choices box below." required onblur="autoFillInlineChoices()"><?= htmlspecialchars($editing_question['question_text'] ?? '') ?></textarea>
        <small class="helper-text">Example: Which Python library is used for OpenAI API calls?</small>
      </div>
      <div class="form-group">
        <label class="form-label" style="display:flex;justify-content:space-between;align-items:center;gap:10px">
          <span>Suggested Reply / AI Scoring Criteria</span>
          <button type="button" class="btn-sm" onclick="assistIdealAnswer()" style="padding:5px 10px"><i class="fa-solid fa-wand-magic-sparkles"></i> AI Assist</button>
        </label>
        <textarea name="ideal_answer_hint" class="form-control" rows="2" placeholder="Keywords or criteria AI should look for..."><?= htmlspecialchars($editing_question['ideal_answer_hint'] ?? '') ?></textarea>
      </div>
      <div class="form-group mcq-box" id="mcqBox">
        <label class="form-label" style="display:flex;justify-content:space-between;align-items:center;gap:10px">
          <span>MCQ / Rating Choices *</span>
          <button type="button" class="btn-sm" onclick="fillOptionSuggestion()" style="padding:5px 10px">Guide me</button>
        </label>
        <div class="mcq-presets">
          <button type="button" onclick="setQuestionOptions('Yes\nNo')">Yes / No</button>
          <button type="button" onclick="setQuestionOptions('Beginner\nIntermediate\nAdvanced')">Skill Level</button>
          <button type="button" onclick="setQuestionOptions('1 - Poor\n2 - Fair\n3 - Good\n4 - Very Good\n5 - Excellent')">5-point Rating</button>
        </div>
        <textarea name="options_text" class="form-control" rows="4" placeholder="One option per line&#10;Example:&#10;pandas&#10;openai&#10;flask&#10;numpy"><?= htmlspecialchars($editing_options_text) ?></textarea>
        <small class="helper-text">These choices will be shown as dropdown/checkboxes to the candidate.</small>
      </div>
      <div class="form-group advanced-question-block">
        <details>
          <summary style="cursor:pointer;font-weight:800;color:#334155">Advanced branching rules</summary>
          <label class="form-label" style="margin-top:12px">Branching rules JSON</label>
          <textarea name="branch_rules_json" class="form-control" rows="4" placeholder='Example: [{"when":"yes","jump_to_order":5},{"when":"no","skip_to_order":8}]'><?= htmlspecialchars($editing_question['branch_rules_json'] ?? '') ?></textarea>
          <small style="color:#8892A4">Optional. This is for advanced users only. Leave blank for normal step-by-step flow.</small>
        </details>
      </div>
      <button type="submit" class="btn-primary"><?= $editing_question ? 'Save Question Changes' : '+ Add Question' ?></button>
    </form>
  </div>
  </div>
  <aside class="journey-card">
    <h3 style="font-size:16px;font-weight:900;color:#0F172A;margin-bottom:4px">Campaign Journey</h3>
    <p style="font-size:13px;color:#64748B">Complete each block to move from setup to publish.</p>
    <div class="journey-ring"><span style="width:<?= (int)$setup_state['percent'] ?>%"></span></div>
    <div style="font-size:13px;font-weight:800;color:#334155;margin-bottom:8px"><?= (int)$setup_state['percent'] ?>% complete</div>
    <?php foreach ($setup_state['steps'] as $step): ?>
      <div class="journey-step <?= $step['done'] ? 'done' : '' ?>">
        <i class="fa-solid <?= $step['done'] ? 'fa-circle-check' : 'fa-circle' ?>"></i>
        <span><?= htmlspecialchars($step['label']) ?></span>
      </div>
    <?php endforeach; ?>
    <div class="summary-stat">
      <div><strong><?= count($questions) ?></strong><span>Questions</span></div>
      <div><strong><?= count($application_fields) ?></strong><span>Apply fields</span></div>
      <div><strong><?= (int)$setup_state['weight'] ?>%</strong><span>Weight used</span></div>
      <div><strong><?= (int)$setup_state['remaining_weight'] ?>%</strong><span>Remaining</span></div>
    </div>
    <?php if ($setup_state['integration_pending']): ?>
      <div class="alert alert-info" style="margin:0 0 12px;padding:11px;font-size:13px">DB connection pending: choose CRM/API or Google Sheet on the campaign edit page.</div>
    <?php endif; ?>
    <div class="alert alert-info" style="margin:0;padding:11px;font-size:13px">Audio/video answers are stored as recordings/transcripts and then summarized during scoring for candidate filtering.</div>
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
    @media(max-width:1100px){.builder-shell{grid-template-columns:1fr}.builder-panel{position:static}.panel-grid-3{grid-template-columns:1fr}.quick-grid{grid-template-columns:1fr 1fr}}
    @media(max-width:620px){.builder-hero{padding:20px}.builder-title{font-size:22px}.builder-link-card{display:block}.builder-link-card code{display:block;margin-bottom:10px}.field-tile{grid-template-columns:24px 30px minmax(0,1fr)}.field-tile>a{grid-column:1/-1;justify-content:center}.quick-grid,.panel-grid-2{grid-template-columns:1fr}}
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
      <?= !empty($setup_state['integration_pending']) ? ' DB connection is still pending: select CRM/API or Google Sheet on Edit Campaign.' : '' ?>
    </div>
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
      <form method="POST" action="campaigns.php?action=bulk_delete_application_fields&id=<?= $campaign_id ?>" onsubmit="return confirmBulkFieldDelete()">
        <?= csrf_input() ?>
        <div class="bulk-field-actions" id="bulkFieldActions">
          <span id="selectedFieldCount">0 fields selected</span>
          <button type="submit" class="btn-danger" style="font-size:12px;padding:7px 12px"><i class="fa-solid fa-trash-can"></i> Delete Selected</button>
        </div>
      <div class="field-list">
      <?php if (!empty($application_fields)): ?>
        <?php foreach ($application_fields as $f): $opts = json_decode($f['options_json'] ?? '[]', true) ?: []; ?>
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
    </div>

    <div class="builder-panel">
      <div class="panel-head">
        <h3><i class="fa-solid fa-plus" style="color:#10B981"></i> Build Form</h3>
        <span class="canvas-meta">Default first</span>
      </div>
      <div class="default-template-cta">
        <div class="default-template-title"><i class="fa-solid fa-wand-magic-sparkles" style="color:#6D28D9"></i> Step 1: Add Complete Apply Form</div>
        <div class="default-template-copy">Start with the full original application form. After that, add or remove fields as per this campaign.</div>
        <form method="POST" action="campaigns.php?action=add_application_template&id=<?= $campaign_id ?>" onsubmit="return confirm('Add the complete default application form to this campaign? Existing matching fields will be skipped.')">
          <?= csrf_input() ?>
          <button type="submit" class="template-btn"><i class="fa-solid fa-circle-plus"></i> Add Complete Apply Form</button>
        </form>
      </div>
      <div style="padding:14px 20px 0;font-size:12px;font-weight:900;color:#64748B;text-transform:uppercase;letter-spacing:.5px">Then add custom fields if needed</div>
      <div class="quick-grid">
        <button type="button" class="quick-chip" onclick="presetField('Full Name','name','text','Candidate full name')"><i class="fa-solid fa-user"></i> Name</button>
        <button type="button" class="quick-chip" onclick="presetField('Phone','phone','phone','WhatsApp number')"><i class="fa-brands fa-whatsapp"></i> Phone</button>
        <button type="button" class="quick-chip" onclick="presetField('Email','email','email','Candidate email')"><i class="fa-solid fa-envelope"></i> Email</button>
        <button type="button" class="quick-chip" onclick="presetField('Upload CV','resume','file','Upload PDF or DOCX CV')"><i class="fa-solid fa-file-arrow-up"></i> CV</button>
        <button type="button" class="quick-chip" onclick="presetField('Photo','photo','file','Upload a recent photo')"><i class="fa-solid fa-image"></i> Photo</button>
        <button type="button" class="quick-chip" onclick="presetField('LinkedIn Profile','linkedin','url','https://linkedin.com/in/...')"><i class="fa-brands fa-linkedin"></i> LinkedIn</button>
      </div>
      <div class="panel-body">
        <form method="POST" action="campaigns.php?action=add_application_field&id=<?= $campaign_id ?>">
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
function setParameterLabel(select) {
  const label = select?.options?.[select.selectedIndex]?.dataset?.label || '';
  const input = document.querySelector('input[name="parameter_label"]');
  if (input && label) input.value = label;
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
document.addEventListener('DOMContentLoaded', () => {
  const parameter = document.querySelector('select[name="parameter"]');
  if (parameter) setParameterLabel(parameter);
  syncQuestionTypeUI();
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
