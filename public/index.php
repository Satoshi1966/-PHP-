<?php
declare(strict_types=1);
// privateを公開領域の外へ移した場合、この1行を実際の絶対パスへ変更する。
$privateDir = dirname(__DIR__) . '/private';
ini_set('display_errors','0');
ini_set('log_errors','1');
date_default_timezone_set('Asia/Tokyo');
error_reporting(E_ALL);
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
session_name('genre_shiritori');
$cookiePath=rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME'])),'/').'/';
if (!session_start(['use_strict_mode'=>1,'cookie_httponly'=>true,'cookie_samesite'=>'Lax','cookie_secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','cookie_path'=>$cookiePath])) { http_response_code(503); exit('セッションを開始できない。管理者がPHPのsession.save_pathを確認する。'); }
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
function h(mixed $s):string{return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function hidden(string $action, ?array $m=null):void{
    echo '<input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'"><input type="hidden" name="action" value="'.h($action).'">';
    if($m)echo '<input type="hidden" name="match" value="'.h($m['id']).'"><input type="hidden" name="revision" value="'.h($m['revision']).'">';
}
function redirect(string $screen=''):never{header('Location: index.php'.($screen!==''?'?screen='.$screen:''),true,303);exit;}
try {
    require $privateDir.'/Game.php';
    $config=require $privateDir.'/config.php';
    $game=new Game($config,$privateDir.'/words.csv');
    $uid=$_SESSION['uid']??null;
    if($_SERVER['REQUEST_METHOD']==='POST'){
        // 文字列以外や過大なフォーム値を受け付けない。
        foreach($_POST as $value) if(!is_string($value)||strlen($value)>8000)throw new DomainException('入力形式が不正である。');
        if(!hash_equals($_SESSION['csrf'],$_POST['csrf']??''))throw new DomainException('画面の有効期限が切れた。再読込する。');
        $action=$_POST['action']??'';$screen='';
        if($action==='logout'){
            $_SESSION=[];session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));redirect();
        }
        if(in_array($action,['register','login'],true)){
            $result=$game->transaction(fn($g)=>$g->authenticate($_POST['login']??'',$_POST['password']??'',$_SERVER['REMOTE_ADDR']??'', $action==='register',$_POST['name']??''));
            if(isset($result['error']))throw new DomainException($result['error']);
            session_regenerate_id(true);$_SESSION['uid']=$result['uid'];$_SESSION['csrf']=bin2hex(random_bytes(32));
            $_SESSION['flash']='ログインした。';redirect('menu');
        }
        if(!$uid)throw new DomainException('ログインする。');
        $_SESSION['lastInput']=$_POST['answer']??($_SESSION['lastInput']??'');
        $message=$game->transaction(function($g)use($uid,$action){
            if(!isset($g->db['users'][$uid]))throw new DomainException('ログインし直す。');
            if($action==='addWord'){$w=$g->addWord($_POST,$uid);return '「'.$w['name'].'（'.$w['reading'].'）」を追加した。CPUも使用できる。';}
            if($action==='start'){$g->start($uid,$_POST['genre']??'',$_POST['difficulty']??'');return '対戦を開始した。';}
            if($action==='reset'){
                $genre=$_POST['genre']??'';
                if(!isset(Game::GENRES[$genre])||($_POST['confirm']??'')!=='yes')throw new DomainException('ジャンルと確認欄を指定する。');
                $m=$g->db['matches'][$uid]??null;
                if($m&&!$m['finished'])throw new DomainException('進行中・中断中の対戦を終了してからリセットする。');
                unset($g->db['users'][$uid]['answered'][$genre]);return '選択ジャンルの自分の回答履歴をリセットした。';
            }
            return $g->act($uid,$action,$_POST);
        });
        if(in_array($action,['addWord','reset','save'],true))$screen='menu';
        if(in_array($action,['answer','pass','start'],true))unset($_SESSION['lastInput']);
        $_SESSION['flash']=$message;redirect($screen);
    }
} catch(DomainException $e){
    $_SESSION['flash']=$e->getMessage();
    $menu=in_array($_POST['action']??'',['addWord','reset','login','register','start'],true);
    redirect($menu?'menu':'');
} catch(Throwable $e){
    error_log('GenreShiritori: '.$e->getMessage());
    http_response_code(503);
    echo '<!doctype html><html lang="ja"><meta charset="utf-8"><title>起動エラー</title><h1>起動・保存に失敗した。</h1><p>PHP拡張、privateへのパス、保存先の権限を管理者が確認する。既存データは削除せず退避する。</p></html>';exit;
}
try {
    $view=$game->transaction(function($g)use($uid){return ['user'=>$g->db['users'][$uid]??null,'match'=>$g->db['matches'][$uid]??null,'scores'=>array_values(array_filter($g->db['scores'],fn($s)=>$s['uid']===$uid)),'custom'=>$g->db['customWords']];},false);
} catch(Throwable $e){error_log('GenreShiritori read: '.$e->getMessage());http_response_code(503);exit('保存データを読み込めない。管理者がログと保存先を確認する。');}
$user=$view['user'];$m=$view['match'];
$menu=($_GET['screen']??'')==='menu'||!$m||($m['suspended']??false);
$flash=$_SESSION['flash']??'';unset($_SESSION['flash']);
$input=$_SESSION['lastInput']??'';
?>
<!doctype html>
<html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>ジャンルしりとり</title><link rel="stylesheet" href="css/style.css"><script src="js/game.js" defer></script></head>
<body><div class="shell">
<header class="top"><a class="brand" href="index.php?screen=menu"><img src="images/logo.svg" width="42" height="42" alt=""><span>ジャンルしりとり<small>ことばをつないで、CPUに挑戦。</small></span></a><span class="badge">PHP版</span></header>
<?php if($flash!==''):?><p class="notice" role="status"><?=h($flash)?></p><?php endif;?>
<?php if(!$user):?>
<section class="intro"><p class="eyebrow">WORD CHAIN GAME</p><h1>好きなジャンルで、<br>しりとり対戦。</h1><p>映画・歴史・魚・ロボットアニメ・昭和の曲と歌手。自分のIDで履歴を引き継ぐ。</p></section>
<div class="grid"><section class="card"><h2>ログイン</h2><form method="post"><?php hidden('login');?><label for="login">プレイヤーID</label><input id="login" name="login" autocomplete="username" required maxlength="30"><label for="password">パスワード</label><input id="password" name="password" type="password" autocomplete="current-password" required maxlength="72"><button class="wide">ログインする</button></form></section>
<section class="card"><h2>初めてのプレイ</h2><form method="post"><?php hidden('register');?><label for="reg-login">プレイヤーID</label><input id="reg-login" name="login" pattern="[a-zA-Z0-9_]{3,30}" minlength="3" maxlength="30" autocomplete="username" required><p class="muted">半角英数字・_の3～30文字。大文字・小文字は区別しない。</p><label for="name">表示名</label><input id="name" name="name" maxlength="30" required><label for="reg-password">パスワード</label><input id="reg-password" name="password" type="password" minlength="10" maxlength="72" autocomplete="new-password" required><p class="muted">半角英数字・記号で10～72文字を推奨する。パスワード再発行機能はないため、安全に保管する。</p><button class="wide">登録してはじめる</button></form></section></div>
<?php elseif($menu):?>
<section class="intro"><p class="eyebrow">WELCOME, <?=h($user['name'])?></p><h1>今日はどのジャンルで<br>挑戦する？</h1><p>30秒のシンキングタイム。詰まったら、いつでもパスできる。</p></section>
<div class="grid"><section class="card"><h2>対戦をはじめる</h2>
<?php if($m&&!$m['finished']):?><p><?=h(Game::GENRES[$m['genre']])?>の対戦が保存されている。</p><form method="post"><?php hidden('resume',$m);?><button class="wide">対戦に戻る・再開する</button></form><p class="muted"><?= $m['suspended']?'中断セーブは再開時に30秒から始まる。':'メニュー表示中も制限時間は進む。'?></p><form method="post"><?php hidden('giveup',$m);?><button class="danger wide">ギブアップして終了する</button></form>
<?php else:?><form method="post"><?php hidden('start');?><label for="genre">ジャンル</label><select id="genre" name="genre"><?php foreach(Game::GENRES as $key=>$label):?><option value="<?=h($key)?>"><?=h($label)?>（<?=count($game->all($key))?>語）</option><?php endforeach;?></select><label for="difficulty">難易度</label><select id="difficulty" name="difficulty"><option value="EASY">やさしい・再使用できる</option><option value="NORMAL">ふつう・自分の過去回答も再使用できない</option></select><button class="wide">新しい対戦をはじめる →</button></form><?php endif;?></section>
<section class="card"><h2>自分の記録</h2><p><strong><?=h($user['name'])?></strong> ／ ID：<?=h($uid)?></p><p><?=count($view['scores'])?>戦 ／ <?=count(array_filter($view['scores'],fn($s)=>$s['won']))?>勝</p><div class="tableWrap"><table><thead><tr><th>ジャンル</th><th>回答済み</th></tr></thead><tbody><?php foreach(Game::GENRES as $key=>$label):?><tr><td><?=h($label)?></td><td><?=count($user['answered'][$key]??[])?>語</td></tr><?php endforeach;?></tbody></table></div><form method="post"><?php hidden('logout');?><button class="secondary wide">ログアウト</button></form><p class="muted">対戦・履歴は自動保存される。別の端末でも同じIDでログインできる。</p></section></div>
<section class="card section" id="dictionary"><p class="eyebrow">GROW THE DICTIONARY</p><h2>言葉を辞書に追加</h2><p class="muted">未登録の映画タイトル・曲名などを登録できる。追加語は全プレイヤーとCPUで共有する。</p>
<form method="post"><?php hidden('addWord');?><div class="grid"><div><label for="addGenre">ジャンル</label><select id="addGenre" name="genre"><?php foreach(Game::GENRES as $key=>$label):?><option value="<?=h($key)?>" <?=$m&&$m['genre']===$key?'selected':''?>><?=h($label)?></option><?php endforeach;?></select><label for="type">昭和ジャンルの種別</label><select id="type" name="type"><option value="SONG">曲名</option><option value="SINGER">歌手名</option></select><p class="muted">昭和以外のジャンルは種別を自動設定する。</p></div><div><label for="addName">正式名称</label><input id="addName" name="name" maxlength="200" value="<?=h($input)?>" required><label for="reading">読み</label><input id="reading" name="reading" maxlength="200" required placeholder="ひらがな、またはカタカナ"><p class="muted">英数字・記号も、しりとりに使う読みへ直して入力する。</p></div></div><label for="aliases">別名・略称（任意）</label><input id="aliases" name="aliases" maxlength="1100" placeholder="複数ある場合は | で区切る"><p class="muted">略称で答えても、正式名称の読みで判定する。登録は回答そのものにはならず、制限時間も延長しない。1人1日100件までである。</p><button>辞書に追加する</button></form>
<details><summary>最近追加された言葉（全<?=count($view['custom'])?>件）</summary><ul><?php foreach(array_reverse(array_slice($view['custom'],-50)) as $w):?><li><?=h(Game::GENRES[$w['genre']])?>：<?=h($w['name'])?>（<?=h($w['reading'])?>）</li><?php endforeach;?></ul></details></section>
<details class="card section"><summary>自分の回答履歴をリセット</summary><p>選択ジャンルの累積回答履歴を消去する。戦績と追加辞書は保持する。対戦中・中断中は実行できない。</p><form method="post"><?php hidden('reset');?><label for="resetGenre">ジャンル</label><select id="resetGenre" name="genre"><?php foreach(Game::GENRES as $key=>$label):?><option value="<?=h($key)?>"><?=h($label)?></option><?php endforeach;?></select><label class="check"><input type="checkbox" name="confirm" value="yes" required>選択ジャンルの自分の履歴を消去する</label><button class="danger">履歴をリセットする</button></form></details>
<?php else:?>
<div class="gameHeader"><div><p class="eyebrow"><?=h($user['name'])?> ／ <?=h(Game::GENRES[$m['genre']])?></p><h1><?=$m['difficulty']==='NORMAL'?'ふつう':'やさしい'?>モード</h1></div><a class="textLink" href="index.php?screen=menu">メニューへ</a></div>
<?php if($m['finished']):?><section class="card result"><p class="eyebrow">RESULT</p><h2><?=$m['won']?'あなたの勝ち！':'あなたの負け'?></h2><p><?=h($m['reason'])?></p><a class="button" href="index.php?screen=menu">次の対戦へ</a></section>
<?php else:$next=$game->next($m);$remaining=max(0,($m['deadline']-microtime(true))*1000);?>
<section class="card" id="turn" data-remaining="<?=h((int)$remaining)?>" data-match="<?=h($m['id'])?>" data-revision="<?=h($m['revision'])?>"><p class="eyebrow">YOUR TURN</p><h2><?=$next===null?'最初は好きな言葉から。':'「'.h($next).'」から始まる言葉を回答する。'?></h2><p class="muted">制限時間は30秒。残り10秒で一度だけ通知する。</p><div class="warningSpace" role="status" aria-live="polite"><p class="timeWarning" id="timeWarning" hidden>残り10秒。詰まったらパスも選択できる。</p></div>
<div id="answerArea"><form method="post"><?php hidden('answer',$m);?><label for="answer">名称・読み・別名</label><div class="answerRow"><input id="answer" name="answer" maxlength="200" value="<?=h($input)?>" autocomplete="off" required><button>回答する</button></div>
<?php $found=$input!==''?$game->find($m['genre'],$input):[];if(count($found)>1):?><label for="wordId">回答候補</label><select id="wordId" name="wordId"><?php foreach($found as $w):?><option value="<?=h($w['id'])?>"><?=h(($w['type']==='SINGER'?'［歌手］':'［作品・曲］').$w['name'].'（'.$w['reading'].'）')?></option><?php endforeach;?></select><?php endif;?></form></div>
<div id="timeout" hidden><h3>30秒が経過した。</h3><p>パスまたはギブアップを選択する。</p></div>
<div class="actions"><form method="post"><?php hidden('pass',$m);?><button class="secondary">パスする</button></form><form method="post"><?php hidden('giveup',$m);?><button class="danger">ギブアップ</button></form></div><p class="muted"><a class="textLink" href="index.php?screen=menu#dictionary">未登録の言葉を辞書に追加する</a>（メニュー表示中も時間は進む）</p></section>
<div class="saveSection"><form method="post"><?php hidden('save',$m);?><button class="quiet">セーブして中断する</button></form></div><?php endif;?>
<section class="card section"><h2>ことばの履歴</h2><ol class="history"><?php foreach($m['moves'] as $move):?><li class="<?=$move['actor']==='CPU'?'cpu':''?>"><span class="actor"><?=$move['actor']==='CPU'?'CPU':'あなた'?></span><span><?php if($move['pass']):?>パス<?php else:$w=$game->words[$move['wordId']];?><?=h($w['name'])?> <small>（<?=h($w['reading'])?>）</small><?php endif;?></span></li><?php endforeach;?></ol><?php if(!$m['moves']):?><p class="muted">まだ回答はない。</p><?php endif;?></section>
<?php endif;?>
<section class="card section"><h2>あそびかた</h2><ul class="rules"><li>正式名・かな読み・登録された別名で回答する。判定には正式名称の読みを使う。</li><li>末尾の「ん」は負け。小さい文字は大きい文字として、「ー」は直前の文字として扱う。</li><li>「ふつう」は対戦内の使用済み語と、自分が以前のプレイで回答した語を再使用できない。</li><li>パスは回数制限なし。直前の言葉を引き継いでCPUが答える。</li><li>昭和ジャンルは曲名と歌手名を混ぜて回答できる。辞書への追加時はジャンルに合う名称を登録する。</li></ul></section>
<footer>Genre Shiritori PHP 1.0</footer></div></body></html>
