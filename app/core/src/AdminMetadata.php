<?php

declare(strict_types=1);

namespace Tenyen\Analytics;

use InvalidArgumentException;
use PDO;
use Throwable;

final class AdminMetadata
{
    public const ENTITY_TYPES = ['organization', 'visitor', 'content', 'referrer', 'campaign', 'external_target'];
    public const COLORS = ['slate', 'blue', 'cyan', 'green', 'amber', 'orange', 'red', 'purple'];
    public const REPORTS = ['history', 'sessions', 'content', 'organizations', 'referrers', 'campaigns', 'events'];

    /** @var array<string,list<string>> */
    private const VIEW_KEYS = [
        'history' => ['analysis_period','analysis_from','analysis_to','analysis_actor','q','from','to','event','actor','country','browser','os','device','organization','path','per_page','order','visible_columns','tag_ids','watched'],
        'sessions' => ['analysis_period','analysis_from','analysis_to','analysis_actor','from','to','q','actor','country','organization','browser','os','device','content','per_page','order','tag_ids','watched'],
        'content' => ['analysis_period','analysis_from','analysis_to','analysis_actor','q','per_page','order','tag_ids'],
        'organizations' => ['analysis_period','analysis_from','analysis_to','analysis_actor','q','per_page','order','tag_id','tag_ids','watched'],
        'referrers' => ['analysis_period','analysis_from','analysis_to','analysis_actor','q','per_page','order','tag_ids'],
        'campaigns' => ['analysis_period','analysis_from','analysis_to','analysis_actor','q','per_page','order','tag_ids'],
        'events' => ['analysis_period','analysis_from','analysis_to','analysis_actor','event_type','q','per_page','order'],
    ];

    public function __construct(private readonly PDO $pdo, private readonly string $owner)
    {
    }

    public static function entityKey(string $type, string $key): string
    {
        if (!in_array($type, self::ENTITY_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported entity type.');
        }
        $key = trim($key);
        if ($key === '' || strlen($key) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $key)) {
            throw new InvalidArgumentException('Invalid entity key.');
        }
        if ($type === 'organization' && !preg_match('/^[1-9][0-9]{0,9}$/', $key)) {
            throw new InvalidArgumentException('An organization key must be a numeric ASN.');
        }
        if ($type === 'visitor' && !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $key)) {
            throw new InvalidArgumentException('Invalid anonymous visitor identifier.');
        }
        if ($type === 'referrer' || $type === 'external_target') {
            $key = strtolower(rtrim($key, '.'));
            if (strlen($key) > 253 || !preg_match('/^[a-z0-9.-]+$/i', $key)) {
                throw new InvalidArgumentException('Invalid domain key.');
            }
        }
        if ($type === 'campaign') {
            $decoded = json_decode($key, true);
            $names = ['source','medium','campaign','content','term'];
            if (!is_array($decoded) || array_keys($decoded) !== $names) {
                throw new InvalidArgumentException('Invalid campaign key.');
            }
            foreach ($decoded as $value) {
                if (!is_string($value) || strlen($value) > 255) throw new InvalidArgumentException('Invalid campaign dimension.');
            }
            $key = (string)json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $key;
    }

    /** @param list<int> $tagIds */
    public function saveAnnotation(string $type, string $key, string $alias, string $note, bool $watched, array $tagIds): array
    {
        $key = self::entityKey($type, $key);
        $alias = self::plain($alias, 120, 'Alias');
        $note = self::plain($note, 4000, 'Note', true);
        if ($type !== 'organization') $watched = false;
        $tagIds = array_values(array_unique(array_map('intval', $tagIds)));
        if (count($tagIds) > 50 || min($tagIds ?: [1]) < 1) throw new InvalidArgumentException('Invalid tag selection.');
        $hash = hash('sha256', $key, true);
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO tya_annotations (entity_type,entity_hash,entity_key,alias,note,watched,created_at,updated_at)
                 VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE entity_key=VALUES(entity_key),alias=VALUES(alias),note=VALUES(note),watched=VALUES(watched),
                 updated_at=IF(alias<>VALUES(alias) OR note<>VALUES(note) OR watched<>VALUES(watched),UTC_TIMESTAMP(),updated_at)'
            );
            $statement->execute([$type, $hash, $key, $alias, $note, $watched ? 1 : 0]);
            $idStatement = $this->pdo->prepare('SELECT annotation_id FROM tya_annotations WHERE entity_type=? AND entity_hash=?');
            $idStatement->execute([$type, $hash]);
            $id = (int)$idStatement->fetchColumn();
            $valid = [];
            if ($tagIds) {
                $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
                $tags = $this->pdo->prepare("SELECT tag_id FROM tya_tags WHERE tag_id IN ({$placeholders})");
                $tags->execute($tagIds);
                $valid = array_map('intval', $tags->fetchAll(PDO::FETCH_COLUMN));
                if (count($valid) !== count($tagIds)) throw new InvalidArgumentException('A selected tag does not exist.');
            }
            $delete = $this->pdo->prepare('DELETE FROM tya_annotation_tags WHERE annotation_id=?');
            $delete->execute([$id]);
            $insert = $this->pdo->prepare('INSERT IGNORE INTO tya_annotation_tags (annotation_id,tag_id,created_at) VALUES (?,?,UTC_TIMESTAMP())');
            foreach ($valid as $tagId) $insert->execute([$id, $tagId]);
            $this->pdo->commit();
            return $this->annotation($type, $key) ?? [];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function toggleWatch(string $key, bool $watched): array
    {
        $current = $this->annotation('organization', self::entityKey('organization', $key));
        return $this->saveAnnotation('organization', $key, (string)($current['alias'] ?? ''), (string)($current['note'] ?? ''), $watched, array_column($current['tags'] ?? [], 'tag_id'));
    }

    public function annotation(string $type, string $key): ?array
    {
        $key = self::entityKey($type, $key);
        $statement = $this->pdo->prepare('SELECT * FROM tya_annotations WHERE entity_type=? AND entity_hash=?');
        $statement->execute([$type, hash('sha256', $key, true)]);
        $row = $statement->fetch();
        if (!$row) return null;
        $row['watched'] = (bool)$row['watched'];
        $row['tags'] = $this->tagsFor([(int)$row['annotation_id']])[(int)$row['annotation_id']] ?? [];
        return $row;
    }

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int} */
    public function listAnnotations(array $filters): array
    {
        $page = max(1, (int)($filters['page'] ?? 1));
        $perPage = min(100, max(10, (int)($filters['per_page'] ?? 25)));
        $where = ['1=1']; $params = [];
        $type = (string)($filters['entity_type'] ?? '');
        if ($type !== '') {
            if (!in_array($type, self::ENTITY_TYPES, true)) throw new InvalidArgumentException('Invalid entity type filter.');
            $where[] = 'a.entity_type=?'; $params[] = $type;
        }
        $watched = (string)($filters['watched'] ?? '');
        if ($watched !== '') {
            if (!in_array($watched, ['0','1'], true)) throw new InvalidArgumentException('Invalid watched filter.');
            $where[] = 'a.watched=?'; $params[] = (int)$watched;
        }
        $tagId=(int)($filters['tag_id']??0);
        if($tagId>0){$where[]='EXISTS (SELECT 1 FROM tya_annotation_tags filter_tags WHERE filter_tags.annotation_id=a.annotation_id AND filter_tags.tag_id=?)';$params[]=$tagId;}
        $query = trim((string)($filters['q'] ?? ''));
        if (self::length($query) > 120) throw new InvalidArgumentException('Search is too long.');
        if ($query !== '') {
            $like = '%' . self::escapeLike($query) . '%';
            $where[] = "(a.alias LIKE ? ESCAPE '=' OR a.entity_key LIKE ? ESCAPE '=' OR a.note LIKE ? ESCAPE '=')";
            array_push($params, $like, $like, $like);
        }
        $sqlWhere = implode(' AND ', $where);
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM tya_annotations a WHERE {$sqlWhere}");
        $count->execute($params); $total = (int)$count->fetchColumn();
        $offset = ($page - 1) * $perPage;
        $statement = $this->pdo->prepare("SELECT a.* FROM tya_annotations a WHERE {$sqlWhere} ORDER BY a.watched DESC,a.updated_at DESC,a.annotation_id DESC LIMIT {$perPage} OFFSET {$offset}");
        $statement->execute($params); $items = $statement->fetchAll();
        $tagMap = $this->tagsFor(array_map(static fn(array $row): int => (int)$row['annotation_id'], $items));
        foreach ($items as &$item) {
            $item['watched'] = (bool)$item['watched'];
            $item['tags'] = $tagMap[(int)$item['annotation_id']] ?? [];
        }
        return ['items'=>$items,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/$perPage))];
    }

    public function saveTag(?int $id, string $name, string $color): array
    {
        $name = preg_replace('/\s+/u', ' ', trim($name)) ?? '';
        $name = self::plain($name, 50, 'Tag');
        if ($name === '') throw new InvalidArgumentException('Tag name is required.');
        if (str_contains($name, '<') || str_contains($name, '>')) throw new InvalidArgumentException('Tag names cannot contain HTML markup.');
        if (!in_array($color, self::COLORS, true)) throw new InvalidArgumentException('Invalid tag color.');
        $normalized = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        try {
            if ($id) {
                $statement = $this->pdo->prepare('UPDATE tya_tags SET name=?,normalized_name=?,color=?,updated_at=UTC_TIMESTAMP() WHERE tag_id=?');
                $statement->execute([$name,$normalized,$color,$id]);
            } else {
                $statement = $this->pdo->prepare('INSERT INTO tya_tags (name,normalized_name,color,created_at,updated_at) VALUES (?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
                $statement->execute([$name,$normalized,$color]); $id = (int)$this->pdo->lastInsertId();
            }
        } catch (\PDOException $exception) {
            if ((string)$exception->getCode() === '23000') throw new InvalidArgumentException('A tag with this name already exists.');
            throw $exception;
        }
        $statement = $this->pdo->prepare('SELECT * FROM tya_tags WHERE tag_id=?');
        $statement->execute([$id]);
        return $statement->fetch() ?: [];
    }

    public function listTags(string $query = ''): array
    {
        if (self::length($query) > 120) throw new InvalidArgumentException('Search is too long.');
        $params=[];$where='';
        if ($query !== '') {$where="WHERE t.name LIKE ? ESCAPE '='";$params[]='%'.self::escapeLike($query).'%';}
        $statement=$this->pdo->prepare("SELECT t.*,COUNT(at.annotation_id) usage_count FROM tya_tags t LEFT JOIN tya_annotation_tags at ON at.tag_id=t.tag_id {$where} GROUP BY t.tag_id ORDER BY t.name");
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public function deleteTag(int $id): void
    {
        $statement=$this->pdo->prepare('DELETE FROM tya_tags WHERE tag_id=?');
        $statement->execute([$id]);
        if (!$statement->rowCount()) throw new InvalidArgumentException('Tag not found.');
    }

    public function saveView(?int $id, string $report, string $name, string $description, array $state, bool $pinned, bool $default): array
    {
        if (!in_array($report,self::REPORTS,true)) throw new InvalidArgumentException('Unsupported report.');
        $name=self::plain($name,120,'Name'); if($name==='')throw new InvalidArgumentException('Name is required.');
        $description=self::plain($description,500,'Description',true);
        $state=$this->normalizeViewState($report,$state);
        $json=(string)json_encode(['version'=>1,'state'=>$state],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $this->pdo->beginTransaction();
        try {
            if($default){$clear=$this->pdo->prepare('UPDATE tya_saved_views SET is_default=0,updated_at=UTC_TIMESTAMP() WHERE owner_key=? AND report=?');$clear->execute([$this->owner,$report]);}
            if($id){
                $statement=$this->pdo->prepare('UPDATE tya_saved_views SET report=?,name=?,description=?,state_json=?,pinned=?,is_default=?,updated_at=UTC_TIMESTAMP() WHERE saved_view_id=? AND owner_key=?');
                $statement->execute([$report,$name,$description,$json,$pinned?1:0,$default?1:0,$id,$this->owner]);
            }else{
                $statement=$this->pdo->prepare('INSERT INTO tya_saved_views (owner_key,report,name,description,state_json,pinned,is_default,created_at,updated_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
                $statement->execute([$this->owner,$report,$name,$description,$json,$pinned?1:0,$default?1:0]);$id=(int)$this->pdo->lastInsertId();
            }
            $this->pdo->commit();
        }catch(Throwable $exception){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $exception;}
        return $this->getView((int)$id);
    }

    public function listViews(?string $report=null): array
    {
        $params=[$this->owner];$where='owner_key=?';
        if($report!==null){if(!in_array($report,self::REPORTS,true))throw new InvalidArgumentException('Unsupported report.');$where.=' AND report=?';$params[]=$report;}
        $statement=$this->pdo->prepare("SELECT * FROM tya_saved_views WHERE {$where} ORDER BY pinned DESC,is_default DESC,name");
        $statement->execute($params);$rows=$statement->fetchAll();
        foreach($rows as &$row){$decoded=json_decode((string)$row['state_json'],true);$row['state']=$decoded['state']??[];unset($row['state_json']);$row['pinned']=(bool)$row['pinned'];$row['is_default']=(bool)$row['is_default'];}
        return $rows;
    }

    public function deleteView(int $id): void
    {
        $statement=$this->pdo->prepare('DELETE FROM tya_saved_views WHERE saved_view_id=? AND owner_key=?');$statement->execute([$id,$this->owner]);
        if(!$statement->rowCount())throw new InvalidArgumentException('Saved view not found.');
    }

    private function getView(int $id): array
    {
        foreach($this->listViews() as $view)if((int)$view['saved_view_id']===$id)return $view;
        throw new InvalidArgumentException('Saved view not found.');
    }

    private function normalizeViewState(string $report,array $state): array
    {
        $unknown=array_diff(array_keys($state),self::VIEW_KEYS[$report]);
        if($unknown)throw new InvalidArgumentException('Saved view contains unsupported keys.');
        $result=[];
        foreach($state as $key=>$value){
            if(in_array($key,['tag_ids','visible_columns'],true)){
                if(!is_array($value)||count($value)>50)throw new InvalidArgumentException('Invalid saved-view array.');
                $result[$key]=array_values(array_unique(array_map(static fn($item):string=>substr((string)$item,0,64),$value)));continue;
            }
            if(!is_scalar($value)||strlen((string)$value)>255)throw new InvalidArgumentException('Invalid saved-view value.');
            $result[$key]=(string)$value;
        }
        unset($result['page'],$result['csrf'],$result['session_id'],$result['site_token']);
        if(isset($result['analysis_period'])&&!in_array($result['analysis_period'],['today','yesterday','7d','30d','custom'],true))throw new InvalidArgumentException('Invalid date mode.');
        if(isset($result['analysis_actor'])&&!in_array($result['analysis_actor'],['human','bot','all'],true))throw new InvalidArgumentException('Invalid visitor type.');
        if(isset($result['order'])&&!in_array($result['order'],['asc','desc'],true))throw new InvalidArgumentException('Invalid sort order.');
        return $result;
    }

    /** @param list<int> $ids @return array<int,list<array<string,mixed>>> */
    private function tagsFor(array $ids): array
    {
        if(!$ids)return[];
        $placeholders=implode(',',array_fill(0,count($ids),'?'));
        $statement=$this->pdo->prepare("SELECT at.annotation_id,t.tag_id,t.name,t.color FROM tya_annotation_tags at JOIN tya_tags t ON t.tag_id=at.tag_id WHERE at.annotation_id IN ({$placeholders}) ORDER BY t.name");
        $statement->execute($ids);$result=[];
        foreach($statement->fetchAll() as $row)$result[(int)$row['annotation_id']][]=$row;
        return$result;
    }

    private static function plain(string $value,int $maximum,string $label,bool $multiline=false): string
    {
        $value=trim(str_replace(["\r\n","\r"],"\n",$value));
        if(!$multiline)$value=str_replace("\n",' ',$value);
        if(preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value))throw new InvalidArgumentException("{$label} contains control characters.");
        if(self::length($value)>$maximum)throw new InvalidArgumentException("{$label} is too long.");
        return$value;
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen')?mb_strlen($value,'UTF-8'):strlen($value);
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['=','%','_'],['==','=%','=_'],$value);
    }
}
