<?php
declare(strict_types=1);
/** 辞書・ルール・アカウント・JSON保存。外部ライブラリとDBは不要である。 */
final class Game
{
    public const GENRES = ['MOVIE'=>'映画のタイトル','HISTORY'=>'歴史上の人物','FISH'=>'魚の名前','ROBOT_ANIME'=>'日本のロボットアニメ','SHOWA'=>'昭和のヒット曲・歌手名'];
    public array $words = [];
    public array $db;
    private array $base = [];
    private array $config;
    public string $dictionaryVersion;

    public function __construct(array $config, ?string $csv = null)
    {
        if (!extension_loaded('mbstring') || !class_exists('Normalizer')) {
            throw new RuntimeException('PHPのmbstringとintl拡張が必要である。');
        }
        $this->config = $config;
        $csv ??= __DIR__.'/words.csv';
        $this->dictionaryVersion = hash_file('sha256', $csv);
        $f = fopen($csv, 'r');
        if (!$f) throw new RuntimeException('初期辞書を読み込めない。');
        fgetcsv($f, 0, ',', '"', '');
        while (($c = fgetcsv($f, 0, ',', '"', '')) !== false) {
            if (count($c) !== 6) throw new RuntimeException('初期辞書の形式が不正である。');
            [$id,$genre,$type,$name,$reading,$aliases] = $c;
            $this->base[$id] = compact('id','genre','type','name','reading') + ['aliases'=>$aliases === '' ? [] : explode('|',$aliases)];
        }
        fclose($f);
        $this->db = self::emptyDb();
        $this->words = $this->base;
    }
    private static function emptyDb(): array
    {
        return ['version'=>1,'users'=>[],'customWords'=>[],'matches'=>[],'scores'=>[],'attempts'=>[]];
    }
    /** 共有ロックファイルを使い、読込→変更→一時ファイル置換までを排他制御する。 */
    public function transaction(callable $fn, bool $write = true): mixed
    {
        $dir = $this->config['data_dir'];
        if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('保存先を作成できない。');
        $lock = fopen($dir.'/data.lock','c');
        if (!$lock || !flock($lock, $write ? LOCK_EX : LOCK_SH)) throw new RuntimeException('保存先をロックできない。');
        $temp = null;
        try {
            $file = $dir.'/data.json';
            if (is_file($file)) {
                if (filesize($file) > 30_000_000) throw new RuntimeException('保存データが上限に達した。');
                $raw = file_get_contents($file);
                $db = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($db) || ($db['version'] ?? null) !== 1) throw new RuntimeException('保存形式が不正である。');
                foreach (['users','customWords','matches','scores','attempts'] as $key) {
                    if (!isset($db[$key]) || !is_array($db[$key])) throw new RuntimeException('保存データが破損している。');
                }
                $this->db = $db;
            } else $this->db = self::emptyDb();
            $this->words = $this->base;
            foreach ($this->db['customWords'] as $w) $this->words[$w['id']] = $w;
            $result = $fn($this);
            if ($write) {
                $json = json_encode($this->db, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
                if (strlen($json)>30_000_000) throw new RuntimeException('保存容量の上限に達した。');
                $temp = tempnam($dir, 'save-');
                if ($temp === false || file_put_contents($temp,$json)!==strlen($json) || !rename($temp,$file)) throw new RuntimeException('保存できない。ディレクトリの権限を確認する。');
                chmod($file,0600);
            }
            return $result;
        } finally {
            if ($temp && is_file($temp)) unlink($temp);
            flock($lock, LOCK_UN); fclose($lock);
        }
    }
    public static function norm(string $s): string
    {
        $s = Normalizer::normalize(trim($s), Normalizer::FORM_KC);
        return mb_strtolower(mb_convert_kana($s,'c','UTF-8'),'UTF-8');
    }
    private static function large(string $s): string
    {
        $small = preg_split('//u','ぁぃぅぇぉゃゅょっゎゕゖ',-1,PREG_SPLIT_NO_EMPTY);
        $big = preg_split('//u','あいうえおやゆよつわかけ',-1,PREG_SPLIT_NO_EMPTY);
        return str_replace($small,$big,$s);
    }
    public static function first(string $s): string { return self::large(mb_substr($s,0,1)); }
    public static function last(string $s): string { while(mb_strlen($s)>1 && mb_substr($s,-1)==='ー')$s=mb_substr($s,0,-1);return self::large(mb_substr($s,-1,1)); }
    public function all(string $genre): array { return array_values(array_filter($this->words, fn($w)=>$w['genre']===$genre)); }
    public function find(string $genre,string $input): array
    {
        $n=self::norm($input);
        return array_values(array_filter($this->all($genre), function($w)use($n){
            foreach (array_merge([$w['name'],$w['reading']],$w['aliases']) as $v) if (self::norm($v)===$n) return true;
            return false;
        }));
    }
    public function addWord(array $p, string $uid): array
    {
        if(count($this->db['customWords'])>=5000) throw new DomainException('追加語は5000件までである。');
        $g=$p['genre']??'';
        $type=match($g){'MOVIE','ROBOT_ANIME'=>'WORK','HISTORY'=>'PERSON','FISH'=>'FISH','SHOWA'=>$p['type']??'',default=>''};
        $name=trim($p['name']??''); $reading=self::norm($p['reading']??'');
        if(!isset(self::GENRES[$g])||!in_array($type,['WORK','PERSON','FISH','SONG','SINGER'],true)) throw new DomainException('ジャンル・種別を選択する。');
        if(mb_strlen($name)<1||mb_strlen($name)>200||preg_match('/[\x00-\x1f\x7f]/u',$name)) throw new DomainException('名称は改行を含まない1～200文字とする。');
        if(mb_strlen($reading)>200||!preg_match('/^[ぁ-ゖ][ぁ-ゖー]*$/u',$reading)) throw new DomainException('読みはひらがな・カタカナで入力する。記号や空白は使えない。');
        $aliases=array_values(array_unique(array_filter(array_map('trim',explode('|',$p['aliases']??'')),fn($s)=>$s!=='')));
        if(count($aliases)>10) throw new DomainException('別名は10件以内とする。');
        foreach($aliases as $a) if(mb_strlen($a)>100||preg_match('/[\x00-\x1f\x7f]/u',$a)) throw new DomainException('別名は改行を含まない各100文字以内とする。');
        $keys=array_map([self::class,'norm'],array_merge([$name],$aliases));
        foreach($this->all($g) as $w) if($w['type']===$type){
            $existing=array_map([self::class,'norm'],array_merge([$w['name']],$w['aliases']));
            if(array_intersect($keys,$existing)) throw new DomainException('同じ名称・別名が登録済みである。');
        }
        $today=date('Y-m-d');$u=&$this->db['users'][$uid];
        if(($u['wordDate']??'')!==$today){$u['wordDate']=$today;$u['wordCount']=0;}
        if($u['wordCount']>=100) throw new DomainException('1日の追加上限100件に達した。');
        $u['wordCount']++;
        $w=['id'=>'custom_'.bin2hex(random_bytes(16)),'genre'=>$g,'type'=>$type,'name'=>$name,'reading'=>$reading,'aliases'=>$aliases];
        $this->db['customWords'][]=$w;$this->words[$w['id']]=$w;return $w;
    }
    public function authenticate(string $login,string $password,string $ip,bool $register,string $name=''): array
    {
        $key=hash('sha256',$ip); $now=time();
        $this->db['attempts']=array_filter($this->db['attempts'],fn($a)=>$a['at']>$now-900);
        $a=$this->db['attempts'][$key]??['at'=>$now,'count'=>0];
        if($a['count']>=10) return ['error'=>'試行回数が上限に達した。15分後に再試行する。'];
        $a['count']++;$this->db['attempts'][$key]=$a;
        if(!preg_match('/^[a-zA-Z0-9_]{3,30}$/D',$login)) return ['error'=>'IDは半角英数字・_の3～30文字とする。'];
        $id=strtolower($login);
        if($register){
            if(!$this->config['registration_enabled']) return ['error'=>'新規登録を停止している。'];
            if(isset($this->db['users'][$id])) return ['error'=>'このIDは使用できない。'];
            if(count($this->db['users'])>=1000) return ['error'=>'登録人数の上限に達した。'];
            if(strlen($password)<10||strlen($password)>72) return ['error'=>'パスワードは10～72バイトとする。'];
            $name=trim($name);
            if($name===''||mb_strlen($name)>30||preg_match('/[\x00-\x1f\x7f]/u',$name)) return ['error'=>'表示名は改行を含まない1～30文字とする。'];
            $this->db['users'][$id]=['id'=>$id,'name'=>$name,'hash'=>password_hash($password,PASSWORD_DEFAULT),'answered'=>[]];
        } else {
            $hash=$this->db['users'][$id]['hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
            if(!password_verify($password,$hash)||!isset($this->db['users'][$id])) return ['error'=>'IDまたはパスワードが異なる。'];
        }
        unset($this->db['attempts'][$key]);return ['uid'=>$id];
    }
    private function clock(array &$m):void { $m['deadline']=microtime(true)+(int)$this->config['thinking_seconds']; }
    public function start(string $uid,string $genre,string $difficulty):void
    {
        if(!isset(self::GENRES[$genre])||!in_array($difficulty,['EASY','NORMAL'],true)) throw new DomainException('対戦条件が不正である。');
        $old=$this->db['matches'][$uid]??null;
        if($old&&!$old['finished']) throw new DomainException('中断中の対戦がある。再開して終了させる。');
        $m=['id'=>bin2hex(random_bytes(16)),'genre'=>$genre,'difficulty'=>$difficulty,'dictionary'=>$this->dictionaryVersion,'moves'=>[],'revision'=>0,'finished'=>false,'suspended'=>false,'won'=>false,'reason'=>''];
        $this->clock($m);$this->db['matches'][$uid]=$m;
    }
    public function next(array $m):?string
    {
        foreach(array_reverse($m['moves']) as $move) if(!$move['pass']) return self::last($this->words[$move['wordId']]['reading']);
        return null;
    }
    public function used(array $m):array { return array_column(array_filter($m['moves'],fn($v)=>!$v['pass']),'wordId'); }
    public function check(array $m,string $uid,array $w):?string
    {
        if($w['genre']!==$m['genre']) return 'ジャンルが異なる。';
        $next=$this->next($m);
        if($next!==null&&self::first($w['reading'])!==$next) return '「'.$next.'」から始まる言葉が必要である。';
        if($m['difficulty']==='NORMAL'){
            if(in_array($w['id'],$this->used($m),true)) return 'この対戦ですでに使用されている。';
            if(in_array($w['id'],$this->db['users'][$uid]['answered'][$m['genre']]??[],true)) return '過去に自分が回答した言葉は再使用できない。';
        }return null;
    }
    public function finish(array &$m,string $uid,bool $won,string $reason):void
    {
        $m['finished']=true;$m['won']=$won;$m['reason']=$reason;$m['deadline']=0;$m['suspended']=false;
        $answers=count(array_filter($m['moves'],fn($v)=>$v['actor']==='PLAYER'&&!$v['pass']));
        $this->db['scores'][$m['id']]=['uid'=>$uid,'genre'=>$m['genre'],'won'=>$won,'answers'=>$answers,'at'=>date(DATE_ATOM)];
    }
    public function cpu(array &$m,string $uid):void
    {
        $next=$this->next($m);$banned=$m['difficulty']==='NORMAL'?$this->used($m):[];
        $c=array_values(array_filter($this->all($m['genre']),fn($w)=>!in_array($w['id'],$banned,true)&&($next===null||self::first($w['reading'])===$next)));
        if(!$c){$this->finish($m,$uid,true,'CPUが答えられる言葉がなくなった。');return;}
        if($m['difficulty']==='NORMAL'){$safe=array_values(array_filter($c,fn($w)=>self::last($w['reading'])!=='ん'));if($safe)$c=$safe;}
        $w=$c[random_int(0,count($c)-1)];$m['moves'][]=['actor'=>'CPU','wordId'=>$w['id'],'pass'=>false];
        if(self::last($w['reading'])==='ん') $this->finish($m,$uid,true,'CPUが「ん」で終わる言葉を回答した。');else $this->clock($m);
    }
    public function act(string $uid,string $action,array $p):string
    {
        $m=&$this->db['matches'][$uid];
        if(!$m||$m['finished']) throw new DomainException('進行中の対戦がない。');
        if(!hash_equals($m['id'], $p['match']??'')||(string)$m['revision']!==($p['revision']??'')) throw new DomainException('古い画面からの操作である。最新画面を使用する。');
        if($m['dictionary']!==$this->dictionaryVersion) throw new DomainException('初期辞書が変更されている。管理者に確認する。');
        if($action==='resume'){
            if($m['suspended']){$m['suspended']=false;$this->clock($m);$m['revision']++;}
            return '対戦を再開した。';
        }
        if($action==='giveup'){$this->finish($m,$uid,false,'ギブアップした。');$m['revision']++;return '対戦を終了した。';}
        if($m['suspended']) throw new DomainException('対戦を再開する。');
        if($action==='save'){$m['suspended']=true;$m['revision']++;return 'セーブして中断した。';}
        if(count($m['moves'])>=2000) throw new DomainException('回答履歴の上限に達した。ギブアップして新しく対戦する。');
        if($action==='pass'){$m['moves'][]=['actor'=>'PLAYER','wordId'=>null,'pass'=>true];$this->cpu($m,$uid);$m['revision']++;return 'パスした。';}
        if($action!=='answer') throw new DomainException('操作が不正である。');
        if(microtime(true)>=$m['deadline']) throw new DomainException('30秒が経過した。パスまたはギブアップを選択する。');
        $found=$this->find($m['genre'],$p['answer']??'');
        if(!$found) throw new DomainException('未登録の言葉である。メニューの辞書追加フォームから登録できる。');
        $chosen=$p['wordId']??'';
        if(count($found)>1&&$chosen==='') throw new DomainException('複数の候補がある。回答する名称を選択する。');
        $w=null;foreach($found as $v)if($chosen===''||$chosen===$v['id']){$w=$v;break;}
        if(!$w) throw new DomainException('回答候補が不正である。');
        $error=$this->check($m,$uid,$w);if($error)throw new DomainException($error);
        $history=&$this->db['users'][$uid]['answered'][$m['genre']];$history??=[];
        if(!in_array($w['id'],$history,true))$history[]=$w['id'];
        $m['moves'][]=['actor'=>'PLAYER','wordId'=>$w['id'],'pass'=>false];
        if(self::last($w['reading'])==='ん')$this->finish($m,$uid,false,'「ん」で終わる言葉を回答した。');else $this->cpu($m,$uid);
        $m['revision']++;return '回答を受け付けた。';
    }
}
