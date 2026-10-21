const fs=require('fs');const path=require('path');const {spawn}=require('child_process');const {chromium}=require('playwright');
const os=require('os');const project=path.resolve(__dirname,'..');const root=fs.mkdtempSync(path.join(os.tmpdir(),'shiritori-web-'));const qa=path.join(root,'app');
fs.rmSync(qa,{recursive:true,force:true});fs.cpSync(project,qa,{recursive:true});
fs.mkdirSync(qa+'/sessions');
const php=process.env.PHP_BIN||'php';const args=['-d','log_errors=1','-d','session.save_path='+qa+'/sessions'];if(process.env.PHP_EXTDIR)args.push('-n','-d','extension_dir='+process.env.PHP_EXTDIR,'-d','extension=mbstring','-d','extension=intl');
const log=fs.openSync(path.join(root,'php-web.log'),'w');const server=spawn(php,[...args,'-S','127.0.0.1:18090','-t',qa+'/public'],{stdio:['ignore',log,log]});
let browser;let count=0;const lines=[];function ok(b,s){if(!b)throw new Error('FAIL '+s);lines.push('PASS '+(++count)+' '+s);console.log(lines.at(-1));}
const base='http://127.0.0.1:18090/';const delay=m=>new Promise(r=>setTimeout(r,m));
(async()=>{try{
 for(let i=0;i<30;i++){try{if((await fetch(base)).status===200)break;}catch{}await delay(100);}
 browser=await chromium.launch({executablePath:process.env.BROWSER_BIN||undefined,args:['--no-sandbox']});
 const context=await browser.newContext();const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto(base);ok(await page.locator('h1').innerText().then(t=>t.includes('しりとり')),'PHP起動と入口表示');
 
 const reg=page.locator('form').filter({has:page.locator('input[name=action][value=register]')});
 await reg.locator('[name=login]').fill('browser');await reg.locator('[name=name]').fill('ブラウザー<script>');await reg.locator('[name=password]').fill('test-password-123');
 await Promise.all([page.waitForNavigation(),reg.locator('button').click()]);
 ok((await page.content()).includes('ブラウザー&lt;script&gt;'),'登録とHTMLエスケープ');
 const cookies=await context.cookies();ok(cookies.some(c=>c.name==='genre_shiritori'&&c.httpOnly&&c.sameSite==='Lax'),'セッションCookie保護');
 async function post(action,extra={}){
  const csrf=await page.locator('input[name=csrf]').first().getAttribute('value');
  const res=await context.request.post(base+'index.php',{form:{csrf,action,...extra}});const text=await res.text();await page.goto(base+'index.php'+(['addWord','reset','register'].includes(action)?'?screen=menu':''));return text;
 }
 async function state(){return {match:await page.locator('#turn input[name=match]').first().getAttribute('value'),revision:await page.locator('#turn input[name=revision]').first().getAttribute('value')};}
 let t=await post('addWord',{genre:'MOVIE',type:'WORK',name:'試験映画','reading':'ヌヱ',aliases:'試験略称'});ok(t.includes('を追加した'),'映画の追加フォーム処理');
 t=await post('addWord',{genre:'MOVIE',type:'WORK',name:'CPU映画','reading':'ヱヌ',aliases:''});ok(t.includes('を追加した'),'CPU候補の追加');
 t=await post('addWord',{genre:'SHOWA',type:'SONG',name:'試験曲','reading':'しけんきょく',aliases:''});ok(t.includes('を追加した'),'曲名追加');
 t=await post('addWord',{genre:'SHOWA',type:'SINGER',name:'試験曲','reading':'しけんきょく',aliases:''});ok(t.includes('を追加した'),'同名の歌手追加');
 t=await post('addWord',{genre:'MOVIE',name:'試験映画',reading:'べつ',aliases:''});ok(t.includes('登録済み'),'重複拒否');
 await post('start',{genre:'MOVIE',difficulty:'NORMAL'});ok(await page.locator('#turn').count()===1,'新規対戦');
 const stale=await state();t=await post('answer',{...stale,answer:'試験略称'});ok(t.includes('CPU映画')&&t.includes('試験映画'),'追加語の別名回答とCPUの追加語使用');
 t=await post('pass',stale);ok(t.includes('古い画面'),'古いフォームの拒否');
 await post('save',await state());ok((await page.content()).includes('中断セーブ'),'セーブ中断');
 const resumeFields=await page.locator('form').filter({has:page.locator('input[name=action][value=resume]')}).evaluate(f=>Object.fromEntries(new FormData(f)));
 await post('logout');ok(await page.locator('#login').count()===1,'ログアウト');
 const login=page.locator('form').filter({has:page.locator('input[name=action][value=login]')});await login.locator('[name=login]').fill('browser');await login.locator('[name=password]').fill('test-password-123');await Promise.all([page.waitForNavigation(),login.locator('button').click()]);
 await post('resume',{match:resumeFields.match,revision:resumeFields.revision});ok((await page.content()).includes('CPU映画')&&await page.locator('#turn').count()===1,'再ログイン後の再開');
 // ギブアップ、次回の累積再使用拒否
 await post('giveup',await state());ok((await page.content()).includes('あなたの負け'),'ギブアップと結果');
 await page.goto(base+'index.php?screen=menu');await post('start',{genre:'MOVIE',difficulty:'NORMAL'});t=await post('answer',{...await state(),answer:'試験映画'});ok(t.includes('過去に自分が回答'),'次回プレイでの再使用拒否');
 const fields=await state();const bad=await context.request.post(base+'index.php',{form:{csrf:'invalid',action:'pass',...fields}});ok((await bad.text()).includes('有効期限'),'CSRF拒否');
 // 新しい期限で実時間の通知確認
 await post('giveup',await state());await page.goto(base+'index.php?screen=menu');await post('start',{genre:'FISH',difficulty:'EASY'});
 await page.locator('#answer').fill('入力途中の文字');
 ok(await page.locator('#timeWarning').isHidden(),'初期状態で通知を非表示');
 await page.waitForFunction(()=>!document.getElementById('timeWarning').hidden,null,{timeout:23000});
 ok(await page.locator('#answer').inputValue()==='入力途中の文字','残り10秒通知と入力保持');
 await page.waitForFunction(()=>!document.getElementById('timeout').hidden,null,{timeout:12000});
 ok(await page.locator('#answerArea').isHidden()&&await page.locator('button').filter({hasText:'パスする'}).isVisible(),'30秒でパス・ギブアップ表示');
 t=await post('answer',{...await state(),answer:'さば'});ok(t.includes('30秒が経過'),'期限後の直接送信拒否');
 t=await post('pass',await state());ok(t.includes('パス'),'時間切れ後パス');
 await page.goto(base+'index.php?screen=menu');await page.setViewportSize({width:390,height:844});
 ok(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),'390px幅で横はみ出しなし');
 await page.screenshot({path:root+'/php-mobile.png',fullPage:true});await page.setViewportSize({width:1280,height:900});await page.screenshot({path:root+'/php-menu.png',fullPage:true});
 ok(errors.length===0,'JavaScriptエラーなし');
 ok((await context.request.get(base+'../private/words.csv')).status()===404,'公開ドキュメントルートからprivateを参照不可');
 const db=JSON.parse(fs.readFileSync(qa+'/private/runtime/data.json','utf8'));ok(db.customWords.length===4&&db.users.browser.answered.MOVIE.length===1,'追加辞書と個人履歴をJSON保存');
 lines.push('ALL '+count+' CHECKS PASSED');fs.writeFileSync(project+'/tests/web-output.txt',lines.join('\n')+'\n');
 }finally{if(browser)await browser.close();server.kill();fs.closeSync(log);fs.rmSync(root,{recursive:true,force:true});}})().catch(e=>{console.error(e);process.exitCode=1});
