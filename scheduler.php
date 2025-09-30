<?php
session_start();
date_default_timezone_set('America/Chicago'); // user's timezone per developer note

// ---------- Configuration ----------
define('START_MINUTES', 9*60);   // 9:00 = minutes from midnight
define('END_MINUTES', 18*60);    // 18:00 = 6pm
define('SLOT_MIN', 15);          // 15-minute granularity
define('LUNCH_START', 12*60);    // 12:00
define('LUNCH_END', 13*60);      // 13:00
define('GRID_MINUTE', 2.0);      // each grid unit = 2 minutes

$masterfile = __DIR__ . '/masterlist.json';

// ---------- Helpers ----------
function load_masterlist() {
    global $masterfile;
    if (!file_exists($masterfile)) {
        file_put_contents($masterfile, json_encode([], JSON_PRETTY_PRINT));
    }
    $json = file_get_contents($masterfile);
    $arr = json_decode($json, true);
    if (!is_array($arr)) $arr = [];
    return $arr;
}

function save_masterlist($arr) {
    global $masterfile;
    file_put_contents($masterfile, json_encode(array_values($arr), JSON_PRETTY_PRINT));
}

function minutes_to_hm($m) {
    $h = floor($m/60);
    $mm = $m % 60;
    return sprintf("%02d:%02d", $h, $mm);
}

function hm_to_minutes($hm) {
    if (!$hm) return null;
    list($h,$m) = explode(':', $hm);
    return intval($h)*60 + intval($m);
}

// Euclidean grid distance -> minutes (2 * sqrt(dx^2 + dy^2))
function drive_minutes($a, $b) {
    if (!$a || !$b) return 0;
    $dx = $a['x'] - $b['x'];
    $dy = $a['y'] - $b['y'];
    $d = sqrt($dx*$dx + $dy*$dy);
    return $d * GRID_MINUTE;
}

// Round up to nearest quarter-slot
function round_up_slot($minutes) {
    $r = ceil($minutes / SLOT_MIN) * SLOT_MIN;
    return $r;
}

// duration based on new/established
function visit_duration($patient) {
    return (!empty($patient['new'])) ? 60 : 30;
}

// load today's list from session
function load_todays() {
    if (!isset($_SESSION['todays'])) $_SESSION['todays'] = [];
    return $_SESSION['todays'];
}
function save_todays($arr) {
    $_SESSION['todays'] = $arr;
}

// ---------- POST Actions: masterlist CRUD & today's list editing ----------
$master = load_masterlist();
$todays = load_todays();
$action = $_REQUEST['action'] ?? '';

if ($action === 'add_master') {
    $name = trim($_POST['name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $x = floatval($_POST['x'] ?? 0);
    $y = floatval($_POST['y'] ?? 0);
    if ($name !== '') {
        $id = time() + rand(1,999);
        $master[] = ['id'=>$id, 'name'=>$name, 'address'=>$address, 'x'=>$x, 'y'=>$y];
        save_masterlist($master);
    }
    header("Location: ".$_SERVER['PHP_SELF']);
    exit;
}

if ($action === 'add_to_tomorrow') {
    $pid = intval($_POST['patient_id']);
    $master_item = null;
    foreach ($master as $m) if ($m['id']==$pid) $master_item = $m;
    if ($master_item) {
        // windows optional
        $start = $_POST['win_start'] ?? '';
        $end = $_POST['win_end'] ?? '';
        $newFlag = isset($_POST['is_new']) ? true : false;
        $entry = [
            'uid' => uniqid(),
            'master_id' => $master_item['id'],
            'name' => $master_item['name'],
            'address' => $master_item['address'],
            'x' => $master_item['x'],
            'y' => $master_item['y'],
            'new' => $newFlag,
            'win_start' => $start ?: null,
            'win_end' => $end ?: null
        ];
        $todays[] = $entry;
        save_todays($todays);
    }
    header("Location: ".$_SERVER['PHP_SELF']);
    exit;
}

if ($action === 'delete_today') {
    $uid = $_POST['uid'] ?? '';
    $todays = array_values(array_filter($todays, function($p) use($uid){ return $p['uid'] !== $uid; }));
    save_todays($todays);
    header("Location: ".$_SERVER['PHP_SELF']);
    exit;
}

if ($action === 'move_up' || $action === 'move_down') {
    $uid = $_POST['uid'] ?? '';
    $idx = null;
    foreach ($todays as $i=>$p) if ($p['uid']==$uid) $idx=$i;
    if ($idx !== null) {
        if ($action === 'move_up' && $idx>0) {
            $tmp = $todays[$idx-1];
            $todays[$idx-1] = $todays[$idx];
            $todays[$idx] = $tmp;
        } elseif ($action === 'move_down' && $idx < count($todays)-1) {
            $tmp = $todays[$idx+1];
            $todays[$idx+1] = $todays[$idx];
            $todays[$idx] = $tmp;
        }
        save_todays($todays);
    }
    header("Location: ".$_SERVER['PHP_SELF']);
    exit;
}

if ($action === 'clear_today') {
    save_todays([]);
    header("Location: ".$_SERVER['PHP_SELF']);
    exit;
}

// ---------- Scheduling: core and optimization ----------
function compute_schedule_for_order($order) {
    // order = array of patient entries (each contains x,y,win_start/win_end, new flag)
    // returns schedule entries: [{patient, start_min, end_min, drive_before_min}]
    $schedule = [];
    $time = START_MINUTES;
    // We will always round to SLOT_MIN
    $prev_loc = null;
    foreach ($order as $i => $p) {
        // drive time from prev_loc to current
        $drive = $prev_loc ? drive_minutes($prev_loc, $p) : 0;
        $drive_rounded = $drive; // not rounding - keep fractional for total optimization, but when placing times we round up
        // arrival earliest after driving
        $arrival = $time + $drive_rounded;
        // if arrival crosses lunch, push to after lunch (simple policy)
        if ($arrival < LUNCH_END && $time < LUNCH_END && $arrival > LUNCH_START) {
            // push to after lunch start
            $arrival = LUNCH_END;
        } elseif ($arrival <= LUNCH_START && ($arrival + visit_duration($p)) > LUNCH_START) {
            // would cross into lunch: push to LUNCH_END
            $arrival = LUNCH_END;
        }
        // respect patient's start window if given
        if (!empty($p['win_start'])) {
            $ws = hm_to_minutes($p['win_start']);
            if ($arrival < $ws) $arrival = $ws;
        }
        // round up to quarter
        $arrival = round_up_slot($arrival);
        $dur = visit_duration($p);
        $end = $arrival + $dur;
        // If there's an end window and we exceed it, we still schedule but mark violation later
        // If end exceeds END_MINUTES, mark infeasible (but still return schedule)
        $schedule[] = [
            'patient' => $p,
            'start' => $arrival,
            'end' => $end,
            'drive_before' => $drive_rounded
        ];
        $time = $end;
        $prev_loc = $p;
    }
    return $schedule;
}

function schedule_total_drive($schedule) {
    $sum = 0.0;
    foreach ($schedule as $entry) $sum += $entry['drive_before'];
    return $sum;
}

function schedule_dead_time($schedule) {
    // total time from first start to last end minus visits and drive. We'll approximate dead as gaps between end+drive and next start.
    $dead = 0.0;
    for ($i=0;$i<count($schedule)-1;$i++) {
        $cur = $schedule[$i];
        $next = $schedule[$i+1];
        // time leaving current ends at cur['end'], then drive to next is next['drive_before'] (drive before next), arrival expected = cur['end'] + drive
        $expected_arrival = $cur['end'] + $next['drive_before'];
        if ($next['start'] > $expected_arrival) $dead += ($next['start'] - $expected_arrival);
    }
    return $dead;
}

function violates_windows($schedule) {
    $violations = [];
    foreach ($schedule as $entry) {
        $p = $entry['patient'];
        $s = $entry['start'];
        $e = $entry['end'];
        $v = false;
        if (!empty($p['win_start'])) {
            $ws = hm_to_minutes($p['win_start']);
            if ($s < $ws) $v = true;
        }
        if (!empty($p['win_end'])) {
            $we = hm_to_minutes($p['win_end']);
            if ($e > $we) $v = true;
        }
        if ($e > END_MINUTES) $v = true;
        if ($s < START_MINUTES) $v = true;
        if ($v) $violations[] = $p['uid'];
    }
    return $violations;
}

// Brute-force optimization of order to minimize total driving time, up to n<=8
function optimize_order($patients) {
    $n = count($patients);
    if ($n <= 1) return $patients;
    // create all permutations
    $best = null;
    $best_drive = INF;
    $perms = new RecursiveIteratorIterator(new RecursiveArrayIterator([0]));
    // We'll implement simple recursive permutation generator
    $indices = range(0,$n-1);
    $used = array_fill(0,$n,false);
    $perm = [];
    $best_schedule = null;

    $found = false;
    $stack = function($depth) use (&$stack, &$used, &$perm, $patients, $n, &$best_drive, &$best_schedule, &$best) {
        if ($depth === $n) {
            $order = [];
            foreach ($perm as $i) $order[] = $patients[$i];
            $sched = compute_schedule_for_order($order);
            $drive = schedule_total_drive($sched);
            // choose minimal drive, tiebreak minimal dead time
            if ($drive + 1e-6 < $best_drive) {
                $best_drive = $drive;
                $best_schedule = $sched;
                $best = $order;
            } elseif (abs($drive - $best_drive) < 1e-6) {
                // tie-breaker: smaller dead time
                $cur_dead = schedule_dead_time($sched);
                $best_dead = schedule_dead_time($best_schedule);
                if ($cur_dead < $best_dead) {
                    $best = $order;
                    $best_schedule = $sched;
                }
            }
            return;
        }
        for ($i=0;$i<$n;$i++) {
            if ($used[$i]) continue;
            $used[$i] = true;
            $perm[$depth] = $i;
            $stack($depth+1);
            $used[$i] = false;
        }
    };
    $stack(0);
    return ['order'=>$best, 'schedule'=>$best_schedule];
}

// If user requested generate or optimize:
$draft_schedule = null;
$violations = [];
if (isset($_REQUEST['action']) && ($_REQUEST['action'] === 'generate' || $_REQUEST['action'] === 'optimize_schedule')) {
    // make a local copy of today's patients in the order they appear by default
    $patients = $todays;
    if (count($patients) == 0) {
        $draft_schedule = [];
    } else {
        if ($_REQUEST['action'] === 'optimize_schedule') {
            // brute-force optimize order to minimize drive time
            if (count($patients) > 8) {
                // fallback: simple greedy nearest neighbor
                $order = [$patients[0]];
                $remaining = array_slice($patients,1);
                while (count($remaining)>0) {
                    $last = end($order);
                    $best_idx = 0; $best_d = INF;
                    foreach ($remaining as $k=>$cand) {
                        $d = drive_minutes($last, $cand);
                        if ($d < $best_d) { $best_d = $d; $best_idx = $k; }
                    }
                    $order[] = $remaining[$best_idx];
                    array_splice($remaining, $best_idx, 1);
                }
                $patients = $order;
                $draft_schedule = compute_schedule_for_order($patients);
            } else {
                $opt = optimize_order($patients);
                $patients = $opt['order'];
                $draft_schedule = $opt['schedule'];
            }
        } else {
            // generate with given order (todays order)
            $draft_schedule = compute_schedule_for_order($patients);
        }
        $violations = violates_windows($draft_schedule);
    }
}

// ---------- HTML / UI ----------
?><!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Home Visits Scheduler — Prototype</title>
<style>
body{font-family: Arial, sans-serif; margin:20px;}
.column { float:left; width:48%; margin-right:2%; }
.card { border:1px solid #ddd; padding:10px; margin-bottom:10px; background:#fafafa; }
.clearfix::after { content:""; display:table; clear:both; }
.small { font-size:0.9em; color:#666; }
.bad { color:darkred; font-weight:bold; }
.table { width:100%; border-collapse:collapse; }
.table th, .table td { border:1px solid #eee; padding:6px; text-align:left; }
.btn { padding:6px 10px; border:1px solid #888; background:#eee; text-decoration:none; margin-right:6px; }
.form-inline input { margin-right:6px; }
</style>
</head>
<body>
<h1>Home Visits Scheduler — Prototype</h1>
<div class="clearfix">
  <div class="column">
    <div class="card">
      <h3>Masterlist</h3>
      <form method="post" style="margin-bottom:8px">
        <input type="hidden" name="action" value="add_master">
        <input name="name" placeholder="Name" required>
        <input name="address" placeholder="Address">
        <input name="x" placeholder="X" size="3">
        <input name="y" placeholder="Y" size="3">
        <button class="btn" type="submit">Add to masterlist</button>
      </form>
      <table class="table small">
        <tr><th>Name</th><th>Addr</th><th>XY</th><th>Add</th></tr>
        <?php foreach($master as $m): ?>
          <tr>
            <td><?php echo htmlspecialchars($m['name']) ?></td>
            <td><?php echo htmlspecialchars($m['address']) ?></td>
            <td><?php echo "{$m['x']},{$m['y']}" ?></td>
            <td>
              <form method="post" style="display:inline">
                <input type="hidden" name="action" value="add_to_tomorrow">
                <input type="hidden" name="patient_id" value="<?php echo $m['id'] ?>">
                <label class="small">New?<input type="checkbox" name="is_new"></label><br>
                <label class="small">Window:</label>
                <input type="time" name="win_start" style="width:90px">
                <input type="time" name="win_end" style="width:90px">
                <button class="btn" type="submit">Add →</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>

    <div class="card">
      <h3>Today's list (tomorrow)</h3>
      <form method="post" style="display:inline">
        <input type="hidden" name="action" value="clear_today">
        <button class="btn" type="submit">Clear list</button>
      </form>
      <form method="post" style="display:inline">
        <input type="hidden" name="action" value="generate">
        <button class="btn" type="submit">Generate Draft Schedule</button>
      </form>
      <form method="post" style="display:inline">
        <input type="hidden" name="action" value="optimize_schedule">
        <button class="btn" type="submit">Optimize Order (min drive)</button>
      </form>

      <table class="table small" style="margin-top:8px">
        <tr><th>#</th><th>Name</th><th>New?</th><th>Window</th><th>Actions</th></tr>
        <?php foreach($todays as $i=>$p): ?>
          <tr>
            <td><?php echo $i+1 ?></td>
            <td><?php echo htmlspecialchars($p['name']) ?><br><span class="small"><?php echo htmlspecialchars($p['address']) ?></span></td>
            <td><?php echo !empty($p['new']) ? 'Yes' : 'No' ?></td>
            <td><?php echo ($p['win_start'] ?? '') . ($p['win_end'] ? ' - '.$p['win_end'] : '') ?></td>
            <td>
              <form method="post" style="display:inline">
                <input type="hidden" name="action" value="move_up">
                <input type="hidden" name="uid" value="<?php echo $p['uid'] ?>">
                <button class="btn" type="submit">Up</button>
              </form>
              <form method="post" style="display:inline">
                <input type="hidden" name="action" value="move_down">
                <input type="hidden" name="uid" value="<?php echo $p['uid'] ?>">
                <button class="btn" type="submit">Down</button>
              </form>
              <form method="post" style="display:inline">
                <input type="hidden" name="action" value="delete_today">
                <input type="hidden" name="uid" value="<?php echo $p['uid'] ?>">
                <button class="btn" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (count($todays)==0): ?>
          <tr><td colspan="5" class="small">No patients selected for tomorrow yet.</td></tr>
        <?php endif; ?>
      </table>
    </div>
  </div>

  <div class="column">
    <div class="card">
      <h3>Draft Schedule</h3>
      <?php if ($draft_schedule === null): ?>
        <p class="small">No draft generated yet. Click "Generate Draft Schedule" or "Optimize Order".</p>
      <?php else: ?>
        <?php if (count($draft_schedule) == 0): ?>
          <p class="small">No patients to schedule.</p>
        <?php else: ?>
          <?php
            $total_drive = schedule_total_drive($draft_schedule);
            $total_dead = schedule_dead_time($draft_schedule);
          ?>
          <p class="small">Total estimated drive: <?php echo round($total_drive,1) ?> minutes. Estimated idle gaps: <?php echo round($total_dead,1) ?> minutes.</p>
          <table class="table">
            <tr><th>Time</th><th>Patient</th><th>Visit</th><th>Drive before</th></tr>
            <?php foreach($draft_schedule as $i=>$e):
                $p = $e['patient'];
                $start = minutes_to_hm($e['start']);
                $end = minutes_to_hm($e['end']);
                $drive_b = round($e['drive_before'],1);
                $violate = in_array($p['uid'], $violations);
            ?>
              <tr>
                <td><?php echo $start ?> - <?php echo $end ?></td>
                <td>
                  <?php if ($violate): ?><span class="bad">*!*</span><?php endif; ?>
                  <strong><?php echo htmlspecialchars($p['name']) ?></strong><br>
                  <span class="small"><?php echo htmlspecialchars($p['address']) ?></span>
                </td>
                <td><?php echo visit_duration($p) ?> min <?php if (!empty($p['new'])) echo "(new)"; ?></td>
                <td><?php echo $drive_b ?> min</td>
              </tr>
            <?php endforeach; ?>
          </table>
          <?php if (count($violations) > 0): ?>
            <p class="bad small">Some appointments violate requested time windows or schedule bounds. Violated entries marked with <span class="bad">*!*</span>.</p>
          <?php endif; ?>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <div class="card">
      <h3>Notes & design</h3>
      <ul class="small">
        <li>Appointment times are 15-minute aligned. Established = 30 min, New = 60 min.</li>
        <li>Drive time estimated as <code>2 * sqrt(dx^2 + dy^2)</code> minutes (grid units ≈ 2 minutes).</li>
        <li>Optimize performs brute-force permutations when ≤ 8 patients, otherwise falls back to greedy nearest neighbor.</li>
        <li>Lunch policy: the scheduler will avoid scheduling across 12:00–13:00 by pushing appointments to after 13:00.</li>
      </ul>
    </div>
  </div>
</div>
</body>
</html>
