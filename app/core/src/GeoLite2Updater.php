<?php

declare(strict_types=1);

namespace Tenyen\Analytics;

use InvalidArgumentException;
use PharData;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

final class GeoLite2Updater
{
    private const EDITIONS=['city'=>'GeoLite2-City','asn'=>'GeoLite2-ASN'];
    private const MAX_ARCHIVE=150*1024*1024;
    private const MAX_ENTRIES=200;

    public function __construct(private readonly string $root,private readonly array $config,private readonly Crypto $crypto){}

    public static function validateAccountId(mixed $value): string
    {
        $value=trim((string)$value);if(!preg_match('/^[0-9]{1,20}$/',$value))throw new InvalidArgumentException('MaxMind account ID is required.');return $value;
    }

    public static function validateLicenseKey(mixed $value): string
    {
        $value=trim((string)$value);if(!preg_match('/^[A-Za-z0-9_-]{12,80}$/',$value))throw new InvalidArgumentException('A valid MaxMind license key is required.');return $value;
    }

    public static function maskSecret(string $value): string { return $value===''?'not configured':'••••••••'.substr($value,-4); }

    public static function validateArchivePath(string $path): bool
    {
        $path=str_replace('\\','/',$path);return $path!==''&&!str_starts_with($path,'/')&&!preg_match('~(^|/)\.\.(/|$)~',$path)&&!str_contains($path,"\0");
    }

    /** @return array<string,mixed> */
    public function settings(): array
    {
        $stored=$this->readJson($this->credentialsPath());$key=$this->crypto->decryptSecret(isset($stored['license_key'])?base64_decode((string)$stored['license_key'],true)?:'':'');
        return ['account_id'=>(string)($stored['account_id']??''),'license_mask'=>self::maskSecret($key),'configured'=>(string)($stored['account_id']??'')!==''&&$key!=='','enabled'=>(bool)($stored['enabled']??false),'schedule'=>(string)($stored['schedule']??'weekly')];
    }

    public function saveSettings(mixed $accountId,mixed $licenseKey,mixed $enabled): array
    {
        $current=$this->readJson($this->credentialsPath());$account=self::validateAccountId($accountId);$key=trim((string)$licenseKey);
        if($key===''){$encrypted=(string)($current['license_key']??'');if($encrypted==='')throw new InvalidArgumentException('A MaxMind license key is required.');}
        else{$key=self::validateLicenseKey($key);$encrypted=base64_encode($this->crypto->encryptSecret($key));}
        $payload=['account_id'=>$account,'license_key'=>$encrypted,'enabled'=>filter_var($enabled,FILTER_VALIDATE_BOOLEAN),'schedule'=>'weekly','updated_at'=>gmdate(DATE_ATOM)];
        $this->writeJson($this->credentialsPath(),$payload);$state=$this->state();$state['enabled']=$payload['enabled'];$state['next_run']=$payload['enabled']?gmdate(DATE_ATOM,time()+86400):null;$this->writeJson($this->statePath(),$state);return $this->settings();
    }

    /** @return array<string,mixed> */
    public function state(): array
    {
        $default=['enabled'=>false,'schedule'=>'weekly','next_run'=>null,'last_run'=>null,'retry_count'=>0,'city'=>$this->emptyDatabase('city'),'asn'=>$this->emptyDatabase('asn')];
        return array_replace_recursive($default,$this->readJson($this->statePath()));
    }

    public function due(): bool { $s=$this->state();return !empty($s['enabled'])&&($s['next_run']===null||strtotime((string)$s['next_run'])<=time()); }

    /** @return array<string,mixed> */
    public function updateAll(bool $scheduled=false): array
    {
        if($scheduled&&!$this->due())return ['status'=>'not_due']+$this->publicStatus();
        $directory=$this->root.'/storage/geolite2';if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new RuntimeException('GeoLite2 storage directory is unavailable.');
        $lock=fopen($directory.'/update.lock','c+');if($lock===false||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('A GeoLite2 update is already running.');
        try{$results=[];foreach(array_keys(self::EDITIONS) as $kind){try{$results[$kind]=$this->update($kind);}catch(Throwable $e){$results[$kind]=['ok'=>false,'message'=>$this->safeError($e)];}}$state=$this->state();$failures=count(array_filter($results,static fn(array $r):bool=>empty($r['ok'])));$state['last_run']=gmdate(DATE_ATOM);$state['retry_count']=$failures?min(6,(int)$state['retry_count']+1):0;$delay=$failures?min(7*86400,6*3600*(2**max(0,$state['retry_count']-1))):7*86400;$state['next_run']=!empty($state['enabled'])?gmdate(DATE_ATOM,time()+$delay):null;$this->writeJson($this->statePath(),$state);return ['status'=>$failures?'partial_failure':'success','results'=>$results,'next_run'=>$state['next_run']];}
        finally{flock($lock,LOCK_UN);fclose($lock);}
    }

    /** @return array<string,mixed> */
    public function update(string $kind): array
    {
        $kind=$this->kind($kind);$credentials=$this->credentials();$state=$this->state();$entry=$state[$kind];$entry['last_attempt']=gmdate(DATE_ATOM);$entry['status']='running';$entry['error']=null;$state[$kind]=$entry;$this->writeJson($this->statePath(),$state);
        $work=$this->root.'/storage/geolite2';if(!is_dir($work)&&!mkdir($work,0700,true)&&!is_dir($work))throw new RuntimeException('GeoLite2 storage directory is unavailable.');$this->cleanupTemps($work);
        $token=bin2hex(random_bytes(8));$archive=$work.'/'.$kind.'-'.$token.'.tar.gz';$candidate=$work.'/'.$kind.'-'.$token.'.mmdb';
        try{$this->download($kind,$credentials,$archive);$this->extractExpected($archive,self::EDITIONS[$kind].'.mmdb',$candidate);$inspection=(new Installer($this->root))->inspectMmdb($candidate,$kind);if(!$inspection['ok'])throw new RuntimeException($inspection['type']===''?'Downloaded MMDB is corrupt or unreadable.':'Downloaded MMDB has the wrong database type.');$destination=$this->destination($kind);$this->atomicReplace($candidate,$destination);$meta=$this->metadata($destination,$kind,(string)$inspection['type']);$state=$this->state();$state[$kind]=array_replace($state[$kind],$meta,['status'=>'current','last_success'=>gmdate(DATE_ATOM),'last_attempt'=>gmdate(DATE_ATOM),'error'=>null]);$this->writeJson($this->statePath(),$state);$result=$meta;unset($result['path']);return ['ok'=>true,'kind'=>$kind]+$result;}
        catch(Throwable $e){$state=$this->state();$health=$this->health($kind);$state[$kind]=array_replace($state[$kind],$health,['status'=>'failed','last_attempt'=>gmdate(DATE_ATOM),'error'=>$this->safeError($e)]);$this->writeJson($this->statePath(),$state);throw new RuntimeException($this->safeError($e));}
        finally{@unlink($archive);@unlink($candidate);}
    }

    public function recordManual(string $kind): void
    {
        $kind=$this->kind($kind);$state=$this->state();$health=$this->health($kind);$state[$kind]=array_replace($state[$kind],$health,['status'=>$health['installed']?'manual':'missing','last_success'=>$health['installed']?gmdate(DATE_ATOM):$state[$kind]['last_success'],'error'=>null]);$this->writeJson($this->statePath(),$state);
    }

    public function publicStatus(): array
    {
        $state=$this->state();foreach(array_keys(self::EDITIONS) as $kind){$state[$kind]=array_replace($state[$kind],$this->health($kind));unset($state[$kind]['path']);}$settings=$this->settings();unset($settings['account_id']);return ['settings'=>$settings,'state'=>$state];
    }

    private function credentials(): array
    {
        $stored=$this->readJson($this->credentialsPath());$account=self::validateAccountId($stored['account_id']??'');$encoded=base64_decode((string)($stored['license_key']??''),true);$key=$this->crypto->decryptSecret($encoded===false?'':$encoded);return ['account_id'=>$account,'license_key'=>self::validateLicenseKey($key)];
    }

    private function download(string $kind,array $credentials,string $target): void
    {
        if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL is required for automatic GeoLite2 updates.');$query=http_build_query(['edition_id'=>self::EDITIONS[$kind],'license_key'=>$credentials['license_key'],'suffix'=>'tar.gz'],'','&',PHP_QUERY_RFC3986);$url='https://download.maxmind.com/app/geoip_download?'.$query;$out=fopen($target,'wb');if($out===false)throw new RuntimeException('Could not create the temporary archive.');$curl=curl_init($url);curl_setopt_array($curl,[CURLOPT_FILE=>$out,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>180,CURLOPT_FAILONERROR=>true,CURLOPT_USERAGENT=>'Tenyen-Analytics/0.8.0',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);$ok=curl_exec($curl);$code=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);fclose($out);if(!$ok||$code!==200||!is_file($target)||filesize($target)<1024||filesize($target)>self::MAX_ARCHIVE){@unlink($target);throw new RuntimeException($code===401?'MaxMind rejected the credentials.':'GeoLite2 download failed.');}@chmod($target,0600);
    }

    private function extractExpected(string $archive,string $expected,string $target): void
    {
        try{$phar=new PharData($archive);$count=0;$found=null;foreach(new RecursiveIteratorIterator($phar) as $path=>$file){$count++;$relative=str_replace('phar://'.$archive.'/','',(string)$path);if($count>self::MAX_ENTRIES||!self::validateArchivePath($relative))throw new RuntimeException('The GeoLite2 archive contains unsafe paths.');if($file->isFile()&&basename($relative)===$expected){if($found!==null)throw new RuntimeException('The GeoLite2 archive contains duplicate databases.');$found=$path;}}if($found===null)throw new RuntimeException('The expected MMDB is missing from the archive.');$source=fopen((string)$found,'rb');$out=fopen($target,'wb');if($source===false||$out===false)throw new RuntimeException('Could not extract the GeoLite2 database.');$written=stream_copy_to_stream($source,$out,self::MAX_ARCHIVE+1);fclose($source);fclose($out);if($written===false||$written<1024||$written>self::MAX_ARCHIVE)throw new RuntimeException('The extracted MMDB size is invalid.');@chmod($target,0600);}catch(RuntimeException $e){throw $e;}catch(Throwable){throw new RuntimeException('The GeoLite2 archive is invalid.');}
    }

    private function atomicReplace(string $candidate,string $destination): void
    {
        $directory=dirname($destination);if(!is_dir($directory)||!is_writable($directory))throw new RuntimeException('The configured GeoLite2 directory is not writable.');$incoming=$destination.'.incoming-'.bin2hex(random_bytes(5));$backup=$destination.'.previous';if(!@rename($candidate,$incoming)&&!@copy($candidate,$incoming))throw new RuntimeException('Could not stage the validated MMDB.');@chmod($incoming,0600);if(is_file($destination)){@unlink($backup);if(!@rename($destination,$backup)){@unlink($incoming);throw new RuntimeException('Could not preserve the current MMDB.');}}if(!@rename($incoming,$destination)){if(is_file($backup))@rename($backup,$destination);@unlink($incoming);throw new RuntimeException('Could not activate the validated MMDB.');}@chmod($destination,0600);@unlink($backup);
    }

    private function health(string $kind): array
    {
        $path=$this->destination($kind);if(!is_file($path))return ['installed'=>false,'readable'=>false,'health'=>'missing','path'=>$path,'filename'=>basename($path),'size'=>0,'build_date'=>null,'stale'=>true];if(!is_readable($path))return ['installed'=>true,'readable'=>false,'health'=>'unreadable','path'=>$path,'filename'=>basename($path),'size'=>(int)filesize($path),'build_date'=>null,'stale'=>true];$inspection=(new Installer($this->root))->inspectMmdb($path,$kind);if(!$inspection['ok'])return ['installed'=>true,'readable'=>true,'health'=>$inspection['type']===''?'corrupt':'wrong_type','path'=>$path,'filename'=>basename($path),'size'=>(int)filesize($path),'build_date'=>null,'stale'=>true];return $this->metadata($path,$kind,(string)$inspection['type']);
    }

    private function metadata(string $path,string $kind,string $type): array
    {
        $build=0;try{$reader=class_exists(\MaxMind\Db\Reader::class)?new \MaxMind\Db\Reader($path):new MmdbReader($path);$metadata=$reader->metadata();$reader->close();if(is_array($metadata))$build=(int)($metadata['build_epoch']??0);elseif(is_object($metadata))$build=(int)($metadata->buildEpoch??0);}catch(Throwable){}if($build<=0)$build=(int)(filemtime($path)?:0);$stale=$build<time()-45*86400;return ['installed'=>true,'readable'=>true,'health'=>$stale?'stale':'current','path'=>$path,'filename'=>basename($path),'database_type'=>$type,'size'=>(int)filesize($path),'build_date'=>$build?gmdate('Y-m-d',$build):null,'stale'=>$stale];
    }

    private function destination(string $kind): string { $geo=$this->config['geoip']??[];return (string)($kind==='city'?($geo['city_database']??$this->root.'/data/GeoLite2-City.mmdb'):($geo['asn_database']??$this->root.'/data/GeoLite2-ASN.mmdb')); }
    private function kind(string $kind): string { if(!isset(self::EDITIONS[$kind]))throw new InvalidArgumentException('Database kind must be city or asn.');return $kind; }
    private function credentialsPath(): string { return $this->root.'/storage/geolite2-credentials.json'; }
    private function statePath(): string { return $this->root.'/storage/geolite2-state.json'; }
    private function emptyDatabase(string $kind): array { $path=$this->destination($kind);return ['kind'=>$kind,'installed'=>false,'path'=>$path,'filename'=>basename($path),'build_date'=>null,'size'=>0,'last_success'=>null,'last_attempt'=>null,'status'=>'never','health'=>'missing','error'=>null]; }
    private function cleanupTemps(string $directory): void { foreach(glob($directory.'/*')?:[] as $file)if(is_file($file)&&preg_match('/\.(tar\.gz|mmdb|incoming-[a-f0-9]+)$/',basename($file))&&filemtime($file)!==false&&filemtime($file)<time()-86400)@unlink($file); }
    private function readJson(string $path): array { if(!is_file($path)||!is_readable($path))return [];$data=json_decode((string)file_get_contents($path),true);return is_array($data)?$data:[]; }
    private function writeJson(string $path,array $data): void { $dir=dirname($path);if(!is_dir($dir)||!is_writable($dir))throw new RuntimeException('Storage directory is not writable.');$tmp=$path.'.tmp-'.bin2hex(random_bytes(5));if(file_put_contents($tmp,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",LOCK_EX)===false)throw new RuntimeException('Could not save GeoLite2 state.');@chmod($tmp,0600);if(!@rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('Could not save GeoLite2 state.');} }
    private function safeError(Throwable $e): string { $message=$e->getMessage();$allowed=['credentials','cURL','archive','MMDB','database','update','directory','running','download','staged'];return array_reduce($allowed,static fn(string $safe,string $word):string=>stripos($message,$word)!==false?$message:$safe,'GeoLite2 update failed. Check credentials, HTTPS access, and storage permissions.'); }
}
