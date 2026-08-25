<?php

declare(strict_types=1);

namespace Tenyen\Analytics;

use InvalidArgumentException;
use PDO;
use RuntimeException;

final class LogLifecycle
{
    public const PRESETS = [30,90,180,365];

    public function __construct(private readonly PDO $pdo,private readonly string $statePath,private readonly int $configDays=90){}

    public static function validateRetention(mixed $value): ?int
    {
        if($value===null||$value===''||$value==='unlimited')return null;
        $days=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>3650]]);
        if($days===false)throw new InvalidArgumentException('Retention must be unlimited or between 1 and 3650 days.');
        return (int)$days;
    }

    /** @return array<string,mixed> */
    public function state(): array
    {
        $default=['retention_days'=>$this->configDays>0?$this->configDays:null,'cleanup'=>['status'=>'never','deleted'=>0,'last_run'=>null,'next_run'=>null,'error'=>null,'cutoff'=>null]];
        if(!is_file($this->statePath)||!is_readable($this->statePath))return $default;
        $data=json_decode((string)file_get_contents($this->statePath),true);
        return is_array($data)?array_replace_recursive($default,$data):$default;
    }

    public function saveRetention(mixed $value): array
    {
        $state=$this->state();$state['retention_days']=self::validateRetention($value);$this->write($state);return $state;
    }

    /** @return array{cutoff:?string,events:int,sessions:int,retention_days:?int} */
    public function preview(?int $days=null): array
    {
        if(func_num_args()===0)$days=$this->state()['retention_days'];
        if($days===null)return ['cutoff'=>null,'events'=>0,'sessions'=>0,'retention_days'=>null];
        $cutoff=gmdate('Y-m-d H:i:s',time()-$days*86400);
        $stmt=$this->pdo->prepare("SELECT COUNT(*) events,COUNT(DISTINCT NULLIF(session_id,'')) sessions FROM tya_events WHERE occurred_at<?");$stmt->execute([$cutoff]);$row=$stmt->fetch()?:[];
        return ['cutoff'=>$cutoff,'events'=>(int)($row['events']??0),'sessions'=>(int)($row['sessions']??0),'retention_days'=>$days];
    }

    /** @return array<string,mixed> */
    public function cleanup(int $batchSize=1000,int $maxBatches=100): array
    {
        $batchSize=max(100,min(5000,$batchSize));$maxBatches=max(1,min(1000,$maxBatches));$directory=dirname($this->statePath);
        if(!is_dir($directory)||!is_writable($directory))throw new RuntimeException('Storage directory is not writable.');
        $lock=fopen($directory.'/cleanup.lock','c+');if($lock===false||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Cleanup is already running.');
        try{
            $state=$this->state();$days=$state['retention_days'];if($days===null)throw new InvalidArgumentException('Retention is unlimited; cleanup has nothing to delete.');
            $cutoff=(($state['cleanup']['status']??'')==='running'&&!empty($state['cleanup']['cutoff']))?(string)$state['cleanup']['cutoff']:gmdate('Y-m-d H:i:s',time()-(int)$days*86400);
            $deleted=0;$state['cleanup']=array_replace($state['cleanup'],['status'=>'running','cutoff'=>$cutoff,'last_attempt'=>gmdate(DATE_ATOM),'error'=>null]);$this->write($state);
            for($batch=0;$batch<$maxBatches;$batch++){
                $select=$this->pdo->prepare("SELECT event_id FROM tya_events WHERE occurred_at<? ORDER BY event_id LIMIT {$batchSize}");$select->execute([$cutoff]);$ids=array_map('intval',$select->fetchAll(PDO::FETCH_COLUMN));if(!$ids)break;
                $this->pdo->beginTransaction();try{$marks=implode(',',array_fill(0,count($ids),'?'));$delete=$this->pdo->prepare("DELETE FROM tya_events WHERE event_id IN ({$marks})");$delete->execute($ids);$count=$delete->rowCount();$this->pdo->commit();}catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
                $deleted+=$count;$state['cleanup']['deleted']=(int)($state['cleanup']['deleted']??0)+$count;$this->write($state);
                if(count($ids)<$batchSize)break;
            }
            $remaining=$this->pdo->prepare('SELECT COUNT(*) FROM tya_events WHERE occurred_at<?');$remaining->execute([$cutoff]);$left=(int)$remaining->fetchColumn();$now=gmdate(DATE_ATOM);
            $state['cleanup']=array_replace($state['cleanup'],['status'=>$left>0?'paused':'success','last_run'=>$now,'next_run'=>gmdate(DATE_ATOM,time()+86400),'remaining'=>$left,'cutoff'=>$left>0?$cutoff:null,'error'=>null]);$this->write($state);
            return ['deleted'=>$deleted,'remaining'=>$left]+$state['cleanup'];
        }catch(\Throwable $e){$safe=self::safeError($e);$state=$this->state();$state['cleanup']=array_replace($state['cleanup'],['status'=>'failed','last_attempt'=>gmdate(DATE_ATOM),'error'=>$safe]);$this->write($state);if($e instanceof InvalidArgumentException)throw $e;throw new RuntimeException($safe);}finally{flock($lock,LOCK_UN);fclose($lock);}
    }

    /** @return array<string,mixed> */
    public function diagnostics(): array
    {
        $summary=$this->pdo->query("SELECT COUNT(*) events,COUNT(DISTINCT NULLIF(session_id,'')) sessions,MIN(occurred_at) oldest,MAX(occurred_at) newest FROM tya_events")->fetch()?:[];
        $monthly=$this->pdo->query("SELECT DATE_FORMAT(occurred_at,'%Y-%m') month,COUNT(*) events FROM tya_events GROUP BY DATE_FORMAT(occurred_at,'%Y-%m') ORDER BY month DESC LIMIT 24")->fetchAll();
        $database=(string)$this->pdo->query('SELECT DATABASE()')->fetchColumn();$size=0;$tableSize=0;
        if($database!==''){$stmt=$this->pdo->prepare('SELECT COALESCE(SUM(DATA_LENGTH+INDEX_LENGTH),0),COALESCE(SUM(CASE WHEN TABLE_NAME=\'tya_events\' THEN DATA_LENGTH+INDEX_LENGTH ELSE 0 END),0) FROM information_schema.TABLES WHERE TABLE_SCHEMA=?');$stmt->execute([$database]);$sizes=$stmt->fetch(PDO::FETCH_NUM)?:[0,0];$size=(int)$sizes[0];$tableSize=(int)$sizes[1];}
        return ['database_bytes'=>$size,'event_table_bytes'=>$tableSize,'events'=>(int)($summary['events']??0),'sessions'=>(int)($summary['sessions']??0),'oldest'=>$summary['oldest']??null,'newest'=>$summary['newest']??null,'monthly'=>$monthly,'state'=>$this->state()];
    }

    public function due(): bool
    {
        $state=$this->state();if($state['retention_days']===null)return false;$next=$state['cleanup']['next_run']??null;return $next===null||strtotime((string)$next)<=time();
    }

    private function write(array $state): void
    {
        $directory=dirname($this->statePath);if(!is_dir($directory)||!is_writable($directory))throw new RuntimeException('Storage directory is not writable.');$temporary=$this->statePath.'.tmp-'.bin2hex(random_bytes(6));$json=json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        if(file_put_contents($temporary,$json,LOCK_EX)===false)throw new RuntimeException('Could not write lifecycle state.');@chmod($temporary,0600);if(!@rename($temporary,$this->statePath)){@unlink($temporary);throw new RuntimeException('Could not save lifecycle state.');}
    }
    private static function safeError(\Throwable $e): string { return match(true){$e instanceof InvalidArgumentException=>$e->getMessage(),default=>'Cleanup failed. Check database and storage permissions.'}; }
}
