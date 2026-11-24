<?php
declare(strict_types=1);
/*
 * Coordinator column («Coordinator Employee ID») in the employees Excel export / import template:
 * export, parse + preview, validation, atomic apply, old files without the column, and the employee form hook.
 *
 * DESTRUCTIVE: wipes the employees table. Runs only against a database whose name contains "test".
 *   HR_CONFIG=/path/to/test-config.php php bin/test_emp_coordinator.php
 */
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../app/bootstrap.php';

if (!str_contains((string)q_val('SELECT DATABASE()'), 'test')) { fwrite(STDERR, "Refusing: not a test database.\n"); exit(1); }
$GLOBALS['USER'] = ['id' => null, 'username' => 'coord-test', 'is_super' => 1, 'is_staff' => 1, 'role_slug' => 'super_admin', 'dept_scope' => null, 'employee_id' => null, 'perms' => []];

$fails = 0; $n = 0;
function check(string $what, $got, $want): void
{
    global $fails, $n;
    $n++;
    if ($got !== $want) { $fails++; echo "FAIL  $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n"; }
    else echo "ok    $what\n";
}
/** Writes an upload with the given header + rows to a temp .xlsx (the same writer the template uses). */
function xlsx(array $head, array $rows): string
{
    $x = new XlsxWriter();
    $x->addSheet(EMP_SHEET, array_merge([$head], $rows));
    $f = tempnam(emp_import_dir(), 'test');
    $x->save($f);
    return $f;
}
function coord(int $id) { $v = q_val('SELECT coordinator_id FROM employees WHERE id = ?', [$id]); return $v === null || $v === false ? null : (int)$v; }
function reset_data(): void
{
    q('UPDATE employees SET coordinator_id = NULL');
    q('DELETE FROM employees');
    $past = date('Y-m-d', strtotime('-10 days'));
    foreach ([[1, 'أحمد المنسق', null, null], [2, 'منى', null, null], [3, 'خالد (ترك الخدمة)', $past, null], [4, 'سارة', null, 1], [5, 'يوسف', null, 1]] as [$id, $name, $end, $c]) {
        q('INSERT INTO employees (id, name_ar, end_date, coordinator_id) VALUES (?,?,?,?)', [$id, $name, $end, $c]);
    }
}
$H = array_values(EMP_COLS);
$col = fn(string $k) => array_search($k, array_keys(EMP_COLS), true);
$row = function (array $v) use ($col) { $r = array_fill(0, count(EMP_COLS), ''); foreach ($v as $k => $x) $r[$col($k)] = $x; return $r; };

// ── 1. Template + export
reset_data();
check('column header is «Coordinator Employee ID»', EMP_COLS['coordinator'], 'Coordinator Employee ID');
$f = emp_template_file(false);
$rows = (new XlsxReader($f))->rows(EMP_SHEET); unlink($f);
check('empty template has the column', in_array('Coordinator Employee ID', $rows[0], true), true);
$f = emp_template_file(true);
$rows = (new XlsxReader($f))->rows(EMP_SHEET); unlink($f);
$ci = array_search('Coordinator Employee ID', $rows[0], true);
$byId = []; foreach (array_slice($rows, 1) as $r) $byId[(int)$r[0]] = $r;
check('export: employee 4 → coordinator code 1', (string)$byId[4][$ci], '1');
check('export: employee 2 → empty', (string)$byId[2][$ci], '');
// round trip: the exported file re-imported changes nothing
$f = emp_template_file(true);
$res = emp_import_parse($f); unlink($f);
check('round trip: no errors', $res['errors'], []);
check('round trip: no coordinator changes', count(array_filter($res['rows'], fn($r) => array_key_exists('coordinator_id', $r['changes']))), 0);

// ── 2. Valid import: link by employee ID, preview shows the coordinator, apply writes it
reset_data();
$f = xlsx($H, [$row(['id' => 2, 'coordinator' => 1]), $row(['id' => 4, 'coordinator' => '2']), $row(['name_ar' => 'موظف جديد', 'coordinator' => 1]), $row(['id' => 5, 'coordinator' => '-'])]);
$res = emp_import_parse($f); unlink($f);
check('valid: no errors', $res['errors'], []);
$prev = array_column($res['rows'], 'coordinator', 'row');
check('preview row 2: coordinator 1 with name', [$prev[2]['id'], $prev[2]['name'], $prev[2]['changed']], [1, 'أحمد المنسق', true]);
check('preview row 3: coordinator 2 (منى)', [$prev[3]['id'], $prev[3]['name']], [2, 'منى']);
check('preview row 4: new employee coordinator 1', $prev[4]['id'], 1);
check('preview row 5: «-» shows as removed', [$prev[5]['id'], $prev[5]['cleared']], [null, true]);
emp_import_apply($res['rows'], '');
check('applied: 2 → 1', coord(2), 1);
check('applied: 4 → 2', coord(4), 2);
check('applied: new employee 6 → 1', coord(6), 1);
check('applied: 5 cleared by «-»', coord(5), null);
check('change log has the coordinator change', (int)q_val("SELECT COUNT(*) FROM employee_change_log WHERE field = 'coordinator_id' AND employee_id = 4 AND old_value = '1' AND new_value = '2'"), 1);

// ── 3. Validation: name instead of code, unknown, inactive, self. Any error = nothing saved.
reset_data();
$f = xlsx($H, [$row(['id' => 2, 'coordinator' => 1]), $row(['id' => 4, 'coordinator' => 'أحمد المنسق']), $row(['id' => 5, 'coordinator' => 999]),
               $row(['id' => 1, 'coordinator' => 3])]);
$res = emp_import_parse($f); unlink($f);
check('name rejected (ID only)', str_contains(implode(' ', $res['errors'][3] ?? []), 'مش اسم'), true);
check('unknown code rejected', $res['errors'][4] ?? null, [__('كود المنسق %d مش موجود.', 999)]);
check('inactive coordinator rejected', $res['errors'][5] ?? null, [__('المنسق %d خدمته منتهية (مش نشط).', 3)]);
$f = xlsx($H, [$row(['id' => 2, 'coordinator' => 2])]);
$self = emp_import_parse($f); unlink($f);
check('self-coordination rejected', $self['errors'][2] ?? null, [__('الموظف مينفعش يكون منسق لنفسه.')]);
// the page only creates a confirm token when there are no errors at all: simulate the same gate
check('errors present → no plan to confirm', (bool)($res['rows'] && !$res['errors']), false);
check('nothing saved: 2 still has no coordinator', coord(2), null);
// coordinator whose service ends in the same file
$past = date('Y-m-d', strtotime('-1 day'));
$f = xlsx($H, [$row(['id' => 1, 'end_date' => $past]), $row(['id' => 2, 'coordinator' => 1])]);
$res = emp_import_parse($f); unlink($f);
check('coordinator ended in the same file rejected', $res['errors'][3] ?? null, [__('المنسق %d خدمته منتهية (مش نشط).', 1)]);

// ── 4. Atomic apply: a coordinator that ends between preview and confirm rolls back every row
reset_data();
$f = xlsx($H, [$row(['id' => 2, 'coordinator' => 1, 'name_ar' => 'منى (معدلة)']), $row(['id' => 5, 'coordinator' => 4])]);
$res = emp_import_parse($f); unlink($f);
check('atomic: preview valid', $res['errors'], []);
q('UPDATE employees SET end_date = ? WHERE id = 4', [$past]);
$threw = false;
try { emp_import_apply($res['rows'], ''); } catch (RuntimeException $ex) { $threw = true; }
check('atomic: apply refused', $threw, true);
check('atomic: row 1 rolled back (coordinator)', coord(2), null);
check('atomic: row 1 rolled back (name)', q_val('SELECT name_ar FROM employees WHERE id = 2'), 'منى');
check('atomic: row 2 unchanged', coord(5), 1);

// ── 5. Old file without the column: current assignments are kept
reset_data();
$old = array_values(array_filter($H, fn($h) => $h !== 'Coordinator Employee ID'));
$f = xlsx($old, [[4, 'سارة (معدلة)'] + array_fill(2, count($old) - 2, ''), [5, ''] + array_fill(2, count($old) - 2, '')]);
$res = emp_import_parse($f); unlink($f);
check('old file: no errors', $res['errors'], []);
emp_import_apply($res['rows'], '');
check('old file: name updated', q_val('SELECT name_ar FROM employees WHERE id = 4'), 'سارة (معدلة)');
check('old file: coordinator of 4 kept', coord(4), 1);
check('old file: coordinator of 5 kept', coord(5), 1);
// empty cell in a new file also keeps it
$f = xlsx($H, [$row(['id' => 4, 'name_ar' => 'سارة'])]);
$res = emp_import_parse($f); unlink($f);
emp_import_apply($res['rows'], '');
check('empty cell: coordinator kept', coord(4), 1);

// ── 6. Employee form uses the same rule
reset_data();
$data = ['coordinator_id' => 2]; $errors = [];
hook_employees_validate($data, $errors, q_one('SELECT * FROM employees WHERE id = 2'));
check('form: self rejected', $errors['coordinator_id'] ?? null, __('الموظف مينفعش يكون منسق لنفسه.'));
$data = ['coordinator_id' => 3]; $errors = [];
hook_employees_validate($data, $errors, q_one('SELECT * FROM employees WHERE id = 2'));
check('form: inactive rejected', $errors['coordinator_id'] ?? null, __('المنسق %d خدمته منتهية (مش نشط).', 3));
$data = ['coordinator_id' => 1]; $errors = [];
hook_employees_validate($data, $errors, q_one('SELECT * FROM employees WHERE id = 2'));
check('form: valid accepted', $errors, []);

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
