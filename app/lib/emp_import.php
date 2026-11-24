<?php
declare(strict_types=1);

/*
 * Employee bulk upload: download an Excel template (empty or pre-filled), fill it, upload it,
 * review a preview, confirm. Every change goes through the audit log / employee change log.
 */
const EMP_SHEET = 'الموظفين';
const EMP_COLS = [
    'id'            => 'كود الموظف',
    'name_ar'       => 'الاسم بالعربي',
    'name_en'       => 'الاسم بالإنجليزي (من برنامج البصمة)',
    'department'    => 'القسم',
    'specialization' => 'التخصص',
    'job_title'     => 'المسمى الوظيفي',
    'coordinator'   => 'Coordinator Employee ID',
    'hire_date'     => 'تاريخ التعيين',
    'end_date'      => 'تاريخ نهاية الخدمة',
    'exempt'        => 'معفى يدويًا من البصمة؟ (نعم/لا)',
    'nationality'   => 'الجنسية',
    'phone'         => 'رقم التليفون',
    'pay_method'    => 'طريقة القبض (كاش/فيزا)',
    'bank'          => 'البنك',
    'papers'        => 'استلم أوراقه بعد ترك الخدمة؟ (نعم/لا)',
    'summer'        => 'خصم الصيف',
    'first_year'    => 'خصم السنة الأولى',
    'first_raise'   => 'شهر أول علاوة مستحقة',
    'fy_exception'  => 'استثناء خصم السنة الأولى',
    'basic_salary'  => 'الراتب الأساسي',
    'salary_from'   => 'تاريخ بدء الراتب',
    'role'          => 'الصلاحية',
];
/** Headers of templates downloaded before the columns were renamed; still read. */
const EMP_COL_ALIASES = ['name_en' => 'الاسم بالإنجليزي', 'exempt' => 'معفى من البصمة (نعم/لا)'];
/** Fixed values (same as the employee form). */
const EMP_SUMMER = ['ربع', 'ربعين'];
const EMP_FIRST_YEAR = ['شهر واحد', 'شهرين'];
const EMP_FY_EXCEPTION = ['1', '2', '3', '4'];
const EMP_NATIONALITIES = ['مصري', 'سوداني', 'سوري', 'أردني', 'فلسطيني', 'سعودي', 'ليبي', 'عراقي', 'يمني', 'لبناني', 'تونسي', 'مغربي', 'جزائري', 'بريطاني', 'أمريكي', 'كندي', 'أخرى'];
/** Shown when a cell of that column is selected. */
const EMP_NOTES = [
    'id'           => 'سيبه فاضي لموظف جديد عشان يتولد تلقائيًا. لو الكود موجود، بيانات الموظف بتتحدث.',
    'name_ar'      => 'إلزامي لموظف جديد.',
    'name_en'      => 'زي ما هو مكتوب في برنامج البصمة.',
    'specialization' => 'بيتختار من القائمة. التخصصات بتتضاف من صفحة «التخصصات».',
    'coordinator'  => 'كود الموظف المنسق (Employee ID) مش الاسم. لازم يكون موظف نشط ومش نفس الموظف. فاضي = المنسق الحالي يفضل زي ما هو. اكتب - عشان تشيل المنسق.',
    'hire_date'    => 'مثلًا 2026-10-01 أو 1/10/2026.',
    'end_date'     => 'سيبه فاضي لو الموظف لسه شغال.',
    'phone'        => 'بالصفر في الأول، مثلًا 01012345678.',
    'bank'         => 'بيتكتب بس لو طريقة القبض فيزا. البنوك بتتضاف من صفحة «البنوك».',
    'summer'       => 'فاضي = حسب القسم. ربع = يوليو. ربعين = يوليو + أغسطس.',
    'first_year'   => 'شهر واحد = يوليو. شهرين = يوليو + أغسطس.',
    'first_raise'  => 'أي يوم في الشهر، مثلًا 2027-09-01.',
    'fy_exception' => '1: نص يوليو بلا رد. 2: نص يوليو + نص أغسطس بلا رد. 3: ربع يوليو. 4: ربع يوليو + ربع أغسطس.',
    'basic_salary' => 'لو اتغير، القيمة القديمة بتتقفل يوم قبل تاريخ بدء الراتب.',
    'salary_from'  => 'الافتراضي أول الشهر الحالي.',
    'role'         => 'لو مكتوبة، بيتعمل للموظف حساب دخول أو بتتغير صلاحيته. للأدمن بس.',
];

function emp_import_dir(): string
{
    $d = config('storage_dir') . '/tmp';
    if (!is_dir($d)) mkdir($d, 0770, true);
    return $d;
}

function current_basic(int $empId): ?array
{
    return q_one("SELECT t.* FROM salary_tx t JOIN salary_tx_types ty ON ty.id = t.type_id WHERE t.employee_id = ? AND ty.category = 'basic'
                  AND t.date_from <= CURDATE() AND (t.date_to IS NULL OR t.date_to >= CURDATE()) ORDER BY t.date_from DESC, t.id DESC LIMIT 1", [$empId]);
}

/** Builds the template. $filled = include the employees the user may see. */
function emp_template_file(bool $filled): string
{
    $withSalary = can('salary', 'view') && can('salary', 'export');
    $rows = [array_values(EMP_COLS)];
    if ($filled) {
        [$w, $p] = scope_sql('e');
        $roles = [];
        foreach (q_all('SELECT u.employee_id, r.name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.employee_id IS NOT NULL') as $r) $roles[$r['employee_id']] = $r['name'];
        foreach (q_all("SELECT e.*, d.name dept, s.name spec, j.name job, b.name bank FROM employees e LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN job_titles j ON j.id = e.job_title_id
                        LEFT JOIN specializations s ON s.id = e.specialization_id
                        LEFT JOIN banks b ON b.id = e.bank_id WHERE $w ORDER BY e.id", $p) as $e) {
            $b = $withSalary ? current_basic((int)$e['id']) : null;
            $v = ['id' => (string)$e['id'], 'name_ar' => $e['name_ar'], 'name_en' => $e['name_en'], 'department' => $e['dept'], 'specialization' => $e['spec'], 'job_title' => $e['job'],
                  'coordinator' => $e['coordinator_id'] !== null ? (string)$e['coordinator_id'] : '',
                  'hire_date' => $e['hire_date'], 'end_date' => $e['end_date'], 'exempt' => $e['attendance_exempt'] ? 'نعم' : 'لا', 'nationality' => $e['nationality'],
                  'phone' => $e['phone'], 'pay_method' => ['cash' => 'كاش', 'visa' => 'فيزا'][$e['pay_method'] ?? ''] ?? '', 'bank' => $e['pay_method'] === 'visa' ? $e['bank'] : '', 'papers' => $e['papers_received'] ? 'نعم' : 'لا',
                  'summer' => $e['summer_deduction'], 'first_year' => $e['first_year_deduction'], 'first_raise' => $e['first_raise_month'] ? substr($e['first_raise_month'], 0, 10) : '',
                  'fy_exception' => $e['first_year_exception'], 'basic_salary' => $b ? (float)$b['amount'] : '', 'salary_from' => $b ? $b['date_from'] : '',
                  'role' => is_manager() ? ($roles[$e['id']] ?? '') : ''];
            $rows[] = array_map(fn($k) => $v[$k] ?? '', array_keys(EMP_COLS));
        }
    }
    $nats = EMP_NATIONALITIES;
    foreach (q_all("SELECT DISTINCT nationality FROM employees WHERE nationality IS NOT NULL AND nationality <> '' ORDER BY nationality") as $n) {
        if (!in_array($n['nationality'], $nats, true)) array_splice($nats, -1, 0, [$n['nationality']]); // before «أخرى»
    }
    // column on the lists sheet => [title, values, employees-sheet columns that pick from it]
    $choices = [
        ['الأقسام', array_values(fk_options(['fk' => ['departments', 'name']])), ['department']],
        ['المسميات الوظيفية', array_values(fk_options(['fk' => ['job_titles', 'name']])), ['job_title']],
        ['التخصصات', array_values(fk_options(['fk' => ['specializations', 'name']])), ['specialization']],
        ['الصلاحيات', array_column(q_all('SELECT name FROM roles WHERE active = 1 AND is_super = 0 ORDER BY is_staff, name'), 'name'), ['role']],
        ['نعم/لا', ['نعم', 'لا'], ['exempt', 'papers']],
        ['طريقة القبض', ['كاش', 'فيزا'], ['pay_method']],
        ['البنوك', array_column(q_all('SELECT name FROM banks WHERE active = 1 ORDER BY name'), 'name'), ['bank']],
        ['الجنسية', $nats, ['nationality']],
        ['خصم الصيف', EMP_SUMMER, ['summer']],
        ['خصم السنة الأولى', EMP_FIRST_YEAR, ['first_year']],
        ['استثناء خصم السنة الأولى', EMP_FY_EXCEPTION, ['fy_exception']],
    ];
    $lists = [array_column($choices, 0)];
    for ($i = 0; $i < max(array_map(fn($c) => count($c[1]), $choices)); $i++) $lists[] = array_map(fn($c) => $c[1][$i] ?? '', $choices);
    // dropdowns on the employees sheet, fed by the lists sheet, so a typo can't get in
    $last = count($rows) + 1000;
    $col = fn(string $k) => chr(65 + array_search($k, array_keys(EMP_COLS), true));
    $ls = "'" . XlsxWriter::sheetName('القوائم') . "'!";
    $rules = []; $ruled = [];
    foreach ($choices as $li => $c) {
        [, $vals, $keys] = $c;
        $free = !empty($c[3]); // suggestions only: any other value is accepted
        if (!$vals) continue;
        $L = chr(65 + $li);
        foreach ($keys as $k) {
            $rules[] = ['ref' => $col($k) . '2:' . $col($k) . $last, 'list' => $ls . '$' . $L . '$2:$' . $L . '$' . (count($vals) + 1), 'title' => mb_substr(EMP_COLS[$k], 0, 32),
                        'error' => __('اختار من القائمة بس. لو القيمة مش موجودة، ضيفها في النظام الأول ونزّل القالب تاني.')] + ($free ? ['free' => true] : []) + (isset(EMP_NOTES[$k]) ? ['prompt' => EMP_NOTES[$k]] : []);
            $ruled[$k] = true;
        }
    }
    $A = $col('id');
    $rules[] = ['ref' => "{$A}2:{$A}$last", 'custom' => "AND(ISNUMBER(--{$A}2),--{$A}2>=1,INT(--{$A}2)=--{$A}2,COUNTIF(\${$A}\$2:\${$A}\$$last,{$A}2)=1)",
                'title' => EMP_COLS['id'], 'error' => __('كود الموظف لازم يكون رقم صحيح ومايتكررش في الملف.'), 'prompt' => EMP_NOTES['id']];
    $ruled['id'] = true;
    $C = $col('coordinator');
    $rules[] = ['ref' => "{$C}2:{$C}$last", 'custom' => "OR({$C}2=\"-\",AND(ISNUMBER(--{$C}2),--{$C}2>=1,INT(--{$C}2)=--{$C}2,--{$C}2<>--{$A}2))",
                'title' => EMP_COLS['coordinator'], 'error' => __('اكتب كود الموظف المنسق (رقم) مش الاسم، ومينفعش يكون نفس كود الموظف. اكتب - عشان تشيل المنسق.'), 'prompt' => EMP_NOTES['coordinator']];
    $ruled['coordinator'] = true;
    foreach (EMP_NOTES as $k => $note) if (!isset($ruled[$k])) $rules[] = ['ref' => $col($k) . '2:' . $col($k) . $last, 'title' => mb_substr(EMP_COLS[$k], 0, 32), 'prompt' => $note];
    $help = [['تعليمات'],
        ['كل صف = موظف. "الاسم بالعربي" إلزامي.'],
        ['كود الموظف: سيبه فاضي لموظف جديد عشان يتولد تلقائيًا. لو الكود موجود، بيانات الموظف بتتحدث.'],
        ['القسم والتخصص والمسمى والصلاحية والجنسية ونعم/لا وطريقة القبض والبنك وخانات الخصم بتتختار من القائمة المنسدلة في الخانة (جاية من شيت "القوائم").'],
        ['لو قسم أو تخصص أو مسمى مش موجود في القائمة، ضيفه في النظام الأول ونزّل القالب تاني.'],
        ['البنك: بيتكتب بس لو طريقة القبض فيزا. لو البنك مش في القائمة، الأدمن يضيفه من صفحة "البنوك" وتنزّل القالب تاني.'],
        ['لو هتنسخ بيانات من ملف تاني، الصق "قيم فقط" (Paste Values) عشان القوائم ماتتمسحش.'],
        ['التواريخ بصيغة 2026-10-01 (أو تاريخ Excel عادي). شهر أول علاوة: اكتب أي يوم في الشهر.'],
        ['خصم الصيف: فاضي = حسب القسم، ربع = يوليو، ربعين = يوليو + أغسطس. خصم السنة الأولى: شهر واحد = يوليو، شهرين = يوليو + أغسطس.'],
        ['استثناء خصم السنة الأولى: 1 = نص يوليو بلا رد، 2 = نص يوليو + نص أغسطس بلا رد، 3 = ربع يوليو، 4 = ربع يوليو + ربع أغسطس.'],
        ['حط الماوس على أي خانة عشان تشوف شرحها.'],
        ['الراتب الأساسي: لو اتغير، القيمة القديمة بتتقفل يوم قبل "تاريخ بدء الراتب" (الافتراضي أول الشهر الحالي) وبتتسجل الجديدة.'],
        ['الصلاحية: لو مكتوبة، بيتعمل للموظف حساب دخول (اسم المستخدم = الكود) أو بتتغير صلاحية حسابه. للسوبر أدمن بس.'],
        ['Coordinator Employee ID: كود الموظف المنسق (مش اسمه). لازم يكون موظف موجود ونشط، ومينفعش الموظف يكون منسق لنفسه. فاضي = المنسق الحالي يفضل زي ما هو، و - = شيل المنسق.'],
        ['خلية فاضية = سيب القيمة الحالية زي ما هي.'],
    ];
    $x = new XlsxWriter();
    $x->addSheet(EMP_SHEET, $rows, [12, 26, 30, 18, 18, 18, 16, 14, 16, 20, 14, 16, 18, 20, 22, 12, 16, 16, 18, 14, 16, 18], $rules);
    $x->addSheet('القوائم', $lists, [22, 22, 22, 22, 10, 14, 22, 14, 12, 16, 22]);
    $x->addSheet('تعليمات', $help, [110]);
    $file = tempnam(emp_import_dir(), 'tpl');
    $x->save($file);
    return $file;
}

/** @return string|null|false  date, empty, or invalid */
function xl_date($v): string|null|false
{
    if ($v === '' || $v === null) return null;
    if (is_int($v) || is_float($v)) return ($v > 20000 && $v < 80000) ? gmdate('Y-m-d', (int)round(($v - 25569) * 86400)) : false;
    $v = trim((string)$v);
    if (valid_date($v)) return $v;
    if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{4})$#', $v, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    return false;
}

/** Parses + validates an uploaded workbook. Returns ['rows' => normalized changes, 'errors' => [rowNo => msgs], 'stats' => ...]. */
function emp_import_parse(string $file): array
{
    $xr = new XlsxReader($file);
    if (!in_array(EMP_SHEET, $xr->sheetNames(), true)) return ['fatal' => __('الملف لازم يكون فيه شيت اسمه "%s" (نزّل القالب واستخدمه).', EMP_SHEET)];
    $raw = $xr->rows(EMP_SHEET);
    if (!$raw) return ['fatal' => __('الشيت فاضي.')];
    $head = array_map(fn($h) => trim((string)$h), array_shift($raw));
    $idx = [];
    foreach (EMP_COLS as $k => $label) {
        $i = array_search($label, $head, true);
        if ($i === false && isset(EMP_COL_ALIASES[$k])) $i = array_search(EMP_COL_ALIASES[$k], $head, true);
        if ($i !== false) $idx[$k] = $i;
    }
    if (!isset($idx['name_ar'])) return ['fatal' => __('عمود "الاسم بالعربي" مش موجود. استخدم القالب من غير ما تغيّر العناوين.')];
    if (count($raw) > 5000) return ['fatal' => __('أقصى عدد 5000 صف في المرة.')];

    $depts = array_change_key_case(array_flip(fk_options(['fk' => ['departments', 'name']])));
    $jobs = array_change_key_case(array_flip(fk_options(['fk' => ['job_titles', 'name']])));
    $specs = array_change_key_case(array_flip(fk_options(['fk' => ['specializations', 'name']])));
    $banks = [];
    foreach (q_all('SELECT id, name FROM banks WHERE active = 1') as $b) $banks[mb_strtolower(trim($b['name']))] = (int)$b['id'];
    $roles = [];
    foreach (q_all('SELECT id, name, is_staff FROM roles WHERE active = 1 AND is_super = 0') as $r) $roles[mb_strtolower($r['name'])] = $r;
    $scope = dept_scope();
    $canSalary = can('salary', 'create');
    $out = []; $errors = []; $warnings = []; $seen = [];
    // A coordinator whose own row in this file ends his service is checked against that new end date.
    $fileEnd = [];
    if (isset($idx['coordinator'], $idx['end_date'], $idx['id'])) {
        foreach ($raw as $r) {
            $rid = trim((string)($r[$idx['id']] ?? ''));
            $d = xl_date($r[$idx['end_date']] ?? '');
            if (preg_match('/^\d{1,9}(\.0+)?$/', $rid) && is_string($d)) $fileEnd[(int)$rid] = $d;
        }
    }
    $stats = ['new' => 0, 'update' => 0, 'same' => 0, 'salary' => 0, 'accounts' => 0];

    foreach ($raw as $n => $r) {
        $rowNo = $n + 2;
        $get = function (string $k) use ($r, $idx) { $v = isset($idx[$k]) ? ($r[$idx[$k]] ?? '') : ''; return is_string($v) ? trim($v) : $v; };
        if (count(array_filter(array_map(fn($k) => $get($k), array_keys(EMP_COLS)), fn($v) => $v !== '' && $v !== null)) === 0) continue;
        $err = [];
        $idRaw = $get('id');
        $id = null;
        if ($idRaw !== '') {
            if (!preg_match('/^\d{1,9}(\.0+)?$/', (string)$idRaw) || (int)$idRaw < 1) $err[] = __('كود الموظف لازم يكون رقم صحيح.');
            else $id = (int)$idRaw;
        }
        if ($id !== null && isset($seen[$id])) $err[] = __('الكود %d متكرر في الملف (صف %d).', $id, $seen[$id]);
        if ($id !== null) $seen[$id] = $rowNo;
        $existing = $id !== null ? q_one('SELECT * FROM employees WHERE id = ?', [$id]) : null;
        if ($existing && !can_see_employee($id)) { $errors[$rowNo] = [__('الموظف ده خارج نطاق صلاحياتك.')]; continue; }
        if ($existing && !can('employees', 'update')) $err[] = __('معندكش صلاحية تعديل الموظفين.');
        if (!$existing && !can('employees', 'create')) $err[] = __('معندكش صلاحية إضافة موظفين.');

        $f = [];
        $name = (string)$get('name_ar');
        if ($name === '' && !$existing) $err[] = __('الاسم بالعربي إلزامي.');
        if ($name !== '') $f['name_ar'] = mb_substr($name, 0, 150);
        foreach (['name_en' => 150, 'nationality' => 80] as $k => $max) if ((string)$get($k) !== '') $f[$k] = mb_substr((string)$get($k), 0, $max);
        $phone = $get('phone');
        if ($phone !== '') {
            $phone = is_float($phone) || is_int($phone) ? '0' . (string)(int)$phone : (string)$phone;
            if (!preg_match('/^[0-9+\- ]{6,30}$/', $phone)) $err[] = __('رقم التليفون غير صحيح.'); else $f['phone'] = $phone;
        }
        foreach (['department' => [$depts, 'department_id', 'القسم'], 'specialization' => [$specs, 'specialization_id', 'التخصص'], 'job_title' => [$jobs, 'job_title_id', 'المسمى الوظيفي']] as $k => [$map, $col, $label]) {
            $v = (string)$get($k);
            if ($v === '') continue;
            if (!isset($map[mb_strtolower($v)])) $err[] = __('%s "%s" مش موجود في القوائم.', __($label), $v);
            else $f[$col] = (int)$map[mb_strtolower($v)];
        }
        $deptAfter = $f['department_id'] ?? ($existing['department_id'] ?? null);
        if ($scope !== null && !in_array((int)$deptAfter, $scope, true)) $err[] = __('لازم القسم يكون من الأقسام المسموحة لك.');
        foreach (['hire_date', 'end_date'] as $k) {
            $d = xl_date($get($k));
            if ($d === false) $err[] = __('%s تاريخ غير صحيح.', __(EMP_COLS[$k])); elseif ($d) $f[$k] = $d;
        }
        $ex = (string)$get('exempt');
        if ($ex !== '') { if (!in_array($ex, ['نعم', 'لا'], true)) $err[] = __('معفى من البصمة: اكتب نعم أو لا.'); else $f['attendance_exempt'] = $ex === 'نعم' ? 1 : 0; }
        $pp = (string)$get('papers');
        if ($pp !== '') { if (!in_array($pp, ['نعم', 'لا'], true)) $err[] = __('استلم أوراقه: اكتب نعم أو لا.'); else $f['papers_received'] = $pp === 'نعم' ? 1 : 0; }
        foreach (['summer' => [EMP_SUMMER, 'summer_deduction'], 'first_year' => [EMP_FIRST_YEAR, 'first_year_deduction'], 'fy_exception' => [EMP_FY_EXCEPTION, 'first_year_exception']] as $k => [$allowed, $c]) {
            $v = $get($k);
            $v = is_float($v) || is_int($v) ? (string)(int)$v : (string)$v;
            if ($v === '') continue;
            if (!in_array($v, $allowed, true)) $err[] = __('%s: القيم المسموحة %s.', __(EMP_COLS[$k]), implode(' / ', $allowed)); else $f[$c] = $v;
        }
        $fr = $get('first_raise');
        if (is_string($fr) && preg_match('/^(\d{4})-(\d{1,2})$/', $fr, $m)) $fr = sprintf('%04d-%02d-01', $m[1], $m[2]);
        $fr = xl_date($fr);
        if ($fr === false) $err[] = __('%s تاريخ غير صحيح.', __(EMP_COLS['first_raise'])); elseif ($fr) $f['first_raise_month'] = substr($fr, 0, 8) . '01';
        $pm = (string)$get('pay_method');
        if ($pm !== '') { $pmv = ['كاش' => 'cash', 'فيزا' => 'visa'][$pm] ?? null; if (!$pmv) $err[] = __('طريقة القبض: كاش أو فيزا.'); else $f['pay_method'] = $pmv; }
        $bank = (string)$get('bank');
        $payAfter = $f['pay_method'] ?? ($existing['pay_method'] ?? null);
        if ($bank !== '') {
            if ($payAfter !== 'visa') $err[] = __('البنك بيتكتب بس لو طريقة القبض فيزا.');
            elseif (!isset($banks[mb_strtolower($bank)])) $err[] = __('البنك "%s" مش موجود في قائمة البنوك.', $bank);
            else $f['bank_id'] = $banks[mb_strtolower($bank)];
        } elseif ($payAfter !== 'visa' && !empty($existing['bank_id'])) {
            $f['bank_id'] = null; // moved to cash: the bank no longer applies
        }

        $salary = null;
        $sal = $get('basic_salary');
        if ($sal !== '') {
            if (!$canSalary) $warnings[$rowNo][] = __('الراتب اتجاهل: معندكش صلاحية إضافة حركات مرتب.');
            elseif (!is_numeric($sal) || (float)$sal < 0 || (float)$sal > 10000000) $err[] = __('الراتب الأساسي لازم يكون رقم موجب.');
            else {
                $from = xl_date($get('salary_from'));
                if ($from === false) $err[] = __('تاريخ بدء الراتب غير صحيح.');
                else {
                    $from = $from ?: date('Y-m-01');
                    $cur = $existing ? current_basic($id) : null;
                    if (!$cur || abs((float)$cur['amount'] - (float)$sal) > 0.004) {
                        if ($cur && $from <= $cur['date_from']) $err[] = __('تاريخ بدء الراتب الجديد لازم يكون بعد بداية الراتب الحالي.');
                        elseif ($p = closed_period_for($from)) $err[] = __('تاريخ بدء الراتب جوه فترة مقفولة (%s).', $p);
                        else $salary = ['amount' => round((float)$sal, 2), 'from' => $from];
                    }
                }
            }
        }
        $role = null;
        $roleName = (string)$get('role');
        if ($roleName !== '') {
            if (!is_manager()) $warnings[$rowNo][] = __('الصلاحية اتجاهلت: تحديدها للسوبر أدمن والأدمن بس.');
            elseif (!isset($roles[mb_strtolower($roleName)])) $err[] = __('الصلاحية "%s" مش موجودة.', $roleName);
            else $role = (int)$roles[mb_strtolower($roleName)]['id'];
        }

        // Coordinator: linked by employee code only. Column missing (older file) = assignments left as they are.
        if (isset($idx['coordinator'])) {
            $cv = $get('coordinator');
            $cv = is_float($cv) || is_int($cv) ? (string)$cv : trim((string)$cv);
            if ($cv === '-') $f['coordinator_id'] = null;
            elseif ($cv !== '') {
                if (!preg_match('/^\d{1,9}(\.0+)?$/', $cv) || (int)$cv < 1) $err[] = __('Coordinator Employee ID لازم يكون كود موظف (رقم) مش اسم: "%s".', $cv);
                elseif ($p = coordinator_problem((int)$cv, $id, array_key_exists((int)$cv, $fileEnd) ? $fileEnd[(int)$cv] : false)) $err[] = $p;
                else $f['coordinator_id'] = (int)$cv;
            }
        }

        if ($err) { $errors[$rowNo] = $err; continue; }
        $changes = [];
        if ($existing) foreach ($f as $k => $v) { if ((string)($existing[$k] ?? '') !== (string)$v) $changes[$k] = $v; }
        else $changes = $f;
        if ($role !== null) {
            $u = $existing ? q_one('SELECT id, role_id FROM users WHERE employee_id = ?', [$id]) : null;
            if ($u && (int)$u['role_id'] === $role) $role = null;
        }
        if (!$changes && !$salary && $role === null && $existing) { $stats['same']++; continue; }
        $stats[$existing ? 'update' : 'new']++;
        if ($salary) $stats['salary']++;
        if ($role !== null) $stats['accounts']++;
        $coordId = array_key_exists('coordinator_id', $f) ? $f['coordinator_id'] : ($existing['coordinator_id'] ?? null);
        $coord = ['id' => $coordId, 'name' => $coordId !== null ? (string)q_val('SELECT name_ar FROM employees WHERE id = ?', [$coordId]) : null,
                  'changed' => array_key_exists('coordinator_id', $changes), 'cleared' => array_key_exists('coordinator_id', $changes) && $coordId === null];
        $out[] = ['row' => $rowNo, 'id' => $id, 'is_new' => !$existing, 'name' => $f['name_ar'] ?? $existing['name_ar'], 'changes' => $changes, 'salary' => $salary, 'role' => $role, 'coordinator' => $coord];
    }
    return ['rows' => $out, 'errors' => $errors, 'warnings' => $warnings, 'stats' => $stats];
}

/** Applies a validated plan inside one transaction. Returns counts. */
function emp_import_apply(array $plan, string $tempPassword): array
{
    $by = user()['username'];
    $op = bin2hex(random_bytes(8));
    $basicType = (int)(q_val("SELECT id FROM salary_tx_types WHERE category = 'basic' AND active = 1 ORDER BY id LIMIT 1")
        ?: q_val("SELECT id FROM salary_tx_types WHERE name = 'الراتب الأساسي'") ?: 0);
    $hash = $tempPassword !== '' ? hash_password($tempPassword) : null;
    $done = ['new' => 0, 'update' => 0, 'salary' => 0, 'accounts' => 0, 'roles' => 0];
    db()->beginTransaction();
    try {
        foreach ($plan as $r) {
            $id = $r['id'];
            // The coordinator may have left or been removed since the preview: fail the whole import, change nothing.
            if (($r['changes']['coordinator_id'] ?? null) !== null && ($p = coordinator_problem((int)$r['changes']['coordinator_id'], $id)))
                throw new RuntimeException(__('صف %d: %s ارفع الملف تاني.', $r['row'], $p));
            if ($r['is_new']) {
                if ($id === null) $id = (int)q_val('SELECT COALESCE(MAX(id), 0) + 1 FROM employees');
                elseif (q_val('SELECT 1 FROM employees WHERE id = ?', [$id])) throw new RuntimeException(__('الكود %d اتضاف من وقت المعاينة؛ ارفع الملف تاني.', $id));
                $cols = array_merge(['id' => $id], $r['changes']);
                q('INSERT INTO employees (`' . implode('`,`', array_keys($cols)) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($cols));
                q('INSERT INTO employee_change_log (employee_id, field, new_value, reason, changed_by, op_id) VALUES (?,?,?,?,?,?)', [$id, 'إضافة موظف جديد', 'رفع ملف Excel', 'رفع ملف Excel', $by, $op]);
                $done['new']++;
            } elseif ($r['changes']) {
                $old = q_one('SELECT * FROM employees WHERE id = ? FOR UPDATE', [$id]);
                q('UPDATE employees SET ' . implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($r['changes']))) . ' WHERE id = ?', array_merge(array_values($r['changes']), [$id]));
                foreach ($r['changes'] as $k => $v) {
                    q('INSERT INTO employee_change_log (employee_id, field, old_value, new_value, reason, changed_by, op_id) VALUES (?,?,?,?,?,?,?)', [$id, $k, $old[$k], $v, 'رفع ملف Excel', $by, $op]);
                }
                $done['update']++;
            }
            if ($r['salary']) {
                if (!$basicType) { q("INSERT INTO salary_tx_types (name, direction, category) VALUES ('الراتب الأساسي', 'add', 'basic')"); $basicType = (int)db()->lastInsertId(); }
                $cur = current_basic($id);
                if ($cur) {
                    $to = date('Y-m-d', strtotime($r['salary']['from'] . ' -1 day'));
                    q('UPDATE salary_tx SET date_to = ? WHERE id = ?', [$to, $cur['id']]);
                    audit('salary_tx.update', $cur['id'], ['date_to' => [$cur['date_to'], $to], 'via' => 'excel']);
                }
                q('INSERT INTO salary_tx (tx_code, employee_id, type_id, date_from, amount, reason, created_by) VALUES (?,?,?,?,?,?,?)',
                    ['TX-' . strtoupper(bin2hex(random_bytes(5))), $id, $basicType, $r['salary']['from'], $r['salary']['amount'], 'رفع ملف Excel', $by]);
                audit('salary_tx.create', (int)db()->lastInsertId(), ['employee_id' => $id, 'amount' => $r['salary']['amount'], 'date_from' => $r['salary']['from'], 'via' => 'excel']);
                $done['salary']++;
            }
            if ($r['role'] !== null) {
                $u = q_one('SELECT id, role_id FROM users WHERE employee_id = ?', [$id]);
                if ($u) {
                    q('UPDATE users SET role_id = ?, session_epoch = session_epoch + 1 WHERE id = ?', [$r['role'], $u['id']]);
                    audit('user.role', $id, ['from' => $u['role_id'], 'to' => $r['role'], 'via' => 'excel']);
                    $done['roles']++;
                } elseif ($hash !== null && !q_val('SELECT 1 FROM users WHERE username = ?', [(string)$id])) {
                    q('INSERT INTO users (username, password_hash, role_id, employee_id, must_change_password) VALUES (?,?,?,?,1)', [(string)$id, $hash, $r['role'], $id]);
                    audit('user.create', (string)$id, ['role' => $r['role'], 'via' => 'excel']);
                    $done['accounts']++;
                }
            }
        }
        face_purge_ended(); // employees whose service ended lose their face data right away
        audit('employees.excel_import', null, $done);
        db()->commit();
    } catch (Throwable $ex) {
        db()->rollBack();
        throw $ex;
    }
    return $done;
}
