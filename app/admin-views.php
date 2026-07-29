<?php

declare(strict_types=1);

use Tenyen\Analytics\OrganizationClassifier;
use Tenyen\Analytics\SessionAnalytics;
use Tenyen\Analytics\Translator;
require_once __DIR__ . '/admin-auth.php';

function tyaav_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function tyaav_t(string $key, array $replacements = []): string
{
    global $tyaavTranslator;
    return $tyaavTranslator instanceof Translator ? $tyaavTranslator->get($key, $replacements) : $key;
}

function tyaav_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
}

function tyaav_safe_url(string $url): string
{
    $url = trim($url);
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true) && filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
}

function tyaav_link(string $url, string $label): string
{
    $safe = tyaav_safe_url($url);
    return $safe === '' ? tyaav_h($label) : '<a class="out-link" target="_blank" rel="noopener noreferrer" href="' . tyaav_h($safe) . '">' . tyaav_h($label) . '</a>';
}

function tyaav_page_link(string $path, string $label, string $siteUrl): string
{
    $label = trim($label) !== '' ? $label : ($path !== '' ? $path : '名称なし');
    if (preg_match('~^https?://~i', $path)) return tyaav_link($path, $label);
    $path = trim($path);
    if ($path === '') return tyaav_h($label);
    if (!str_starts_with($path, '/')) $path = '/' . $path;
    return tyaav_link(rtrim($siteUrl, '/') . $path, $label);
}

function tyaav_referrer(string $url): string
{
    if ($url === '') return 'Direct';
    $host = (string)(parse_url($url, PHP_URL_HOST) ?: $url);
    return tyaav_link($url, $host);
}

function tyaav_duration(int $milliseconds): string
{
    $seconds = max(0, (int)round($milliseconds / 1000));
    if ($seconds < 60) return $seconds . '秒';
    $minutes = intdiv($seconds, 60);
    $remaining = $seconds % 60;
    if ($minutes < 60) return $minutes . '分' . str_pad((string)$remaining, 2, '0', STR_PAD_LEFT) . '秒';
    return intdiv($minutes, 60) . '時間' . ($minutes % 60) . '分';
}

/** @param array{category:string,label:string,icon:string,featured:bool,confidence:int,reason:string} $classification */
function tyaav_badge(array $classification): string
{
    $label = isset($classification['label_key']) ? tyaav_t($classification['label_key']) : $classification['label'];
    $reason = isset($classification['reason_key']) ? tyaav_t($classification['reason_key']) : $classification['reason'];
    return '<span class="badge badge--' . tyaav_h($classification['category']) . '" title="'
        . tyaav_h($reason . ' / ' . $classification['confidence'] . '%') . '">'
        . tyaav_h($classification['icon'] . ' ' . $label) . '</span>';
}

function tyaav_valid_date(string $date): string
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($date), $m)) return '';
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $date : '';
}

/** @return array{period:string,actor:string,from:string,to:string,label:string,days:int,start_local:DateTimeImmutable,end_local:DateTimeImmutable,start_utc:string,end_utc:string} */
function tyaav_range(array $query, DateTimeZone $timezone): array
{
    $period = (string)($query['analysis_period'] ?? '7d');
    if (!in_array($period, ['today','yesterday','7d','30d','month','custom'], true)) $period = '7d';
    $actor = (string)($query['analysis_actor'] ?? 'human');
    if (!in_array($actor, ['human','bot','all'], true)) $actor = 'human';
    $today = new DateTimeImmutable('today', $timezone);
    switch ($period) {
        case 'today': $start=$today; $end=$today->modify('+1 day'); break;
        case 'yesterday': $start=$today->modify('-1 day'); $end=$today; break;
        case '30d': $start=$today->modify('-29 days'); $end=$today->modify('+1 day'); break;
        case 'month': $start=$today->modify('first day of this month'); $end=$today->modify('+1 day'); break;
        case 'custom':
            $from=tyaav_valid_date((string)($query['analysis_from']??''));
            $to=tyaav_valid_date((string)($query['analysis_to']??''));
            $start=$from!==''?new DateTimeImmutable($from,$timezone):$today->modify('-6 days');
            $inclusive=$to!==''?new DateTimeImmutable($to,$timezone):$today;
            if($start>$inclusive)[$start,$inclusive]=[$inclusive,$start];
            $end=$inclusive->modify('+1 day');
            break;
        default: $start=$today->modify('-6 days'); $end=$today->modify('+1 day');
    }
    if ((int)$start->diff($end)->days > 730) $start=$end->modify('-730 days');
    $utc=new DateTimeZone('UTC');
    return [
        'period'=>$period,'actor'=>$actor,'from'=>$start->format('Y-m-d'),'to'=>$end->modify('-1 day')->format('Y-m-d'),
        'label'=>$start->format('Y-m-d').' ～ '.$end->modify('-1 day')->format('Y-m-d'),
        'days'=>max(1,(int)$start->diff($end)->days),'start_local'=>$start,'end_local'=>$end,
        'start_utc'=>$start->setTimezone($utc)->format('Y-m-d H:i:s'),'end_utc'=>$end->setTimezone($utc)->format('Y-m-d H:i:s'),
    ];
}

/** @return array{sql:string,params:list<string>} */
function tyaav_scope(array $range, string $alias = ''): array
{
    $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    $sql = $prefix . 'occurred_at >= ? AND ' . $prefix . 'occurred_at < ?';
    if ($range['actor'] === 'human') $sql .= ' AND ' . $prefix . 'is_bot = 0';
    elseif ($range['actor'] === 'bot') $sql .= ' AND ' . $prefix . 'is_bot = 1';
    return ['sql'=>$sql,'params'=>[$range['start_utc'],$range['end_utc']]];
}

function tyaav_filter_html(array $range): string
{
    ob_start(); ?>
<form class="filters" data-view-filter>
<label>期間<select name="analysis_period"><?php foreach(['today'=>'今日','yesterday'=>'昨日','7d'=>'直近7日','30d'=>'直近30日','month'=>'今月','custom'=>'カスタム'] as $value=>$label): ?><option value="<?= tyaav_h($value) ?>"<?= $range['period']===$value?' selected':'' ?>><?= tyaav_h($label) ?></option><?php endforeach; ?></select></label>
<label>開始日<input type="date" name="analysis_from" value="<?= tyaav_h($range['from']) ?>"></label><label>終了日<input type="date" name="analysis_to" value="<?= tyaav_h($range['to']) ?>"></label>
<label>対象<select name="analysis_actor"><?php foreach(['human'=>'人間のみ','bot'=>'Botのみ','all'=>'すべて'] as $value=>$label): ?><option value="<?= tyaav_h($value) ?>"<?= $range['actor']===$value?' selected':'' ?>><?= tyaav_h($label) ?></option><?php endforeach; ?></select></label>
<button class="button" type="submit">適用</button></form><p class="note"><?= tyaav_h($range['label']) ?>。集計条件は画面遷移せず反映されます。</p>
<?php return (string)ob_get_clean();
}

/** @param list<array<string,mixed>> $rows @return list<array{label:string,pageviews:int,visitors:int,sessions:int}> */
function tyaav_fill_timeline(array $rows, DateTimeImmutable $start, DateTimeImmutable $end, string $grain): array
{
    $map=[]; foreach($rows as $row)$map[(string)$row['bucket']]=$row;
    $result=[];
    if($grain==='hour'){$cursor=$start->setTime(0,0);$step=new DateInterval('PT1H');$key='Y-m-d H:00';$label='m/d H時';}
    elseif($grain==='day'){$cursor=$start;$step=new DateInterval('P1D');$key='Y-m-d';$label='m/d';}
    else{$cursor=$start->modify('first day of this month');$step=new DateInterval('P1M');$key='Y-m-01';$label='Y/m';}
    while($cursor<$end){$row=$map[$cursor->format($key)]??[];$result[]=['label'=>$cursor->format($label),'pageviews'=>(int)($row['pageviews']??0),'visitors'=>(int)($row['visitors']??0),'sessions'=>(int)($row['sessions']??0)];$cursor=$cursor->add($step);}
    return $result;
}

/** @param list<array<string,mixed>> $items @return list<array{label:string,value:int}> */
function tyaav_with_other(array $items, int $total): array
{
    $result=[];$shown=0;
    foreach($items as $item){$value=(int)($item['value']??0);if($value<=0)continue;$result[]=['label'=>(string)($item['label']??'不明'),'value'=>$value];$shown+=$value;}
    if($total>$shown)$result[]=['label'=>'その他','value'=>$total-$shown];
    return $result;
}

/** @return array<string,list<array{value:string,label:string}>> */
function tyaav_history_options(PDO $pdo): array
{
    $options=['countries'=>[],'browsers'=>[],'os'=>[],'devices'=>[]];
    foreach(['countries'=>['country_name',100],'browsers'=>['browser',60],'os'=>['os',60],'devices'=>['device_type',60]] as $key=>[$column,$limit]){
        $rows=$pdo->query("SELECT {$column} AS value,COUNT(*) AS hits FROM tya_events WHERE {$column}<>'' GROUP BY {$column} ORDER BY hits DESC,{$column} ASC LIMIT ".(int)$limit)->fetchAll();
        foreach($rows as $row)$options[$key][]=['value'=>(string)$row['value'],'label'=>(string)$row['value']];
    }
    return $options;
}

function tyaav_mmdb_upload_panel(): string
{
    $csrf = tyaa_csrf_token();
    return '<section class="panel"><h2>GeoLite2データベース</h2>'
        . '<p class="note">City／ASNのMMDBを512KBずつ分割送信します。HTTPのテスト環境でも利用でき、PHPの通常アップロード上限を超えるファイルにも対応します。</p>'
        . '<form class="mmdb-form" data-mmdb-form data-endpoint="api/mmdb-upload.php">'
        . '<input type="hidden" name="csrf" value="' . tyaav_h($csrf) . '">'
        . '<div class="mmdb-grid">'
        . '<label>GeoLite2-City.mmdb<input type="file" name="city_database" accept=".mmdb,application/octet-stream"></label>'
        . '<label>GeoLite2-ASN.mmdb<input type="file" name="asn_database" accept=".mmdb,application/octet-stream"></label>'
        . '</div>'
        . '<div class="mmdb-progress" data-mmdb-progress hidden>'
        . '<strong data-mmdb-title>アップロード準備中…</strong>'
        . '<div class="mmdb-track"><span data-mmdb-bar></span></div>'
        . '<small data-mmdb-detail></small>'
        . '</div>'
        . '<button class="button" type="submit">選択したMMDBをアップロード</button>'
        . '</form></section>';
}

function tyaav_history_shell(array $options): string
{
    ob_start(); ?>
<section id="tya-history" class="tya-history">
<header class="tya-history-header"><div><h2>アクセス詳細履歴</h2><span class="tya-history-status" data-history-status>未読込</span></div><div class="tya-history-actions"><button type="button" class="button secondary" data-history-toggle aria-expanded="true">履歴を閉じる</button><button type="button" class="button secondary" data-history-settings-toggle aria-expanded="false">⚙ 表示設定</button></div></header>
<div class="tya-history-settings" data-history-settings hidden><div class="tya-history-settings-grid">
<fieldset><legend>表示密度</legend><label><input type="radio" name="history_density" value="compact"> コンパクト</label><label><input type="radio" name="history_density" value="standard"> 標準</label><label><input type="checkbox" name="history_wrap"> セル内を折り返す</label><label><input type="checkbox" name="history_sticky"> ヘッダーを固定</label><label><input type="checkbox" name="history_collapsed"> 初期状態で折り畳む</label></fieldset>
<fieldset><legend>表示列</legend><?php foreach(['datetime'=>'日時','event'=>'種別','ip'=>'IP','location'=>'地域','organization'=>'ASN／法人候補','page'=>'ページ','referrer'=>'参照元','environment'=>'環境','details'=>'詳細'] as $value=>$label): ?><label><input type="checkbox" name="history_columns[]" value="<?= tyaav_h($value) ?>"> <?= tyaav_h($label) ?></label><?php endforeach; ?></fieldset>
<fieldset><legend>自動更新</legend><label>間隔<select name="history_auto_refresh"><option value="0">無効</option><option value="30">30秒</option><option value="60">1分</option><option value="300">5分</option></select></label><p class="note">表示設定はこのブラウザだけに保存されます。</p></fieldset>
</div><div class="tya-history-settings-actions"><button type="button" class="button" data-settings-apply>設定を適用</button><button type="button" class="button secondary" data-settings-reset>初期設定へ戻す</button></div></div>
<div class="tya-history-body" data-history-body><form class="tya-history-filter" data-history-form>
<label>検索<input type="search" name="q" placeholder="IP・URL・記事名・地域・ASN・環境" autocomplete="off"></label><label>開始日<input type="date" name="from"></label><label>終了日<input type="date" name="to"></label>
<label>イベント<select name="event"><option value="all">すべて</option><option value="pageview">pageview</option><option value="engagement">engagement</option><option value="external_click">external_click</option><option value="download">download</option></select></label>
<label>訪問者<select name="actor"><option value="human">人間のみ</option><option value="bot">Botのみ</option><option value="all">すべて</option></select></label>
<label>国<select name="country"><option value="">すべての国</option></select></label><label>ブラウザ<select name="browser"><option value="">すべてのブラウザ</option></select></label><label>OS<select name="os"><option value="">すべてのOS</option></select></label><label>端末<select name="device"><option value="">すべての端末</option></select></label>
<label>表示件数<select name="per_page"><option value="25">25件</option><option value="50">50件</option><option value="100">100件</option></select></label><label>並び順<select name="order"><option value="desc">新しい順</option><option value="asc">古い順</option></select></label>
<div class="tya-history-filter-actions"><button type="submit" class="button">検索</button><button type="button" class="button secondary" data-filter-reset>リセット</button></div></form>
<p class="tya-history-help">検索・絞り込み・ページ移動は非同期で更新します。生IP検索は完全一致です。</p><div class="tya-history-range" data-history-range-top></div><div data-history-table><div class="tya-history-empty">履歴を読み込んでいます…</div></div><div class="tya-history-range" data-history-range-bottom></div></div></section>
<?php return (string)ob_get_clean();
}

/** @return array{title:string,html:string,chart_data:array<string,mixed>,history_config:?array<string,mixed>,refresh_seconds:int} */
function tyaav_render(string $view, array $services, array $query): array
{
    global $tyaavTranslator;
    $tyaavTranslator = $services['translator'] ?? null;
    $pdo=$services['pdo'];$config=$services['config'];$geoIp=$services['geoIp'];
    $timezone=new DateTimeZone((string)($config['app']['timezone']??'Asia/Tokyo'));
    $utc=new DateTimeZone('UTC');
    $siteUrl=rtrim((string)($config['app']['site_url']??''),'/');
    $baseUrl=rtrim((string)($config['app']['base_url']??''),'/');
    $overrides=(array)($config['app']['organization_overrides']??[]);
    $range=tyaav_range($query,$timezone);$scope=tyaav_scope($range);
    $chart=[];$history=null;$refresh=0;
    $titles=['dashboard'=>tyaav_t('nav.dashboard'),'realtime'=>tyaav_t('nav.realtime'),'history'=>tyaav_t('nav.history'),'sessions'=>tyaav_t('nav.sessions'),'events'=>tyaav_t('nav.events'),'campaigns'=>tyaav_t('nav.campaigns'),'content'=>tyaav_t('nav.content'),'referrers'=>tyaav_t('nav.referrers'),'organizations'=>tyaav_t('nav.organizations'),'metadata'=>tyaav_t('nav.metadata'),'audience'=>tyaav_t('nav.audience'),'engagement'=>tyaav_t('nav.engagement'),'system'=>tyaav_t('nav.system'),'settings'=>tyaav_t('nav.settings')];
    if(!isset($titles[$view]))$view='dashboard';

    ob_start();
    if($view==='dashboard'){
        $stmt=$pdo->prepare("SELECT SUM(event_type='pageview') pageviews,COUNT(DISTINCT CASE WHEN event_type='pageview' THEN NULLIF(visitor_id,'') END) visitors,COUNT(DISTINCT CASE WHEN event_type='pageview' THEN NULLIF(session_id,'') END) sessions,AVG(CASE WHEN event_type='engagement' AND duration_ms>0 THEN duration_ms END) avg_duration_ms,AVG(CASE WHEN event_type='engagement' THEN scroll_depth END) avg_scroll FROM tya_events WHERE {$scope['sql']}");$stmt->execute($scope['params']);$summary=$stmt->fetch()?:[];
        $bot=$pdo->prepare("SELECT COUNT(*) FROM tya_events WHERE occurred_at>=? AND occurred_at<? AND is_bot=1");$bot->execute([$range['start_utc'],$range['end_utc']]);$summary['bot_events']=(int)$bot->fetchColumn();
        $offset=intdiv($range['start_local']->getOffset(),60);$grain=$range['days']<=2?'hour':($range['days']<=62?'day':'month');$local="DATE_ADD(occurred_at,INTERVAL {$offset} MINUTE)";$bucket=$grain==='hour'?"CONCAT(DATE({$local}),' ',LPAD(HOUR({$local}),2,'0'),':00')":($grain==='day'?"DATE({$local})":"CONCAT(YEAR({$local}),'-',LPAD(MONTH({$local}),2,'0'),'-01')");
        $timeline=$pdo->prepare("SELECT {$bucket} bucket,COUNT(*) pageviews,COUNT(DISTINCT NULLIF(visitor_id,'')) visitors,COUNT(DISTINCT NULLIF(session_id,'')) sessions FROM tya_events WHERE event_type='pageview' AND {$scope['sql']} GROUP BY {$bucket} ORDER BY {$bucket}");$timeline->execute($scope['params']);$timelineRows=tyaav_fill_timeline($timeline->fetchAll(),$range['start_local'],$range['end_local'],$grain);
        $chart=['timeline'=>['rows'=>$timelineRows,'series'=>[['key'=>'pageviews','label'=>'PV'],['key'=>'visitors','label'=>'UU'],['key'=>'sessions','label'=>'セッション']]],'breakdowns'=>[]];
        $top=$pdo->prepare("SELECT path,MAX(page_title) page_title,COUNT(*) pageviews,COUNT(DISTINCT session_id) sessions FROM tya_events WHERE event_type='pageview' AND {$scope['sql']} GROUP BY path ORDER BY pageviews DESC,sessions DESC LIMIT 5");$top->execute($scope['params']);$topPages=$top->fetchAll();
        $recent=$pdo->query("SELECT * FROM tya_events WHERE event_type='pageview' AND is_bot=0 ORDER BY event_id DESC LIMIT 8")->fetchAll();
        $candidates=$pdo->query("SELECT * FROM tya_events WHERE event_type='pageview' AND is_bot=0 AND asn_org<>'' ORDER BY event_id DESC LIMIT 120")->fetchAll();$notable=[];
        foreach($candidates as $row){$cl=OrganizationClassifier::classify($row['asn']!==null?(int)$row['asn']:null,(string)$row['asn_org'],false,$overrides);if(!$cl['featured'])continue;$row['_classification']=$cl;$key=(string)$row['asn'].'|'.$row['path'];if(isset($notable[$key]))continue;$notable[$key]=$row;if(count($notable)>=5)break;}$notable=array_values($notable);
        echo '<section class="view-head"><div><h1>'.tyaav_h(tyaav_t('nav.dashboard')).'</h1><p>'.tyaav_h(tyaav_t('dashboard.description')).'</p></div><a class="button secondary" href="?view=history" data-view-link="history">'.tyaav_h(tyaav_t('dashboard.history')).'</a></section>';
        echo '<section class="panel"><h2>期間分析</h2>'.tyaav_filter_html($range).'<div class="cards">';
        foreach(['PV'=>number_format((int)($summary['pageviews']??0)),'UU（推定）'=>number_format((int)($summary['visitors']??0)),'セッション'=>number_format((int)($summary['sessions']??0)),'平均滞在'=>tyaav_duration((int)round((float)($summary['avg_duration_ms']??0))),'平均スクロール'=>number_format((float)($summary['avg_scroll']??0),1).'%','Botイベント'=>number_format((int)($summary['bot_events']??0))] as $label=>$value)echo '<div class="card">'.tyaav_h($label).'<b>'.tyaav_h($value).'</b></div>';
        echo '</div><div class="chart-card"><h3>PV・UU・セッション推移</h3><canvas data-tya-line></canvas><div class="chart-legend"><span>PV</span><span>UU</span><span>セッション</span></div></div></section>';
        echo '<div class="insight-grid"><section class="panel"><h2>注目組織アクセス</h2>';
        if(!$notable)echo '<p class="muted">'.tyaav_h(tyaav_t('dashboard.notable_empty')).'</p>';
        foreach($notable as $row){$dt=(new DateTimeImmutable($row['occurred_at'],$utc))->setTimezone($timezone)->format('m-d H:i');$asn=trim(($row['asn']?'AS'.(int)$row['asn'].' ':'').$row['asn_org']);echo '<div class="notable-item">'.tyaav_badge($row['_classification']).' <span class="muted">'.tyaav_h($dt).'</span><div class="notable-title">'.tyaav_h($asn).'</div>'.tyaav_page_link((string)$row['path'],(string)$row['page_title'],$siteUrl).'</div>';}
        echo '</section><section class="panel"><h2>人気記事</h2><ol class="rank">';foreach($topPages as $row)echo '<li><b>'.tyaav_page_link((string)$row['path'],(string)$row['page_title'],$siteUrl).'</b><br><span class="muted">'.number_format((int)$row['pageviews']).' PV / '.number_format((int)$row['sessions']).' セッション</span></li>';if(!$topPages)echo '<li class="muted">データなし</li>';echo '</ol></section></div>';
        echo '<section class="panel"><h2>最近の閲覧</h2><div class="table-wrap"><table class="mini-table"><thead><tr><th>日時</th><th>組織</th><th>ページ</th><th>流入</th></tr></thead><tbody>';
        foreach($recent as $row){$dt=(new DateTimeImmutable($row['occurred_at'],$utc))->setTimezone($timezone)->format('m-d H:i:s');$cl=OrganizationClassifier::classify($row['asn']!==null?(int)$row['asn']:null,(string)$row['asn_org'],false,$overrides);echo '<tr><td>'.tyaav_h($dt).'</td><td>'.tyaav_badge($cl).'<br>'.tyaav_h(trim(($row['asn']?'AS'.(int)$row['asn'].' ':'').$row['asn_org'])?:'―').'</td><td>'.tyaav_page_link((string)$row['path'],(string)$row['page_title'],$siteUrl).'</td><td>'.tyaav_referrer((string)$row['referrer']).'</td></tr>';}
        if(!$recent)echo '<tr><td colspan="4">'.tyaav_h(tyaav_t('dashboard.recent_empty')).'</td></tr>';echo '</tbody></table></div></section>';
    } elseif($view==='realtime'){
        $minutes=max(5,min(180,(int)($query['minutes']??30)));$since=(new DateTimeImmutable('-'.$minutes.' minutes',$utc))->format('Y-m-d H:i:s');
        $stmt=$pdo->prepare("SELECT p.*,COALESCE((SELECT MAX(e.duration_ms) FROM tya_events e WHERE e.session_id=p.session_id AND e.path=p.path AND e.event_type='engagement' AND e.occurred_at>=p.occurred_at),0) live_duration,GREATEST(p.scroll_depth,COALESCE((SELECT MAX(e2.scroll_depth) FROM tya_events e2 WHERE e2.session_id=p.session_id AND e2.path=p.path AND e2.occurred_at>=p.occurred_at),0)) live_scroll FROM tya_events p WHERE p.event_type='pageview' AND p.occurred_at>=? ORDER BY p.event_id DESC LIMIT 100");$stmt->execute([$since]);$rows=$stmt->fetchAll();
        echo '<section class="view-head"><div><h1>リアルタイム</h1><p>直近の閲覧を自動更新します。</p></div><span class="live-dot">● 30秒ごと</span></section><section class="panel"><form class="filters" data-view-filter><label>対象時間<select name="minutes">';foreach([5,15,30,60,180] as $m)echo '<option value="'.$m.'"'.($minutes===$m?' selected':'').'>直近'.$m.'分</option>';echo '</select></label><button class="button" type="submit">適用</button></form><p class="note">'.number_format(count($rows)).'件のページビュー</p><div class="table-wrap"><table><thead><tr><th>日時</th><th>状態</th><th>ページ</th><th>ASN／組織</th><th>流入</th><th>滞在／スクロール</th></tr></thead><tbody>';
        foreach($rows as $row){$dt=(new DateTimeImmutable($row['occurred_at'],$utc))->setTimezone($timezone)->format('H:i:s');$cl=OrganizationClassifier::classify($row['asn']!==null?(int)$row['asn']:null,(string)$row['asn_org'],(bool)$row['is_bot'],$overrides);$state=(int)$row['is_bot']?'Bot':((int)$row['live_duration']>0?'閲覧・計測済み':'新規閲覧');echo '<tr><td>'.tyaav_h($dt).'</td><td><span class="state">'.tyaav_h($state).'</span></td><td>'.tyaav_page_link((string)$row['path'],(string)$row['page_title'],$siteUrl).'<br><code>'.tyaav_h($row['path']).'</code></td><td>'.tyaav_badge($cl).'<br>'.tyaav_h(trim(($row['asn']?'AS'.(int)$row['asn'].' ':'').$row['asn_org'])?:'―').'</td><td>'.tyaav_referrer((string)$row['referrer']).'</td><td>'.tyaav_duration((int)$row['live_duration']).' / '.(int)$row['live_scroll'].'%</td></tr>';}
        if(!$rows)echo '<tr><td colspan="6">'.tyaav_h(tyaav_t('realtime.empty')).'</td></tr>';echo '</tbody></table></div></section>';$refresh=30;
    } elseif($view==='history'){
        echo '<section class="view-head"><div><h1>アクセス履歴</h1><p>生イベントを絞り込み、ページを移動しても画面全体は再読み込みしません。</p></div></section>'.tyaav_history_shell(tyaav_history_options($pdo));
        $history=['endpoint'=>'api/events.php','storageKey'=>'tenyenAnalytics.history.v1','defaults'=>['collapsed'=>false,'density'=>'compact','perPage'=>25,'actor'=>'human','event'=>'all','autoRefresh'=>0,'wrap'=>false,'stickyHeader'=>true,'order'=>'desc','visibleColumns'=>['datetime','event','organization','page','referrer','environment','details']],'initialState'=>array_intersect_key($query,array_flip(['q','from','to','event','actor','country','browser','os','device','per_page','order','visible_columns'])),'options'=>tyaav_history_options($pdo)];
    } elseif($view==='sessions'){
        echo '<section class="view-head"><div><h1>'.tyaav_h(tyaav_t('nav.sessions')).'</h1><p>'.tyaav_h(tyaav_t('sessions.description')).'</p></div></section>'
            .'<section class="panel" data-sessions data-initial-session="'.tyaav_h((string)($query['session']??'')).'" data-initial-visitor="'.tyaav_h((string)($query['visitor']??'')).'"><form class="sessions-filter" data-sessions-filter>'
            .'<label>'.tyaav_h(tyaav_t('filter.from')).'<input type="date" name="from" value="'.tyaav_h($range['from']).'"></label>'
            .'<label>'.tyaav_h(tyaav_t('filter.to')).'<input type="date" name="to" value="'.tyaav_h($range['to']).'"></label>'
            .'<label>'.tyaav_h(tyaav_t('common.search')).'<input type="search" name="q" value="'.tyaav_h((string)($query['q']??'')).'"></label>'
            .'<label>'.tyaav_h(tyaav_t('filter.actor')).'<select name="actor">'.implode('',array_map(static fn(string $actor):string=>'<option value="'.$actor.'"'.(($query['actor']??'human')===$actor?' selected':'').'>'.tyaav_h($actor==='human'?tyaav_t('filter.human'):($actor==='all'?tyaav_t('filter.all'):'Bot')).'</option>',['human','bot','all'])).'</select></label>'
            .'<label>'.tyaav_h(tyaav_t('sessions.country')).'<input name="country" value="'.tyaav_h((string)($query['country']??'')).'"></label><label>ASN / '.tyaav_h(tyaav_t('sessions.organization')).'<input name="organization" value="'.tyaav_h((string)($query['organization']??'')).'"></label>'
            .'<label>'.tyaav_h(tyaav_t('sessions.browser')).'<input name="browser" value="'.tyaav_h((string)($query['browser']??'')).'"></label><label>OS<input name="os" value="'.tyaav_h((string)($query['os']??'')).'"></label><label>'.tyaav_h(tyaav_t('sessions.device')).'<input name="device" value="'.tyaav_h((string)($query['device']??'')).'"></label>'
            .'<label>'.tyaav_h(tyaav_t('sessions.landing')).'<input name="content" value="'.tyaav_h((string)($query['content']??'')).'"></label>'
            .'<label>'.tyaav_h(tyaav_t('filter.per_page')).'<select name="per_page">'.implode('',array_map(static fn(int $size):string=>'<option'.(((int)($query['per_page']??25)===$size)?' selected':'').'>'.$size.'</option>',[25,50,100])).'</select></label>'
            .'<label>'.tyaav_h(tyaav_t('filter.order')).'<select name="order"><option value="desc"'.(($query['order']??'desc')==='desc'?' selected':'').'>'.tyaav_h(tyaav_t('filter.newest')).'</option><option value="asc"'.(($query['order']??'desc')==='asc'?' selected':'').'>'.tyaav_h(tyaav_t('filter.oldest')).'</option></select></label>'
            .'<button class="button" type="submit">'.tyaav_h(tyaav_t('common.apply')).'</button></form>'
            .'<p class="note" data-sessions-status aria-live="polite">'.tyaav_h(tyaav_t('common.loading')).'</p><div class="sessions-results" data-sessions-results></div>'
            .'<dialog class="journey-dialog" data-journey-dialog aria-label="'.tyaav_h(tyaav_t('sessions.detail')).'"><div class="journey-dialog-body" data-journey-dialog-body></div></dialog></section>';
    } elseif($view==='events'){
        $type=preg_match('/^[a-z_]{0,32}$/',(string)($query['event_type']??''))?(string)($query['event_type']??''):'';
        $where=$scope['sql']." AND event_type NOT IN('pageview','engagement')";$params=$scope['params'];
        if($type!==''){$where.=' AND event_type=?';$params[]=$type;}
        $stmt=$pdo->prepare("SELECT event_type,event_name,COUNT(*) events,COUNT(DISTINCT NULLIF(session_id,'')) sessions,COUNT(DISTINCT NULLIF(visitor_id,'')) visitors,MAX(path) source_page,MAX(target_url) target,MAX(referrer_domain) target_host,SUM(is_bot=0) human_events,SUM(is_bot=1) bot_events FROM tya_events WHERE {$where} GROUP BY event_type,event_name ORDER BY events DESC,event_type,event_name LIMIT 100");$stmt->execute($params);$rows=$stmt->fetchAll();
        echo '<section class="view-head"><div><h1>'.tyaav_h(tyaav_t('nav.events')).'</h1><p>Clicks, downloads, 404 responses, and bounded custom events.</p></div></section><section class="panel">'.tyaav_filter_html($range).'<form class="filters" data-view-filter><label>Event type<input name="event_type" value="'.tyaav_h($type).'"></label><button class="button" type="submit">Apply</button></form><div class="table-wrap"><table><thead><tr><th>Type</th><th>Name</th><th>Events</th><th>Sessions</th><th>Visitors</th><th>Source</th><th>Target</th><th>Human / Bot</th></tr></thead><tbody>';
        foreach($rows as $row)echo '<tr><td>'.tyaav_h($row['event_type']).'</td><td class="journey-long">'.tyaav_h($row['event_name']?:'—').'</td><td>'.number_format((int)$row['events']).'</td><td>'.number_format((int)$row['sessions']).'</td><td>'.number_format((int)$row['visitors']).'</td><td class="journey-long">'.tyaav_h($row['source_page']).'</td><td class="journey-long">'.tyaav_h($row['target']?:'—').'</td><td>'.(int)$row['human_events'].' / '.(int)$row['bot_events'].'</td></tr>';
        if(!$rows)echo '<tr><td colspan="8">'.tyaav_h(tyaav_t('common.no_data')).'</td></tr>';echo '</tbody></table></div></section>';
    } elseif($view==='campaigns'){
        $campaignSql="SELECT utm_source,utm_medium,utm_campaign,utm_content,utm_term,COUNT(*) sessions,COUNT(DISTINCT visitor_id) visitors,SUM(pageviews) pageviews,SUM(events) events FROM (SELECT session_id,MAX(visitor_id) visitor_id,SUM(event_type='pageview') pageviews,SUM(event_type NOT IN('pageview','engagement')) events,SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN event_type='pageview' THEN NULLIF(utm_source,'') END ORDER BY occurred_at,event_id SEPARATOR '\\n'),'\\n',1) utm_source,SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN event_type='pageview' THEN NULLIF(utm_medium,'') END ORDER BY occurred_at,event_id SEPARATOR '\\n'),'\\n',1) utm_medium,SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN event_type='pageview' THEN NULLIF(utm_campaign,'') END ORDER BY occurred_at,event_id SEPARATOR '\\n'),'\\n',1) utm_campaign,SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN event_type='pageview' THEN NULLIF(utm_content,'') END ORDER BY occurred_at,event_id SEPARATOR '\\n'),'\\n',1) utm_content,SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN event_type='pageview' THEN NULLIF(utm_term,'') END ORDER BY occurred_at,event_id SEPARATOR '\\n'),'\\n',1) utm_term FROM tya_events WHERE session_id<>'' AND {$scope['sql']} GROUP BY session_id) first_touch WHERE utm_campaign<>'' GROUP BY utm_source,utm_medium,utm_campaign,utm_content,utm_term ORDER BY sessions DESC,utm_campaign LIMIT 100";
        $stmt=$pdo->prepare($campaignSql);$stmt->execute($scope['params']);$rows=$stmt->fetchAll();
        echo '<section class="view-head"><div><h1>'.tyaav_h(tyaav_t('nav.campaigns')).'</h1><p>First-touch campaign dimensions are taken from each session landing page.</p></div></section><section class="panel">'.tyaav_filter_html($range).'<div class="table-wrap"><table><thead><tr><th>Source</th><th>Medium</th><th>Campaign</th><th>Content</th><th>Term</th><th>Sessions</th><th>Visitors</th><th>PV</th><th>Events</th><th>'.tyaav_h(tyaav_t('common.edit')).'</th></tr></thead><tbody>';
        foreach($rows as $row){$campaignKey=(string)json_encode(['source'=>(string)$row['utm_source'],'medium'=>(string)$row['utm_medium'],'campaign'=>(string)$row['utm_campaign'],'content'=>(string)$row['utm_content'],'term'=>(string)$row['utm_term']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);echo '<tr><td>'.tyaav_h($row['utm_source']).'</td><td>'.tyaav_h($row['utm_medium']).'</td><td class="journey-long">'.tyaav_h($row['utm_campaign']).'</td><td class="journey-long">'.tyaav_h($row['utm_content']).'</td><td class="journey-long">'.tyaav_h($row['utm_term']).'</td><td>'.(int)$row['sessions'].'</td><td>'.(int)$row['visitors'].'</td><td>'.(int)$row['pageviews'].'</td><td>'.(int)$row['events'].'</td><td><button class="button secondary" data-edit-annotation data-entity-type="campaign" data-entity-key="'.tyaav_h($campaignKey).'" data-original="'.tyaav_h($row['utm_campaign']).'">'.tyaav_h(tyaav_t('common.edit')).'</button></td></tr>';}
        if(!$rows)echo '<tr><td colspan="10">'.tyaav_h(tyaav_t('common.no_data')).'</td></tr>';echo '</tbody></table></div></section>';
    } elseif($view==='content'){
        $rows=(new SessionAnalytics($pdo))->getContentJourneyMetrics(['actor'=>$range['actor'],'start_utc'=>$range['start_utc'],'end_utc'=>$range['end_utc']]);
        echo '<section class="view-head"><div><h1>'.tyaav_h(tyaav_t('nav.content')).'</h1><p>'.tyaav_h(tyaav_t('content.description')).'</p></div></section><section class="panel"><h2>'.tyaav_h(tyaav_t('content.ranking')).'</h2>'.tyaav_filter_html($range).'<p class="note">'.tyaav_h(tyaav_t('content.metric_note')).'</p><div class="table-wrap"><table><thead><tr><th>#</th><th>'.tyaav_h(tyaav_t('content.page')).'</th><th>PV</th><th>'.tyaav_h(tyaav_t('content.sessions')).'</th><th>'.tyaav_h(tyaav_t('content.entries')).'</th><th>'.tyaav_h(tyaav_t('content.exits')).'</th><th>'.tyaav_h(tyaav_t('content.bounces')).'</th><th>'.tyaav_h(tyaav_t('content.bounce_rate')).'</th><th>'.tyaav_h(tyaav_t('content.exit_rate')).'</th><th>'.tyaav_h(tyaav_t('content.pv_per_session')).'</th><th>'.tyaav_h(tyaav_t('common.edit')).'</th></tr></thead><tbody>';
        foreach($rows as $i=>$row){$entries=(int)$row['entries'];$pageviews=(int)$row['pageviews'];$sessions=(int)$row['sessions'];echo '<tr><td>'.($i+1).'</td><td><b>'.tyaav_page_link((string)$row['path'],(string)$row['page_title'],$siteUrl).'</b><br><code>'.tyaav_h($row['path']).'</code></td><td>'.number_format($pageviews).'</td><td>'.number_format($sessions).'</td><td>'.number_format($entries).'</td><td>'.number_format((int)$row['exits']).'</td><td>'.number_format((int)$row['bounces']).'</td><td>'.number_format($entries>0?(int)$row['bounces']/$entries*100:0,1).'%</td><td>'.number_format($pageviews>0?(int)$row['exits']/$pageviews*100:0,1).'%</td><td>'.number_format($sessions>0?$pageviews/$sessions:0,2).'</td><td><button class="button secondary" data-edit-annotation data-entity-type="content" data-entity-key="'.tyaav_h($row['path']).'" data-original="'.tyaav_h($row['page_title']?:$row['path']).'">'.tyaav_h(tyaav_t('common.edit')).'</button></td></tr>';}if(!$rows)echo '<tr><td colspan="11">'.tyaav_h(tyaav_t('common.no_data')).'</td></tr>';echo '</tbody></table></div></section>';
    } elseif($view==='referrers'){
        $stmt=$pdo->prepare("SELECT COALESCE(NULLIF(traffic_channel,''),'Unknown') traffic_channel,COALESCE(NULLIF(referrer_domain,''),'Direct') referrer_host,MAX(referrer) sample_url,COUNT(*) pageviews,COUNT(DISTINCT session_id) sessions,COUNT(DISTINCT visitor_id) visitors FROM tya_events WHERE event_type='pageview' AND {$scope['sql']} GROUP BY traffic_channel,referrer_host ORDER BY sessions DESC,pageviews DESC LIMIT 100");$stmt->execute($scope['params']);$rows=$stmt->fetchAll();
        $external=$pdo->prepare("SELECT event_type,target_url,COUNT(*) events FROM tya_events WHERE event_type IN('outbound','download') AND {$scope['sql']} GROUP BY event_type,target_url ORDER BY events DESC LIMIT 50");$external->execute($scope['params']);$clicks=$external->fetchAll();
        echo '<section class="view-head"><div><h1>Traffic Sources</h1><p>Stable channels and referrer domains with session and visitor counts.</p></div></section><section class="panel"><h2>Channels</h2>'.tyaav_filter_html($range).'<div class="table-wrap"><table><thead><tr><th>#</th><th>Channel</th><th>Referrer</th><th>PV</th><th>Sessions</th><th>Visitors</th></tr></thead><tbody>';
        foreach($rows as $i=>$row){$label=(string)$row['referrer_host'];$render=$label==='Direct'?'Direct':tyaav_link((string)$row['sample_url'],$label);echo '<tr><td>'.($i+1).'</td><td>'.tyaav_h($row['traffic_channel']).'</td><td>'.$render.($label!=='Direct'?' <button class="button secondary" data-edit-annotation data-entity-type="referrer" data-entity-key="'.tyaav_h($label).'" data-original="'.tyaav_h($label).'">'.tyaav_h(tyaav_t('common.edit')).'</button>':'').'</td><td>'.number_format((int)$row['pageviews']).'</td><td>'.number_format((int)$row['sessions']).'</td><td>'.number_format((int)$row['visitors']).'</td></tr>';}if(!$rows)echo '<tr><td colspan="6">No data</td></tr>';echo '</tbody></table></div></section><section class="panel"><h2>External clicks and downloads</h2><div class="table-wrap"><table><thead><tr><th>Type</th><th>Target URL</th><th>Events</th><th>'.tyaav_h(tyaav_t('common.edit')).'</th></tr></thead><tbody>';
        foreach($clicks as $row){$targetHost=strtolower((string)(parse_url((string)$row['target_url'],PHP_URL_HOST)?:''));echo '<tr><td>'.tyaav_h($row['event_type']).'</td><td>'.tyaav_link((string)$row['target_url'],(string)$row['target_url']).'</td><td>'.number_format((int)$row['events']).'</td><td>'.($targetHost!==''?'<button class="button secondary" data-edit-annotation data-entity-type="external_target" data-entity-key="'.tyaav_h($targetHost).'" data-original="'.tyaav_h($targetHost).'">'.tyaav_h(tyaav_t('common.edit')).'</button>':'').'</td></tr>';}if(!$clicks)echo '<tr><td colspan="4">'.tyaav_h(tyaav_t('common.no_data')).'</td></tr>';echo '</tbody></table></div></section>';
    } elseif($view==='organizations'){
        $search=trim((string)($query['q']??''));$watched=(string)($query['watched']??'');$tagId=max(0,(int)($query['tag_id']??0));$where="e.event_type='pageview' AND {$scope['sql']}";$params=$scope['params'];
        if($search!==''){$where.=" AND (e.asn_org LIKE ? ESCAPE '=' OR CAST(e.asn AS CHAR) LIKE ? ESCAPE '=' OR a.alias LIKE ? ESCAPE '=')";$like='%'.str_replace(['=','%','_'],['==','=%','=_'],$search).'%';array_push($params,$like,$like,$like);}
        if(in_array($watched,['0','1'],true)){$where.=' AND COALESCE(a.watched,0)=?';$params[]=(int)$watched;}
        if($tagId>0){$where.=' AND EXISTS (SELECT 1 FROM tya_annotation_tags filter_tags WHERE filter_tags.annotation_id=a.annotation_id AND filter_tags.tag_id=?)';$params[]=$tagId;}
        $availableTags=$pdo->query('SELECT tag_id,name FROM tya_tags ORDER BY name LIMIT 500')->fetchAll();
        $stmt=$pdo->prepare("SELECT e.asn,e.asn_org,MAX(a.alias) alias,MAX(a.note) note,MAX(COALESCE(a.watched,0)) watched,COUNT(*) pageviews,COUNT(DISTINCT e.visitor_id) visitors,COUNT(DISTINCT e.session_id) sessions,MAX(e.occurred_at) last_seen FROM tya_events e LEFT JOIN tya_annotations a ON a.entity_type='organization' AND a.entity_hash=UNHEX(SHA2(CAST(e.asn AS CHAR),256)) WHERE {$where} GROUP BY e.asn,e.asn_org ORDER BY watched DESC,pageviews DESC LIMIT 200");$stmt->execute($params);$rows=$stmt->fetchAll();
        echo '<section class="view-head"><div><h1>'.tyaav_h(tyaav_t('nav.organizations')).'</h1><p>'.tyaav_h(tyaav_t('organizations.description')).'</p></div></section><section class="panel"><h2>'.tyaav_h(tyaav_t('organizations.ranking')).'</h2>'.tyaav_filter_html($range).'<form class="filters" data-view-filter><label>'.tyaav_h(tyaav_t('common.search')).'<input type="search" name="q" value="'.tyaav_h($search).'"></label><label>'.tyaav_h(tyaav_t('metadata.watchlist')).'<select name="watched"><option value="">'.tyaav_h(tyaav_t('filter.all')).'</option><option value="1"'.($watched==='1'?' selected':'').'>'.tyaav_h(tyaav_t('metadata.watched')).'</option><option value="0"'.($watched==='0'?' selected':'').'>'.tyaav_h(tyaav_t('metadata.unwatched')).'</option></select></label><label>'.tyaav_h(tyaav_t('metadata.tags')).'<select name="tag_id"><option value="">'.tyaav_h(tyaav_t('filter.all')).'</option>';foreach($availableTags as $tag)echo '<option value="'.(int)$tag['tag_id'].'"'.($tagId===(int)$tag['tag_id']?' selected':'').'>'.tyaav_h($tag['name']).'</option>';echo '</select></label><button class="button" type="submit">'.tyaav_h(tyaav_t('common.apply')).'</button></form><p class="note">'.tyaav_h(tyaav_t('organizations.disclaimer')).'</p><div class="table-wrap"><table><thead><tr><th>#</th><th>'.tyaav_h(tyaav_t('organizations.classification')).'</th><th>ASN / '.tyaav_h(tyaav_t('organizations.registered')).'</th><th>PV</th><th>UU</th><th>'.tyaav_h(tyaav_t('content.sessions')).'</th><th>'.tyaav_h(tyaav_t('organizations.last_access')).'</th><th>'.tyaav_h(tyaav_t('common.edit')).'</th></tr></thead><tbody>';
        foreach($rows as $i=>$row){$cl=OrganizationClassifier::classify($row['asn']!==null?(int)$row['asn']:null,(string)$row['asn_org'],false,$overrides);$raw=trim(($row['asn']?'AS'.(int)$row['asn'].' ':'').$row['asn_org'])?:tyaav_t('common.unknown');$last=(new DateTimeImmutable($row['last_seen'],$utc))->setTimezone($timezone)->format('Y-m-d H:i');$display=$row['alias']!==''?'<b>'.tyaav_h($row['alias']).'</b><br><small>'.tyaav_h($raw).'</small>':'<b>'.tyaav_h($raw).'</b>';$edit=$row['asn']?'<button class="button secondary" type="button" data-edit-annotation data-entity-type="organization" data-entity-key="'.(int)$row['asn'].'" data-original="'.tyaav_h($raw).'">'.tyaav_h(tyaav_t('common.edit')).'</button>':'—';echo '<tr><td>'.($i+1).'</td><td>'.(!empty($row['watched'])?'<span class="watch-badge">★ '.tyaav_h(tyaav_t('metadata.watched')).'</span><br>':'').tyaav_badge($cl).'</td><td>'.$display.'<br><small class="muted">'.tyaav_h($cl['reason']).' / '.$cl['confidence'].'%</small></td><td>'.number_format((int)$row['pageviews']).'</td><td>'.number_format((int)$row['visitors']).'</td><td>'.number_format((int)$row['sessions']).'</td><td>'.tyaav_h($last).'</td><td>'.$edit.'</td></tr>';}if(!$rows)echo '<tr><td colspan="8">'.tyaav_h(tyaav_t('common.no_data')).'</td></tr>';echo '</tbody></table></div></section>';
    } elseif($view==='metadata'){
        $managerTags=$pdo->query('SELECT tag_id,name FROM tya_tags ORDER BY name LIMIT 500')->fetchAll();
        echo '<section class="view-head"><div><h1>'.tyaav_h(tyaav_t('nav.metadata')).'</h1><p>'.tyaav_h(tyaav_t('metadata.description')).'</p></div></section>'
            .'<section class="panel metadata-manager" data-metadata-manager><div class="metadata-tabs" role="tablist">'
            .'<button class="button" type="button" data-metadata-tab="watched">'.tyaav_h(tyaav_t('metadata.watched_organizations')).'</button>'
            .'<button class="button secondary" type="button" data-metadata-tab="annotations">'.tyaav_h(tyaav_t('metadata.annotations')).'</button>'
            .'<button class="button secondary" type="button" data-metadata-tab="tags">'.tyaav_h(tyaav_t('metadata.tags')).'</button>'
            .'<button class="button secondary" type="button" data-metadata-tab="views">'.tyaav_h(tyaav_t('metadata.saved_views')).'</button></div>'
            .'<div class="metadata-toolbar"><label>'.tyaav_h(tyaav_t('common.search')).'<input type="search" data-metadata-search maxlength="120"></label><label>'.tyaav_h(tyaav_t('metadata.tags')).'<select data-metadata-tag><option value="">'.tyaav_h(tyaav_t('filter.all')).'</option>'.implode('',array_map(static fn(array $tag):string=>'<option value="'.(int)$tag['tag_id'].'">'.tyaav_h($tag['name']).'</option>',$managerTags)).'</select></label><button class="button" type="button" data-create-tag hidden>'.tyaav_h(tyaav_t('metadata.create_tag')).'</button></div>'
            .'<p class="note" data-metadata-status aria-live="polite">'.tyaav_h(tyaav_t('common.loading')).'</p><div data-metadata-results></div></section>';
    } elseif($view==='audience'){
        $totalStmt=$pdo->prepare("SELECT COUNT(*) FROM tya_events WHERE event_type='pageview' AND {$scope['sql']}");$totalStmt->execute($scope['params']);$total=(int)$totalStmt->fetchColumn();$breakdowns=[];
        foreach(['browser'=>['ブラウザ','browser'],'os'=>['OS','os'],'device'=>['端末','device_type'],'country'=>['国','country_name']] as $key=>[$title,$column]){$stmt=$pdo->prepare("SELECT COALESCE(NULLIF({$column},''),'不明') label,COUNT(*) value FROM tya_events WHERE event_type='pageview' AND {$scope['sql']} GROUP BY {$column} ORDER BY value DESC LIMIT 8");$stmt->execute($scope['params']);$breakdowns[$key]=['title'=>$title,'rows'=>tyaav_with_other($stmt->fetchAll(),$total)];}
        $chart=['timeline'=>[],'breakdowns'=>$breakdowns];echo '<section class="view-head"><div><h1>ユーザー環境</h1><p>ブラウザ・OS・端末・国の構成を分けて確認します。</p></div></section><section class="panel"><h2>利用環境</h2>'.tyaav_filter_html($range).'<div class="chart-grid">';foreach($breakdowns as $key=>$part){echo '<div class="chart-card"><h3>'.tyaav_h($part['title']).'</h3><canvas data-tya-donut="'.tyaav_h($key).'"></canvas><ol class="composition">';foreach($part['rows'] as $row){$pct=$total>0?$row['value']/$total*100:0;echo '<li><b>'.tyaav_h($row['label']).'</b> <small>'.number_format($row['value']).' PV / '.number_format($pct,1).'%</small></li>';}echo '</ol></div>';}echo '</div></section>';
    } elseif($view==='engagement'){
        $stmt=$pdo->prepare("SELECT p.path,MAX(p.page_title) page_title,COUNT(*) pageviews,COALESCE(AVG((SELECT MAX(e.duration_ms) FROM tya_events e WHERE e.session_id=p.session_id AND e.path=p.path AND e.event_type='engagement' AND e.occurred_at>=p.occurred_at)),0) avg_duration,COALESCE(AVG((SELECT MAX(e2.scroll_depth) FROM tya_events e2 WHERE e2.session_id=p.session_id AND e2.path=p.path AND e2.event_type='engagement' AND e2.occurred_at>=p.occurred_at)),0) avg_scroll FROM tya_events p WHERE p.event_type='pageview' AND {$scope['sql']} GROUP BY p.path ORDER BY pageviews DESC LIMIT 100");$stmt->execute($scope['params']);$rows=$stmt->fetchAll();
        echo '<section class="view-head"><div><h1>エンゲージメント</h1><p>記事ごとの滞在時間とスクロール率を確認します。</p></div></section><section class="panel"><h2>記事別エンゲージメント</h2>'.tyaav_filter_html($range).'<div class="table-wrap"><table><thead><tr><th>#</th><th>ページ</th><th>PV</th><th>平均滞在</th><th>平均スクロール</th></tr></thead><tbody>';
        foreach($rows as $i=>$row)echo '<tr><td>'.($i+1).'</td><td>'.tyaav_page_link((string)$row['path'],(string)$row['page_title'],$siteUrl).'<br><code>'.tyaav_h($row['path']).'</code></td><td>'.number_format((int)$row['pageviews']).'</td><td>'.tyaav_duration((int)round((float)$row['avg_duration'])).'</td><td>'.number_format((float)$row['avg_scroll'],1).'%</td></tr>';if(!$rows)echo '<tr><td colspan="5">データなし</td></tr>';echo '</tbody></table></div></section>';
    } elseif($view==='system'){
        $geo=$config['geoip']??[];$city=(string)($geo['city_database']??'');$asn=(string)($geo['asn_database']??'');$count=(int)$pdo->query('SELECT COUNT(*) FROM tya_events')->fetchColumn();$latest=(string)($pdo->query('SELECT MAX(occurred_at) FROM tya_events')->fetchColumn()?:'');$latestLabel=$latest!==''?(new DateTimeImmutable($latest,$utc))->setTimezone($timezone)->format('Y-m-d H:i:s'):'未受信';$embed='<script src="'.$baseUrl.'/config.js.php"></script>'."\n".'<script defer src="'.$baseUrl.'/tracker.js"></script>';
        echo '<section class="view-head"><div><h1>システム</h1><p>設置状態・データベース・GeoLite2・埋め込みコードを診断します。</p></div></section><div class="diagnostics">';foreach([['PHP',PHP_VERSION,true],['DB接続',(string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION),true],['保存イベント',number_format($count).'件',true],['最終受信',$latestLabel,$latest!==''],['GeoIP Reader',$geoIp->isReaderAvailable()?(class_exists(\MaxMind\Db\Reader::class)?'公式Reader':'内蔵Reader'):'利用不可',$geoIp->isReaderAvailable()],['GeoLite2 City',is_readable($city)?basename($city):'未配置',is_readable($city)],['GeoLite2 ASN',is_readable($asn)?basename($asn):'未配置',is_readable($asn)]] as [$label,$detail,$ok])echo '<div class="diagnostic '.($ok?'ok':'warn').'"><span>'.($ok?'✅':'⚠️').'</span><div><b>'.tyaav_h($label).'</b><small>'.tyaav_h($detail).'</small></div></div>';echo '</div><section class="panel"><h2>埋め込みコード</h2><p class="note">解析対象サイトの&lt;/head&gt;直前などへ貼り付けてください。</p><pre class="code-block"><button type="button" class="copy-button" data-copy-code>コピー</button><code>'.tyaav_h($embed).'</code></pre><dl class="system-list"><dt>管理画面</dt><dd>'.tyaav_link($baseUrl.'/admin/',$baseUrl.'/admin/').'</dd><dt>収集エンドポイント</dt><dd><code>'.tyaav_h($baseUrl.'/collect.php').'</code></dd><dt>公開URL</dt><dd><code>'.tyaav_h($baseUrl).'</code></dd><dt>解析対象</dt><dd><code>'.tyaav_h($siteUrl).'</code></dd></dl></section>';echo tyaav_mmdb_upload_panel();
    } else {
        $app=$config['app']??[];$admin=$config['admin']??[];$stored=$services['runtimePreferences']->load();$selected=$stored['locale']??($app['locale']??'auto');
        echo '<section class="view-head"><div><h1>'.tyaav_h(tyaav_t('nav.settings')).'</h1><p>'.tyaav_h(tyaav_t('settings.description')).'</p></div></section>'
            .'<section class="panel"><h2>'.tyaav_h(tyaav_t('settings.language')).'</h2><form data-language-form action="api/settings.php" method="post">'
            .'<input type="hidden" name="csrf" value="'.tyaav_h(tyaa_csrf_token()).'"><label><select name="locale">';
        foreach(['auto'=>'settings.auto','en'=>'settings.english','ja'=>'settings.japanese'] as $value=>$key)echo '<option value="'.$value.'"'.($selected===$value?' selected':'').'>'.tyaav_h(tyaav_t($key)).'</option>';
        echo '</select></label> <button class="button" type="submit">'.tyaav_h(tyaav_t('common.apply')).'</button></form><p class="note">'.tyaav_h(tyaav_t('settings.asn_note')).'</p></section>'
            .'<section class="panel"><h2>'.tyaav_h(tyaav_t('settings.current')).'</h2><dl class="system-list"><dt>Site URL</dt><dd><code>'.tyaav_h($siteUrl).'</code></dd><dt>Public URL</dt><dd><code>'.tyaav_h($baseUrl).'</code></dd><dt>Timezone</dt><dd>'.tyaav_h($app['timezone']??'Asia/Tokyo').'</dd><dt>'.tyaav_h(tyaav_t('settings.retention')).'</dt><dd>'.(int)($app['retention_days']??90).' '.tyaav_h(tyaav_t('settings.days')).'</dd><dt>'.tyaav_h(tyaav_t('settings.bot_logging')).'</dt><dd>'.tyaav_h(tyaav_t(!empty($app['log_bots'])?'settings.enabled':'settings.disabled')).'</dd><dt>'.tyaav_h(tyaav_t('settings.administrator')).'</dt><dd>'.tyaav_h($admin['username']??'').'</dd></dl></section>';
        echo tyaav_mmdb_upload_panel();
    }
    $html=(string)ob_get_clean();
    if(in_array($view,['history','sessions','content','organizations','referrers','campaigns','events'],true)){
        $html='<section class="saved-view-bar" data-saved-view-bar data-report="'.tyaav_h($view).'"><label>'.tyaav_h(tyaav_t('metadata.saved_views')).'<select data-saved-view-select><option value="">'.tyaav_h(tyaav_t('common.select')).'</option></select></label><button class="button secondary" type="button" data-load-saved-view>'.tyaav_h(tyaav_t('common.apply')).'</button><button class="button secondary" type="button" data-save-current-view>'.tyaav_h(tyaav_t('saved_views.save_current')).'</button></section>'.$html;
    }
    return ['title'=>$titles[$view],'html'=>$html,'chart_data'=>$chart,'history_config'=>$history,'refresh_seconds'=>$refresh];
}
