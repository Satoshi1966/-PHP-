<?php
declare(strict_types=1);
// 公開前にHTTPSを設定する。保存先は公開領域の外を推奨する。
return [
    'data_dir' => __DIR__ . '/runtime',
    'thinking_seconds' => 30,
    'registration_enabled' => true,
];
