<?php
$current = basename($_SERVER['SCRIPT_NAME']);
$role = Auth::user()['role'];
?>
<div class="sidebar">
  <a href="/dashboard.php" class="<?= $current === 'dashboard.php' ? 'active' : '' ?>">Dashboard</a>
  <a href="/departments.php" class="<?= $current === 'departments.php' ? 'active' : '' ?>">Departments</a>
  <a href="/faculty.php" class="<?= $current === 'faculty.php' ? 'active' : '' ?>">Faculty</a>
  <a href="/courses.php" class="<?= $current === 'courses.php' ? 'active' : '' ?>">Courses</a>
  <a href="/attendance.php" class="<?= $current === 'attendance.php' ? 'active' : '' ?>">Attendance</a>
  <a href="/timetable.php" class="<?= $current === 'timetable.php' ? 'active' : '' ?>">Timetable</a>
  <a href="/documents.php" class="<?= $current === 'documents.php' ? 'active' : '' ?>">Documents</a>
  <?php if (in_array($role, ['hod', 'admin'], true)): ?>
    <a href="/reports.php" class="<?= $current === 'reports.php' ? 'active' : '' ?>">Reports</a>
    <a href="/audit-logs.php" class="<?= $current === 'audit-logs.php' ? 'active' : '' ?>">Audit Logs</a>
  <?php endif; ?>
</div>
