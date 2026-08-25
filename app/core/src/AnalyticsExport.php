<?php

declare(strict_types=1);

namespace Tenyen\Analytics;

use InvalidArgumentException;
use PDO;

final class AnalyticsExport
{
    public const DATASETS=['access','sessions','content','organizations','traffic_sources','campaigns','events'];
    public const FORMATS=['csv','json'];
    public const IP_MODES=['omit','masked','raw'];
    public function __construct(private readonly PDO $pdo,private readonly Crypto $crypto){}

    public static function csvCell(mixed $value): string
    {
        $value=(string)($value??'');return preg_match('/^[\s]*[=+\-@]/u',$value)?"'".$value:$value;
    }
    public static function maskIp(string $ip): string
    {
        if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)){$parts=explode('.',$ip);$parts[3]='0';return implode('.',$parts).'/24';}
        if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)){$packed=inet_pton($ip);return $packed===false?'':inet_ntop(substr($packed,0,6).str_repeat("\0",10)).'/48';}
        return '';
    }

    /** @return array{statement:\PDOStatement,columns:list<string>} */
    public function query(string $dataset,array $filters): array
    {
        if(!in_array($dataset,self::DATASETS,true))throw new InvalidArgumentException('Unsupported export dataset.');
        if(defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY'))$this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
        [$where,$params]=$this->where($filters,'e');
        $sql=match($dataset){
            'access'=>"SELECT e.* FROM tya_events e WHERE {$where} ORDER BY e.event_id",
            'sessions'=>"SELECT e.session_id,MIN(e.occurred_at) session_start,MAX(e.occurred_at) last_activity,MAX(e.visitor_id) visitor_id,SUM(e.event_type='pageview') pageviews,SUM(e.event_type NOT IN('pageview','engagement')) events,MAX(e.country_code) country_code,MAX(e.asn) asn,MAX(e.asn_org) organization,MAX(e.traffic_channel) traffic_channel,MAX(e.utm_campaign) campaign,MAX(e.is_bot) is_bot FROM tya_events e WHERE {$where} AND e.session_id<>'' GROUP BY e.session_id ORDER BY session_start",
            'content'=>"SELECT e.path,MAX(e.page_title) page_title,SUM(e.event_type='pageview') pageviews,COUNT(DISTINCT NULLIF(e.visitor_id,'')) visitors,COUNT(DISTINCT NULLIF(e.session_id,'')) sessions,MAX(e.occurred_at) last_seen FROM tya_events e WHERE {$where} GROUP BY e.path ORDER BY pageviews DESC",
            'organizations'=>"SELECT e.asn,e.asn_org organization,COUNT(*) events,SUM(e.event_type='pageview') pageviews,COUNT(DISTINCT NULLIF(e.visitor_id,'')) visitors,COUNT(DISTINCT NULLIF(e.session_id,'')) sessions,MAX(e.occurred_at) last_seen FROM tya_events e WHERE {$where} AND e.asn IS NOT NULL GROUP BY e.asn,e.asn_org ORDER BY pageviews DESC",
            'traffic_sources'=>"SELECT e.traffic_channel,e.referrer_domain,COUNT(*) events,SUM(e.event_type='pageview') pageviews,COUNT(DISTINCT NULLIF(e.visitor_id,'')) visitors,COUNT(DISTINCT NULLIF(e.session_id,'')) sessions FROM tya_events e WHERE {$where} GROUP BY e.traffic_channel,e.referrer_domain ORDER BY sessions DESC",
            'campaigns'=>"SELECT e.utm_source,e.utm_medium,e.utm_campaign,e.utm_content,e.utm_term,COUNT(*) events,SUM(e.event_type='pageview') pageviews,COUNT(DISTINCT NULLIF(e.visitor_id,'')) visitors,COUNT(DISTINCT NULLIF(e.session_id,'')) sessions FROM tya_events e WHERE {$where} AND e.utm_campaign<>'' GROUP BY e.utm_source,e.utm_medium,e.utm_campaign,e.utm_content,e.utm_term ORDER BY sessions DESC",
            'events'=>"SELECT e.event_type,e.event_name,COUNT(*) events,COUNT(DISTINCT NULLIF(e.visitor_id,'')) visitors,COUNT(DISTINCT NULLIF(e.session_id,'')) sessions,MAX(e.occurred_at) last_seen FROM tya_events e WHERE {$where} AND e.event_type NOT IN('pageview','engagement') GROUP BY e.event_type,e.event_name ORDER BY events DESC",
        };
        $stmt=$this->pdo->prepare($sql);$stmt->execute($params);$columns=[];for($i=0;$i<$stmt->columnCount();$i++)$columns[]=(string)$stmt->getColumnMeta($i)['name'];return ['statement'=>$stmt,'columns'=>$columns];
    }

    public function privacyRow(array $row,string $mode): array
    {
        if(!in_array($mode,self::IP_MODES,true))throw new InvalidArgumentException('Unsupported IP privacy mode.');$encrypted=$row['ip_encrypted']??null;unset($row['ip_encrypted'],$row['ip_hash']);
        if($mode!=='omit'){$ip=$this->crypto->decryptIp(is_string($encrypted)?$encrypted:null);$row['ip']=$mode==='masked'?self::maskIp($ip):$ip;}
        return $row;
    }

    /** @return array{0:string,1:list<mixed>} */
    private function where(array $filters,string $alias): array
    {
        $where=[ExclusionRules::analysisSql($alias)];$params=[];
        foreach(['from','to'] as $key)if(!empty($filters[$key])){$date=\DateTimeImmutable::createFromFormat('!Y-m-d',(string)$filters[$key],new \DateTimeZone('UTC'));if(!$date||$date->format('Y-m-d')!==(string)$filters[$key])throw new InvalidArgumentException('Invalid export date.');$where[]="{$alias}.occurred_at".($key==='from'?'>=':'<')."?";$params[]=($key==='to'?$date->modify('+1 day'):$date)->format('Y-m-d H:i:s');}
        $actor=(string)($filters['actor']??'all');if(!in_array($actor,['all','human','bot'],true))throw new InvalidArgumentException('Invalid visitor type.');if($actor==='human')$where[]="{$alias}.is_bot=0";elseif($actor==='bot')$where[]="{$alias}.is_bot=1";
        foreach(['traffic_channel'=>'source','utm_campaign'=>'campaign','event_type'=>'event','path'=>'content','country_code'=>'country','asn'=>'asn','asn_org'=>'organization'] as $column=>$key){$value=trim((string)($filters[$key]??''));if($value==='')continue;if(strlen($value)>255||preg_match('/[\x00-\x1F\x7F]/',$value))throw new InvalidArgumentException('Invalid export filter.');if($column==='asn'&&filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>4294967295]])===false)throw new InvalidArgumentException('Invalid export ASN.');$where[]="{$alias}.{$column}=?";$params[]=$column==='asn'?(int)$value:$value;}
        $watched=(string)($filters['watched']??'');if($watched!==''){if(!in_array($watched,['0','1'],true))throw new InvalidArgumentException('Invalid watch filter.');$where[]="EXISTS (SELECT 1 FROM tya_annotations export_a WHERE export_a.entity_type='organization' AND export_a.entity_hash=UNHEX(SHA2(CAST({$alias}.asn AS CHAR),256)) AND export_a.watched=?)";$params[]=(int)$watched;}
        $tagValue=(string)($filters['tag_id']??'');$tagId=$tagValue===''?0:(int)$tagValue;if($tagValue!==''&&($tagId<1||(string)$tagId!==$tagValue))throw new InvalidArgumentException('Invalid export tag.');if($tagId>0){$where[]="EXISTS (SELECT 1 FROM tya_annotations export_a JOIN tya_annotation_tags export_at ON export_at.annotation_id=export_a.annotation_id WHERE export_a.entity_type='organization' AND export_a.entity_hash=UNHEX(SHA2(CAST({$alias}.asn AS CHAR),256)) AND export_at.tag_id=?)";$params[]=$tagId;}
        return [implode(' AND ',$where),$params];
    }
}
