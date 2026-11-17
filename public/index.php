<?php
declare(strict_types=1);

function envFileValues(string $path): array
{
    if (!is_readable($path)) return [];
    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (str_starts_with($line, 'export ')) $line = trim(substr($line, 7));
        if (!str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) continue;
        $value = trim($value);
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && substr($value, -1) === $value[0]) {
            $value = substr($value, 1, -1);
        } else {
            // Ignore inline comments only when preceded by whitespace.
            $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
        }
        $values[$key] = $value;
    }
    return $values;
}

function setting(array $fileEnv, array $keys, string $fallback = ''): string
{
    foreach ($keys as $key) {
        $value = getenv($key);
        if ($value !== false) return (string)$value;
        if (array_key_exists($key, $fileEnv)) return (string)$fileEnv[$key];
    }
    return $fallback;
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function numberId(float $value, int $digits = 0): string
{
    return number_format($value, $digits, ',', '.');
}

function js($value): string
{
    return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
}

$root = dirname(__DIR__, 2); // project root: .../sf-telegram-attendance
$fileEnv = envFileValues($root . DIRECTORY_SEPARATOR . '.env');
$host = setting($fileEnv, ['DB_HOST'], '127.0.0.1');
$port = setting($fileEnv, ['DB_PORT'], '3306');
$db = setting($fileEnv, ['DB_NAME', 'DB_DATABASE'], 'sf_attendance');
$user = setting($fileEnv, ['DB_USER', 'DB_USERNAME'], 'root');
$password = setting($fileEnv, ['DB_PASSWORD', 'DB_PASS'], '');

$modelLabels = [
    'BACKTEST_BASELINE_7D' => 'Baseline 7 Hari',
    'BACKTEST_RF_V2' => 'Random Forest V2',
    'BACKTEST_POISSON_V3' => 'Poisson V3',
];
$selectedModel = (string)($_GET['model'] ?? 'BACKTEST_RF_V2');
if (!isset($modelLabels[$selectedModel])) $selectedModel = 'BACKTEST_RF_V2';
$requestedDate = (string)($_GET['date'] ?? '');
if ($requestedDate !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedDate) || !checkdate((int)substr($requestedDate, 5, 2), (int)substr($requestedDate, 8, 2), (int)substr($requestedDate, 0, 4)))) {
    $requestedDate = '';
}

$error = null;
$dates = [];
$selectedDate = '';
$rows = [];
$chartModels = [];
$history = [];
$sfData = [];
$summary = ['sf' => 0, 'actual' => 0, 'predicted' => 0.0, 'mae' => null];
$lastPrediction = null;

try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, (int)$port, $db);
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $dates = $pdo->query('SELECT DISTINCT prediction_date FROM sf_re_predictions ORDER BY prediction_date DESC')
        ->fetchAll(PDO::FETCH_COLUMN);
    $dates = array_map('strval', $dates);
    $selectedDate = in_array($requestedDate, $dates, true) ? $requestedDate : ($dates[0] ?? '');

    if ($selectedDate !== '') {
        $query = $pdo->prepare(
            'SELECT prediction_date, sf_code, model_name, predicted_re, actual_re
             FROM sf_re_predictions
             WHERE prediction_date = :d
             ORDER BY sf_code, model_name'
        );
        $query->execute(['d' => $selectedDate]);
        $rows = $query->fetchAll();

        $names = [];
        try {
            // Avoid accidental row multiplication if master has duplicate SF codes.
            $nameRows = $pdo->query("SELECT UPPER(TRIM(kode_sf)) AS code, MAX(nama_sf) AS name
                                     FROM sf_master_telkomsel
                                     WHERE kode_sf IS NOT NULL AND TRIM(kode_sf) <> ''
                                     GROUP BY UPPER(TRIM(kode_sf))")->fetchAll();
            foreach ($nameRows as $r) $names[(string)$r['code']] = (string)$r['name'];
        } catch (Throwable $ignore) {
            // Name enrichment is optional. Prediction dashboard still works.
        }

        $bySf = [];
        $byModel = [];
        foreach ($rows as $r) {
            $code = (string)$r['sf_code'];
            $model = (string)$r['model_name'];
            if (!isset($modelLabels[$model])) continue;
            if (!isset($bySf[$code])) {
                $bySf[$code] = [
                    'sf_code' => $code,
                    'sf_name' => $names[strtoupper(trim($code))] ?? 'Belum ada di master',
                    'actual' => null,
                    'predictions' => [],
                ];
            }
            $prediction = (float)$r['predicted_re'];
            $bySf[$code]['predictions'][$model] = $prediction;
            if ($r['actual_re'] !== null) $bySf[$code]['actual'] = (int)$r['actual_re'];
            if (!isset($byModel[$model])) $byModel[$model] = ['sum' => 0.0, 'actual' => 0, 'n' => 0, 'error' => 0.0, 'hasActual' => false];
            $byModel[$model]['sum'] += $prediction;
            $byModel[$model]['n']++;
            if ($r['actual_re'] !== null) {
                $actual = (int)$r['actual_re'];
                $byModel[$model]['actual'] += $actual;
                $byModel[$model]['error'] += abs($actual - $prediction);
                $byModel[$model]['hasActual'] = true;
            }
        }
        foreach ($modelLabels as $key => $label) {
            if (!isset($byModel[$key])) continue;
            $m = $byModel[$key];
            $chartModels[] = [
                'key' => $key, 'label' => $label,
                'predicted' => round($m['sum'], 4),
                'actual' => $m['hasActual'] ? $m['actual'] : null,
                'mae_sf' => $m['hasActual'] && $m['n'] ? round($m['error'] / $m['n'], 4) : null,
            ];
        }
        foreach ($bySf as $sf) {
            if (!isset($sf['predictions'][$selectedModel])) continue;
            $predicted = $sf['predictions'][$selectedModel];
            $sfData[] = [
                'sf_code' => $sf['sf_code'], 'sf_name' => $sf['sf_name'],
                'prediction' => round($predicted, 4), 'actual' => $sf['actual'],
                'error' => $sf['actual'] === null ? null : round(abs($predicted - $sf['actual']), 4),
            ];
        }
        usort($sfData, fn ($a, $b) => $b['prediction'] <=> $a['prediction']);
        $summary['sf'] = count($sfData);
        if (isset($byModel[$selectedModel])) {
            $m = $byModel[$selectedModel];
            $summary['predicted'] = $m['sum'];
            $summary['actual'] = $m['hasActual'] ? $m['actual'] : null;
            $summary['mae'] = $m['hasActual'] && $m['n'] ? $m['error'] / $m['n'] : null;
        }
    }

    // Historical totals for the selected model, always aggregate from stored results.
    $stmt = $pdo->prepare(
        'SELECT prediction_date, COUNT(DISTINCT sf_code) sf_count,
                SUM(predicted_re) predicted,
                IF(COUNT(actual_re) = 0, NULL, SUM(actual_re)) actual
         FROM sf_re_predictions
         WHERE model_name = :m
         GROUP BY prediction_date
         ORDER BY prediction_date ASC'
    );
    $stmt->execute(['m' => $selectedModel]);
    foreach ($stmt->fetchAll() as $r) {
        $history[] = [
            'date' => (string)$r['prediction_date'],
            'predicted' => round((float)$r['predicted'], 4),
            'actual' => $r['actual'] === null ? null : (int)$r['actual'],
        ];
    }
} catch (Throwable $e) {
    error_log('[sf_dashboard] ' . $e->getMessage());
    $error = 'Gagal memuat dashboard. Periksa koneksi MySQL, file .env, dan tabel sf_re_predictions. Detail teknis dicatat pada error log PHP.';
}
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Sales Force Prediction | Makassar</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root { --ink:#17213a; --muted:#72809b; --line:#e9edf5; --back:#f5f7fc; --brand:#e21c38; --blue:#3764de; --teal:#0d9a8c; }
    body{background:var(--back);color:var(--ink);font-family:'DM Sans',sans-serif;font-size:14px}
    h1,h2,h3,h4,h5,.display-value{font-family:'Plus Jakarta Sans',sans-serif}
    .app-nav{background:#fff;border-bottom:1px solid var(--line)}
    .brand-mark{width:38px;height:38px;background:var(--brand);border-radius:12px;display:grid;place-items:center;color:white;font-size:20px;font-weight:800}
    .brand-title{font-weight:800;font-size:15px;letter-spacing:-.3px}
    .brand-sub{font-size:11px;color:var(--muted)}
    .page-title{font-size:27px;font-weight:800;letter-spacing:-1px}
    .eyebrow{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:1.7px;color:var(--brand)}
    .muted{color:var(--muted)}
    .panel{background:white;border:1px solid var(--line);border-radius:18px;box-shadow:0 6px 24px rgba(21,41,77,.025)}
    .kpi{padding:20px 22px;min-height:139px}
    .kpi-title{color:var(--muted);font-size:12px;font-weight:600}
    .display-value{font-size:30px;font-weight:800;letter-spacing:-1.4px;line-height:1.3}
    .kpi-icon{width:38px;height:38px;border-radius:12px;display:grid;place-items:center;font-size:18px;background:#f0f3fa}
    .section-title{font-size:16px;font-weight:800;letter-spacing:-.4px}
    .chip{border:1px solid #f5cdd3;color:#bb2137;background:#fff4f5;padding:6px 10px;border-radius:999px;font-size:11px;font-weight:700}
    .filter-label{font-size:11px;font-weight:700;color:var(--muted);margin-bottom:5px}
    .form-control,.form-select{border-color:#e4e9f3;border-radius:10px;font-size:13px;min-height:39px}
    .form-control:focus,.form-select:focus{border-color:#f49ba8;box-shadow:0 0 0 3px #fce7ea}
    .btn-primary-custom{background:var(--brand);color:white;border:0;border-radius:10px;font-weight:700;font-size:13px;padding:10px 17px}
    .btn-primary-custom:hover{background:#ba1630;color:white}
    .data-table{margin-bottom:0}
    .data-table thead th{background:#fafbfe;color:#8290a8;font-size:11px;font-weight:800;letter-spacing:.35px;padding:14px 15px;border-bottom:1px solid var(--line);white-space:nowrap}
    .data-table tbody td{padding:12px 15px;vertical-align:middle;border-color:#f0f2f7}
    .sf-name{font-weight:700;color:#293550}
    .sf-code{font-size:11px;color:var(--muted)}
    .small-meta{font-size:11px;color:var(--muted)}
    .divider{border-top:1px solid var(--line)}
    .soft-tag{background:#f1f5fd;color:#4868a1;border-radius:7px;padding:4px 7px;font-size:11px;font-weight:700}
    .sf-score{font-weight:800;color:#283754}
    .footer-note{font-size:12px;color:#8b96ad}
    .pagination .page-link{color:#425677;border-color:#e5e9f2;font-size:12px}
    .pagination .active .page-link{background:var(--brand);border-color:var(--brand);color:white}
    .card-pad{padding:22px}
    @media(max-width:768px){.page-title{font-size:22px}.kpi{min-height:auto;padding:17px}.display-value{font-size:24px}.card-pad{padding:16px}}
  </style>
</head>
<body>
<header class="app-nav">
  <div class="container-fluid px-3 px-lg-5 py-3 d-flex justify-content-between align-items-center gap-3">
    <div class="d-flex align-items-center gap-3">
      <div class="brand-mark">SF</div>
      <div><div class="brand-title">Sales Force Intelligence</div><div class="brand-sub">Branch Makassar · Performance & Prediction</div></div>
    </div>
    <span class="chip d-none d-sm-inline-block">HISTORICAL BACKTEST</span>
  </div>
</header>
<main class="container-fluid px-3 px-lg-5 py-4 py-lg-5" style="max-width:1660px">
  <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
    <div>
      <div class="eyebrow mb-2">Sales Analytics / Makassar</div>
      <h1 class="page-title mb-2">Prediction Performance Dashboard</h1>
      <p class="muted mb-0">Pantau prediksi RE, realisasi penjualan, dan kesalahan model berdasarkan data historis.</p>
    </div>
    <span class="chip">Data pengujian historis · bukan prediksi live</span>
  </div>

  <?php if ($error !== null): ?>
    <div class="alert alert-danger rounded-4" role="alert"><?=h($error)?></div>
  <?php elseif ($selectedDate === ''): ?>
    <div class="alert alert-info rounded-4">Belum ada hasil prediksi dalam tabel <code>sf_re_predictions</code>. Jalankan <code>py predict_re_mysql.py 2026-09-24</code> lebih dahulu.</div>
  <?php else: ?>
    <section class="panel card-pad mb-4">
      <form method="get" class="row g-3 align-items-end">
        <div class="col-12 col-sm-5 col-lg-3">
          <label class="filter-label" for="date">TANGGAL PREDIKSI</label>
          <select id="date" name="date" class="form-select">
            <?php foreach ($dates as $d): ?><option value="<?=h($d)?>" <?=$d === $selectedDate ? 'selected' : ''?>><?=h(date('d M Y', strtotime($d)))?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-sm-5 col-lg-4">
          <label class="filter-label" for="model">MODEL PREDIKSI</label>
          <select id="model" name="model" class="form-select">
            <?php foreach ($modelLabels as $key => $label): ?><option value="<?=h($key)?>" <?=$key === $selectedModel ? 'selected' : ''?>><?=h($label)?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-sm-2 col-lg-2"><button type="submit" class="btn btn-primary-custom w-100">Terapkan Filter</button></div>
        <div class="col-12 col-lg-3 text-lg-end"><div class="small-meta">Sumber: <strong>sf_re_predictions</strong></div><div class="small-meta">Mode data: <strong>Backtest</strong></div></div>
      </form>
    </section>

    <section class="row g-3 mb-4">
      <div class="col-6 col-xl-3"><div class="panel kpi"><div class="d-flex justify-content-between align-items-start"><div class="kpi-title">Sales Force Tercakup</div><div class="kpi-icon">👥</div></div><div class="display-value mt-3"><?=numberId((float)$summary['sf'])?></div><div class="small-meta">Pada model dan tanggal terpilih</div></div></div>
      <div class="col-6 col-xl-3"><div class="panel kpi"><div class="d-flex justify-content-between align-items-start"><div class="kpi-title">Realisasi RE</div><div class="kpi-icon">📈</div></div><div class="display-value mt-3"><?=$summary['actual'] === null ? '—' : numberId((float)$summary['actual'])?></div><div class="small-meta">RE aktual (historis)</div></div></div>
      <div class="col-6 col-xl-3"><div class="panel kpi"><div class="d-flex justify-content-between align-items-start"><div class="kpi-title">Prediksi RE</div><div class="kpi-icon">🎯</div></div><div class="display-value mt-3"><?=numberId((float)$summary['predicted'], 2)?></div><div class="small-meta"><?=h($modelLabels[$selectedModel])?></div></div></div>
      <div class="col-6 col-xl-3"><div class="panel kpi"><div class="d-flex justify-content-between align-items-start"><div class="kpi-title">MAE Per SF</div><div class="kpi-icon">📊</div></div><div class="display-value mt-3"><?=$summary['mae'] === null ? '—' : numberId((float)$summary['mae'], 3)?></div><div class="small-meta">Rata-rata error absolut per SF</div></div></div>
    </section>

    <section class="row g-3 mb-4">
      <div class="col-12 col-xl-7">
        <div class="panel card-pad h-100">
          <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3"><div><h2 class="section-title mb-1">Prediksi vs Realisasi</h2><p class="small-meta mb-0">Perbandingan seluruh metode pada <?=h(date('d F Y', strtotime($selectedDate)))?></p></div><span class="soft-tag">RE / Branch</span></div>
          <div id="modelChart" style="min-height:280px"></div>
        </div>
      </div>
      <div class="col-12 col-xl-5">
        <div class="panel card-pad h-100">
          <h2 class="section-title mb-1">Perbandingan Model</h2>
          <p class="small-meta mb-3">Pilih berdasarkan akurasi pada banyak tanggal, bukan satu hari.</p>
          <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Metode</th><th class="text-end">Prediksi</th><th class="text-end">MAE SF</th></tr></thead><tbody>
          <?php foreach ($chartModels as $m): ?><tr><td><span class="<?= $m['key'] === $selectedModel ? 'fw-bold' : '' ?>"><?=h($m['label'])?></span></td><td class="text-end"><?=numberId((float)$m['predicted'],2)?></td><td class="text-end"><?=$m['mae_sf'] === null ? '—' : numberId((float)$m['mae_sf'],3)?></td></tr><?php endforeach; ?>
          </tbody></table></div>
          <div class="divider pt-3 mt-2 small-meta">MAE per SF berbeda dari error total Branch. Metrik ini dihitung untuk tanggal terpilih saja; hasil evaluasi multi-hari perlu dilihat secara terpisah.</div>
        </div>
      </div>
    </section>

    <section class="panel card-pad mb-4">
      <div class="d-flex flex-wrap justify-content-between gap-2 align-items-start mb-2"><div><h2 class="section-title mb-1">Tren Prediksi Historis</h2><p class="small-meta mb-0">Tanggal yang sudah tersimpan di MySQL untuk <?=h($modelLabels[$selectedModel])?></p></div><span class="soft-tag"><?=count($history)?> tanggal tersedia</span></div>
      <?php if (count($history) < 2): ?><div class="small-meta mb-2">Saat ini baru ada satu tanggal backtest. Jalankan backtest pada tanggal lain untuk membuat tren yang lebih informatif.</div><?php endif; ?>
      <div id="trendChart" style="min-height:260px"></div>
    </section>

    <section class="panel mb-4 overflow-hidden">
      <div class="card-pad pb-3 d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div><h2 class="section-title mb-1">Performance per Sales Force</h2><p class="small-meta mb-0">Hasil prediksi individual pada <?=h($selectedDate)?> · <?=h($modelLabels[$selectedModel])?></p></div>
        <div class="d-flex gap-2 flex-wrap"><input id="sfSearch" class="form-control" style="min-width:210px" placeholder="Cari nama / kode SF..."><button type="button" class="btn btn-outline-secondary btn-sm rounded-3 px-3" id="exportBtn">Ekspor CSV</button></div>
      </div>
      <div class="table-responsive">
        <table class="table data-table" id="sfTable"><thead><tr><th style="width:65px">NO.</th><th>SALES FORCE</th><th class="text-end">PREDIKSI RE</th><th class="text-end">REALISASI RE</th><th class="text-end">ERROR ABSOLUT</th></tr></thead><tbody id="sfTableBody"></tbody></table>
      </div>
      <div class="d-flex justify-content-between align-items-center gap-3 px-4 py-3 flex-wrap"><div class="small-meta" id="tableMeta"></div><div class="d-flex gap-2"><button class="btn btn-outline-secondary btn-sm rounded-3" id="prevPage" type="button">Sebelumnya</button><span class="small-meta align-self-center" id="pageMeta"></span><button class="btn btn-outline-secondary btn-sm rounded-3" id="nextPage" type="button">Berikutnya</button></div></div>
    </section>

    <div class="panel p-3 p-lg-4 mb-4" style="border-left:4px solid var(--brand)">
      <div class="fw-bold mb-1">Catatan kualitas data</div>
      <div class="small-meta">Dashboard ini menampilkan hasil backtest yang dibuat setelah histori RE tersedia, bukan prediksi real-time. Angka SF adalah cakupan dataset, bukan jumlah hadir dari Telegram Bot. Data RE untuk SF yang dapat dicocokkan ke master terakhir teridentifikasi pada 28 September 2026; pembaruan sumber dan validasi diperlukan sebelum prediksi live.</div>
    </div>
  <?php endif; ?>
  <p class="footer-note text-center mb-0">Sales Force Intelligence · Prototype analytics dashboard · Telkomsel Branch Makassar</p>
</main>
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script>
(function(){
  const rows = <?=js($sfData)?>;
  const models = <?=js($chartModels)?>;
  const history = <?=js($history)?>;
  const selectedDate = <?=js($selectedDate)?>;
  const fmt = (v,d=2) => v === null || v === undefined ? '—' : Number(v).toLocaleString('id-ID',{minimumFractionDigits:d,maximumFractionDigits:d});
  const escapeHtml = (s) => String(s ?? '').replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const optionsBase = {chart:{toolbar:{show:false},fontFamily:'DM Sans, sans-serif'},grid:{borderColor:'#edf0f6'},dataLabels:{enabled:false},colors:['#e21c38','#3764de','#0d9a8c','#abb4c4'],tooltip:{theme:'light'},legend:{position:'bottom'},noData:{text:'Belum ada data'}};

  if (typeof ApexCharts !== 'undefined' && selectedDate) {
    const labels = [...models.map(m => m.label), 'Realisasi'];
    const actual = models.find(m => m.actual !== null)?.actual ?? null;
    new ApexCharts(document.querySelector('#modelChart'),{
      ...optionsBase,chart:{...optionsBase.chart,type:'bar',height:285},
      series:[{name:'Jumlah RE',data:[...models.map(m=>m.predicted),actual]}],
      xaxis:{categories:labels,labels:{style:{fontSize:'11px'},rotate:-18}},
      plotOptions:{bar:{borderRadius:5,columnWidth:'50%',distributed:true}},
      yaxis:{min:0,labels:{formatter:v=>Math.round(v).toString()}},
      tooltip:{y:{formatter:v=>fmt(v,2)+' RE'}},
      legend:{show:false}
    }).render();
    new ApexCharts(document.querySelector('#trendChart'),{
      ...optionsBase,chart:{...optionsBase.chart,type:'line',height:255},
      series:[{name:'Prediksi RE',data:history.map(x=>x.predicted)},{name:'Realisasi RE',data:history.map(x=>x.actual)}],
      colors:['#3764de','#e21c38'],stroke:{curve:'smooth',width:3},markers:{size:history.length<=5?5:2},
      xaxis:{categories:history.map(x=>x.date),labels:{rotate:-25}},
      yaxis:{min:0,labels:{formatter:v=>Math.round(v).toString()}},
      tooltip:{y:{formatter:v=>v===null?'—':fmt(v,2)+' RE'}}
    }).render();
  } else if (selectedDate) {
    for(const id of ['modelChart','trendChart']) document.getElementById(id).innerHTML='<p class="small-meta py-5 text-center">Grafik memerlukan akses internet untuk memuat ApexCharts dari CDN. Data tabel tetap tersedia.</p>';
  }

  const tbody = document.getElementById('sfTableBody');
  if(!tbody) return;
  const input = document.getElementById('sfSearch');
  const pageSize = 15;
  let page = 1;
  let filtered = rows;
  const render = () => {
    const pages = Math.max(1,Math.ceil(filtered.length/pageSize));
    page = Math.max(1,Math.min(page,pages));
    const pageRows = filtered.slice((page-1)*pageSize,page*pageSize);
    tbody.innerHTML = pageRows.length ? pageRows.map((r,i) => `
      <tr><td class="small-meta">${(page-1)*pageSize+i+1}</td>
      <td><div class="sf-name">${escapeHtml(r.sf_name)}</div><div class="sf-code">${escapeHtml(r.sf_code)}</div></td>
      <td class="text-end sf-score">${fmt(r.prediction,2)}</td>
      <td class="text-end">${fmt(r.actual,0)}</td>
      <td class="text-end">${fmt(r.error,2)}</td></tr>`).join('') : '<tr><td colspan="5" class="text-center muted py-4">Tidak ada SF yang cocok.</td></tr>';
    document.getElementById('tableMeta').textContent=`${filtered.length} SF · menampilkan ${pageRows.length} per halaman`;
    document.getElementById('pageMeta').textContent=`${page} / ${pages}`;
    document.getElementById('prevPage').disabled=page<=1;
    document.getElementById('nextPage').disabled=page>=pages;
  };
  input.addEventListener('input',()=>{const q=input.value.trim().toLowerCase();filtered=rows.filter(r=>(r.sf_code+' '+r.sf_name).toLowerCase().includes(q));page=1;render()});
  document.getElementById('prevPage').addEventListener('click',()=>{page--;render()});
  document.getElementById('nextPage').addEventListener('click',()=>{page++;render()});
  document.getElementById('exportBtn').addEventListener('click',()=>{
    // Guard spreadsheet apps against formula injection.
    const safe = x => {const s=String(x??'');return '"'+(/^[\s]*[=+@\-]/.test(s)?"'"+s:s).replace(/"/g,'""')+'"'};
    const lines=[['Tanggal','Kode SF','Nama SF','Prediksi RE','Realisasi RE','Error Absolut'].map(safe).join(',')];
    for(const r of filtered) lines.push([selectedDate,r.sf_code,r.sf_name,r.prediction,r.actual??'',r.error??''].map(safe).join(','));
    const blob=new Blob(['\uFEFF'+lines.join('\r\n')],{type:'text/csv;charset=utf-8;'});
    const url=URL.createObjectURL(blob);const a=document.createElement('a');a.href=url;a.download='sf_prediction_'+selectedDate+'.csv';document.body.appendChild(a);a.click();a.remove();URL.revokeObjectURL(url);
  });
  render();
})();
</script>
</body>
</html>
