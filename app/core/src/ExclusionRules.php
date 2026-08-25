<?php

declare(strict_types=1);

namespace Tenyen\Analytics;

use InvalidArgumentException;
use PDO;

final class ExclusionRules
{
    public const TYPES = [
        'ip_exact','ip_cidr','uri_exact','uri_prefix','native_admin','bot','country','region','asn',
        'organization','organization_category','browser','os','device','referrer_domain',
        'utm_source','utm_medium','utm_campaign',
    ];
    public const SCOPES = ['collection','analysis','both'];
    private const PRECEDENCE = [
        'native_admin'=>10,'ip_exact'=>20,'ip_cidr'=>30,'uri_exact'=>40,'uri_prefix'=>50,'bot'=>60,
        'country'=>70,'region'=>80,'asn'=>90,'organization'=>100,'organization_category'=>110,
        'browser'=>120,'os'=>130,'device'=>140,'referrer_domain'=>150,
        'utm_source'=>160,'utm_medium'=>170,'utm_campaign'=>180,
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly Crypto $crypto,
        private readonly array $organizationOverrides = []
    ) {}

    /** @return list<array<string,mixed>> */
    public function list(int $page = 1, int $perPage = 50): array
    {
        $perPage = in_array($perPage, [25,50,100], true) ? $perPage : 50;
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->query("SELECT * FROM tya_exclusion_rules ORDER BY enabled DESC,precedence ASC,rule_id ASC LIMIT {$perPage} OFFSET {$offset}");
        return $stmt->fetchAll();
    }

    public function count(): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM tya_exclusion_rules')->fetchColumn();
    }

    /** @return array<string,mixed> */
    public function save(?int $id, string $type, string $value, string $scope, bool $enabled, string $note): array
    {
        [$type,$value,$scope,$note] = self::validate($type, $value, $scope, $note);
        $now = gmdate('Y-m-d H:i:s');
        $requiresBackfill = $enabled && in_array($scope, ['analysis','both'], true);
        $storedEnabled = $requiresBackfill ? 0 : ($enabled ? 1 : 0);
        $this->pdo->beginTransaction();
        try {
            if ($id !== null && $id > 0) {
                $stmt=$this->pdo->prepare('UPDATE tya_exclusion_rules SET rule_type=?,rule_value=?,scope=?,action=\'exclude\',enabled=?,note=?,precedence=?,updated_at=? WHERE rule_id=?');
                $stmt->execute([$type,$value,$scope,$storedEnabled,$note,self::PRECEDENCE[$type],$now,$id]);
                if ($stmt->rowCount() === 0 && !$this->exists($id)) throw new InvalidArgumentException('Exclusion rule not found.');
            } else {
                $stmt=$this->pdo->prepare('INSERT INTO tya_exclusion_rules(rule_type,rule_value,scope,action,enabled,note,precedence,created_at,updated_at) VALUES(?,?,?,\'exclude\',?,?,?,?,?)');
                $stmt->execute([$type,$value,$scope,$storedEnabled,$note,self::PRECEDENCE[$type],$now,$now]);
                $id=(int)$this->pdo->lastInsertId();
            }
            $this->pdo->prepare('DELETE FROM tya_event_exclusions WHERE rule_id=?')->execute([$id]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
        if ($requiresBackfill) {
            $this->rebuildRuleMatches($id);
            $this->pdo->prepare('UPDATE tya_exclusion_rules SET enabled=1 WHERE rule_id=?')->execute([$id]);
        }
        return $this->get($id);
    }

    public function delete(int $id): void
    {
        if ($id < 1) throw new InvalidArgumentException('Invalid exclusion rule.');
        $stmt=$this->pdo->prepare('DELETE FROM tya_exclusion_rules WHERE rule_id=?');
        $stmt->execute([$id]);
    }

    /** @return array{excluded:bool,winner:?array<string,mixed>,matches:list<array<string,mixed>>,reason:string} */
    public function diagnose(array $context, string $scope): array
    {
        if (!in_array($scope, ['collection','analysis'], true)) throw new InvalidArgumentException('Invalid diagnostic scope.');
        return self::evaluateRules($this->enabledFor($scope), $context);
    }

    /** @param list<array<string,mixed>> $rules @return array{excluded:bool,winner:?array<string,mixed>,matches:list<array<string,mixed>>,reason:string} */
    public static function evaluateRules(array $rules, array $context): array
    {
        $matches=[];
        foreach ($rules as $rule) {
            if (!empty($rule['enabled']) && self::matches($rule, $context)) {
                $rule['reason']=self::reason($rule);
                $matches[]=$rule;
            }
        }
        usort($matches, static fn(array $a,array $b): int => [(int)$a['precedence'],(int)$a['rule_id']] <=> [(int)$b['precedence'],(int)$b['rule_id']]);
        $winner=$matches[0]??null;
        return ['excluded'=>$winner!==null,'winner'=>$winner,'matches'=>$matches,'reason'=>$winner['reason']??'No enabled rule matched.'];
    }

    /** @return array{excluded:bool,winner:?array<string,mixed>,matches:list<array<string,mixed>>,reason:string} */
    public function collectionDecision(array $context): array
    {
        return $this->diagnose($context, 'collection');
    }

    public function recordAnalysisMatches(int $eventId, array $context): void
    {
        if ($eventId < 1) return;
        $insert=$this->pdo->prepare('INSERT IGNORE INTO tya_event_exclusions(event_id,rule_id,matched_at) VALUES(?,?,?)');
        foreach ($this->enabledFor('analysis') as $rule) {
            if (self::matches($rule,$context)) $insert->execute([$eventId,(int)$rule['rule_id'],gmdate('Y-m-d H:i:s')]);
        }
    }

    public static function analysisSql(string $alias = ''): string
    {
        $event = ($alias !== '' ? rtrim($alias,'.').'.' : '') . 'event_id';
        return "NOT EXISTS (SELECT 1 FROM tya_event_exclusions tx JOIN tya_exclusion_rules tr ON tr.rule_id=tx.rule_id WHERE tx.event_id={$event} AND tr.enabled=1 AND tr.scope IN ('analysis','both'))";
    }

    /** @return array{type:string,value:string,scope:string,note:string} */
    public static function normalizedInput(string $type,string $value,string $scope,string $note=''): array
    {
        [$type,$value,$scope,$note]=self::validate($type,$value,$scope,$note);
        return ['type'=>$type,'value'=>$value,'scope'=>$scope,'note'=>$note];
    }

    /** @return list<array<string,mixed>> */
    private function enabledFor(string $scope): array
    {
        $stmt=$this->pdo->prepare("SELECT * FROM tya_exclusion_rules WHERE enabled=1 AND scope IN (?, 'both') ORDER BY precedence,rule_id");
        $stmt->execute([$scope]);
        return $stmt->fetchAll();
    }

    private function rebuildRuleMatches(int $ruleId): void
    {
        $rule=$this->get($ruleId);
        $cursor=0;
        $select=$this->pdo->prepare('SELECT * FROM tya_events WHERE event_id>? ORDER BY event_id LIMIT 500');
        $insert=$this->pdo->prepare('INSERT IGNORE INTO tya_event_exclusions(event_id,rule_id,matched_at) VALUES(?,?,?)');
        do {
            $select->execute([$cursor]);
            $rows=$select->fetchAll();
            foreach ($rows as $row) {
                $cursor=(int)$row['event_id'];
                if (self::matches($rule,$this->eventContext($row))) $insert->execute([$cursor,$ruleId,gmdate('Y-m-d H:i:s')]);
            }
        } while (count($rows)===500);
    }

    /** @return array<string,mixed> */
    private function eventContext(array $row): array
    {
        $classification=OrganizationClassifier::classify(isset($row['asn'])?(int)$row['asn']:null,(string)($row['asn_org']??''),(bool)($row['is_bot']??false),$this->organizationOverrides);
        return $row+['ip'=>$this->crypto->decryptIp($row['ip_encrypted']??null),'native_admin'=>false,'organization_category'=>$classification['category']];
    }

    private static function matches(array $rule,array $context): bool
    {
        $type=(string)$rule['rule_type'];$value=(string)$rule['rule_value'];
        return match($type) {
            'ip_exact' => self::ipEqual($value,(string)($context['ip']??'')),
            'ip_cidr' => self::cidrContains($value,(string)($context['ip']??'')),
            'uri_exact' => self::path((string)($context['path']??'')) === $value,
            'uri_prefix' => str_starts_with(self::path((string)($context['path']??'')),$value),
            'native_admin' => !empty($context['native_admin']),
            'bot' => !empty($context['is_bot']),
            'country' => self::equal($context['country_code']??$context['country_name']??'',$value),
            'region' => self::equal($context['region']??'',$value),
            'asn' => (string)(int)($context['asn']??0)===$value,
            'organization' => self::contains($context['asn_org']??'',$value),
            'organization_category' => self::equal($context['organization_category']??'',$value),
            'browser' => self::equal($context['browser']??'',$value),
            'os' => self::equal($context['os']??'',$value),
            'device' => self::equal($context['device_type']??$context['device']??'',$value),
            'referrer_domain' => self::equal($context['referrer_domain']??'',$value),
            'utm_source','utm_medium','utm_campaign' => self::equal($context[$type]??'',$value),
            default => false,
        };
    }

    private static function reason(array $rule): string
    {
        return sprintf('Rule #%d matched %s with precedence %d; action: exclude from %s.',(int)$rule['rule_id'],(string)$rule['rule_type'],(int)$rule['precedence'],(string)$rule['scope']);
    }

    /** @return array<string,mixed> */
    private function get(int $id): array
    {
        $stmt=$this->pdo->prepare('SELECT * FROM tya_exclusion_rules WHERE rule_id=?');$stmt->execute([$id]);$row=$stmt->fetch();
        if(!$row)throw new InvalidArgumentException('Exclusion rule not found.');return $row;
    }

    private function exists(int $id): bool
    {
        $stmt=$this->pdo->prepare('SELECT 1 FROM tya_exclusion_rules WHERE rule_id=?');$stmt->execute([$id]);return (bool)$stmt->fetchColumn();
    }

    /** @return array{0:string,1:string,2:string,3:string} */
    private static function validate(string $type,string $value,string $scope,string $note): array
    {
        $type=trim($type);$scope=trim($scope);$value=trim($value);$note=trim($note);
        if(!in_array($type,self::TYPES,true))throw new InvalidArgumentException('Unsupported exclusion type.');
        if(!in_array($scope,self::SCOPES,true))throw new InvalidArgumentException('Unsupported exclusion scope.');
        if(strlen($note)>1000)throw new InvalidArgumentException('Note is too long.');
        if($type==='native_admin'||$type==='bot')$value='1';
        if($value===''||strlen($value)>255||preg_match('/[<>\x00-\x1F\x7F]/',$value))throw new InvalidArgumentException('Invalid exclusion value.');
        if($type==='ip_exact'&&filter_var($value,FILTER_VALIDATE_IP)===false)throw new InvalidArgumentException('Invalid IP address.');
        if($type==='ip_cidr'&&!self::validCidr($value))throw new InvalidArgumentException('Invalid CIDR block.');
        if(in_array($type,['uri_exact','uri_prefix'],true))$value=self::path($value);
        if($type==='asn'){$asn=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>4294967295]]);if($asn===false)throw new InvalidArgumentException('Invalid ASN.');$value=(string)$asn;}
        if($type==='country'&&!(preg_match('/^[A-Za-z]{2}$/',$value)||strlen($value)<=128))throw new InvalidArgumentException('Invalid country.');
        if($type==='organization_category'&&!array_key_exists(strtolower($value),OrganizationClassifier::categories()))throw new InvalidArgumentException('Invalid organization category.');
        if($type==='referrer_domain'){$value=strtolower(rtrim($value,'.'));if(!filter_var($value,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME))throw new InvalidArgumentException('Invalid referrer domain.');}
        return [$type,$value,$scope,$note];
    }

    private static function path(string $value): string
    {
        $path=(string)(parse_url(trim($value),PHP_URL_PATH)??'');if($path===''||$path[0]!=='/')$path='/'.ltrim($path,'/');return $path;
    }
    private static function equal(mixed $left,string $right): bool { return self::lower(trim((string)$left))===self::lower(trim($right)); }
    private static function contains(mixed $left,string $right): bool { return str_contains(self::lower((string)$left),self::lower($right)); }
    private static function lower(string $value): string { return function_exists('mb_strtolower')?mb_strtolower($value,'UTF-8'):strtolower($value); }
    private static function validCidr(string $cidr): bool { if(!preg_match('/^([^\/]+)\/(\d{1,3})$/',$cidr,$m)||filter_var($m[1],FILTER_VALIDATE_IP)===false)return false;$packed=inet_pton($m[1]);return $packed!==false&&(int)$m[2]>=0&&(int)$m[2]<=strlen($packed)*8; }
    private static function ipEqual(string $left,string $right): bool { $a=inet_pton($left);$b=inet_pton($right);return $a!==false&&$b!==false&&hash_equals($a,$b); }
    private static function cidrContains(string $cidr,string $ip): bool
    {
        if(!self::validCidr($cidr)||filter_var($ip,FILTER_VALIDATE_IP)===false)return false;[$network,$bits]=explode('/',$cidr,2);$a=inet_pton($network);$b=inet_pton($ip);if($a===false||$b===false||strlen($a)!==strlen($b))return false;
        $bits=(int)$bits;$bytes=intdiv($bits,8);$remainder=$bits%8;if(substr($a,0,$bytes)!==substr($b,0,$bytes))return false;if($remainder===0)return true;$mask=(0xFF << (8-$remainder))&0xFF;return (ord($a[$bytes])&$mask)===(ord($b[$bytes])&$mask);
    }
}
