# シンフリーサーバーへの設置手順

## 1. PHPとHTTPSを準備する

サーバーパネルで対象ドメインのPHPを8.3以降に設定する。独自SSLを有効にしHTTPSで接続する。
mbstring・intlが必要である。起動エラーが出る場合はPHPのエラーログと拡張の設定を確認する。
本配布物はPHPの通常リクエストだけで動作し、Servlet・JSP・Tomcat・Dockerを必要としない。

## 2. 推奨する配置

公開するpublicの中身と、非公開のprivateを分ける。
以下は例であり、実際のドメイン・ホームディレクトリはファイルマネージャーで確認する。

- 公開側：対象ドメインのpublic_html/shiritori/へ、publicの中身をアップロードする。
- 非公開側：public_htmlの外の任意の場所へprivateをアップロードする。
- 例：/home/サーバーID/shiritori-private/ にGame.php、config.php、words.csv、runtime/を置く。
- public/index.php先頭の `$privateDir` を、その非公開フォルダーの実際の絶対パスに変更する。

```php
$privateDir = '/home/実際のサーバーID/shiritori-private';
```

公開URLは `https://実際のドメイン/shiritori/` となる。
index.phpは入口であるため、index.jspへの置換やリンクは不要である。
README・tests・ZIPは公開ディレクトリへアップロードしない。

公開領域の外へ書き込めない契約・設定の場合は、GenreShiritoriPHPをフォルダーごと配置し、
`https://実際のドメイン/GenreShiritoriPHP/public/` を入口にできる。
この場合はprivate/.htaccessを必ず含め、private/words.csvおよびprivate/runtime/data.jsonへのURLアクセスが403で拒否されることを公開前に確認する。
拒否されない環境では、この配置で運用せず、非公開保存先へ変更する。

## 3. 保存先の権限

config.phpのdata_dirは初期状態でprivate/runtimeである。
PHPの実行ユーザーがこのディレクトリへ書き込めるようにする。最初は700を試し、サーバーの仕様に合わせる。
PHP・CSVは644など、サーバーの通常の推奨権限を使う。777へ一律変更しない。
data.jsonとdata.lockは初回操作時に生成される。設定変更や更新時にruntimeの中身を上書きしない。

## 4. 公開前確認

1. HTTPSで入口を開き、新規プレイヤーを登録する。
2. 新しい対戦で回答・即時パス・ギブアップを試す。
3. 何も送信せず約20秒後の一度だけの通知と、約30秒後の選択肢を確認する。
4. 新しい対戦をセーブ中断し、ログアウトする。同じIDで再ログインして再開する。
5. 映画・曲名をフォームで登録し、登録語で回答できることを確認する。
6. 別のブラウザーやスマホでも同じIDで履歴を確認する。
7. データ保存先がURLから取得できないことを確認する。

## 5. 更新・バックアップ

既存のprivate/runtimeを退避してからプログラムだけを更新する。
稼働中のJSONを単独で直接編集しない。バックアップ・復元時は一時的に入口をメンテナンスページへ差し替え、
新規リクエストが止まった状態でruntime全体をコピーする。
辞書CSVのID変更・削除は過去履歴と対戦に影響する。追加は原則フォームを使用する。
公開後に新規登録を止める場合はconfig.phpのregistration_enabledをfalseにする。

## エラーの確認

- 「起動・保存に失敗した」：privateDirのパス、intl・mbstring、data_dirの書込権限を確認する。
- 403：公開側と非公開側を取り違えていないか、.htaccessの配置を確認する。
- 500：PHPバージョン、.htaccessのディレクティブ許可、サーバーのPHPエラーログを確認する。
- 入力後に元へ戻る・ログイン保持不可：Cookie許可、HTTPS、ブラウザーのCookieを確認する。
- 破損JSON：削除せずバックアップし、正常なバックアップから復元する。

現時点で実際のシンフリーサーバーへのアップロード・実機検証は行っていない。
