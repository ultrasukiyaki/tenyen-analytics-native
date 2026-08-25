<?php

declare(strict_types=1);

namespace Tenyen\Analytics;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/** Retention-safe daily rollups. Complete local days are immutable report units. */
final class DailyAggregation
{
    private const ACTORS = ['all', 'human', 'bot'];
    private const DIMENSIONS = [
        'content' => ['key' => "NULLIF(path,'')", 'label' => "MAX(page_title)", 'where' => "event_type='pageview'", 'limit' => 200],
        'channel' => ['key' => "COALESCE(NULLIF(traffic_channel,''),'Unknown')", 'label' => "COALESCE(NULLIF(traffic_channel,''),'Unknown')", 'where' => "event_type='pageview'", 'limit' => 50],
        'referrer' => ['key' => "COALESCE(NULLIF(referrer_domain,''),'Direct')", 'label' => "COALESCE(NULLIF(referrer_domain,''),'Direct')", 'where' => "event_type='pageview'", 'limit' => 100],
        'campaign' => ['key' => "CONCAT_WS(CHAR(31),utm_source,utm_medium,utm_campaign,utm_content,utm_term)", 'label' => "MAX(utm_campaign)", 'where' => "event_type='pageview' AND utm_campaign<>''", 'limit' => 100],
        'country' => ['key' => "COALESCE(NULLIF(country_code,''),'--')", 'label' => "COALESCE(MAX(NULLIF(country_name,'')),'Unknown')", 'where' => "event_type='pageview'", 'limit' => 250],
        'organization' => ['key' => "CAST(asn AS CHAR)", 'label' => "MAX(asn_org)", 'where' => "event_type='pageview' AND asn IS NOT NULL", 'limit' => 100],
        'event' => ['key' => "CONCAT_WS(CHAR(31),event_type,event_name)", 'label' => "CONCAT_WS(': ',event_type,MAX(event_name))", 'where' => "event_type NOT IN('pageview','engagement')", 'limit' => 100],
    ];

    public function __construct(private readonly PDO $pdo, private readonly DateTimeZone $timezone)
    {
    }

    public static function validateDay(mixed $value): string
    {
        $day = trim((string)$value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d') !== $day) {
            throw new InvalidArgumentException('Date must use YYYY-MM-DD.');
        }
        return $day;
    }

    /** @return array{start:string,end:string} */
    public function dayBounds(string $day): array
    {
        $local = new DateTimeImmutable(self::validateDay($day), $this->timezone);
        $utc = new DateTimeZone('UTC');
        return ['start' => $local->setTimezone($utc)->format('Y-m-d H:i:s'), 'end' => $local->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s')];
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $state = $this->pdo->query("SELECT * FROM tya_aggregate_state WHERE state_key='daily'")->fetch() ?: [];
        $counts = $this->pdo->query("SELECT COUNT(DISTINCT metric_day) complete_days,MIN(metric_day) first_day,MAX(metric_day) last_day,SUM(source_event_count) source_events FROM tya_daily_metrics WHERE actor='all'")->fetch() ?: [];
        return array_replace(['status'=>'never'],$state,['complete_days'=>(int)($counts['complete_days']??0),'first_complete_day'=>$counts['first_day']??null,'last_complete_day'=>$counts['last_day']??null,'source_events'=>(int)($counts['source_events']??0)]);
    }

    /** Idempotently replaces one complete local day. */
    public function rebuildDay(string $day): array
    {
        $day = self::validateDay($day);
        $today = new DateTimeImmutable('today', $this->timezone);
        if (new DateTimeImmutable($day, $this->timezone) >= $today) throw new InvalidArgumentException('Only completed local days can be aggregated.');
        $bounds = $this->dayBounds($day);
        $built = gmdate('Y-m-d H:i:s');
        $allMetrics = self::emptyMetrics();
        $this->pdo->beginTransaction();
        try {
            $delete = $this->pdo->prepare('DELETE FROM tya_daily_dimensions WHERE metric_day=?');
            $delete->execute([$day]);
            foreach (self::ACTORS as $actor) {
                $metrics = $this->rawMetrics($bounds['start'], $bounds['end'], $actor);
                if($actor==='all')$allMetrics=$metrics;
                $this->writeMetrics($day, $actor, $metrics, $built);
                foreach (self::DIMENSIONS as $type => $definition) {
                    foreach ($this->rawDimensions($bounds['start'], $bounds['end'], $actor, $definition) as $row) {
                        $this->writeDimension($day, $actor, $type, $row);
                    }
                }
                $this->enrichContent($day, $bounds['start'], $bounds['end'], $actor);
            }
            $this->pdo->commit();
            return ['day' => $day, 'source_events' => (int)$allMetrics['source_event_count'], 'source_event_max_id' => (int)$allMetrics['source_event_max_id']];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Rebuilds at most 31 days per call and persists the next day for resumption. */
    public function rebuildRange(string $from, string $to, int $maxDays = 31): array
    {
        $from = self::validateDay($from); $to = self::validateDay($to);
        if ($from > $to) throw new InvalidArgumentException('Start date must not be after end date.');
        $span = (int)(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days + 1;
        if ($span > 730) throw new InvalidArgumentException('Aggregate rebuild range is limited to 730 days.');
        $maxDays = max(1, min(31, $maxDays));
        $this->writeState('running', $from, $to, $from, null);
        $cursor = new DateTimeImmutable($from); $end = new DateTimeImmutable($to); $built = 0; $last = null;
        try {
            while ($cursor <= $end && $built < $maxDays) {
                $last = $cursor->format('Y-m-d');
                $result = $this->rebuildDay($last);
                $cursor = $cursor->add(new DateInterval('P1D')); $built++;
                $next = $cursor <= $end ? $cursor->format('Y-m-d') : null;
                $this->writeState($next ? 'paused' : 'complete', $from, $to, $next, null, $last, (int)$result['source_event_max_id']);
            }
        } catch (Throwable $e) {
            $this->writeState('failed', $from, $to, $last ?? $from, self::safeError($e));
            throw $e;
        }
        return ['status' => $cursor <= $end ? 'paused' : 'complete', 'built_days' => $built, 'last_day' => $last, 'next_day' => $cursor <= $end ? $cursor->format('Y-m-d') : null];
    }

    public function resume(int $maxDays = 31): array
    {
        $state = $this->status();
        if (empty($state['next_day']) || empty($state['range_end'])) throw new InvalidArgumentException('No aggregate rebuild is awaiting resumption.');
        return $this->rebuildRange((string)$state['next_day'], (string)$state['range_end'], $maxDays);
    }

    public function incremental(int $maxDays = 31): array
    {
        $status = $this->status();
        $yesterday = (new DateTimeImmutable('today', $this->timezone))->modify('-1 day')->format('Y-m-d');
        if (!empty($status['next_day'])) return $this->resume($maxDays);
        $from = (string)($status['last_complete_day'] ?? '');
        if ($from === '') {
            $oldest = (string)($this->pdo->query('SELECT MIN(occurred_at) FROM tya_events')->fetchColumn() ?: '');
            if ($oldest === '') return ['status' => 'complete', 'built_days' => 0, 'last_day' => null, 'next_day' => null];
            $from = (new DateTimeImmutable($oldest, new DateTimeZone('UTC')))->setTimezone($this->timezone)->format('Y-m-d');
        }
        if ($from > $yesterday) return ['status' => 'complete', 'built_days' => 0, 'last_day' => null, 'next_day' => null];
        return $this->rebuildRange($from, $yesterday, $maxDays);
    }

    /** @return array{complete:bool,required_through:?string,first_raw_day:?string,reason:string} */
    public function verifyCoverage(string $cutoffUtc): array
    {
        $cutoff = new DateTimeImmutable($cutoffUtc, new DateTimeZone('UTC'));
        $requiredThrough = $cutoff->setTimezone($this->timezone)->modify('-1 day')->format('Y-m-d');
        $stmt = $this->pdo->prepare('SELECT MIN(occurred_at) FROM tya_events WHERE occurred_at<?'); $stmt->execute([$cutoffUtc]);
        $oldest = (string)($stmt->fetchColumn() ?: '');
        if ($oldest === '') return ['complete' => true, 'required_through' => null, 'first_raw_day' => null, 'reason' => 'No raw events are eligible.'];
        $first = (new DateTimeImmutable($oldest, new DateTimeZone('UTC')))->setTimezone($this->timezone)->format('Y-m-d');
        $count = $this->pdo->prepare("SELECT COUNT(DISTINCT metric_day) FROM tya_daily_metrics WHERE actor='all' AND metric_day>=? AND metric_day<=?");
        $count->execute([$first, $requiredThrough]);
        $expected = (int)(new DateTimeImmutable($first))->diff(new DateTimeImmutable($requiredThrough))->days + 1;
        $complete = (int)$count->fetchColumn() === $expected;
        return ['complete' => $complete, 'required_through' => $requiredThrough, 'first_raw_day' => $first, 'reason' => $complete ? 'Daily aggregate coverage is complete.' : 'Daily aggregate coverage is incomplete; run or resume aggregation before cleanup.'];
    }

    /** Hybrid dashboard totals and daily timeline without overlapping raw/aggregate dates. */
    public function report(string $fromDay, string $toDay, string $actor = 'human'): array
    {
        $fromDay = self::validateDay($fromDay); $toDay = self::validateDay($toDay);
        if ($fromDay > $toDay || !in_array($actor, self::ACTORS, true)) throw new InvalidArgumentException('Invalid aggregate report range or actor.');
        $totals = self::emptyMetrics(); $timeline = []; $sources = []; $coveredDays=[];
        $stmt = $this->pdo->prepare("SELECT metric_day bucket,pageviews,estimated_visitors visitors,sessions,bounces,entries,exits,engaged_time_ms_sum,engaged_samples,scroll_depth_sum,scroll_samples,events,source_event_count FROM tya_daily_metrics WHERE actor=? AND metric_day>=? AND metric_day<=? ORDER BY metric_day");
        $stmt->execute([$actor, $fromDay, $toDay]);
        foreach ($stmt->fetchAll() as $row) { $day=(string)$row['bucket'];$coveredDays[$day]=true;$timeline[$day]=$row;$totals=self::addMetrics($totals,$row); }
        if($coveredDays)$sources[]='aggregate';
        foreach ($this->missingRanges($fromDay,$toDay,$coveredDays) as [$rawFrom, $rawTo]) {
            $boundsFrom = $this->dayBounds($rawFrom); $after = (new DateTimeImmutable($rawTo))->add(new DateInterval('P1D'))->format('Y-m-d'); $boundsTo = $this->dayBounds($after);
            $raw = $this->rawMetrics($boundsFrom['start'], $boundsTo['start'], $actor); $totals = self::addMetrics($totals, $raw);
            foreach ($this->rawTimeline($boundsFrom['start'], $boundsTo['start'], $actor) as $row) { $timeline[(string)$row['bucket']] = $row; }
            $sources[] = 'raw';
        }
        ksort($timeline);
        $totals['avg_duration_ms'] = self::ratio($totals['engaged_time_ms_sum'], $totals['engaged_samples']);
        $totals['avg_scroll'] = self::ratio($totals['scroll_depth_sum'], $totals['scroll_samples']);
        $days=array_keys($coveredDays);
        return ['mode' => count(array_unique($sources)) > 1 ? 'mixed' : ($sources[0] ?? 'raw'), 'summary' => $totals, 'timeline' => array_values($timeline), 'aggregate_from' => $days?min($days):null, 'aggregate_to' => $days?max($days):null];
    }

    public static function ratio(int|float $numerator, int $denominator): float { return $denominator > 0 ? $numerator / $denominator : 0.0; }

    /** @return array{aggregate:?array{0:string,1:string},raw:list<array{0:string,1:string}>} */
    public static function partitionDays(string $from, string $to, ?string $coveredFrom, ?string $coveredTo): array
    {
        $from=self::validateDay($from);$to=self::validateDay($to);
        $hasCoverage=$coveredFrom!==null&&$coveredTo!==null&&$coveredFrom<=$coveredTo&&$coveredTo>=$from&&$coveredFrom<=$to;
        if(!$hasCoverage)return ['aggregate'=>null,'raw'=>[[$from,$to]]];
        $aggregateFrom=max($from,(string)$coveredFrom);$aggregateTo=min($to,(string)$coveredTo);$raw=[];
        if($from<$aggregateFrom)$raw[]=[$from,(new DateTimeImmutable($aggregateFrom))->modify('-1 day')->format('Y-m-d')];
        if($to>$aggregateTo)$raw[]=[(new DateTimeImmutable($aggregateTo))->modify('+1 day')->format('Y-m-d'),$to];
        return ['aggregate'=>[$aggregateFrom,$aggregateTo],'raw'=>$raw];
    }

    /** @return array<string,int> */
    private function rawMetrics(string $start, string $end, string $actor): array
    {
        [$actorSql, $actorParams] = $this->actorSql($actor);
        $analysis = ExclusionRules::analysisSql('e');
        $sql = "SELECT SUM(e.event_type='pageview') pageviews,COUNT(DISTINCT CASE WHEN e.event_type='pageview' THEN NULLIF(e.visitor_id,'') END) estimated_visitors,COUNT(DISTINCT CASE WHEN e.event_type='pageview' THEN NULLIF(e.session_id,'') END) sessions,SUM(e.event_type NOT IN('pageview','engagement')) events,COUNT(*) source_event_count,COALESCE(MAX(e.event_id),0) source_event_max_id FROM tya_events e WHERE e.occurred_at>=? AND e.occurred_at<? AND {$analysis}{$actorSql}";
        $stmt = $this->pdo->prepare($sql); $stmt->execute(array_merge([$start, $end], $actorParams)); $row = $stmt->fetch() ?: [];
        $engagement = $this->pdo->prepare("SELECT COALESCE(SUM(max_duration),0) engaged_time_ms_sum,SUM(max_duration>0) engaged_samples,COALESCE(SUM(max_scroll),0) scroll_depth_sum,SUM(max_scroll BETWEEN 1 AND 100) scroll_samples FROM (SELECT e.session_id,e.path,MAX(e.duration_ms) max_duration,MAX(CASE WHEN e.scroll_depth BETWEEN 1 AND 100 THEN e.scroll_depth ELSE 0 END) max_scroll FROM tya_events e WHERE e.event_type='engagement' AND e.occurred_at>=? AND e.occurred_at<? AND {$analysis}{$actorSql} GROUP BY e.session_id,e.path) engagement");
        $engagement->execute(array_merge([$start, $end], $actorParams)); $row += $engagement->fetch() ?: [];
        $candidateActor=$actor==='all'?'':' AND c.is_bot=?';$candidateAnalysis=ExclusionRules::analysisSql('c');
        $sessions = $this->pdo->prepare("SELECT COUNT(*) sessions_started,SUM(pageviews=1) bounces FROM (SELECT e.session_id,SUM(e.event_type='pageview') pageviews,MIN(CASE WHEN e.event_type='pageview' THEN e.occurred_at END) first_pageview FROM tya_events e WHERE e.session_id IN (SELECT c.session_id FROM tya_events c WHERE c.event_type='pageview' AND c.session_id<>'' AND c.occurred_at>=? AND c.occurred_at<? AND {$candidateAnalysis}{$candidateActor} GROUP BY c.session_id) AND {$analysis}{$actorSql} GROUP BY e.session_id HAVING first_pageview>=? AND first_pageview<?) daily_sessions");
        $sessions->execute(array_merge([$start,$end],$actorParams,$actorParams,[$start,$end])); $sessionRow = $sessions->fetch() ?: [];
        $row['sessions'] = (int)($sessionRow['sessions_started'] ?? $row['sessions'] ?? 0); $row['entries'] = $row['sessions']; $row['exits'] = $row['sessions']; $row['bounces'] = (int)($sessionRow['bounces'] ?? 0);
        return array_map('intval', array_replace(self::emptyMetrics(), $row));
    }

    /** @return list<array<string,mixed>> */
    private function rawDimensions(string $start, string $end, string $actor, array $definition): array
    {
        [$actorSql, $actorParams] = $this->actorSql($actor); $analysis = ExclusionRules::analysisSql('e');
        $key = $definition['key']; $label = $definition['label']; $limit = (int)$definition['limit'];
        $sql = "SELECT {$key} dimension_key,{$label} dimension_label,SUM(e.event_type='pageview') pageviews,COUNT(DISTINCT CASE WHEN e.event_type='pageview' THEN NULLIF(e.visitor_id,'') END) estimated_visitors,COUNT(DISTINCT CASE WHEN e.event_type='pageview' THEN NULLIF(e.session_id,'') END) sessions,SUM(e.event_type NOT IN('pageview','engagement')) events,MAX(e.occurred_at) last_seen FROM tya_events e WHERE e.occurred_at>=? AND e.occurred_at<? AND {$definition['where']} AND {$analysis}{$actorSql} GROUP BY {$key} HAVING dimension_key IS NOT NULL ORDER BY pageviews DESC,events DESC LIMIT {$limit}";
        $stmt = $this->pdo->prepare($sql); $stmt->execute(array_merge([$start, $end], $actorParams)); return $stmt->fetchAll();
    }

    private function enrichContent(string $day, string $start, string $end, string $actor): void
    {
        [$actorSql, $actorParams] = $this->actorSql($actor); $analysis = ExclusionRules::analysisSql('e');
        $candidateActor=$actor==='all'?'':' AND c.is_bot=?';$candidateAnalysis=ExclusionRules::analysisSql('c');
        $candidate="e.session_id IN (SELECT c.session_id FROM tya_events c WHERE c.event_type='pageview' AND c.session_id<>'' AND c.occurred_at>=? AND c.occurred_at<? AND {$candidateAnalysis}{$candidateActor} GROUP BY c.session_id)";
        $sql = "SELECT landing_page dimension_key,COUNT(*) entries,SUM(pageviews=1) bounces,0 exits FROM (SELECT e.session_id,SUM(e.event_type='pageview') pageviews,SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN e.event_type='pageview' THEN e.path END ORDER BY e.occurred_at,e.event_id SEPARATOR '\n'),'\n',1) landing_page,MIN(CASE WHEN e.event_type='pageview' THEN e.occurred_at END) first_pageview FROM tya_events e WHERE {$candidate} AND {$analysis}{$actorSql} GROUP BY e.session_id HAVING first_pageview>=? AND first_pageview<?) s GROUP BY landing_page UNION ALL SELECT exit_page dimension_key,0 entries,0 bounces,COUNT(*) exits FROM (SELECT e.session_id,SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN e.event_type='pageview' THEN e.path END ORDER BY e.occurred_at DESC,e.event_id DESC SEPARATOR '\n'),'\n',1) exit_page,MIN(CASE WHEN e.event_type='pageview' THEN e.occurred_at END) first_pageview FROM tya_events e WHERE {$candidate} AND {$analysis}{$actorSql} GROUP BY e.session_id HAVING first_pageview>=? AND first_pageview<?) s GROUP BY exit_page";
        $params=array_merge([$start,$end],$actorParams,$actorParams,[$start,$end]);$stmt = $this->pdo->prepare($sql); $stmt->execute(array_merge($params,$params));
        $update = $this->pdo->prepare("UPDATE tya_daily_dimensions SET entries=entries+?,exits=exits+?,bounces=bounces+? WHERE metric_day=? AND actor=? AND dimension_type='content' AND dimension_hash=UNHEX(SHA2(?,256))");
        foreach ($stmt->fetchAll() as $row) $update->execute([(int)$row['entries'], (int)$row['exits'], (int)$row['bounces'], $day, $actor, (string)$row['dimension_key']]);
        $engagement=$this->pdo->prepare("SELECT path dimension_key,SUM(max_duration) engaged_time_ms_sum,SUM(max_duration>0) engaged_samples,SUM(max_scroll) scroll_depth_sum,SUM(max_scroll BETWEEN 1 AND 100) scroll_samples FROM (SELECT e.session_id,e.path,MAX(e.duration_ms) max_duration,MAX(CASE WHEN e.scroll_depth BETWEEN 1 AND 100 THEN e.scroll_depth ELSE 0 END) max_scroll FROM tya_events e WHERE e.event_type='engagement' AND e.occurred_at>=? AND e.occurred_at<? AND {$analysis}{$actorSql} GROUP BY e.session_id,e.path) samples GROUP BY path");
        $engagement->execute(array_merge([$start,$end],$actorParams));$updateEngagement=$this->pdo->prepare("UPDATE tya_daily_dimensions SET engaged_time_ms_sum=?,engaged_samples=?,scroll_depth_sum=?,scroll_samples=? WHERE metric_day=? AND actor=? AND dimension_type='content' AND dimension_hash=UNHEX(SHA2(?,256))");
        foreach($engagement->fetchAll() as $row)$updateEngagement->execute([(int)$row['engaged_time_ms_sum'],(int)$row['engaged_samples'],(int)$row['scroll_depth_sum'],(int)$row['scroll_samples'],$day,$actor,(string)$row['dimension_key']]);
    }

    private function writeMetrics(string $day, string $actor, array $m, string $built): void
    {
        $sql = 'INSERT INTO tya_daily_metrics(metric_day,actor,pageviews,estimated_visitors,sessions,bounces,entries,exits,engaged_time_ms_sum,engaged_samples,scroll_depth_sum,scroll_samples,events,source_event_count,source_event_max_id,built_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE pageviews=VALUES(pageviews),estimated_visitors=VALUES(estimated_visitors),sessions=VALUES(sessions),bounces=VALUES(bounces),entries=VALUES(entries),exits=VALUES(exits),engaged_time_ms_sum=VALUES(engaged_time_ms_sum),engaged_samples=VALUES(engaged_samples),scroll_depth_sum=VALUES(scroll_depth_sum),scroll_samples=VALUES(scroll_samples),events=VALUES(events),source_event_count=VALUES(source_event_count),source_event_max_id=VALUES(source_event_max_id),built_at=VALUES(built_at)';
        $this->pdo->prepare($sql)->execute([$day,$actor,$m['pageviews'],$m['estimated_visitors'],$m['sessions'],$m['bounces'],$m['entries'],$m['exits'],$m['engaged_time_ms_sum'],$m['engaged_samples'],$m['scroll_depth_sum'],$m['scroll_samples'],$m['events'],$m['source_event_count'],$m['source_event_max_id'],$built]);
    }

    private function writeDimension(string $day, string $actor, string $type, array $row): void
    {
        $key = substr((string)$row['dimension_key'], 0, 512); if ($key === '') return;
        $sql = 'INSERT INTO tya_daily_dimensions(metric_day,actor,dimension_type,dimension_hash,dimension_key,dimension_label,pageviews,estimated_visitors,sessions,events,last_seen) VALUES(?,?,?,UNHEX(SHA2(?,256)),?,?,?,?,?,?,?)';
        $this->pdo->prepare($sql)->execute([$day,$actor,$type,$key,$key,substr((string)($row['dimension_label'] ?? ''),0,512),(int)$row['pageviews'],(int)$row['estimated_visitors'],(int)$row['sessions'],(int)$row['events'],$row['last_seen'] ?? null]);
    }

    private function writeState(string $status, string $from, string $to, ?string $next, ?string $error, ?string $last = null, int $sourceId = 0): void
    {
        $sql = "INSERT INTO tya_aggregate_state(state_key,status,range_start,range_end,next_day,first_complete_day,last_complete_day,last_source_event_id,error_message,updated_at) VALUES('daily',?,?,?,?,?,?,?, ?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE status=VALUES(status),range_start=VALUES(range_start),range_end=VALUES(range_end),next_day=VALUES(next_day),first_complete_day=COALESCE(first_complete_day,VALUES(first_complete_day)),last_complete_day=CASE WHEN VALUES(last_complete_day) IS NULL THEN last_complete_day WHEN last_complete_day IS NULL OR VALUES(last_complete_day)>last_complete_day THEN VALUES(last_complete_day) ELSE last_complete_day END,last_source_event_id=GREATEST(last_source_event_id,VALUES(last_source_event_id)),error_message=VALUES(error_message),updated_at=VALUES(updated_at)";
        $this->pdo->prepare($sql)->execute([$status,$from,$to,$next,$last!==null?$from:null,$last,$sourceId,$error]);
    }

    /** @return array{0:string,1:list<int>} */
    private function actorSql(string $actor): array
    {
        if (!in_array($actor, self::ACTORS, true)) throw new InvalidArgumentException('Actor must be all, human, or bot.');
        return $actor === 'all' ? ['', []] : [' AND e.is_bot=?', [$actor === 'bot' ? 1 : 0]];
    }

    /** @return list<array{0:string,1:string}> */
    private function rawRanges(string $from, string $to, ?string $coveredFrom, ?string $coveredTo): array
    {
        return self::partitionDays($from,$to,$coveredFrom,$coveredTo)['raw'];
    }

    /** @param array<string,bool> $covered @return list<array{0:string,1:string}> */
    private function missingRanges(string $from,string $to,array $covered): array
    {
        $ranges=[];$rangeStart=null;$cursor=new DateTimeImmutable($from);$end=new DateTimeImmutable($to);
        while($cursor<=$end){$day=$cursor->format('Y-m-d');if(!isset($covered[$day])&&$rangeStart===null)$rangeStart=$day;if(isset($covered[$day])&&$rangeStart!==null){$ranges[]=[$rangeStart,$cursor->modify('-1 day')->format('Y-m-d')];$rangeStart=null;}$cursor=$cursor->add(new DateInterval('P1D'));}
        if($rangeStart!==null)$ranges[]=[$rangeStart,$to];return $ranges;
    }

    /** @return list<array<string,mixed>> */
    private function rawTimeline(string $start, string $end, string $actor): array
    {
        [$actorSql,$actorParams]=$this->actorSql($actor);$analysis=ExclusionRules::analysisSql('e');$offset=intdiv((new DateTimeImmutable($start,new DateTimeZone('UTC')))->setTimezone($this->timezone)->getOffset(),60);
        $sql="SELECT DATE(DATE_ADD(e.occurred_at,INTERVAL {$offset} MINUTE)) bucket,SUM(e.event_type='pageview') pageviews,COUNT(DISTINCT CASE WHEN e.event_type='pageview' THEN NULLIF(e.visitor_id,'') END) visitors,COUNT(DISTINCT CASE WHEN e.event_type='pageview' THEN NULLIF(e.session_id,'') END) sessions FROM tya_events e WHERE e.occurred_at>=? AND e.occurred_at<? AND {$analysis}{$actorSql} GROUP BY bucket ORDER BY bucket";
        $stmt=$this->pdo->prepare($sql);$stmt->execute(array_merge([$start,$end],$actorParams));return $stmt->fetchAll();
    }

    /** @return array<string,int> */
    private static function emptyMetrics(): array
    {
        return array_fill_keys(['pageviews','estimated_visitors','visitors','sessions','bounces','entries','exits','engaged_time_ms_sum','engaged_samples','scroll_depth_sum','scroll_samples','events','source_event_count','source_event_max_id'],0);
    }

    private static function addMetrics(array $left, array $right): array
    {
        foreach (array_keys(self::emptyMetrics()) as $key) $left[$key] = (int)($left[$key] ?? 0) + (int)($right[$key] ?? ($key === 'estimated_visitors' ? ($right['visitors'] ?? 0) : 0));
        $left['visitors'] = $left['estimated_visitors'];
        return $left;
    }

    private static function safeError(Throwable $e): string
    {
        return $e instanceof InvalidArgumentException ? $e->getMessage() : 'Daily aggregation failed. Check database access and retry.';
    }
}
