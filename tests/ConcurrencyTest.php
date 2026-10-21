<?php
declare(strict_types=1);
require __DIR__.'/../private/Game.php';
$worker=($argv[1]??'')==='worker';
$dir=$worker?$argv[2]:sys_get_temp_dir().'/shiritori-concurrent-'.bin2hex(random_bytes(6));
$config=['data_dir'=>$dir,'thinking_seconds'=>30,'registration_enabled'=>true];
$g=new Game($config);
if($worker){for($i=0;$i<20;$i++)$g->transaction(function($g){$g->db['counter']=($g->db['counter']??0)+1;usleep(1000);});exit;}
try{
 $g->transaction(function($g){$g->db['counter']=0;});$jobs=[];
 for($i=0;$i<3;$i++){
  $cmd=[PHP_BINARY,'-n','-d','extension_dir='.ini_get('extension_dir'),'-d','extension=mbstring','-d','extension=intl',__FILE__,'worker',$dir];
  $p=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
  if(!is_resource($p))throw new RuntimeException('worker起動失敗');fclose($pipes[0]);$jobs[]=[$p,$pipes];
 }
 foreach($jobs as [$p,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0)throw new RuntimeException($out.$err);}
 $g->transaction(function($g){if($g->db['counter']!==60)throw new RuntimeException('更新欠落');echo "PASS 3プロセス×20回の同時更新で60件を保持\n";},false);
}finally{foreach(glob($dir.'/*')?:[] as $p)unlink($p);if(is_dir($dir))rmdir($dir);}
