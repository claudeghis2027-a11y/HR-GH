<div class="page-head"><div><h1><?= e(__('رفع بيانات الموظفين من Excel')) ?></h1><div class="sub"><?= e(__('القالب بالعربي، والعناوين لازم تفضل زي ما هي.')) ?></div></div></div>
<?php if (!empty($error)): ?><div class="flash err"><?= e($error) ?></div><?php endif; ?>

<div class="grid2">
  <div class="card">
    <h2><?= e(__('1) نزّل القالب')) ?></h2>
    <p class="muted"><?= e(__('املاه (صف لكل موظف) من غير ما تغيّر العناوين. شيت "القوائم" فيه الأقسام والمسميات والصلاحيات المسموحة، وشيت "تعليمات" فيه الشرح.')) ?></p>
    <a class="btn" href="<?= e(url('emp_template')) ?>"><?= e(__('قالب فاضي')) ?></a>
    <?php if (can('employees', 'export')): ?><a class="btn" href="<?= e(url('emp_template', ['filled' => 1])) ?>"><?= e(__('قالب فيه الموظفين الحاليين')) ?></a><?php endif; ?>
  </div>
  <form class="card" method="post" action="<?= e(url('emp_import')) ?>" enctype="multipart/form-data">
    <h2><?= e(__('2) ارفع الملف للمعاينة')) ?></h2>
    <?= csrf_field() ?><input type="hidden" name="op" value="upload">
    <label><?= e(__('ملف .xlsx (حد أقصى 5 ميجا)')) ?> <input type="file" name="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></label>
    <button class="btn primary"><?= e(__('معاينة')) ?></button>
    <p class="muted"><?= e(__('مفيش أي حاجة بتتحفظ قبل ما تأكد بعد المعاينة.')) ?></p>
  </form>
</div>

<?php if (!empty($res)): $s = $res['stats']; ?>
<div class="card">
  <h2><?= e(__('المعاينة')) ?></h2>
  <div class="stats">
    <div class="stat green"><span><?= $s['new'] ?></span><?= e(__('موظف جديد')) ?></div>
    <div class="stat"><span><?= $s['update'] ?></span><?= e(__('تحديث')) ?></div>
    <div class="stat"><span><?= $s['same'] ?></span><?= e(__('من غير تغيير')) ?></div>
    <div class="stat ochre"><span><?= $s['salary'] ?></span><?= e(__('تغيير راتب')) ?></div>
    <div class="stat"><span><?= $s['accounts'] ?></span><?= e(__('صلاحية/حساب')) ?></div>
    <div class="stat warn"><span><?= count($res['errors']) ?></span><?= e(__('صف فيه أخطاء')) ?></div>
  </div>

  <?php if ($res['errors']): ?>
    <div class="flash err"><?= e(__('صلّح الأخطاء دي في الملف وارفعه تاني. مفيش أي حاجة اتحفظت.')) ?></div>
    <div class="table-wrap"><table><thead><tr><th><?= e(__('الصف')) ?></th><th><?= e(__('الأخطاء')) ?></th></tr></thead><tbody>
      <?php foreach ($res['errors'] as $row => $msgs): ?><tr><td class="num"><?= (int)$row ?></td><td><?= e(implode(' · ', $msgs)) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
  <?php if ($res['warnings']): ?>
    <div class="table-wrap"><table><thead><tr><th><?= e(__('الصف')) ?></th><th><?= e(__('تنبيهات')) ?></th></tr></thead><tbody>
      <?php foreach ($res['warnings'] as $row => $msgs): ?><tr><td class="num"><?= (int)$row ?></td><td><?= e(implode(' · ', $msgs)) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>

  <?php if ($res['rows']): ?>
    <?php $colLabels = ['name_ar' => 'الاسم بالعربي', 'name_en' => 'الاسم بالإنجليزي', 'department_id' => 'القسم', 'specialization_id' => 'التخصص', 'job_title_id' => 'المسمى الوظيفي', 'coordinator_id' => 'المنسق', 'hire_date' => 'تاريخ التعيين', 'end_date' => 'تاريخ نهاية الخدمة', 'attendance_exempt' => 'معفى من البصمة', 'nationality' => 'الجنسية', 'phone' => 'رقم التليفون', 'pay_method' => 'طريقة القبض', 'bank_id' => 'البنك', 'papers_received' => 'استلم أوراقه', 'summer_deduction' => 'خصم الصيف', 'first_year_deduction' => 'خصم السنة الأولى', 'first_raise_month' => 'شهر أول علاوة مستحقة', 'first_year_exception' => 'استثناء خصم السنة الأولى']; ?>
    <div class="table-wrap"><table class="small"><thead><tr><th><?= e(__('الصف')) ?></th><th><?= e(__('الكود')) ?></th><th><?= e(__('الاسم')) ?></th><th><?= e(__('العملية')) ?></th><th><?= e(__('الحقول المتغيرة')) ?></th><th><?= e(__('المنسق')) ?></th><th><?= e(__('الراتب')) ?></th></tr></thead><tbody>
      <?php foreach ($res['rows'] as $r): ?>
        <tr><td class="num"><?= (int)$r['row'] ?></td><td><?= $r['id'] === null ? e(__('تلقائي')) : (int)$r['id'] ?></td><td><?= e($r['name']) ?></td>
          <td><span class="badge <?= $r['is_new'] ? 'green' : 'blue' ?>"><?= e(__($r['is_new'] ? 'إضافة' : 'تحديث')) ?></span></td>
          <td><?= e(implode('، ', array_map(fn($k) => __($colLabels[$k] ?? $k), array_keys($r['changes'])))) ?><?= $r['role'] !== null ? ' + ' . e(__('صلاحية')) : '' ?></td>
          <?php $c = $r['coordinator'] ?? null; ?>
          <td><?php if ($c && $c['id'] !== null): ?><?= $c['changed'] ? '<strong>' : '' ?><?= (int)$c['id'] ?> - <?= e((string)$c['name']) ?><?= $c['changed'] ? '</strong> <span class="badge blue">' . e(__('جديد')) . '</span>' : '' ?><?php elseif ($c && $c['cleared']): ?><span class="badge"><?= e(__('هيتشال')) ?></span><?php else: ?>—<?php endif; ?></td>
          <td class="num"><?= $r['salary'] ? e(__('%s من %s', money($r['salary']['amount']), $r['salary']['from'])) : '' ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>

  <?php if (!empty($token)): ?>
  <form method="post" action="<?= e(url('emp_import')) ?>" data-confirm="<?= e(__('هيتم حفظ التغييرات دي. متأكد؟')) ?>">
    <?= csrf_field() ?><input type="hidden" name="op" value="confirm"><input type="hidden" name="token" value="<?= e($token) ?>">
    <?php if ($s['accounts'] > 0): ?>
      <label><?= e(__('كلمة مرور مؤقتة للحسابات الجديدة (لو فاضية، مش هيتعمل حسابات جديدة؛ تغيير الصلاحية للحسابات الموجودة هيتم عادي)')) ?>
        <input type="password" name="password" minlength="10" autocomplete="new-password"></label>
    <?php endif; ?>
    <button class="btn primary"><?= e(__('تأكيد وحفظ')) ?></button>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>
