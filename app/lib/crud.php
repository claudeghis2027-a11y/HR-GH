<?php
declare(strict_types=1);

// Old notification "content items": the employee sees only the parts the notification names.
const NOTIF_CONTENTS = ['message' => 'نص الرسالة', 'profile' => 'البيانات الشخصية', 'documents' => 'المستندات (المكتملة والناقصة)',
    'absence' => 'الغياب', 'late' => 'التأخير', 'deductions' => 'الخصومات', 'monthlyClosing' => 'التقفيل الشهري'];
const NOTIF_REPORT_KEYS = ['absence', 'late', 'deductions', 'monthlyClosing'];

function resources(): array
{
    static $r = null;
    return $r ??= require __DIR__ . '/../resources.php';
}

function resource(string $key): array
{
    $r = resources()[$key] ?? null;
    if (!$r) http_error(404);
    $r['key'] = $key;
    $r['pk'] ??= 'id';
    return $r;
}

/** id => label map for a foreign-key field (small lookup tables only). */
function fk_options(array $f): array
{
    static $cache = [];
    [$table, $label] = $f['fk'];
    $k = "$table.$label";
    if (!isset($cache[$k])) {
        $cache[$k] = [];
        foreach (q_all("SELECT id, `$label` AS l FROM `$table` ORDER BY `$label`") as $r) $cache[$k][$r['id']] = $r['l'];
    }
    return $cache[$k];
}

/** Options of an fk select in a form: active rows only (when the table has «active»), plus the row's current value. */
function fk_form_options(array $f, $current): array
{
    $all = fk_options($f);
    if (empty($f['fk_active'])) return $all;
    [$table] = $f['fk'];
    $active = array_flip(array_map('intval', array_column(q_all("SELECT id FROM `$table` WHERE active = 1"), 'id')));
    return array_filter($all, fn($k) => isset($active[(int)$k]) || (string)$k === (string)$current, ARRAY_FILTER_USE_KEY);
}

function employee_options(): array
{
    [$w, $p] = scope_sql('e');
    $out = [];
    foreach (q_all("SELECT e.id, e.name_ar FROM employees e WHERE $w ORDER BY e.name_ar", $p) as $r) $out[$r['id']] = $r['id'] . ' - ' . $r['name_ar'];
    return $out;
}

function display_value(array $f, $v, array $row = []): string
{
    if ($v === null || $v === '') return '';
    switch ($f['type']) {
        case 'bool': return $v ? __('نعم') : __('لا');
        case 'enum':
            if (!empty($f['multi'])) return implode('، ', array_map(fn($k) => __((string)($f['options'][$k] ?? $k)), explode(',', (string)$v)));
            return __((string)($f['options'][$v] ?? $v));
        case 'fk': return (string)(fk_options($f)[$v] ?? $v);
        case 'employee': return $v . (isset($row['_emp_name']) ? ' - ' . $row['_emp_name'] : '');
        case 'datetime': return substr((string)$v, 0, 16);
        case 'month': return substr((string)$v, 0, 7);
        case 'filelink': return '📎';
        case 'scope': // department id or employee code, shown by name
            $ids = array_map('intval', explode(',', (string)$v));
            if (($row['scope'] ?? '') === 'department') { $d = fk_options(['fk' => ['departments', 'name']]); return implode('، ', array_map(fn($i) => (string)($d[$i] ?? $i), $ids)); }
            if (($row['scope'] ?? '') === 'employee') {
                if (count($ids) > 3) return __('%d موظف', count($ids));
                return implode('، ', array_map(fn($i) => $i . ' - ' . (string)q_val('SELECT name_ar FROM employees WHERE id = ?', [$i]), $ids));
            }
            return (string)$v;
        case 'time': return substr((string)$v, 0, 5);
        case 'decimal': return num($v);
        default: return (string)$v;
    }
}

/** Builds the WHERE clause shared by list + export. */
function crud_where(array $res, bool $selfOnly): array
{
    $where = ['1=1'];
    $params = [];
    $emp = $res['emp'] ?? null;
    if ($selfOnly) {
        $where[] = "t.`$emp` = ?";
        $params[] = require_employee_self();
    } elseif ($emp) {
        [$w, $p] = scope_sql('e');
        $where[] = $w;
        $params = array_merge($params, $p);
    }
    $empFilter = in_int('emp', $_GET);
    if ($emp && $empFilter !== null && !$selfOnly) { $where[] = "t.`$emp` = ?"; $params[] = $empFilter; }
    if (!empty($res['date_filter'])) {
        $df = $res['date_filter'];
        if ($d = valid_date($_GET['from'] ?? null)) { $where[] = "t.`$df` >= ?"; $params[] = $d; }
        if ($d = valid_date($_GET['to'] ?? null)) { $where[] = "t.`$df` <= ?"; $params[] = $d; }
    }
    if (!empty($res['soft_delete'])) $where[] = !$selfOnly && ($_GET['deleted'] ?? '') === '1' ? "t.`{$res['soft_delete']}` = 1" : "t.`{$res['soft_delete']}` = 0"; // ?deleted=1 lists the removed ones
    if (!empty($res['current_filter']) && ($_GET['current'] ?? '') === '1') {
        $where[] = "(t.`{$res['current_filter']}` IS NULL OR t.`{$res['current_filter']}` >= CURDATE())";
    }
    if (!empty($res['present_filter']) && ($_GET['present'] ?? '') === '1') {
        $where[] = "t.`{$res['present_filter']}` IS NOT NULL";
    }
    foreach ($res['multi_filters'] ?? [] as $param => $col) {
        $ids = crud_multi_ids($param);
        if ($ids) { $where[] = "t.`$col` IN (" . implode(',', array_fill(0, count($ids), '?')) . ')'; array_push($params, ...$ids); }
    }
    $s = in_str('q', 100, $_GET);
    if ($s !== '' && !empty($res['search'])) {
        $or = [];
        foreach ($res['search'] as $col) { $or[] = "t.`$col` LIKE ?"; $params[] = '%' . addcslashes($s, '%_\\') . '%'; }
        if (ctype_digit($s)) { $or[] = 't.`' . $res['pk'] . '` = ?'; $params[] = (int)$s; }
        $where[] = '(' . implode(' OR ', $or) . ')';
    }
    return [implode(' AND ', $where), $params];
}

/** Multi-select filter values (?dept[]=1&dept[]=2) as positive ints. */
function crud_multi_ids(string $param): array
{
    $v = $_GET[$param] ?? [];
    return array_values(array_unique(array_filter(array_map('intval', is_array($v) ? $v : [$v]), fn($x) => $x > 0)));
}

/** Old employee status and final attendance exemption (manual or by department). */
function employee_status(array $emp): string
{
    return !empty($emp['end_date']) && $emp['end_date'] < date('Y-m-d') ? 'منتهي الخدمة' : 'نشط';
}

function employee_exempt_final(array $emp): bool
{
    static $deptExempt = null;
    $deptExempt ??= array_map('intval', array_column(q_all('SELECT id, attendance_exempt FROM departments'), 'attendance_exempt', 'id'));
    return !empty($emp['attendance_exempt']) || !empty($deptExempt[(int)($emp['department_id'] ?? 0)]);
}

function crud_from(array $res): string
{
    $emp = $res['emp'] ?? null;
    $sql = "FROM `{$res['table']}` t";
    if ($emp) $sql .= " LEFT JOIN employees e ON e.id = t.`$emp`";
    return $sql;
}

function crud_list(string $key, bool $selfOnly = false): void
{
    $res = resource($key);
    if ($selfOnly) {
        if (empty($res['self'])) http_error(404);
        require_employee_self();
    } else {
        require_perm($res['module'], 'view');
    }
    [$where, $params] = crud_where($res, $selfOnly);
    $from = crud_from($res);
    $nameSel = !empty($res['emp']) ? ', e.name_ar AS _emp_name' : '';

    if (export_format() && !$selfOnly) {
        require_perm($res['module'], 'export');
        $rows = q_all("SELECT t.* $nameSel $from WHERE $where ORDER BY {$res['order']} LIMIT 50000", $params);
        $cols = array_keys($res['fields']);
        $out = [];
        foreach ($rows as $r) {
            $line = [];
            foreach ($cols as $c) $line[] = display_value($res['fields'][$c] + ['type' => 'text'], $r[$c] ?? null, $r);
            $out[] = $line;
        }
        audit('export.' . export_format(), $key, ['rows' => count($out)]);
        send_export(__($res['title']), $key . '-' . date('Ymd'), array_map(fn($c) => $res['fields'][$c]['label'], $cols), $out);
    }

    $per = 50;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $total = (int)q_val("SELECT COUNT(*) $from WHERE $where", $params);
    $rows = q_all("SELECT t.* $nameSel $from WHERE $where ORDER BY {$res['order']} LIMIT $per OFFSET " . (($page - 1) * $per), $params);

    render('crud_list', [
        'res' => $res, 'rows' => $rows, 'total' => $total, 'page' => $page, 'per' => $per, 'selfOnly' => $selfOnly,
        'title' => $res['title'],
    ]);
}

function crud_form(string $key): void
{
    $res = resource($key);
    $id = in_int('id', $_GET);
    $row = [];
    if ($id !== null) {
        require_perm($res['module'], 'view');
        $row = crud_load($res, $id);
    } else {
        require_perm($res['module'], 'create');
        foreach ($res['fields'] as $name => $f) if (isset($f['default'])) $row[$name] = $f['default'];
        if (!empty($res['emp']) && ($e = in_int('emp', $_GET)) !== null) $row[$res['emp']] = $e;
    }
    $hook = $res['hooks'] ?? null;
    if ($hook && function_exists("hook_{$hook}_load")) ("hook_{$hook}_load")($row);
    render('crud_form', ['res' => $res, 'row' => $row, 'id' => $id, 'errors' => [], 'title' => $res['title']]);
}

function crud_load(array $res, int $id): array
{
    $row = q_one('SELECT t.* ' . crud_from($res) . " WHERE t.`{$res['pk']}` = ?", [$id]);
    if (!$row) http_error(404);
    if (!empty($res['emp']) && !can_see_employee((int)$row[$res['emp']])) http_error(403);
    return $row;
}

/** Validates POSTed values for a resource. Returns [data, errors]. */
function crud_validate(array $res, bool $isNew): array
{
    $data = [];
    $errors = [];
    foreach ($res['fields'] as $name => $f) {
        if (!empty($f['auto']) || !empty($f['readonly'])) continue;
        if (!empty($f['create_only']) && !$isNew) continue;
        if (!empty($f['edit_only']) && $isNew) continue;
        if ($f['type'] === 'file') continue; // uploads are handled by the resource hook ($_FILES)
        $raw = $_POST[$name] ?? null;
        if (!empty($f['multi']) && $f['type'] === 'enum') { // several options, kept as "a,b,c" in the options' order
            $picked = is_array($raw) ? array_filter($raw, 'is_string') : [];
            $keys = array_values(array_filter(array_keys($f['options']), fn($k) => in_array((string)$k, $picked, true)));
            if (count($keys) !== count(array_unique($picked))) $errors[$name] = __('%s قيمة غير مسموحة.', __($f['label']));
            if (!$keys && !empty($f['required'])) $errors[$name] = __('%s مطلوب.', __($f['label']));
            $data[$name] = $keys ? implode(',', $keys) : null;
            continue;
        }
        if (!empty($f['multi'])) { // several departments / employees, kept as "3,7,12"
            $ids = is_array($raw) ? array_values(array_unique(array_map('intval', array_filter($raw, fn($x) => is_string($x) && preg_match('/^\d{1,10}$/', $x))))) : [];
            sort($ids);
            foreach ($ids as $v) {
                if ($f['type'] === 'fk' && !isset(fk_options($f)[$v])) { $errors[$name] = __('%s غير موجود.', __($f['label'])); break; }
                if ($f['type'] === 'employee' && !can_see_employee($v)) { $errors[$name] = __('الموظف ده خارج نطاق صلاحياتك.'); break; }
            }
            $data[$name] = $ids ? implode(',', $ids) : null;
            continue;
        }
        $raw = is_string($raw) ? trim($raw) : null;
        $label = __($f['label']);
        if ($f['type'] === 'bool') { $data[$name] = $raw === '1' ? 1 : 0; continue; }
        if ($raw === null || $raw === '') {
            if (!empty($f['required'])) $errors[$name] = __('%s مطلوب.', $label);
            $data[$name] = $f['type'] === 'enum' && isset($f['default']) ? $f['default'] : null; // NOT NULL enums fall back to their default
            continue;
        }
        $v = null;
        switch ($f['type']) {
            case 'text': case 'textarea':
                $max = $f['max'] ?? 500;
                if (mb_strlen($raw) > $max) $errors[$name] = __('%s أطول من %d حرف.', $label, $max);
                elseif (!empty($f['pattern']) && !preg_match($f['pattern'], $raw)) $errors[$name] = __('%s بصيغة غير صحيحة.', $label);
                $v = $raw; break;
            case 'int': case 'fk': case 'employee':
                if (!preg_match('/^-?\d{1,10}$/', $raw)) { $errors[$name] = __('%s لازم يكون رقم صحيح.', $label); break; }
                $v = (int)$raw; break;
            case 'decimal':
                if (!preg_match('/^-?\d{1,12}(\.\d{1,7})?$/', $raw)) { $errors[$name] = __('%s لازم يكون رقم.', $label); break; }
                $v = $raw; break;
            case 'date':
                if (!($v = valid_date($raw))) $errors[$name] = __('%s تاريخ غير صحيح.', $label); break;
            case 'month': // stored as the first day of the month
                if (!($m = valid_month(substr($raw, 0, 7)))) $errors[$name] = __('%s شهر غير صحيح.', $label); else $v = "$m-01";
                break;
            case 'time':
                if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $raw)) $errors[$name] = __('%s وقت غير صحيح.', $label);
                $v = $raw; break;
            case 'datetime':
                $raw = str_replace('T', ' ', $raw);
                $dt = DateTime::createFromFormat('Y-m-d H:i', substr($raw, 0, 16));
                if (!$dt) $errors[$name] = __('%s غير صحيح.', $label); else $v = $dt->format('Y-m-d H:i:00');
                break;
            case 'enum':
                if (!array_key_exists($raw, $f['options'])) $errors[$name] = __('%s قيمة غير مسموحة.', $label);
                $v = $raw; break;
        }
        if (in_array($f['type'], ['int', 'decimal'], true) && $v !== null && !isset($errors[$name])) {
            if (isset($f['min']) && (float)$v < $f['min']) $errors[$name] = __('%s أقل من المسموح.', $label);
            if (isset($f['max_val']) && (float)$v > $f['max_val']) $errors[$name] = __('%s أكبر من المسموح.', $label);
        }
        if ($f['type'] === 'fk' && $v !== null && !isset(fk_options($f)[$v])) $errors[$name] = __('%s غير موجود.', $label);
        if ($f['type'] === 'employee' && $v !== null && !isset($errors[$name])) {
            if (!q_val('SELECT 1 FROM employees WHERE id = ?', [$v])) $errors[$name] = __('الموظف غير موجود.');
            elseif (!can_see_employee($v)) $errors[$name] = __('الموظف ده خارج نطاق صلاحياتك.');
        }
        $data[$name] = $v;
    }
    foreach ([['date_from', 'date_to']] as [$a, $b]) {
        if (!empty($data[$a]) && !empty($data[$b]) && $data[$b] < $data[$a]) $errors[$b] = __('تاريخ النهاية قبل تاريخ البداية.');
    }
    return [$data, $errors];
}

function crud_save(string $key): void
{
    $res = resource($key);
    if (!empty($res['readonly'])) http_error(403);
    $id = in_int('id');
    $isNew = $id === null;
    require_perm($res['module'], $isNew ? 'create' : 'update');
    $old = $isNew ? null : crud_load($res, $id);

    [$data, $errors] = crud_validate($res, $isNew);
    foreach ([$old[$res['lock_date'] ?? ''] ?? null, $data[$res['lock_date'] ?? ''] ?? null] as $d) {
        if ($d && ($p = closed_period_for($d))) $errors['_'] = __('الفترة "%s" مقفولة؛ مينفعش تضيف أو تعدل حاجة بتاريخ جواها.', $p);
    }
    $hook = $res['hooks'] ?? null;
    if ($hook && function_exists("hook_{$hook}_validate")) {
        ("hook_{$hook}_validate")($data, $errors, $old);
    }
    // Virtual fields (e.g. the reason of a change) go to the hooks, never to the table.
    $extra = [];
    foreach ($res['fields'] as $name => $f) if (!empty($f['virtual']) && array_key_exists($name, $data)) { $extra[$name] = $data[$name]; unset($data[$name]); }
    if ($errors) {
        render('crud_form', ['res' => $res, 'row' => array_merge($old ?? [], $data, $extra), 'id' => $id, 'errors' => $errors, 'title' => $res['title']]);
        return;
    }
    if ($isNew) {
        foreach ($res['fields'] as $name => $f) {
            if (($f['auto'] ?? '') === 'username') $data[$name] = user()['username'];
            if (($f['auto'] ?? '') === 'now') $data[$name] = date('Y-m-d H:i:s');
        }
    }

    try {
        db()->beginTransaction();
        if ($isNew) {
            $cols = array_keys($data);
            q("INSERT INTO `{$res['table']}` (`" . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($data));
            $id = (int)($data[$res['pk']] ?? db()->lastInsertId());
        } else {
            $sets = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($data)));
            if ($sets !== '') q("UPDATE `{$res['table']}` SET $sets WHERE `{$res['pk']}` = ?", array_merge(array_values($data), [$id]));
        }
        $diff = [];
        foreach ($data as $k => $v) if ($isNew || (string)($old[$k] ?? '') !== (string)($v ?? '')) $diff[$k] = $isNew ? $v : [$old[$k] ?? null, $v];
        if ($hook && function_exists("hook_{$hook}_after")) ("hook_{$hook}_after")($id, $data + $extra, $old, $diff);
        audit("$key." . ($isNew ? 'create' : 'update'), $id, $diff);
        db()->commit();
    } catch (PDOException $ex) {
        db()->rollBack();
        error_log(safe_error($ex));
        $msg = $ex->getCode() === '23000' ? __('القيمة دي متسجلة قبل كده أو مرتبطة ببيانات تانية.') : __('حصل خطأ أثناء الحفظ.');
        render('crud_form', ['res' => $res, 'row' => array_merge($old ?? [], $data), 'id' => $isNew ? null : $id, 'errors' => ['_' => $msg], 'title' => $res['title']]);
        return;
    }
    flash('ok', __('تم الحفظ.'));
    redirect(crud_back_url() ?? url('list', ['res' => $key]));
}

/** Combined screens (old «القواعد» / «إعفاءات التأخير») post here and come back: only a page name + anchor is accepted. */
function crud_back_url(): ?string
{
    $back = in_str('_back', 80);
    if (!preg_match('/^([a-z_]{2,30})(?:#([a-z_]{1,30}))?$/', $back, $m)) return null;
    $q = [];
    foreach (['from', 'to'] as $k) if ($v = valid_date($_POST["_back_$k"] ?? null)) $q[$k] = $v;
    return url($m[1], $q) . (isset($m[2]) ? '#' . $m[2] : '');
}

function crud_delete(string $key): void
{
    $res = resource($key);
    if (!empty($res['readonly'])) http_error(403);
    require_perm($res['module'], 'delete');
    $id = in_int('id');
    if ($id === null) http_error(400);
    $old = crud_load($res, $id);
    if (!empty($res['lock_date']) && ($p = closed_period_for((string)$old[$res['lock_date']]))) {
        flash('err', __('الفترة "%s" مقفولة؛ مينفعش تحذف حاجة بتاريخ جواها.', $p));
        redirect(url('list', ['res' => $key]));
    }
    $hook = $res['hooks'] ?? null;
    if ($hook && function_exists("hook_{$hook}_delete") && ($msg = ("hook_{$hook}_delete")($old)) !== null) {
        flash('err', $msg);
        redirect(url('list', ['res' => $key]));
    }
    try {
        q("DELETE FROM `{$res['table']}` WHERE `{$res['pk']}` = ?", [$id]);
        audit("$key.delete", $id, $old);
        flash('ok', __('تم الحذف.'));
    } catch (PDOException $ex) {
        flash('err', __('مش ممكن الحذف لأن السجل مرتبط ببيانات تانية.'));
    }
    redirect(url('list', ['res' => $key]));
}

/** Name of a closed payroll period containing $date (super admin may still edit; it is audited). */
function closed_period_for(?string $date): ?string
{
    if (!$date || is_manager()) return null;
    $p = q_one("SELECT id, name FROM payroll_periods WHERE status = 'closed' AND ? BETWEEN date_from AND date_to LIMIT 1", [substr($date, 0, 10)]);
    return $p ? ($p['name'] ?: __('رقم %d', $p['id'])) : null;
}

// ───────────── Resource hooks ─────────────
/**
 * Why $coordId can't be the coordinator of employee $selfId (null = it can). Linked by employee code only.
 * $endDateOverride: the coordinator's end date as it will be after the same save/import (false = use the stored one).
 * Used by the employee form and the Excel import, so both apply the same rule.
 */
function coordinator_problem(int $coordId, ?int $selfId, string|null|false $endDateOverride = false): ?string
{
    if ($selfId !== null && $coordId === $selfId) return __('الموظف مينفعش يكون منسق لنفسه.');
    $c = q_one('SELECT id, end_date FROM employees WHERE id = ?', [$coordId]);
    if (!$c) return __('كود المنسق %d مش موجود.', $coordId);
    $end = $endDateOverride === false ? $c['end_date'] : $endDateOverride;
    if ($end !== null && $end !== '' && $end < date('Y-m-d')) return __('المنسق %d خدمته منتهية (مش نشط).', $coordId);
    return null;
}

function hook_employees_validate(array &$data, array &$errors, ?array $old): void
{
    if ($old === null) {
        if (($data['id'] ?? null) === null) {
            if (setting('auto_employee_code', '1') !== '1') { $errors['id'] = __('كود الموظف مطلوب.'); return; }
            $data['id'] = (int)q_val('SELECT COALESCE(MAX(id), 0) + 1 FROM employees');
        } elseif ($data['id'] < 1) {
            $errors['id'] = __('كود غير صالح.');
        } elseif (q_val('SELECT 1 FROM employees WHERE id = ?', [$data['id']])) {
            $errors['id'] = __('الكود ده مستخدم.');
        }
    }
    $scope = dept_scope();
    if ($scope !== null && !in_array((int)($data['department_id'] ?? 0), $scope, true)) {
        $errors['department_id'] = __('لازم تختار قسم من الأقسام المسموحة لك.');
    }
    // The bank only means something for card (فيزا) pay.
    if (array_key_exists('bank_id', $data) && ($data['pay_method'] ?? null) !== 'visa') $data['bank_id'] = null;
    if (array_key_exists('coordinator_id', $data)) {
        $oldCoord = $old['coordinator_id'] ?? null;
        // The picker lists only employees in the user's scope: an out-of-scope coordinator shows as empty, so keep it.
        if ($data['coordinator_id'] === null && $oldCoord !== null && !can_see_employee((int)$oldCoord)) $data['coordinator_id'] = (int)$oldCoord;
        $selfId = isset($old['id']) ? (int)$old['id'] : (isset($data['id']) ? (int)$data['id'] : null);
        if ($data['coordinator_id'] !== null && ((int)$data['coordinator_id'] === $selfId || (string)$data['coordinator_id'] !== (string)$oldCoord)
            && ($p = coordinator_problem((int)$data['coordinator_id'], $selfId))) {
            $errors['coordinator_id'] = $p;
        }
    }
}

function hook_employees_after(int $id, array $data, ?array $old, array $diff): void
{
    $by = user()['username'];
    $op = bin2hex(random_bytes(8));
    if ($old === null) {
        q('INSERT INTO employee_change_log (employee_id, field, new_value, reason, changed_by, op_id) VALUES (?,?,?,?,?,?)', [$id, 'إضافة موظف جديد', 'تم الإنشاء', trim((string)($data['change_reason'] ?? '')) ?: null, $by, $op]);
        return;
    }
    // Old updateEmployee_: one operation id per save, a history row per changed field, with the reason.
    $reason = trim((string)($data['change_reason'] ?? '')) ?: null;
    foreach ($diff as $field => [$from, $to]) {
        q('INSERT INTO employee_change_log (employee_id, field, old_value, new_value, reason, changed_by, op_id) VALUES (?,?,?,?,?,?,?)', [$id, $field, $from, $to, $reason, $by, $op]);
    }
    if (!empty($data['end_date']) && $data['end_date'] <= date('Y-m-d')) face_purge_employee($id, 'end_of_service');
}

/** Old AttendanceAdjustService.adjustmentAdd: a time is required for the three timed types and ignored for the rest. */
function hook_adjustments_validate(array &$data, array &$errors, ?array $old): void
{
    if (in_array($data['adj_type'] ?? '', ['set_check_in', 'set_check_out', 'force_present'], true)) {
        $v = trim((string)($data['value'] ?? ''));
        if (preg_match('/^(\d{1,2}):(\d{2})/', $v, $m) && (int)$m[1] < 24 && (int)$m[2] < 60) $data['value'] = sprintf('%02d:%02d', $m[1], $m[2]);
        else $errors['value'] = __('لازم تحدد وقت صحيح (HH:MM).');
    } elseif (in_array($data['adj_type'] ?? '', ['force_absent', 'waive_day'], true)) {
        $data['value'] = null;
    }
}

/** Scope picked from lists (old: department or employee chosen from a dropdown): scope_dept / scope_emp → scope_value. */
function scope_pick_validate(array &$data, array &$errors): void
{
    $scope = $data['scope'] ?? 'all';
    $data['scope_value'] = null;
    if ($scope === 'department') {
        if (empty($data['scope_dept'])) $errors['scope_dept'] = __('اختار القسم.'); else $data['scope_value'] = (string)$data['scope_dept'];
    } elseif ($scope === 'employee') {
        if (empty($data['scope_emp'])) $errors['scope_emp'] = __('اختار الموظف.'); else $data['scope_value'] = (string)$data['scope_emp'];
    }
}

function scope_pick_load(array &$row): void
{
    if (($row['scope'] ?? '') === 'department') $row['scope_dept'] = $row['scope_value'];
    if (($row['scope'] ?? '') === 'employee') $row['scope_emp'] = $row['scope_value'];
}

function hook_notifications_validate(array &$data, array &$errors, ?array $old): void
{
    if ($old !== null && $old['status'] === 'cancelled') { $errors['_'] = __('الإشعار ملغي — مينفعش يتعدّل.'); return; }
    scope_pick_validate($data, $errors);
    // Old notification types: a payslip notice names its month, an attendance report its period
    if ($data['ntype'] === 'كشف مرتب' && empty($data['period_month'])) $errors['period_month'] = __('اختار شهر كشف المرتب.');
    $keys = array_filter(explode(',', (string)($data['contents'] ?? '')));
    $needsPeriod = $data['ntype'] === 'تقرير حضور' || array_intersect($keys, NOTIF_REPORT_KEYS);
    if ($needsPeriod) {
        if (empty($data['report_from'])) $errors['report_from'] = __('حدد بداية فترة التقرير.');
        if (empty($data['report_to'])) $errors['report_to'] = __('حدد نهاية فترة التقرير.');
        if (!empty($data['report_from']) && !empty($data['report_to']) && $data['report_from'] > $data['report_to']) $errors['report_to'] = __('نهاية الفترة لازم تكون بعد بدايتها.');
    }
    if ($data['ntype'] !== 'كشف مرتب') $data['period_month'] = null;
    if (!$needsPeriod) $data['report_from'] = $data['report_to'] = null;
}

function hook_notifications_load(array &$row): void { scope_pick_load($row); }

function hook_checkout_ex_validate(array &$data, array &$errors, ?array $old): void { scope_pick_validate($data, $errors); }
function hook_checkout_ex_load(array &$row): void { scope_pick_load($row); }

/** Old LateWaiverService._validate: reason and approver required, at most a full day. */
function hook_late_ex_validate(array &$data, array &$errors, ?array $old): void
{
    if (trim((string)($data['reason'] ?? '')) === '') $errors['reason'] = __('لازم تكتب سبب الإعفاء.');
    if (trim((string)($data['approver'] ?? '')) === '') $errors['approver'] = __('لازم تحدد جهة الموافقة.');
}

/** (Re)builds the recipients of a notification, limited to the author's department scope. */
function hook_notifications_after(int $id, array $data, ?array $old, array $diff): void
{
    $n = q_one('SELECT * FROM notifications WHERE id = ?', [$id]);
    if ($n['status'] === 'cancelled') return;
    [$w, $p] = scope_sql('e');
    $sql = "SELECT e.id FROM employees e WHERE $w AND (e.end_date IS NULL OR e.end_date >= CURDATE())";
    $ids = array_values(array_filter(array_map('intval', explode(',', (string)$n['scope_value']))));
    $in = implode(',', array_fill(0, max(1, count($ids)), '?'));
    if ($n['scope'] === 'department') { $sql .= " AND e.department_id IN ($in)"; array_push($p, ...($ids ?: [0])); }
    if ($n['scope'] === 'employee')   { $sql .= " AND e.id IN ($in)"; array_push($p, ...($ids ?: [0])); }
    $hide = date('Y-m-d H:i:s', strtotime($n['show_at']) + 60 * (int)$n['duration_min']);
    q('DELETE FROM notification_targets WHERE notification_id = ? AND read_at IS NULL', [$id]);
    foreach (q_all($sql, $p) as $r) {
        q('INSERT INTO notification_targets (notification_id, employee_id, show_at, hide_at) VALUES (?,?,?,?)
           ON DUPLICATE KEY UPDATE show_at = VALUES(show_at), hide_at = VALUES(hide_at)', [$id, $r['id'], $n['show_at'], $hide]);
    }
    q('UPDATE notifications SET updated_at = NOW() WHERE id = ?', [$id]);
}

/** Old RulesService: historical schedule rules never overlap; a new rule gets the next SR-0001 id. */
function hook_schedule_rules_validate(array &$data, array &$errors, ?array $old): void
{
    if ($errors) return;
    if ($o = range_overlap('schedule_rules', $data['date_from'], $data['date_to'], $old ? (int)$old['id'] : null)) {
        $errors['date_from'] = __('الفترة (%s إلى %s) بتتداخل مع قاعدة موجودة بالفعل (%s إلى %s).', $data['date_from'], $data['date_to'], $o['date_from'], $o['date_to']);
    }
    if ($old !== null && trim((string)($data['change_reason'] ?? '')) === '') $errors['change_reason'] = __('لازم تكتب سبب التعديل.');
    if ($old === null && ($data['code'] ?? '') === '') {
        $max = 0;
        foreach (q_all("SELECT code FROM schedule_rules WHERE code LIKE 'SR-%'") as $r) if (preg_match('/^SR-(\d+)$/', (string)$r['code'], $m)) $max = max($max, (int)$m[1]);
        $data['code'] = sprintf('SR-%04d', $max + 1);
    }
}

/** Old updateScheduleRule_: the previous version is archived (with the reason) before it is overwritten. */
function hook_schedule_rules_after(int $id, array $data, ?array $old, array $diff): void
{
    if ($old !== null) schedule_rule_archive($old, 'تعديل', (string)($data['change_reason'] ?? ''));
}

function hook_schedule_rules_delete(array $old): ?string
{
    schedule_rule_archive($old, 'حذف', '');
    return null;
}

function schedule_rule_archive(array $old, string $op, string $reason): void
{
    q('INSERT INTO schedule_rules_archive (archive_code, rule_code, operation, date_from, date_to, start_time, end_time, done_by, done_at, reason) VALUES (?,?,?,?,?,?,?,?,NOW(),?)',
        ['SA-' . strtoupper(bin2hex(random_bytes(4))), $old['code'] ?: (string)$old['id'], $op, $old['date_from'], $old['date_to'], $old['start_time'], $old['end_time'], user()['username'] ?? null, $reason]);
}

/** Old deductionRuleAdd: deduction rules never overlap either. */
function hook_deduction_rules_validate(array &$data, array &$errors, ?array $old): void
{
    if ($errors) return;
    if ($o = range_overlap('deduction_rules', $data['date_from'], $data['date_to'], $old ? (int)$old['id'] : null)) {
        $errors['date_from'] = __('الفترة (%s إلى %s) بتتداخل مع قاعدة موجودة بالفعل (%s إلى %s).', $data['date_from'], $data['date_to'], $o['date_from'], $o['date_to']);
    }
}

/** Old SalaryService.add: value > 0, an active type, and one basic salary per employee and effective date. */
function hook_salary_tx_validate(array &$data, array &$errors, ?array $old): void
{
    if ($errors) return;
    if (!((float)$data['amount'] > 0)) $errors['amount'] = __('القيمة غير صحيحة.');
    $type = q_one('SELECT * FROM salary_tx_types WHERE id = ?', [$data['type_id']]);
    if ($old === null && $type && !$type['active']) $errors['type_id'] = __('نوع الحركة ده متوقف حاليًا: %s', $type['name']);
    if ($type && $type['category'] === 'basic' && q_val("SELECT 1 FROM salary_tx t JOIN salary_tx_types ty ON ty.id = t.type_id WHERE t.employee_id = ? AND ty.category = 'basic' AND t.date_from = ? AND t.id <> ?",
            [$data['employee_id'], $data['date_from'], $old ? (int)$old['id'] : 0])) {
        $errors['date_from'] = __('فيه راتب أساسي مسجَّل بالفعل بنفس تاريخ السريان (%s). عدّل الحركة الحالية أو ألغِها الأول.', $data['date_from']);
    }
}

/** Old advanceCreate: the installment value comes from the cents split; a cancelled advance can't be edited. */
function hook_advances_validate(array &$data, array &$errors, ?array $old): void
{
    if ($old !== null && $old['status'] === 'cancelled') { $errors['_'] = __('السلفة دي ملغاة ومينفعش تتعدل.'); return; }
    if ($errors) return;
    if (!((float)$data['total'] > 0)) { $errors['total'] = __('مبلغ السلفة غير صحيح.'); return; }
    $data['installment_value'] = split_installments((float)$data['total'], (int)$data['installments'])[0];
    if ($old === null) $data['status'] = 'active';
}

function hook_advances_delete(array $old): ?string
{
    return __('السلف مابتتحذفش. استخدم «إلغاء» من تقرير السلف وسجّل السبب.');
}

/** Old periodAdd: official periods never overlap; a closed period's dates can't move and it can't be deleted. */
function hook_payroll_periods_validate(array &$data, array &$errors, ?array $old): void
{
    if ($old !== null && $old['status'] === 'closed' && ($data['date_from'] !== $old['date_from'] || $data['date_to'] !== $old['date_to'])) {
        $errors['_'] = __('الفترة مقفولة؛ افتحها الأول (إعادة فتح) قبل ما تغيّر تواريخها.');
        return;
    }
    if ($errors) return;
    if ($o = range_overlap('payroll_periods', $data['date_from'], $data['date_to'], $old ? (int)$old['id'] : null)) {
        $errors['date_from'] = __('الفترة الجديدة (%s → %s) بتتداخل مع فترة رسمية موجودة: %s (%s → %s). عدّل التواريخ عشان مفيش تداخل.',
            $data['date_from'], $data['date_to'], period_label($o), $o['date_from'], $o['date_to']);
    }
}

function hook_payroll_periods_delete(array $old): ?string
{
    if ($old['status'] === 'closed') return __('مينفعش تحذف فترة مقفولة.');
    if (q_val('SELECT 1 FROM payroll_snapshots WHERE period_id = ?', [$old['id']])) return __('الفترة دي ليها نسخ إقفال محفوظة، فمينفعش تتحذف.');
    return null;
}

/** Old NameListService.manage: departments and job titles are never deleted, only deactivated (employee data stays as is). */
function hook_name_lists_delete(array $old): ?string
{
    return __('القوائم مابتتمسحش عشان بيانات الموظفين ماتتأثرش. شيل علامة «نشط» بدل الحذف.');
}

/** Old AcademicYearService.setActive: only one academic year is active at a time. */
function academic_year_activate(int $id): void
{
    db()->beginTransaction();
    q('UPDATE academic_years SET active = 0 WHERE active = 1 AND id <> ?', [$id]);
    q('UPDATE academic_years SET active = 1 WHERE id = ?', [$id]);
    db()->commit();
}

function hook_academic_years_after(int $id, array $data, ?array $old, array $diff): void
{
    if (!empty($data['active'])) q('UPDATE academic_years SET active = 0 WHERE active = 1 AND id <> ?', [$id]);
}

/** Old ScheduleOverrideService.add: a new personal schedule trims, splits or replaces the employee's overlapping ones. */
function hook_emp_schedules_after(int $id, array $data, ?array $old, array $diff): void
{
    schedule_segment((int)$data['employee_id'], $data['date_from'], $data['date_to'], $id);
}

function schedule_segment(int $empId, string $from, string $to, int $keepId): array
{
    $n = ['trimmed' => 0, 'removed' => 0, 'split' => 0];
    $day = fn(string $d, int $k) => date('Y-m-d', strtotime("$d $k day"));
    foreach (q_all('SELECT * FROM employee_schedules WHERE employee_id = ? AND id <> ? AND date_from <= ? AND date_to >= ?', [$empId, $keepId, $to, $from]) as $ex) {
        $head = $ex['date_from'] < $from; $tail = $ex['date_to'] > $to;
        if ($head && $tail) {
            q('UPDATE employee_schedules SET date_to = ? WHERE id = ?', [$day($from, -1), $ex['id']]);
            q('INSERT INTO employee_schedules (employee_id, date_from, date_to, start_time, end_time, created_by, created_at) VALUES (?,?,?,?,?,?,?)',
                [$empId, $day($to, 1), $ex['date_to'], $ex['start_time'], $ex['end_time'], $ex['created_by'], $ex['created_at']]);
            $n['trimmed']++; $n['split']++;
        } elseif ($head) {
            q('UPDATE employee_schedules SET date_to = ? WHERE id = ?', [$day($from, -1), $ex['id']]); $n['trimmed']++;
        } elseif ($tail) {
            q('UPDATE employee_schedules SET date_from = ? WHERE id = ?', [$day($to, 1), $ex['id']]); $n['trimmed']++;
        } else {
            q('DELETE FROM employee_schedules WHERE id = ?', [$ex['id']]); $n['removed']++;
        }
    }
    if (array_sum($n)) audit('employee_schedules.segment', $empId, $n);
    return $n;
}
