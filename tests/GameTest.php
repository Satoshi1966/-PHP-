<?php
declare(strict_types=1);
require __DIR__.'/../private/Game.php';
$n=0;
function ok(bool $b,string $name):void {global $n;if(!$b)throw new RuntimeException('FAIL '.$name);echo 'PASS '.(++$n).' '.$name."\n";}
function rejects(callable $f,string $name):void {try{$f();}catch(DomainException $e){ok(true,$name);return;}ok(false,$name);}
$dir=sys_get_temp_dir().'/shiritori-php-test-'.bin2hex(random_bytes(6));
$config=['data_dir'=>$dir,'thinking_seconds'=>30,'registration_enabled'=>true];
$g=new Game($config);
function fields(Game $g,string $uid):array{$m=$g->db['matches'][$uid];return ['match'=>$m['id'],'revision'=>(string)$m['revision']];}
function word(string $name,string $reading,string $genre='MOVIE',string $type='WORK'):array{return compact('name','reading','genre','type')+['aliases'=>''];}
try{
 ok(count($g->words)===270,'初期辞書270語');
 ok(Game::norm('  ｶﾞﾝﾀﾞﾑ ABC  ')==='がんだむ abc','半角カナ・英字・空白の正規化');
 ok(Game::last('るびー')==='び'&&Game::last('きゃ')==='や','末尾の長音と小文字');
 $g->transaction(function($g){
  $r=$g->authenticate('tester','test-password-123','127.0.0.1',true,'テスト');ok($r['uid']==='tester','登録');
  $r=$g->authenticate('other','test-password-456','127.0.0.1',true,'別人');ok($r['uid']==='other','別ユーザー登録');
 });
 $g->transaction(function($g){
  ok(password_verify('test-password-123',$g->db['users']['tester']['hash']),'ハッシュ保存');
  ok(isset($g->authenticate('tester','wrong','127.0.0.2',false)['error']),'誤パスワード拒否');
  ok($g->authenticate('TESTER','test-password-123','127.0.0.1',false)['uid']==='tester','ID大小文字の統一とログイン');
  $w=$g->addWord(word('試験映画','テストエイガ')+['unused'=>''],'tester');ok($w['reading']==='てすとえいが','追加語のかな正規化');
  rejects(fn()=>$g->addWord(word('試験映画','べつのよみ'),'tester'),'名称重複拒否');
  rejects(fn()=>$g->addWord(word('不正','test'),'tester'),'読みの英字拒否');
  rejects(fn()=>$g->addWord(word('不正','ーてすと'),'tester'),'先頭長音拒否');
  $p=word('別名映画','えいが');$p['aliases']='映画別称|略称';$g->addWord($p,'tester');
  ok(count($g->find('MOVIE','略称'))===1,'別名検索');
  rejects(fn()=>$g->addWord(word('略称','べつ'),'tester'),'既存別名との衝突拒否');
  $g->addWord(word('同名','どうめい','SHOWA','SONG'),'tester');$g->addWord(word('同名','どうめい','SHOWA','SINGER'),'tester');
  ok(count($g->find('SHOWA','同名'))===2,'同名の曲と歌手を保持');
  $g->start('tester','MOVIE','NORMAL');$m=$g->db['matches']['tester'];
  rejects(fn()=>$g->start('tester','FISH','EASY'),'進行中対戦の上書き拒否');
  $g->act('tester','answer',fields($g,'tester')+['answer'=>'試験映画']);
  ok(in_array($w['id'],$g->db['users']['tester']['answered']['MOVIE'],true),'正解回答を累積記録');
  if(!$g->db['matches']['tester']['finished'])$g->act('tester','giveup',fields($g,'tester'));
  $g->start('tester','MOVIE','NORMAL');
  ok($g->check($g->db['matches']['tester'],'tester',$w)!==null,'次回対戦の再使用拒否');
  if(!$g->db['matches']['tester']['finished'])$g->act('tester','giveup',fields($g,'tester'));$g->start('tester','MOVIE','EASY');
  ok($g->check($g->db['matches']['tester'],'tester',$w)===null,'やさしいでは累積語を使用可能');
  $g->start('other','MOVIE','NORMAL');ok($g->check($g->db['matches']['other'],'other',$w)===null,'別ユーザー履歴を分離');
  $stale=fields($g,'other');$g->act('other','save',$stale);
  rejects(fn()=>$g->act('other','pass',$stale),'古い画面の操作拒否');
  $g->act('other','resume',fields($g,'other'));ok(!$g->db['matches']['other']['suspended'],'中断再開');
  $g->db['matches']['other']['deadline']=microtime(true)-1;
  rejects(fn()=>$g->act('other','answer',fields($g,'other')+['answer'=>'試験映画']),'期限後の回答を拒否');
  $g->act('other','pass',fields($g,'other'));ok($g->db['matches']['other']['moves'][0]['pass'],'期限後パス');
  if(!$g->db['matches']['tester']['finished'])$g->act('tester','giveup',fields($g,'tester'));$g->start('tester','MOVIE','NORMAL');
  $lose=$g->addWord(word('負け映画','まけん'),'tester');
  $g->act('tester','answer',fields($g,'tester')+['answer'=>'負け映画']);
  ok($g->db['matches']['tester']['finished']&&!$g->db['matches']['tester']['won'],'んで敗北');
  $g->start('tester','MOVIE','NORMAL');
  $x=$g->addWord(word('試験開始','ぬゑ'),'tester');$y=$g->addWord(word('CPU専用追加','ゑぬ'),'tester');
  $g->act('tester','answer',fields($g,'tester')+['answer'=>'試験開始']);
  ok($g->db['matches']['tester']['moves'][1]['wordId']===$y['id'],'CPUが追加語を回答');
 });
 $reload=new Game($config);
 $reload->transaction(function($g){ok(count($g->find('MOVIE','試験映画'))===1,'JSONから追加語再読込');ok(count($g->db['users']['tester']['answered']['MOVIE'])>=3,'累積回答履歴の永続化');},false);
 $before=file_get_contents($dir.'/data.json');
 try{$reload->transaction(function($g){$g->db['users']=[];throw new DomainException('rollback');});}catch(DomainException $e){}
 ok(file_get_contents($dir.'/data.json')===$before,'例外発生時に元データを保持');
 $reload->transaction(function($g){for($i=0;$i<11;$i++)$r=$g->authenticate('missing','wrong','192.0.2.1',false);ok(str_contains($r['error'],'15分'),'ログイン試行制限');});
 file_put_contents($dir.'/data.json','{broken');
 try{$reload->transaction(fn($g)=>null);ok(false,'破損JSON');}catch(JsonException $e){ok(file_get_contents($dir.'/data.json')==='{broken','破損JSONを上書きしない');}
 echo "ALL $n CHECKS PASSED\n";
}finally{foreach(glob($dir.'/*')?:[] as $p)unlink($p);if(is_dir($dir))rmdir($dir);}
