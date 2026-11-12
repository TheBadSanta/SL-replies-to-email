<?php
declare(strict_types=1);
session_start();
$cfg = require __DIR__ . '/config.php';
require __DIR__ . '/smtp.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function norm(string $s): string {
  $s = str_replace(['ё', 'Ё'], 'е', mb_strtolower($s, 'UTF-8'));
  return trim(preg_replace('/\s+/u', ' ', $s));
}

// ---- optional password ----
if ($cfg['page_password'] !== '') {
  if (isset($_POST['pw']) && hash_equals($cfg['page_password'], (string)$_POST['pw'])) $_SESSION['ok'] = 1;
  if (empty($_SESSION['ok'])) {
    echo '<!doctype html><meta name="viewport" content="width=device-width"><form method="post" style="margin:20vh auto;max-width:300px;font-family:sans-serif">'
       . '<input type="password" name="pw" placeholder="Password" autofocus style="width:100%;padding:10px"></form>';
    exit;
  }
}
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

$db = new PDO('sqlite:' . $cfg['db'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$root = rtrim(realpath($cfg['books_root']) ?: $cfg['books_root'], '/') . '/';

function book(PDO $db, int $id, string $root): array {
  $st = $db->prepare('SELECT * FROM books WHERE id=?'); $st->execute([$id]);
  $b = $st->fetch(PDO::FETCH_ASSOC);
  if (!$b) throw new Exception('Book not found');
  $real = realpath($b['path']);
  if (!$real || strpos($real, $root) !== 0) throw new Exception('File missing or outside library');
  $b['real'] = $real;
  $b['fname'] = trim(($b['author'] ? $b['author'] . ' - ' : '') . $b['title']);
  $b['fname'] = preg_replace('/[\/\\\\:*?"<>|]+/u', '_', mb_substr($b['fname'], 0, 120)) . '.epub';
  return $b;
}

// ---- download ----
if (isset($_GET['dl'])) {
  try { $b = book($db, (int)$_GET['dl'], $root); } catch (Exception $e) { http_response_code(404); exit(h($e->getMessage())); }
  header('Content-Type: application/epub+zip');
  header('Content-Length: ' . filesize($b['real']));
  header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($b['fname']));
  readfile($b['real']); exit;
}

// ---- send to kindle ----
$flash = null;
if (isset($_POST['kindle'])) {
  try {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) throw new Exception('Session expired, reload the page');
    $b = book($db, (int)$_POST['kindle'], $root);
    if (filesize($b['real']) > 48 * 1024 * 1024) throw new Exception('File is over Amazon\'s 50 MB email limit');
    smtp_send_attachment($cfg, $cfg['kindle_email'], $b['title'], $b['real'], $b['fname']);
    $flash = ['ok', 'Sent "' . $b['title'] . '" to Kindle. Usually shows up in 1-5 minutes.'];
  } catch (Exception $e) { $flash = ['err', $e->getMessage()]; }
}

// ---- search ----
$q = trim((string)($_GET['q'] ?? ''));
$mode = in_array($_GET['m'] ?? '', ['author', 'title'], true) ? $_GET['m'] : 'all';
$page = max(1, (int)($_GET['p'] ?? 1)); $per = 50; $off = ($page - 1) * $per;
$rows = []; $total = 0;
$count = (int)$db->query('SELECT COUNT(*) FROM books')->fetchColumn();

if ($q !== '') {
  $tokens = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', norm($q))));
  $hasFts = (bool)$db->query("SELECT 1 FROM sqlite_master WHERE name='books_fts'")->fetchColumn();
  try {
    if (!$hasFts || !$tokens) throw new Exception('no fts');
    $col = $mode === 'all' ? '' : "$mode : ";
    $match = implode(' AND ', array_map(fn($t) => $col . '"' . $t . '"*', $tokens));
    $st = $db->prepare('SELECT COUNT(*) FROM books_fts WHERE books_fts MATCH ?'); $st->execute([$match]);
    $total = (int)$st->fetchColumn();
    $st = $db->prepare("SELECT b.* FROM books_fts f JOIN books b ON b.id=f.rowid WHERE books_fts MATCH ?
                        ORDER BY b.author, b.title LIMIT $per OFFSET $off");
    $st->execute([$match]);
  } catch (Exception $e) {
    // Fallback: LIKE on normalized column ("author | title")
    $field = ['all' => 'search', 'author' => "substr(search,1,instr(search,' | '))",
              'title' => "substr(search,instr(search,' | ')+3)"][$mode];
    $where = implode(' AND ', array_fill(0, max(1, count($tokens)), "$field LIKE ?"));
    $args = array_map(fn($t) => '%' . $t . '%', $tokens ?: [norm($q)]);
    $st = $db->prepare("SELECT COUNT(*) FROM books WHERE $where"); $st->execute($args);
    $total = (int)$st->fetchColumn();
    $st = $db->prepare("SELECT * FROM books WHERE $where ORDER BY author, title LIMIT $per OFFSET $off");
    $st->execute($args);
  }
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
}
$link = fn(array $p) => '?' . http_build_query(array_merge(['q' => $q, 'm' => $mode], $p));
?>
<!doctype html>
<html lang="ru"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Library</title>
<style>
:root{--bg:#fafaf9;--fg:#1c1917;--mut:#78716c;--line:#e7e5e4;--acc:#b45309;--card:#fff}
@media (prefers-color-scheme:dark){:root{--bg:#1c1917;--fg:#f5f5f4;--mut:#a8a29e;--line:#44403c;--acc:#f59e0b;--card:#292524}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--fg);font:16px/1.45 system-ui,sans-serif}
.wrap{max-width:860px;margin:0 auto;padding:24px 16px}
h1{font-size:20px;margin:0 0 4px}.sub{color:var(--mut);font-size:13px;margin-bottom:16px}
form.s{display:flex;gap:8px;flex-wrap:wrap}
form.s input[type=search]{flex:1 1 260px;padding:12px;font-size:16px;border:1px solid var(--line);border-radius:8px;background:var(--card);color:var(--fg)}
select,button{padding:10px 12px;font-size:15px;border-radius:8px;border:1px solid var(--line);background:var(--card);color:var(--fg);cursor:pointer}
button.k{background:var(--acc);color:#fff;border:0}
.flash{padding:10px 12px;border-radius:8px;margin:12px 0}.ok{background:#dcfce7;color:#14532d}.err{background:#fee2e2;color:#7f1d1d}
.row{display:flex;gap:12px;align-items:center;justify-content:space-between;padding:12px 0;border-bottom:1px solid var(--line)}
.t{font-weight:600}.a a{color:var(--mut);text-decoration:none}.a a:hover{text-decoration:underline}
.meta{color:var(--mut);font-size:12px}.act{display:flex;gap:6px;flex-shrink:0}.act a{padding:10px 12px;color:var(--fg);text-decoration:none;border:1px solid var(--line);border-radius:8px;font-size:15px}
.pg{display:flex;gap:12px;margin-top:16px}.pg a{color:var(--acc)}
@media (max-width:560px){.row{flex-direction:column;align-items:flex-start}}
</style></head><body><div class="wrap">
<h1>Library</h1><div class="sub"><?= number_format($count, 0, '.', ' ') ?> EPUB books</div>
<form class="s" method="get">
  <input type="search" name="q" value="<?= h($q) ?>" placeholder="Author or title..." autofocus>
  <select name="m">
    <?php foreach (['all' => 'Everything', 'author' => 'Author', 'title' => 'Title'] as $k => $v): ?>
      <option value="<?= $k ?>"<?= $mode === $k ? ' selected' : '' ?>><?= $v ?></option>
    <?php endforeach; ?>
  </select>
  <button>Search</button>
</form>
<?php if ($flash): ?><div class="flash <?= $flash[0] ?>"><?= h($flash[1]) ?></div><?php endif; ?>
<?php if ($q !== ''): ?>
  <div class="sub" style="margin-top:12px"><?= $total ?> found</div>
  <?php foreach ($rows as $r): ?>
    <div class="row"><div>
      <div class="t"><?= h($r['title']) ?></div>
      <div class="a"><a href="<?= h('?' . http_build_query(['q' => $r['author'], 'm' => 'author'])) ?>"><?= h($r['author']) ?></a></div>
      <div class="meta"><?= h(trim(($r['year'] ?? '') . ' ' . ($r['lang'] ?? ''))) ?> · <?= round($r['size'] / 1048576, 1) ?> MB</div>
    </div><div class="act">
      <a href="?dl=<?= (int)$r['id'] ?>">Download</a>
      <form method="post" action="<?= h($link(['p' => $page])) ?>">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
        <button class="k" name="kindle" value="<?= (int)$r['id'] ?>">Kindle</button>
      </form>
    </div></div>
  <?php endforeach; ?>
  <div class="pg">
    <?php if ($page > 1): ?><a href="<?= h($link(['p' => $page - 1])) ?>">&larr; Prev</a><?php endif; ?>
    <?php if ($off + $per < $total): ?><a href="<?= h($link(['p' => $page + 1])) ?>">Next &rarr;</a><?php endif; ?>
  </div>
<?php endif; ?>
</div></body></html>
