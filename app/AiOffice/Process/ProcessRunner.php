<?php

namespace App\AiOffice\Process;

/**
 * 執行外部程序的抽象。抽出介面的理由不是為了「好抽換」，而是為了能夠斷言
 * **我們到底送了哪些參數給 docker**——沙箱的安全性幾乎全部落在那串旗標上
 * （`--network none`、`--cap-drop ALL`、沒有掛 docker.sock…），
 * 那是最該被測試盯住的東西，而它在真的跑起來之後就看不見了。
 */
interface ProcessRunner
{
    /**
     * @param  list<string>  $argv  不經過 shell，直接 exec，所以不需要跳脫
     */
    public function run(array $argv, ?int $timeoutSeconds = null, ?string $cwd = null): ProcessResult;
}
