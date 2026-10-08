<?php

declare(strict_types=1);

/**
 * Report page. Included by ReportRenderer::template() with $view. Header and
 * footer strings are escaped here; tutor data arrives as one JSON block that
 * the script reads and renders with textContent only.
 *
 * @var array{season: string, types: string, timezone: string, generated: string,
 *            footer: list<string>, warnings: list<string>, json: string} $view
 */

use AcuityReport\ReportRenderer;

$e = ReportRenderer::e(...);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'">
<title>Availability <?= $e($view['season']) ?></title>
<style>
:root {
  --bg: #ffffff; --fg: #1d2330; --muted: #5d6573; --line: #d9dde3; --panel: #f4f6f8;
  --accent: #1f5fbf; --free-bg: #e3f4e6; --free-fg: #1b5e2a; --booked-bg: #fde9d7; --booked-fg: #8a3b00;
  --unknown-bg: repeating-linear-gradient(45deg, #f1f1f1, #f1f1f1 6px, #e6e6e6 6px, #e6e6e6 12px);
}
@media (prefers-color-scheme: dark) {
  :root {
    --bg: #15181e; --fg: #e6e9ee; --muted: #9aa3b1; --line: #343a45; --panel: #1d2129;
    --accent: #7fb0ff; --free-bg: #173322; --free-fg: #9be2ae; --booked-bg: #3a2614; --booked-fg: #ffbf8a;
    --unknown-bg: repeating-linear-gradient(45deg, #23272f, #23272f 6px, #2b3039 6px, #2b3039 12px);
  }
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--fg); font: 15px/1.4 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
header, footer { padding: 16px 20px; }
header { border-bottom: 1px solid var(--line); }
h1 { font-size: 20px; margin: 0 0 6px; }
.meta { color: var(--muted); margin: 0; }
.meta span { margin-right: 14px; display: inline-block; }
main { display: flex; gap: 20px; padding: 16px 20px; align-items: flex-start; }
nav.tutors { flex: 0 0 220px; }
nav.tutors ul { list-style: none; margin: 0; padding: 0; }
nav.tutors button { width: 100%; text-align: left; padding: 7px 10px; margin: 0 0 2px; border: 0; border-radius: 6px; background: none; color: inherit; font: inherit; cursor: pointer; }
nav.tutors button:hover { background: var(--panel); }
nav.tutors button[aria-current="true"] { background: var(--accent); color: var(--bg); font-weight: 600; }
.tutor-select { display: none; width: 100%; padding: 8px; font: inherit; margin-bottom: 12px; }
section.month { flex: 1; min-width: 0; }
.month-bar { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; }
.month-bar h2 { font-size: 18px; margin: 0; flex: 1; }
.month-bar button { font: inherit; padding: 5px 12px; border: 1px solid var(--line); border-radius: 6px; background: var(--panel); color: inherit; cursor: pointer; }
.month-bar button:disabled { opacity: .4; cursor: default; }
.grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); border-top: 1px solid var(--line); border-left: 1px solid var(--line); }
.weekday { font-size: 12px; font-weight: 600; text-transform: uppercase; color: var(--muted); padding: 6px 8px; border-right: 1px solid var(--line); border-bottom: 1px solid var(--line); }
.cell { min-height: 92px; padding: 6px 8px; border-right: 1px solid var(--line); border-bottom: 1px solid var(--line); }
.cell.blank { background: var(--panel); }
.cell.unknown { background: var(--unknown-bg); }
.cell.past .num { color: var(--muted); }
.num { font-weight: 600; font-size: 13px; }
.wd { display: none; }
.slots { list-style: none; margin: 4px 0 0; padding: 0; }
.slot { margin: 0 0 3px; padding: 2px 6px; border-radius: 4px; font-size: 13px; }
.slot.free { background: var(--free-bg); color: var(--free-fg); }
.slot.booked { background: var(--booked-bg); color: var(--booked-fg); border-left: 3px solid currentColor; }
.slot .label { font-weight: 600; }
.flag { font-size: 12px; font-style: italic; color: var(--muted); }
footer { border-top: 1px solid var(--line); color: var(--muted); font-size: 13px; }
footer p { margin: 0 0 6px; }
.warnings { color: var(--booked-fg); }
@media (max-width: 760px) {
  main { display: block; padding: 12px 16px; }
  nav.tutors { display: none; }
  .tutor-select { display: block; }
  .grid { display: block; border: 0; }
  .weekday, .cell.blank, .cell.empty { display: none; }
  .cell { min-height: 0; border: 1px solid var(--line); border-radius: 6px; margin-bottom: 6px; }
  .wd { display: inline; font-weight: 400; color: var(--muted); }
  header, footer { padding: 12px 16px; }
}
@media print {
  nav.tutors, .tutor-select, .month-bar button { display: none; }
  main { display: block; }
  .cell { break-inside: avoid; }
}
</style>
</head>
<body>
<header>
  <h1>Reconstructed availability</h1>
  <p class="meta">
    <span>Season: <?= $e($view['season']) ?></span>
    <span>Types: <?= $e($view['types']) ?></span>
    <span>Time zone: <?= $e($view['timezone']) ?></span>
    <span>Generated: <?= $e($view['generated']) ?></span>
  </p>
</header>
<main>
  <nav class="tutors" aria-label="Tutors"><ul id="tutor-list"></ul></nav>
  <select class="tutor-select" id="tutor-select" aria-label="Tutor"></select>
  <section class="month">
    <div class="month-bar">
      <button type="button" id="prev" aria-label="Previous month">&lsaquo; Prev</button>
      <h2 id="month-title" aria-live="polite"></h2>
      <button type="button" id="next" aria-label="Next month">Next &rsaquo;</button>
    </div>
    <div class="grid" id="grid"></div>
  </section>
</main>
<footer>
<?php foreach ($view['footer'] as $sentence): ?>
  <p><?= $e($sentence) ?></p>
<?php endforeach; ?>
<?php if ($view['warnings'] !== []): ?>
  <div class="warnings">
    <p><strong>Warnings</strong></p>
<?php foreach ($view['warnings'] as $warning): ?>
    <p><?= $e($warning) ?></p>
<?php endforeach; ?>
  </div>
<?php endif; ?>
</footer>
<script type="application/json" id="report-data"><?= $view['json'] ?></script>
<script>
(function () {
  'use strict';
  var data = JSON.parse(document.getElementById('report-data').textContent);
  var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  var WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  var tutor = 0;
  var month = Math.max(0, data.months.indexOf(data.today.slice(0, 7)));

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) { node.className = className; }
    if (text !== undefined) { node.textContent = text; }
    return node;
  }

  function pad(n) { return (n < 10 ? '0' : '') + n; }

  function slotItem(slot) {
    var item = el('li', 'slot ' + slot.status);
    item.appendChild(el('span', 'time', slot.from + '–' + slot.to));
    var label;
    if (slot.status === 'free') {
      // A merged range longer than one session shows its count: "· 2 free".
      label = slot.sessions > 1 ? ' · ' + slot.sessions + ' free' : ' free';
    } else {
      label = ' booked';
    }
    item.appendChild(el('span', 'label', label));
    if (slot.status === 'booked' && slot.type) {
      item.appendChild(el('span', 'type', ' · ' + slot.type));
    }
    return item;
  }

  function renderMonth() {
    var t = data.tutors[tutor];
    var parts = data.months[month].split('-');
    var year = Number(parts[0]);
    var m = Number(parts[1]);
    document.getElementById('month-title').textContent = MONTHS[m - 1] + ' ' + year + (t ? ' · ' + t.name : '');
    document.getElementById('prev').disabled = month === 0;
    document.getElementById('next').disabled = month === data.months.length - 1;

    var grid = document.getElementById('grid');
    grid.textContent = '';
    WEEKDAYS.forEach(function (name) { grid.appendChild(el('div', 'weekday', name)); });

    // Weekdays from UTC date arithmetic on the date keys: no time zone involved.
    var offset = (new Date(Date.UTC(year, m - 1, 1)).getUTCDay() + 6) % 7;
    var length = new Date(Date.UTC(year, m, 0)).getUTCDate();
    for (var i = 0; i < offset; i++) { grid.appendChild(el('div', 'cell blank')); }
    for (var d = 1; d <= length; d++) {
      var key = year + '-' + pad(m) + '-' + pad(d);
      var day = t ? t.days[key] : undefined;
      var cell = el('div', 'cell');
      if (key < data.today) { cell.classList.add('past'); }
      if (!day) { cell.classList.add('empty'); }
      var num = el('div', 'num', String(d));
      num.appendChild(el('span', 'wd', ' ' + WEEKDAYS[(offset + d - 1) % 7]));
      cell.appendChild(num);
      if (day) {
        if (day.unknown) {
          cell.classList.add('unknown');
          cell.appendChild(el('div', 'flag', 'hours unknown'));
        }
        if (day.slots.length) {
          var list = el('ul', 'slots');
          day.slots.forEach(function (slot) { list.appendChild(slotItem(slot)); });
          cell.appendChild(list);
        }
      }
      grid.appendChild(cell);
    }
  }

  function selectTutor(index) {
    tutor = index;
    var buttons = document.querySelectorAll('#tutor-list button');
    for (var i = 0; i < buttons.length; i++) {
      buttons[i].setAttribute('aria-current', i === index ? 'true' : 'false');
    }
    document.getElementById('tutor-select').value = String(index);
    renderMonth();
  }

  var list = document.getElementById('tutor-list');
  var select = document.getElementById('tutor-select');
  data.tutors.forEach(function (t, index) {
    var button = el('button', '', t.name);
    button.type = 'button';
    button.addEventListener('click', function () { selectTutor(index); });
    var item = el('li');
    item.appendChild(button);
    list.appendChild(item);
    var option = el('option', '', t.name);
    option.value = String(index);
    select.appendChild(option);
  });
  select.addEventListener('change', function () { selectTutor(Number(select.value)); });
  document.getElementById('prev').addEventListener('click', function () { if (month > 0) { month--; renderMonth(); } });
  document.getElementById('next').addEventListener('click', function () { if (month < data.months.length - 1) { month++; renderMonth(); } });

  selectTutor(0);
}());
</script>
</body>
</html>
