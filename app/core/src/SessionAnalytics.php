<?php

declare(strict_types=1);

namespace Tenyen\Analytics;

use PDO;

final class SessionAnalytics
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int} */
    public function listSessions(array $filters): array
    {
        $perPage = in_array((int)($filters['per_page'] ?? 25), [25, 50, 100], true)
            ? (int)$filters['per_page'] : 25;
        $page = max(1, (int)($filters['page'] ?? 1));
        $direction = ($filters['order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
        [$where, $params] = $this->eventWhere($filters, 'e');
        $where[] = "e.session_id <> ''";
        $whereSql = implode(' AND ', $where);

        $having = [];
        $havingParams = [];
        $keyword = $this->text($filters['q'] ?? '', 255);
        if ($keyword !== '') {
            $having[] = '(session_id LIKE ? ESCAPE \'=\' OR visitor_id LIKE ? ESCAPE \'=\' OR landing_page LIKE ? ESCAPE \'=\' OR exit_page LIKE ? ESCAPE \'=\' OR referrer LIKE ? ESCAPE \'=\' OR asn_org LIKE ? ESCAPE \'=\')';
            $like = $this->like($keyword);
            $havingParams = array_fill(0, 6, $like);
        }
        foreach (['country_name' => 'country', 'browser' => 'browser', 'os' => 'os', 'device_type' => 'device'] as $column => $key) {
            $value = $this->text($filters[$key] ?? '', 128);
            if ($value !== '') {
                $having[] = "{$column} = ?";
                $havingParams[] = $value;
            }
        }
        $organization = $this->text($filters['organization'] ?? '', 128);
        if ($organization !== '') {
            $having[] = '(asn_org LIKE ? ESCAPE \'=\' OR CAST(asn AS CHAR) LIKE ? ESCAPE \'=\')';
            $havingParams[] = $this->like($organization);
            $havingParams[] = $this->like(preg_replace('/^AS\\s*/i', '', $organization) ?? $organization);
        }
        $content = $this->text($filters['content'] ?? '', 255);
        if ($content !== '') {
            $having[] = 'landing_page LIKE ? ESCAPE \'=\'';
            $havingParams[] = $this->like($content);
        }
        $havingSql = $having ? ' HAVING ' . implode(' AND ', $having) : '';
        $aggregate = $this->sessionAggregateSql($whereSql) . $havingSql;

        $count = $this->pdo->prepare("SELECT COUNT(*) FROM ({$aggregate}) sessions");
        $count->execute(array_merge($params, $havingParams));
        $total = (int)$count->fetchColumn();
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $stmt = $this->pdo->prepare(
            "SELECT * FROM ({$aggregate}) sessions ORDER BY session_start {$direction}, session_id {$direction} LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute(array_merge($params, $havingParams));
        return ['items' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage];
    }

    /** @return array{summary:array<string,mixed>,events:list<array<string,mixed>>}|null */
    public function getSessionDetail(string $sessionId): ?array
    {
        $sessionId = $this->identifier($sessionId);
        if ($sessionId === '') {
            return null;
        }
        $aggregate = $this->sessionAggregateSql('e.session_id = ?');
        $stmt = $this->pdo->prepare($aggregate);
        $stmt->execute([$sessionId]);
        $summary = $stmt->fetch();
        if (!$summary) {
            return null;
        }
        $events = $this->pdo->prepare(
            'SELECT event_id,occurred_at,event_type,event_name,path,page_title,referrer,target_url,duration_ms,scroll_depth,'
            . 'traffic_channel,referrer_domain,utm_source,utm_medium,utm_campaign,utm_content,utm_term,event_metadata '
            . 'FROM tya_events WHERE session_id = ? ORDER BY occurred_at ASC,event_id ASC'
        );
        $events->execute([$sessionId]);
        return ['summary' => $summary, 'events' => $events->fetchAll()];
    }

    /** @return array{summary:array<string,mixed>,sessions:list<array<string,mixed>>,common_content:list<array<string,mixed>>,common_referrers:list<array<string,mixed>>}|null */
    public function getVisitorSummary(string $visitorId): ?array
    {
        $visitorId = $this->identifier($visitorId);
        if ($visitorId === '') {
            return null;
        }
        $stmt = $this->pdo->prepare(
            "SELECT MIN(occurred_at) first_seen,MAX(occurred_at) last_seen,"
            . "COUNT(DISTINCT NULLIF(session_id,'')) session_count,SUM(event_type='pageview') total_pageviews,"
            . "SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(browser,'') ORDER BY occurred_at DESC,event_id DESC SEPARATOR '\\n'),'\\n',1) browser,"
            . "SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(os,'') ORDER BY occurred_at DESC,event_id DESC SEPARATOR '\\n'),'\\n',1) os,"
            . "SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(device_type,'') ORDER BY occurred_at DESC,event_id DESC SEPARATOR '\\n'),'\\n',1) device_type,"
            . "SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(country_name,'') ORDER BY occurred_at DESC,event_id DESC SEPARATOR '\\n'),'\\n',1) country_name,"
            . "SUBSTRING_INDEX(GROUP_CONCAT(asn ORDER BY occurred_at DESC,event_id DESC SEPARATOR '\\n'),'\\n',1) asn,"
            . "SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(asn_org,'') ORDER BY occurred_at DESC,event_id DESC SEPARATOR '\\n'),'\\n',1) asn_org "
            . 'FROM tya_events WHERE visitor_id = ?'
        );
        $stmt->execute([$visitorId]);
        $summary = $stmt->fetch();
        if (!$summary || $summary['first_seen'] === null) {
            return null;
        }
        $aggregate = $this->sessionAggregateSql("e.visitor_id = ? AND e.session_id <> ''");
        $sessions = $this->pdo->prepare(
            "SELECT * FROM ({$aggregate}) sessions ORDER BY session_start DESC,session_id DESC LIMIT 100"
        );
        $sessions->execute([$visitorId]);
        $content = $this->pdo->prepare(
            "SELECT path,MAX(page_title) page_title,COUNT(*) hits FROM tya_events "
            . "WHERE visitor_id=? AND event_type='pageview' GROUP BY path ORDER BY hits DESC,path ASC LIMIT 10"
        );
        $content->execute([$visitorId]);
        $referrers = $this->pdo->prepare(
            "SELECT referrer,COUNT(*) hits FROM tya_events WHERE visitor_id=? AND event_type='pageview' AND referrer<>'' "
            . 'GROUP BY referrer ORDER BY hits DESC,referrer ASC LIMIT 10'
        );
        $referrers->execute([$visitorId]);
        return [
            'summary' => $summary,
            'sessions' => $sessions->fetchAll(),
            'common_content' => $content->fetchAll(),
            'common_referrers' => $referrers->fetchAll(),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function getContentJourneyMetrics(array $filters): array
    {
        [$where, $params] = $this->eventWhere($filters, 'e');
        $where[] = "e.session_id <> ''";
        $scope = implode(' AND ', $where);
        $sql = <<<SQL
SELECT page.path,MAX(page.page_title) page_title,COUNT(*) pageviews,
       COUNT(DISTINCT page.session_id) sessions,
       SUM(page.path = journey.landing_page) entries,
       SUM(page.path = journey.exit_page) exits,
       SUM(page.path = journey.landing_page AND journey.pageviews = 1) bounces
FROM tya_events page
JOIN (
    SELECT e.session_id,
           SUM(e.event_type='pageview') pageviews,
           SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN e.event_type='pageview' THEN e.path END ORDER BY e.occurred_at,e.event_id SEPARATOR '\n'),'\n',1) landing_page,
           SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN e.event_type='pageview' THEN e.path END ORDER BY e.occurred_at DESC,e.event_id DESC SEPARATOR '\n'),'\n',1) exit_page
    FROM tya_events e WHERE {$scope} GROUP BY e.session_id
) journey ON journey.session_id=page.session_id
WHERE page.event_type='pageview' AND {$this->aliasWhere($scope, 'e', 'page')}
GROUP BY page.path ORDER BY pageviews DESC,page.path ASC LIMIT 100
SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge($params, $params));
        return $stmt->fetchAll();
    }

    private function sessionAggregateSql(string $where): string
    {
        return <<<SQL
SELECT e.session_id,MAX(e.visitor_id) visitor_id,MIN(e.occurred_at) session_start,MAX(e.occurred_at) last_activity,
       SUM(e.event_type='pageview') pageviews,
       COALESCE((SELECT SUM(d.max_duration) FROM (
           SELECT session_id,path,MAX(duration_ms) max_duration FROM tya_events
           WHERE event_type='engagement' GROUP BY session_id,path
       ) d WHERE d.session_id=e.session_id),0) engaged_time_ms,
       SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN e.event_type='pageview' THEN e.path END ORDER BY e.occurred_at,e.event_id SEPARATOR '\n'),'\n',1) landing_page,
       SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN e.event_type='pageview' THEN e.path END ORDER BY e.occurred_at DESC,e.event_id DESC SEPARATOR '\n'),'\n',1) exit_page,
       SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(e.referrer,'') ORDER BY e.occurred_at,e.event_id SEPARATOR '\n'),'\n',1) referrer,
       SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN e.event_type='pageview' THEN NULLIF(e.traffic_channel,'') END ORDER BY e.occurred_at,e.event_id SEPARATOR '\n'),'\n',1) traffic_channel,
       SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN e.event_type='pageview' THEN NULLIF(e.referrer_domain,'') END ORDER BY e.occurred_at,e.event_id SEPARATOR '\n'),'\n',1) referrer_domain,
       SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN e.event_type='pageview' THEN NULLIF(e.utm_source,'') END ORDER BY e.occurred_at,e.event_id SEPARATOR '\n'),'\n',1) utm_source,
       SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN e.event_type='pageview' THEN NULLIF(e.utm_medium,'') END ORDER BY e.occurred_at,e.event_id SEPARATOR '\n'),'\n',1) utm_medium,
       SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN e.event_type='pageview' THEN NULLIF(e.utm_campaign,'') END ORDER BY e.occurred_at,e.event_id SEPARATOR '\n'),'\n',1) utm_campaign,
       SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(e.country_name,'') ORDER BY e.occurred_at DESC,e.event_id DESC SEPARATOR '\n'),'\n',1) country_name,
       SUBSTRING_INDEX(GROUP_CONCAT(e.asn ORDER BY e.occurred_at DESC,e.event_id DESC SEPARATOR '\n'),'\n',1) asn,
       SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(e.asn_org,'') ORDER BY e.occurred_at DESC,e.event_id DESC SEPARATOR '\n'),'\n',1) asn_org,
       SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(e.browser,'') ORDER BY e.occurred_at DESC,e.event_id DESC SEPARATOR '\n'),'\n',1) browser,
       SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(e.os,'') ORDER BY e.occurred_at DESC,e.event_id DESC SEPARATOR '\n'),'\n',1) os,
       SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(e.device_type,'') ORDER BY e.occurred_at DESC,e.event_id DESC SEPARATOR '\n'),'\n',1) device_type,
       MAX(e.is_bot) is_bot
FROM tya_events e WHERE {$where} GROUP BY e.session_id
SQL;
    }

    /** @return array{0:list<string>,1:list<mixed>} */
    private function eventWhere(array $filters, string $alias): array
    {
        $where = ['1=1'];
        $params = [];
        if (($filters['actor'] ?? 'human') === 'human') {
            $where[] = "{$alias}.is_bot=0";
        } elseif (($filters['actor'] ?? '') === 'bot') {
            $where[] = "{$alias}.is_bot=1";
        }
        if (!empty($filters['start_utc'])) {
            $where[] = "{$alias}.occurred_at>=?";
            $params[] = $filters['start_utc'];
        }
        if (!empty($filters['end_utc'])) {
            $where[] = "{$alias}.occurred_at<?";
            $params[] = $filters['end_utc'];
        }
        return [$where, $params];
    }

    private function aliasWhere(string $sql, string $from, string $to): string
    {
        return str_replace($from . '.', $to . '.', $sql);
    }

    private function identifier(mixed $value): string
    {
        $value = trim((string)$value);
        return preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $value) ? $value : '';
    }

    private function text(mixed $value, int $length): string
    {
        $value = trim(strip_tags((string)$value));
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }

    private function like(string $value): string
    {
        return '%' . strtr($value, ['=' => '==', '%' => '=%', '_' => '=_']) . '%';
    }
}
